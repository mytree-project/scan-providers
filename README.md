# MyTree Scan Providers

Framework-independent PHP package for discovering, resolving and downloading genealogical scan assets from external scan-hosting services.

The package is reusable outside Laravel. MyTree/Laravel integration belongs in adapters and the application composition root; provider-specific HTML, URL and browser behavior stays inside this package.

## Providers

### Genealodzy Skanoteka

Provider key:

```text
genealodzy-skanoteka
host: metryki.genealodzy.pl
```

It supports the deterministic catalog → act locator → viewer → image flow used by Geneteka/Skanoteka links. Exact filenames such as `17.jpg` and strict ranges such as `12-21.jpg` can resolve act numbers; opaque filenames remain discoverable without guessed act semantics.

### Szukaj w Archiwach

Provider key:

```text
szukajwarchiwach
host: www.szukajwarchiwach.gov.pl
```

P2 is implemented for known current unit URLs and official public scan-viewer URLs. Supported public locator families include:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>#scan<N>
https://www.szukajwarchiwach.gov.pl/skan/-/skan/<OPAQUE_TOKEN>
```

The implementation provides:

- browser-backed catalog discovery for known current units,
- deterministic one-based ordinal resolution from `#scan<N>` or `--scan-number=<N>`,
- preservation of provider unit/object identity and discovery provenance,
- direct support for official `/skan/-/skan/<token>` HTML viewer locators,
- browser UI acquisition for known unit + ordinal,
- raw/full-resolution image download through the existing `DownloadScan` boundary,
- registry/CLI exposure and the existing v1 serialized contracts.

### Why Szukaj w Archiwach uses Chromium

Live validation established that Native HTTP is not sufficient as the normal correctness boundary for current unit catalogs: requests may be soft-blocked, and even successful-looking HTTP responses may expose only a partial scan list.

The standalone CLI therefore uses the bundled Playwright/Chromium page capability for current Szukaj w Archiwach unit/catalog discovery. The browser worker prefers the portal's 200-entry catalog view and follows pagination when required.

For known unit + ordinal, the normal live browser flow follows the portal UI rather than deriving undocumented photo URLs:

```text
unit page
  → request/show 200 entries
  → select the exact ordinal thumbnail
  → verify data-plikid against catalog resolution
  → open photoslider iframe
  → use the portal's "Link do scanu" / "Link do skanu" control
  → read the provider-emitted /skan/-/skan/<token> viewer locator
  → open that viewer
  → capture the actual full-resolution image response from photos.szukajwarchiwach.gov.pl
```

The implementation never manufactures a `_max` URL from an observed token. The observed `photos.szukajwarchiwach.gov.pl/<token>_max` shape is compatibility evidence, not a public API contract.

The official `/skan/-/skan/<token>` locator is an HTML viewer, not the raw image URL.

See [docs/SZUKAJWARCHIWACH.md](docs/SZUKAJWARCHIWACH.md).

## Requirements

- PHP 8.2+
- `allow_url_fopen=1` for the built-in standalone HTTP client
- Node.js 20+ for the current bundled browser runtime
- no Laravel dependency

Install dependencies and Chromium:

```bash
composer install
npm ci
npx playwright install chromium
```

Browser-specific Playwright objects do not enter `ScanProviderInterface`, `ScanCatalogDiscoveryInterface`, application services or serialized domain results.

## CLI

List providers:

```bash
php bin/mytree-scan providers
```

Expected keys include:

```text
genealodzy-skanoteka
szukajwarchiwach
```

### Discover

```bash
php bin/mytree-scan discover \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>"
```

JSON output:

```bash
php bin/mytree-scan discover --url="..." --format=json
```

### Resolve an ordinal

From a deep link:

```bash
php bin/mytree-scan resolve \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>#scan42"
```

Or from an explicit scan-number hint:

```bash
php bin/mytree-scan resolve \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=42
```

An official public viewer locator is also directly resolvable:

```bash
php bin/mytree-scan resolve \
  --url="https://www.szukajwarchiwach.gov.pl/skan/-/skan/<OPAQUE_TOKEN>"
```

Resolution statuses remain explicit:

```text
resolved
ambiguous
unresolved
unsupported
```

### Download

Known unit + ordinal:

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=42 \
  --output=var/scans
```

Direct public viewer:

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/skan/-/skan/<OPAQUE_TOKEN>" \
  --output=var/scans
```

Optional browser diagnostics:

```bash
--browser-debug-dir=var/szukajwarchiwach-browser-debug
```

Diagnostics write JSON manifests only and do not change the normal SZA transport path. Active workers do not record video/WebM. See [docs/SZUKAJWARCHIWACH_BROWSER_DEBUG.md](docs/SZUKAJWARCHIWACH_BROWSER_DEBUG.md).

## Public architecture

The extension API remains deliberately small:

```text
ScanProviderInterface
ScanCatalogDiscoveryInterface   # optional capability
ScanProviderRegistry

DiscoverScans
ResolveScan
DownloadScan
```

`DefaultScanProviderRegistryFactory` is the standalone composition root used by the CLI. Provider routing stays inside `ScanProviderRegistry` and provider `supports()` implementations rather than caller-side domain switches.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and [docs/LARAVEL_INTEGRATION.md](docs/LARAVEL_INTEGRATION.md).

## Provenance and source identity

This package retrieves digital assets; it does not establish MyTree historical `Source` identity.

Serialized contracts remain:

```text
mytree.scan-catalog.v1
mytree.scan-resolution.v1
mytree.downloaded-scan.v1
```

For known-unit Szukaj w Archiwach resolution, catalog/resolution provenance retains unit ID, one-based ordinal, provider object/file locator, input locator and catalog metadata/hashes. `DownloadedScan.viewer_url` remains the deterministic per-object viewer locator selected by catalog resolution, while `download_url` is the effective raw image response URL.

The public `/skan/-/skan/<token>` obtained inside the unit browser flow is an intermediate provider transport locator. It is not separately serialized by `mytree.downloaded-scan.v1`. Direct `/skan/...` requests naturally use that public viewer as `viewer_url` because it is the input/resolved viewer itself.

## Tests and quality gates

```bash
composer test
```

Normal tests use local fixtures and fake HTTP/browser responses. GitHub Actions also validates `composer.json`, Node worker syntax/imports and the full PHPUnit suite. Live Szukaj w Archiwach availability is not a normal CI dependency.

Coverage includes multi-scan, zero-scan and paginated catalogs; exact/out-of-range/malformed ordinal behavior; browser page selection; viewer/download failure behavior; registry/CLI/serialization; and Genealodzy Skanoteka non-regression.

## Current limitations / deferred capabilities

- Szukaj w Archiwach starts from a known current numeric unit URL or an official public scan-viewer URL.
- Legacy `szukajwarchiwach.pl` URLs are preserved as locator/provenance values and are not mechanically rewritten.
- Arbitrary archival-signature → current-unit discovery is deferred (#161).
- Optimized whole-unit/batch acquisition is deferred (#162); P2 downloads individual resolved scans.
- Migration to a future supported official API is deferred until such a contract exists (#163).
- Provider HTML/UI changes may require adapter updates; fixture tests and optional browser JSON diagnostics are the compatibility boundary.
- Laravel/MyTree application registration and Source Acquisition handoff belong to M7; MyTree must reuse this package's browser-capable provider rather than reimplement provider-specific Playwright logic.
