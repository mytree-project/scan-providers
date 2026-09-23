# Szukaj w Archiwach browser diagnostics

Use the browser diagnostic mode when live Szukaj w Archiwach HTML, pagination, viewer behavior or image selection needs inspection.

The standalone CLI already uses Chromium for current Szukaj w Archiwach unit/catalog page retrieval because live validation showed that a successful-looking Native HTTP response can expose only a partial catalog. `--browser-debug-dir` does not switch catalog transport; it records JSON diagnostics for the browser work that the normal standalone SZA flow already performs.

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=<N> \
  --output=var/scans \
  --browser-debug-dir=var/szukajwarchiwach-browser-debug
```

When `--browser-debug-dir` is present:

- Every Playwright worker invocation writes a JSON manifest with navigation, response and scan-candidate events.
- No WebM/video recording is created.
- The worker prints the exact manifest path to stderr.
- Debug manifests intentionally do **not** contain cookie values.

The diagnostic directory is normally placed under `var/`, which is ignored by Git.

A single CLI command may still create several manifests because the runtime uses a bounded ephemeral browser context per browser operation. Current catalog retrieval tries the portal's `delta=200` catalog view first, so units with at most 200 scans can normally be enumerated in one catalog browser operation when the portal honors that setting. If the portal still exposes pagination, the provider continues through the returned pagination links/guards.

The JSON manifest uses schema:

```text
mytree.szukajwarchiwach-browser-debug.v1
```

Useful event fields include:

- requested and final navigation URLs,
- the actual catalog navigation URL and whether `delta=200` was applied,
- HTTP status and page title,
- `scan_entry_count` visible in the rendered DOM,
- interesting responses from `*.szukajwarchiwach.gov.pl`,
- image candidate URLs, MIME types and body sizes,
- resolved object-binding diagnostics,
- selected scan image candidate,
- browser block/error information.

## Live pagination finding from unit 11959850

The 2026-09-23 diagnostic run initially showed that the default browser-visible pagination rendered the complete catalog as:

```text
page 1: 20 entries
page 2: 20 entries
page 3: 20 entries
page 4: 20 entries
page 5: 20 entries
page 6: 20 entries
page 7: 20 entries
page 8: 14 entries
-----------------
total: 154 entries
```

This confirmed that the complete browser-rendered catalog contains 154 entries and that earlier live results of 60/40 entries came from trusting incomplete successful-looking Native HTTP page content. The runtime now prefers the portal's `delta=200` catalog view to reduce repeated browser operations; the 154-entry unit is the live validation case for that optimization.

Do not commit generated manifests. They are runtime diagnostics only.
