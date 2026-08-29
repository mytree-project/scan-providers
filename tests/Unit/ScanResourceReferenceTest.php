<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use InvalidArgumentException;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use PHPUnit\Framework\TestCase;

final class ScanResourceReferenceTest extends TestCase
{
    public function testItExtractsNormalizedHost(): void
    {
        $resource = new ScanResourceReference('HTTPS://metryki.genealodzy.pl/metryki.php?op=kt');
        self::assertSame('metryki.genealodzy.pl', $resource->host());
    }

    public function testItRejectsRelativeUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScanResourceReference('/metryki.php?op=kt');
    }
}
