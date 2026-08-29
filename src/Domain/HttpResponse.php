<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

final readonly class HttpResponse
{
    /** @param array<string,list<string>> $headers */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
        public string $url,
    ) {
    }

    public function firstHeader(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];
        return $values[0] ?? null;
    }
}
