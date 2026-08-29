# Genealodzy Skanoteka provider

Provider key:

```text
genealodzy-skanoteka
```

Initial supported host:

```text
metryki.genealodzy.pl
```

## Resource model

Geneteka may link an index result to a Skanoteka resource such as:

```text
https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12
```

This URL identifies a catalog/resource rather than necessarily one exact image.

## Discovery

Catalog HTML is parsed for anchors whose resolved URL contains a non-empty `plik` query parameter. The filename is retained exactly (after URL decoding/basename extraction) and exposed through `AvailableScan`.

Strict filename forms produce standardized act-number locators:

```text
17.jpg       => exact act 17
017.jpg      => exact act 17
12-21.jpg    => act range 12..21
012-021.jpg  => act range 12..21
```

Other filenames remain `opaque` and are still returned by discovery.

## Resolution

The first deterministic strategy is:

```text
filename_act_number_range
```

Input record numbers must be positive numeric strings. The provider returns:

- `resolved` when exactly one scan locator contains the requested number,
- `ambiguous` when multiple scans contain it,
- `unresolved` when none contain it or the input format is unsupported.

No OCR, person-name matching or fuzzy filename guessing is performed.

## Download URL resolution

After act resolution, the provider opens the selected viewer URL and inspects anchor/image URLs for the remote filename and known `plik`/`skan` parameters. The highest-specificity candidate is used as the binary request URL.

The implementation is intentionally parser-driven and does not require Selenium. If the live service later requires JavaScript execution, browser-capable retrieval should be added behind an infrastructure contract rather than embedded into MyTree/Laravel code.

## Provider drift

Third-party HTML is not a stable API. Parser changes must be covered by fixtures before release. Unexpected pages should fail explicitly rather than returning an invented scan mapping.
