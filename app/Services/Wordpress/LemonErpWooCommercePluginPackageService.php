<?php

declare(strict_types=1);

namespace App\Services\Wordpress;

use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;

final class LemonErpWooCommercePluginPackageService
{
    private const PLUGIN_DIRECTORY = 'wordpress/lemon-erp-woocommerce';

    private const PLUGIN_SLUG = 'lemon-erp-woocommerce';

    /**
     * @return array{path:string,filename:string,version:string}
     */
    public function build(): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Na serwerze brakuje rozszerzenia PHP zip, więc nie można spakować wtyczki WordPress.');
        }

        $source = base_path(self::PLUGIN_DIRECTORY);
        $mainFile = $source.'/'.self::PLUGIN_SLUG.'.php';

        if (! File::isDirectory($source) || ! File::exists($mainFile)) {
            throw new RuntimeException('Nie znaleziono źródeł wtyczki Lemon ERP for WooCommerce.');
        }

        $version = $this->version($mainFile);
        $filename = self::PLUGIN_SLUG.'-'.$version.'.zip';
        $targetDirectory = storage_path('app/plugin-downloads');
        $target = $targetDirectory.'/'.$filename;

        File::ensureDirectoryExists($targetDirectory);

        if (File::exists($target)) {
            File::delete($target);
        }

        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Nie można utworzyć paczki ZIP wtyczki Lemon ERP.');
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relativePath = self::PLUGIN_SLUG.'/'.ltrim(str_replace($source, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $zip->addFile($file->getPathname(), str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
        }

        $zip->close();

        return [
            'path' => $target,
            'filename' => $filename,
            'version' => $version,
        ];
    }

    public function version(?string $mainFile = null): string
    {
        $mainFile ??= base_path(self::PLUGIN_DIRECTORY.'/'.self::PLUGIN_SLUG.'.php');
        $contents = File::exists($mainFile) ? File::get($mainFile) : '';

        if (preg_match('/^\s*\*\s*Version:\s*([^\r\n]+)/mi', $contents, $matches) === 1) {
            return preg_replace('/[^0-9A-Za-z._-]+/', '-', trim($matches[1])) ?: 'dev';
        }

        return 'dev';
    }
}
