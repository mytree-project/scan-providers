<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Support;

use MyTree\ScanProviders\Contracts\ScanAssetStorageInterface;
use MyTree\ScanProviders\Domain\StoredScanAsset;

final class InMemoryScanAssetStorage implements ScanAssetStorageInterface
{
    /** @var array<string,string> */
    public array $files = [];

    public function store(string $suggestedFilename, string $contents): StoredScanAsset
    {
        $this->files[$suggestedFilename] = $contents;
        return new StoredScanAsset(
            storagePath: 'memory://' . $suggestedFilename,
            filename: $suggestedFilename,
            size: strlen($contents),
            sha256: hash('sha256', $contents),
        );
    }
}
