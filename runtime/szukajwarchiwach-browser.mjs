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
const runId = `${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}-${action}`;
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
    let result = null;
    let failure = null;

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
        context.on('page', (newPage) => observeDebugPage(newPage));

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

        if (action === 'page') {
            result = await fetchRenderedPage(page);
        } else if (action === 'scan-image') {
            result = await fetchScanImage(context, page);
        } else {
            fail('Expected action page or scan-image.');
        }
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

async function fetchRenderedPage(page) {
    debugEvent('page:navigate:start', { url: targetUrl });
    const response = await page.goto(targetUrl, {
        waitUntil: 'domcontentloaded',
        timeout: timeoutMs,
    });

    if (response === null) {
        fail('Browser navigation did not produce an HTTP response.');
    }

    await page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 10000) }).catch(() => null);

    const body = Buffer.from(await page.content(), 'utf8');
    const title = await page.title().catch(() => '');
    debugEvent('page:navigate:done', {
        status: response.status(),
        url: page.url(),
        title,
        body_bytes: body.length,
        scan_entry_count: await visibleScanEntryCount(page),
    });

    if (isBlockPage(title, body)) {
        fail(`Browser session received an Imperva/forbidden page for ${page.url()}.`);
    }

    return {
        status: response.status(),
        url: page.url(),
        headers: normalizeHeaders(response.headers()),
        body_base64: body.toString('base64'),
    };
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
    debugEvent('scan:navigate:done', {
        status: viewerResponse.status(),
        url: page.url(),
        title: await page.title().catch(() => ''),
        candidates: summarizeCandidates(candidates),
    });

    if (isObjectViewer(targetUrl)) {
        const explicitAssetCaptured = await captureExplicitPhotoAsset(context, page, candidates, timeoutMs);
        debugEvent('scan:object-viewer:explicit-photo', {
            captured: explicitAssetCaptured,
            candidates: summarizeCandidates(candidates),
        });

        const publicViewerUrl = await discoverPublicScanViewerUrl(page);
        debugEvent('scan:object-viewer:public-viewer', { url: publicViewerUrl });
        if (publicViewerUrl !== null) {
            await page.goto(publicViewerUrl, {
                waitUntil: 'domcontentloaded',
                timeout: timeoutMs,
            });
            await page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 15000) }).catch(() => null);
            await page.waitForTimeout(2500);
            await Promise.allSettled(pending);
        } else {
            const activated = await activatePrimaryPhoto(page);
            debugEvent('scan:object-viewer:activate-primary-photo', { activated });
            if (activated) {
                await page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 10000) }).catch(() => null);
                await page.waitForTimeout(3500);

                for (const extraPage of context.pages()) {
                    if (extraPage !== page) {
                        await extraPage.waitForLoadState('domcontentloaded', { timeout: Math.min(timeoutMs, 10000) }).catch(() => null);
                        await extraPage.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 10000) }).catch(() => null);
                    }

                    await captureExplicitPhotoAsset(context, extraPage, candidates, timeoutMs);
                }

                await Promise.allSettled(pending);

                const discoveredAfterActivation = await discoverPublicScanViewerFromContext(context);
                debugEvent('scan:object-viewer:public-viewer-after-activation', { url: discoveredAfterActivation });
                if (discoveredAfterActivation !== null) {
                    await page.goto(discoveredAfterActivation, {
                        waitUntil: 'domcontentloaded',
                        timeout: timeoutMs,
                    });
                    await page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 15000) }).catch(() => null);
                    await page.waitForTimeout(2500);
                    await Promise.allSettled(pending);
                }
            }
        }
    }

    if (candidates.length === 0) {
        fail(
            `Scan viewer did not load a recognized image from ${PHOTO_HOST}. `
            + `Viewer status=${viewerResponse.status()} url=${page.url()}`,
        );
    }

    candidates.sort(compareImageCandidates);
    const selected = candidates[0];
    debugEvent('scan:selected-candidate', {
        selected: summarizeCandidate(selected),
        candidates: summarizeCandidates(candidates),
    });

    if (isObjectViewer(targetUrl) && isKnownPreviewAsset(selected.url)) {
        const observed = [...new Set(candidates.map((candidate) => candidate.url))].join(', ');
        fail(
            'Object viewer exposed only preview-quality image responses; '
            + `original/raw scan asset was not observed. Candidates: ${observed}`,
        );
    }

    return {
        status: selected.status,
        url: selected.url,
        headers: selected.headers,
        body_base64: selected.body.toString('base64'),
    };
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
        if (
            hostname.endsWith('szukajwarchiwach.gov.pl')
            || contentType.toLowerCase().startsWith('image/')
        ) {
            debugEvent('browser:response', {
                status: response.status(),
                url: response.url(),
                content_type: contentType,
                resource_type: response.request().resourceType(),
            });
        }
    });
}

async function visibleScanEntryCount(page) {
    return await page.locator('[data-plikid]').count().catch(() => 0);
}

async function captureExplicitPhotoAsset(context, page, candidates, timeoutMs) {
    const assetUrl = await discoverExplicitPhotoAssetUrl(page);
    if (assetUrl === null) {
        return false;
    }

    debugEvent('scan:explicit-photo-url', { url: assetUrl });

    let response;
    try {
        response = await context.request.get(assetUrl, {
            failOnStatusCode: false,
            timeout: timeoutMs,
        });
    } catch {
        return false;
    }

    if (response.status() < 200 || response.status() >= 300) {
        return false;
    }

    const headers = normalizeHeaders(response.headers());
    const contentType = (headers['content-type']?.[0] ?? '').toLowerCase();

    let body;
    try {
        body = Buffer.from(await response.body());
    } catch {
        return false;
    }

    if (!looksLikeImage(contentType, body)) {
        return false;
    }

    candidates.push({
        status: response.status(),
        url: response.url(),
        headers,
        body,
    });

    return true;
}

async function discoverExplicitPhotoAssetUrl(page) {
    const candidates = await page.evaluate((photoHost) => {
        const values = new Set();

        for (const anchor of document.querySelectorAll('a[href]')) {
            try {
                const url = new URL(anchor.href, document.baseURI);
                if (url.protocol === 'https:' && url.hostname === photoHost) {
                    values.add(url.toString());
                }
            } catch {
                // Ignore malformed UI references.
            }
        }

        const rawHtml = document.documentElement?.innerHTML ?? '';
        const normalizedHtml = rawHtml.replaceAll('\\/', '/');
        const pattern = /https:\/\/photos\.szukajwarchiwach\.gov\.pl\/[A-Za-z0-9._~-]+/g;
        for (const match of normalizedHtml.matchAll(pattern)) {
            values.add(match[0]);
        }

        return [...values];
    }, PHOTO_HOST).catch(() => []);

    return candidates
        .filter((candidate) => {
            try {
                const url = new URL(candidate);
                return url.protocol === 'https:' && url.hostname === PHOTO_HOST;
            } catch {
                return false;
            }
        })
        .sort((left, right) => {
            const qualityDifference = imageQualityRank(right) - imageQualityRank(left);
            if (qualityDifference !== 0) {
                return qualityDifference;
            }

            return left.localeCompare(right);
        })[0] ?? null;
}

async function discoverPublicScanViewerUrl(page) {
    const candidate = await page.evaluate(() => {
        const pattern = /https?:\/\/(?:www\.)?szukajwarchiwach\.gov\.pl\/skan\/-\/skan\/[A-Za-z0-9_-]+|\/skan\/-\/skan\/[A-Za-z0-9_-]+/g;
        const values = new Set();

        for (const anchor of document.querySelectorAll('a[href]')) {
            if (anchor.href.includes('/skan/-/skan/')) {
                values.add(anchor.href);
            }
        }

        for (const entry of performance.getEntriesByType('resource')) {
            if (entry.name.includes('/skan/-/skan/')) {
                values.add(entry.name);
            }
        }

        const rawHtml = document.documentElement?.innerHTML ?? '';
        const normalizedHtml = rawHtml.replaceAll('\\/', '/');
        for (const match of normalizedHtml.matchAll(pattern)) {
            values.add(match[0]);
        }

        return [...values][0] ?? null;
    }).catch(() => null);

    if (candidate === null) {
        return null;
    }

    try {
        const url = new URL(candidate, page.url());
        if (
            url.protocol === 'https:'
            && ['szukajwarchiwach.gov.pl', 'www.szukajwarchiwach.gov.pl'].includes(url.hostname)
            && /^\/skan\/-\/skan\/[A-Za-z0-9_-]+\/?$/.test(url.pathname)
        ) {
            return url.toString();
        }
    } catch {
        return null;
    }

    return null;
}

function compareImageCandidates(left, right) {
    const qualityDifference = imageQualityRank(right.url) - imageQualityRank(left.url);
    if (qualityDifference !== 0) {
        return qualityDifference;
    }

    return right.body.length - left.body.length;
}

function isKnownPreviewAsset(url) {
    try {
        const pathname = new URL(url).pathname.toLowerCase();
        return pathname.endsWith('_mid')
            || pathname.endsWith('_min')
            || pathname.endsWith('_thumb');
    } catch {
        return false;
    }
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

function summarizeCandidate(candidate) {
    return {
        status: candidate.status,
        url: candidate.url,
        content_type: candidate.headers['content-type']?.[0] ?? '',
        body_bytes: candidate.body.length,
    };
}

function summarizeCandidates(candidates) {
    return candidates.map((candidate) => summarizeCandidate(candidate));
}

async function discoverPublicScanViewerFromContext(context) {
    for (const observedPage of context.pages()) {
        const candidate = await discoverPublicScanViewerUrl(observedPage);
        if (candidate !== null) {
            return candidate;
        }
    }

    return null;
}

function isObjectViewer(url) {
    try {
        return /\/jednostka\/-\/jednostka\/[1-9]\d*\/obiekty\/[1-9]\d*\/?$/.test(new URL(url).pathname);
    } catch {
        return false;
    }
}

async function activatePrimaryPhoto(page) {
    return await page.evaluate((photoHost) => {
        const images = [...document.images]
            .filter((image) => {
                const value = image.currentSrc || image.src;
                if (!value) {
                    return false;
                }

                try {
                    return new URL(value, document.baseURI).hostname === photoHost;
                } catch {
                    return false;
                }
            })
            .sort((left, right) => {
                const leftArea = (left.naturalWidth || left.width || 0) * (left.naturalHeight || left.height || 0);
                const rightArea = (right.naturalWidth || right.width || 0) * (right.naturalHeight || right.height || 0);

                return rightArea - leftArea;
            });

        const image = images[0];
        if (!image) {
            return false;
        }

        const clickable = image.closest('a,button,[role="button"]') ?? image;
        clickable.click();

        return true;
    }, PHOTO_HOST).catch(() => false);
}

async function capturePhotoCandidate(response, candidates) {
    let url;
    try {
        url = new URL(response.url());
    } catch {
        return;
    }

    if (url.hostname !== PHOTO_HOST) {
        return;
    }

    if (response.status() < 200 || response.status() >= 300) {
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

function looksLikeImage(contentType, body) {
    if (contentType.startsWith('image/')) {
        return true;
    }

    return (
        (body.length >= 3 && body[0] === 0xff && body[1] === 0xd8 && body[2] === 0xff)
        || (body.length >= 8 && body[0] === 0x89 && body[1] === 0x50 && body[2] === 0x4e && body[3] === 0x47)
    );
}

function isBlockPage(title, body) {
    const text = body.subarray(0, Math.min(body.length, 16384)).toString('utf8').toLowerCase();
    const normalizedTitle = String(title).trim().toLowerCase();

    return normalizedTitle === '403 forbidden'
        || text.includes('request unsuccessful. incapsula')
        || text.includes('incapsula incident id')
        || text.includes('/_incapsula_resource');
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
    if (!['page', 'scan-image'].includes(action)) {
        fail('Expected action page or scan-image.');
    }
    if (!Number.isFinite(timeoutMs) || timeoutMs < 1000) {
        fail('Browser timeout must be at least 1000 ms.');
    }
    if (debugDirArg !== undefined && debugDirArg.slice('--debug-dir='.length).trim() === '') {
        fail('Browser debug directory cannot be empty.');
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
