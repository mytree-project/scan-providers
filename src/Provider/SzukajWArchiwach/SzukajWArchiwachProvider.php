<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

use MyTree\ScanProviders\Contracts\ClockInterface;
use MyTree\ScanProviders\Contracts\HttpClientInterface;
use MyTree\ScanProviders\Contracts\ScanAssetStorageInterface;
use MyTree\ScanProviders\Contracts\ScanCatalogDiscoveryInterface;
use MyTree\ScanProviders\Contracts\ScanProviderInterface;
use MyTree\ScanProviders\Domain\AvailableScan;
use MyTree\ScanProviders\Domain\DownloadedScan;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ResolvedScan;
use MyTree\ScanProviders\Domain\ScanCatalog;
use MyTree\ScanProviders\Domain\ScanLocator;
use MyTree\ScanProviders\Domain\ScanProvenance;
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Infrastructure\SystemClock;

final class SzukajWArchiwachProvider implements ScanProviderInterface, ScanCatalogDiscoveryInterface
{
    public const KEY = 'szukajwarchiwach';
    public const VERSION = '0.1.0';
    public const PUBLIC_SCAN_VIEWER_STRATEGY = 'public_scan_viewer_url';

    /** @var array<string,ScanCatalog> */
    private array $catalogCache = [];

    private readonly RetryingHttpFetcher $fetcher;
    private readonly SzukajWArchiwachAssetDownloader $assetDownloader;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ?BrowserSessionClientInterface $browserSessionClient = null,
        private readonly CatalogPageParser $parser = new CatalogPageParser(),
        private readonly OrdinalScanResolver $ordinalResolver = new OrdinalScanResolver(),
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly int $maxAttempts = 3,
        private readonly int $retryBackoffMilliseconds = 500,
        private readonly int $requestPacingMilliseconds = 250,
        private readonly int $maxPages = 500,
        ?SzukajWArchiwachAssetDownloader $assetDownloader = null,
    ) {
        if ($this->maxAttempts < 1 || $this->maxPages < 1) {
            throw new \InvalidArgumentException('Szukaj w Archiwach retry/page limits must be positive.');
        }
        if ($this->retryBackoffMilliseconds < 0 || $this->requestPacingMilliseconds < 0) {
            throw new \InvalidArgumentException('Szukaj w Archiwach delays cannot be negative.');
        }

        $this->fetcher = new RetryingHttpFetcher(
            http: $this->http,
            browserSessionClient: $this->browserSessionClient,
            maxAttempts: $this->maxAttempts,
            retryBackoffMilliseconds: $this->retryBackoffMilliseconds,
        );
        $this->assetDownloader = $assetDownloader ?? new SzukajWArchiwachAssetDownloader(
            fetcher: $this->fetcher,
            browserSessionClient: $this->browserSessionClient,
            clock: $this->clock,
            requestPacingMilliseconds: $this->requestPacingMilliseconds,
        );
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(ScanResourceReference $resource): bool
    {
        return $this->unitId($resource) !== null || $this->publicScanToken($resource) !== null;
    }

    public function discoverScans(ScanResourceReference $resource): ScanCatalog
    {
        $directToken = $this->publicScanToken($resource);
        if ($directToken !== null) {
            return new ScanCatalog(
                providerKey: self::KEY,
                resource: $resource,
                scans: [$this->directAvailableScan($resource, $directToken)],
                provenance: $this->directProvenance($resource, $directToken),
            );
        }

        $unitId = $this->unitId($resource);
        if ($unitId === null) {
            throw new \InvalidArgumentException('Resource is not a supported Szukaj w Archiwach unit URL.');
        }

        $cacheKey = $resource->url;
        if (isset($this->catalogCache[$cacheKey])) {
            return $this->catalogCache[$cacheKey];
        }

        $unitUrl = $this->canonicalUnitUrl($unitId);
        $catalogStartUrl = $this->catalogStartUrl($unitId);
        $nextPageUrl = $unitUrl;
        $visited = [];
        $bootstrapResponseSha256 = null;
        $bootstrapFallbackUsed = false;
        $rawEntries = [];
        $seenObjectIds = [];
        $pageHashes = [];
        $pageEntryCounts = [];
        $responseCorpus = '';
        $observedPageSize = null;
        $firstPage = null;
        $expectedCount = null;
        $pageNumber = 0;
        $lastResponseUrl = null;
        $lastResponseBody = '';

        while ($nextPageUrl !== null) {
            if (isset($visited[$nextPageUrl])) {
                throw new UnexpectedProviderResponseException('Szukaj w Archiwach catalog pagination entered a URL loop.');
            }
            $visited[$nextPageUrl] = true;
            $this->assertUnitPageUrl($nextPageUrl, $unitId);

            if (count($visited) > 1) {
                $this->sleepMilliseconds($this->requestPacingMilliseconds);
            }

            $response = $this->fetchPage($nextPageUrl);
            $lastResponseUrl = $nextPageUrl;
            $lastResponseBody = $response->body;
            $parsed = $this->parser->parse($response->body, $nextPageUrl);

            if (
                $nextPageUrl === $unitUrl
                && $parsed->scanCount === null
                && $parsed->scanEntries === []
                && $parsed->nextPageUrl === null
            ) {
                $bootstrapResponseSha256 = hash('sha256', $response->body);
                $bootstrapFallbackUsed = true;
                $nextPageUrl = $catalogStartUrl;
                continue;
            }

            ++$pageNumber;
            if ($pageNumber > $this->maxPages) {
                throw new UnexpectedProviderResponseException('Szukaj w Archiwach catalog exceeded the configured pagination limit.');
            }

            $firstPage ??= $parsed;
            if ($parsed->scanCount !== null) {
                $expectedCount ??= $parsed->scanCount;
                if ($parsed->scanCount !== $expectedCount) {
                    throw new UnexpectedProviderResponseException('Szukaj w Archiwach scan cardinality changed between catalog pages.');
                }
            }

            $pageHash = hash('sha256', $response->body);
            $pageHashes[$nextPageUrl] = $pageHash;
            $pageEntryCounts[$nextPageUrl] = count($parsed->scanEntries);
            $responseCorpus .= $nextPageUrl . "\n" . $response->body . "\n";

            if ($parsed->nextPageUrl !== null && count($parsed->scanEntries) > 0) {
                $observedPageSize = count($parsed->scanEntries);
            }

            foreach ($parsed->scanEntries as $entry) {
                if (isset($seenObjectIds[$entry['object_id']])) {
                    throw new UnexpectedProviderResponseException(
                        'Szukaj w Archiwach catalog repeated provider object id ' . $entry['object_id'] . '.',
                    );
                }
                $seenObjectIds[$entry['object_id']] = true;
                $rawEntries[] = $entry;
            }

            $nextPageUrl = $parsed->nextPageUrl
                ?? $this->syntheticNextCatalogPageUrl(
                    $nextPageUrl,
                    count($parsed->scanEntries),
                    $observedPageSize,
                );
        }

        if ($firstPage === null) {
            throw new UnexpectedProviderResponseException('Szukaj w Archiwach catalog did not return a parseable first page.');
        }
        if ($expectedCount === null && $rawEntries === []) {
            throw new UnexpectedProviderResponseException(sprintf(
                'Szukaj w Archiwach catalog exposed neither a declared scan count nor recognizable scan entries. %s',
                $this->catalogDiagnostics($lastResponseUrl, $lastResponseBody),
            ));
        }
        if ($expectedCount !== null && count($rawEntries) !== $expectedCount) {
            throw new UnexpectedProviderResponseException(sprintf(
                'Szukaj w Archiwach catalog declared %d scan(s) but complete enumeration found %d.',
                $expectedCount,
                count($rawEntries),
            ));
        }

        $scans = [];
        foreach ($rawEntries as $offset => $entry) {
            $ordinal = $offset + 1;
            $scans[] = new AvailableScan(
                providerKey: self::KEY,
                remoteId: $entry['object_id'],
                label: $entry['label'] !== '' ? $entry['label'] : 'Scan ' . $ordinal,
                remoteFilename: '',
                viewerUrl: $this->objectViewerUrl($unitId, $entry['object_id']),
                locators: [
                    new ScanLocator(ScanLocator::OPAQUE, 'scan:' . $ordinal),
                    new ScanLocator(ScanLocator::OPAQUE, 'object:' . $entry['object_id']),
                ],
                metadata: [
                    'unit_id' => $unitId,
                    'scan_ordinal' => $ordinal,
                    'object_id' => $entry['object_id'],
                    'remote_filename_available' => false,
                    'raw_label' => $entry['label'],
                ],
            );
        }

        $unitMetadata = $this->unitMetadata($firstPage->title, $firstPage->rawMetadata);
        $catalog = new ScanCatalog(
            providerKey: self::KEY,
            resource: $resource,
            scans: $scans,
            provenance: new ScanProvenance(
                providerKey: self::KEY,
                providerVersion: self::VERSION,
                resourceUrl: $resource->url,
                retrievedAt: $this->clock->now()->format(DATE_ATOM),
                responseSha256: hash('sha256', $responseCorpus),
                details: [
                    'discovery_strategy' => 'public_unit_html_scan_catalog',
                    'unit_id' => $unitId,
                    'canonical_unit_url' => $unitUrl,
                    'catalog_start_url' => $catalogStartUrl,
                    'bootstrap_fallback_used' => $bootstrapFallbackUsed,
                    'bootstrap_response_sha256' => $bootstrapResponseSha256,
                    'scan_count' => count($rawEntries),
                    'declared_scan_count' => $expectedCount,
                    'scan_count_source' => $expectedCount === null ? 'enumerated_catalog' : 'declared_and_verified',
                    'page_count' => $pageNumber,
                    'page_response_sha256' => $pageHashes,
                    'page_entry_count' => $pageEntryCounts,
                    'observed_page_size' => $observedPageSize,
                    'unit_metadata' => $unitMetadata,
                ],
            ),
        );

        $this->catalogCache[$cacheKey] = $catalog;
        return $catalog;
    }

    public function resolve(ResolveScanRequest $request): ScanResolution
    {
        $directToken = $this->publicScanToken($request->resource);
        if ($directToken !== null) {
            $scan = $this->directAvailableScan($request->resource, $directToken);
            $provenance = $this->directProvenance($request->resource, $directToken);

            return new ScanResolution(
                status: ScanResolutionStatus::Resolved,
                providerKey: self::KEY,
                request: $request,
                candidates: [$scan],
                resolved: new ResolvedScan(
                    providerKey: self::KEY,
                    resource: $request->resource,
                    scan: $scan,
                    strategy: self::PUBLIC_SCAN_VIEWER_STRATEGY,
                    matchedHintRaw: $request->resource->url,
                    catalogProvenance: $provenance,
                ),
                strategy: self::PUBLIC_SCAN_VIEWER_STRATEGY,
                trace: ['Official public scan viewer URL is already an exact scan locator; raw image acquisition may require browser-aware transport.'],
            );
        }

        if (!$this->supports($request->resource)) {
            return new ScanResolution(
                status: ScanResolutionStatus::Unsupported,
                providerKey: self::KEY,
                request: $request,
                reason: 'resource_not_supported',
                trace: ['Resource is not a supported Szukaj w Archiwach unit or public scan URL.'],
            );
        }

        $preflight = $this->ordinalResolver->preflight($request);
        if ($preflight !== null) {
            return $preflight;
        }

        return $this->ordinalResolver->resolve(
            $request,
            $this->discoverScans($request->resource),
        );
    }

    public function download(ResolvedScan $scan, ScanAssetStorageInterface $storage): DownloadedScan
    {
        return $this->assetDownloader->download($scan, $storage);
    }

    private function directAvailableScan(ScanResourceReference $resource, string $token): AvailableScan
    {
        return new AvailableScan(
            providerKey: self::KEY,
            remoteId: $token,
            label: 'Szukaj w Archiwach scan viewer',
            remoteFilename: '',
            viewerUrl: $this->publicScanViewerUrl($token),
            locators: [new ScanLocator(ScanLocator::OPAQUE, 'public-scan-viewer:' . $token)],
            metadata: [
                'public_scan_viewer_token' => $token,
                'public_scan_viewer_url' => true,
                'remote_filename_available' => false,
            ],
        );
    }

    private function directProvenance(ScanResourceReference $resource, string $token): ScanProvenance
    {
        return new ScanProvenance(
            providerKey: self::KEY,
            providerVersion: self::VERSION,
            resourceUrl: $resource->url,
            retrievedAt: $this->clock->now()->format(DATE_ATOM),
            responseSha256: hash('sha256', "public-scan-viewer-locator\n" . $resource->url),
            details: [
                'discovery_strategy' => self::PUBLIC_SCAN_VIEWER_STRATEGY,
                'public_scan_viewer_token' => $token,
                'response_sha256_basis' => 'public_scan_viewer_locator_without_network_fetch',
            ],
        );
    }

    private function publicScanViewerUrl(string $token): string
    {
        return 'https://www.szukajwarchiwach.gov.pl/skan/-/skan/' . rawurlencode($token);
    }

    private function publicScanToken(ScanResourceReference $resource): ?string
    {
        $parts = parse_url($resource->url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($scheme !== 'https' || !in_array($host, ['www.szukajwarchiwach.gov.pl', 'szukajwarchiwach.gov.pl'], true)) {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (!preg_match('~^/skan/-/skan/([A-Za-z0-9_-]+)/?$~D', $path, $match)) {
            return null;
        }

        return $match[1];
    }

    private function fetchPage(string $url): HttpResponse
    {
        return $this->fetcher->get($url, [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
    }

    private function unitId(ScanResourceReference $resource): ?string
    {
        if (!in_array($resource->host(), ['www.szukajwarchiwach.gov.pl', 'szukajwarchiwach.gov.pl'], true)) {
            return null;
        }

        $path = (string) parse_url($resource->url, PHP_URL_PATH);
        if (!preg_match('~^/(?:en/|de/)?jednostka/-/jednostka/([1-9]\\d*)/?$~', $path, $match)) {
            return null;
        }

        return $match[1];
    }

    private function canonicalUnitUrl(string $unitId): string
    {
        return 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/' . $unitId;
    }

    private function catalogStartUrl(string $unitId): string
    {
        return $this->canonicalUnitUrl($unitId)
            . '?_Jednostka_delta=200'
            . '&_Jednostka_resetCur=false'
            . '&_Jednostka_cur=1'
            . '&_Jednostka_id_jednostki=' . rawurlencode($unitId);
    }

    private function objectViewerUrl(string $unitId, string $objectId): string
    {
        return $this->canonicalUnitUrl($unitId) . '/obiekty/' . $objectId;
    }

    private function assertUnitPageUrl(string $url, string $unitId): void
    {
        try {
            $resource = new ScanResourceReference($url);
        } catch (\InvalidArgumentException $exception) {
            throw new UnexpectedProviderResponseException('Szukaj w Archiwach pagination exposed an invalid URL.', 0, $exception);
        }

        if ($this->unitId($resource) !== $unitId) {
            throw new UnexpectedProviderResponseException(
                'Szukaj w Archiwach pagination escaped the current unit URL boundary.',
            );
        }
    }

    /**
     * @param array<string,string> $rawMetadata
     * @return array<string,mixed>
     */
    private function unitMetadata(?string $title, array $rawMetadata): array
    {
        $metadata = [
            'raw_fields' => $rawMetadata,
        ];
        if ($title !== null) {
            $metadata['title'] = $title;
        }
        $mapping = [
            'Sygnatura' => 'signature',
            'Reference code' => 'signature',
            'Daty skrajne' => 'dates',
            'Daty' => 'dates',
            'Dates' => 'dates',
            'Archiwum' => 'archive',
            'Archive' => 'archive',
            'Zespół' => 'fonds',
            'Zespol' => 'fonds',
            'Fonds' => 'fonds',
            'Collection' => 'fonds',
            'Opis' => 'description',
            'Description' => 'description',
            'Prawa' => 'access_rights',
            'Rights' => 'access_rights',
            'Warunki udostępniania' => 'access_rights',
            'Dostęp' => 'access_rights',
            'Access' => 'access_rights',
        ];

        foreach ($rawMetadata as $label => $value) {
            if (isset($mapping[$label]) && !isset($metadata[$mapping[$label]])) {
                $metadata[$mapping[$label]] = $value;
            }
        }

        return $metadata;
    }

    private function syntheticNextCatalogPageUrl(
        string $pageUrl,
        int $entryCount,
        ?int $observedPageSize,
    ): ?string {
        $query = \MyTree\ScanProviders\Support\Url::query($pageUrl);
        $currentPage = $this->positiveQueryInt($query['_Jednostka_cur'] ?? null);
        $unitId = $query['_Jednostka_id_jednostki'] ?? null;

        if (
            $currentPage === null
            || $observedPageSize === null
            || $observedPageSize < 1
            || !is_scalar($unitId)
            || (string) $unitId === ''
            || $entryCount < $observedPageSize
        ) {
            return null;
        }

        $parts = parse_url($pageUrl);
        if (!is_array($parts)) {
            return null;
        }

        $query['_Jednostka_cur'] = (string) ($currentPage + 1);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? 'www.szukajwarchiwach.gov.pl');
        $path = (string) ($parts['path'] ?? '');

        return $scheme . '://' . $host . $path . '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    private function positiveQueryInt(mixed $value): ?int
    {
        if (!is_scalar($value) || preg_match('~^[1-9]\\d*$~', (string) $value) !== 1) {
            return null;
        }

        $validated = filter_var((string) $value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($validated) ? $validated : null;
    }

    private function catalogDiagnostics(?string $url, string $body): string
    {
        $markers = [];
        foreach (['data-plikid', 'skan_-id-pliku', 'load-photo-slider', 'jednostka-skan', 'Wpisy', '_Jednostka_'] as $marker) {
            $markers[] = $marker . '=' . (str_contains($body, $marker) ? 'yes' : 'no');
        }

        return sprintf(
            '[url=%s bytes=%d sha256=%s markers:%s]',
            $url ?? 'unknown',
            strlen($body),
            hash('sha256', $body),
            implode(',', $markers),
        );
    }

    private function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
