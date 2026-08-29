<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use MyTree\ScanProviders\Domain\ScanLocator;
use MyTree\ScanProviders\Resolution\ActNumberLocatorParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActNumberLocatorParserTest extends TestCase
{
    #[DataProvider('filenames')]
    public function testItParsesStrictActLocators(string $filename, string $kind, ?int $from, ?int $to): void
    {
        $locator = (new ActNumberLocatorParser())->fromFilename($filename)[0];
        self::assertSame($kind, $locator->kind);
        self::assertSame($from, $locator->from);
        self::assertSame($to, $locator->to);
    }

    /** @return iterable<string,array{string,string,?int,?int}> */
    public static function filenames(): iterable
    {
        yield 'exact' => ['017.jpg', ScanLocator::ACT_NUMBER, 17, 17];
        yield 'range' => ['012-021.jpg', ScanLocator::ACT_NUMBER_RANGE, 12, 21];
        yield 'opaque' => ['SkU-1.jpg', ScanLocator::OPAQUE, null, null];
    }
}
