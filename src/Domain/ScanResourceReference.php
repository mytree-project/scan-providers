<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ScanResourceReference implements JsonSerializable
{
    public function __construct(public string $url)
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('Scan resource URL must be an absolute HTTP(S) URL.');
        }
    }

    public function host(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_HOST));
    }

    /** @return array<string,mixed> */
    public function query(): array
    {
        parse_str((string) parse_url($this->url, PHP_URL_QUERY), $query);
        return $query;
    }

    public function jsonSerialize(): string
    {
        return $this->url;
    }

    public function __toString(): string
    {
        return $this->url;
    }
}
