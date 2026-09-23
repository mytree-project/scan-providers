<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Integration;

use DateTimeImmutable;
use MyTree\ScanProviders\Application\DiscoverScans;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\SzukajWArchiwachProvider;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;
use MyTree\ScanProviders\Tests\Support\FakeBrowserSessionClient;
use MyTree\ScanProviders\Tests\Support\FakeHttpClient;
use MyTree\ScanProviders\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;

final class SzukajWArchiwachForcedBrowserPageTest extends TestCase
{
    private const UNIT_URL = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990004';

    public function testItCanForceCatalogDiscoveryThroughBrowserWithoutNativeHttp(): void
    {
        $http = new FakeHttpClient();
        $browser = new FakeBrowserSessionClient();
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

        $provider = new SzukajWArchiwachProvider(
            http: $http,
            browserSessionClient: $browser,
            preferBrowserPages: true,
            clock: new FixedClock(new DateTimeImmutable('2026-09-23T05:30:00+00:00')),
            retryBackoffMilliseconds: 0,
            requestPacingMilliseconds: 0,
        );

        $catalog = (new DiscoverScans(new ScanProviderRegistry([$provider])))->execute(
            new ScanResourceReference(self::UNIT_URL),
        );

        self::assertCount(3, $catalog->scans);
        self::assertSame([], $http->requests);
        self::assertSame(['page:' . self::UNIT_URL], $browser->requests);
    }
}
