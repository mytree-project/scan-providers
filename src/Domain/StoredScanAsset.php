<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class StoredScanAsset implements JsonSerializable
{
    public function __construct(
        public string $storagePath,
        public string $filename,
        public int $size,
        public string $sha256,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'storage_path' => $this->storagePath,
            'filename' => $this->filename,
            'size' => $this->size,
            'sha256' => $this->sha256,
        ];
    }
}
