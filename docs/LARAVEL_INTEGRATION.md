# Laravel / MyTree integration

The core package must remain free of Laravel dependencies.

## Composition root

A future Laravel integration should bind infrastructure contracts and register providers in a service provider, for example:

```text
HttpClientInterface
    -> LaravelHttpClient

ScanAssetStorageInterface
    -> LaravelFilesystemScanAssetStorage
```

`ScanProviderRegistry` should receive registered provider services through dependency injection/tagging rather than reading framework configuration inside the core library.

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
