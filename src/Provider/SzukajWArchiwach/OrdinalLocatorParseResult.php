<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

final readonly class OrdinalLocatorParseResult
{
    public const VALID = 'valid';
    public const MISSING = 'missing';
    public const UNSUPPORTED = 'unsupported';
    public const CONFLICT = 'conflict';

    /** @param list<string> $trace */
    public function __construct(
        public string $status,
        public ?int $ordinal = null,
        public ?string $matchedRaw = null,
        public ?string $reason = null,
        public array $trace = [],
    ) {
    }
}
