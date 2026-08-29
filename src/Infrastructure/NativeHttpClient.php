<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Infrastructure;

use MyTree\ScanProviders\Contracts\HttpClientInterface;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Exception\ScanProviderException;

final class NativeHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $maxBodyBytes = 104_857_600,
        private readonly string $userAgent = 'MyTree-Scan-Providers/0.1 (+https://github.com/mytree-project/scan-providers)',
    ) {
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        $headerLines = [
            'User-Agent: ' . $this->userAgent,
            'Connection: close',
        ];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headerLines),
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context, 0, $this->maxBodyBytes + 1);
        $responseHeaders = $http_response_header ?? [];
        if ($body === false && $responseHeaders === []) {
            $error = error_get_last();
            throw new ScanProviderException('HTTP GET failed for ' . $url . ': ' . ($error['message'] ?? 'unknown error'));
        }
        if ($body !== false && strlen($body) > $this->maxBodyBytes) {
            throw new ScanProviderException('HTTP response exceeded configured maximum size.');
        }

        [$status, $parsedHeaders] = $this->parseHeaders($responseHeaders);
        return new HttpResponse($status, $parsedHeaders, $body === false ? '' : $body, $url);
    }

    /**
     * @param list<string> $lines
     * @return array{0:int,1:array<string,list<string>>}
     */
    private function parseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('~^HTTP/\\S+\\s+(\\d{3})~i', $line, $match)) {
                $status = (int) $match[1];
                $headers = [];
                continue;
            }
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $position)));
            $value = trim(substr($line, $position + 1));
            $headers[$name] ??= [];
            $headers[$name][] = $value;
        }

        return [$status, $headers];
    }
}
