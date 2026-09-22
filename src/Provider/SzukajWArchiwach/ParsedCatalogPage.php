<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

final readonly class ParsedCatalogPage
{
    /**
     * @param array<string,string> $rawMetadata
     * @param list<array{object_id:string,label:string}> $scanEntries
     */
    public function __construct(
        public ?int $scanCount,
        public ?string $title,
        public array $rawMetadata,
        public array $scanEntries,
        public ?string $nextPageUrl,
    ) {
    }
}
