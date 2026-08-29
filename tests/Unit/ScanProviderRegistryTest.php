<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use MyTree\ScanProviders\Contracts\ScanAssetStorageInterface;
use MyTree\ScanProviders\Contracts\ScanProviderInterface;
use MyTree\ScanProviders\Domain\DownloadedScan;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ResolvedScan;
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\AmbiguousScanProviderException;
use MyTree\ScanProviders\Registry\ScanProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ScanProviderRegistryTest extends TestCase
{
    public function testItRoutesByProviderSupport(): void
    {
        $provider = new StubProvider('p1', 'example.test');
        $registry = new ScanProviderRegistry([$provider]);
        self::assertSame($provider, $registry->forResource(new ScanResourceReference('https://example.test/a')));
    }

    public function testItFailsWhenTwoProvidersClaimTheSameResource(): void
    {
        $registry = new ScanProviderRegistry([
            new StubProvider('p1', 'example.test'),
            new StubProvider('p2', 'example.test'),
        ]);
        $this->expectException(AmbiguousScanProviderException::class);
        $registry->forResource(new ScanResourceReference('https://example.test/a'));
    }
}

final class StubProvider implements ScanProviderInterface
{
    public function __construct(private string $key, private string $host) {}
    public function key(): string { return $this->key; }
    public function supports(ScanResourceReference $resource): bool { return $resource->host() === $this->host; }
    public function resolve(ResolveScanRequest $request): ScanResolution { throw new \LogicException('not used'); }
    public function download(ResolvedScan $scan, ScanAssetStorageInterface $storage): DownloadedScan { throw new \LogicException('not used'); }
}
