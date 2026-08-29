<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Resolution;

use MyTree\ScanProviders\Domain\ScanLocator;

final class ActNumberLocatorParser
{
    /** @return list<ScanLocator> */
    public function fromFilename(string $filename): array
    {
        $stem = pathinfo(trim($filename), PATHINFO_FILENAME);
        if (preg_match('~^0*(\\d+)$~', $stem, $match)) {
            $number = (int) $match[1];
            if ($number > 0) {
                return [new ScanLocator(ScanLocator::ACT_NUMBER, $stem, $number, $number)];
            }
        }

        if (preg_match('~^0*(\\d+)\\s*[-–—]\\s*0*(\\d+)$~u', $stem, $match)) {
            $from = (int) $match[1];
            $to = (int) $match[2];
            if ($from > 0 && $to >= $from) {
                return [new ScanLocator(ScanLocator::ACT_NUMBER_RANGE, $stem, $from, $to)];
            }
        }

        return [new ScanLocator(ScanLocator::OPAQUE, $stem)];
    }
}
