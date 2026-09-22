# MyTree Scan Providers

Framework-independent PHP package for discovering, resolving and downloading genealogical scan assets from external scan-hosting services.

The package is designed to remain reusable outside Laravel. MyTree/Laravel integration should be implemented through adapters and the Laravel composition root rather than by introducing framework dependencies into this core package.

## Providers

### Genealodzy Skanoteka

Provider key:

```text
genealodzy-skanoteka
host: metryki.genealodzy.pl
```

It supports the workflow used by Geneteka links where an index record points to a Skanoteka catalog rather than directly to one image:

```text
catalog/resource URL
+ record/act number
        ↓
discover available scan links
        ↓
parse exact/range act locators from scan filenames
        ↓
resolve exactly one / ambiguous / unresolved
        ↓
open the scan viewer
        ↓
resolve the image/download URL
        ↓
download and store the raw image
```

The provider does **not** use OCR or fuzzy identity matching. It does not claim that a scan belongs to an act when the provider's catalog does not expose a supported deterministic locator.

### Szukaj w Archiwach

Provider key:

```text
szukajwarchiwach
host: www.szukajwarchiwach.gov.pl
```

The P2 package implementation contains fixture-backed catalog discovery for known current unit URLs, deterministic ordinal resolution from `#scan<N>` deep links or explicit positive `scanNumberRaw` hints, per-object viewer resolution, default registry/CLI exposure, and support for official `/skan/-/skan/<opaque-token>` **viewer locators**.

Live compatibility testing established two distinct transport facts:

- current unit/catalog requests from a simple non-browser HTTP client can be intercepted by the portal's Imperva/Incapsula layer,
- a 2026-09-22 Playwright/Chromium PoC successfully loaded a public `/skan/-/skan/<token>` viewer and observed the real JPEG as a separate subresource from `photos.szukajwarchiwach.gov.pl`.

The public `/skan/-/skan/<token>` URL is therefore modeled as an HTML viewer locator, not as the raw image asset. `resolve` can identify that viewer deterministically without catalog discovery. The current `NativeHttpClient` step fails explicitly with `ScanCapabilityUnavailableException` when it receives viewer HTML at the image-acquisition step. P2 is not complete at that point: the standalone `scan-providers` tool must add a browser-aware infrastructure transport (Playwright/Chromium or equivalent) so the CLI can complete the supported live download flow without depending on MyTree.

The adapter does not use undocumented `/o/pliki-api/...` endpoints as its public contract. For unit discovery it preserves the numeric unit ID, ordered scan ordinals, provider object/file locators and unit metadata/provenance. When `Skany (N)` / `Scans (N)` is present it is verified against complete enumeration; when current HTML omits that label, the adapter follows the official `_Jednostka_cur` pagination and records the enumerated cardinality explicitly. Zero-scan and paginated units are supported without silently treating unrecognized markup as an empty catalog. Legacy `szukajwarchiwach.pl` URLs are retained as external provenance/locator values and are not mechanically rewritten into current service URLs.

See [docs/SZUKAJWARCHIWACH.md](docs/SZUKAJWARCHIWACH.md).

## Requirements

- PHP 8.2+
- `allow_url_fopen=1` for the built-in standalone HTTP client
- no Laravel dependency
- browser-agnostic PHP/domain contracts; provider runtime may include Playwright/Chromium infrastructure when required by a live service

Install PHP dependencies:

```bash
composer install
```

Install the standalone browser runtime used by current live Szukaj w Archiwach flows:

```bash
npm ci
npx playwright install chromium
```

The Node dependency is pinned in `package-lock.json`. Chromium is launched only when the selected Szukaj w Archiwach operation requires a browser-established session; normal HTTP-only provider operations continue to use `NativeHttpClient`.

## CLI

The standalone CLI registers both completed provider adapters through the same `ScanProviderRegistry` extension boundary used by the application services.

List registered providers:

```bash
php bin/mytree-scan providers
```

Expected provider keys include:

```text
genealodzy-skanoteka
szukajwarchiwach
```

### Discover available scans

Genealodzy Skanoteka example:

```bash
php bin/mytree-scan discover \
  --url="https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12"
```

Szukaj w Archiwach example:

```bash
php bin/mytree-scan discover \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>"
```

JSON output:

```bash
php bin/mytree-scan discover --url="..." --format=json
```

The table format is provider-neutral and shows the provider remote ID, label, optional remote filename, primary locator and viewer URL. Discovery remains a first-class capability and does not guess historical source identity.

### Resolve a scan

Genealodzy Skanoteka resolves a strict act-number locator:

```bash
php bin/mytree-scan resolve \
  --url="https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12" \
  --record-number=<ACT_NUMBER>
```

Supported deterministic filename forms in v0.1 are:

```text
17.jpg       -> act 17
12-21.jpg    -> acts 12 through 21
```

Opaque filenames such as `SkU-1.jpg` remain discoverable but are not guessed as act-number mappings.

Szukaj w Archiwach resolves an ordinal either from the current deep link itself:

```bash
php bin/mytree-scan resolve \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>#scan42"
```

or from an explicit provider-neutral scan-number hint:

```bash
php bin/mytree-scan resolve \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=42
```

An official public **Link do skanu** viewer can also be resolved directly:

```bash
php bin/mytree-scan resolve \
  --url="https://www.szukajwarchiwach.gov.pl/skan/-/skan/<OPAQUE_TOKEN>"
```

Resolution statuses are explicit:

```text
resolved
ambiguous
unresolved
unsupported
```

### Download a resolved scan

Genealodzy Skanoteka example:

```bash
php bin/mytree-scan download \
  --url="https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12" \
  --record-number=<ACT_NUMBER> \
  --output=var/scans
```

The Szukaj w Archiwach command surface remains the same:

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>#scan42" \
  --output=var/scans
```

and accepts an official viewer locator:

```bash
php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/skan/-/skan/<OPAQUE_TOKEN>" \
  --output=var/scans
```

For the current live service, the standalone `NativeHttpClient` may fail at unit/catalog access or return HTML at the public scan viewer step. The standalone composition now includes a Playwright/Chromium browser-session fallback for Szukaj w Archiwach: protected catalog pages can be fetched through a real browser context, and HTML scan viewers can resolve the actual JPEG response loaded from `photos.szukajwarchiwach.gov.pl`. MyTree/M7 will integrate this package capability rather than implement it separately.

## Public architecture

The intentionally small extension API is built around:

```text
ScanProviderInterface
ScanCatalogDiscoveryInterface   # optional capability
ScanProviderRegistry

DiscoverScans
ResolveScan
DownloadScan
```

A new scan service should normally be added by implementing `ScanProviderInterface` and registering the provider. If the service can enumerate scans for a remote resource, it can additionally implement `ScanCatalogDiscoveryInterface`.

`DefaultScanProviderRegistryFactory` is the standalone composition root used by the CLI. It only assembles completed provider implementations; routing decisions remain inside `ScanProviderRegistry` and each provider's `supports()` method rather than caller-side switches.

Provider-specific HTTP/HTML/URL rules must stay inside the provider implementation.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and [docs/LARAVEL_INTEGRATION.md](docs/LARAVEL_INTEGRATION.md).

## Provenance and source identity

This package downloads assets; it does not decide historical source identity.

A downloaded scan is not automatically a MyTree `Source`. The Laravel/MyTree integration layer may attach it as a `SourceAsset` after preserving its independent provider URL, retrieval metadata and hash. Multiple assets from different providers can represent the same underlying source and must retain independent provenance.

Serialized results use the existing explicit schema identifiers:

```text
mytree.scan-catalog.v1
mytree.scan-resolution.v1
mytree.downloaded-scan.v1
```

The Szukaj w Archiwach P2 integration does not require a schema-version change. Provider-specific unit/object/ordinal metadata remains in the existing scan/provenance structures, while the resolution request preserves the original locator input.

## Tests

```bash
composer test
```

Normal tests use local fixtures and fake HTTP responses. CI does not depend on the availability or current HTML of third-party genealogy portals. Package-level integration coverage includes registry routing and the complete Szukaj w Archiwach discover → resolve → download → serialization flow.

## Current limitations

- Live Szukaj w Archiwach unit/catalog requests may be soft-blocked by the service's current Imperva/Incapsula layer. The standalone HTTP client detects explicit challenge signatures and does not attempt to bypass them; `x-iinfo` alone is not considered proof of a block because successful viewer/image responses may also contain it.
- Szukaj w Archiwach `/skan/-/skan/<token>` is an HTML viewer locator in the observed live flow, not a raw JPEG URL. The standalone runtime uses Playwright/Chromium when browser-session acquisition is required; live compatibility still needs to be validated for both direct-viewer and known-unit + ordinal CLI flows before P2 closes.
- Szukaj w Archiwach starts from a known current numeric-unit URL; arbitrary archival-signature-to-unit search is not implemented.
- Legacy `szukajwarchiwach.pl` URLs are not mechanically migrated by the scan provider.
- Szukaj w Archiwach uses per-object acquisition; optimized whole-unit/batch download is intentionally deferred.
- Genealodzy Skanoteka act resolution supports positive numeric act numbers and strict exact/range filename conventions only.
- The package starts from a known scan-resource/catalog URL. Discovering the correct remote collection solely from parish/year/type is intentionally outside the initial API and can be introduced later as a segregated capability.
- Provider HTML changes may require parser updates; provenance hashes and fixture tests make such changes diagnosable.
- Laravel/MyTree application registration is intentionally not part of P2; that integration belongs to M7. Browser transport required by a provider is still part of standalone provider/tool operation and is not deferred to Laravel.
