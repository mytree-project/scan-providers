<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\GenealodzySkanoteka;

use MyTree\ScanProviders\Support\HtmlLinkExtractor;
use MyTree\ScanProviders\Support\Url;

final readonly class DownloadLinkParser
{
    public function __construct(private HtmlLinkExtractor $links = new HtmlLinkExtractor())
    {
    }

    public function parse(string $html, string $viewerUrl, string $remoteFilename): ?string
    {
        $ranked = [];
        foreach ($this->links->anchors($html) as $anchor) {
            $url = Url::resolve($viewerUrl, $anchor['href']);
            if ($url !== null && $url !== $viewerUrl) {
                $ranked[] = [$this->score($url, $remoteFilename, false), $url];
            }
        }
        foreach ($this->links->imageSources($html) as $src) {
            $url = Url::resolve($viewerUrl, $src);
            if ($url !== null) {
                $ranked[] = [$this->score($url, $remoteFilename, true), $url];
            }
        }

        $ranked = array_values(array_filter($ranked, static fn (array $item): bool => $item[0] > 0));
        if ($ranked === []) {
            return null;
        }
        usort($ranked, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        return $ranked[0][1];
    }

    private function score(string $url, string $remoteFilename, bool $imageElement): int
    {
        $query = Url::query($url);
        $filename = basename((string) parse_url($url, PHP_URL_PATH));
        $score = $imageElement ? 5 : 0;

        foreach (['skan', 'plik'] as $key) {
            if (isset($query[$key]) && is_scalar($query[$key])) {
                $score += 40;
                if (basename((string) $query[$key]) === $remoteFilename) {
                    $score += 50;
                }
            }
        }
        if ($filename === $remoteFilename) {
            $score += 100;
        } elseif (preg_match('~\\.(?:jpe?g|png|webp|tiff?)$~i', $filename)) {
            $score += 20;
        }

        return $score;
    }
}
