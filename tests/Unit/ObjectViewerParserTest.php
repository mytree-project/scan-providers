<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Provider\SzukajWArchiwach\ObjectViewerParser;
use PHPUnit\Framework\TestCase;

final class ObjectViewerParserTest extends TestCase
{
    private const VIEWER_URL = 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/990003/obiekty/700002';
    private const PUBLIC_SCAN_URL = 'https://www.szukajwarchiwach.gov.pl/skan/-/skan/0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testItExtractsPublicScanRouteAndIgnoresUndocumentedInternalApiLink(): void
    {
        $parser = new ObjectViewerParser();

        $url = $parser->publicScanUrl($this->fixture('object-viewer.html'), self::VIEWER_URL);

        self::assertSame(self::PUBLIC_SCAN_URL, $url);
    }

    public function testItAcceptsAbsolutePublicScanLinkWithoutWwwHost(): void
    {
        $parser = new ObjectViewerParser();
        $html = '<a href="https://szukajwarchiwach.gov.pl/skan/-/skan/opaque-token_123">Link do skanu</a>';

        self::assertSame(
            'https://szukajwarchiwach.gov.pl/skan/-/skan/opaque-token_123',
            $parser->publicScanUrl($html, self::VIEWER_URL),
        );
    }

    public function testItReturnsNullWhenOnlyUndocumentedInternalApiLinkIsPresent(): void
    {
        $parser = new ObjectViewerParser();

        self::assertNull($parser->publicScanUrl(
            $this->fixture('object-viewer-missing.html'),
            self::VIEWER_URL,
        ));
    }

    public function testItRejectsMultipleDistinctPublicScanLinksInsteadOfGuessing(): void
    {
        $parser = new ObjectViewerParser();
        $html = <<<'HTML'
<a href="/skan/-/skan/first-token">first</a>
<a href="/skan/-/skan/second-token">second</a>
HTML;

        $this->expectException(UnexpectedProviderResponseException::class);
        $parser->publicScanUrl($html, self::VIEWER_URL);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/szukajwarchiwach/' . $name);
    }
}
