# Szukaj w Archiwach provider

## Current P2 scope

Provider key:

```text
szukajwarchiwach
```

The current P2 implementation supports fixture-backed catalog discovery and deterministic ordinal resolution for a known current unit URL:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>#scan<N>
```

The numeric unit identifier is a provider locator. It is not a MyTree `SourceId`, and catalog discovery or ordinal resolution does not establish historical source identity.

## Catalog discovery

`SzukajWArchiwachProvider` implements `ScanCatalogDiscoveryInterface`. Discovery:

- reads the public unit HTML rather than an undocumented service API,
- treats `Skany (N)` / `Scans (N)` as the digital scan cardinality,
- accepts `Skany (0)` as a valid empty catalog,
- follows the public catalog pagination in order,
- verifies that complete enumeration matches the declared cardinality,
- preserves the provider object/file locator from `data-plikid` as `AvailableScan::remoteId`,
- assigns a stable one-based `scan_ordinal` from the complete ordered catalog,
- keeps the unit ID, metadata, raw metadata fields, per-page hashes and complete discovery hash in provenance.

`AvailableScan::viewerUrl` remains the unit URL and `remoteFilename` remains empty until the later per-object viewer/download step. The provider object locator is preserved explicitly in `remoteId`, opaque locators and metadata.

## Ordinal resolution

Szukaj w Archiwach deep links use a browser fragment such as:

```text
#scan42
```

The fragment is a locator hint, not an asset URL. Resolution therefore parses the requested positive ordinal, discovers or reuses the current unit catalog, and matches the requested value against the catalog entry's explicit `scan_ordinal` metadata.

Package callers may alternatively supply the positive decimal ordinal through `ScanLocatorHints::scanNumberRaw` when the resource URL does not contain a fragment. If both the URL fragment and `scanNumberRaw` are present, they must agree. Conflicting raw hints are preserved on the request and resolution returns `unresolved` instead of choosing one silently.

Resolution outcomes are explicit:

```text
exactly one matching ordinal  -> resolved
no matching ordinal           -> unresolved
more than one matching ordinal-> ambiguous
malformed/unsupported locator  -> unsupported
missing ordinal                -> unresolved
```

A resolved result retains the exact provider object/file locator selected from catalog discovery, the original request URL/hints, the `scan_ordinal` strategy and the full catalog provenance. An out-of-range raw index locator remains visible in the request even when current discovery contradicts it.

The serialized result remains `mytree.scan-resolution.v1`; this step adds provider behavior without changing the public result shape.

## Metadata and provenance

The parser preserves raw label/value pairs and maps common public labels when recognized, including:

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

Legacy `szukajwarchiwach.pl` URLs are not mechanically rewritten by this adapter. Current-unit discovery requires the current numeric-unit URL family.

## Network behavior

This is an undocumented public-web integration. The discovery adapter is deliberately bounded:

- requests are sequential,
- pagination has a hard maximum,
- optional request pacing is applied between pages,
- transport failures plus HTTP `429` / `5xx` responses use bounded retry/backoff,
- successful validated catalogs are cached in-memory for the same resource URL,
- malformed, incomplete or cardinality-mismatched responses fail explicitly and are never cached as successful discovery.

Normal automated tests never access the live service.

## Fixtures and tests

Fixtures under `tests/fixtures/szukajwarchiwach/` are sanitized structural fixtures based on the public HTML selectors recorded during feasibility work. They cover metadata-rich, zero-scan, paginated and malformed catalog structures.

Ordinal parsing/resolution tests additionally cover:

- the accepted `#scan42` form,
- first/last valid ordinals,
- out-of-range discovery mismatches,
- malformed/non-positive fragments,
- missing ordinals,
- conflicting raw locator hints,
- deliberately non-unique catalog ordinal metadata producing `ambiguous`.

Tests intentionally avoid live service availability and undocumented internal API endpoints.

## Deferred P2 work

The following capabilities remain outside this step:

```text
per-object viewer route resolution
raw asset/download URL resolution
asset download
CLI/default composition-root registration
arbitrary signature -> unit search
optimized whole-unit/batch download
```
