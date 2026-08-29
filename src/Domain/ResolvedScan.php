<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class ResolvedScan implements JsonSerializable
{
    public function __construct(
        public string $providerKey,
        public ScanResourceReference $resource,
        public AvailableScan $scan,
        public string $strategy,
        public ?string $matchedHintRaw,
        public ScanProvenance $catalogProvenance,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->providerKey,
            'resource_url' => $this->resource->url,
            'scan' => $this->scan,
            'strategy' => $this->strategy,
            'matched_hint_raw' => $this->matchedHintRaw,
            'catalog_provenance' => $this->catalogProvenance,
        ];
    }
}
