#!/usr/bin/env node

import process from 'node:process';
import path from 'node:path';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const BOOTSTRAP_URL = 'https://www.szukajwarchiwach.gov.pl/';
const PORTAL_HOSTS = new Set(['www.szukajwarchiwach.gov.pl', 'szukajwarchiwach.gov.pl']);
const PHOTO_HOST = 'photos.szukajwarchiwach.gov.pl';
const UNIT_PAGE_SIZE = 200;

const action = process.argv[2] ?? '';
const targetUrl = process.argv[3] ?? '';
const extraArgs = process.argv.slice(4);
const timeoutArg = extraArgs.find((argument) => argument.startsWith('--timeout-ms='));
const debugDirArg = extraArgs.find((argument) => argument.startsWith('--debug-dir='));
const scanNumberArg = extraArgs.find((argument) => argument.startsWith('--scan-number='));
const expectedObjectIdArg = extraArgs.find((argument) => argument.startsWith('--expected-object-id='));

const timeoutMs = Number.parseInt(timeoutArg?.slice('--timeout-ms='.length) ?? '60000', 10);
const scanNumber = scanNumberArg === undefined
    ? null
    : Number.parseInt(scanNumberArg.slice('--scan-number='.length), 10);
const expectedObjectId = expectedObjectIdArg === undefined
    ? null
    : expectedObjectIdArg.slice('--expected-object-id='.length);
const debugDir = debugDirArg === undefined
    ? null
    : path.resolve(process.cwd(), debugDirArg.slice('--debug-dir='.length));

const runId = `${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}-${action}`;
const debugState = {
    schema: 'mytree.szukajwarchiwach-browser-debug.v1',
    run_id: runId,
    action,
    target_url: targetUrl,
    scan_number: scanNumber,
    expected_object_id: expectedObjectId,
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

        if (action === 'scan-image') {
            result = await fetchDirectViewerImage(context, page, targetUrl);
        } else {
            result = await fetchUnitOrdinalImage(
                context,
                page,
                targetUrl,
                scanNumber,
                expectedObjectId,
            );
        }
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

async function fetchUnitOrdinalImage(context, page, unitUrl, ordinal, expectedId) {
    const unitId = unitIdFromUrl(unitUrl);
    if (unitId === null) {
        fail(`Unit-scan browser flow received an unsupported unit URL: ${unitUrl}`);
    }

    debugEvent('unit:navigate:start', { url: unitUrl, unit_id: unitId });
    const response = await page.goto(canonicalUnitUrl(unitId), {
        waitUntil: 'domcontentloaded',
        timeout: timeoutMs,
    });
    if (response === null) {
        fail('Unit navigation did not produce an HTTP response.');
    }

    await settlePage(page);
    await assertNotBlocked(page);

    debugEvent('unit:navigate:done', {
        status: response.status(),
        url: page.url(),
        title: await page.title().catch(() => ''),
        thumbnail_count: await page.locator('a.load-photo-slider').count().catch(() => 0),
    });

    await choosePageSize200(page);

    const targetPageNumber = Math.floor((ordinal - 1) / UNIT_PAGE_SIZE) + 1;
    const indexOnPage = (ordinal - 1) % UNIT_PAGE_SIZE;

    if (targetPageNumber > 1) {
        await chooseCatalogPage(page, targetPageNumber);
    }

    await settlePage(page);
    await assertNotBlocked(page);

    const thumbnails = page.locator('a.load-photo-slider');
    const thumbnailCount = await thumbnails.count();
    if (indexOnPage >= thumbnailCount) {
        fail(
            `Szukaj w Archiwach unit page ${targetPageNumber} contains ${thumbnailCount} visible scan thumbnails; `
            + `cannot select scan ${ordinal} at page offset ${indexOnPage + 1}.`,
        );
    }

    const thumbnail = thumbnails.nth(indexOnPage);
    const objectId = await thumbnail.getAttribute('data-plikid');
    const previewUrl = await thumbnail.locator('img').first().getAttribute('src').catch(() => null);
    const displayedOrdinalText = await thumbnail
        .locator('xpath=..')
        .locator('.number-of-scan')
        .first()
        .textContent()
        .catch(() => null);
    const displayedOrdinal = displayedOrdinalText === null
        ? null
        : Number.parseInt(displayedOrdinalText.trim(), 10);

    debugEvent('unit:thumbnail-selected', {
        scan_number: ordinal,
        target_page: targetPageNumber,
        index_on_page: indexOnPage + 1,
        thumbnail_count: thumbnailCount,
        displayed_scan_number: Number.isInteger(displayedOrdinal) ? displayedOrdinal : null,
        object_id: objectId,
        preview_url: previewUrl,
    });

    if (displayedOrdinal !== null && Number.isInteger(displayedOrdinal) && displayedOrdinal !== ordinal) {
        fail(
            `Szukaj w Archiwach scan ordering mismatch: requested ${ordinal}, `
            + `but the selected thumbnail is labeled ${displayedOrdinal}.`,
        );
    }

    if (objectId === null || !/^[1-9]\d*$/.test(objectId)) {
        fail(`Selected scan ${ordinal} does not expose a valid data-plikid.`);
    }
    if (expectedId !== null && objectId !== expectedId) {
        fail(
            `Resolved object mismatch for scan ${ordinal}: catalog resolved ${expectedId}, `
            + `but browser thumbnail exposes ${objectId}.`,
        );
    }

    await thumbnail.scrollIntoViewIfNeeded();
    await thumbnail.click();

    const frame = await waitForPhotoSliderFrame(page, objectId);
    debugEvent('unit:photoslider-opened', {
        scan_number: ordinal,
        object_id: objectId,
        frame_url: frame.url(),
    });

    const publicViewerUrl = await extractPublicViewerUrlFromPhotoSlider(frame);
    debugEvent('unit:public-viewer-resolved', {
        scan_number: ordinal,
        object_id: objectId,
        viewer_url: publicViewerUrl,
    });

    const viewerPage = await context.newPage();
    observeDebugPage(viewerPage);

    return await fetchDirectViewerImage(context, viewerPage, publicViewerUrl);
}

async function choosePageSize200(page) {
    const paginator = page.locator('[data-qa-id="paginator"]').first();
    if (await paginator.count() === 0) {
        fail('Szukaj w Archiwach unit page does not expose the scan paginator.');
    }

    const current = await paginator
        .locator('.pagination-items-per-page .dropdown-toggle')
        .first()
        .textContent()
        .catch(() => null);

    if (current !== null && /^\s*200\b/.test(current)) {
        debugEvent('unit:page-size', { page_size: UNIT_PAGE_SIZE, changed: false });
        return;
    }

    const toggle = paginator.locator('.pagination-items-per-page .dropdown-toggle').first();
    await toggle.click();

    const link200 = paginator
        .locator('.pagination-items-per-page .dropdown-menu a')
        .filter({ hasText: /^\s*200\s*$/ })
        .first();

    if (await link200.count() === 0) {
        fail('Szukaj w Archiwach paginator does not expose the 200-entries page-size option.');
    }

    await clickNavigation(page, link200);
    await settlePage(page);

    const updated = await page
        .locator('[data-qa-id="paginator"] .pagination-items-per-page .dropdown-toggle')
        .first()
        .textContent()
        .catch(() => null);

    if (updated === null || !/^\s*200\b/.test(updated)) {
        fail('Szukaj w Archiwach did not switch the unit gallery to 200 entries per page.');
    }

    debugEvent('unit:page-size', { page_size: UNIT_PAGE_SIZE, changed: true, url: page.url() });
}

async function chooseCatalogPage(page, pageNumber) {
    const paginator = page.locator('[data-qa-id="paginator"]').first();
    const pageLink = paginator
        .locator('ul.pagination a')
        .filter({ hasText: new RegExp(`^\\s*${pageNumber}\\s*$`) })
        .first();

    if (await pageLink.count() === 0) {
        fail(`Szukaj w Archiwach paginator does not expose catalog page ${pageNumber}.`);
    }

    await clickNavigation(page, pageLink);
    await settlePage(page);
    debugEvent('unit:page-selected', { page_number: pageNumber, url: page.url() });
}

async function clickNavigation(page, locator) {
    const before = page.url();

    await Promise.all([
        page.waitForURL((url) => url.toString() !== before, {
            timeout: Math.min(timeoutMs, 15000),
            waitUntil: 'domcontentloaded',
        }).catch(() => null),
        locator.click(),
    ]);
}

async function waitForPhotoSliderFrame(page, objectId) {
    const deadline = Date.now() + Math.min(timeoutMs, 15000);

    while (Date.now() < deadline) {
        for (const frame of page.frames()) {
            const frameUrl = frame.url();
            if (!frameUrl.includes('/plugins/photoslider/dist/index.html')) {
                continue;
            }
            try {
                const parsed = new URL(frameUrl);
                if (parsed.searchParams.get('plikid') === objectId) {
                    return frame;
                }
            } catch {
                // Keep waiting for the expected photoslider frame.
            }
        }
        await page.waitForTimeout(100);
    }

    const frames = page.frames().map((frame) => frame.url());
    debugEvent('unit:photoslider-missing', { object_id: objectId, frames });
    fail(`Clicking scan object ${objectId} did not open the expected Szukaj w Archiwach photoslider iframe.`);
}

async function extractPublicViewerUrlFromPhotoSlider(frame) {
    const triggerSelectors = [
        'a',
        'button',
        '[role="button"]',
    ];
    let trigger = null;

    for (const selector of triggerSelectors) {
        const candidate = frame
            .locator(selector)
            .filter({ hasText: /^\s*Link do skanu\s*$/i })
            .first();
        if (await candidate.count() > 0) {
            trigger = candidate;
            break;
        }
    }

    if (trigger === null) {
        const bodyText = await frame.locator('body').innerText().catch(() => '');
        debugEvent('unit:scan-link-trigger-missing', {
            frame_url: frame.url(),
            body_text_excerpt: bodyText.slice(0, 3000),
        });
        fail('Szukaj w Archiwach photoslider does not expose the "Link do skanu" control.');
    }

    await trigger.click();
    debugEvent('unit:scan-link-trigger-clicked', { frame_url: frame.url() });

    const deadline = Date.now() + Math.min(timeoutMs, 10000);
    while (Date.now() < deadline) {
        const controls = frame.locator('input, textarea');
        const count = await controls.count();

        for (let index = 0; index < count; index += 1) {
            const control = controls.nth(index);
            const value = await control.inputValue().catch(() => '');
            const normalized = normalizePublicViewerUrl(value, frame.url());
            if (normalized !== null) {
                return normalized;
            }
        }

        const bodyText = await frame.locator('body').innerText().catch(() => '');
        const textMatch = bodyText.match(
            /https:\/\/(?:www\.)?szukajwarchiwach\.gov\.pl\/skan\/-\/skan\/[A-Za-z0-9_-]+/i,
        );
        if (textMatch !== null) {
            const normalized = normalizePublicViewerUrl(textMatch[0], frame.url());
            if (normalized !== null) {
                return normalized;
            }
        }

        await frame.page().waitForTimeout(100);
    }

    fail('Szukaj w Archiwach "Link do skanu" panel did not expose a recognizable public scan viewer URL.');
}

function normalizePublicViewerUrl(raw, baseUrl) {
    if (typeof raw !== 'string' || raw.trim() === '') {
        return null;
    }

    let parsed;
    try {
        parsed = new URL(raw.trim(), baseUrl);
    } catch {
        return null;
    }

    if (
        parsed.protocol !== 'https:'
        || !PORTAL_HOSTS.has(parsed.hostname)
        || !/^\/skan\/-\/skan\/[A-Za-z0-9_-]+\/?$/.test(parsed.pathname)
    ) {
        return null;
    }

    parsed.hostname = 'www.szukajwarchiwach.gov.pl';
    parsed.search = '';
    parsed.hash = '';

    return parsed.toString().replace(/\/$/, '');
}

async function fetchDirectViewerImage(context, page, viewerUrl) {
    const candidates = [];
    const pending = [];

    page.on('response', (response) => {
        const promise = capturePhotoCandidate(response, candidates);
        pending.push(promise);
    });

    debugEvent('viewer:navigate:start', { url: viewerUrl });
    const viewerResponse = await page.goto(viewerUrl, {
        waitUntil: 'domcontentloaded',
        timeout: timeoutMs,
    });
    if (viewerResponse === null) {
        fail('Scan viewer navigation did not produce an HTTP response.');
    }

    await settlePage(page);
    await Promise.allSettled(pending);
    await assertNotBlocked(page);

    debugEvent('viewer:navigate:done', {
        status: viewerResponse.status(),
        url: page.url(),
        title: await page.title().catch(() => ''),
        candidates: summarizeCandidates(candidates),
    });

    if (candidates.length === 0) {
        fail(
            `Scan viewer did not load a recognized image from ${PHOTO_HOST}. `
            + `Viewer status=${viewerResponse.status()} url=${page.url()}`,
        );
    }

    candidates.sort(compareImageCandidates);
    const selected = candidates[0];

    if (isKnownPreviewAsset(selected.url)) {
        fail(
            `Public scan viewer ${viewerUrl} exposed only preview-quality image responses; `
            + 'refusing to silently accept _mid/_min/_thumb.',
        );
    }

    debugEvent('viewer:selected-candidate', { selected: summarizeCandidate(selected) });

    return responsePayload(selected);
}

async function capturePhotoCandidate(response, candidates) {
    let parsed;
    try {
        parsed = new URL(response.url());
    } catch {
        return;
    }

    if (parsed.hostname !== PHOTO_HOST || response.status() < 200 || response.status() >= 300) {
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

async function assertNotBlocked(page) {
    const title = await page.title().catch(() => '');
    const body = Buffer.from(await page.content(), 'utf8');
    if (isBlockPage(title, body)) {
        fail(`Browser session received an Imperva/forbidden page for ${page.url()}.`);
    }
}

async function settlePage(page) {
    await page.waitForLoadState('networkidle', {
        timeout: Math.min(timeoutMs, 10000),
    }).catch(() => null);
    await page.waitForTimeout(1000).catch(() => null);
}

function canonicalUnitUrl(unitId) {
    return `https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/${unitId}`;
}

function unitIdFromUrl(url) {
    try {
        const parsed = new URL(url);
        if (parsed.protocol !== 'https:' || !PORTAL_HOSTS.has(parsed.hostname)) {
            return null;
        }
        const match = parsed.pathname.match(/^\/(?:pl\/)?jednostka\/-\/jednostka\/([1-9]\d*)\/?$/);
        return match?.[1] ?? null;
    } catch {
        return null;
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

function responsePayload(candidate) {
    return {
        status: candidate.status,
        url: candidate.url,
        headers: candidate.headers,
        body_base64: candidate.body.toString('base64'),
    };
}

function observeDebugPage(page) {
    if (debugDir === null) {
        return;
    }

    page.on('framenavigated', (frame) => {
        debugEvent('browser:navigation', {
            frame: frame === page.mainFrame() ? 'main' : 'child',
            url: frame.url(),
        });
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
            || hostname === PHOTO_HOST
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
        || (
            body.length >= 8
            && body.subarray(0, 8).equals(
                Buffer.from([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]),
            )
        )
        || (
            body.length >= 12
            && body.subarray(0, 4).toString('ascii') === 'RIFF'
            && body.subarray(8, 12).toString('ascii') === 'WEBP'
        );
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
    debugState.events.push({
        at: new Date().toISOString(),
        type,
        ...details,
    });
}

function validateArguments() {
    if (!['scan-image', 'unit-scan-image'].includes(action)) {
        fail('Expected action scan-image or unit-scan-image.');
    }

    let parsed;
    try {
        parsed = new URL(targetUrl);
    } catch {
        fail('Expected a valid target URL.');
    }

    if (parsed.protocol !== 'https:' || !PORTAL_HOSTS.has(parsed.hostname)) {
        fail('Target URL must use https on szukajwarchiwach.gov.pl.');
    }

    if (!Number.isInteger(timeoutMs) || timeoutMs < 1000) {
        fail('Timeout must be at least 1000 ms.');
    }

    if (action === 'scan-image') {
        if (!/^\/skan\/-\/skan\/[A-Za-z0-9_-]+\/?$/.test(parsed.pathname)) {
            fail('scan-image action requires a public /skan/-/skan/<token> viewer URL.');
        }
        return;
    }

    if (!Number.isInteger(scanNumber) || scanNumber < 1) {
        fail('unit-scan-image action requires --scan-number=<positive integer>.');
    }
    if (expectedObjectId !== null && !/^[1-9]\d*$/.test(expectedObjectId)) {
        fail('--expected-object-id must be a positive numeric Szukaj w Archiwach object id.');
    }
    if (unitIdFromUrl(targetUrl) === null) {
        fail('unit-scan-image action requires a canonical Szukaj w Archiwach unit URL.');
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
