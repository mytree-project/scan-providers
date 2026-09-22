<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Integration;

use DateTimeImmutable;
use MyTree\ScanProviders\Application\DownloadScan;
use MyTree\ScanProviders\Domain\DownloadedScan;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\ScanCapabilityUnavailableException;
use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\SzukajWArchiwachProvider;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;
use MyTree\ScanProviders\Tests\Support\FakeBrowserSessionClient;
use MyTree\ScanProviders\Tests\Support\FakeHttpClient;
use MyTree\ScanProviders\Tests\Support\FixedClock;
use MyTree\ScanProviders\Tests\Support\InMemoryScanAssetStorage;
use PHPUnit\Framework\TestCase;

final class SzukajWArchiwachDownloadTest extends TestCase
{
    private const UNIT_URL = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990003';
    private const RESOURCE_URL = self::UNIT_URL . '#scan2';
    private const VIEWER_URL = self::UNIT_URL . '/obiekty/700002';
    private const PUBLIC_SCAN_VIEWER_URL = 'https://www.szukajwarchiwach.gov.pl/skan/-/skan/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const PHOTO_ASSET_URL = 'https://photos.szukajwarchiwach.gov.pl/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef_max';
    private const EXPECTED_FILENAME = 'szukajwarchiwach-990003-object-700002.jpg';
    private const EXPECTED_SHA256 = '8b7153f04cb34a8bf9065c5769ab6bcc7de05bb423a596c343fa139d48b3c6e2';

    public function testItDownloadsResolvedObjectThroughApplicationBoundary(): void
    {
        $http = new FakeHttpClient();
        $this->prepareSuccessfulFlow($http);
        $provider = $this->provider($http);
        $resolution = $provider->resolve(new ResolveScanRequest(new ScanResourceReference(self::RESOURCE_URL)));

        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertNotNull($resolution->resolved);
        self::assertSame('700002', $resolution->resolved->scan->remoteId);
        self::assertSame(self::VIEWER_URL, $resolution->resolved->scan->viewerUrl);

        $storage = new InMemoryScanAssetStorage();
        $download = new DownloadScan(new ScanProviderRegistry([$provider]), $storage);
        $result = $download->execute($resolution->resolved);

        self::assertSame(DownloadedScan::SCHEMA, $result->jsonSerialize()['schema']);
        self::assertSame(SzukajWArchiwachProvider::KEY, $result->providerKey);
        self::assertSame('image/jpeg', $result->mimeType);
        self::assertSame(self::RESOURCE_URL, $result->resourceUrl);
        self::assertSame(self::VIEWER_URL, $result->viewerUrl);
        self::assertSame(self::PHOTO_ASSET_URL, $result->downloadUrl);
        self::assertSame('2026-09-17T12:00:00+00:00', $result->retrievedAt);
        self::assertSame(20, $result->asset->size);
        self::assertSame(self::EXPECTED_SHA256, $result->asset->sha256);
        self::assertSame(self::EXPECTED_FILENAME, $result->asset->filename);
        self::assertArrayHasKey(self::EXPECTED_FILENAME, $storage->files);
        self::assertSame(
            'Status prawny zgodnie z opisem materiału',
            $result->catalogProvenance->details['unit_metadata']['access_rights'],
        );
        self::assertSame('990003', $result->catalogProvenance->details['unit_id']);
        self::assertSame(
            [self::UNIT_URL, self::VIEWER_URL, self::PUBLIC_SCAN_VIEWER_URL],
            $http->requests,
        );
    }

    public function testItFailsExplicitlyWhenViewerDoesNotExposePublicScanLink(): void
    {
        $http = new FakeHttpClient();
        $http->respond(self::UNIT_URL, new HttpResponse(200, [], $this->fixture('multi-scan.html'), self::UNIT_URL));
        $http->respond(self::VIEWER_URL, new HttpResponse(200, [], $this->fixture('object-viewer-missing.html'), self::VIEWER_URL));
        $provider = $this->provider($http);
        $resolution = $provider->resolve(new ResolveScanRequest(new ScanResourceReference(self::RESOURCE_URL)));
        self::assertNotNull($resolution->resolved);

        $download = new DownloadScan(
            new ScanProviderRegistry([$provider]),
            new InMemoryScanAssetStorage(),
        );

        try {
            $download->execute($resolution->resolved);
            self::fail('Expected missing public scan link to fail explicitly.');
        } catch (UnexpectedProviderResponseException $exception) {
            self::assertStringContainsString('public /skan/-/skan/ link', $exception->getMessage());
        }

        self::assertSame([self::UNIT_URL, self::VIEWER_URL], $http->requests);
    }

    public function testItCapturesImageDirectlyFromBrowserObjectViewerWhenNoPublicScanLinkIsExposed(): void
    {
        $http = new FakeHttpClient();
        $browser = new FakeBrowserSessionClient();

        $http->respond(self::UNIT_URL, new HttpResponse(
            200,
            [],
            $this->fixture('multi-scan.html'),
            self::UNIT_URL,
        ));
        $http->respond(self::VIEWER_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            $this->fixture('object-viewer-missing.html'),
            self::VIEWER_URL,
        ));

        $browser->respondPage(self::VIEWER_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            $this->fixture('object-viewer-missing.html'),
            self::VIEWER_URL,
        ));
        $browser->respondScanImage(self::VIEWER_URL, $this->imageResponse());

        $provider = $this->provider($http, browserSessionClient: $browser);
        $resolution = $provider->resolve(new ResolveScanRequest(new ScanResourceReference(self::RESOURCE_URL)));
        self::assertNotNull($resolution->resolved);

        $result = (new DownloadScan(
            new ScanProviderRegistry([$provider]),
            new InMemoryScanAssetStorage(),
        ))->execute($resolution->resolved);

        self::assertSame(self::VIEWER_URL, $result->viewerUrl);
        self::assertSame(self::PHOTO_ASSET_URL, $result->downloadUrl);
        self::assertSame([
            'page:' . self::VIEWER_URL,
            'scan-image:' . self::VIEWER_URL,
        ], $browser->requests);
        self::assertSame([
            self::UNIT_URL,
            self::VIEWER_URL,
        ], $http->requests);
    }

    public function testItRetriesTransientViewerAndAssetFailuresWithoutRealSleeps(): void
    {
        $http = new FakeHttpClient();
        $http->respond(self::UNIT_URL, new HttpResponse(200, [], $this->fixture('multi-scan.html'), self::UNIT_URL));
        $http->respondSequence(self::VIEWER_URL, [
            new HttpResponse(503, [], 'temporary viewer failure', self::VIEWER_URL),
            new HttpResponse(200, [], $this->fixture('object-viewer.html'), self::VIEWER_URL),
        ]);
        $http->respondSequence(self::PUBLIC_SCAN_VIEWER_URL, [
            new HttpResponse(429, [], 'slow down', self::PUBLIC_SCAN_VIEWER_URL),
            $this->imageResponse(),
        ]);
        $provider = $this->provider($http, maxAttempts: 2);
        $resolution = $provider->resolve(new ResolveScanRequest(new ScanResourceReference(self::RESOURCE_URL)));
        self::assertNotNull($resolution->resolved);

        $result = (new DownloadScan(
            new ScanProviderRegistry([$provider]),
            new InMemoryScanAssetStorage(),
        ))->execute($resolution->resolved);

        self::assertSame(self::EXPECTED_SHA256, $result->asset->sha256);
        self::assertSame([
            self::UNIT_URL,
            self::VIEWER_URL,
            self::VIEWER_URL,
            self::PUBLIC_SCAN_VIEWER_URL,
            self::PUBLIC_SCAN_VIEWER_URL,
        ], $http->requests);
    }

    public function testItRejectsNonImageDownloadResponse(): void
    {
        $http = new FakeHttpClient();
        $http->respond(self::UNIT_URL, new HttpResponse(200, [], $this->fixture('multi-scan.html'), self::UNIT_URL));
        $http->respond(self::VIEWER_URL, new HttpResponse(200, [], $this->fixture('object-viewer.html'), self::VIEWER_URL));
        $http->respond(self::PUBLIC_SCAN_VIEWER_URL, new HttpResponse(200, ['content-type' => ['text/html']], '<html>error</html>', self::PUBLIC_SCAN_VIEWER_URL));
        $provider = $this->provider($http);
        $resolution = $provider->resolve(new ResolveScanRequest(new ScanResourceReference(self::RESOURCE_URL)));
        self::assertNotNull($resolution->resolved);

        $this->expectException(ScanCapabilityUnavailableException::class);
        $this->expectExceptionMessage('browser-aware transport');
        (new DownloadScan(
            new ScanProviderRegistry([$provider]),
            new InMemoryScanAssetStorage(),
        ))->execute($resolution->resolved);
    }

    private function prepareSuccessfulFlow(FakeHttpClient $http): void
    {
        $http->respond(self::UNIT_URL, new HttpResponse(200, [], $this->fixture('multi-scan.html'), self::UNIT_URL));
        $http->respond(self::VIEWER_URL, new HttpResponse(200, [], $this->fixture('object-viewer.html'), self::VIEWER_URL));
        $http->respond(self::PUBLIC_SCAN_VIEWER_URL, $this->imageResponse());
    }

    private function imageResponse(): HttpResponse
    {
        return new HttpResponse(
            200,
            ['content-type' => ['image/jpeg']],
            "\xFF\xD8\xFF\xE0MYTREE-SZWA-TEST",
            self::PHOTO_ASSET_URL,
        );
    }

    private function provider(
        FakeHttpClient $http,
        int $maxAttempts = 3,
        ?FakeBrowserSessionClient $browserSessionClient = null,
    ): SzukajWArchiwachProvider {
        return new SzukajWArchiwachProvider(
            http: $http,
            browserSessionClient: $browserSessionClient,
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
