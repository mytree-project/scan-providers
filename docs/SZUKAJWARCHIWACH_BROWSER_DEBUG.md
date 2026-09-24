# Szukaj w Archiwach browser diagnostics

Use browser diagnostics when live Szukaj w Archiwach HTML, pagination, photoslider behavior or final image selection needs inspection.

The standalone CLI already uses Chromium for current Szukaj w Archiwach unit/catalog retrieval and for the supported unit+ordinal image-acquisition flow. `--browser-debug-dir` does **not** switch transport modes; it only records JSON diagnostics for browser work the normal runtime already performs.

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=<N> \
  --output=var/scans \
  --browser-debug-dir=var/szukajwarchiwach-browser-debug
```

When enabled:

- every active Playwright worker invocation writes a JSON manifest,
- active workers do not record video/WebM,
- each manifest path is printed to stderr,
- cookie values are never written to diagnostics.

The manifest schema is:

```text
mytree.szukajwarchiwach-browser-debug.v1
```

Useful events include:

- requested/final navigation URLs,
- HTTP status and page title,
- browser-visible scan-thumbnail count,
- selected one-based scan ordinal, page and offset,
- selected `data-plikid` and preview URL,
- photoslider iframe URL,
- photoslider-rendered readiness,
- click of the portal's `Link do scanu` control (`Link do skanu` is tolerated as a wording variant),
- provider-emitted `/skan/-/skan/<token>` viewer URL,
- recognized image candidates from `photos.szukajwarchiwach.gov.pl`,
- selected final original/full-resolution image,
- browser block/error details.

## Unit + ordinal browser flow

For requested ordinal `N` the worker:

1. opens the canonical unit page in Chromium,
2. uses the portal's **200 Wpisy** option when available,
3. navigates to the gallery page containing `N` when more than 200 scans exist,
4. selects `((N - 1) mod 200) + 1`,
5. verifies the visible ordinal and `data-plikid` against catalog resolution,
6. clicks the portal's `load-photo-slider` control,
7. waits for the matching photoslider iframe,
8. waits until the photoslider application has actually rendered,
9. clicks **Link do scanu** / tolerated **Link do skanu**,
10. reads the provider-emitted public `/skan/-/skan/<opaque-token>` locator,
11. opens that viewer and captures the actual image response.

No photo URL or `_max` suffix is synthesized.

## Confirmed live mapping used during P2

The 2026-09-24 live validation used unit `11959850`. In the portal's 200-entry gallery, scan 79 was exposed as:

```text
scan ordinal: 79
data-plikid: 6956791
preview token: 3628fd030c0f3c8b8e785a3724235e654775f21fd483fcc668e0af77a69359a7_mid
```

Clicking that thumbnail created a photoslider iframe containing:

```text
plikid=6956791
jednostkaid=11959850
liczbawszystkichskanow=154
```

The final `Link do scanu` flow then downloaded the correct full-resolution scan 79.

An earlier object-viewer experiment observed `f7509c..._mid` among multiple previews. That response was unrelated to the selected scan and is retained only as diagnostic history, not as identity evidence.

## Catalog optimization

The catalog page worker prefers the observed 200-entry representation so a unit with at most 200 scans can normally be enumerated in one browser render. Pagination remains bounded and available when required.

Do not commit generated manifests. They are runtime diagnostics only.
