<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

use MyTree\ScanProviders\Domain\HttpResponse;

interface BrowserSessionClientInterface
{
    public function fetchPage(string $url): HttpResponse;

    public function fetchScanImage(string $viewerUrl): HttpResponse;

    public function fetchUnitScanImage(
        string $unitUrl,
        int $scanOrdinal,
        string $expectedObjectId,
    ): HttpResponse;
}
