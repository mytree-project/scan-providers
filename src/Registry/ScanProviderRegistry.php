<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Registry;

use MyTree\ScanProviders\Contracts\ScanProviderInterface;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\AmbiguousScanProviderException;
use MyTree\ScanProviders\Exception\UnsupportedScanProviderException;

final class ScanProviderRegistry
{
    /** @var array<string,ScanProviderInterface> */
    private array $providers = [];

    /** @param iterable<ScanProviderInterface> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(ScanProviderInterface $provider): void
    {
        $key = trim($provider->key());
        if ($key === '') {
            throw new \InvalidArgumentException('Scan provider key cannot be empty.');
        }
        if (isset($this->providers[$key])) {
            throw new \InvalidArgumentException("Scan provider '$key' is already registered.");
        }
        $this->providers[$key] = $provider;
    }

    public function forResource(ScanResourceReference $resource): ScanProviderInterface
    {
        $matches = array_values(array_filter(
            $this->providers,
            static fn (ScanProviderInterface $provider): bool => $provider->supports($resource),
        ));

        if ($matches === []) {
            throw new UnsupportedScanProviderException('No scan provider supports host: ' . $resource->host());
        }
        if (count($matches) > 1) {
            $keys = implode(', ', array_map(static fn (ScanProviderInterface $p): string => $p->key(), $matches));
            throw new AmbiguousScanProviderException('Multiple scan providers matched the resource: ' . $keys);
        }

        return $matches[0];
    }

    public function byKey(string $key): ScanProviderInterface
    {
        return $this->providers[$key]
            ?? throw new UnsupportedScanProviderException("Scan provider '$key' is not registered.");
    }

    /** @return list<ScanProviderInterface> */
    public function all(): array
    {
        return array_values($this->providers);
    }
}
