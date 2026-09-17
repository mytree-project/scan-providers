<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Provider\SzukajWArchiwach;

use MyTree\ScanProviders\Exception\UnexpectedProviderResponseException;
use MyTree\ScanProviders\Support\Url;

final class CatalogPageParser
{
    public function parse(string $html, string $pageUrl): ParsedCatalogPage
    {
        $scanCount = $this->scanCount($html);
        if ($scanCount === null) {
            throw new UnexpectedProviderResponseException(
                'Szukaj w Archiwach unit page did not expose a recognizable "Skany (N)" / "Scans (N)" count.',
            );
        }

        return new ParsedCatalogPage(
            scanCount: $scanCount,
            title: $this->title($html),
            rawMetadata: $this->metadata($html),
            scanEntries: $this->scanEntries($html),
            nextPageUrl: $this->nextPageUrl($html, $pageUrl),
        );
    }

    private function scanCount(string $html): ?int
    {
        $text = $this->text($html);
        if (!preg_match('~(?:Skany|Scans)\s*\(\s*(\d+)\s*\)~iu', $text, $match)) {
            return null;
        }

        return (int) $match[1];
    }

    private function title(string $html): ?string
    {
        if (!preg_match(
            '~<div\b[^>]*class=(?:["\'][^"\']*\btytulJednostki\b[^"\']*["\'])[^>]*>.*?<h2\b[^>]*>(.*?)</h2>~isu',
            $html,
            $match,
        )) {
            return null;
        }

        $title = $this->text($match[1]);
        return $title === '' ? null : $title;
    }

    /** @return array<string,string> */
    private function metadata(string $html): array
    {
        $result = [];
        if (!preg_match_all(
            '~<div\b[^>]*class=(?:["\'][^"\']*\btitle\b[^"\']*["\'])[^>]*>(.*?)</div>\s*<div\b[^>]*class=(?:["\'][^"\']*\bvalue\b[^"\']*["\'])[^>]*>(.*?)</div>~isu',
            $html,
            $matches,
            PREG_SET_ORDER,
        )) {
            return [];
        }

        foreach ($matches as $match) {
            $label = $this->text($match[1]);
            $value = $this->text($match[2]);
            if ($label !== '' && $value !== '') {
                $result[$label] = $value;
            }
        }

        return $result;
    }

    /** @return list<array{object_id:string,label:string}> */
    private function scanEntries(string $html): array
    {
        $entries = [];
        if (!preg_match_all('~<a\b([^>]*)>(.*?)</a>~isu', $html, $anchors, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($anchors as $anchor) {
            $class = $this->attribute($anchor[1], 'class');
            if ($class === null || !$this->hasClass($class, 'load-photo-slider')) {
                continue;
            }

            $objectId = $this->attribute($anchor[1], 'data-plikid');
            if ($objectId === null || !preg_match('~^[1-9]\d*$~', $objectId)) {
                throw new UnexpectedProviderResponseException(
                    'Szukaj w Archiwach scan entry did not expose a positive numeric data-plikid object locator.',
                );
            }

            $entries[] = [
                'object_id' => $objectId,
                'label' => $this->text($anchor[2]),
            ];
        }

        return $entries;
    }

    private function nextPageUrl(string $html, string $pageUrl): ?string
    {
        if (!preg_match_all('~<a\b([^>]*)>(.*?)</a>~isu', $html, $anchors, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($anchors as $anchor) {
            $ariaLabel = $this->attribute($anchor[1], 'aria-label');
            $isNext = preg_match('~\bicon-caret-right\b~iu', $anchor[2]) === 1
                || in_array(strtolower(trim((string) $ariaLabel)), ['next', 'next page'], true);
            if (!$isNext) {
                continue;
            }

            $href = $this->attribute($anchor[1], 'href');
            if ($href === null) {
                return null;
            }

            return Url::resolve($pageUrl, $href);
        }

        return null;
    }

    private function attribute(string $attributes, string $name): ?string
    {
        if (preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*(["\'])(.*?)\1~isu', $attributes, $match)) {
            return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('~\b' . preg_quote($name, '~') . '\s*=\s*([^\s>]+)~isu', $attributes, $match)) {
            return html_entity_decode(trim($match[1], "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    private function hasClass(string $classes, string $expected): bool
    {
        return in_array($expected, preg_split('~\s+~u', trim($classes)) ?: [], true);
    }

    private function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }
}
