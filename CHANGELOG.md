# Changelog

## Unreleased

- Add Playwright/Chromium browser-session transport for live Szukaj w Archiwach fallback: protected HTML pages can be retrieved through an ephemeral browser context and scan viewers can yield the actual image subresource without exposing browser types in public/domain contracts.

- Add initial `szukajwarchiwach` known-unit catalog discovery with metadata, digital scan cardinality, ordered provider object locators, pagination validation, bounded retry/pacing, in-memory validated-result caching and offline fixtures.
- Add deterministic Szukaj w Archiwach scan-ordinal resolution from `#scan<N>` or explicit `scanNumberRaw`, with safe unresolved/ambiguous/unsupported outcomes and preserved catalog provenance.
- Add Szukaj w Archiwach per-object viewer resolution and public `/skan/-/skan/<opaque-token>` viewer-locator handling with MIME validation, explicit browser-transport-required failure when standalone HTTP receives viewer HTML, bounded retries and preserved catalog provenance.
- Register the completed Szukaj w Archiwach provider in the standalone CLI/default registry, add provider-neutral discovery output and end-to-end offline registry/serialization coverage while retaining the existing v1 schemas.
- Accept official Szukaj w Archiwach `/skan/-/skan/<opaque-token>` links as exact resolvable viewer locators, document the browser-session requirement for current live image acquisition, and tolerate current `_Jednostka_cur` catalog pagination when the historical `Skany (N)` label is absent.

## 0.1.0

- Initial framework-independent scan provider package.
- Add provider registry and scan discovery/resolution/download application services.
- Add the first provider for `metryki.genealodzy.pl` (Genealodzy Skanoteka).
- Add deterministic act-number-to-scan resolution from exact/range filenames.
- Add local filesystem storage, CLI commands and fixture-driven PHPUnit coverage.
