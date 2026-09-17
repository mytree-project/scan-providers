<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use MyTree\ScanProviders\Domain\AvailableScan;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanCatalog;
use MyTree\ScanProviders\Domain\ScanLocatorHints;
use MyTree\ScanProviders\Domain\ScanProvenance;
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\OrdinalScanResolver;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\SzukajWArchiwachProvider;
use PHPUnit\Framework\TestCase;

final class OrdinalScanResolverTest extends TestCase
{
    private const UNIT = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/1833707';

    public function testItResolvesAcceptedScan42ExampleToExactObjectLocator(): void
    {
        $scans = [];
        for ($ordinal = 1; $ordinal <= 48; ++$ordinal) {
            $scans[] = $this->scan($ordinal, (string) (900000 + $ordinal));
        }
        $catalog = $this->catalog($scans);
        $request = new ResolveScanRequest(new ScanResourceReference(self::UNIT . '#scan42'));

        $resolution = (new OrdinalScanResolver())->resolve($request, $catalog);

        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertSame(OrdinalScanResolver::STRATEGY, $resolution->strategy);
        self::assertNotNull($resolution->resolved);
        self::assertSame('900042', $resolution->resolved->scan->remoteId);
        self::assertSame(42, $resolution->resolved->scan->metadata['scan_ordinal']);
        self::assertSame('#scan42', $resolution->resolved->matchedHintRaw);
        self::assertSame(self::UNIT . '#scan42', $resolution->request->resource->url);
        self::assertSame('1833707', $resolution->resolved->catalogProvenance->details['unit_id']);

        $serialized = $resolution->jsonSerialize();
        self::assertSame(ScanResolution::SCHEMA, $serialized['schema']);
        self::assertSame(self::UNIT . '#scan42', $serialized['request']->resource->url);
    }

    public function testItResolvesFirstAndLastOrdinalBoundaries(): void
    {
        $catalog = $this->catalog([
            $this->scan(1, '700001'),
            $this->scan(2, '700002'),
            $this->scan(3, '700003'),
        ]);
        $resolver = new OrdinalScanResolver();

        $first = $resolver->resolve(
            new ResolveScanRequest(new ScanResourceReference(self::UNIT . '#scan1')),
            $catalog,
        );
        $last = $resolver->resolve(
            new ResolveScanRequest(new ScanResourceReference(self::UNIT), new ScanLocatorHints(scanNumberRaw: '3')),
            $catalog,
        );

        self::assertSame(ScanResolutionStatus::Resolved, $first->status);
        self::assertSame('700001', $first->resolved?->scan->remoteId);
        self::assertSame(ScanResolutionStatus::Resolved, $last->status);
        self::assertSame('700003', $last->resolved?->scan->remoteId);
        self::assertSame('3', $last->resolved?->matchedHintRaw);
    }

    public function testItReturnsUnresolvedWhenRawOrdinalContradictsCurrentDiscovery(): void
    {
        $catalog = $this->catalog([
            $this->scan(1, '700001'),
            $this->scan(2, '700002'),
            $this->scan(3, '700003'),
        ]);
        $request = new ResolveScanRequest(new ScanResourceReference(self::UNIT . '#scan42'));

        $resolution = (new OrdinalScanResolver())->resolve($request, $catalog);

        self::assertSame(ScanResolutionStatus::Unresolved, $resolution->status);
        self::assertSame('scan_ordinal_not_found', $resolution->reason);
        self::assertSame(self::UNIT . '#scan42', $resolution->request->resource->url);
        self::assertStringContainsString('Catalog discovery returned 3 scan(s).', implode(' ', $resolution->trace));
    }

    public function testItReturnsAmbiguousForNonUniqueCatalogOrdinal(): void
    {
        $catalog = $this->catalog([
            $this->scan(42, '700042'),
            $this->scan(42, '800042'),
        ]);

        $resolution = (new OrdinalScanResolver())->resolve(
            new ResolveScanRequest(new ScanResourceReference(self::UNIT . '#scan42')),
            $catalog,
        );

        self::assertSame(ScanResolutionStatus::Ambiguous, $resolution->status);
        self::assertSame('multiple_scan_ordinal_matches', $resolution->reason);
        self::assertCount(2, $resolution->candidates);
        self::assertSame(['700042', '800042'], array_map(
            static fn (AvailableScan $scan): string => $scan->remoteId,
            $resolution->candidates,
        ));
    }

    /** @param list<AvailableScan> $scans */
    private function catalog(array $scans): ScanCatalog
    {
        $resource = new ScanResourceReference(self::UNIT);
        return new ScanCatalog(
            providerKey: SzukajWArchiwachProvider::KEY,
            resource: $resource,
            scans: $scans,
            provenance: new ScanProvenance(
                providerKey: SzukajWArchiwachProvider::KEY,
                providerVersion: SzukajWArchiwachProvider::VERSION,
                resourceUrl: self::UNIT,
                retrievedAt: '2026-09-17T12:00:00+00:00',
                responseSha256: str_repeat('a', 64),
                details: ['unit_id' => '1833707', 'scan_count' => count($scans)],
            ),
        );
    }

    private function scan(int $ordinal, string $objectId): AvailableScan
    {
        return new AvailableScan(
            providerKey: SzukajWArchiwachProvider::KEY,
            remoteId: $objectId,
            label: 'Scan ' . $ordinal,
            remoteFilename: '',
            viewerUrl: self::UNIT,
            metadata: [
                'unit_id' => '1833707',
                'scan_ordinal' => $ordinal,
                'object_id' => $objectId,
            ],
        );
    }
}
