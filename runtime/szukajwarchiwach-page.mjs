#!/usr/bin/env node

import process from 'node:process';
import path from 'node:path';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const BOOTSTRAP_URL = 'https://www.szukajwarchiwach.gov.pl/';

const action = process.argv[2] ?? '';
const targetUrl = process.argv[3] ?? '';
const extraArgs = process.argv.slice(4);
const timeoutArg = extraArgs.find((argument) => argument.startsWith('--timeout-ms='));
const debugDirArg = extraArgs.find((argument) => argument.startsWith('--debug-dir='));
const timeoutMs = Number.parseInt(timeoutArg?.slice('--timeout-ms='.length) ?? '60000', 10);
const debugDir = debugDirArg === undefined
    ? null
    : path.resolve(process.cwd(), debugDirArg.slice('--debug-dir='.length));
const runId = `${new Date().toISOString().replace(/[:.]/g, '-')}-${process.pid}-page`;
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
    let failure = null;
    let result = null;

    try {
        browser = await chromium.launch({ headless: true });
        context = await browser.newContext({ viewport: { width: 1280, height: 720 } });
        page = await context.newPage();
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

        const navigationUrl = preferredCatalogUrl(targetUrl);
        debugEvent('page:navigation-url', {
            requested_url: targetUrl,
            navigation_url: navigationUrl,
            catalog_delta_200: navigationUrl !== targetUrl,
        });

        const response = await page.goto(navigationUrl, {
            waitUntil: 'domcontentloaded',
            timeout: timeoutMs,
        });
        if (response === null) {
            fail('Browser navigation did not produce an HTTP response.');
        }

        await page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 10000) }).catch(() => null);
        const body = Buffer.from(await page.content(), 'utf8');
        const title = await page.title().catch(() => '');
        const scanEntryCount = await page.locator('[data-plikid]').count().catch(() => 0);

        debugEvent('page:navigate:done', {
            status: response.status(),
            requested_url: targetUrl,
            url: page.url(),
            title,
            body_bytes: body.length,
            scan_entry_count: scanEntryCount,
        });

        if (isBlockPage(title, body)) {
            fail(`Browser session received an Imperva/forbidden page for ${page.url()}.`);
        }

        result = {
            status: response.status(),
            url: page.url(),
            headers: normalizeHeaders(response.headers()),
            body_base64: body.toString('base64'),
        };
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

function preferredCatalogUrl(url) {
    try {
        const parsed = new URL(url);
        if (
            parsed.protocol !== 'https:'
            || !['www.szukajwarchiwach.gov.pl', 'szukajwarchiwach.gov.pl'].includes(parsed.hostname)
            || parsed.search !== ''
        ) {
            return url;
        }

        const match = parsed.pathname.match(/^\/(?:en\/|de\/)?jednostka\/-\/jednostka\/([1-9]\d*)\/?$/);
        if (match === null) {
            return url;
        }

        parsed.hostname = 'www.szukajwarchiwach.gov.pl';
        parsed.pathname = `/jednostka/-/jednostka/${match[1]}`;
        parsed.searchParams.set('_Jednostka_delta', '200');
        parsed.searchParams.set('_Jednostka_resetCur', 'false');
        parsed.searchParams.set('_Jednostka_cur', '1');
        parsed.searchParams.set('_Jednostka_id_jednostki', match[1]);

        return parsed.toString();
    } catch {
        return url;
    }
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

        if (!hostname.endsWith('szukajwarchiwach.gov.pl')) {
            return;
        }

        debugEvent('browser:response', {
            status: response.status(),
            url: response.url(),
            content_type: response.headers()['content-type'] ?? '',
            resource_type: response.request().resourceType(),
        });
    });
}

function isBlockPage(title, body) {
    const text = `${title}\n${body.toString('utf8', 0, Math.min(body.length, 65536))}`.toLowerCase();
    return text.includes('request unsuccessful')
        || text.includes('incapsula incident id')
        || text.includes('<title>forbidden</title>')
        || text.includes('access denied');
}

function normalizeHeaders(headers) {
    const normalized = {};
    for (const [name, value] of Object.entries(headers)) {
        normalized[name.toLowerCase()] = Array.isArray(value) ? value : [String(value)];
    }
    return normalized;
}

function debugEvent(type, details = {}) {
    if (debugDir === null) {
        return;
    }
    debugState.events.push({ at: new Date().toISOString(), type, ...details });
}

function validateArguments() {
    if (action !== 'page') {
        fail('Expected action page.');
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
