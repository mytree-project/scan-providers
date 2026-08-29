<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Unit;

use MyTree\ScanProviders\Domain\AvailableScan;
use MyTree\ScanProviders\Domain\ScanLocator;
use MyTree\ScanProviders\Resolution\ActNumberMatcher;
use PHPUnit\Framework\TestCase;

final class ActNumberMatcherTest extends TestCase
{
    public function testItMatchesOnlyScansWhoseActRangeContainsTheRecordNumber(): void
    {
        $scans = [
            $this->scan('12-21.jpg', new ScanLocator(ScanLocator::ACT_NUMBER_RANGE, '12-21', 12, 21)),
            $this->scan('22-31.jpg', new ScanLocator(ScanLocator::ACT_NUMBER_RANGE, '22-31', 22, 31)),
            $this->scan('SkU-1.jpg', new ScanLocator(ScanLocator::OPAQUE, 'SkU-1')),
        ];

        $matches = (new ActNumberMatcher())->matching(17, $scans);
        self::assertCount(1, $matches);
        self::assertSame('12-21.jpg', $matches[0]->remoteFilename);
    }

    public function testItDoesNotInventNumericMeaningForAlphanumericRecordNumbers(): void
    {
        self::assertNull((new ActNumberMatcher())->parseRecordNumber('17a'));
    }

    private function scan(string $filename, ScanLocator $locator): AvailableScan
    {
        return new AvailableScan('p', hash('sha256', $filename), $filename, $filename, 'https://example.test/' . $filename, [$locator]);
    }
}
