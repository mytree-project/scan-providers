# Szukaj w Archiwach browser diagnostics

Use the browser diagnostic mode when live Szukaj w Archiwach HTML, pagination, viewer behavior or image selection needs inspection.

The standalone CLI already uses Chromium for current Szukaj w Archiwach unit/catalog page retrieval because live validation showed that a successful-looking Native HTTP response can expose only a partial catalog. `--browser-debug-dir` does not switch catalog transport; it writes diagnostics for the browser work that the normal standalone SZA flow already performs.

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=<N> \
  --output=var/scans \
  --browser-debug-dir=var/szukajwarchiwach-browser-debug
```

When `--browser-debug-dir` is present:

- Every active Playwright worker invocation writes a JSON manifest with navigation, response and scan-candidate events.
- Video/WebM recording is intentionally disabled.
- The worker prints the exact manifest path to stderr.
- Debug manifests intentionally do **not** contain cookie values.

The diagnostic directory is normally placed under `var/`, which is ignored by Git.

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
- object-viewer photo DOM mapping and conservative binding strategy,
- selected scan image candidate,
- browser block/error information.

For object-viewer diagnostics the worker records bounded structural data rather than the whole HTML document. This includes photo `src`, rendered/natural dimensions, selected/current state signals, nearby `data-plikid`, closest anchors, relevant ancestor attributes, bounded inline-script snippets and provider-related hidden/form values. The goal is to discover the provider-emitted object-to-photo relationship without guessing from image size or network timing.

## Catalog page-size optimization

For a plain current unit URL the browser page worker prefers the portal's observed catalog form:

```text
_Jednostka_delta=200
_Jednostka_resetCur=false
_Jednostka_cur=1
_Jednostka_id_jednostki=<UNIT_ID>
```

This reduces browser navigations when the portal honors the larger page size. Normal pagination is still followed when additional pages are exposed.

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

A later 2026-09-24 object-viewer run reached the resolved URL `/obiekty/6956791` for scan ordinal 79. The page loaded 20 `_mid` photo responses, including the previously observed `f7509c..._mid` preview, but did not expose `6956791` as a matching DOM `data-plikid`. The browser worker therefore refuses to infer identity from image byte size/order and records richer DOM state for a provider-backed binding.

Do not commit generated manifests. They are runtime diagnostics only.
