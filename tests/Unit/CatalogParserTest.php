<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use MyTree\ScanProviders\Domain\ScanLocator;
use MyTree\ScanProviders\Provider\GenealodzySkanoteka\CatalogParser;
use PHPUnit\Framework\TestCase;

final class CatalogParserTest extends TestCase
{
    public function testItDiscoversScansAndPreservesOpaqueFiles(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../fixtures/genealodzy-skanoteka/catalog.html');
        $resource = 'https://metryki.genealodzy.pl/metryki.php?op=kt&ar=10&zs=2596d&sy=501&kt=12';
        $scans = (new CatalogParser())->parse($html, $resource, 'genealodzy-skanoteka');

        self::assertCount(4, $scans);
        self::assertSame('12-21.jpg', $scans[1]->remoteFilename);
        self::assertSame(12, $scans[1]->locators[0]->from);
        self::assertSame(21, $scans[1]->locators[0]->to);
        self::assertSame(ScanLocator::OPAQUE, $scans[3]->locators[0]->kind);
        self::assertStringStartsWith('https://metryki.genealodzy.pl/index.php?', $scans[1]->viewerUrl);
    }
}
