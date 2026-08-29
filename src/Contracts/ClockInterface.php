<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Contracts;

use DateTimeImmutable;

interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
