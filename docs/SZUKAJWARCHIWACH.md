# Szukaj w Archiwach provider

## Current P2 scope

Provider key:

```text
szukajwarchiwach
```

The first P2 implementation step supports fixture-backed catalog discovery for a known current unit URL:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>
```

The numeric unit identifier is a provider locator. It is not a MyTree `SourceId` and catalog discovery does not establish historical source identity.

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

At this stage the provider intentionally does not resolve `#scan<N>` to one object and does not resolve per-object viewer/download URLs. `AvailableScan::viewerUrl` therefore remains the unit URL and `remoteFilename` remains empty; the provider object locator is preserved explicitly in `remoteId`, opaque locators and metadata. Later P2 steps fill the resolution/download behavior without changing the catalog ordering contract.

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

## Fixtures

Fixtures under `tests/fixtures/szukajwarchiwach/` are sanitized structural fixtures based on the public HTML selectors recorded during feasibility work. They cover:

- a metadata-rich multi-scan unit,
- a zero-scan unit,
- a 30-scan paginated catalog split over three pages,
- malformed/changed HTML.

They intentionally avoid depending on live service availability or undocumented internal API endpoints.

## Deferred P2 work

The following capabilities are deliberately outside this step:

```text
#scan<N> deterministic resolution
per-object viewer route resolution
raw asset/download URL resolution
asset download
CLI/default composition-root registration
arbitrary signature -> unit search
optimized whole-unit/batch download
```
