# Changelog

## Unreleased

- Add initial `szukajwarchiwach` known-unit catalog discovery with metadata, digital scan cardinality, ordered provider object locators, pagination validation, bounded retry/pacing, in-memory validated-result caching and offline fixtures.
- Add deterministic Szukaj w Archiwach scan-ordinal resolution from `#scan<N>` or explicit `scanNumberRaw`, with safe unresolved/ambiguous/unsupported outcomes and preserved catalog provenance.
- Add Szukaj w Archiwach per-object viewer resolution and public `/skan/-/skan/<opaque-token>` image download with MIME validation, bounded retries, stored size/SHA-256 and preserved catalog provenance.

## 0.1.0

- Initial framework-independent scan provider package.
- Add provider registry and scan discovery/resolution/download application services.
- Add the first provider for `metryki.genealodzy.pl` (Genealodzy Skanoteka).
- Add deterministic act-number-to-scan resolution from exact/range filenames.
- Add local filesystem storage, CLI commands and fixture-driven PHPUnit coverage.
