<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Infrastructure\SzukajWArchiwach;

use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Exception\ScanProviderException;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\BrowserSessionClientInterface;

final readonly class PlaywrightBrowserSessionClient implements BrowserSessionClientInterface
{
    public function __construct(
        private string $nodeBinary = 'node',
        private ?string $workerPath = null,
        private int $timeoutSeconds = 60,
        private ?string $debugDirectory = null,
    ) {
        if ($this->timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Browser session timeout must be positive.');
        }
        if ($this->debugDirectory !== null && trim($this->debugDirectory) === '') {
            throw new \InvalidArgumentException('Browser debug directory cannot be empty.');
        }
    }

    public function fetchPage(string $url): HttpResponse
    {
        return $this->run('page', $url);
    }

    public function fetchScanImage(string $viewerUrl): HttpResponse
    {
        return $this->run('scan-image', $viewerUrl);
    }

    private function run(string $action, string $url): HttpResponse
    {
        $worker = $this->workerPath ?? dirname(__DIR__, 3) . '/runtime/szukajwarchiwach-browser.mjs';
        if (!is_file($worker)) {
            throw new ScanProviderException('Szukaj w Archiwach browser worker is missing: ' . $worker);
        }

        $command = [
            $this->nodeBinary,
            $worker,
            $action,
            $url,
            '--timeout-ms=' . ($this->timeoutSeconds * 1000),
        ];
        if ($this->debugDirectory !== null) {
            $command[] = '--debug-dir=' . $this->debugDirectory;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, dirname(__DIR__, 3));
        if (!is_resource($process)) {
            throw new ScanProviderException(
                'Unable to start the Szukaj w Archiwach browser worker. Ensure Node.js is installed.',
            );
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $detail = trim($stderr);
            throw new ScanProviderException(
                'Szukaj w Archiwach browser worker failed'
                . ($detail !== '' ? ': ' . $detail : '.'),
            );
        }

        if ($this->debugDirectory !== null && trim($stderr) !== '') {
            fwrite(STDERR, rtrim($stderr) . PHP_EOL);
        }

        try {
            $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ScanProviderException(
                'Szukaj w Archiwach browser worker returned invalid JSON.',
                0,
                $exception,
            );
        }

        if (!is_array($payload)) {
            throw new ScanProviderException('Szukaj w Archiwach browser worker returned an invalid payload.');
        }

        $status = $payload['status'] ?? null;
        $responseUrl = $payload['url'] ?? null;
        $headers = $payload['headers'] ?? null;
        $bodyBase64 = $payload['body_base64'] ?? null;
        if (
            !is_int($status)
            || !is_string($responseUrl)
            || !is_array($headers)
            || !is_string($bodyBase64)
        ) {
            throw new ScanProviderException('Szukaj w Archiwach browser worker response is incomplete.');
        }

        $body = base64_decode($bodyBase64, true);
        if ($body === false) {
            throw new ScanProviderException('Szukaj w Archiwach browser worker returned invalid response bytes.');
        }

        $normalizedHeaders = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name)) {
                continue;
            }
            if (is_string($values)) {
                $normalizedHeaders[strtolower($name)] = [$values];
                continue;
            }
            if (!is_array($values)) {
                continue;
            }

            $normalizedHeaders[strtolower($name)] = array_values(array_filter(
                $values,
                static fn (mixed $value): bool => is_string($value),
            ));
        }

        return new HttpResponse(
            status: $status,
            headers: $normalizedHeaders,
            body: $body,
            url: $responseUrl,
        );
    }
}
