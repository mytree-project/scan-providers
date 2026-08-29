<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Support;

final class MimeTypeDetector
{
    public function detect(string $contents, ?string $contentTypeHeader = null): ?string
    {
        $header = strtolower(trim((string) $contentTypeHeader));
        if ($header !== '') {
            $header = trim(explode(';', $header, 2)[0]);
            if (str_starts_with($header, 'image/')) {
                return $header;
            }
        }

        if (str_starts_with($contents, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($contents, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (strlen($contents) >= 12 && substr($contents, 0, 4) === 'RIFF' && substr($contents, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (str_starts_with($contents, "II*\x00") || str_starts_with($contents, "MM\x00*")) {
            return 'image/tiff';
        }

        return null;
    }
}
