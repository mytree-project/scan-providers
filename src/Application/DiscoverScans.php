<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Application;

use MyTree\ScanProviders\Contracts\ScanCatalogDiscoveryInterface;
use MyTree\ScanProviders\Domain\ScanCatalog;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\ScanCapabilityUnavailableException;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;

final readonly class DiscoverScans
{
    public function __construct(private ScanProviderRegistry $registry)
    {
    }

    public function execute(ScanResourceReference $resource): ScanCatalog
    {
        $provider = $this->registry->forResource($resource);
        if (!$provider instanceof ScanCatalogDiscoveryInterface) {
            throw new ScanCapabilityUnavailableException(
                "Scan provider '{$provider->key()}' does not support catalog discovery.",
            );
        }

        return $provider->discoverScans($resource);
    }
}
