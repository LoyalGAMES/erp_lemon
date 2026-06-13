<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\PackingTask;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PackingTaskService
{
    private const READY_STATUSES = ['processing'];

    /**
     * @return array{created:int,updated:int,cancelled:int}
     */
    public function syncReadyOrders(): array
    {
        $created = 0;
        $updated = 0;

        DB::transaction(function () use (&$created, &$updated): void {
            ExternalOrder::query()
                ->with(['lines.product', 'salesChannel'])
                ->whereIn('status', self::READY_STATUSES)
                ->orderBy('external_created_at')
                ->get()
                ->each(function (ExternalOrder $order) use (&$created, &$updated): void {
                    foreach ($order->lines as $line) {
                        if ((float) $line->quantity <= 0) {
                            continue;
                        }

                        $externalLineId = $line->external_line_id ?: 'line-' . $line->id;

                        $task = PackingTask::query()->firstOrNew([
                            'external_order_id' => $order->id,
                            'external_line_id' => $externalLineId,
                        ]);

                        if (in_array($task->status, ['packed', 'cancelled', 'problem'], true)) {
                            continue;
                        }

                        $required = (float) $line->quantity;
                        $picked = min((float) ($task->quantity_picked ?? 0), $required);
                        $status = $picked >= $required ? 'picked' : 'open';

                        $metadata = array_merge((array) $task->metadata, [
                            'sales_channel_code' => $order->salesChannel?->code,
                            'warehouse_location' => $this->warehouseLocation($line),
                            'customer_note' => (string) data_get($order->raw_payload, 'customer_note', ''),
                            'order_notes' => $this->orderNotes($order),
                            'payment_method' => data_get($order->raw_payload, 'payment_method_title'),
                            'shipping' => $order->shipping_data,
                            'billing' => $order->billing_data,
                        ]);

                        $task->fill([
                            'sales_channel_id' => $order->sales_channel_id,
                            'external_order_line_id' => $line->id,
                            'product_id' => $line->product_id,
                            'order_number' => $order->external_number,
                            'customer_name' => $this->customerName($order),
                            'sku' => $line->sku,
                            'product_name' => $line->product?->name ?: $line->name,
                            'quantity_required' => $required,
                            'quantity_picked' => $picked,
                            'status' => $status,
                            'courier' => $this->courier($order),
                            'size_label' => $this->sizeLabel($line),
                            'order_date' => $order->external_created_at,
                            'picked_at' => $status === 'picked' ? ($task->picked_at ?? now()) : null,
                            'metadata' => $metadata,
                        ]);

                        $task->exists ? $updated++ : $created++;
                        $task->save();
                    }
                });
        });

        $cancelled = PackingTask::query()
            ->whereIn('status', ['open', 'picked', 'problem'])
            ->whereHas('order', fn ($query) => $query->whereNotIn('status', self::READY_STATUSES))
            ->update(['status' => 'cancelled']);

        return [
            'created' => $created,
            'updated' => $updated,
            'cancelled' => $cancelled,
        ];
    }

    public function scan(string $code): PackingTask
    {
        $code = trim($code);

        if ($code === '') {
            throw new RuntimeException('Zeskanuj SKU albo EAN produktu.');
        }

        return DB::transaction(function () use ($code): PackingTask {
            $task = PackingTask::query()
                ->with('product')
                ->where('status', 'open')
                ->whereColumn('quantity_picked', '<', 'quantity_required')
                ->where(function ($query) use ($code): void {
                    $query
                        ->where('sku', $code)
                        ->orWhereHas('product', fn ($productQuery) => $productQuery->where('ean', $code));
                })
                ->orderBy('order_date')
                ->lockForUpdate()
                ->first();

            if (! $task instanceof PackingTask) {
                $hasCompletedTask = PackingTask::query()
                    ->where('status', 'picked')
                    ->where(function ($query) use ($code): void {
                        $query
                            ->where('sku', $code)
                            ->orWhereHas('product', fn ($productQuery) => $productQuery->where('ean', $code));
                    })
                    ->exists();

                if ($hasCompletedTask) {
                    throw new RuntimeException("Wszystkie pozycje dla kodu {$code} są już zebrane.");
                }

                throw new RuntimeException("Nie znaleziono otwartej pozycji do zebrania dla kodu {$code}.");
            }

            $picked = min((float) $task->quantity_required, (float) $task->quantity_picked + 1);
            $isComplete = $picked >= (float) $task->quantity_required;

            $task->update([
                'quantity_picked' => $picked,
                'status' => $isComplete ? 'picked' : 'open',
                'picked_at' => $isComplete ? now() : $task->picked_at,
            ]);

            return $task->refresh();
        });
    }

    public function markPacked(PackingTask $task): PackingTask
    {
        if ($task->status !== 'picked') {
            throw new RuntimeException('Pozycja musi być najpierw w całości zebrana.');
        }

        $task->update([
            'status' => 'packed',
            'packed_at' => now(),
        ]);

        return $task->refresh();
    }

    /**
     * @param array<int|string> $taskIds
     */
    public function markPickedMany(array $taskIds): int
    {
        $ids = collect($taskIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new RuntimeException('Wybierz pozycje do oznaczenia jako zebrane.');
        }

        return DB::transaction(function () use ($ids): int {
            $tasks = PackingTask::query()
                ->whereIn('id', $ids)
                ->where('status', 'open')
                ->lockForUpdate()
                ->get();

            if ($tasks->isEmpty()) {
                throw new RuntimeException('Pozycje z tej grupy są już zebrane albo nie są dostępne do zbierania.');
            }

            foreach ($tasks as $task) {
                $task->update([
                    'quantity_picked' => $task->quantity_required,
                    'status' => 'picked',
                    'picked_at' => now(),
                ]);
            }

            return $tasks->count();
        });
    }

    public function markOrderPacked(ExternalOrder $order): int
    {
        return DB::transaction(function () use ($order): int {
            $tasks = PackingTask::query()
                ->where('external_order_id', $order->id)
                ->whereIn('status', ['open', 'picked'])
                ->lockForUpdate()
                ->get();

            if ($tasks->isEmpty()) {
                throw new RuntimeException('Brak aktywnych pozycji pakowania dla tego zamówienia.');
            }

            if ($tasks->contains(fn (PackingTask $task): bool => $task->status === 'open')) {
                throw new RuntimeException('Najpierw zbierz wszystkie pozycje z tego zamówienia.');
            }

            $pickedTasks = $tasks->where('status', 'picked');

            if ($pickedTasks->isEmpty()) {
                throw new RuntimeException('To zamówienie nie ma pozycji gotowych do spakowania.');
            }

            foreach ($pickedTasks as $task) {
                $task->update([
                    'status' => 'packed',
                    'packed_at' => now(),
                ]);
            }

            return $pickedTasks->count();
        });
    }

    /**
     * @param array<int|string> $taskIds
     */
    public function markProblemMany(array $taskIds, string $reason = 'Do wyjaśnienia'): int
    {
        $ids = collect($taskIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new RuntimeException('Wybierz pozycje do oznaczenia jako problem.');
        }

        return DB::transaction(function () use ($ids, $reason): int {
            $tasks = PackingTask::query()
                ->whereIn('id', $ids)
                ->whereIn('status', ['open', 'picked'])
                ->lockForUpdate()
                ->get();

            if ($tasks->isEmpty()) {
                throw new RuntimeException('Nie znaleziono aktywnych pozycji do oznaczenia jako problem.');
            }

            foreach ($tasks as $task) {
                $metadata = (array) $task->metadata;
                $metadata['packing_problem'] = [
                    'reason' => $reason,
                    'reported_at' => now()->toISOString(),
                ];

                $task->update([
                    'status' => 'problem',
                    'metadata' => $metadata,
                ]);
            }

            return $tasks->count();
        });
    }

    public function markOrderProblem(ExternalOrder $order, string $reason = 'Problem z zamówieniem'): int
    {
        $taskIds = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->whereIn('status', ['open', 'picked'])
            ->pluck('id')
            ->all();

        return $this->markProblemMany($taskIds, $reason);
    }

    public function reopenProblem(PackingTask $task): PackingTask
    {
        if ($task->status !== 'problem') {
            throw new RuntimeException('Tylko pozycję w statusie problem można przywrócić do kolejki.');
        }

        $metadata = (array) $task->metadata;
        $metadata['packing_problem_resolved_at'] = now()->toISOString();
        unset($metadata['packing_problem']);

        $required = (float) $task->quantity_required;
        $picked = min((float) $task->quantity_picked, $required);

        $task->update([
            'status' => $picked >= $required ? 'picked' : 'open',
            'quantity_picked' => $picked,
            'metadata' => $metadata,
        ]);

        return $task->refresh();
    }

    public function readyStatuses(): array
    {
        return self::READY_STATUSES;
    }

    private function customerName(ExternalOrder $order): string
    {
        $shippingName = trim(implode(' ', array_filter([
            data_get($order->shipping_data, 'first_name'),
            data_get($order->shipping_data, 'last_name'),
        ])));

        if ($shippingName !== '') {
            return $shippingName;
        }

        $billingName = trim(implode(' ', array_filter([
            data_get($order->billing_data, 'first_name'),
            data_get($order->billing_data, 'last_name'),
        ])));

        return $billingName !== '' ? $billingName : '-';
    }

    private function courier(ExternalOrder $order): string
    {
        $couriers = collect(data_get($order->raw_payload, 'shipping_lines', []))
            ->map(fn (array $line): string => trim((string) ($line['method_title'] ?? $line['method_id'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        return $couriers->isNotEmpty() ? $couriers->implode(', ') : 'Nieznany kurier';
    }

    private function warehouseLocation(ExternalOrderLine $line): string
    {
        $location = trim((string) data_get($line->product?->attributes, 'master.stock.location', ''));

        if ($location !== '') {
            return $location;
        }

        return trim((string) data_get($line->product?->attributes, 'warehouse_location', ''));
    }

    private function sizeLabel(ExternalOrderLine $line): string
    {
        foreach ((array) data_get($line->raw_payload, 'meta_data', []) as $meta) {
            $key = mb_strtolower((string) ($meta['display_key'] ?? $meta['key'] ?? ''));

            if (str_contains($key, 'rozmiar') || str_contains($key, 'size')) {
                $value = trim((string) ($meta['display_value'] ?? $meta['value'] ?? ''));

                if ($value !== '') {
                    return strip_tags($value);
                }
            }
        }

        foreach ((array) data_get($line->product?->attributes, 'woocommerce_variation_attributes', []) as $attribute) {
            $name = mb_strtolower((string) ($attribute['name'] ?? ''));

            if (str_contains($name, 'rozmiar') || str_contains($name, 'size')) {
                $value = trim((string) ($attribute['option'] ?? ''));

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '-';
    }

    /**
     * @return list<array{date_created?:string,author?:string,note?:string}>
     */
    private function orderNotes(ExternalOrder $order): array
    {
        return collect(data_get($order->raw_payload, 'erp_imported_order_notes', []))
            ->map(fn (array $note): array => [
                'date_created' => isset($note['date_created']) ? (string) $note['date_created'] : null,
                'author' => isset($note['author']) ? (string) $note['author'] : null,
                'note' => isset($note['note']) ? trim(strip_tags((string) $note['note'])) : null,
            ])
            ->filter(fn (array $note): bool => ($note['note'] ?? '') !== '')
            ->values()
            ->all();
    }
}
