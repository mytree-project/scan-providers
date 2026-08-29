# MyTree Scan Providers

Framework-independent PHP package for discovering, resolving and downloading genealogical scan assets from external scan-hosting services.

The package is designed to remain reusable outside Laravel. MyTree/Laravel integration should be implemented through adapters and the Laravel composition root rather than by introducing framework dependencies into this core package.

## Initial provider

The first provider is:

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

## Requirements

- PHP 8.2+
- `allow_url_fopen=1` for the built-in standalone HTTP client
- no Laravel dependency
- no Selenium/browser dependency

Install development dependencies:

```bash
composer install
```

## CLI

List registered providers:

```bash
php bin/mytree-scan providers
```

### Discover available scans

```bash
php bin/mytree-scan discover \
  --url="https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12"
```

JSON output:

```bash
php bin/mytree-scan discover --url="..." --format=json
```

Discovery is intentionally a first-class capability. It exposes every recognized scan in the supplied resource, including scans whose filename cannot be interpreted as an act number.

### Resolve a scan by act number

```bash
php bin/mytree-scan resolve \
  --url="https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12" \
  --record-number=<ACT_NUMBER>
```

Supported deterministic filename forms in v0.1:

```text
17.jpg       -> act 17
12-21.jpg    -> acts 12 through 21
```

Opaque filenames such as `SkU-1.jpg` remain discoverable but are not guessed as act-number mappings.

Resolution statuses are explicit:

```text
resolved
ambiguous
unresolved
unsupported
```

### Download a resolved scan

```bash
php bin/mytree-scan download \
  --url="https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12" \
  --record-number=<ACT_NUMBER> \
  --output=var/scans
```

The result carries the remote resource URL, viewer URL, resolved download URL, retrieval timestamp, SHA-256, file size, provider/version and the resolution strategy.

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

Provider-specific HTTP/HTML/URL rules must stay inside the provider implementation.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and [docs/LARAVEL_INTEGRATION.md](docs/LARAVEL_INTEGRATION.md).

## Provenance and source identity

This package downloads assets; it does not decide historical source identity.

A downloaded scan is not automatically a MyTree `Source`. The Laravel/MyTree integration layer may attach it as a `SourceAsset` after preserving its independent provider URL, retrieval metadata and hash. Multiple assets from different providers can represent the same underlying source and must retain independent provenance.

Serialized results use explicit schema identifiers:

```text
mytree.scan-catalog.v1
mytree.scan-resolution.v1
mytree.downloaded-scan.v1
```

## Tests

```bash
composer test
```

Normal tests use local fixtures and fake HTTP responses. CI does not depend on the availability or current HTML of third-party genealogy portals.

## Current limitations

- v0.1 routes only `metryki.genealodzy.pl` to `genealodzy-skanoteka`.
- Act resolution supports positive numeric act numbers and strict exact/range filename conventions only.
- The package starts from a known scan-resource/catalog URL. Discovering the correct remote collection solely from parish/year/type is intentionally outside the initial API and can be introduced later as a segregated capability.
- Provider HTML changes may require parser updates; provenance hashes and fixture tests make such changes diagnosable.
