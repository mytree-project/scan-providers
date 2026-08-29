<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Integration;

use DateTimeImmutable;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanLocatorHints;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Provider\GenealodzySkanoteka\GenealodzySkanotekaProvider;
use MyTree\ScanProviders\Tests\Support\FakeHttpClient;
use MyTree\ScanProviders\Tests\Support\FixedClock;
use MyTree\ScanProviders\Tests\Support\InMemoryScanAssetStorage;
use PHPUnit\Framework\TestCase;

final class GenealodzySkanotekaProviderTest extends TestCase
{
    private const RESOURCE = 'https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12';
    private const VIEWER = 'https://metryki.genealodzy.pl/index.php?id=350&kt=12&op=pg&plik=12-21.jpg&sy=501';
    private const DOWNLOAD = 'https://metryki.genealodzy.pl/metryka.php?ar=10&zs=2596d&sy=501&kt=12&plik=12-21.jpg';

    public function testItDiscoversResolvesAndDownloadsWithProvenance(): void
    {
        $http = new FakeHttpClient();
        $catalogHtml = (string) file_get_contents(__DIR__ . '/../fixtures/genealodzy-skanoteka/catalog.html');
        $viewerHtml = (string) file_get_contents(__DIR__ . '/../fixtures/genealodzy-skanoteka/viewer.html');
        $jpeg = "\xFF\xD8\xFF\xE0fixture-jpeg";

        $http->respond(self::RESOURCE, new HttpResponse(200, ['content-type' => ['text/html']], $catalogHtml, self::RESOURCE));
        $http->respond(self::VIEWER, new HttpResponse(200, ['content-type' => ['text/html']], $viewerHtml, self::VIEWER));
        $http->respond(self::DOWNLOAD, new HttpResponse(200, ['content-type' => ['image/jpeg']], $jpeg, self::DOWNLOAD));

        $provider = new GenealodzySkanotekaProvider(
            http: $http,
            clock: new FixedClock(new DateTimeImmutable('2026-08-29T12:00:00+00:00')),
        );

        $catalog = $provider->discoverScans(new ScanResourceReference(self::RESOURCE));
        self::assertCount(4, $catalog->scans);
        self::assertSame(hash('sha256', $catalogHtml), $catalog->provenance->responseSha256);

        $resolution = $provider->resolve(new ResolveScanRequest(
            new ScanResourceReference(self::RESOURCE),
            new ScanLocatorHints(recordNumberRaw: '17', year: 1853, recordType: 'birth', parishRaw: 'Imbramowice'),
        ));
        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertNotNull($resolution->resolved);
        self::assertSame('12-21.jpg', $resolution->resolved->scan->remoteFilename);

        $storage = new InMemoryScanAssetStorage();
        $downloaded = $provider->download($resolution->resolved, $storage);
        self::assertSame('image/jpeg', $downloaded->mimeType);
        self::assertSame(hash('sha256', $jpeg), $downloaded->asset->sha256);
        self::assertSame(self::DOWNLOAD, $downloaded->downloadUrl);
        self::assertSame('2026-08-29T12:00:00+00:00', $downloaded->retrievedAt);
        self::assertSame($jpeg, $storage->files['12-21.jpg']);
    }

    public function testItReturnsUnresolvedInsteadOfGuessingOpaqueFilenames(): void
    {
        $http = new FakeHttpClient();
        $catalogHtml = (string) file_get_contents(__DIR__ . '/../fixtures/genealodzy-skanoteka/catalog.html');
        $http->respond(self::RESOURCE, new HttpResponse(200, [], $catalogHtml, self::RESOURCE));

        $provider = new GenealodzySkanotekaProvider(
            http: $http,
            clock: new FixedClock(new DateTimeImmutable('2026-08-29T12:00:00+00:00')),
        );
        $resolution = $provider->resolve(new ResolveScanRequest(
            new ScanResourceReference(self::RESOURCE),
            new ScanLocatorHints(recordNumberRaw: '99'),
        ));

        self::assertSame(ScanResolutionStatus::Unresolved, $resolution->status);
        self::assertSame('no_matching_act_number_locator', $resolution->reason);
    }
}
