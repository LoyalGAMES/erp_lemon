<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\AppSetting;

final class WarehouseDocumentSettingsService
{
    private const NUMBERING_KEY = 'warehouse_document_numbering';
    private const LOCATIONS_KEY = 'warehouse_locations';

    /**
     * @return array{pattern:string,padding:int}
     */
    public function numberingData(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::NUMBERING_KEY)
            ->value('value');

        $data = array_merge($this->defaultNumberingData(), is_array($stored) ? $stored : []);

        return [
            'pattern' => $this->cleanPattern((string) $data['pattern']),
            'padding' => max(3, min(9, (int) $data['padding'])),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{pattern:string,padding:int}
     */
    public function updateNumberingData(array $data): array
    {
        $payload = [
            'pattern' => $this->cleanPattern((string) ($data['pattern'] ?? '')),
            'padding' => max(3, min(9, (int) ($data['padding'] ?? 6))),
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::NUMBERING_KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    /**
     * @return list<string>
     */
    public function locations(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::LOCATIONS_KEY)
            ->value('value');

        $locations = is_array($stored) ? ($stored['items'] ?? []) : [];

        return collect(is_array($locations) ? $locations : [])
            ->map(fn (mixed $location): string => trim((string) $location))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $locations
     * @return list<string>
     */
    public function updateLocations(array $locations): array
    {
        $payload = collect($locations)
            ->map(fn (mixed $location): string => trim((string) $location))
            ->filter()
            ->unique()
            ->values()
            ->take(500)
            ->all();

        AppSetting::query()->updateOrCreate(
            ['key' => self::LOCATIONS_KEY],
            ['value' => ['items' => $payload]],
        );

        return $payload;
    }

    public function exampleNumber(string $type = 'PZ'): string
    {
        return $this->renderNumber($type, 1, now(), $this->numberingData());
    }

    /**
     * @param array{pattern:string,padding:int} $numbering
     */
    public function renderNumber(string $type, int $sequence, \DateTimeInterface $date, array $numbering): string
    {
        $sequenceValue = str_pad((string) $sequence, $numbering['padding'], '0', STR_PAD_LEFT);

        return strtr($numbering['pattern'], [
            '{TYPE}' => strtoupper($type),
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{SEQ}' => $sequenceValue,
        ]);
    }

    /**
     * @return array{pattern:string,padding:int}
     */
    private function defaultNumberingData(): array
    {
        return [
            'pattern' => env('WAREHOUSE_DOCUMENT_NUMBER_PATTERN', '{TYPE}/{YYYY}/{SEQ}'),
            'padding' => (int) env('WAREHOUSE_DOCUMENT_NUMBER_PADDING', 6),
        ];
    }

    private function cleanPattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            $pattern = '{TYPE}/{YYYY}/{SEQ}';
        }

        $pattern = preg_replace('/[^A-Za-z0-9_\/{}-]+/', '', $pattern) ?? '';

        if (! str_contains($pattern, '{SEQ}')) {
            $pattern = rtrim($pattern, '/') . '/{SEQ}';
        }

        return $pattern !== '' ? $pattern : '{TYPE}/{YYYY}/{SEQ}';
    }
}
