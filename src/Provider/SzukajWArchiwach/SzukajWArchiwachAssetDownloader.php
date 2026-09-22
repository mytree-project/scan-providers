<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

use MyTree\ScanProviders\Contracts\ClockInterface;
use MyTree\ScanProviders\Contracts\ScanAssetStorageInterface;
use MyTree\ScanProviders\Domain\DownloadedScan;
use MyTree\ScanProviders\Domain\HttpResponse;
use MyTree\ScanProviders\Domain\ResolvedScan;
use MyTree\ScanProviders\Exception\ScanCapabilityUnavailableException;
use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Infrastructure\SystemClock;
use MyTree\ScanProviders\Support\MimeTypeDetector;

final readonly class SzukajWArchiwachAssetDownloader
{
    public function __construct(
        private RetryingHttpFetcher $fetcher,
        private ?BrowserSessionClientInterface $browserSessionClient = null,
        private ObjectViewerParser $viewerParser = new ObjectViewerParser(),
        private MimeTypeDetector $mimeTypeDetector = new MimeTypeDetector(),
        private ClockInterface $clock = new SystemClock(),
        private int $requestPacingMilliseconds = 250,
    ) {
        if ($this->requestPacingMilliseconds < 0) {
            throw new \InvalidArgumentException('Szukaj w Archiwach request pacing cannot be negative.');
        }
    }

    public function download(ResolvedScan $scan, ScanAssetStorageInterface $storage): DownloadedScan
    {
        if ($scan->providerKey !== SzukajWArchiwachProvider::KEY) {
            throw new \InvalidArgumentException('Resolved scan belongs to a different provider.');
        }

        $directToken = $scan->scan->metadata['public_scan_viewer_token'] ?? null;
        if (($scan->scan->metadata['public_scan_viewer_url'] ?? false) === true && is_string($directToken)) {
            return $this->downloadPublicScanViewer($scan, $directToken, $storage);
        }

        [$unitId, $objectId] = $this->assertResolvedObject($scan);
        $objectViewerUrl = $scan->scan->viewerUrl;
        $viewer = $this->fetcher->get($objectViewerUrl, [
            'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
            'Referer' => $this->withoutFragment($scan->resource->url),
        ]);

        $scanViewerUrl = $this->viewerParser->publicScanUrl($viewer->body, $objectViewerUrl);
        if ($scanViewerUrl === null) {
            throw new UnexpectedProviderResponseException(
                'Szukaj w Archiwach object viewer did not expose a recognizable public /skan/-/skan/ link.',
            );
        }

        $this->sleepMilliseconds($this->requestPacingMilliseconds);
        $candidate = $this->fetcher->get($scanViewerUrl, [
            'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'Referer' => $objectViewerUrl,
        ]);
        [$binary, $mimeType] = $this->resolveImageResponse($candidate, $scanViewerUrl);

        $stored = $storage->store(
            $this->suggestedFilename($unitId, $objectId, $mimeType),
            $binary->body,
        );

        return new DownloadedScan(
            providerKey: SzukajWArchiwachProvider::KEY,
            asset: $stored,
            mimeType: $mimeType,
            resourceUrl: $scan->resource->url,
            viewerUrl: $objectViewerUrl,
            downloadUrl: $binary->url,
            retrievedAt: $this->clock->now()->format(DATE_ATOM),
            resolutionStrategy: $scan->strategy,
            catalogProvenance: $scan->catalogProvenance,
        );
    }

    private function downloadPublicScanViewer(
        ResolvedScan $scan,
        string $token,
        ScanAssetStorageInterface $storage,
    ): DownloadedScan {
        if (preg_match('~^[A-Za-z0-9_-]+$~D', $token) !== 1) {
            throw new \InvalidArgumentException('Resolved Szukaj w Archiwach public scan viewer token is invalid.');
        }

        $viewerUrl = $scan->scan->viewerUrl;
        $candidate = $this->fetcher->get($viewerUrl, [
            'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
        ]);
        [$binary, $mimeType] = $this->resolveImageResponse($candidate, $viewerUrl);

        $stored = $storage->store(
            $this->suggestedDirectFilename($token, $mimeType),
            $binary->body,
        );

        return new DownloadedScan(
            providerKey: SzukajWArchiwachProvider::KEY,
            asset: $stored,
            mimeType: $mimeType,
            resourceUrl: $scan->resource->url,
            viewerUrl: $viewerUrl,
            downloadUrl: $binary->url,
            retrievedAt: $this->clock->now()->format(DATE_ATOM),
            resolutionStrategy: $scan->strategy,
            catalogProvenance: $scan->catalogProvenance,
        );
    }

    /** @return array{0:HttpResponse,1:string} */
    private function resolveImageResponse(HttpResponse $response, string $viewerUrl): array
    {
        $mimeType = $this->mimeTypeDetector->detect($response->body, $response->firstHeader('content-type'));
        if ($mimeType !== null) {
            return [$response, $mimeType];
        }

        $contentType = strtolower((string) $response->firstHeader('content-type'));
        $bodyPrefix = strtolower(substr($response->body, 0, 16384));
        $isHtml = str_contains($contentType, 'text/html') || str_contains($bodyPrefix, '<html');

        if ($isHtml && $this->browserSessionClient !== null) {
            $browserResponse = $this->browserSessionClient->fetchScanImage($viewerUrl);
            $browserMimeType = $this->mimeTypeDetector->detect(
                $browserResponse->body,
                $browserResponse->firstHeader('content-type'),
            );
            if ($browserMimeType === null) {
                throw new UnexpectedProviderResponseException(
                    'Szukaj w Archiwach browser session did not return a recognized image asset.',
                );
            }

            return [$browserResponse, $browserMimeType];
        }

        if ($isHtml) {
            throw new ScanCapabilityUnavailableException(sprintf(
                'Szukaj w Archiwach public scan locator %s is an HTML viewer. '
                . 'Raw image acquisition requires browser-aware transport; '
                . 'browser-established session transport is not configured.',
                $viewerUrl,
            ));
        }

        throw new UnexpectedProviderResponseException(
            'Downloaded Szukaj w Archiwach response is not a recognized image asset.',
        );
    }

    private function suggestedDirectFilename(string $token, string $mimeType): string
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/tiff' => 'tif',
            default => 'bin',
        };

        return sprintf('szukajwarchiwach-scan-%s.%s', substr(hash('sha256', $token), 0, 24), $extension);
    }

    /** @return array{0:string,1:string} */
    private function assertResolvedObject(ResolvedScan $scan): array
    {
        $unitId = $scan->scan->metadata['unit_id'] ?? null;
        $objectId = $scan->scan->metadata['object_id'] ?? null;
        if (!is_string($unitId) || preg_match('~^[1-9]\\d*$~', $unitId) !== 1) {
            throw new \InvalidArgumentException('Resolved Szukaj w Archiwach scan does not contain a valid unit id.');
        }
        if (!is_string($objectId) || preg_match('~^[1-9]\\d*$~', $objectId) !== 1) {
            throw new \InvalidArgumentException('Resolved Szukaj w Archiwach scan does not contain a valid object id.');
        }

        $expectedViewerUrl = sprintf(
            'https://www.szukajwarchiwach.gov.pl/jednostka/-/jednostka/%s/obiekty/%s',
            $unitId,
            $objectId,
        );
        if ($scan->scan->viewerUrl !== $expectedViewerUrl) {
            throw new \InvalidArgumentException('Resolved Szukaj w Archiwach scan viewer URL does not match its unit/object locators.');
        }

        return [$unitId, $objectId];
    }

    private function suggestedFilename(string $unitId, string $objectId, string $mimeType): string
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/tiff' => 'tif',
            default => 'bin',
        };

        return sprintf('szukajwarchiwach-%s-object-%s.%s', $unitId, $objectId, $extension);
    }

    private function withoutFragment(string $url): string
    {
        $position = strpos($url, '#');
        return $position === false ? $url : substr($url, 0, $position);
    }

    private function sleepMilliseconds(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
