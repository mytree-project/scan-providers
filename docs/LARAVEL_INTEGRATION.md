# Laravel / MyTree integration

The core package must remain free of Laravel dependencies.

## Composition roots

The standalone package CLI uses `DefaultScanProviderRegistryFactory` to register the completed built-in providers with `ScanProviderRegistry`. This is a package composition convenience, not a Laravel integration mechanism and not a provider-routing switch.

A future Laravel integration should bind infrastructure contracts and register providers in a service provider, for example:

```text
HttpClientInterface
    -> LaravelHttpClient

ScanAssetStorageInterface
    -> LaravelFilesystemScanAssetStorage
```

`ScanProviderRegistry` should receive registered provider services through dependency injection/tagging rather than reading framework configuration inside the core library. M7 owns this MyTree/Laravel registration; completing the P2 standalone CLI does not pre-empt that work.

For Szukaj w Archiwach, browser-aware live transport is implemented and owned by the standalone `scan-providers` runtime because the package is also an independently usable scan-acquisition tool. M7 must reuse/configure that capability rather than introduce a Laravel-specific browser workaround. URL/viewer interpretation remains on the provider side; Laravel domain/application code must not parse Szukaj w Archiwach HTML or know the `photos.szukajwarchiwach.gov.pl` asset convention. Chromium/Playwright remains an infrastructure/runtime concern with bounded ephemeral sessions and no persistence/logging of cookie values. Normal CI remains offline through fakes/fixtures.

## Suggested MyTree flow

```text
ExternalIndexRecord / manual locator
        ↓
MyTree adapter creates ScanResourceReference + ScanLocatorHints
        ↓
ResolveScan
        ↓
DownloadScan
        ↓
DownloadedScan
        ↓
MyTree Source Acquisition adapter
        ↓
SourceAsset
```

The adapter is responsible for mapping MyTree/index-provider data to the scan-provider contract. The scan provider must not depend on `ExternalIndexRecord` directly.

## Source identity boundary

`DownloadedScan` represents a retrieved digital asset, not a historical source identity decision.

Two providers may download two different `SourceAsset` objects that later turn out to represent the same underlying `Source`. MyTree must preserve each asset's independent:

```text
provider
origin URL
viewer/download locator
retrieval timestamp
SHA-256
technical metadata
```

Byte equality may support storage deduplication but must not be treated as proof of historical source identity.

## Queueing and retries

Laravel may later execute resolve/download operations in queued jobs. Retry/checkpoint orchestration belongs to the Laravel/application layer for batch workflows; the provider itself stays focused on one requested resource/scan operation.

## Filament

A future Filament UI can expose separate actions:

```text
Inspect available scans
Resolve from source/index hints
Download selected/resolved scan
Attach as SourceAsset
```

Ambiguous/unresolved results must remain visible to the user instead of being auto-corrected by the UI.
