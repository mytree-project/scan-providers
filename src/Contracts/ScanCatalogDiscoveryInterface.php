<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Contracts;

use MyTree\ScanProviders\Domain\ScanCatalog;
use MyTree\ScanProviders\Domain\ScanResourceReference;

/** Optional provider capability for enumerating scans in a remote resource. */
interface ScanCatalogDiscoveryInterface
{
    public function discoverScans(ScanResourceReference $resource): ScanCatalog;
}
