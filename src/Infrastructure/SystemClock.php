<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use MyTree\ScanProviders\Contracts\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
