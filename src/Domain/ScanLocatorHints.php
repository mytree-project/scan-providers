<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Domain;

use JsonSerializable;

final readonly class ScanLocatorHints implements JsonSerializable
{
    public function __construct(
        public ?string $recordNumberRaw = null,
        public ?int $year = null,
        public ?string $recordType = null,
        public ?string $parishRaw = null,
        public ?string $pageRaw = null,
        public ?string $scanNumberRaw = null,
        public ?string $archiveSignatureRaw = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'record_number_raw' => $this->recordNumberRaw,
            'year' => $this->year,
            'record_type' => $this->recordType,
            'parish_raw' => $this->parishRaw,
            'page_raw' => $this->pageRaw,
            'scan_number_raw' => $this->scanNumberRaw,
            'archive_signature_raw' => $this->archiveSignatureRaw,
        ];
    }
}
