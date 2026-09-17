<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Integration;

use DateTimeImmutable;
use MyTree\ScanProviders\Application\DiscoverScans;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanLocatorHints;
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\OrdinalScanResolver;
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

    public function testItResolvesDeepLinkOrdinalAgainstDiscoveredCatalog(): void
    {
        $http = new FakeHttpClient();
        $html = $this->fixture('multi-scan.html');
        $http->respond(self::MULTI_UNIT, new HttpResponse(200, [], $html, self::MULTI_UNIT));
        $provider = $this->provider($http);
        $request = new ResolveScanRequest(
            new ScanResourceReference(self::MULTI_UNIT . '#scan2'),
            new ScanLocatorHints(
                scanNumberRaw: '2',
                archiveSignatureRaw: '827/6.1/63',
            ),
        );

        $resolution = $provider->resolve($request);

        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertSame(OrdinalScanResolver::STRATEGY, $resolution->strategy);
        self::assertNotNull($resolution->resolved);
        self::assertSame('700002', $resolution->resolved->scan->remoteId);
        self::assertSame(2, $resolution->resolved->scan->metadata['scan_ordinal']);
        self::assertSame('#scan2', $resolution->resolved->matchedHintRaw);
        self::assertSame(self::MULTI_UNIT . '#scan2', $resolution->request->resource->url);
        self::assertSame('827/6.1/63', $resolution->request->hints->archiveSignatureRaw);
        self::assertSame('990003', $resolution->resolved->catalogProvenance->details['unit_id']);
        self::assertSame([self::MULTI_UNIT], $http->requests);

        $serialized = $resolution->jsonSerialize();
        self::assertSame(ScanResolution::SCHEMA, $serialized['schema']);
    }

    public function testItReturnsSafeStatusForOutOfRangeMalformedMissingAndConflictingOrdinals(): void
    {
        $html = $this->fixture('multi-scan.html');

        $http = new FakeHttpClient();
        $http->respond(self::MULTI_UNIT, new HttpResponse(200, [], $html, self::MULTI_UNIT));
        $outOfRange = $this->provider($http)->resolve(new ResolveScanRequest(
            new ScanResourceReference(self::MULTI_UNIT . '#scan42'),
        ));
        self::assertSame(ScanResolutionStatus::Unresolved, $outOfRange->status);
        self::assertSame('scan_ordinal_not_found', $outOfRange->reason);
        self::assertSame(self::MULTI_UNIT . '#scan42', $outOfRange->request->resource->url);

        $http = new FakeHttpClient();
        $http->respond(self::MULTI_UNIT, new HttpResponse(200, [], $html, self::MULTI_UNIT));
        $malformed = $this->provider($http)->resolve(new ResolveScanRequest(
            new ScanResourceReference(self::MULTI_UNIT . '#scan0'),
        ));
        self::assertSame(ScanResolutionStatus::Unsupported, $malformed->status);
        self::assertSame('unsupported_scan_fragment', $malformed->reason);

        $http = new FakeHttpClient();
        $http->respond(self::MULTI_UNIT, new HttpResponse(200, [], $html, self::MULTI_UNIT));
        $missing = $this->provider($http)->resolve(new ResolveScanRequest(
            new ScanResourceReference(self::MULTI_UNIT),
        ));
        self::assertSame(ScanResolutionStatus::Unresolved, $missing->status);
        self::assertSame('missing_scan_ordinal', $missing->reason);

        $http = new FakeHttpClient();
        $http->respond(self::MULTI_UNIT, new HttpResponse(200, [], $html, self::MULTI_UNIT));
        $conflict = $this->provider($http)->resolve(new ResolveScanRequest(
            new ScanResourceReference(self::MULTI_UNIT . '#scan2'),
            new ScanLocatorHints(scanNumberRaw: '3'),
        ));
        self::assertSame(ScanResolutionStatus::Unresolved, $conflict->status);
        self::assertSame('conflicting_scan_ordinals', $conflict->reason);
        self::assertSame('3', $conflict->request->hints->scanNumberRaw);
        self::assertSame(self::MULTI_UNIT . '#scan2', $conflict->request->resource->url);
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
