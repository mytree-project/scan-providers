# Szukaj w Archiwach browser diagnostics

Use the browser diagnostic mode when live Szukaj w Archiwach HTML, pagination, photoslider behavior or image selection needs inspection.

The standalone CLI already uses Chromium for current Szukaj w Archiwach unit/catalog page retrieval because live validation showed that a successful-looking Native HTTP response can expose only a partial catalog. `--browser-debug-dir` does not switch transport modes; it writes diagnostics for the browser work that the normal standalone SZA flow already performs.

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=<N> \
  --output=var/scans \
  --browser-debug-dir=var/szukajwarchiwach-browser-debug
```

When `--browser-debug-dir` is present:

- every active Playwright worker invocation writes a JSON manifest with navigation, response and selection events,
- Video/WebM recording is intentionally disabled,
- the worker prints the exact manifest path to stderr,
- debug manifests intentionally do **not** contain cookie values.

The diagnostic directory is normally placed under `var/`, which is ignored by Git.

The JSON manifest uses schema:

```text
mytree.szukajwarchiwach-browser-debug.v1
```

Useful event fields include:

- requested and final navigation URLs,
- HTTP status and page title,
- browser-visible scan-thumbnail count,
- selected one-based scan ordinal, page number and position on the page,
- selected `data-plikid` and preview URL,
- photoslider iframe URL,
- official `Link do skanu` viewer URL extracted from the photoslider panel,
- recognized final image candidates from `photos.szukajwarchiwach.gov.pl`,
- selected original/full-resolution candidate,
- browser block/error information.

## Unit + ordinal browser flow

The current implementation deliberately follows the portal's normal browser UI instead of trying to infer the target image from an `/obiekty/<id>` page.

For a requested scan ordinal `N` the worker:

1. opens the canonical unit page in a normal Chromium session,
2. uses the portal's **200 Wpisy** paginator option,
3. if more than 200 scans exist, opens the paginator page containing `N`,
4. selects the thumbnail at `((N - 1) mod 200) + 1`,
5. verifies the thumbnail's visible scan number and `data-plikid` against the resolved catalog object,
6. clicks the portal's `load-photo-slider` control,
7. waits for the provider-created photoslider iframe,
8. clicks **Link do skanu** inside that iframe,
9. reads the official `/skan/-/skan/<opaque-token>` URL exposed by the side panel,
10. opens that official viewer in the same browser context and captures the actual image response from `photos.szukajwarchiwach.gov.pl`.

No photo token or `_max` URL is synthesized.

## Confirmed unit 11959850 mapping

A 2026-09-24 captured unit page rendered with `200 Wpisy` exposes all 154 thumbnails at once. The HTML itself identifies scan 79 as:

```text
scan ordinal: 79
data-plikid: 6956791
preview token: 3628fd030c0f3c8b8e785a3724235e654775f21fd483fcc668e0af77a69359a7_mid
```

Clicking that thumbnail creates a photoslider iframe whose query includes:

```text
plikid=6956791
jednostkaid=11959850
liczbawszystkichskanow=154
```

This supersedes the earlier object-viewer diagnostic that observed an unrelated `f7509c..._mid` image among multiple previews. That response was not evidence for the identity of scan 79.

## Catalog page-size optimization

The catalog page worker used for discovery still prefers the portal's observed 200-entry catalog form so catalog resolution can normally complete in one browser render for units with up to 200 scans. The download worker independently follows the visible paginator UI because its purpose is to reproduce the user-facing scan-selection flow.

Do not commit generated manifests. They are runtime diagnostics only.
