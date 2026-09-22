#!/usr/bin/env node

import process from 'node:process';
import { chromium } from 'playwright';

const BOOTSTRAP_URL = 'https://www.szukajwarchiwach.gov.pl/';
const PHOTO_HOST = 'photos.szukajwarchiwach.gov.pl';

const action = process.argv[2] ?? '';
const targetUrl = process.argv[3] ?? '';
const timeoutArg = process.argv.slice(4).find((argument) => argument.startsWith('--timeout-ms='));
const timeoutMs = Number.parseInt(timeoutArg?.slice('--timeout-ms='.length) ?? '60000', 10);

if (!['page', 'scan-image'].includes(action)) {
    fail('Expected action page or scan-image.');
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

const browser = await chromium.launch({ headless: true });

try {
    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto(BOOTSTRAP_URL, {
        waitUntil: 'domcontentloaded',
        timeout: timeoutMs,
    });
    await page.waitForTimeout(1000);

    if (action === 'page') {
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
        if (isBlockPage(title, body)) {
            fail(`Browser session received an Imperva/forbidden page for ${page.url()}.`);
        }

        emit({
            status: response.status(),
            url: page.url(),
            headers: normalizeHeaders(response.headers()),
            body_base64: body.toString('base64'),
        });
    }

    if (action === 'scan-image') {
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

        if (isObjectViewer(targetUrl)) {
            await captureExplicitPhotoAsset(context, page, candidates, timeoutMs);

            const publicViewerUrl = await discoverPublicScanViewerUrl(page);
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

        if (isObjectViewer(targetUrl) && isKnownPreviewAsset(selected.url)) {
            const observed = [...new Set(candidates.map((candidate) => candidate.url))].join(', ');
            fail(
                'Object viewer exposed only preview-quality image responses; '
                + `original/raw scan asset was not observed. Candidates: ${observed}`,
            );
        }

        emit({
            status: selected.status,
            url: selected.url,
            headers: selected.headers,
            body_base64: selected.body.toString('base64'),
        });
    }
} finally {
    await browser.close();
}

async function captureExplicitPhotoAsset(context, page, candidates, timeoutMs) {
    const assetUrl = await discoverExplicitPhotoAssetUrl(page);
    if (assetUrl === null) {
        return false;
    }

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
        const normalizedHtml = rawHtml.replaceAll('\\\\/', '/');
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
    }, photoHost).catch(() => false);
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

function emit(payload) {
    process.stdout.write(JSON.stringify(payload));
}

function fail(message) {
    process.stderr.write(message + '\n');
    process.exit(1);
}
