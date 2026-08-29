<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Support;

final class Url
{
    public static function resolve(string $baseUrl, string $reference): ?string
    {
        $reference = html_entity_decode(trim($reference), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($reference === '' || str_starts_with(strtolower($reference), 'javascript:')) {
            return null;
        }
        if (preg_match('~^https?://~i', $reference)) {
            return $reference;
        }

        $base = parse_url($baseUrl);
        if (!isset($base['scheme'], $base['host'])) {
            return null;
        }
        $origin = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) {
            $origin .= ':' . $base['port'];
        }
        if (str_starts_with($reference, '//')) {
            return $base['scheme'] . ':' . $reference;
        }
        if (str_starts_with($reference, '/')) {
            return $origin . $reference;
        }

        $basePath = $base['path'] ?? '/';
        if (str_ends_with($basePath, '/')) {
            $directory = $basePath;
        } else {
            $directory = rtrim(dirname($basePath), '/');
            $directory = ($directory === '' ? '' : $directory) . '/';
        }
        return $origin . $directory . $reference;
    }

    /** @return array<string,mixed> */
    public static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        return $query;
    }
}
