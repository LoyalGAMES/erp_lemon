<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\AppSetting;
use DateTimeInterface;

final class ReturnSettingsService
{
    private const KEY = 'return_settings';

    /**
     * @return array{
     *     numbering_pattern:string,
     *     numbering_prefix:string,
     *     numbering_padding:int,
     *     default_target_warehouse_id:?int,
     *     default_condition:string,
     *     default_disposition:string,
     *     return_reasons:list<string>,
     *     conditions:list<array{code:string,label:string}>,
     *     dispositions:list<array{code:string,label:string,warehouse_id:?int}>,
     *     disposition_warehouse_ids:array<string, ?int>
     * }
     */
    public function data(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');

        $data = array_merge($this->defaults(), is_array($stored) ? $stored : []);
        $conditions = $this->cleanOptions($data['conditions'] ?? null, $this->defaultConditions());
        $dispositions = $this->cleanDispositions(
            $data['dispositions'] ?? null,
            is_array($data['disposition_warehouse_ids'] ?? null) ? $data['disposition_warehouse_ids'] : [],
        );
        $conditionCodes = array_column($conditions, 'code');
        $dispositionCodes = array_column($dispositions, 'code');

        return [
            'numbering_pattern' => $this->cleanPattern((string) $data['numbering_pattern']),
            'numbering_prefix' => $this->cleanPrefix((string) $data['numbering_prefix']),
            'numbering_padding' => max(3, min(9, (int) $data['numbering_padding'])),
            'default_target_warehouse_id' => filled($data['default_target_warehouse_id'] ?? null)
                ? (int) $data['default_target_warehouse_id']
                : null,
            'default_condition' => $this->allowedValue((string) $data['default_condition'], $conditionCodes, 'unchecked'),
            'default_disposition' => $this->allowedValue((string) $data['default_disposition'], $dispositionCodes, 'restock'),
            'return_reasons' => $this->cleanStringList($data['return_reasons'] ?? null, $this->defaultReasons()),
            'conditions' => $conditions,
            'dispositions' => $dispositions,
            'disposition_warehouse_ids' => $this->dispositionWarehouseMap($dispositions),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(array $data): array
    {
        $conditions = $this->cleanOptions($data['conditions'] ?? null, $this->defaultConditions());
        $dispositions = $this->cleanDispositions($data['dispositions'] ?? null);
        $conditionCodes = array_column($conditions, 'code');
        $dispositionCodes = array_column($dispositions, 'code');

        $payload = [
            'numbering_pattern' => $this->cleanPattern((string) ($data['numbering_pattern'] ?? '')),
            'numbering_prefix' => $this->cleanPrefix((string) ($data['numbering_prefix'] ?? 'RET')),
            'numbering_padding' => max(3, min(9, (int) ($data['numbering_padding'] ?? 6))),
            'default_target_warehouse_id' => filled($data['default_target_warehouse_id'] ?? null)
                ? (int) $data['default_target_warehouse_id']
                : null,
            'default_condition' => $this->allowedValue((string) ($data['default_condition'] ?? ''), $conditionCodes, $conditions[0]['code'] ?? 'unchecked'),
            'default_disposition' => $this->allowedValue((string) ($data['default_disposition'] ?? ''), $dispositionCodes, $dispositions[0]['code'] ?? 'restock'),
            'return_reasons' => $this->cleanStringList($data['return_reasons'] ?? null, $this->defaultReasons()),
            'conditions' => $conditions,
            'dispositions' => $dispositions,
            'disposition_warehouse_ids' => $this->dispositionWarehouseMap($dispositions),
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    public function exampleNumber(): string
    {
        $settings = $this->data();

        return $this->renderNumber(1, now(), $settings);
    }

    /**
     * @param array{
     *     dispositions?:list<array{code:string,label:string,warehouse_id:?int}>,
     *     default_target_warehouse_id?:?int
     * }|null $settings
     */
    public function warehouseIdForDisposition(string $disposition, ?int $fallbackWarehouseId = null, ?array $settings = null): ?int
    {
        $settings ??= $this->data();
        $disposition = $this->cleanCode($disposition);

        foreach ((array) ($settings['dispositions'] ?? []) as $setting) {
            if (($setting['code'] ?? null) === $disposition) {
                return $setting['warehouse_id']
                    ?? $fallbackWarehouseId
                    ?? ($settings['default_target_warehouse_id'] ?? null);
            }
        }

        return $fallbackWarehouseId ?? ($settings['default_target_warehouse_id'] ?? null);
    }

    /**
     * @param array{numbering_pattern:string,numbering_prefix:string,numbering_padding:int} $settings
     */
    public function renderNumber(int $sequence, DateTimeInterface $date, array $settings): string
    {
        $sequenceValue = str_pad((string) $sequence, (int) $settings['numbering_padding'], '0', STR_PAD_LEFT);

        return strtr((string) $settings['numbering_pattern'], [
            '{PREFIX}' => trim((string) $settings['numbering_prefix'], '/'),
            '{YYYY}' => $date->format('Y'),
            '{YY}' => $date->format('y'),
            '{MM}' => $date->format('m'),
            '{SEQ}' => $sequenceValue,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'numbering_pattern' => '{PREFIX}/{YYYY}/{SEQ}',
            'numbering_prefix' => 'RET',
            'numbering_padding' => 6,
            'default_target_warehouse_id' => null,
            'default_condition' => 'unchecked',
            'default_disposition' => 'restock',
            'return_reasons' => $this->defaultReasons(),
            'conditions' => $this->defaultConditions(),
            'dispositions' => $this->defaultDispositions(),
            'disposition_warehouse_ids' => [
                'restock' => null,
                'inspection' => null,
                'laundry' => null,
                'scrap' => null,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function defaultReasons(): array
    {
        return [
            'Odstąpienie od umowy',
            'Reklamacja',
            'Wymiana rozmiaru',
            'Błędny produkt',
            'Uszkodzony produkt',
        ];
    }

    /**
     * @return list<array{code:string,label:string}>
     */
    private function defaultConditions(): array
    {
        return [
            ['code' => 'unchecked', 'label' => 'Niezweryfikowany'],
            ['code' => 'new', 'label' => 'Nowy'],
            ['code' => 'opened', 'label' => 'Otwarte opakowanie'],
            ['code' => 'damaged', 'label' => 'Uszkodzony'],
        ];
    }

    /**
     * @return list<array{code:string,label:string,warehouse_id:?int}>
     */
    private function defaultDispositions(): array
    {
        return [
            ['code' => 'restock', 'label' => 'Przywróć na stan', 'warehouse_id' => null],
            ['code' => 'inspection', 'label' => 'Do kontroli', 'warehouse_id' => null],
            ['code' => 'laundry', 'label' => 'Do prania', 'warehouse_id' => null],
            ['code' => 'scrap', 'label' => 'Utylizacja', 'warehouse_id' => null],
        ];
    }

    private function cleanPattern(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            $value = '{PREFIX}/{YYYY}/{SEQ}';
        }

        $value = preg_replace('/[^A-Za-z0-9_\/{}-]+/', '', $value) ?? '';

        if (! str_contains($value, '{SEQ}')) {
            $value = rtrim($value, '/') . '/{SEQ}';
        }

        return $value !== '' ? $value : '{PREFIX}/{YYYY}/{SEQ}';
    }

    private function cleanPrefix(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B/");
        $value = preg_replace('/[^A-Za-z0-9_\/-]+/', '', $value) ?? '';

        return $value !== '' ? $value : 'RET';
    }

    private function cleanWarehouseId(mixed $value): ?int
    {
        return filled($value) ? (int) $value : null;
    }

    /**
     * @param list<string> $allowed
     */
    private function allowedValue(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @param mixed $items
     * @param list<string> $fallback
     * @return list<string>
     */
    private function cleanStringList(mixed $items, array $fallback): array
    {
        $values = is_array($items) ? $items : [];
        $cleaned = [];

        foreach ($values as $value) {
            $value = trim((string) $value);

            if ($value !== '' && ! in_array($value, $cleaned, true)) {
                $cleaned[] = mb_substr($value, 0, 120);
            }
        }

        return $cleaned !== [] ? $cleaned : $fallback;
    }

    /**
     * @param mixed $items
     * @param list<array{code:string,label:string}> $fallback
     * @return list<array{code:string,label:string}>
     */
    private function cleanOptions(mixed $items, array $fallback): array
    {
        $values = is_array($items) ? $items : [];
        $cleaned = [];

        foreach ($values as $item) {
            if (! is_array($item)) {
                continue;
            }

            $code = $this->cleanCode((string) ($item['code'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));

            if ($code === '' || $label === '' || array_key_exists($code, $cleaned)) {
                continue;
            }

            $cleaned[$code] = [
                'code' => $code,
                'label' => mb_substr($label, 0, 80),
            ];
        }

        return array_values($cleaned !== [] ? $cleaned : array_column($fallback, null, 'code'));
    }

    /**
     * @param mixed $items
     * @param array<string, mixed> $legacyWarehouses
     * @return list<array{code:string,label:string,warehouse_id:?int}>
     */
    private function cleanDispositions(mixed $items, array $legacyWarehouses = []): array
    {
        $values = is_array($items) ? $items : $this->defaultDispositions();
        $cleaned = [];

        foreach ($values as $item) {
            if (! is_array($item)) {
                continue;
            }

            $code = $this->cleanCode((string) ($item['code'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));

            if ($code === '' || $label === '' || array_key_exists($code, $cleaned)) {
                continue;
            }

            $cleaned[$code] = [
                'code' => $code,
                'label' => mb_substr($label, 0, 80),
                'warehouse_id' => $this->cleanWarehouseId($item['warehouse_id'] ?? ($legacyWarehouses[$code] ?? null)),
            ];
        }

        return array_values($cleaned !== [] ? $cleaned : $this->defaultDispositions());
    }

    private function cleanCode(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', '_', $value) ?? '';
        $value = preg_replace('/[^a-z0-9_-]+/', '', $value) ?? '';

        return mb_substr($value, 0, 40);
    }

    /**
     * @param list<array{code:string,label:string,warehouse_id:?int}> $dispositions
     * @return array<string, ?int>
     */
    private function dispositionWarehouseMap(array $dispositions): array
    {
        $map = [];

        foreach ($dispositions as $disposition) {
            $map[$disposition['code']] = $disposition['warehouse_id'];
        }

        return $map;
    }
}
