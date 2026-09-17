<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Integration;

use DateTimeImmutable;
use MyTree\ScanProviders\Application\DiscoverScans;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanLocatorHints;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\SzukajWArchiwachProvider;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;
use MyTree\ScanProviders\Tests\Support\FakeHttpClient;
use MyTree\ScanProviders\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class SzukajWArchiwachProviderTest extends TestCase
{
    private const MULTI_UNIT = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990003';
    private const ZERO_UNIT = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990000';
    private const LARGE_UNIT = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990030';

    public function testItDiscoversUnitMetadataAndOrderedObjectLocatorsThroughApplicationBoundary(): void
    {
        $http = new FakeHttpClient();
        $html = $this->fixture('multi-scan.html');
        $http->respond(self::MULTI_UNIT, new HttpResponse(200, [], $html, self::MULTI_UNIT));
        $provider = $this->provider($http);

        $discover = new DiscoverScans(new ScanProviderRegistry([$provider]));
        $catalog = $discover->execute(new ScanResourceReference(self::MULTI_UNIT));

        self::assertCount(3, $catalog->scans);
        self::assertSame(['700001', '700002', '700003'], array_map(
            static fn ($scan): string => $scan->remoteId,
            $catalog->scans,
        ));
        self::assertSame([1, 2, 3], array_map(
            static fn ($scan): int => $scan->metadata['scan_ordinal'],
            $catalog->scans,
        ));
        self::assertSame('827/6.1/63', $catalog->provenance->details['unit_metadata']['signature']);
        self::assertSame('1863', $catalog->provenance->details['unit_metadata']['dates']);
        self::assertSame(
            'Archiwum Państwowe w Poznaniu. Oddział w Koninie',
            $catalog->provenance->details['unit_metadata']['archive'],
        );
        self::assertSame('Status prawny zgodnie z opisem materiału', $catalog->provenance->details['unit_metadata']['access_rights']);
        self::assertSame(3, $catalog->provenance->details['scan_count']);
        self::assertSame('990003', $catalog->provenance->details['unit_id']);
    }

    public function testItRepresentsZeroScanUnitAsAValidEmptyCatalog(): void
    {
        $http = new FakeHttpClient();
        $html = $this->fixture('zero-scan.html');
        $http->respond(self::ZERO_UNIT, new HttpResponse(200, [], $html, self::ZERO_UNIT));

        $catalog = $this->provider($http)->discoverScans(new ScanResourceReference(self::ZERO_UNIT));

        self::assertSame([], $catalog->scans);
        self::assertSame(0, $catalog->provenance->details['scan_count']);
        self::assertSame('54/744/0/6.1/47', $catalog->provenance->details['unit_metadata']['signature']);
    }

    public function testItEnumeratesEveryScanAcrossPaginationInStableOrder(): void
    {
        $http = new FakeHttpClient();
        $page2 = self::LARGE_UNIT . '?page=2';
        $page3 = self::LARGE_UNIT . '?page=3';
        $http->respond(self::LARGE_UNIT, new HttpResponse(200, [], $this->fixture('large-page-1.html'), self::LARGE_UNIT));
        $http->respond($page2, new HttpResponse(200, [], $this->fixture('large-page-2.html'), $page2));
        $http->respond($page3, new HttpResponse(200, [], $this->fixture('large-page-3.html'), $page3));

        $catalog = $this->provider($http)->discoverScans(new ScanResourceReference(self::LARGE_UNIT));

        self::assertCount(30, $catalog->scans);
        self::assertSame('800001', $catalog->scans[0]->remoteId);
        self::assertSame('800030', $catalog->scans[29]->remoteId);
        self::assertSame(30, $catalog->scans[29]->metadata['scan_ordinal']);
        self::assertSame(3, $catalog->provenance->details['page_count']);
        self::assertSame([self::LARGE_UNIT, $page2, $page3], $http->requests);
    }

    public function testItRejectsMalformedCatalogInsteadOfCachingPartialDiscovery(): void
    {
        $http = new FakeHttpClient();
        $html = $this->fixture('malformed.html');
        $http->respond(self::MULTI_UNIT, new HttpResponse(200, [], $html, self::MULTI_UNIT));
        $provider = $this->provider($http);

        $this->expectException(UnexpectedProviderResponseException::class);
        $provider->discoverScans(new ScanResourceReference(self::MULTI_UNIT));
    }

    public function testItRetriesTransientStatusAndCachesOnlySuccessfulCatalog(): void
    {
        $http = new FakeHttpClient();
        $html = $this->fixture('multi-scan.html');
        $http->respondSequence(self::MULTI_UNIT, [
            new HttpResponse(503, [], 'temporary', self::MULTI_UNIT),
            new HttpResponse(200, [], $html, self::MULTI_UNIT),
        ]);
        $provider = $this->provider($http, maxAttempts: 2);
        $resource = new ScanResourceReference(self::MULTI_UNIT);

        $first = $provider->discoverScans($resource);
        $second = $provider->discoverScans($resource);

        self::assertSame($first, $second);
        self::assertSame([self::MULTI_UNIT, self::MULTI_UNIT], $http->requests);
    }

    public function testResolutionRemainsExplicitlyUnsupportedInCatalogStep(): void
    {
        $provider = $this->provider(new FakeHttpClient());
        $resolution = $provider->resolve(new ResolveScanRequest(
            new ScanResourceReference(self::MULTI_UNIT . '#scan2'),
            new ScanLocatorHints(),
        ));

        self::assertSame(ScanResolutionStatus::Unsupported, $resolution->status);
        self::assertSame('scan_resolution_not_implemented', $resolution->reason);
    }

    private function provider(FakeHttpClient $http, int $maxAttempts = 3): SzukajWArchiwachProvider
    {
        return new SzukajWArchiwachProvider(
            http: $http,
            clock: new FixedClock(new DateTimeImmutable('2026-09-17T12:00:00+00:00')),
            maxAttempts: $maxAttempts,
            retryBackoffMilliseconds: 0,
            requestPacingMilliseconds: 0,
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/szukajwarchiwach/' . $name);
    }
}
