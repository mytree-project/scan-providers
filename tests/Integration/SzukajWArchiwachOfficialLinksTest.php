<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Integration;

use DateTimeImmutable;
use MyTree\ScanProviders\Application\DiscoverScans;
use MyTree\ScanProviders\Application\DownloadScan;
use MyTree\ScanProviders\Application\ResolveScan;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanCatalog;
use MyTree\ScanProviders\Domain\ScanLocatorHints;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\ScanCapabilityUnavailableException;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\SzukajWArchiwachProvider;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;
use MyTree\ScanProviders\Tests\Support\FakeBrowserSessionClient;
use MyTree\ScanProviders\Tests\Support\FakeHttpClient;
use MyTree\ScanProviders\Tests\Support\FixedClock;
use MyTree\ScanProviders\Tests\Support\InMemoryScanAssetStorage;
use PHPUnit\Framework\TestCase;

final class SzukajWArchiwachOfficialLinksTest extends TestCase
{
    private const UNIT_URL = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990004';
    private const PAGE_TWO = self::UNIT_URL
        . '?_Jednostka_delta=20&_Jednostka_resetCur=false&_Jednostka_cur=2&_Jednostka_id_jednostki=990004';
    private const PUBLIC_SCAN_VIEWER_URL = 'https://www.szukajwarchiwach.gov.pl/skan/-/skan/'
        . '3628fd030c0f3c8b8e785a3724235e654775f21fd483fcc668e0af77a69359a7';

    public function testItResolvesOfficialPublicScanViewerWithoutCatalogDiscoveryAndRequiresBrowserTransportForImage(): void
    {
        $http = new FakeHttpClient();
        $provider = $this->provider($http);
        $registry = new ScanProviderRegistry([$provider]);
        $resource = new ScanResourceReference(self::PUBLIC_SCAN_VIEWER_URL);

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
        self::assertSame(SzukajWArchiwachProvider::PUBLIC_SCAN_VIEWER_STRATEGY, $resolution->strategy);
        self::assertNotNull($resolution->resolved);
        self::assertSame(self::PUBLIC_SCAN_VIEWER_URL, $resolution->resolved->scan->viewerUrl);
        self::assertSame(self::PUBLIC_SCAN_VIEWER_URL, $resolution->resolved->matchedHintRaw);
        self::assertSame([], $http->requests);

        $viewerHtml = '<!doctype html><html><head><title>Skan - Szukaj w Archiwach</title></head>'
            . '<body>' . str_repeat('viewer-content-', 100) . '</body></html>';
        $http->respond(self::PUBLIC_SCAN_VIEWER_URL, new HttpResponse(
            200,
            [
                'content-type' => ['text/html;charset=UTF-8'],
                'x-iinfo' => ['14-39368798-0 0NNN'],
            ],
            $viewerHtml,
            self::PUBLIC_SCAN_VIEWER_URL,
        ));

        $storage = new InMemoryScanAssetStorage();
        try {
            (new DownloadScan($registry, $storage))->execute($resolution->resolved);
            self::fail('Expected browser-aware transport requirement for an HTML scan viewer.');
        } catch (ScanCapabilityUnavailableException $exception) {
            self::assertStringContainsString('HTML viewer', $exception->getMessage());
            self::assertStringContainsString('browser-aware transport', $exception->getMessage());
        }

        self::assertSame([self::PUBLIC_SCAN_VIEWER_URL], $http->requests);
    }

    public function testItUsesBrowserSessionToDownloadImageFromPublicScanViewer(): void
    {
        $http = new FakeHttpClient();
        $browser = new FakeBrowserSessionClient();
        $provider = $this->provider($http, $browser);
        $registry = new ScanProviderRegistry([$provider]);
        $resource = new ScanResourceReference(self::PUBLIC_SCAN_VIEWER_URL);

        $resolution = (new ResolveScan($registry))->execute(new ResolveScanRequest($resource));
        self::assertNotNull($resolution->resolved);

        $viewerHtml = '<!doctype html><html><head><title>Skan - Szukaj w Archiwach</title></head>'
            . '<body>viewer</body></html>';
        $http->respond(self::PUBLIC_SCAN_VIEWER_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html;charset=UTF-8']],
            $viewerHtml,
            self::PUBLIC_SCAN_VIEWER_URL,
        ));

        $photoUrl = 'https://photos.szukajwarchiwach.gov.pl/sample_max';
        $jpeg = "\xFF\xD8\xFF\xE0BROWSER-SZWA";
        $browser->respondScanImage(self::PUBLIC_SCAN_VIEWER_URL, new HttpResponse(
            200,
            ['content-type' => ['image/jpeg']],
            $jpeg,
            $photoUrl,
        ));

        $downloaded = (new DownloadScan(
            $registry,
            new InMemoryScanAssetStorage(),
        ))->execute($resolution->resolved);

        self::assertSame('image/jpeg', $downloaded->mimeType);
        self::assertSame(self::PUBLIC_SCAN_VIEWER_URL, $downloaded->viewerUrl);
        self::assertSame($photoUrl, $downloaded->downloadUrl);
        self::assertSame(hash('sha256', $jpeg), $downloaded->asset->sha256);
        self::assertSame(['scan-image:' . self::PUBLIC_SCAN_VIEWER_URL], $browser->requests);
    }

    public function testItFallsBackToBrowserSessionWhenNativeCatalogRequestIsSoftBlocked(): void
    {
        $http = new FakeHttpClient();
        $browser = new FakeBrowserSessionClient();
        $body = '<html><body>Request unsuccessful. Incapsula incident ID: 123456789</body></html>';
        $http->respond(self::UNIT_URL, new HttpResponse(
            200,
            [
                'content-type' => ['text/html'],
                'x-iinfo' => ['14-39368798-0 0NNN'],
                'set-cookie' => ['incap_ses_878_3269802=redacted; path=/'],
            ],
            $body,
            self::UNIT_URL,
        ));
        $browser->respondPage(self::UNIT_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            '<!doctype html><html><body><h3>Skany (3)</h3>'
                . '<a data-plikid="700001">Skan 1</a>'
                . '<a data-plikid="700002">Skan 2</a>'
                . '<a data-plikid="700003">Skan 3</a>'
                . '</body></html>',
            self::UNIT_URL,
        ));

        $registry = new ScanProviderRegistry([$this->provider($http, $browser)]);
        $resolution = (new ResolveScan($registry))->execute(new ResolveScanRequest(
            new ScanResourceReference(self::UNIT_URL),
            new ScanLocatorHints(scanNumberRaw: '2'),
        ));

        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertNotNull($resolution->resolved);
        self::assertSame('700002', $resolution->resolved->scan->remoteId);
        self::assertSame(['page:' . self::UNIT_URL], $browser->requests);
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

    public function testItReportsImpervaSoftBlockInsteadOfTreatingItAsProviderHtml(): void
    {
        $http = new FakeHttpClient();
        $body = '<html><body>Request unsuccessful. Incapsula incident ID: 123456789</body></html>';
        $http->respond(self::UNIT_URL, new HttpResponse(
            200,
            [
                'content-type' => ['text/html'],
                'content-length' => [(string) strlen($body)],
                'x-iinfo' => ['14-39368798-0 0NNN'],
                'set-cookie' => [
                    'visid_incap_3269802=redacted; path=/; Domain=.szukajwarchiwach.gov.pl',
                    'incap_ses_878_3269802=redacted; path=/; Domain=.szukajwarchiwach.gov.pl',
                ],
            ],
            $body,
            self::UNIT_URL,
        ));

        $this->expectExceptionMessage('Imperva/Incapsula anti-bot protection');
        $this->expectExceptionMessage('browser-established session');

        $this->provider($http)->discoverScans(new ScanResourceReference(self::UNIT_URL));
    }

    public function testUnrecognizedCatalogReportsSafeStructuralDiagnostics(): void
    {
        $http = new FakeHttpClient();
        $catalogUrl = self::UNIT_URL
            . '?_Jednostka_delta=200&_Jednostka_resetCur=false&_Jednostka_cur=1'
            . '&_Jednostka_id_jednostki=990004';

        $http->respond(self::UNIT_URL, new HttpResponse(
            200,
            [],
            '<html><body>shell</body></html>',
            self::UNIT_URL,
        ));
        $http->respond($catalogUrl, new HttpResponse(
            200,
            [],
            '<html><body>100 Wpisy <input name="skan_-id-pliku"></body></html>',
            $catalogUrl,
        ));

        $this->expectExceptionMessage('skan_-id-pliku=yes');
        $this->expectExceptionMessage('Wpisy=yes');

        $this->provider($http)->discoverScans(new ScanResourceReference(self::UNIT_URL));
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

    private function provider(
        FakeHttpClient $http,
        ?FakeBrowserSessionClient $browserSessionClient = null,
    ): SzukajWArchiwachProvider {
        return new SzukajWArchiwachProvider(
            http: $http,
            browserSessionClient: $browserSessionClient,
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
