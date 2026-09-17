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

    /** @var array<string,ScanCatalog> */
    private array $catalogCache = [];

    private readonly RetryingHttpFetcher $fetcher;
    private readonly SzukajWArchiwachAssetDownloader $assetDownloader;

    public function __construct(
        private readonly HttpClientInterface $http,
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
            maxAttempts: $this->maxAttempts,
            retryBackoffMilliseconds: $this->retryBackoffMilliseconds,
        );
        $this->assetDownloader = $assetDownloader ?? new SzukajWArchiwachAssetDownloader(
            fetcher: $this->fetcher,
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
        return $this->unitId($resource) !== null;
    }

    public function discoverScans(ScanResourceReference $resource): ScanCatalog
    {
        $unitId = $this->unitId($resource);
        if ($unitId === null) {
            throw new \InvalidArgumentException('Resource is not a supported Szukaj w Archiwach unit URL.');
        }

        $cacheKey = $resource->url;
        if (isset($this->catalogCache[$cacheKey])) {
            return $this->catalogCache[$cacheKey];
        }

        $unitUrl = $this->canonicalUnitUrl($unitId);
        $nextPageUrl = $unitUrl;
        $visited = [];
        $rawEntries = [];
        $seenObjectIds = [];
        $pageHashes = [];
        $responseCorpus = '';
        $firstPage = null;
        $expectedCount = null;
        $pageNumber = 0;

        while ($nextPageUrl !== null) {
            ++$pageNumber;
            if ($pageNumber > $this->maxPages) {
                throw new UnexpectedProviderResponseException('Szukaj w Archiwach catalog exceeded the configured pagination limit.');
            }
            if (isset($visited[$nextPageUrl])) {
                throw new UnexpectedProviderResponseException('Szukaj w Archiwach catalog pagination entered a URL loop.');
            }
            $visited[$nextPageUrl] = true;
            $this->assertUnitPageUrl($nextPageUrl, $unitId);

            if ($pageNumber > 1) {
                $this->sleepMilliseconds($this->requestPacingMilliseconds);
            }

            $response = $this->fetchPage($nextPageUrl);
            $parsed = $this->parser->parse($response->body, $nextPageUrl);
            $firstPage ??= $parsed;
            $expectedCount ??= $parsed->scanCount;
            if ($parsed->scanCount !== $expectedCount) {
                throw new UnexpectedProviderResponseException('Szukaj w Archiwach scan cardinality changed between catalog pages.');
            }

            $pageHash = hash('sha256', $response->body);
            $pageHashes[$nextPageUrl] = $pageHash;
            $responseCorpus .= $nextPageUrl . "\n" . $response->body . "\n";

            foreach ($parsed->scanEntries as $entry) {
                if (isset($seenObjectIds[$entry['object_id']])) {
                    throw new UnexpectedProviderResponseException(
                        'Szukaj w Archiwach catalog repeated provider object id ' . $entry['object_id'] . '.',
                    );
                }
                $seenObjectIds[$entry['object_id']] = true;
                $rawEntries[] = $entry;
            }

            $nextPageUrl = $parsed->nextPageUrl;
        }

        if ($firstPage === null || $expectedCount === null) {
            throw new UnexpectedProviderResponseException('Szukaj w Archiwach catalog did not return a parseable first page.');
        }
        if ($firstPage->title === null) {
            throw new UnexpectedProviderResponseException('Szukaj w Archiwach unit page did not expose a recognizable unit title.');
        }
        if (count($rawEntries) !== $expectedCount) {
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
                    'scan_count' => $expectedCount,
                    'page_count' => $pageNumber,
                    'page_response_sha256' => $pageHashes,
                    'unit_metadata' => $unitMetadata,
                ],
            ),
        );

        $this->catalogCache[$cacheKey] = $catalog;
        return $catalog;
    }

    public function resolve(ResolveScanRequest $request): ScanResolution
    {
        if (!$this->supports($request->resource)) {
            return new ScanResolution(
                status: ScanResolutionStatus::Unsupported,
                providerKey: self::KEY,
                request: $request,
                reason: 'resource_not_supported',
                trace: ['Resource is not a supported Szukaj w Archiwach unit URL.'],
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

    private function fetchPage(string $url): HttpResponse
    {
        return $this->fetcher->get($url, [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
    }

    private function unitId(ScanResourceReference $resource): ?string
    {
        if ($resource->host() !== 'www.szukajwarchiwach.gov.pl') {
            return null;
        }

        $path = (string) parse_url($resource->url, PHP_URL_PATH);
        if (!preg_match('~^/jednostka/-/jednostka/([1-9]\\d*)/?$~', $path, $match)) {
            return null;
        }

        return $match[1];
    }

    private function canonicalUnitUrl(string $unitId): string
    {
        return 'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/' . $unitId;
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
    private function unitMetadata(string $title, array $rawMetadata): array
    {
        $metadata = [
            'title' => $title,
            'raw_fields' => $rawMetadata,
        ];
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

    private function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
