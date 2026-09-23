# Szukaj w Archiwach browser diagnostics

Use the browser diagnostic mode when live Szukaj w Archiwach HTML or pagination differs from the standalone HTTP view.

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=<N> \
  --output=var/scans \
  --browser-debug-dir=var/szukajwarchiwach-browser-debug
```

When `--browser-debug-dir` is present:

- Szukaj w Archiwach catalog/page retrieval is forced through Chromium for that command instead of accepting a successful-looking Native HTTP page.
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

Do not commit generated recordings/manifests. They are runtime diagnostics only.
