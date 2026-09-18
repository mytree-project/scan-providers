<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

use MyTree\ScanProviders\Contracts\HttpClientInterface;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Exception\ScanProviderException;
use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;

final readonly class RetryingHttpFetcher
{
    public function __construct(
        private HttpClientInterface $http,
        private int $maxAttempts = 3,
        private int $retryBackoffMilliseconds = 500,
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('Szukaj w Archiwach max attempts must be positive.');
        }
        if ($this->retryBackoffMilliseconds < 0) {
            throw new \InvalidArgumentException('Szukaj w Archiwach retry backoff cannot be negative.');
        }
    }

    /** @param array<string,string> $headers */
    public function get(string $url, array $headers = []): HttpResponse
    {
        $lastTransportFailure = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; ++$attempt) {
            try {
                $response = $this->http->get($url, $headers);
                $lastTransportFailure = null;
            } catch (ScanProviderException $exception) {
                $lastTransportFailure = $exception;
                if ($attempt === $this->maxAttempts) {
                    throw $exception;
                }
                $this->sleepMilliseconds($this->retryBackoffMilliseconds * $attempt);
                continue;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $response;
            }

            $retryable = $response->status === 429 || $response->status >= 500;
            if (!$retryable || $attempt === $this->maxAttempts) {
                throw new UnexpectedProviderResponseException(
                    sprintf('Szukaj w Archiwach returned HTTP %d for %s.', $response->status, $url),
                );
            }

            $this->sleepMilliseconds($this->retryBackoffMilliseconds * $attempt);
        }

        throw $lastTransportFailure ?? new UnexpectedProviderResponseException(
            'Szukaj w Archiwach request failed without a response.',
        );
    }

    private function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
