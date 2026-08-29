<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Resolution;

use MyTree\ScanProviders\Domain\AvailableScan;

final class ActNumberMatcher
{
    public const STRATEGY = 'filename_act_number_range';

    public function parseRecordNumber(?string $raw): ?int
    {
        if ($raw === null || !preg_match('~^\\s*0*(\\d+)\\s*$~', $raw, $match)) {
            return null;
        }
        $number = (int) $match[1];
        return $number > 0 ? $number : null;
    }

    /**
     * @param list<AvailableScan> $scans
     * @return list<AvailableScan>
     */
    public function matching(int $recordNumber, array $scans): array
    {
        $matches = [];
        foreach ($scans as $scan) {
            foreach ($scan->locators as $locator) {
                if ($locator->matchesActNumber($recordNumber)) {
                    $matches[] = $scan;
                    break;
                }
            }
        }
        return $matches;
    }
}
