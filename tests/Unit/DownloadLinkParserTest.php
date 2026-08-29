<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use MyTree\ScanProviders\Provider\GenealodzySkanoteka\DownloadLinkParser;
use PHPUnit\Framework\TestCase;

final class DownloadLinkParserTest extends TestCase
{
    public function testItResolvesTheDownloadLinkForTheSelectedFilename(): void
    {
        $html = (string) file_get_contents(__DIR__ . '/../fixtures/genealodzy-skanoteka/viewer.html');
        $viewer = 'https://metryki.genealodzy.pl/index.php?id=350&kt=12&op=pg&plik=12-21.jpg&sy=501';

        $url = (new DownloadLinkParser())->parse($html, $viewer, '12-21.jpg');
        self::assertSame(
            'https://metryki.genealodzy.pl/metryka.php?ar=10&zs=2596d&sy=501&kt=12&plik=12-21.jpg',
            $url,
        );
    }
}
