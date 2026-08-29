<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class ResolveScanRequest implements JsonSerializable
{
    public function __construct(
        public ScanResourceReference $resource,
        public ScanLocatorHints $hints = new ScanLocatorHints(),
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'resource_url' => $this->resource->url,
            'hints' => $this->hints,
        ];
    }
}
