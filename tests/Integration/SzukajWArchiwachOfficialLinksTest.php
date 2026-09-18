<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Integration;

use DateTimeImmutable;
use MyTree\ScanProviders\Application\DiscoverScans;
use MyTree\ScanProviders\Application\DownloadScan;
use MyTree\ScanProviders\Application\ResolveScan;
use MyTree\ScanProviders\Domain\DownloadedScan;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanCatalog;
use MyTree\ScanProviders\Domain\ScanLocatorHints;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\SzukajWArchiwachProvider;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;
use MyTree\ScanProviders\Tests\Support\FakeHttpClient;
use MyTree\ScanProviders\Tests\Support\FixedClock;
use MyTree\ScanProviders\Tests\Support\InMemoryScanAssetStorage;
use PHPUnit\Framework\TestCase;

final class SzukajWArchiwachOfficialLinksTest extends TestCase
{
    private const UNIT_URL = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990004';
    private const PAGE_TWO = self::UNIT_URL
        . '?_Jednostka_delta=20&_Jednostka_resetCur=false&_Jednostka_cur=2&_Jednostka_id_jednostki=990004';
    private const PUBLIC_SCAN_URL = 'https://www.szukajwarchiwach.gov.pl/skan/-/skan/'
        . '3628fd030c0f3c8b8e785a3724235e654775f21fd483fcc668e0af77a69359a7';

    public function testItResolvesOfficialDirectPublicScanLinkWithoutCatalogDiscovery(): void
    {
        $http = new FakeHttpClient();
        $provider = $this->provider($http);
        $registry = new ScanProviderRegistry([$provider]);
        $resource = new ScanResourceReference(self::PUBLIC_SCAN_URL);

        self::assertSame(SzukajWArchiwachProvider::KEY, $registry->forResource($resource)->key());

        $catalog = (new DiscoverScans($registry))->execute($resource);
        self::assertSame(ScanCatalog::SCHEMA, $catalog->jsonSerialize()['schema']);
        self::assertCount(1, $catalog->scans);
        self::assertSame(
            '3628fd030c0f3c8b8e785a3724235e654775f21fd483fcc668e0af77a69359a7',
            $catalog->scans[0]->remoteId,
        );
        self::assertSame([], $http->requests);

        $resolution = (new ResolveScan($registry))->execute(new ResolveScanRequest($resource));
        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertSame(SzukajWArchiwachProvider::DIRECT_PUBLIC_SCAN_STRATEGY, $resolution->strategy);
        self::assertNotNull($resolution->resolved);
        self::assertSame(self::PUBLIC_SCAN_URL, $resolution->resolved->scan->viewerUrl);
        self::assertSame(self::PUBLIC_SCAN_URL, $resolution->resolved->matchedHintRaw);
        self::assertSame([], $http->requests);

        $jpeg = "\xFF\xD8\xFF\xE0DIRECT-SZWA";
        $http->respond(self::PUBLIC_SCAN_URL, new HttpResponse(
            200,
            ['content-type' => ['image/jpeg']],
            $jpeg,
            self::PUBLIC_SCAN_URL,
        ));

        $storage = new InMemoryScanAssetStorage();
        $downloaded = (new DownloadScan($registry, $storage))->execute($resolution->resolved);

        self::assertSame(DownloadedScan::SCHEMA, $downloaded->jsonSerialize()['schema']);
        self::assertSame(self::PUBLIC_SCAN_URL, $downloaded->resourceUrl);
        self::assertSame(self::PUBLIC_SCAN_URL, $downloaded->viewerUrl);
        self::assertSame(self::PUBLIC_SCAN_URL, $downloaded->downloadUrl);
        self::assertSame(hash('sha256', $jpeg), $downloaded->asset->sha256);
        self::assertSame([self::PUBLIC_SCAN_URL], $http->requests);
    }

    public function testItFallsBackFromUnitShellToOfficialCatalogPaginationUrl(): void
    {
        $http = new FakeHttpClient();
        $catalogUrl = self::UNIT_URL
            . '?_Jednostka_delta=200&_Jednostka_resetCur=false&_Jednostka_cur=1'
            . '&_Jednostka_id_jednostki=990004';

        $http->respond(self::UNIT_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            '<!doctype html><html lang="pl"><body><main>Unit shell</main></body></html>',
            self::UNIT_URL,
        ));
        $http->respond($catalogUrl, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            <<<'HTML'
<!doctype html>
<html lang="pl"><body>
<h3>Skany (3)</h3>
<a data-plikid="700001">Skan 1</a>
<a data-plikid="700002">Skan 2</a>
<a data-plikid="700003">Skan 3</a>
</body></html>
HTML,
            $catalogUrl,
        ));

        $registry = new ScanProviderRegistry([$this->provider($http)]);
        $resolution = (new ResolveScan($registry))->execute(new ResolveScanRequest(
            new ScanResourceReference(self::UNIT_URL),
            new ScanLocatorHints(scanNumberRaw: '3'),
        ));

        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertNotNull($resolution->resolved);
        self::assertSame('700003', $resolution->resolved->scan->remoteId);
        self::assertTrue($resolution->resolved->catalogProvenance->details['bootstrap_fallback_used']);
        self::assertSame($catalogUrl, $resolution->resolved->catalogProvenance->details['catalog_start_url']);
        self::assertNotNull($resolution->resolved->catalogProvenance->details['bootstrap_response_sha256']);
        self::assertSame([self::UNIT_URL, $catalogUrl], $http->requests);
    }

    public function testUnitTitleIsOptionalForDeterministicOrdinalResolution(): void
    {
        $http = new FakeHttpClient();
        $html = <<<'HTML'
<!doctype html>
<html lang="pl"><body>
<h3>Skany (3)</h3>
<a data-plikid="700001">Skan 1</a>
<a data-plikid="700002">Skan 2</a>
<a data-plikid="700003">Skan 3</a>
</body></html>
HTML;
        $http->respond(self::UNIT_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            $html,
            self::UNIT_URL,
        ));

        $registry = new ScanProviderRegistry([$this->provider($http)]);
        $resolution = (new ResolveScan($registry))->execute(new ResolveScanRequest(
            new ScanResourceReference(self::UNIT_URL),
            new ScanLocatorHints(scanNumberRaw: '2'),
        ));

        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertNotNull($resolution->resolved);
        self::assertSame('700002', $resolution->resolved->scan->remoteId);
        self::assertArrayNotHasKey(
            'title',
            $resolution->resolved->catalogProvenance->details['unit_metadata'],
        );
    }

    public function testItEnumeratesOfficialLiferayPaginationWhenDeclaredScanCountIsAbsent(): void
    {
        $http = new FakeHttpClient();
        $http->respond(self::UNIT_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            $this->fixture('liferay-page-1-no-count.html'),
            self::UNIT_URL,
        ));
        $http->respond(self::PAGE_TWO, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            $this->fixture('liferay-page-2-no-count.html'),
            self::PAGE_TWO,
        ));

        $registry = new ScanProviderRegistry([$this->provider($http)]);
        $request = new ResolveScanRequest(
            new ScanResourceReference(self::UNIT_URL),
            new ScanLocatorHints(scanNumberRaw: '3'),
        );

        $resolution = (new ResolveScan($registry))->execute($request);

        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertNotNull($resolution->resolved);
        self::assertSame('700003', $resolution->resolved->scan->remoteId);
        self::assertSame(3, $resolution->resolved->scan->metadata['scan_ordinal']);
        self::assertSame(3, $resolution->resolved->catalogProvenance->details['scan_count']);
        self::assertNull($resolution->resolved->catalogProvenance->details['declared_scan_count']);
        self::assertSame(
            'enumerated_catalog',
            $resolution->resolved->catalogProvenance->details['scan_count_source'],
        );
        self::assertSame([self::UNIT_URL, self::PAGE_TWO], $http->requests);
    }

    private function provider(FakeHttpClient $http): SzukajWArchiwachProvider
    {
        return new SzukajWArchiwachProvider(
            http: $http,
            clock: new FixedClock(new DateTimeImmutable('2026-09-18T06:00:00+00:00')),
            retryBackoffMilliseconds: 0,
            requestPacingMilliseconds: 0,
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/szukajwarchiwach/' . $name);
    }
}
