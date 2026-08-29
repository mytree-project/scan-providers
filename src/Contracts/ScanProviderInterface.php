<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Contracts;

use MyTree\ScanProviders\Domain\DownloadedScan;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ResolvedScan;
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Domain\ScanResourceReference;

interface ScanProviderInterface
{
    public function key(): string;

    public function supports(ScanResourceReference $resource): bool;

    public function resolve(ResolveScanRequest $request): ScanResolution;

    public function download(ResolvedScan $scan, ScanAssetStorageInterface $storage): DownloadedScan;
}
