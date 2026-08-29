<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class ScanCatalog implements JsonSerializable
{
    public const SCHEMA = 'mytree.scan-catalog.v1';

    /** @param list<AvailableScan> $scans */
    public function __construct(
        public string $providerKey,
        public ScanResourceReference $resource,
        public array $scans,
        public ScanProvenance $provenance,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema' => self::SCHEMA,
            'provider' => $this->providerKey,
            'resource_url' => $this->resource->url,
            'scans' => $this->scans,
            'provenance' => $this->provenance,
        ];
    }
}
