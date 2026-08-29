<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Support;

final class HtmlLinkExtractor
{
    /** @return list<array{href:string,text:string}> */
    public function anchors(string $html): array
    {
        $result = [];
        if (!preg_match_all('~<a\\b([^>]*)>(.*?)</a>~isu', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($matches as $match) {
            $href = $this->attribute($match[1], 'href');
            if ($href === null) {
                continue;
            }
            $result[] = [
                'href' => $href,
                'text' => $this->text($match[2]),
            ];
        }
        return $result;
    }

    /** @return list<string> */
    public function imageSources(string $html): array
    {
        $result = [];
        if (!preg_match_all('~<img\\b([^>]*)>~isu', $html, $matches, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($matches as $match) {
            $src = $this->attribute($match[1], 'src');
            if ($src !== null) {
                $result[] = $src;
            }
        }
        return $result;
    }

    private function attribute(string $attributes, string $name): ?string
    {
        if (preg_match('~\\b' . preg_quote($name, '~') . '\\s*=\\s*(["\\\'])(.*?)\\1~isu', $attributes, $match)) {
            return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('~\\b' . preg_quote($name, '~') . '\\s*=\\s*([^\\s>]+)~isu', $attributes, $match)) {
            return html_entity_decode(trim($match[1], "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return null;
    }

    private function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('~\\s+~u', ' ', $text) ?? $text);
    }
}
