# Szukaj w Archiwach provider

## Completed P2 scope

Provider key:

```text
szukajwarchiwach
```

P2 supports:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>#scan<N>
https://www.szukajwarchiwach.gov.pl/skan/-/skan/<opaque-token>
```

The package can discover a known current unit, resolve a deterministic one-based scan ordinal, and download the selected original/full-resolution image through the standalone CLI. It can also resolve/download an official public `/skan/-/skan/<token>` viewer directly.

The numeric unit ID and numeric provider object/file ID are provider locators, not MyTree `SourceId` values. Scan resolution is a technical acquisition decision and does not establish historical source identity.

## Registration and routing

`DefaultScanProviderRegistryFactory` registers `SzukajWArchiwachProvider` alongside `GenealodzySkanotekaProvider` through the normal `ScanProviderRegistry`.

Supported current unit paths may be locale-prefixed and are canonicalized internally. Legacy locators such as:

```text
https://szukajwarchiwach.pl/54/744/0/6.1/47/str/1/3/15/
```

are not mechanically rewritten. They remain raw provenance/locator values unless a future explicit reconciliation capability resolves them.

## Catalog discovery

`SzukajWArchiwachProvider` implements `ScanCatalogDiscoveryInterface`.

### Browser-first correctness boundary

Live validation established that Native HTTP may return:

- explicit Imperva/Incapsula soft-block content, or
- successful-looking HTTP 200 catalog HTML that is incomplete compared with the browser-visible portal.

For the normal standalone CLI, current Szukaj w Archiwach unit/catalog pages are therefore retrieved through the configured Chromium browser page capability. This is a correctness decision, not a debug-only fallback.

The browser page worker prefers the portal's 200-entry catalog representation so units with up to 200 scans can normally be enumerated in one render when the portal honors that setting. Ordinary pagination remains supported for larger units or when the 200-entry representation is not sufficient.

### Catalog semantics

Discovery:

- uses public unit/catalog HTML rather than an undocumented service API,
- preserves numeric unit ID and provider-published metadata/raw fields,
- preserves provider object/file IDs exposed as `data-plikid`,
- enumerates scans in stable portal order,
- assigns explicit one-based `scan_ordinal` metadata,
- validates declared `Skany (N)` / `Scans (N)` cardinality when present,
- accepts explicit declared zero as a valid empty catalog,
- can use safely enumerated cardinality when the count label is absent,
- rejects repeated object IDs, pagination loops, malformed/partial structures and declared-count mismatches,
- records complete discovery hash, per-page hashes/counts, scan cardinality and unit metadata in provenance.

A discovered entry retains a deterministic object-viewer locator:

```text
https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<unit-id>/obiekty/<object-id>
```

`AvailableScan::remoteFilename` remains empty because the unit catalog does not publish a stable raw filename.

## Ordinal resolution

A fragment such as:

```text
#scan42
```

is an ordinal locator hint, not an asset URL.

Callers may alternatively supply:

```text
--scan-number=42
```

Resolution parses/validates the positive ordinal, discovers/reuses the current catalog and matches against explicit `scan_ordinal` metadata. If both fragment and explicit hint exist they must agree.

Outcomes remain explicit:

```text
exactly one ordinal match  → resolved
no matching ordinal        → unresolved
non-unique match           → ambiguous
unsupported/malformed hint → unsupported
```

The result preserves the original request/hints, selected provider object/file locator, candidates and catalog provenance. No ordinal is repaired through fuzzy matching or OCR.

The serialized resolution remains `mytree.scan-resolution.v1`.

## Official public scan viewer

The portal exposes a public viewer locator in the form:

```text
https://www.szukajwarchiwach.gov.pl/skan/-/skan/<opaque-token>
```

The current portal UI labels the control `Link do scanu`; the browser worker also tolerates the `Link do skanu` spelling.

Live validation proved that this route returns an HTML viewer, not the raw image bytes. The viewer loads the actual scan image as a separate request from:

```text
photos.szukajwarchiwach.gov.pl
```

`ResolveScan` can resolve a public viewer URL directly with strategy:

```text
public_scan_viewer_url
```

The token remains opaque. The implementation does not decode it and does not construct an assumed `_max` URL from it.

## Known unit + ordinal download flow

The normal standalone browser path deliberately reproduces the provider's user-facing selection flow rather than trying to infer an image from arbitrary object-viewer DOM.

For requested ordinal `N`:

```text
ResolvedScan(unit id + ordinal + object id)
  → open canonical unit page in Chromium
  → switch/show 200 scans per page when available
  → navigate to the page containing N when necessary
  → select thumbnail ((N - 1) mod 200) + 1
  → verify the visible scan ordinal
  → verify thumbnail data-plikid == resolved object id
  → click load-photo-slider
  → wait for provider-created photoslider iframe
  → wait for the photoslider application to render
  → click Link do scanu / Link do skanu
  → read provider-emitted /skan/-/skan/<opaque-token>
  → open that public viewer in the same browser context
  → capture the actual full-resolution image response
  → validate MIME
  → ScanAssetStorageInterface
```

The identity check against `data-plikid` is important: if current gallery ordering differs from the previously resolved catalog object, download fails instead of silently selecting another scan.

Known preview variants such as `_mid`, `_min` and `_thumb` are not accepted as the final original/full-resolution asset. The worker prefers/accepts the actual observed full-quality response and never manufactures a `_max` URL.

### Live compatibility evidence

The P2 live validation used:

```text
unit: https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/11959850
scan ordinal: 79
provider object/file id: 6956791
```

The portal rendered 154 scans. With the 200-entry view, scan 79 was identified by the page itself as object `6956791`. Clicking it opened a photoslider iframe carrying `plikid=6956791`; the official `Link do scanu` flow then yielded the public viewer and the correct requested full-resolution image. This live path passed on 2026-09-24.

The earlier diagnostic response `f7509c..._mid` came from an unrelated preview on a multi-thumbnail object-viewer page and is not identity evidence for scan 79.

## Direct public-viewer download

When the input itself is `/skan/-/skan/<token>`:

```text
public viewer locator
  → Chromium viewer
  → observed image response from photos.szukajwarchiwach.gov.pl
  → validated image bytes
  → storage
```

A successful PoC/live test observed a JPEG ending in `_max`, but this suffix is compatibility evidence only.

## Optional no-browser compatibility path

Provider internals retain fixture-backed parsing for a static object-viewer page. When `SzukajWArchiwachProvider` is instantiated without browser transport and a supported public `/skan/-/skan/<token>` link is explicitly present in object-viewer HTML, the provider may follow that link through the HTTP path.

This compatibility path does not change the normal standalone CLI behavior: current CLI composition includes Chromium because complete live SZA catalog and full-image acquisition require browser-visible state.

Undocumented technical endpoints such as `/o/pliki-api/...` are not treated as public provider contracts.

## Provenance and serialization

Catalog provenance records, as available:

```text
provider key/version
original resource URL
canonical current unit URL
numeric unit ID
retrieval timestamp
complete discovery SHA-256
per-page response SHA-256 values
per-page entry counts / observed page size
scan count / declared count when available
unit metadata + raw fields
discovery strategy
```

Resolution additionally preserves request hints, requested ordinal, candidates and selected object/file identity.

For known-unit + ordinal download:

- `resource_url` preserves the original supplied resource/deep link,
- `viewer_url` is the deterministic per-object `/obiekty/<object-id>` locator retained by catalog resolution,
- `download_url` is the effective raw image response URL,
- `catalog_provenance` preserves unit/object/ordinal discovery context,
- MIME type, stored path/name, byte size, SHA-256 and retrieval timestamp are preserved.

The public `/skan/-/skan/<token>` discovered inside the browser UI flow is an intermediate transport locator and is not separately represented in `mytree.downloaded-scan.v1`. For a direct public-viewer request, that `/skan/...` URL is naturally the serialized `viewer_url`.

Existing schemas remain sufficient:

```text
mytree.scan-catalog.v1
mytree.scan-resolution.v1
mytree.downloaded-scan.v1
```

No P2 schema version bump is required.

## Access / rights

Provider-published access/rights wording is preserved as unit metadata/provenance when exposed. Download availability is not interpreted as public-domain status.

## Network behavior

The integration is intentionally bounded:

- browser sessions are ephemeral and closed deterministically,
- cookie values are never persisted/logged,
- catalog pagination has a hard maximum,
- requests are sequential/conservative,
- HTTP retry/backoff is bounded for transport failures, 429 and 5xx where the HTTP path is used,
- only structurally validated catalog results are cached,
- unexpected viewer/photoslider/image structures fail explicitly,
- normal automated CI never accesses the live service.

The presence of `x-iinfo` alone is not classified as a block because successful responses may carry it.

## CLI

```bash
php bin/mytree-scan providers

php bin/mytree-scan discover \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>"

php bin/mytree-scan resolve \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>#scan42"

php bin/mytree-scan download \
  --url="https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/<UNIT_ID>" \
  --scan-number=42 \
  --output=var/scans
```

Direct official viewer locators are also accepted by `resolve` and `download`.

Optional diagnostics:

```text
--browser-debug-dir=<path>
```

This writes JSON manifests only; active workers do not record video/WebM. Diagnostics do not alter transport selection. See `docs/SZUKAJWARCHIWACH_BROWSER_DEBUG.md`.

## Fixtures and tests

Normal tests are deterministic/offline and cover:

- multi-scan unit discovery and metadata,
- zero-scan units,
- large/paginated catalogs,
- declared-count mismatch / malformed structures,
- exact, malformed, conflicting and out-of-range ordinal resolution,
- official direct public-viewer resolution,
- browser page selection through fakes,
- unit + ordinal browser download contract through a fake browser client,
- object-viewer compatibility parsing and explicit failures,
- MIME validation, storage metadata and hashes,
- registry/CLI/serialization integration,
- Genealodzy Skanoteka routing/non-regression.

Node browser-worker syntax/imports are validated by CI without live portal access.

## Deferred work / non-goals

P2 intentionally does not implement:

```text
arbitrary archival signature → current unit discovery   (#161)
optimized whole-unit/batch acquisition                  (#162)
official API migration before a supported API exists   (#163)
legacy URL → current unit mechanical rewriting
MyTree/Laravel application integration                  (M7)
```

M7 consumes/configures the completed browser-capable package through public scan-provider contracts; it must not copy provider-specific browser/UI logic into Laravel.
