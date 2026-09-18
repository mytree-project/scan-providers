<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Infrastructure;

use MyTree\ScanProviders\Contracts\HttpClientInterface;
use MyTree\ScanProviders\Provider\GenealodzySkanoteka\GenealodzySkanotekaProvider;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\SzukajWArchiwachProvider;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;

final class DefaultScanProviderRegistryFactory
{
    public static function create(HttpClientInterface $http): ScanProviderRegistry
    {
        return new ScanProviderRegistry([
            new GenealodzySkanotekaProvider($http),
            new SzukajWArchiwachProvider($http),
        ]);
    }
}
