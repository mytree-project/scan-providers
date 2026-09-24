# Architecture

## Scope

`mytree/scan-providers` is the framework-independent boundary between MyTree and third-party services that host genealogical scan assets.

It deliberately does not model MyTree `Source`, `Mention`, `Claim`, `Person` or interpretation semantics.

Provider-specific URL, HTML, browser and download behavior must not leak into application or MyTree domain code.

## Dependency direction

```text
Domain
  ↑
Contracts / Application
  ↑
Provider / Infrastructure
  ↑
CLI or Laravel composition root
```

A runtime may use provider-specific infrastructure such as Playwright/Chromium without putting browser types into domain/public contracts.

## Main flow

```text
ScanResourceReference
        ↓
ScanProviderRegistry
        ↓
matching ScanProviderInterface
        ↓
ResolveScanRequest
        ↓
ScanResolution
        ↓
ResolvedScan
        ↓
DownloadScan
        ↓
DownloadedScan
```

Optional catalog discovery is separate:

```text
ScanResourceReference
        ↓
ScanCatalogDiscoveryInterface
        ↓
ScanCatalog
        ↓
AvailableScan[]
```

Discovery and resolution are separate so providers can expose opaque scans without guessing semantic locators.

## Extension contracts

### `ScanProviderInterface`

Every provider exposes a stable provider key, precise resource matching, resolution and download behavior.

### `ScanCatalogDiscoveryInterface`

Optional capability for providers that can enumerate scans inside a remote resource.

### `ScanProviderRegistry`

Routing is provider-driven. Zero matches fail explicitly. Multiple matches are ambiguous rather than resolved by registration order.

`DefaultScanProviderRegistryFactory` is the standalone composition root used by the CLI. It registers the completed Genealodzy Skanoteka and Szukaj w Archiwach providers without introducing caller-side provider switches.

## Genealodzy Skanoteka

Routing target:

```text
https://metryki.genealodzy.pl/...
```

Observed collection pages expose viewer links with the `plik` query parameter. The provider derives only strict locators:

```text
017.jpg    → act 17
12-21.jpg  → act range 12..21
anything else → opaque locator
```

Download opens the resolved viewer, resolves a supported image/download URL and validates the final response as an image.

## Szukaj w Archiwach — completed P2 capability

Supported resource families:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>#scan<N>
https://www.szukajwarchiwach.gov.pl/skan/-/skan/<opaque-token>
```

### Catalog and ordinal identity

Catalog discovery preserves:

- numeric provider unit ID,
- provider-published unit metadata/raw fields,
- complete ordered scan enumeration,
- one-based `scan_ordinal`,
- numeric provider object/file locator from `data-plikid`,
- per-page/discovery hashes and scan-count provenance.

`#scan<N>` and `ScanLocatorHints::scanNumberRaw` are ordinal hints, not asset URLs. Resolution verifies the ordinal against discovery and preserves the exact object/file locator. Missing, malformed, conflicting or out-of-range hints remain explicit safe outcomes.

A discovered scan carries a deterministic public object-viewer locator:

```text
/jednostka/-/jednostka/<unit-id>/obiekty/<object-id>
```

That locator remains useful provenance even though the normal browser download path does not need to navigate through it.

### Browser transport ownership

Current live Szukaj w Archiwach unit/catalog HTML cannot be treated as complete merely because Native HTTP returns `200`. Live validation observed successful-looking partial catalogs.

Therefore the standalone CLI composes `BrowserSessionClientInterface` using `PlaywrightBrowserSessionClient` and sets browser-page preference for current SZA unit/catalog discovery.

Native HTTP remains available for providers and operations where it is sufficient. Browser-specific objects stay behind the provider/infrastructure boundary.

### Known unit + ordinal download

The production standalone flow follows the provider's visible UI:

```text
ResolvedScan(unit id + ordinal + object id)
        ↓
open canonical unit page in Chromium
        ↓
select/show 200 entries when available
        ↓
select exact ordinal thumbnail
        ↓
verify visible ordinal + data-plikid == resolved object id
        ↓
open provider photoslider iframe
        ↓
wait for photoslider application to render
        ↓
activate "Link do scanu" / tolerated "Link do skanu"
        ↓
read provider-emitted /skan/-/skan/<opaque-token>
        ↓
open that official public viewer
        ↓
capture recognized full-resolution image response from photos.szukajwarchiwach.gov.pl
        ↓
ScanAssetStorageInterface
```

The worker never derives a photo URL by replacing a suffix or manufacturing `<token>_max`. Known preview variants such as `_mid` are not accepted as the final original/full-resolution result.

The browser flow verifies object identity before download, so ordinal selection cannot silently drift to another scan.

### Direct public viewer download

An official public locator:

```text
/skan/-/skan/<opaque-token>
```

is modeled as an HTML viewer locator, not a raw image URL. `ResolveScan` can resolve it directly with strategy `public_scan_viewer_url`. `fetchScanImage()` opens the viewer and captures the actual image subresource.

### Optional non-browser fallback

Provider code retains a fixture-backed object-viewer parser for environments that instantiate the provider without browser transport. It may use a public `/skan/-/skan/<token>` link explicitly exposed by static object-viewer HTML. This is a bounded compatibility path, not the normal current standalone CLI path and not an undocumented internal API contract.

### Provenance

For known-unit resolution:

- `resource_url` preserves the original input locator,
- catalog/resolution provenance retains unit ID, ordinal, object ID, metadata and discovery hashes,
- `viewer_url` remains the deterministic per-object `/obiekty/<object-id>` locator selected by resolution,
- `download_url` is the effective raw image URL returned by the browser flow.

The intermediate `/skan/-/skan/<token>` obtained inside the unit browser session is a transport locator and is not separately serialized by `mytree.downloaded-scan.v1`. Direct public-viewer requests naturally use `/skan/...` as `viewer_url` because it is their resolved viewer locator.

The existing public schemas remain unchanged:

```text
mytree.scan-catalog.v1
mytree.scan-resolution.v1
mytree.downloaded-scan.v1
```

## Determinism and tests

Parser/resolution components are deterministic for the same input. Network timestamps and remote content are explicit I/O inputs preserved in provenance.

Normal CI is offline. It uses fixture HTML and fake HTTP/browser clients; live third-party availability is never required.

GitHub Actions additionally validates Composer metadata, Node worker syntax/imports and the PHPUnit suite.

## Source identity boundary

`DownloadedScan` is a retrieved digital asset result. It does not establish historical source identity. Multiple provider assets may later be attached to one MyTree `Source` only through the normal application/source-identity boundary.

## Deferred / non-goals

- MyTree source identity reconciliation
- OCR or transcription
- fuzzy act matching
- person identity resolution
- arbitrary archival signature → current SZA unit discovery (#161)
- optimized whole-unit/batch SZA acquisition (#162)
- migration to a future supported official API before such a contract exists (#163)
- mechanical migration of legacy `szukajwarchiwach.pl` locators
- Laravel/Eloquent/Filament integration in this core package

M7 integrates/configures this already browser-capable package. It must not reimplement Szukaj w Archiwach HTML/photoslider/Playwright behavior in Laravel.
