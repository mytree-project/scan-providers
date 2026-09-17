<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

use MyTree\ScanProviders\Domain\AvailableScan;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ResolvedScan;
use MyTree\ScanProviders\Domain\ScanCatalog;
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;

final readonly class OrdinalScanResolver
{
    public const STRATEGY = 'scan_ordinal';

    public function __construct(private OrdinalLocatorParser $parser = new OrdinalLocatorParser())
    {
    }

    public function resolve(ResolveScanRequest $request, ScanCatalog $catalog): ScanResolution
    {
        $parsed = $this->parser->parse($request);
        if ($parsed->status === OrdinalLocatorParseResult::UNSUPPORTED) {
            return new ScanResolution(
                status: ScanResolutionStatus::Unsupported,
                providerKey: SzukajWArchiwachProvider::KEY,
                request: $request,
                strategy: self::STRATEGY,
                reason: $parsed->reason,
                trace: $parsed->trace,
            );
        }
        if (in_array($parsed->status, [OrdinalLocatorParseResult::MISSING, OrdinalLocatorParseResult::CONFLICT], true)) {
            return new ScanResolution(
                status: ScanResolutionStatus::Unresolved,
                providerKey: SzukajWArchiwachProvider::KEY,
                request: $request,
                strategy: self::STRATEGY,
                reason: $parsed->reason,
                trace: $parsed->trace,
            );
        }

        $ordinal = $parsed->ordinal;
        if ($ordinal === null) {
            throw new \LogicException('Valid Szukaj w Archiwach ordinal parse result must contain an ordinal.');
        }

        $matches = array_values(array_filter(
            $catalog->scans,
            static fn (AvailableScan $scan): bool => ($scan->metadata['scan_ordinal'] ?? null) === $ordinal,
        ));
        $trace = array_merge($parsed->trace, [
            sprintf('Catalog discovery returned %d scan(s).', count($catalog->scans)),
            sprintf('Requested scan ordinal %d matched %d catalog entr%s.', $ordinal, count($matches), count($matches) === 1 ? 'y' : 'ies'),
        ]);

        if ($matches === []) {
            return new ScanResolution(
                status: ScanResolutionStatus::Unresolved,
                providerKey: SzukajWArchiwachProvider::KEY,
                request: $request,
                strategy: self::STRATEGY,
                reason: 'scan_ordinal_not_found',
                trace: $trace,
            );
        }
        if (count($matches) > 1) {
            return new ScanResolution(
                status: ScanResolutionStatus::Ambiguous,
                providerKey: SzukajWArchiwachProvider::KEY,
                request: $request,
                candidates: $matches,
                strategy: self::STRATEGY,
                reason: 'multiple_scan_ordinal_matches',
                trace: $trace,
            );
        }

        $selected = $matches[0];
        $trace[] = 'Selected provider object/file locator ' . $selected->remoteId . '.';
        $resolved = new ResolvedScan(
            providerKey: SzukajWArchiwachProvider::KEY,
            resource: $request->resource,
            scan: $selected,
            strategy: self::STRATEGY,
            matchedHintRaw: $parsed->matchedRaw,
            catalogProvenance: $catalog->provenance,
        );

        return new ScanResolution(
            status: ScanResolutionStatus::Resolved,
            providerKey: SzukajWArchiwachProvider::KEY,
            request: $request,
            candidates: $matches,
            resolved: $resolved,
            strategy: self::STRATEGY,
            trace: $trace,
        );
    }
}
