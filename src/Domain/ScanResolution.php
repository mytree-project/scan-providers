<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class ScanResolution implements JsonSerializable
{
    public const SCHEMA = 'mytree.scan-resolution.v1';

    /**
     * @param list<AvailableScan> $candidates
     * @param list<string> $trace
     */
    public function __construct(
        public ScanResolutionStatus $status,
        public string $providerKey,
        public ResolveScanRequest $request,
        public array $candidates = [],
        public ?ResolvedScan $resolved = null,
        public ?string $strategy = null,
        public ?string $reason = null,
        public array $trace = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'schema' => self::SCHEMA,
            'status' => $this->status->value,
            'provider' => $this->providerKey,
            'request' => $this->request,
            'candidates' => $this->candidates,
            'resolved' => $this->resolved,
            'strategy' => $this->strategy,
            'reason' => $this->reason,
            'trace' => $this->trace,
        ];
    }
}
