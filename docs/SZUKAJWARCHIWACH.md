# Szukaj w Archiwach provider

## Current P2 scope

Provider key:

```text
szukajwarchiwach
```

The completed P2 package implementation supports fixture-backed catalog discovery, deterministic ordinal resolution, per-object asset download, default standalone registry/CLI exposure and compatible serialized output for a known current unit URL:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>#scan<N>
https://www.szukajwarchiwach.gov.pl/skan/-/skan/<opaque-token>
```

The numeric unit identifier and per-object identifier are provider locators. They are not MyTree `SourceId` values, and discovery, resolution or download does not establish historical source identity.

## Registration and routing

The standalone CLI registers `SzukajWArchiwachProvider` together with `GenealodzySkanotekaProvider` through `DefaultScanProviderRegistryFactory`. The factory builds the normal `ScanProviderRegistry`; callers do not switch on provider keys or hosts themselves.

Routing support is deliberately narrow. Szukaj w Archiwach matches the current numeric-unit URL family and the official public `/skan/-/skan/<opaque-token>` family on the current service host. Locale-prefixed current unit paths such as `/en/jednostka/...` and `/de/jednostka/...` are accepted and canonicalized internally for discovery. A legacy locator such as:

```text
https://szukajwarchiwach.pl/54/744/0/6.1/47/str/1/3/15/
```

is not claimed by the provider and is not mechanically rewritten. Such legacy values remain useful provenance until a separately specified reconciliation strategy can resolve them safely.

## Catalog discovery

`SzukajWArchiwachProvider` implements `ScanCatalogDiscoveryInterface`. Discovery:

- reads the public unit HTML rather than an undocumented service API,
- uses `Skany (N)` / `Scans (N)` as the declared digital scan cardinality when that label is present,
- accepts an explicit declared zero as a valid empty catalog,
- follows the public catalog pagination in order, including the current Liferay-style `_Jednostka_cur` links,
- verifies complete enumeration against a declared cardinality when available,
- otherwise records the safely enumerated catalog cardinality and marks its provenance as `enumerated_catalog`,
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

Package/CLI callers may alternatively supply the positive decimal ordinal through `ScanLocatorHints::scanNumberRaw` / `--scan-number=<N>` when the resource URL does not contain a fragment. If both the URL fragment and `scanNumberRaw` are present, they must agree. Conflicting raw hints are preserved on the request and resolution returns `unresolved` instead of choosing one silently.

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

## Official direct scan links

The portal's side panel exposes an official **Link do skanu** in the form:

```text
https://www.szukajwarchiwach.gov.pl/skan/-/skan/<opaque-token>
```

This is already an exact public scan locator and does not need unit-catalog/ordinal resolution. `ResolveScan` therefore returns `resolved` with strategy `direct_public_scan_url` without a network request. `DownloadScan` fetches that same public URL, validates the image MIME type and stores it through `ScanAssetStorageInterface`.

A direct scan URL does not itself reveal unit ID, ordinal or provider object ID. Those values are therefore not invented. The raw URL/token and direct-resolution strategy are preserved as the available provenance. When unit/object/ordinal provenance is required, callers should use the unit + ordinal workflow instead.

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

## CLI

The existing provider-neutral commands expose the full workflow:

```bash
php bin/mytree-scan providers

php bin/mytree-scan discover \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>"

php bin/mytree-scan resolve \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>#scan42"

php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>#scan42" \
  --output=var/scans
```

`resolve` and `download` also accept `--scan-number=<N>` with a fragment-free current unit URL. They additionally accept an official `/skan/-/skan/<opaque-token>` URL directly. `discover --format=json`, resolution output and download output use the existing structured serialization contracts; no Szukaj-w-Archiwach-specific orchestration command is introduced.

The human-readable discovery table uses provider-neutral columns (`REMOTE ID`, `LABEL`, optional `REMOTE FILENAME`, `LOCATOR`, `VIEWER URL`) so providers without a published remote filename remain usable.

## Metadata, serialization and provenance

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

Resolution additionally preserves the original request/locator inputs, requested ordinal, candidates, selected `AvailableScan` and therefore exact `scan_ordinal` / object identity. A downloaded result preserves through the existing serialized contract:

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
catalog provenance including unit metadata and access-rights metadata
```

The existing contracts remain sufficient and unchanged:

```text
mytree.scan-catalog.v1
mytree.scan-resolution.v1
mytree.downloaded-scan.v1
```

No incompatible public shape change is required for P2.

## Network behavior

This is an undocumented public-web integration. Network behavior is deliberately bounded:

- requests are sequential,
- pagination has a hard maximum,
- optional request pacing is applied between catalog pages and between viewer/asset requests,
- transport failures plus HTTP `429` / `5xx` responses use bounded retry/backoff,
- the same retry policy is reused by catalog, viewer and asset retrieval,
- successful validated catalogs are cached in-memory for the same resource URL,
- malformed, incomplete or cardinality-mismatched responses fail explicitly and are never cached as successful discovery,
- absence of the historical `Skany (N)` label is not by itself an error when ordered scan entries and official pagination can be enumerated safely,
- presentation metadata such as the unit title is preserved when recognizable but is not required to resolve an otherwise deterministic unit/object/ordinal catalog,
- unexpected viewer structure and non-image download responses fail explicitly.

Normal automated tests never access the live service.

## Fixtures and tests

Fixtures under `tests/fixtures/szukajwarchiwach/` are sanitized structural fixtures based on the public HTML/URL behavior recorded during feasibility work. They cover metadata-rich, zero-scan, paginated and malformed catalog structures plus representative per-object viewer structures.

Provider-specific tests cover ordinal parsing/resolution, viewer/download extraction, retry behavior and malformed responses. Package-level integration tests additionally cover:

```text
current unit URL
  → ScanProviderRegistry selects szukajwarchiwach
  → DiscoverScans
  → ResolveScan (#scanN)
  → DownloadScan
  → v1 serialized outputs + provenance
```

They also verify official direct `/skan/-/skan/<opaque-token>` resolution/download, count-less `_Jednostka_cur` pagination, that the default standalone registry still routes Genealodzy Skanoteka correctly, and that legacy `szukajwarchiwach.pl` URLs are not claimed. The `providers` CLI listing is tested without network access.

## Deferred work / non-goals

The completed P2 package does not implement:

```text
arbitrary signature -> unit search
optimized whole-unit/batch download
legacy URL -> current unit reconciliation
MyTree/Laravel application registration
```

Laravel/MyTree composition, persistence and Source Acquisition integration belong to milestone M7. The core package remains framework-independent.
