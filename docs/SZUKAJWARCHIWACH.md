# Szukaj w Archiwach provider

## Current P2 scope

Provider key:

```text
szukajwarchiwach
```

The current P2 implementation supports fixture-backed catalog discovery, deterministic ordinal resolution and per-object asset download for a known current unit URL:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>#scan<N>
```

The numeric unit identifier and per-object identifier are provider locators. They are not MyTree `SourceId` values, and discovery, resolution or download does not establish historical source identity.

## Catalog discovery

`SzukajWArchiwachProvider` implements `ScanCatalogDiscoveryInterface`. Discovery:

- reads the public unit HTML rather than an undocumented service API,
- treats `Skany (N)` / `Scans (N)` as the digital scan cardinality,
- accepts `Skany (0)` as a valid empty catalog,
- follows the public catalog pagination in order,
- verifies that complete enumeration matches the declared cardinality,
- preserves the provider object/file locator from `data-plikid` as `AvailableScan::remoteId`,
- assigns a stable one-based `scan_ordinal` from the complete ordered catalog,
- derives the public object viewer route from the discovered unit ID plus exact object ID,
- keeps the unit ID, metadata, raw metadata fields, per-page hashes and complete discovery hash in provenance.

A discovered scan uses the exact public viewer context:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>/obiekty/<object-id>
```

`AvailableScan::remoteFilename` remains empty because the unit catalog does not publish a stable raw filename. The provider object locator remains explicit in `remoteId`, opaque locators and metadata.

## Ordinal resolution

Szukaj w Archiwach deep links use a browser fragment such as:

```text
#scan42
```

The fragment is a locator hint, not an asset URL. Resolution therefore parses the requested positive ordinal, discovers or reuses the current unit catalog, and matches the requested value against the catalog entry's explicit `scan_ordinal` metadata.

Package callers may alternatively supply the positive decimal ordinal through `ScanLocatorHints::scanNumberRaw` when the resource URL does not contain a fragment. If both the URL fragment and `scanNumberRaw` are present, they must agree. Conflicting raw hints are preserved on the request and resolution returns `unresolved` instead of choosing one silently.

Resolution outcomes are explicit:

```text
exactly one matching ordinal   -> resolved
no matching ordinal            -> unresolved
more than one matching ordinal -> ambiguous
malformed/unsupported locator   -> unsupported
missing ordinal                 -> unresolved
```

A resolved result retains the exact provider object/file locator selected from catalog discovery, the original request URL/hints, the `scan_ordinal` strategy and the full catalog provenance. An out-of-range raw index locator remains visible in the request even when current discovery contradicts it.

The serialized result remains `mytree.scan-resolution.v1`; the provider behavior does not change the public result shape.

## Per-object viewer and asset download

Download uses the resolved catalog object rather than deriving an asset from the ordinal itself:

```text
ResolvedScan
   ↓
/jednostka/-/jednostka/<unit-id>/obiekty/<object-id>
   ↓
public object viewer HTML
   ↓
/skan/-/skan/<opaque-token>
   ↓
validated image response
   ↓
ScanAssetStorageInterface
```

The viewer parser treats the `/skan/-/skan/<opaque-token>` value as opaque provider data. It accepts only HTTPS links on the current Szukaj w Archiwach host family and does not derive or decode the token.

Undocumented technical routes such as:

```text
/o/pliki-api/...
```

are deliberately ignored as provider contracts. A viewer fixture may contain such a decoy route; it is not sufficient for a successful download. If no supported public scan link is exposed, or if multiple distinct supported links are exposed, the adapter fails explicitly rather than guessing.

The public scan URL is fetched through the shared HTTP boundary. The built-in `NativeHttpClient` follows a bounded number of HTTP redirects; `DownloadedScan::downloadUrl` still records the public `/skan/-/skan/<opaque-token>` locator selected from the viewer rather than an undocumented implementation endpoint reached behind it.

The response must validate as a supported image MIME type. Storage receives a deterministic provider-local filename derived from unit/object identity and MIME extension; file size and SHA-256 remain properties of the stored asset. `mytree.downloaded-scan.v1` is unchanged.

## Metadata and provenance

The catalog parser preserves raw label/value pairs and maps common public labels when recognized, including:

```text
signature
dates
archive
fonds/collection
description
access/rights
```

Raw fields remain available in provenance even when a label has no shared mapping. Access/rights text is preserved as provider metadata; the package does not infer public-domain status from download availability.

The catalog provenance records:

```text
provider key/version
original resource URL
canonical current unit URL
numeric unit ID
retrieval timestamp
complete discovery response SHA-256
per-page response SHA-256 values
scan count
page count
unit metadata + raw fields
discovery strategy
```

A downloaded result additionally preserves through the existing serialized contract:

```text
original resource URL (including locator fragment when supplied)
exact per-object viewer URL
public /skan/-/skan/<opaque-token> download URL
resolution strategy
retrieval timestamp
MIME type
stored filename/path
byte size
SHA-256
catalog provenance including unit/object context and access-rights metadata
```

Legacy `szukajwarchiwach.pl` URLs are not mechanically rewritten by this adapter. Current-unit discovery requires the current numeric-unit URL family.

## Network behavior

This is an undocumented public-web integration. Network behavior is deliberately bounded:

- requests are sequential,
- pagination has a hard maximum,
- optional request pacing is applied between catalog pages and between viewer/asset requests,
- transport failures plus HTTP `429` / `5xx` responses use bounded retry/backoff,
- the same retry policy is reused by catalog, viewer and asset retrieval,
- successful validated catalogs are cached in-memory for the same resource URL,
- malformed, incomplete or cardinality-mismatched responses fail explicitly and are never cached as successful discovery,
- unexpected viewer structure and non-image download responses fail explicitly.

Normal automated tests never access the live service.

## Fixtures and tests

Fixtures under `tests/fixtures/szukajwarchiwach/` are sanitized structural fixtures based on the public HTML/URL behavior recorded during feasibility work. They cover metadata-rich, zero-scan, paginated and malformed catalog structures plus representative per-object viewer structures.

Ordinal parsing/resolution tests cover:

- the accepted `#scan42` form,
- first/last valid ordinals,
- out-of-range discovery mismatches,
- malformed/non-positive fragments,
- missing ordinals,
- conflicting raw locator hints,
- deliberately non-unique catalog ordinal metadata producing `ambiguous`.

Download tests additionally cover:

- exact object-viewer URL derivation,
- extraction of the supported public `/skan/-/skan/` route,
- ignoring an undocumented internal API route,
- deterministic image MIME/size/SHA-256 and storage filename,
- missing/changed public download control,
- transient `503` / `429` retry behavior with zero test sleeps,
- rejection of non-image responses,
- preservation of catalog access-rights metadata in `DownloadedScan` provenance.

Tests intentionally avoid live service availability and undocumented internal API endpoints.

## Deferred P2 work

The following capabilities remain outside this step:

```text
CLI/default composition-root registration
arbitrary signature -> unit search
optimized whole-unit/batch download
```
