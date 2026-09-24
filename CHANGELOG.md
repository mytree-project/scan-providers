# Changelog

## Unreleased

- Complete the `szukajwarchiwach` P2 provider for known current unit URLs and official public scan-viewer locators.
- Add browser-first Szukaj w Archiwach unit/catalog retrieval in the standalone runtime because successful-looking Native HTTP responses can expose incomplete catalogs.
- Add deterministic ordinal resolution from `#scan<N>` or explicit `scanNumberRaw`, preserving unit/object identity, safe unresolved/ambiguous/unsupported outcomes and catalog provenance.
- Add Playwright/Chromium infrastructure behind browser-agnostic provider/domain contracts. The standalone CLI provisions this capability; Laravel/MyTree integration is deferred to M7 rather than reimplementing provider-specific browser logic.
- Add known-unit + ordinal browser acquisition through the portal's real UI flow: 200-entry gallery selection, object-id verification, photoslider iframe, provider-emitted `Link do scanu` public viewer, and observed original/full-resolution image response.
- Accept official `/skan/-/skan/<opaque-token>` URLs as exact HTML viewer locators and capture the actual image subresource through Chromium without deriving undocumented `<token>_max` URLs.
- Add JSON-only optional browser diagnostics; active workers no longer record WebM/video.
- Preserve existing `mytree.scan-catalog.v1`, `mytree.scan-resolution.v1` and `mytree.downloaded-scan.v1` contracts while retaining provider catalog/resolution provenance and effective raw download URLs.
- Keep arbitrary archival-signature discovery, optimized whole-unit acquisition and future official-API migration as separate deferred capabilities.

## 0.1.0

- Initial framework-independent scan provider package.
- Add provider registry and scan discovery/resolution/download application services.
- Add the first provider for `metryki.genealodzy.pl` (Genealodzy Skanoteka).
- Add deterministic act-number-to-scan resolution from exact/range filenames.
- Add local filesystem storage, CLI commands and fixture-driven PHPUnit coverage.
