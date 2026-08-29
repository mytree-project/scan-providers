<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class ScanProvenance implements JsonSerializable
{
    /** @param array<string,mixed> $details */
    public function __construct(
        public string $providerKey,
        public string $providerVersion,
        public string $resourceUrl,
        public string $retrievedAt,
        public string $responseSha256,
        public array $details = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->providerKey,
            'provider_version' => $this->providerVersion,
            'resource_url' => $this->resourceUrl,
            'retrieved_at' => $this->retrievedAt,
            'response_sha256' => $this->responseSha256,
            'details' => $this->details,
        ];
    }
}
