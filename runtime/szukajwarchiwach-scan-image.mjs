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
    let page = null;
    let video = null;
    let failure = null;
    let result = null;

    try {
        browser = await chromium.launch({ headless: true });
        context = await browser.newContext(debugDir === null ? {} : {
            viewport: { width: 1280, height: 720 },
            recordVideo: {
                dir: debugDir,
                size: { width: 1280, height: 720 },
            },
        });
        page = await context.newPage();
        video = page.video();
        observeDebugPage(page);
        context.on('page', observeDebugPage);

        debugEvent('bootstrap:start', { url: BOOTSTRAP_URL });
        const bootstrapResponse = await page.goto(BOOTSTRAP_URL, {
            waitUntil: 'domcontentloaded',
            timeout: timeoutMs,
        });
        await page.waitForTimeout(1000);
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
            await context.close().catch((error) => {
                debugEvent('context:close-error', { message: String(error) });
            });
        }

        if (debugDir !== null && video !== null) {
            const videoPath = path.join(debugDir, `${runId}.webm`);
            try {
                await video.saveAs(videoPath);
                debugState.video = videoPath;
            } catch (error) {
                debugState.video_error = String(error);
            }
        }

        if (browser !== null) {
            await browser.close().catch(() => null);
        }

        if (debugDir !== null) {
            debugState.finished_at = new Date().toISOString();
            const manifestPath = path.join(debugDir, `${runId}.json`);
            await writeFile(manifestPath, JSON.stringify(debugState, null, 2), 'utf8').catch(() => null);
            process.stderr.write(`Browser debug manifest: ${manifestPath}\n`);
            if (typeof debugState.video === 'string') {
                process.stderr.write(`Browser debug video: ${debugState.video}\n`);
            }
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

    await page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 15000) }).catch(() => null);
    await page.waitForTimeout(2500);
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
        fail(
            `Resolved object ${objectId} is bound to an unsupported preview URL ${binding.preview_url}.`,
        );
    }

    debugEvent('scan:object-identity', {
        object_id: objectId,
        preview_url: binding.preview_url,
        identity: targetIdentity,
    });

    if (binding.public_viewer_url !== null) {
        debugEvent('scan:object-public-viewer', { url: binding.public_viewer_url });
        await page.goto(binding.public_viewer_url, {
            waitUntil: 'domcontentloaded',
            timeout: timeoutMs,
        });
    }

    await waitForContext(context);
    await Promise.allSettled(pending);

    const publicViewerAfterActivation = await discoverNewPublicViewer(context);
    if (publicViewerAfterActivation !== null && page.url() !== publicViewerAfterActivation) {
        debugEvent('scan:object-public-viewer-after-activation', { url: publicViewerAfterActivation });
        await page.goto(publicViewerAfterActivation, {
            waitUntil: 'domcontentloaded',
            timeout: timeoutMs,
        });
        await waitForContext(context);
        await Promise.allSettled(pending);
    }

    const targetCandidates = candidates.filter((candidate) => photoIdentity(candidate.url) === targetIdentity);
    targetCandidates.sort(compareImageCandidates);

    debugEvent('scan:object-target-candidates', {
        object_id: objectId,
        identity: targetIdentity,
        candidates: summarizeCandidates(targetCandidates),
    });

    if (targetCandidates.length === 0) {
        fail(
            `Object viewer did not load any image response belonging to resolved object ${objectId}.`,
        );
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
        const roots = [];

        for (const selector of [
            `[data-plikid="${objectId}"]`,
            `[data-object-id="${objectId}"]`,
            `[data-id="${objectId}"]`,
        ]) {
            roots.push(...document.querySelectorAll(selector));
        }

        for (const anchor of document.querySelectorAll('a[href]')) {
            try {
                const url = new URL(anchor.href, document.baseURI);
                if (url.pathname.endsWith(objectPath)) {
                    roots.push(anchor);
                }
            } catch {
                // Ignore malformed href values.
            }
        }

        const uniqueRoots = [...new Set(roots)];
        const matches = [];

        for (const root of uniqueRoots) {
            const images = root.matches?.('img') ? [root] : [...root.querySelectorAll('img')];
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

                    const publicViewer = [...root.querySelectorAll('a[href]')]
                        .map((anchor) => anchor.href)
                        .find((href) => href.includes('/skan/-/skan/')) ?? null;

                    matches.push({ root, image, previewUrl: url.toString(), publicViewer });
                } catch {
                    // Ignore malformed image URLs.
                }
            }
        }

        const distinct = [];
        const seen = new Set();
        for (const match of matches) {
            const key = match.previewUrl;
            if (!seen.has(key)) {
                seen.add(key);
                distinct.push(match);
            }
        }

        if (distinct.length !== 1) {
            return {
                bound: false,
                object_id: objectId,
                preview_url: null,
                public_viewer_url: null,
                matching_preview_urls: distinct.map((match) => match.previewUrl),
                root_count: uniqueRoots.length,
            };
        }

        const match = distinct[0];
        const clickable = match.image.closest('button,[role="button"]') ?? match.image;
        clickable.scrollIntoView({ block: 'center', inline: 'center' });
        clickable.click();

        return {
            bound: true,
            object_id: objectId,
            preview_url: match.previewUrl,
            public_viewer_url: match.publicViewer,
            matching_preview_urls: [match.previewUrl],
            root_count: uniqueRoots.length,
            clicked_tag: clickable.tagName,
        };
    }, { objectId, photoHost: PHOTO_HOST });
}

async function waitForContext(context) {
    await Promise.all(context.pages().map(async (observedPage) => {
        await observedPage.waitForLoadState('domcontentloaded', {
            timeout: Math.min(timeoutMs, 10000),
        }).catch(() => null);
        await observedPage.waitForLoadState('networkidle', {
            timeout: Math.min(timeoutMs, 10000),
        }).catch(() => null);
        await observedPage.waitForTimeout(2500).catch(() => null);
    }));
}

async function discoverNewPublicViewer(context) {
    for (const observedPage of context.pages()) {
        const current = observedPage.url();
        if (isPublicViewerUrl(current)) {
            return current;
        }

        const candidate = await observedPage.evaluate(() => {
            for (const anchor of document.querySelectorAll('a[href]')) {
                if (anchor.href.includes('/skan/-/skan/')) {
                    return anchor.href;
                }
            }
            return null;
        }).catch(() => null);

        if (candidate !== null && isPublicViewerUrl(candidate)) {
            return candidate;
        }
    }

    return null;
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

function photoIdentity(url) {
    try {
        const parsed = new URL(url);
        if (parsed.protocol !== 'https:' || parsed.hostname !== PHOTO_HOST) {
            return null;
        }

        const name = parsed.pathname.split('/').filter(Boolean).at(-1) ?? '';
        const match = name.match(/^([A-Za-z0-9._~-]+)_(?:max|mid|min|thumb)$/i);
        return match?.[1] ?? null;
    } catch {
        return null;
    }
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

    candidates.push({
        status: response.status(),
        url: response.url(),
        headers,
        body,
    });
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

function summarizeCandidate(candidate) {
    return {
        status: candidate.status,
        url: candidate.url,
        content_type: candidate.headers['content-type']?.[0] ?? '',
        body_bytes: candidate.body.length,
    };
}

function summarizeCandidates(candidates) {
    return candidates.map(summarizeCandidate);
}

function looksLikeImage(contentType, body) {
    return contentType.startsWith('image/')
        || (body.length >= 3 && body[0] === 0xff && body[1] === 0xd8 && body[2] === 0xff)
        || (body.length >= 8 && body[0] === 0x89 && body[1] === 0x50 && body[2] === 0x4e && body[3] === 0x47);
}

function isBlockPage(title, body) {
    const text = body.subarray(0, Math.min(body.length, 16384)).toString('utf8').toLowerCase();
    const normalizedTitle = String(title).trim().toLowerCase();
    return normalizedTitle === '403 forbidden'
        || text.includes('request unsuccessful. incapsula')
        || text.includes('incapsula incident id');
}

function normalizeHeaders(headers) {
    return Object.fromEntries(
        Object.entries(headers).map(([name, value]) => [name.toLowerCase(), [String(value)]]),
    );
}

function debugEvent(type, details = {}) {
    if (debugDir === null) {
        return;
    }
    debugState.events.push({
        at: new Date().toISOString(),
        type,
        ...details,
    });
}

function validateArguments() {
    if (action !== 'scan-image') {
        fail('Object-bound worker accepts only scan-image action.');
    }
    if (!Number.isFinite(timeoutMs) || timeoutMs < 1000) {
        fail('Browser timeout must be at least 1000 ms.');
    }

    let parsedTarget;
    try {
        parsedTarget = new URL(targetUrl);
    } catch {
        fail('Target URL is invalid.');
    }

    if (
        parsedTarget.protocol !== 'https:'
        || !['szukajwarchiwach.gov.pl', 'www.szukajwarchiwach.gov.pl'].includes(parsedTarget.hostname)
    ) {
        fail('Target URL must use the current szukajwarchiwach.gov.pl host family.');
    }
}

function fail(message) {
    throw new Error(message);
}

main()
    .then((payload) => {
        process.stdout.write(JSON.stringify(payload));
    })
    .catch((error) => {
        process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
        process.exitCode = 1;
    });
