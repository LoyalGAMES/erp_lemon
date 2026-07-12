<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\AppSetting;

final class PackingSettingsService
{
    private const KEY = 'packing_settings';

    /**
     * @return array{
     *     footwear_keywords:list<string>,
     *     stations:list<array{code:string,name:string,printer_name:string,segment:string}>
     * }
     */
    public function data(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');

        $data = array_merge($this->defaults(), is_array($stored) ? $stored : []);

        return [
            'footwear_keywords' => $this->cleanKeywords($data['footwear_keywords'] ?? null),
            'stations' => $this->cleanStations($data['stations'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data): array
    {
        $current = $this->data();

        $payload = [
            'footwear_keywords' => array_key_exists('footwear_keywords', $data)
                ? $this->cleanKeywords($data['footwear_keywords'])
                : $current['footwear_keywords'],
            'stations' => array_key_exists('stations', $data)
                ? $this->cleanStations($data['stations'])
                : $current['stations'],
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    /**
     * @return array{code:string,name:string,printer_name:string,segment:string}|null
     */
    public function station(?string $code): ?array
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        foreach ($this->data()['stations'] as $station) {
            if ($station['code'] === $code) {
                return $station;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'footwear_keywords' => $this->defaultFootwearKeywords(),
            'stations' => [
                [
                    'code' => 'station-1',
                    'name' => 'Stanowisko 1',
                    'printer_name' => 'Drukarka 1',
                    'segment' => 'clothing',
                ],
                [
                    'code' => 'station-2',
                    'name' => 'Stanowisko 2',
                    'printer_name' => 'Drukarka 2',
                    'segment' => 'footwear',
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultFootwearKeywords(): array
    {
        return [
            'obuwie',
            'buty',
            'botki',
            'kozaki',
            'sneakersy',
            'trampki',
            'sandały',
            'sandaly',
            'klapki',
            'baleriny',
            'mokasyny',
            'espadryle',
            'kalosze',
        ];
    }

    /**
     * @return list<string>
     */
    private function cleanKeywords(mixed $keywords): array
    {
        if (is_string($keywords)) {
            $keywords = preg_split('/[\r\n,;]+/', $keywords) ?: [];
        }

        $cleaned = [];

        foreach ((array) $keywords as $keyword) {
            $keyword = mb_strtolower(trim((string) $keyword));

            if ($keyword !== '' && ! in_array($keyword, $cleaned, true)) {
                $cleaned[] = mb_substr($keyword, 0, 60);
            }
        }

        return $cleaned !== [] ? $cleaned : $this->defaultFootwearKeywords();
    }

    /**
     * @return list<array{code:string,name:string,printer_name:string,segment:string}>
     */
    private function cleanStations(mixed $stations): array
    {
        $cleaned = [];

        foreach ((array) $stations as $station) {
            if (! is_array($station)) {
                continue;
            }

            $code = mb_strtolower(trim((string) ($station['code'] ?? '')));
            $code = preg_replace('/[^a-z0-9_-]+/', '-', $code) ?? '';
            $name = trim((string) ($station['name'] ?? ''));

            if ($code === '' || $name === '' || array_key_exists($code, $cleaned)) {
                continue;
            }

            $segment = (string) ($station['segment'] ?? 'all');

            $cleaned[$code] = [
                'code' => mb_substr($code, 0, 40),
                'name' => mb_substr($name, 0, 80),
                'printer_name' => mb_substr(trim((string) ($station['printer_name'] ?? '')), 0, 120),
                'segment' => in_array($segment, ['all', 'clothing', 'footwear'], true) ? $segment : 'all',
            ];
        }

        $cleaned = array_values($cleaned);

        return $cleaned !== [] ? $cleaned : $this->defaults()['stations'];
    }
}
