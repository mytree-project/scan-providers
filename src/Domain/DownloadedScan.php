<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class DownloadedScan implements JsonSerializable
{
    public const SCHEMA = 'mytree.downloaded-scan.v1';

    public function __construct(
        public string $providerKey,
        public StoredScanAsset $asset,
        public string $mimeType,
        public string $resourceUrl,
        public string $viewerUrl,
        public string $downloadUrl,
        public string $retrievedAt,
        public string $resolutionStrategy,
        public ScanProvenance $catalogProvenance,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema' => self::SCHEMA,
            'provider' => $this->providerKey,
            'asset' => $this->asset,
            'mime_type' => $this->mimeType,
            'resource_url' => $this->resourceUrl,
            'viewer_url' => $this->viewerUrl,
            'download_url' => $this->downloadUrl,
            'retrieved_at' => $this->retrievedAt,
            'resolution_strategy' => $this->resolutionStrategy,
            'catalog_provenance' => $this->catalogProvenance,
        ];
    }
}
