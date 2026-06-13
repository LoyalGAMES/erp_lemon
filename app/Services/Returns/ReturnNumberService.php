<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\ReturnCase;

final class ReturnNumberService
{
    public function __construct(
        private readonly ReturnSettingsService $settings,
    ) {
    }

    public function next(): string
    {
        $settings = $this->settings->data();
        $date = now();
        $placeholder = '__SEQ__';
        $template = $this->settings->renderNumber(0, $date, [
            'numbering_pattern' => str_replace('{SEQ}', $placeholder, $settings['numbering_pattern']),
            'numbering_prefix' => $settings['numbering_prefix'],
            'numbering_padding' => $settings['numbering_padding'],
        ]);
        $prefix = explode($placeholder, $template)[0] ?? '';

        $last = ReturnCase::query()
            ->when($prefix !== '', fn ($query) => $query->where('number', 'like', $prefix . '%'))
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('number');

        $sequence = 1;

        if (is_string($last) && preg_match('/(\d+)$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        do {
            $number = $this->settings->renderNumber($sequence, $date, $settings);
            $sequence += 1;
        } while (ReturnCase::query()->where('number', $number)->exists());

        return $number;
    }
}
