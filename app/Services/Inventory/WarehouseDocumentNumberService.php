<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\WarehouseDocument;
use DateTimeInterface;

final class WarehouseDocumentNumberService
{
    public function __construct(
        private readonly WarehouseDocumentSettingsService $settings,
    ) {}

    public function next(string $type, ?DateTimeInterface $date = null): string
    {
        $date ??= now();
        $numbering = $this->settings->numberingData();
        $placeholder = '__SEQ__';
        $template = $this->settings->renderNumber($type, 0, $date, [
            'pattern' => str_replace('{SEQ}', $placeholder, $numbering['pattern']),
            'padding' => $numbering['padding'],
        ]);
        $prefix = explode($placeholder, $template)[0] ?? '';
        $sequence = WarehouseDocument::withTrashed()
            ->when($prefix !== '', fn ($query) => $query->where('number', 'like', $prefix.'%'))
            ->lockForUpdate()
            ->count() + 1;

        do {
            $number = $this->settings->renderNumber($type, $sequence, $date, $numbering);
            $sequence += 1;
        } while (WarehouseDocument::withTrashed()->where('number', $number)->exists());

        return $number;
    }
}
