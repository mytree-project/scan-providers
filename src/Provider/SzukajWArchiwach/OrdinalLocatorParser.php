<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

use MyTree\ScanProviders\Domain\ResolveScanRequest;

final class OrdinalLocatorParser
{
    public function parse(ResolveScanRequest $request): OrdinalLocatorParseResult
    {
        $url = $request->resource->url;
        $hasFragment = str_contains($url, '#');
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        $fragment = is_string($fragment) ? $fragment : null;
        $hintRaw = $request->hints->scanNumberRaw;

        if ($hasFragment) {
            if ($fragment === null || !preg_match('~^scan([1-9]\d*)$~', $fragment, $match)) {
                return new OrdinalLocatorParseResult(
                    status: OrdinalLocatorParseResult::UNSUPPORTED,
                    reason: 'unsupported_scan_fragment',
                    trace: ['Szukaj w Archiwach scan fragments must use the exact #scan<N> form with a positive ordinal.'],
                );
            }

            $fragmentOrdinal = $this->positiveDecimal($match[1]);
            if ($fragmentOrdinal === null) {
                return new OrdinalLocatorParseResult(
                    status: OrdinalLocatorParseResult::UNSUPPORTED,
                    reason: 'unsupported_scan_fragment',
                    trace: ['Szukaj w Archiwach scan ordinal is outside the supported positive integer range.'],
                );
            }

            if ($hintRaw !== null && trim($hintRaw) !== '') {
                $hintOrdinal = $this->positiveDecimal(trim($hintRaw));
                if ($hintOrdinal === null) {
                    return new OrdinalLocatorParseResult(
                        status: OrdinalLocatorParseResult::UNSUPPORTED,
                        reason: 'invalid_scan_number_hint',
                        trace: ['Explicit scanNumberRaw must be a positive decimal ordinal when supplied.'],
                    );
                }
                if ($hintOrdinal !== $fragmentOrdinal) {
                    return new OrdinalLocatorParseResult(
                        status: OrdinalLocatorParseResult::CONFLICT,
                        reason: 'conflicting_scan_ordinals',
                        trace: [
                            sprintf('Resource fragment requested scan ordinal %d.', $fragmentOrdinal),
                            sprintf('Explicit scanNumberRaw requested scan ordinal %d.', $hintOrdinal),
                            'Conflicting raw locator hints are preserved and resolution does not guess between them.',
                        ],
                    );
                }
            }

            return new OrdinalLocatorParseResult(
                status: OrdinalLocatorParseResult::VALID,
                ordinal: $fragmentOrdinal,
                matchedRaw: '#scan' . $fragmentOrdinal,
                trace: [sprintf('Parsed scan ordinal %d from the resource fragment.', $fragmentOrdinal)],
            );
        }

        if ($hintRaw === null || trim($hintRaw) === '') {
            return new OrdinalLocatorParseResult(
                status: OrdinalLocatorParseResult::MISSING,
                reason: 'missing_scan_ordinal',
                trace: ['No #scan<N> fragment or explicit scanNumberRaw ordinal was supplied.'],
            );
        }

        $trimmedHint = trim($hintRaw);
        $hintOrdinal = $this->positiveDecimal($trimmedHint);
        if ($hintOrdinal === null) {
            return new OrdinalLocatorParseResult(
                status: OrdinalLocatorParseResult::UNSUPPORTED,
                reason: 'invalid_scan_number_hint',
                trace: ['Explicit scanNumberRaw must be a positive decimal ordinal when supplied.'],
            );
        }

        return new OrdinalLocatorParseResult(
            status: OrdinalLocatorParseResult::VALID,
            ordinal: $hintOrdinal,
            matchedRaw: $hintRaw,
            trace: [sprintf('Parsed scan ordinal %d from scanNumberRaw.', $hintOrdinal)],
        );
    }

    private function positiveDecimal(string $raw): ?int
    {
        if (!preg_match('~^[1-9]\d*$~', $raw)) {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($value) ? $value : null;
    }
}
