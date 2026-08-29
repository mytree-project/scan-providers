<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Contracts;

use MyTree\ScanProviders\Domain\StoredScanAsset;

interface ScanAssetStorageInterface
{
    public function store(string $suggestedFilename, string $contents): StoredScanAsset;
}
