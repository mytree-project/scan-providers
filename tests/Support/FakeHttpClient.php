<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Support;

use MyTree\ScanProviders\Contracts\HttpClientInterface;
use MyTree\ScanProviders\Domain\HttpResponse;
use RuntimeException;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<string,HttpResponse> */
    private array $responses = [];

    /** @var list<string> */
    public array $requests = [];

    public function respond(string $url, HttpResponse $response): void
    {
        $this->responses[$url] = $response;
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = $url;
        return $this->responses[$url] ?? throw new RuntimeException('No fake response for ' . $url);
    }
}
