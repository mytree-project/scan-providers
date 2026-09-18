# Architecture

## Scope

`mytree/scan-providers` is the framework-independent boundary between MyTree and third-party services that host genealogical scan assets.

It deliberately does not model MyTree `Source`, `Mention`, `Claim`, `Person` or interpretation semantics.

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

Provider-specific URL, HTML and download behavior must not leak into application or MyTree domain code.

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

When a provider exposes catalog enumeration:

```text
ScanResourceReference
        ↓
ScanCatalogDiscoveryInterface
        ↓
ScanCatalog
        ↓
AvailableScan[]
```

Discovery and resolution are separate on purpose. A provider can expose files that cannot be mapped safely to a requested act. Such files remain visible in the catalog instead of being guessed away.

## Extension contracts

### `ScanProviderInterface`

Every scan provider exposes a stable key, precise resource matching, scan resolution and raw asset download.

The provider key is an open string identifier rather than an enum because additional providers are expected.

A provider may be introduced incrementally. During a milestone step that implements catalog discovery or resolution before download, unsupported operations remain explicit rather than guessing or silently falling back.

### `ScanCatalogDiscoveryInterface`

Optional capability for providers that can enumerate scans inside a remote resource. Providers that cannot meaningfully list a catalog are not forced to implement it.

### `ScanProviderRegistry`

Routing is provider-driven. There is no central switch over known domains. If zero providers match, routing fails explicitly. If more than one provider matches, routing fails as ambiguous instead of silently choosing by registration order.

The standalone CLI uses `DefaultScanProviderRegistryFactory` as a small composition root. The factory registers the completed Genealodzy Skanoteka and Szukaj w Archiwach adapters in the normal registry; it does not duplicate routing rules. Future framework composition roots may register the same provider services independently through dependency injection.

## Genealodzy Skanoteka provider

Routing target:

```text
https://metryki.genealodzy.pl/...
```

Observed Skanoteka collection pages expose scan viewer links through URLs containing the `plik` query parameter. The provider parses these links into `AvailableScan` values and derives only strict filename locators:

```text
017.jpg    -> act 17
12-21.jpg  -> act range 12..21
anything else -> opaque locator
```

`ActNumberMatcher` never performs fuzzy filename matching.

For download, the provider opens the resolved viewer page, resolves the best matching image/download link and validates that the downloaded response is an image before storing it.

## Szukaj w Archiwach provider — completed P2 package capability

Current supported resource family:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>#scan<N>
```

The provider uses public Szukaj w Archiwach HTML/routes as an undocumented web integration. The catalog parser preserves the numeric provider unit ID, unit metadata/raw fields, declared digital scan cardinality, ordered scan ordinals and numeric `data-plikid` object locators. Pagination is followed sequentially and the final enumeration must equal the declared `Skany (N)` / `Scans (N)` value before the result can be cached as successful discovery.

Each discovered entry derives its exact public object viewer from the discovered identifiers:

```text
/jednostka/-/jednostka/<unit-id>/obiekty/<object-id>
```

Ordinal resolution treats `#scan<N>` as a browser-side locator hint rather than an asset URL. The provider parses a positive ordinal from the fragment, or from explicit `ScanLocatorHints::scanNumberRaw` when no fragment is present, and matches it against the catalog entry's explicit `scan_ordinal` metadata. The exact provider object/file locator comes from the discovered `AvailableScan::remoteId`; it is never inferred from the ordinal itself.

Conflicting raw hints, missing/out-of-range ordinals and non-unique catalog matches remain explicit `unresolved` / `ambiguous` / `unsupported` outcomes. The original `ResolveScanRequest`, catalog candidates and catalog provenance remain attached to the result so disagreement between supplied locator data and current discovery is diagnosable.

For download, the provider opens the exact object viewer and extracts one supported public scan link:

```text
/skan/-/skan/<opaque-token>
```

The token is treated as opaque provider data. The adapter deliberately ignores undocumented `/o/pliki-api/...` routes as public contracts. Missing or non-unique public scan links fail explicitly. The selected public scan URL is fetched through the shared HTTP boundary, validated as an image, stored through `ScanAssetStorageInterface`, and returned with the existing `mytree.downloaded-scan.v1` semantics. A small provider-specific retry collaborator supplies the same bounded transport/`429`/`5xx` policy to catalog, viewer and asset retrieval.

The standalone CLI exposes the provider only through the existing `DiscoverScans`, `ResolveScan` and `DownloadScan` application services. No parallel provider-specific orchestration API exists. Legacy `szukajwarchiwach.pl` URLs are not claimed or mechanically rewritten by provider routing.

See [SZUKAJWARCHIWACH.md](SZUKAJWARCHIWACH.md).

## Determinism

Pure parser/resolution components are deterministic for the same input. Network retrieval timestamps and remote content are explicit I/O inputs and are preserved in provenance.

Normal CI uses fixture HTML and fake responses. Live third-party availability is not required.

## Provenance and serialization

A catalog records:

```text
provider key/version
resource URL
retrieval timestamp
SHA-256 of provider response/discovery corpus
discovery strategy
```

Provider-specific catalog provenance may add stable remote identifiers, page hashes, metadata and cardinality as long as external raw values remain preserved and provider semantics stay inside the adapter.

A scan resolution additionally preserves the full request, candidates, strategy/status, selected catalog entry when resolved and catalog provenance. A downloaded scan additionally records:

```text
viewer URL
download URL
resolution strategy
asset MIME type
asset size
asset SHA-256
storage path
retrieval timestamp
catalog provenance
```

Original remote filenames and request hints are preserved when the provider exposes them; normalization does not replace raw values. A provider-generated storage filename does not claim to be the remote filename when the remote service did not publish one.

The completed P2 provider uses the existing versioned shapes without an incompatible change:

```text
mytree.scan-catalog.v1
mytree.scan-resolution.v1
mytree.downloaded-scan.v1
```

Unit/ordinal/object identity and rights/access data remain represented through the existing scan metadata, request/resolution objects, URLs and provider provenance.

## Non-goals

- MyTree source identity reconciliation
- OCR or transcription
- fuzzy act matching
- person identity resolution
- automatic selection of a remote book from only parish/year/type
- mechanical migration of legacy Szukaj w Archiwach URLs
- optimized whole-unit/batch Szukaj w Archiwach download
- Laravel/Eloquent/Filament integration in the core package

Collection/book discovery by parish/year/type may be added later as a separate capability after provider semantics are verified. Laravel/MyTree application registration remains an M7 concern.
