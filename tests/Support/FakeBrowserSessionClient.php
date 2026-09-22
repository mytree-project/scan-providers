<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Support;

use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\BrowserSessionClientInterface;
use RuntimeException;

final class FakeBrowserSessionClient implements BrowserSessionClientInterface
{
    /** @var array<string,HttpResponse> */
    private array $pages = [];

    /** @var array<string,HttpResponse> */
    private array $scanImages = [];

    /** @var list<string> */
    public array $requests = [];

    public function respondPage(string $url, HttpResponse $response): void
    {
        $this->pages[$url] = $response;
    }

    public function respondScanImage(string $viewerUrl, HttpResponse $response): void
    {
        $this->scanImages[$viewerUrl] = $response;
    }

    public function fetchPage(string $url): HttpResponse
    {
        $this->requests[] = 'page:' . $url;

        return $this->pages[$url] ?? throw new RuntimeException('No fake browser page response for ' . $url);
    }

    public function fetchScanImage(string $viewerUrl): HttpResponse
    {
        $this->requests[] = 'scan-image:' . $viewerUrl;

        return $this->scanImages[$viewerUrl]
            ?? throw new RuntimeException('No fake browser image response for ' . $viewerUrl);
    }
}
