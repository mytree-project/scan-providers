#!/usr/bin/env node

import process from 'node:process';
import path from 'node:path';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const BOOTSTRAP_URL = 'https://www.szukajwarchiwach.gov.pl/';
const PHOTO_HOST = 'photos.szukajwarchiwach.gov.pl';

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

    const binding = await bindAndActivateObject(page, objectId);
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

async function bindAndActivateObject(page, objectId) {
    return await page.evaluate(({ objectId, photoHost }) => {
        const objectPath = `/obiekty/${objectId}`;
        const markers = [];

        for (const selector of [
            `[data-plikid="${objectId}"]`,
            `[data-object-id="${objectId}"]`,
            `[data-id="${objectId}"]`,
        ]) {
            markers.push(...document.querySelectorAll(selector));
        }

        for (const anchor of document.querySelectorAll('a[href]')) {
            try {
                const url = new URL(anchor.href, document.baseURI);
                if (url.pathname.endsWith(objectPath)) {
                    markers.push(anchor);
                }
            } catch {
                // Ignore malformed href values.
            }
        }

        const uniqueMarkers = [...new Set(markers)];
        const diagnostics = uniqueMarkers.map((marker) => ({
            tag: marker.tagName,
            id: marker.id || null,
            class: marker.className || null,
            data_plikid: marker.getAttribute?.('data-plikid') ?? null,
            href: marker.href ?? null,
        }));
        const bindings = [];

        for (const marker of uniqueMarkers) {
            let container = marker;
            for (let depth = 0; container !== null && depth <= 8; depth += 1, container = container.parentElement) {
                const images = [];
                if (container.matches?.('img')) {
                    images.push(container);
                }
                images.push(...container.querySelectorAll('img'));

                const photoImages = [];
                const seenImages = new Set();
                for (const image of images) {
                    const raw = image.currentSrc || image.src || '';
                    if (raw === '') {
                        continue;
                    }
                    try {
                        const url = new URL(raw, document.baseURI);
                        if (url.protocol !== 'https:' || url.hostname !== photoHost) {
                            continue;
                        }
                        if (!seenImages.has(url.toString())) {
                            seenImages.add(url.toString());
                            photoImages.push({ image, url: url.toString() });
                        }
                    } catch {
                        // Ignore malformed image URLs.
                    }
                }

                if (photoImages.length !== 1) {
                    continue;
                }

                const anchors = [...container.querySelectorAll('a[href]')];
                if (container.matches?.('a[href]')) {
                    anchors.unshift(container);
                }

                const publicViewer = anchors
                    .map((anchor) => anchor.href)
                    .find((href) => href.includes('/skan/-/skan/')) ?? null;
                const photoLinks = anchors
                    .map((anchor) => anchor.href)
                    .filter((href) => {
                        try {
                            const url = new URL(href, document.baseURI);
                            return url.protocol === 'https:' && url.hostname === photoHost;
                        } catch {
                            return false;
                        }
                    });

                bindings.push({
                    marker,
                    container,
                    image: photoImages[0].image,
                    previewUrl: photoImages[0].url,
                    publicViewer,
                    photoLinks,
                    depth,
                });
                break;
            }
        }

        const bestDepth = bindings.length === 0
            ? null
            : Math.min(...bindings.map((binding) => binding.depth));
        const bestBindings = bindings.filter((binding) => binding.depth === bestDepth);
        const distinctByPreview = new Map();
        for (const binding of bestBindings) {
            if (!distinctByPreview.has(binding.previewUrl)) {
                distinctByPreview.set(binding.previewUrl, binding);
            }
        }

        const compactBindings = [...distinctByPreview.values()];
        if (compactBindings.length !== 1) {
            return {
                bound: false,
                object_id: objectId,
                preview_url: null,
                public_viewer_url: null,
                explicit_photo_url: null,
                marker_count: uniqueMarkers.length,
                markers: diagnostics,
                best_depth: bestDepth,
                matching_preview_urls: compactBindings.map((binding) => binding.previewUrl),
            };
        }

        const binding = compactBindings[0];
        const previewIdentity = identityFromPhotoUrl(binding.previewUrl, photoHost);
        const explicitPhoto = binding.photoLinks.find((href) => (
            identityFromPhotoUrl(href, photoHost) === previewIdentity
        )) ?? null;
        const clickable = binding.image.closest('a,button,[role="button"]') ?? binding.image;

        clickable.scrollIntoView({ block: 'center', inline: 'center' });
        clickable.click();

        return {
            bound: true,
            object_id: objectId,
            preview_url: binding.previewUrl,
            public_viewer_url: binding.publicViewer,
            explicit_photo_url: explicitPhoto,
            marker_count: uniqueMarkers.length,
            markers: diagnostics,
            best_depth: binding.depth,
            matching_preview_urls: [binding.previewUrl],
            clicked_tag: clickable.tagName,
        };
    }, { objectId, photoHost: PHOTO_HOST });
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
