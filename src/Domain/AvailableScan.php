<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class AvailableScan implements JsonSerializable
{
    /**
     * @param list<ScanLocator> $locators
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public string $providerKey,
        public string $remoteId,
        public string $label,
        public string $remoteFilename,
        public string $viewerUrl,
        public array $locators = [],
        public array $metadata = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider' => $this->providerKey,
            'remote_id' => $this->remoteId,
            'label' => $this->label,
            'remote_filename' => $this->remoteFilename,
            'viewer_url' => $this->viewerUrl,
            'locators' => $this->locators,
            'metadata' => $this->metadata,
        ];
    }
}
