#!/usr/bin/env node

import process from 'node:process';
import path from 'node:path';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const BOOTSTRAP_URL = 'https://www.szukajwarchiwach.gov.pl/';
const PHOTO_HOST = 'photos.szukajwarchiwach.gov.pl';
const SELECTED_CLASS_RE = /(?:^|[-_\s])(active|selected|current|wybrany|wybrana|aktywny|aktywna|is-active|slick-current|swiper-slide-active)(?:$|[-_\s])/i;

const action = process.argv[2] ?? '';
const targetUrl = process.argv[3] ?? '';
const extraArgs = process.argv.slice(4);
const timeoutArg = extraArgs.find((argument) => argument.startsWith('--timeout-ms='));
const debugDirArg = extraArgs.find((argument) => argument.startsWith('--debug-dir='));
const timeoutMs = Number.parseInt(timeoutArg?.slice('--timeout-ms='.length) ?? '60000', 10);
const debugDir = debugDirArg === undefined
    ? null
    : path.resolve(process.cwd(), debugDirArg.slice('--debug-dir='.length));
const runId = `${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}-${action}-bound-object`;
const debugState = {
    schema: 'mytree.szukajwarchiwach-browser-debug.v1',
    run_id: runId,
    action,
    target_url: targetUrl,
    started_at: new Date().toISOString(),
    events: [],
};

async function main() {
    validateArguments();

    if (debugDir !== null) {
        await mkdir(debugDir, { recursive: true });
    }

    let browser = null;
    let context = null;
    let failure = null;
    let result = null;

    try {
        browser = await chromium.launch({ headless: true });
        context = await browser.newContext({ viewport: { width: 1280, height: 720 } });
        const page = await context.newPage();
        observeDebugPage(page);
        context.on('page', observeDebugPage);

        debugEvent('bootstrap:start', { url: BOOTSTRAP_URL });
        const bootstrapResponse = await page.goto(BOOTSTRAP_URL, {
            waitUntil: 'domcontentloaded',
            timeout: timeoutMs,
        });
        await page.waitForTimeout(500);
        debugEvent('bootstrap:done', {
            status: bootstrapResponse?.status() ?? null,
            url: page.url(),
            title: await page.title().catch(() => ''),
        });

        result = await fetchScanImage(context, page);
    } catch (error) {
        failure = error;
        debugState.error = error instanceof Error ? error.message : String(error);
    } finally {
        if (context !== null) {
            await context.close().catch(() => null);
        }
        if (browser !== null) {
            await browser.close().catch(() => null);
        }
        if (debugDir !== null) {
            debugState.finished_at = new Date().toISOString();
            const manifestPath = path.join(debugDir, `${runId}.json`);
            await writeFile(manifestPath, JSON.stringify(debugState, null, 2), 'utf8').catch(() => null);
            process.stderr.write(`Browser debug manifest: ${manifestPath}\n`);
        }
    }

    if (failure !== null) {
        throw failure;
    }

    return result;
}

async function fetchScanImage(context, page) {
    const candidates = [];
    const pending = [];

    const observePage = (observedPage) => {
        observedPage.on('response', (response) => {
            const promise = capturePhotoCandidate(response, candidates);
            pending.push(promise);
        });
    };

    observePage(page);
    context.on('page', observePage);

    debugEvent('scan:navigate:start', { url: targetUrl });
    const viewerResponse = await page.goto(targetUrl, {
        waitUntil: 'domcontentloaded',
        timeout: timeoutMs,
    });
    if (viewerResponse === null) {
        fail('Scan viewer navigation did not produce an HTTP response.');
    }

    await settlePage(page);
    await Promise.allSettled(pending);

    const title = await page.title().catch(() => '');
    const body = Buffer.from(await page.content(), 'utf8');
    if (isBlockPage(title, body)) {
        fail(`Browser session received an Imperva/forbidden page for ${page.url()}.`);
    }

    debugEvent('scan:navigate:done', {
        status: viewerResponse.status(),
        url: page.url(),
        title,
        scan_entry_count: await page.locator('[data-plikid]').count().catch(() => 0),
        candidates: summarizeCandidates(candidates),
    });

    const objectId = objectIdFromUrl(targetUrl);
    if (objectId === null) {
        return selectDirectViewerCandidate(candidates, viewerResponse.status(), page.url());
    }

    const binding = await inspectAndActivateObject(page, objectId);
    debugEvent('scan:object-binding', binding);
    if (!binding.bound || binding.preview_url === null) {
        fail(
            `Object viewer could not bind a unique photo element to resolved object ${objectId}; `
            + 'refusing to select another scan from the page.',
        );
    }

    const targetIdentity = photoIdentity(binding.preview_url);
    if (targetIdentity === null) {
        fail(`Resolved object ${objectId} is bound to an unsupported preview URL ${binding.preview_url}.`);
    }

    debugEvent('scan:object-identity', {
        object_id: objectId,
        binding_strategy: binding.binding_strategy,
        preview_url: binding.preview_url,
        identity: targetIdentity,
    });

    if (binding.explicit_photo_url !== null) {
        await captureExplicitBoundAsset(context, binding.explicit_photo_url, targetIdentity, candidates);
    }

    if (binding.public_viewer_url !== null && isPublicViewerUrl(binding.public_viewer_url)) {
        debugEvent('scan:object-public-viewer', { url: binding.public_viewer_url });
        await page.goto(binding.public_viewer_url, {
            waitUntil: 'domcontentloaded',
            timeout: timeoutMs,
        });
        await settlePage(page);
    } else {
        await waitForContext(context);
    }

    await Promise.allSettled(pending);

    const navigatedViewer = context.pages()
        .map((observedPage) => observedPage.url())
        .find((url) => isPublicViewerUrl(url)) ?? null;
    if (navigatedViewer !== null && page.url() !== navigatedViewer) {
        debugEvent('scan:object-public-viewer-after-activation', { url: navigatedViewer });
        await page.goto(navigatedViewer, {
            waitUntil: 'domcontentloaded',
            timeout: timeoutMs,
        });
        await settlePage(page);
        await Promise.allSettled(pending);
    }

    const targetCandidates = candidates
        .filter((candidate) => photoIdentity(candidate.url) === targetIdentity)
        .sort(compareImageCandidates);

    debugEvent('scan:object-target-candidates', {
        object_id: objectId,
        identity: targetIdentity,
        candidates: summarizeCandidates(targetCandidates),
    });

    if (targetCandidates.length === 0) {
        fail(`Object viewer did not load any image response belonging to resolved object ${objectId}.`);
    }

    const selected = targetCandidates[0];
    if (isKnownPreviewAsset(selected.url)) {
        fail(
            `Resolved object ${objectId} exposed only preview-quality image responses for its own photo identity; `
            + 'refusing to substitute a different scan or silently accept _mid/_min/_thumb.',
        );
    }

    debugEvent('scan:selected-candidate', {
        object_id: objectId,
        selected: summarizeCandidate(selected),
    });

    return responsePayload(selected);
}

async function inspectAndActivateObject(page, objectId) {
    return await page.evaluate(({ objectId, photoHost, selectedClassPattern }) => {
        const selectedClassRe = new RegExp(selectedClassPattern, 'i');
        const objectPath = `/obiekty/${objectId}`;
        const photoImages = [...document.querySelectorAll('img')].filter((image) => {
            const raw = image.currentSrc || image.src || '';
            if (raw === '') {
                return false;
            }
            try {
                const url = new URL(raw, document.baseURI);
                return url.protocol === 'https:' && url.hostname === photoHost;
            } catch {
                return false;
            }
        });

        const ancestorSummary = (element) => {
            const result = [];
            let current = element;
            for (let depth = 0; current !== null && depth <= 6; depth += 1, current = current.parentElement) {
                result.push({
                    depth,
                    tag: current.tagName,
                    id: current.id || null,
                    class: typeof current.className === 'string' && current.className !== '' ? current.className : null,
                    data_plikid: current.getAttribute?.('data-plikid') ?? null,
                    aria_current: current.getAttribute?.('aria-current') ?? null,
                    data_selected: current.getAttribute?.('data-selected') ?? null,
                    href: current.href ?? null,
                });
            }
            return result;
        };

        const selectedSignals = (ancestors) => {
            const signals = [];
            for (const ancestor of ancestors) {
                if (ancestor.aria_current !== null && ancestor.aria_current !== 'false') {
                    signals.push(`aria-current:${ancestor.aria_current}`);
                }
                if (ancestor.data_selected === 'true' || ancestor.data_selected === '1') {
                    signals.push(`data-selected:${ancestor.data_selected}`);
                }
                if (ancestor.class !== null && selectedClassRe.test(ancestor.class)) {
                    signals.push(`class:${ancestor.class}`);
                }
            }
            return [...new Set(signals)];
        };

        const photos = photoImages.map((image, index) => {
            const src = new URL(image.currentSrc || image.src, document.baseURI).toString();
            const rect = image.getBoundingClientRect();
            const ancestors = ancestorSummary(image);
            const closestAnchor = image.closest('a[href]');
            const closestEntry = image.closest('[data-plikid]');
            return {
                index,
                src,
                id: image.id || null,
                class: image.className || null,
                alt: image.alt || null,
                width: Math.round(rect.width),
                height: Math.round(rect.height),
                natural_width: image.naturalWidth || 0,
                natural_height: image.naturalHeight || 0,
                visible: rect.width > 0 && rect.height > 0,
                closest_anchor_href: closestAnchor?.href ?? null,
                closest_data_plikid: closestEntry?.getAttribute('data-plikid') ?? null,
                outside_scan_entry: closestEntry === null,
                selected_signals: selectedSignals(ancestors),
                ancestors,
            };
        });

        const scanEntries = [...document.querySelectorAll('[data-plikid]')].slice(0, 250).map((entry) => {
            const images = [...entry.querySelectorAll('img')].map((image) => image.currentSrc || image.src || '').filter(Boolean);
            const anchor = entry.matches?.('a[href]') ? entry : entry.querySelector('a[href]');
            return {
                tag: entry.tagName,
                data_plikid: entry.getAttribute('data-plikid'),
                id: entry.id || null,
                class: entry.className || null,
                href: anchor?.href ?? null,
                text: (entry.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 160),
                image_srcs: images.slice(0, 5),
                outer_html: entry.outerHTML.slice(0, 800),
            };
        });

        const attributeMatches = [];
        for (const element of document.querySelectorAll('*')) {
            for (const attribute of element.attributes ?? []) {
                if (!attribute.value.includes(objectId)) {
                    continue;
                }
                attributeMatches.push({
                    tag: element.tagName,
                    id: element.id || null,
                    class: typeof element.className === 'string' && element.className !== '' ? element.className : null,
                    attribute: attribute.name,
                    value: attribute.value.slice(0, 500),
                });
                if (attributeMatches.length >= 50) {
                    break;
                }
            }
            if (attributeMatches.length >= 50) {
                break;
            }
        }

        const interestingInputs = [...document.querySelectorAll('input,button,select,textarea')]
            .filter((element) => {
                const name = element.getAttribute('name') ?? '';
                const value = element.getAttribute('value') ?? '';
                const id = element.id ?? '';
                return /plik|skan|obiekt|object/i.test(`${name} ${id}`) || value.includes(objectId);
            })
            .slice(0, 100)
            .map((element) => ({
                tag: element.tagName,
                type: element.getAttribute('type'),
                name: element.getAttribute('name'),
                id: element.id || null,
                value: (element.getAttribute('value') ?? '').slice(0, 500),
            }));

        const inlineScriptMatches = [];
        const needles = [objectId, '/skan/-/skan/', 'id-pliku', 'plikId', 'data-plikid', 'photos.szukajwarchiwach.gov.pl'];
        for (const script of document.querySelectorAll('script:not([src])')) {
            const text = script.textContent ?? '';
            for (const needle of needles) {
                let offset = text.indexOf(needle);
                while (offset >= 0 && inlineScriptMatches.length < 40) {
                    inlineScriptMatches.push({
                        needle,
                        snippet: text.slice(Math.max(0, offset - 220), Math.min(text.length, offset + needle.length + 380))
                            .replace(/\s+/g, ' ')
                            .trim(),
                    });
                    offset = text.indexOf(needle, offset + needle.length);
                }
                if (inlineScriptMatches.length >= 40) {
                    break;
                }
            }
            if (inlineScriptMatches.length >= 40) {
                break;
            }
        }

        const publicViewerLinks = [...document.querySelectorAll('a[href]')]
            .map((anchor) => anchor.href)
            .filter((href) => href.includes('/skan/-/skan/'))
            .slice(0, 100);

        const uniquePhotoSet = (items) => {
            const bySrc = new Map();
            for (const item of items) {
                if (!bySrc.has(item.src)) {
                    bySrc.set(item.src, item);
                }
            }
            return [...bySrc.values()];
        };

        const byDataPlikid = uniquePhotoSet(photos.filter((photo) => photo.closest_data_plikid === objectId));
        const byObjectAnchor = uniquePhotoSet(photos.filter((photo) => {
            if (photo.closest_anchor_href === null) {
                return false;
            }
            try {
                return new URL(photo.closest_anchor_href, document.baseURI).pathname.endsWith(objectPath);
            } catch {
                return false;
            }
        }));
        const bySelectedSignal = uniquePhotoSet(photos.filter((photo) => photo.selected_signals.length > 0));
        const outsideEntries = uniquePhotoSet(photos.filter((photo) => photo.outside_scan_entry && photo.visible));

        let bindingStrategy = null;
        let selected = null;
        for (const [strategy, matches] of [
            ['data-plikid', byDataPlikid],
            ['object-anchor', byObjectAnchor],
            ['selected-state', bySelectedSignal],
            ['single-photo-outside-scan-entry', outsideEntries],
        ]) {
            if (matches.length === 1) {
                bindingStrategy = strategy;
                selected = matches[0];
                break;
            }
        }

        let publicViewer = null;
        let explicitPhoto = null;
        let clickedTag = null;
        if (selected !== null) {
            const image = photoImages[selected.index];
            const clickable = image.closest('a,button,[role="button"]') ?? image;
            const container = image.closest('[data-plikid],li,article,figure,.item,.scan,.skan,.slide,.thumbnail,.thumb') ?? image.parentElement;
            const anchors = container === null ? [] : [...container.querySelectorAll('a[href]')];
            publicViewer = anchors.map((anchor) => anchor.href).find((href) => href.includes('/skan/-/skan/')) ?? null;
            explicitPhoto = anchors.map((anchor) => anchor.href).find((href) => {
                try {
                    const url = new URL(href, document.baseURI);
                    return url.protocol === 'https:' && url.hostname === photoHost;
                } catch {
                    return false;
                }
            }) ?? null;
            clickable.scrollIntoView({ block: 'center', inline: 'center' });
            clickable.click();
            clickedTag = clickable.tagName;
        }

        return {
            bound: selected !== null,
            binding_strategy: bindingStrategy,
            object_id: objectId,
            preview_url: selected?.src ?? null,
            public_viewer_url: publicViewer,
            explicit_photo_url: explicitPhoto,
            clicked_tag: clickedTag,
            candidate_sets: {
                by_data_plikid: byDataPlikid.map((photo) => photo.src),
                by_object_anchor: byObjectAnchor.map((photo) => photo.src),
                by_selected_signal: bySelectedSignal.map((photo) => photo.src),
                outside_scan_entries: outsideEntries.map((photo) => photo.src),
            },
            photo_elements: photos,
            scan_entries: scanEntries,
            attribute_matches: attributeMatches,
            interesting_inputs: interestingInputs,
            inline_script_matches: inlineScriptMatches,
            public_viewer_links: publicViewerLinks,
        };
    }, {
        objectId,
        photoHost: PHOTO_HOST,
        selectedClassPattern: SELECTED_CLASS_RE.source,
    });
}

async function captureExplicitBoundAsset(context, assetUrl, expectedIdentity, candidates) {
    if (photoIdentity(assetUrl) !== expectedIdentity) {
        return false;
    }

    debugEvent('scan:object-explicit-photo', { url: assetUrl });
    try {
        const response = await context.request.get(assetUrl, {
            failOnStatusCode: false,
            timeout: timeoutMs,
        });
        if (response.status() < 200 || response.status() >= 300) {
            return false;
        }
        const headers = normalizeHeaders(response.headers());
        const contentType = (headers['content-type']?.[0] ?? '').toLowerCase();
        const body = Buffer.from(await response.body());
        if (!looksLikeImage(contentType, body)) {
            return false;
        }
        candidates.push({ status: response.status(), url: response.url(), headers, body });
        return true;
    } catch {
        return false;
    }
}

async function settlePage(page) {
    await page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 10000) }).catch(() => null);
    await page.waitForTimeout(2000).catch(() => null);
}

async function waitForContext(context) {
    await Promise.all(context.pages().map(async (observedPage) => {
        await observedPage.waitForLoadState('domcontentloaded', {
            timeout: Math.min(timeoutMs, 10000),
        }).catch(() => null);
        await settlePage(observedPage);
    }));
}

function selectDirectViewerCandidate(candidates, viewerStatus, viewerUrl) {
    if (candidates.length === 0) {
        fail(
            `Scan viewer did not load a recognized image from ${PHOTO_HOST}. `
            + `Viewer status=${viewerStatus} url=${viewerUrl}`,
        );
    }
    candidates.sort(compareImageCandidates);
    const selected = candidates[0];
    debugEvent('scan:selected-candidate', { selected: summarizeCandidate(selected) });
    return responsePayload(selected);
}

function responsePayload(candidate) {
    return {
        status: candidate.status,
        url: candidate.url,
        headers: candidate.headers,
        body_base64: candidate.body.toString('base64'),
    };
}

function objectIdFromUrl(url) {
    try {
        const match = new URL(url).pathname.match(/\/obiekty\/([1-9]\d*)\/?$/);
        return match?.[1] ?? null;
    } catch {
        return null;
    }
}

function identityFromPhotoUrl(url, photoHost) {
    try {
        const parsed = new URL(url);
        if (parsed.protocol !== 'https:' || parsed.hostname !== photoHost) {
            return null;
        }
        const name = parsed.pathname.split('/').filter(Boolean).at(-1) ?? '';
        return name.match(/^([A-Za-z0-9._~-]+)_(?:max|mid|min|thumb)$/i)?.[1] ?? null;
    } catch {
        return null;
    }
}

function photoIdentity(url) {
    return identityFromPhotoUrl(url, PHOTO_HOST);
}

function isPublicViewerUrl(url) {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'https:'
            && ['szukajwarchiwach.gov.pl', 'www.szukajwarchiwach.gov.pl'].includes(parsed.hostname)
            && /^\/skan\/-\/skan\/[A-Za-z0-9_-]+\/?$/.test(parsed.pathname);
    } catch {
        return false;
    }
}

function compareImageCandidates(left, right) {
    const qualityDifference = imageQualityRank(right.url) - imageQualityRank(left.url);
    if (qualityDifference !== 0) {
        return qualityDifference;
    }
    return right.body.length - left.body.length;
}

function imageQualityRank(url) {
    try {
        const pathname = new URL(url).pathname.toLowerCase();
        if (pathname.endsWith('_max')) {
            return 3;
        }
        if (pathname.endsWith('_mid')) {
            return 2;
        }
        if (pathname.endsWith('_min') || pathname.endsWith('_thumb')) {
            return 1;
        }
    } catch {
        return 0;
    }
    return 0;
}

function isKnownPreviewAsset(url) {
    const rank = imageQualityRank(url);
    return rank > 0 && rank < 3;
}

async function capturePhotoCandidate(response, candidates) {
    let url;
    try {
        url = new URL(response.url());
    } catch {
        return;
    }

    if (url.hostname !== PHOTO_HOST || response.status() < 200 || response.status() >= 300) {
        return;
    }

    const headers = normalizeHeaders(response.headers());
    const contentType = (headers['content-type']?.[0] ?? '').toLowerCase();
    if (!contentType.startsWith('image/')) {
        return;
    }

    let body;
    try {
        body = Buffer.from(await response.body());
    } catch {
        return;
    }

    if (!looksLikeImage(contentType, body)) {
        return;
    }

    candidates.push({ status: response.status(), url: response.url(), headers, body });
}

function observeDebugPage(page) {
    if (debugDir === null) {
        return;
    }

    page.on('framenavigated', (frame) => {
        if (frame === page.mainFrame()) {
            debugEvent('browser:navigation', { url: frame.url() });
        }
    });

    page.on('response', (response) => {
        let hostname = '';
        try {
            hostname = new URL(response.url()).hostname;
        } catch {
            return;
        }

        const contentType = response.headers()['content-type'] ?? '';
        if (hostname.endsWith('szukajwarchiwach.gov.pl') || contentType.toLowerCase().startsWith('image/')) {
            debugEvent('browser:response', {
                status: response.status(),
                url: response.url(),
                content_type: contentType,
                resource_type: response.request().resourceType(),
            });
        }
    });
}

function isBlockPage(title, body) {
    const text = `${title}\n${body.toString('utf8', 0, Math.min(body.length, 65536))}`.toLowerCase();
    return text.includes('request unsuccessful')
        || text.includes('incapsula incident id')
        || text.includes('<title>forbidden</title>')
        || text.includes('access denied');
}

function looksLikeImage(contentType, body) {
    if (contentType.startsWith('image/')) {
        return true;
    }
    return (body.length >= 3 && body[0] === 0xFF && body[1] === 0xD8 && body[2] === 0xFF)
        || (body.length >= 8 && body.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A])))
        || (body.length >= 12 && body.subarray(0, 4).toString('ascii') === 'RIFF' && body.subarray(8, 12).toString('ascii') === 'WEBP');
}

function normalizeHeaders(headers) {
    const normalized = {};
    for (const [name, value] of Object.entries(headers)) {
        normalized[name.toLowerCase()] = Array.isArray(value) ? value : [String(value)];
    }
    return normalized;
}

function summarizeCandidate(candidate) {
    return {
        status: candidate.status,
        url: candidate.url,
        content_type: candidate.headers['content-type']?.[0] ?? null,
        body_bytes: candidate.body.length,
    };
}

function summarizeCandidates(candidates) {
    return candidates.map(summarizeCandidate);
}

function debugEvent(type, details = {}) {
    if (debugDir === null) {
        return;
    }
    debugState.events.push({ at: new Date().toISOString(), type, ...details });
}

function validateArguments() {
    if (action !== 'scan-image') {
        fail('Expected action scan-image.');
    }
    let parsed;
    try {
        parsed = new URL(targetUrl);
    } catch {
        fail('Expected a valid target URL.');
    }
    if (
        parsed.protocol !== 'https:'
        || !['www.szukajwarchiwach.gov.pl', 'szukajwarchiwach.gov.pl'].includes(parsed.hostname)
    ) {
        fail('Target URL must use https on szukajwarchiwach.gov.pl.');
    }
    if (!Number.isInteger(timeoutMs) || timeoutMs < 1000) {
        fail('Timeout must be at least 1000 ms.');
    }
}

function fail(message) {
    throw new Error(message);
}

main()
    .then((result) => {
        process.stdout.write(`${JSON.stringify(result)}\n`);
    })
    .catch((error) => {
        process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
        process.exitCode = 1;
    });
