<?php

declare(strict_types=1);

namespace MyTree\ScanProviders\Infrastructure;

use MyTree\ScanProviders\Contracts\ScanAssetStorageInterface;
use MyTree\ScanProviders\Domain\StoredScanAsset;
use MyTree\ScanProviders\Exception\ScanProviderException;

final readonly class LocalFilesystemScanAssetStorage implements ScanAssetStorageInterface
{
    public function __construct(private string $directory)
    {
    }

    public function store(string $suggestedFilename, string $contents): StoredScanAsset
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new ScanProviderException('Cannot create scan output directory: ' . $this->directory);
        }

        $filename = $this->safeFilename($suggestedFilename);
        $sha256 = hash('sha256', $contents);
        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        if (is_file($path)) {
            $existingHash = hash_file('sha256', $path);
            if ($existingHash !== $sha256) {
                $pathInfo = pathinfo($filename);
                $extension = isset($pathInfo['extension']) ? '.' . $pathInfo['extension'] : '';
                $stem = $pathInfo['filename'] ?? 'scan';
                $filename = $stem . '-' . substr($sha256, 0, 8) . $extension;
                $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
            }
        }

        if (!is_file($path) && file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new ScanProviderException('Cannot store scan asset: ' . $path);
        }

        return new StoredScanAsset($path, $filename, strlen($contents), $sha256);
    }

    private function safeFilename(string $filename): string
    {
        $filename = basename(trim($filename));
        $filename = preg_replace('~[^A-Za-z0-9._-]+~', '_', $filename) ?? '';
        $filename = trim($filename, '._-');
        return $filename !== '' ? $filename : 'scan.bin';
    }
}
