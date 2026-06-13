<?php

declare(strict_types=1);

namespace App\Services\Products;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

class ProductImageThumbnailService
{
    private const MIN_DIMENSION = 40;

    private const MAX_DIMENSION = 600;

    private const MAX_SOURCE_BYTES = 12582912;

    public function thumbnailUrl(?string $source, int $width, int $height): ?string
    {
        $source = $this->normalizeSource($source);

        if ($source === null) {
            return null;
        }

        $width = $this->normalizeDimension($width);
        $height = $this->normalizeDimension($height);

        if ($this->isLocalSource($source)) {
            $path = $this->cachedThumbnailPath($source, $width, $height);

            return $path !== null ? '/'.ltrim($this->publicRelativePath($path), '/') : $source;
        }

        if (! $this->isRemoteSource($source)) {
            return $source;
        }

        $encodedSource = $this->encodeSource($source);

        return route('products.image-thumbnail', [
            'src' => $encodedSource,
            'w' => $width,
            'h' => $height,
            'sig' => $this->signature($encodedSource, $width, $height),
        ], false);
    }

    public function sourceFromSignedRequest(string $encodedSource, int $width, int $height, string $signature): ?string
    {
        $width = $this->normalizeDimension($width);
        $height = $this->normalizeDimension($height);

        if (! hash_equals($this->signature($encodedSource, $width, $height), $signature)) {
            return null;
        }

        return $this->decodeSource($encodedSource);
    }

    public function cachedThumbnailPath(string $source, int $width, int $height): ?string
    {
        $source = $this->normalizeSource($source);

        if ($source === null || ! extension_loaded('gd')) {
            return null;
        }

        $width = $this->normalizeDimension($width);
        $height = $this->normalizeDimension($height);
        $cachePath = $this->cachePath($source, $width, $height);

        if (is_file($cachePath)) {
            return $cachePath;
        }

        $contents = $this->isLocalSource($source)
            ? $this->localSourceContents($source)
            : $this->remoteSourceContents($source);

        if ($contents === null) {
            return null;
        }

        File::ensureDirectoryExists(dirname($cachePath), 0755, true);

        return $this->writeThumbnail($contents, $cachePath, $width, $height) ? $cachePath : null;
    }

    private function normalizeSource(?string $source): ?string
    {
        $source = trim((string) $source);

        return $source === '' ? null : $source;
    }

    private function normalizeDimension(int $dimension): int
    {
        return max(self::MIN_DIMENSION, min(self::MAX_DIMENSION, $dimension));
    }

    private function isLocalSource(string $source): bool
    {
        if (str_starts_with($source, '/')) {
            return true;
        }

        $sourceHost = parse_url($source, PHP_URL_HOST);

        if (! is_string($sourceHost) || $sourceHost === '') {
            return false;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($appHost)
            && $appHost !== ''
            && strcasecmp($sourceHost, $appHost) === 0;
    }

    private function isRemoteSource(string $source): bool
    {
        return in_array(parse_url($source, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    private function localSourceContents(string $source): ?string
    {
        $path = parse_url($source, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return null;
        }

        $relativePath = ltrim(rawurldecode($path), '/');

        if (! str_starts_with($relativePath, 'uploads/')) {
            return null;
        }

        $uploadsRoot = realpath(public_path('uploads'));
        $absolutePath = realpath(public_path($relativePath));

        if ($uploadsRoot === false || $absolutePath === false) {
            return null;
        }

        if ($absolutePath !== $uploadsRoot && ! str_starts_with($absolutePath, $uploadsRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (! is_file($absolutePath) || filesize($absolutePath) > self::MAX_SOURCE_BYTES) {
            return null;
        }

        $contents = @file_get_contents($absolutePath);

        return is_string($contents) ? $contents : null;
    }

    private function remoteSourceContents(string $source): ?string
    {
        if (! $this->isRemoteSource($source)) {
            return null;
        }

        try {
            $response = Http::timeout(6)
                ->withHeaders(['Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8'])
                ->get($source);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $contents = $response->body();

        if ($contents === '' || strlen($contents) > self::MAX_SOURCE_BYTES) {
            return null;
        }

        return $contents;
    }

    private function writeThumbnail(string $contents, string $cachePath, int $width, int $height): bool
    {
        if (@getimagesizefromstring($contents) === false) {
            return false;
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return false;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($source);

            return false;
        }

        $targetRatio = $width / $height;
        $sourceRatio = $sourceWidth / $sourceHeight;
        $cropX = 0;
        $cropY = 0;
        $cropWidth = $sourceWidth;
        $cropHeight = $sourceHeight;

        if ($sourceRatio > $targetRatio) {
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $cropX = (int) floor(($sourceWidth - $cropWidth) / 2);
        } elseif ($sourceRatio < $targetRatio) {
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $cropY = (int) floor(($sourceHeight - $cropHeight) / 2);
        }

        $thumbnail = imagecreatetruecolor($width, $height);

        if ($thumbnail === false) {
            imagedestroy($source);

            return false;
        }

        $background = imagecolorallocate($thumbnail, 255, 255, 255);
        imagefilledrectangle($thumbnail, 0, 0, $width, $height, $background);

        $resampled = imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            $cropX,
            $cropY,
            $width,
            $height,
            $cropWidth,
            $cropHeight,
        );

        $written = $resampled && imagejpeg($thumbnail, $cachePath, 76);

        imagedestroy($thumbnail);
        imagedestroy($source);

        return $written;
    }

    private function cachePath(string $source, int $width, int $height): string
    {
        $fingerprint = $source;

        if ($this->isLocalSource($source)) {
            $path = parse_url($source, PHP_URL_PATH);
            $absolutePath = is_string($path) ? realpath(public_path(ltrim(rawurldecode($path), '/'))) : false;

            if (is_string($absolutePath) && is_file($absolutePath)) {
                $fingerprint .= '|'.filemtime($absolutePath).'|'.filesize($absolutePath);
            }
        }

        return public_path($this->thumbnailDirectory($width, $height).'/'.sha1($fingerprint).'.jpg');
    }

    private function thumbnailDirectory(int $width, int $height): string
    {
        $base = app()->environment('testing') ? 'uploads/testing-product-thumbnails' : 'uploads/product-thumbnails';

        return $base.'/'.$width.'x'.$height;
    }

    private function publicRelativePath(string $path): string
    {
        return ltrim(str_replace(public_path(), '', $path), DIRECTORY_SEPARATOR);
    }

    private function encodeSource(string $source): string
    {
        return rtrim(strtr(base64_encode($source), '+/', '-_'), '=');
    }

    private function decodeSource(string $encodedSource): ?string
    {
        $padding = strlen($encodedSource) % 4;

        if ($padding > 0) {
            $encodedSource .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($encodedSource, '-_', '+/'), true);

        return is_string($decoded) ? $this->normalizeSource($decoded) : null;
    }

    private function signature(string $encodedSource, int $width, int $height): string
    {
        return hash_hmac(
            'sha256',
            $encodedSource.'|'.$width.'|'.$height,
            (string) config('app.key'),
        );
    }
}
