<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Application;

use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;

final readonly class ResolveScan
{
    public function __construct(private ScanProviderRegistry $registry)
    {
    }

    public function execute(ResolveScanRequest $request): ScanResolution
    {
        return $this->registry->forResource($request->resource)->resolve($request);
    }
}
