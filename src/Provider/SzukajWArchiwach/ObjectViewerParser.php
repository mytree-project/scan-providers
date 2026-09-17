<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Support\Url;

final class ObjectViewerParser
{
    public function publicScanUrl(string $html, string $viewerUrl): ?string
    {
        if (!preg_match_all('~<[a-z][^>]*>~isu', $html, $tags)) {
            return null;
        }

        $candidates = [];
        foreach ($tags[0] as $tag) {
            foreach ($this->attributeValues($tag) as $reference) {
                $url = Url::resolve($viewerUrl, $reference);
                if ($url === null || !$this->isPublicScanUrl($url)) {
                    continue;
                }

                $candidates[$url] = true;
            }
        }

        $urls = array_keys($candidates);
        if (count($urls) > 1) {
            throw new UnexpectedProviderResponseException(
                'Szukaj w Archiwach object viewer exposed multiple distinct public scan links.',
            );
        }

        return $urls[0] ?? null;
    }

    private function isPublicScanUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if ($scheme !== 'https') {
            return false;
        }
        if (!in_array($host, ['www.szukajwarchiwach.gov.pl', 'szukajwarchiwach.gov.pl'], true)) {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return preg_match('~^/skan/-/skan/[A-Za-z0-9_-]+/?$~D', $path) === 1;
    }

    /** @return list<string> */
    private function attributeValues(string $tag): array
    {
        $values = [];
        if (!preg_match_all(
            '~\b[A-Za-z_:][-A-Za-z0-9_:.]*\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))~isu',
            $tag,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        foreach ($matches as $match) {
            $raw = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            if ($raw === '') {
                continue;
            }
            $values[] = html_entity_decode(trim($raw, "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $values;
    }
}
