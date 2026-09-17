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

    /** @var array<string,list<HttpResponse>> */
    private array $responseSequences = [];

    /** @var list<string> */
    public array $requests = [];

    public function respond(string $url, HttpResponse $response): void
    {
        $this->responses[$url] = $response;
    }

    /** @param list<HttpResponse> $responses */
    public function respondSequence(string $url, array $responses): void
    {
        if ($responses === []) {
            throw new RuntimeException('Fake response sequence cannot be empty.');
        }

        $this->responseSequences[$url] = array_values($responses);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $this->requests[] = $url;
        if (isset($this->responseSequences[$url])) {
            $response = array_shift($this->responseSequences[$url]);
            if ($response !== null) {
                if ($this->responseSequences[$url] === []) {
                    unset($this->responseSequences[$url]);
                }
                return $response;
            }
        }

        return $this->responses[$url] ?? throw new RuntimeException('No fake response for ' . $url);
    }
}
