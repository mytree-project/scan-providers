<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Tests\Support;

use DateTimeImmutable;
use MyTree\ScanProviders\Contracts\ClockInterface;

final readonly class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
