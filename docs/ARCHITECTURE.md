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

### `ScanCatalogDiscoveryInterface`

Optional capability for providers that can enumerate scans inside a remote resource. Providers that cannot meaningfully list a catalog are not forced to implement it.

### `ScanProviderRegistry`

Routing is provider-driven. There is no central switch over known domains. If zero providers match, routing fails explicitly. If more than one provider matches, routing fails as ambiguous instead of silently choosing by registration order.

## First provider: Genealodzy Skanoteka

Initial routing target:

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

## Determinism

Pure parser/resolution components are deterministic for the same input. Network retrieval timestamps and remote content are explicit I/O inputs and are preserved in provenance.

Normal CI uses fixture HTML and fake responses. Live third-party availability is not required.

## Provenance

A catalog records:

```text
provider key/version
resource URL
retrieval timestamp
SHA-256 of provider response
discovery strategy
```

A downloaded scan additionally records:

```text
viewer URL
download URL
resolution strategy
asset size
asset SHA-256
storage path
```

Original remote filenames and request hints are preserved; normalization does not replace raw values.

## Non-goals for v0.1

- MyTree source identity reconciliation
- OCR or transcription
- fuzzy act matching
- person identity resolution
- automatic selection of a remote book from only parish/year/type
- Laravel/Eloquent/Filament integration in the core package

Collection/book discovery by parish/year/type may be added later as a separate capability after provider semantics are verified.
