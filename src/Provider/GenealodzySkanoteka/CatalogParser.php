<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\GenealodzySkanoteka;

use MyTree\ScanProviders\Domain\AvailableScan;
use MyTree\ScanProviders\Resolution\ActNumberLocatorParser;
use MyTree\ScanProviders\Support\HtmlLinkExtractor;
use MyTree\ScanProviders\Support\Url;

final readonly class CatalogParser
{
    public function __construct(
        private HtmlLinkExtractor $links = new HtmlLinkExtractor(),
        private ActNumberLocatorParser $locatorParser = new ActNumberLocatorParser(),
    ) {
    }

    /** @return list<AvailableScan> */
    public function parse(string $html, string $resourceUrl, string $providerKey): array
    {
        $result = [];
        $seen = [];
        foreach ($this->links->anchors($html) as $anchor) {
            $viewerUrl = Url::resolve($resourceUrl, $anchor['href']);
            if ($viewerUrl === null) {
                continue;
            }
            $query = Url::query($viewerUrl);
            $rawFilename = $query['plik'] ?? null;
            if (!is_scalar($rawFilename) || trim((string) $rawFilename) === '') {
                continue;
            }
            $filename = basename(trim((string) $rawFilename));
            if ($filename === '' || isset($seen[$viewerUrl])) {
                continue;
            }
            $seen[$viewerUrl] = true;
            $result[] = new AvailableScan(
                providerKey: $providerKey,
                remoteId: hash('sha256', $viewerUrl),
                label: $anchor['text'] !== '' ? $anchor['text'] : $filename,
                remoteFilename: $filename,
                viewerUrl: $viewerUrl,
                locators: $this->locatorParser->fromFilename($filename),
            );
        }

        return $result;
    }
}
