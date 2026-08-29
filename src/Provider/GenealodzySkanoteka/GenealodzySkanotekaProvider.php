<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\GenealodzySkanoteka;

use MyTree\ScanProviders\Contracts\ClockInterface;
use MyTree\ScanProviders\Contracts\HttpClientInterface;
use MyTree\ScanProviders\Contracts\ScanAssetStorageInterface;
use MyTree\ScanProviders\Contracts\ScanCatalogDiscoveryInterface;
use MyTree\ScanProviders\Contracts\ScanProviderInterface;
use MyTree\ScanProviders\Domain\DownloadedScan;
use MyTree\ScanProviders\Domain\ResolveScanRequest;
use MyTree\ScanProviders\Domain\ResolvedScan;
use MyTree\ScanProviders\Domain\ScanCatalog;
use MyTree\ScanProviders\Domain\ScanProvenance;
use MyTree\ScanProviders\Domain\ScanResolution;
use MyTree\ScanProviders\Domain\ScanResolutionStatus;
use MyTree\ScanProviders\Domain\ScanResourceReference;
use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Infrastructure\SystemClock;
use MyTree\ScanProviders\Resolution\ActNumberMatcher;
use MyTree\ScanProviders\Support\MimeTypeDetector;

final class GenealodzySkanotekaProvider implements ScanProviderInterface, ScanCatalogDiscoveryInterface
{
    public const KEY = 'genealodzy-skanoteka';
    public const VERSION = '0.1.0';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CatalogParser $catalogParser = new CatalogParser(),
        private readonly DownloadLinkParser $downloadLinkParser = new DownloadLinkParser(),
        private readonly ActNumberMatcher $actNumberMatcher = new ActNumberMatcher(),
        private readonly MimeTypeDetector $mimeTypeDetector = new MimeTypeDetector(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function supports(ScanResourceReference $resource): bool
    {
        return $resource->host() === 'metryki.genealodzy.pl';
    }

    public function discoverScans(ScanResourceReference $resource): ScanCatalog
    {
        $this->assertSupported($resource);
        $response = $this->http->get($resource->url, [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ]);
        $this->assertSuccess($response->status, $resource->url);

        $scans = $this->catalogParser->parse($response->body, $resource->url, self::KEY);
        if ($scans === []) {
            throw new UnexpectedProviderResponseException(
                'Genealodzy Skanoteka catalog did not contain recognizable scan links (expected URL parameter "plik").',
            );
        }

        return new ScanCatalog(
            providerKey: self::KEY,
            resource: $resource,
            scans: $scans,
            provenance: new ScanProvenance(
                providerKey: self::KEY,
                providerVersion: self::VERSION,
                resourceUrl: $resource->url,
                retrievedAt: $this->clock->now()->format(DATE_ATOM),
                responseSha256: hash('sha256', $response->body),
                details: ['discovery_strategy' => 'catalog_plik_links'],
            ),
        );
    }

    public function resolve(ResolveScanRequest $request): ScanResolution
    {
        if (!$this->supports($request->resource)) {
            return new ScanResolution(
                status: ScanResolutionStatus::Unsupported,
                providerKey: self::KEY,
                request: $request,
                reason: 'resource_not_supported',
                trace: ['Host is not supported by this provider.'],
            );
        }

        $catalog = $this->discoverScans($request->resource);
        $recordNumber = $this->actNumberMatcher->parseRecordNumber($request->hints->recordNumberRaw);
        if ($recordNumber === null) {
            return new ScanResolution(
                status: ScanResolutionStatus::Unresolved,
                providerKey: self::KEY,
                request: $request,
                reason: $request->hints->recordNumberRaw === null ? 'missing_record_number' : 'unsupported_record_number_format',
                trace: [
                    'Catalog discovered ' . count($catalog->scans) . ' scan(s).',
                    'Act-number resolution requires a positive numeric record number.',
                ],
            );
        }

        $matches = $this->actNumberMatcher->matching($recordNumber, $catalog->scans);
        if ($matches === []) {
            return new ScanResolution(
                status: ScanResolutionStatus::Unresolved,
                providerKey: self::KEY,
                request: $request,
                strategy: ActNumberMatcher::STRATEGY,
                reason: 'no_matching_act_number_locator',
                trace: [
                    'Catalog discovered ' . count($catalog->scans) . ' scan(s).',
                    "No exact/range filename locator contains act number $recordNumber.",
                ],
            );
        }
        if (count($matches) > 1) {
            return new ScanResolution(
                status: ScanResolutionStatus::Ambiguous,
                providerKey: self::KEY,
                request: $request,
                candidates: $matches,
                strategy: ActNumberMatcher::STRATEGY,
                reason: 'multiple_matching_act_number_locators',
                trace: [
                    'Catalog discovered ' . count($catalog->scans) . ' scan(s).',
                    count($matches) . " scan candidates contain act number $recordNumber.",
                ],
            );
        }

        $resolved = new ResolvedScan(
            providerKey: self::KEY,
            resource: $request->resource,
            scan: $matches[0],
            strategy: ActNumberMatcher::STRATEGY,
            matchedHintRaw: $request->hints->recordNumberRaw,
            catalogProvenance: $catalog->provenance,
        );

        return new ScanResolution(
            status: ScanResolutionStatus::Resolved,
            providerKey: self::KEY,
            request: $request,
            candidates: $matches,
            resolved: $resolved,
            strategy: ActNumberMatcher::STRATEGY,
            trace: [
                'Catalog discovered ' . count($catalog->scans) . ' scan(s).',
                "Exactly one scan locator contains act number $recordNumber.",
            ],
        );
    }

    public function download(ResolvedScan $scan, ScanAssetStorageInterface $storage): DownloadedScan
    {
        if ($scan->providerKey !== self::KEY) {
            throw new \InvalidArgumentException('Resolved scan belongs to a different provider.');
        }

        $viewer = $this->http->get($scan->scan->viewerUrl, [
            'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
            'Referer' => $scan->resource->url,
        ]);
        $this->assertSuccess($viewer->status, $scan->scan->viewerUrl);

        $downloadUrl = $this->downloadLinkParser->parse(
            $viewer->body,
            $scan->scan->viewerUrl,
            $scan->scan->remoteFilename,
        );
        if ($downloadUrl === null) {
            throw new UnexpectedProviderResponseException(
                'Genealodzy Skanoteka scan page did not expose a recognizable image/download link.',
            );
        }

        $binary = $this->http->get($downloadUrl, [
            'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
            'Referer' => $scan->scan->viewerUrl,
        ]);
        $this->assertSuccess($binary->status, $downloadUrl);
        $mimeType = $this->mimeTypeDetector->detect($binary->body, $binary->firstHeader('content-type'));
        if ($mimeType === null) {
            throw new UnexpectedProviderResponseException('Downloaded response is not a recognized image asset.');
        }

        $stored = $storage->store($scan->scan->remoteFilename, $binary->body);
        return new DownloadedScan(
            providerKey: self::KEY,
            asset: $stored,
            mimeType: $mimeType,
            resourceUrl: $scan->resource->url,
            viewerUrl: $scan->scan->viewerUrl,
            downloadUrl: $downloadUrl,
            retrievedAt: $this->clock->now()->format(DATE_ATOM),
            resolutionStrategy: $scan->strategy,
            catalogProvenance: $scan->catalogProvenance,
        );
    }

    private function assertSupported(ScanResourceReference $resource): void
    {
        if (!$this->supports($resource)) {
            throw new \InvalidArgumentException('Resource is not supported by Genealodzy Skanoteka provider.');
        }
    }

    private function assertSuccess(int $status, string $url): void
    {
        if ($status < 200 || $status >= 300) {
            throw new UnexpectedProviderResponseException("Provider returned HTTP $status for $url");
        }
    }
}
