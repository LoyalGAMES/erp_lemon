<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\PackingTask;
use App\Services\Orders\OrderMutationLock;
use App\Services\Orders\OrderStatusPolicyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PackingTaskService
{
    public function __construct(
        private readonly OrderStatusPolicyService $statusPolicy,
        private readonly OrderMutationLock $orderLock,
    ) {}

    /**
     * @return array{created:int,updated:int,cancelled:int}
     */
    public function syncReadyOrders(): array
    {
        $created = 0;
        $updated = 0;
        $cancelled = 0;

        $readyOrderIds = ExternalOrder::query()
            ->whereIn('status', $this->statusPolicy->packingReadyStatuses())
            ->orderBy('external_created_at')
            ->pluck('id');
        $inactiveOrderIds = PackingTask::query()
            ->whereIn('status', ['open', 'picked'])
            ->whereHas('order', fn ($query) => $query->whereNotIn('status', $this->statusPolicy->packingReadyStatuses()))
            ->pluck('external_order_id');
        $orderIds = $readyOrderIds
            ->merge($inactiveOrderIds)
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->filter()
            ->unique()
            ->values();

        ExternalOrder::query()
            ->whereIn('id', $orderIds)
            ->orderBy('external_created_at')
            ->get()
            ->each(function (ExternalOrder $order) use (&$created, &$updated, &$cancelled): void {
                $result = $this->orderLock->forOrder(
                    $order,
                    fn (): array => $this->syncForOrder($order),
                );

                $created += $result['created'];
                $updated += $result['updated'];
                $cancelled += $result['cancelled'];
            });

        return [
            'created' => $created,
            'updated' => $updated,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * @return array{created:int,updated:int,cancelled:int}
     */
    public function syncForOrder(ExternalOrder $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order = ExternalOrder::query()
                ->with(['lines.product', 'salesChannel'])
                ->lockForUpdate()
                ->findOrFail($order->id);

            if ($order->hasCancellationOperation()
                || ! in_array($order->status, $this->statusPolicy->packingReadyStatuses(), true)) {
                $cancelled = $this->cancelActiveTasksForOrder($order, collect());
                $this->syncOrderFulfillmentStatus($order->id);

                return [
                    'created' => 0,
                    'updated' => 0,
                    'cancelled' => $cancelled,
                ];
            }

            return $this->syncLoadedOrder($order);
        });
    }

    /**
     * Cofnij kompletację przed zmianą pozycji zamówienia. Pozostawienie liczby
     * zebranych sztuk spowodowałoby, że nowy produkt odziedziczyłby stan starej
     * pozycji o tym samym identyfikatorze WooCommerce.
     */
    public function resetForOrderEdit(ExternalOrder $order): int
    {
        return DB::transaction(function () use ($order): int {
            $tasks = PackingTask::query()
                ->where('external_order_id', $order->id)
                ->whereIn('status', ['open', 'picked'])
                ->lockForUpdate()
                ->get();

            foreach ($tasks as $task) {
                $metadata = (array) $task->metadata;
                $metadata['order_edit_reset'] = [
                    'previous_status' => $task->status,
                    'previous_quantity_picked' => (float) $task->quantity_picked,
                    'reset_at' => now()->toIso8601String(),
                ];

                $task->forceFill([
                    'status' => 'open',
                    'quantity_picked' => 0,
                    'picked_at' => null,
                    'packed_at' => null,
                    'metadata' => $metadata,
                ])->save();
            }

            $this->syncOrderFulfillmentStatus($order->id);

            return $tasks->count();
        }, 3);
    }

    /**
     * Called while the shared order mutation lock is held by the cancellation
     * workflow. Shipped tasks are intentionally rejected by its preflight.
     */
    public function cancelForOrderCancellation(
        ExternalOrder $order,
        string $operationUuid,
        string $reason,
        bool $preserveAsProblem = false,
    ): int {
        return DB::transaction(function () use ($order, $operationUuid, $reason, $preserveAsProblem): int {
            $tasks = PackingTask::query()
                ->where('external_order_id', $order->id)
                ->where('status', '!=', 'cancelled')
                ->lockForUpdate()
                ->get();

            if ($tasks->contains(fn (PackingTask $task): bool => $task->status === 'shipped')) {
                throw new RuntimeException('Zamówienie zostało już odebrane przez kuriera. Użyj procesu zwrotu zamiast anulacji.');
            }

            foreach ($tasks as $task) {
                $metadata = (array) $task->metadata;
                $previousStatus = (string) $task->status;
                $metadata['order_cancellation'] = [
                    'operation_uuid' => $operationUuid,
                    'reason' => $reason,
                    'previous_status' => $previousStatus,
                    'cancelled_at' => now()->toISOString(),
                    'preserved_as_problem' => $preserveAsProblem,
                ];

                if ($preserveAsProblem) {
                    $metadata['packing_problem'] = array_merge(
                        (array) ($metadata['packing_problem'] ?? []),
                        [
                            'reason' => $reason,
                            'reported_at' => data_get($metadata, 'packing_problem.reported_at') ?: now()->toISOString(),
                            'cancelled_at' => now()->toISOString(),
                            'order_cancellation_uuid' => $operationUuid,
                        ],
                    );
                }

                $task->update([
                    'status' => $preserveAsProblem ? 'problem' : 'cancelled',
                    'metadata' => $metadata,
                ]);
            }

            $this->syncOrderFulfillmentStatus($order->id);

            return $tasks->count();
        }, 3);
    }

    /**
     * @return array{created:int,updated:int,cancelled:int}
     */
    private function syncLoadedOrder(ExternalOrder $order): array
    {
        $created = 0;
        $updated = 0;
        $activeExternalLineIds = collect();

        foreach ($order->lines as $line) {
            if ((float) $line->quantity <= 0) {
                continue;
            }

            $externalLineId = $line->external_line_id ?: 'line-'.$line->id;
            $activeExternalLineIds->push($externalLineId);

            $task = PackingTask::query()->firstOrNew([
                'external_order_id' => $order->id,
                'external_line_id' => $externalLineId,
            ]);

            if (in_array($task->status, ['packed', 'shipped', 'cancelled', 'problem'], true)) {
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

        $cancelled = $this->cancelActiveTasksForOrder($order, $activeExternalLineIds);
        $this->syncOrderFulfillmentStatus($order->id);

        return [
            'created' => $created,
            'updated' => $updated,
            'cancelled' => $cancelled,
        ];
    }

    /**
     * @param  Collection<int, string>  $activeExternalLineIds
     */
    private function cancelActiveTasksForOrder(ExternalOrder $order, Collection $activeExternalLineIds): int
    {
        $activeExternalLineIds = $activeExternalLineIds
            ->map(fn (mixed $externalLineId): string => (string) $externalLineId)
            ->filter()
            ->unique()
            ->values();

        $query = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->whereIn('status', ['open', 'picked']);

        if ($activeExternalLineIds->isNotEmpty()) {
            $query->where(function ($query) use ($activeExternalLineIds): void {
                $query
                    ->whereNull('external_line_id')
                    ->orWhereNotIn('external_line_id', $activeExternalLineIds->all());
            });
        }

        $tasks = $query->lockForUpdate()->get();

        foreach ($tasks as $task) {
            $metadata = (array) $task->metadata;
            $metadata['packing_sync'] = [
                'cancelled_reason' => 'order_line_removed_or_moved',
                'cancelled_at' => now()->toISOString(),
            ];

            $task->update([
                'status' => 'cancelled',
                'metadata' => $metadata,
            ]);
        }

        return $tasks->count();
    }

    public function scan(string $code): PackingTask
    {
        $code = trim($code);

        if ($code === '') {
            throw new RuntimeException('Zeskanuj SKU albo EAN produktu.');
        }

        $candidate = PackingTask::query()
            ->with('order')
            ->where('status', 'open')
            ->whereColumn('quantity_picked', '<', 'quantity_required')
            ->where(function ($query) use ($code): void {
                $query
                    ->where('sku', $code)
                    ->orWhereHas('product', fn ($productQuery) => $productQuery->where('ean', $code));
            })
            ->orderBy('order_date')
            ->first();

        if (! $candidate instanceof PackingTask || ! $candidate->order instanceof ExternalOrder) {
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

        return $this->orderLock->forOrder($candidate->order, function () use ($candidate, $code): PackingTask {
            return DB::transaction(function () use ($candidate, $code): PackingTask {
                $order = ExternalOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($candidate->external_order_id);
                $this->assertPackingMutationAllowed($order);

                $task = PackingTask::query()
                    ->with('product')
                    ->whereKey($candidate->id)
                    ->where('external_order_id', $candidate->external_order_id)
                    ->where('status', 'open')
                    ->whereColumn('quantity_picked', '<', 'quantity_required')
                    ->where(function ($query) use ($code): void {
                        $query
                            ->where('sku', $code)
                            ->orWhereHas('product', fn ($productQuery) => $productQuery->where('ean', $code));
                    })
                    ->lockForUpdate()
                    ->first();

                if (! $task instanceof PackingTask) {
                    throw new RuntimeException('Pozycja zmieniła się podczas aktualizacji zamówienia. Zeskanuj kod ponownie.');
                }

                $picked = min((float) $task->quantity_required, (float) $task->quantity_picked + 1);
                $isComplete = $picked >= (float) $task->quantity_required;

                $task->update([
                    'quantity_picked' => $picked,
                    'status' => $isComplete ? 'picked' : 'open',
                    'picked_at' => $isComplete ? now() : $task->picked_at,
                ]);

                $this->syncOrderFulfillmentStatus($task->external_order_id);

                return $task->refresh();
            });
        });
    }

    public function markPacked(PackingTask $task): PackingTask
    {
        $task->loadMissing('order');

        if (! $task->order instanceof ExternalOrder) {
            throw new RuntimeException('Nie znaleziono zamówienia dla tej pozycji pakowania.');
        }

        return $this->orderLock->forOrder($task->order, function () use ($task): PackingTask {
            return DB::transaction(function () use ($task): PackingTask {
                $order = ExternalOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($task->external_order_id);
                $this->assertPackingMutationAllowed($order);

                $lockedTask = PackingTask::query()->lockForUpdate()->findOrFail($task->id);

                if ($lockedTask->status !== 'picked') {
                    throw new RuntimeException('Pozycja musi być najpierw w całości zebrana.');
                }

                $lockedTask->update([
                    'status' => 'packed',
                    'packed_at' => now(),
                ]);

                $this->syncOrderFulfillmentStatus($lockedTask->external_order_id);

                return $lockedTask->refresh();
            });
        });
    }

    /**
     * @param  array<int|string>  $taskIds
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

        $orderIds = PackingTask::query()
            ->whereIn('id', $ids)
            ->pluck('external_order_id')
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $orders = ExternalOrder::query()
            ->whereIn('id', $orderIds)
            ->orderBy('id')
            ->get()
            ->all();

        if ($orders === []) {
            throw new RuntimeException('Nie znaleziono zamówień dla wybranych pozycji pakowania.');
        }

        return $this->orderLock->forOrders($orders, function () use ($ids): int {
            return DB::transaction(function () use ($ids): int {
                $orders = ExternalOrder::query()
                    ->whereIn('id', PackingTask::query()->whereIn('id', $ids)->select('external_order_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($orders as $order) {
                    $this->assertPackingMutationAllowed($order);
                }

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

                $tasks->pluck('external_order_id')->unique()->each(
                    fn ($orderId) => $this->syncOrderFulfillmentStatus((int) $orderId),
                );

                return $tasks->count();
            });
        });
    }

    public function markOrderPacked(ExternalOrder $order): int
    {
        return DB::transaction(function () use ($order): int {
            $order = ExternalOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);
            $this->assertPackingMutationAllowed($order);

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

            $this->syncOrderFulfillmentStatus($order->id);

            return $pickedTasks->count();
        });
    }

    /**
     * @param  array<int|string>  $taskIds
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

            $tasks->pluck('external_order_id')->unique()->each(
                fn ($orderId) => $this->syncOrderFulfillmentStatus((int) $orderId),
            );

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

    /**
     * @param  array<string, mixed>  $details
     */
    public function annotateOrderProblem(ExternalOrder $order, array $details): void
    {
        PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', 'problem')
            ->get()
            ->each(function (PackingTask $task) use ($details): void {
                $metadata = (array) $task->metadata;
                $metadata['packing_problem'] = array_merge(
                    (array) ($metadata['packing_problem'] ?? []),
                    $details,
                );

                $task->update(['metadata' => $metadata]);
            });
    }

    public function reopenProblem(PackingTask $task): PackingTask
    {
        $task->loadMissing('order');

        if (! $task->order instanceof ExternalOrder) {
            throw new RuntimeException('Nie znaleziono zamówienia dla tej pozycji pakowania.');
        }

        return $this->orderLock->forOrder($task->order, function () use ($task): PackingTask {
            return DB::transaction(function () use ($task): PackingTask {
                $order = ExternalOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($task->external_order_id);
                $this->assertPackingMutationAllowed($order);
                $lockedTask = PackingTask::query()->lockForUpdate()->findOrFail($task->id);

                if ($lockedTask->status !== 'problem') {
                    throw new RuntimeException('Tylko pozycję w statusie problem można przywrócić do kolejki.');
                }

                if (filled(data_get($lockedTask->metadata, 'packing_problem.cancelled_at'))) {
                    throw new RuntimeException('Anulowane zamówienie z listy problemów jest tylko do odczytu.');
                }

                $metadata = (array) $lockedTask->metadata;
                $metadata['packing_problem_resolved_at'] = now()->toISOString();
                unset($metadata['packing_problem']);

                $required = (float) $lockedTask->quantity_required;
                $picked = min((float) $lockedTask->quantity_picked, $required);

                $lockedTask->update([
                    'status' => $picked >= $required ? 'picked' : 'open',
                    'quantity_picked' => $picked,
                    'metadata' => $metadata,
                ]);

                $this->syncOrderFulfillmentStatus($lockedTask->external_order_id);

                return $lockedTask->refresh();
            }, 3);
        });
    }

    public function readyStatuses(): array
    {
        return $this->statusPolicy->packingReadyStatuses();
    }

    private function assertPackingMutationAllowed(ExternalOrder $order): void
    {
        if ($order->hasCancellationOperation()
            || in_array(mb_strtolower(trim((string) $order->status)), [
                'cancellation-pending',
                'cancelled',
                'refunded',
            ], true)) {
            throw new RuntimeException(
                'Nie można kontynuować kompletacji ani pakowania anulowanego zamówienia lub zamówienia w trakcie anulacji.',
            );
        }
    }

    private function syncOrderFulfillmentStatus(int $orderId): void
    {
        $statuses = PackingTask::query()
            ->where('external_order_id', $orderId)
            ->where('status', '!=', 'cancelled')
            ->pluck('status');

        $status = match (true) {
            $statuses->isEmpty() => null,
            $statuses->contains('problem') => 'problem',
            $statuses->every(fn (string $taskStatus): bool => $taskStatus === 'shipped') => 'shipped',
            $statuses->every(fn (string $taskStatus): bool => in_array($taskStatus, ['packed', 'shipped'], true)) => 'awaiting_courier',
            ! $statuses->contains('open')
                && $statuses->every(fn (string $taskStatus): bool => $taskStatus === 'picked') => 'ready_to_pack',
            $statuses->contains('open') => 'picking',
            default => null,
        };

        ExternalOrder::query()
            ->whereKey($orderId)
            ->update(['fulfillment_status' => $status]);
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

        foreach ([$line->product?->name, $line->name] as $name) {
            $value = $this->sizeLabelFromName((string) $name);

            if ($value !== null) {
                return $value;
            }
        }

        return '-';
    }

    private function sizeLabelFromName(string $name): ?string
    {
        $name = trim($name);

        if ($name === '' || ! preg_match('/.*\s[-–—]\s(?<size>.+)$/u', $name, $matches)) {
            return null;
        }

        $candidate = trim((string) $matches['size']);
        $candidate = preg_replace('/\s+/', ' ', $candidate) ?: '';
        $normalized = mb_strtoupper($candidate);

        if (preg_match('/^(?:XXS|XS|S|M|L|XL|XXL|XXXL|[2-6]XL)(?:[\/-](?:XXS|XS|S|M|L|XL|XXL|XXXL|[2-6]XL))*$/u', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^(?:ONE SIZE|ONESIZE|UNI|UNIWERSALNY)$/u', $normalized) === 1) {
            return $candidate;
        }

        if (preg_match('/^\d{2,3}(?:[,.]5)?(?:[\/-]\d{2,3}(?:[,.]5)?)*$/u', $normalized) === 1) {
            return $candidate;
        }

        return null;
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
