<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Application;

use MyTree\ScanProviders\Contracts\ScanAssetStorageInterface;
use MyTree\ScanProviders\Domain\DownloadedScan;
use MyTree\ScanProviders\Domain\ResolvedScan;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;

final readonly class DownloadScan
{
    public function __construct(
        private ScanProviderRegistry $registry,
        private ScanAssetStorageInterface $storage,
    ) {
    }

    public function execute(ResolvedScan $scan): DownloadedScan
    {
        return $this->registry->byKey($scan->providerKey)->download($scan, $this->storage);
    }
}
