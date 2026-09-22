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
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\ScanCapabilityUnavailableException;
use MyTree\ScanProviders\Exception\UnsupportedScanProviderException;
use MyTree\ScanProviders\Infrastructure\DefaultScanProviderRegistryFactory;
use MyTree\ScanProviders\Provider\GenealodzySkanoteka\GenealodzySkanotekaProvider;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\SzukajWArchiwachProvider;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;
use MyTree\ScanProviders\Tests\Support\FakeHttpClient;
use MyTree\ScanProviders\Tests\Support\FixedClock;
use MyTree\ScanProviders\Tests\Support\InMemoryScanAssetStorage;
use PHPUnit\Framework\TestCase;

final class DefaultRegistryEndToEndTest extends TestCase
{
    private const UNIT_URL = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990003';
    private const RESOURCE_URL = self::UNIT_URL . '#scan2';
    private const VIEWER_URL = self::UNIT_URL . '/obiekty/700002';
    private const PUBLIC_SCAN_VIEWER_URL = 'https://www.szukajwarchiwach.gov.pl/skan/-/skan/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const SKANOTEKA_URL = 'https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12';

    public function testDefaultStandaloneRegistryContainsBothProvidersAndRoutesCurrentResources(): void
    {
        $registry = DefaultScanProviderRegistryFactory::create(new FakeHttpClient());

        self::assertSame(
            [GenealodzySkanotekaProvider::KEY, SzukajWArchiwachProvider::KEY],
            array_map(static fn ($provider): string => $provider->key(), $registry->all()),
        );
        self::assertSame(
            SzukajWArchiwachProvider::KEY,
            $registry->forResource(new ScanResourceReference(self::RESOURCE_URL))->key(),
        );
        self::assertSame(
            GenealodzySkanotekaProvider::KEY,
            $registry->forResource(new ScanResourceReference(self::SKANOTEKA_URL))->key(),
        );
    }

    public function testDefaultRegistryDoesNotMechanicallyClaimLegacySzukajWArchiwachUrls(): void
    {
        $registry = DefaultScanProviderRegistryFactory::create(new FakeHttpClient());

        $this->expectException(UnsupportedScanProviderException::class);
        $registry->forResource(new ScanResourceReference(
            'https://szukajwarchiwach.pl/54/744/0/6.1/47/str/1/3/15/',
        ));
    }

    public function testRegistryDiscoverResolveAndDownloadFailurePreserveSzukajWArchiwachBoundary(): void
    {
        $http = new FakeHttpClient();
        $http->respond(self::UNIT_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            $this->fixture('multi-scan.html'),
            self::UNIT_URL,
        ));
        $http->respond(self::VIEWER_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html']],
            $this->fixture('object-viewer.html'),
            self::VIEWER_URL,
        ));
        $http->respond(self::PUBLIC_SCAN_VIEWER_URL, new HttpResponse(
            200,
            ['content-type' => ['text/html;charset=UTF-8']],
            '<!doctype html><html><head><title>Skan - Szukaj w Archiwach</title></head><body>viewer</body></html>',
            self::PUBLIC_SCAN_VIEWER_URL,
        ));

        $clock = new FixedClock(new DateTimeImmutable('2026-09-18T03:00:00+00:00'));
        $registry = new ScanProviderRegistry([
            new GenealodzySkanotekaProvider(http: $http, clock: $clock),
            new SzukajWArchiwachProvider(
                http: $http,
                clock: $clock,
                retryBackoffMilliseconds: 0,
                requestPacingMilliseconds: 0,
            ),
        ]);
        $resource = new ScanResourceReference(self::RESOURCE_URL);

        self::assertSame(SzukajWArchiwachProvider::KEY, $registry->forResource($resource)->key());

        $catalog = (new DiscoverScans($registry))->execute($resource);
        $catalogJson = $this->serialized($catalog);
        self::assertSame(ScanCatalog::SCHEMA, $catalogJson['schema']);
        self::assertSame(SzukajWArchiwachProvider::KEY, $catalog->providerKey);
        self::assertSame(self::RESOURCE_URL, $catalog->resource->url);
        self::assertSame('990003', $catalog->provenance->details['unit_id']);
        self::assertSame('827/6.1/63', $catalog->provenance->details['unit_metadata']['signature']);
        self::assertSame(
            'Status prawny zgodnie z opisem materiału',
            $catalog->provenance->details['unit_metadata']['access_rights'],
        );

        $resolution = (new ResolveScan($registry))->execute(new ResolveScanRequest($resource));
        $resolutionJson = $this->serialized($resolution);
        self::assertSame(ScanResolution::SCHEMA, $resolutionJson['schema']);
        self::assertSame(ScanResolutionStatus::Resolved, $resolution->status);
        self::assertNotNull($resolution->resolved);
        self::assertSame('700002', $resolution->resolved->scan->remoteId);
        self::assertSame(2, $resolution->resolved->scan->metadata['scan_ordinal']);
        self::assertSame(self::VIEWER_URL, $resolution->resolved->scan->viewerUrl);
        self::assertSame('#scan2', $resolution->resolved->matchedHintRaw);
        self::assertSame(self::RESOURCE_URL, $resolution->request->resource->url);
        self::assertSame(self::RESOURCE_URL, $resolutionJson['request']['resource_url']);
        self::assertSame(2, $resolutionJson['resolved']['scan']['metadata']['scan_ordinal']);
        self::assertSame('700002', $resolutionJson['resolved']['scan']['metadata']['object_id']);

        $storage = new InMemoryScanAssetStorage();
        try {
            (new DownloadScan($registry, $storage))->execute($resolution->resolved);
            self::fail('Expected browser-aware transport requirement for the public scan viewer.');
        } catch (ScanCapabilityUnavailableException $exception) {
            self::assertStringContainsString('HTML viewer', $exception->getMessage());
            self::assertStringContainsString('browser-aware transport', $exception->getMessage());
        }

        self::assertSame(
            [self::UNIT_URL, self::VIEWER_URL, self::PUBLIC_SCAN_VIEWER_URL],
            $http->requests,
        );
    }

    public function testProvidersCliListsBothStandaloneProvidersWithoutNetworkAccess(): void
    {
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/bin/mytree-scan')
            . ' providers';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode);
        self::assertSame([
            GenealodzySkanotekaProvider::KEY,
            SzukajWArchiwachProvider::KEY,
        ], $output);
    }

    /** @return array<string,mixed> */
    private function serialized(object $value): array
    {
        return json_decode(
            json_encode($value, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/szukajwarchiwach/' . $name);
    }
}
