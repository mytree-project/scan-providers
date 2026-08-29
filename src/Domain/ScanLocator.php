<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use InvalidArgumentException;
use JsonSerializable;

final readonly class ScanLocator implements JsonSerializable
{
    public const ACT_NUMBER = 'act_number';
    public const ACT_NUMBER_RANGE = 'act_number_range';
    public const OPAQUE = 'opaque';

    public function __construct(
        public string $kind,
        public string $raw,
        public ?int $from = null,
        public ?int $to = null,
    ) {
        if (($from === null) !== ($to === null)) {
            throw new InvalidArgumentException('Scan locator range requires both from and to values.');
        }
        if ($from !== null && ($from < 1 || $to < $from)) {
            throw new InvalidArgumentException('Invalid scan locator range.');
        }
    }

    public function matchesActNumber(int $actNumber): bool
    {
        return $this->from !== null
            && $this->to !== null
            && $actNumber >= $this->from
            && $actNumber <= $this->to;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind,
            'raw' => $this->raw,
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
