# Szukaj w Archiwach browser diagnostics

Use the browser diagnostic mode when live Szukaj w Archiwach HTML, pagination, viewer behavior or image selection needs inspection.

The standalone CLI already uses Chromium for current Szukaj w Archiwach unit/catalog page retrieval because live validation showed that a successful-looking Native HTTP response can expose only a partial catalog. `--browser-debug-dir` does not switch catalog transport anymore; it records the browser work that the normal standalone SZA flow already performs.

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=<N> \
  --output=var/scans \
  --browser-debug-dir=var/szukajwarchiwach-browser-debug
```

When `--browser-debug-dir` is present:

- Every Playwright worker invocation records a `1280x720` WebM video in the selected directory.
- Every worker invocation writes a JSON manifest with navigation, response and scan-candidate events.
- The worker prints the exact manifest/video paths to stderr.
- Debug manifests intentionally do **not** contain cookie values.

The diagnostic directory is normally placed under `var/`, which is ignored by Git.

A single CLI command may create several recordings because the current runtime deliberately uses a bounded ephemeral browser context per browser operation. For catalog debugging this is useful: individual videos/manifests show exactly what Chromium rendered for each requested catalog page.

The JSON manifest uses schema:

```text
mytree.szukajwarchiwach-browser-debug.v1
```

Useful event fields include:

- requested and final navigation URLs,
- HTTP status and page title,
- `scan_entry_count` visible in the rendered DOM,
- interesting responses from `*.szukajwarchiwach.gov.pl`,
- image candidate URLs, MIME types and body sizes,
- selected scan image candidate,
- browser block/error information.

## Live pagination finding from unit 11959850

The 2026-09-23 diagnostic run showed that Chromium rendered the complete catalog as:

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

This confirmed that the pagination algorithm can enumerate the complete catalog from browser-rendered HTML and that earlier live results of 60/40 entries came from trusting incomplete successful-looking Native HTTP page content, not from an expected cardinality hard-coded into the provider.

Do not commit generated recordings/manifests. They are runtime diagnostics only.
