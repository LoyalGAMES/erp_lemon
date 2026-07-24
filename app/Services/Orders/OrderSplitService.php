<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\Invoice;
use App\Models\OrderCancellation;
use App\Models\PackingTask;
use App\Models\ReturnCase;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Services\Audit\AuditLogService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\StockReservationService;
use App\Services\Packing\PackingTaskService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class OrderSplitService
{
    private const SHIPPING_LOCK_SECONDS = 900;

    private const SHIPPING_LOCK_WAIT_SECONDS = 15;

    /** @var list<string> */
    private const MUTABLE_STATUSES = ['pending', 'processing', 'on-hold'];

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly PackingTaskService $packingTasks,
        private readonly CustomerCommunicationService $communication,
        private readonly OrderMutationLock $orderLock,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * Wydziela pozycje zamówienia do nowego zamówienia potomnego i przelicza
     * rezerwacje oraz zadania pakowania obu zamówień.
     *
     * @param  array<int, float>  $quantities  mapa line_id => ilość do wydzielenia
     * @param  array<string, mixed>  $context
     */
    public function split(
        ExternalOrder $order,
        array $quantities,
        ?string $note = null,
        string $source = 'manual',
        array $context = [],
        ?string $requestUuid = null,
    ): ExternalOrder {
        $requestUuid = trim((string) ($requestUuid ?: Str::uuid()));

        if (! Str::isUuid($requestUuid)) {
            throw new RuntimeException('Identyfikator operacji podziału jest nieprawidłowy. Odśwież stronę i spróbuj ponownie.');
        }

        return $this->orderLock->forOrderFamily($order, function () use ($order, $quantities, $note, $source, $context, $requestUuid): ExternalOrder {
            $familyIds = $this->familyOrders($order)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            try {
                return $this->withShippingLocks(
                    $familyIds,
                    0,
                    fn (): ExternalOrder => $this->splitWhileLocked(
                        $order,
                        $quantities,
                        $note,
                        $source,
                        $context,
                        $requestUuid,
                    ),
                );
            } catch (LockTimeoutException $exception) {
                throw new RuntimeException(
                    'Dla jednej z części zamówienia trwa generowanie lub anulowanie etykiety. Spróbuj ponownie za chwilę.',
                    previous: $exception,
                );
            }
        });
    }

    /** @return array{available:bool,reasons:list<string>} */
    public function availability(ExternalOrder $order): array
    {
        $family = $this->familyOrders($order);
        $fresh = $family->firstWhere('id', $order->id) ?? $order->fresh() ?? $order;
        $reasons = $this->splitBlockers($fresh, $family);

        return ['available' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * @param  array<int, float>  $quantities
     * @param  array<string, mixed>  $context
     */
    private function splitWhileLocked(
        ExternalOrder $order,
        array $quantities,
        ?string $note,
        string $source,
        array $context,
        string $requestUuid,
    ): ExternalOrder {
        $quantities = collect($quantities)
            ->mapWithKeys(fn ($quantity, $lineId): array => [(int) $lineId => (float) $quantity])
            ->filter(fn (float $quantity): bool => $quantity > 0)
            ->all();

        if ($quantities === []) {
            throw new RuntimeException('Podaj ilość co najmniej jednej pozycji do wydzielenia.');
        }

        $requestFingerprint = $this->splitRequestFingerprint(
            (int) $order->id,
            $quantities,
            $note,
            $source,
            $context,
        );

        $wasCreated = false;
        $splitOrder = DB::transaction(function () use ($order, $quantities, $note, $source, $context, $requestUuid, $requestFingerprint, &$wasCreated): ExternalOrder {
            $orderSnapshot = ExternalOrder::query()->findOrFail($order->id);
            $rootOrderId = (int) ($orderSnapshot->split_root_order_id ?: $orderSnapshot->id);
            $family = ExternalOrder::query()
                ->where('sales_channel_id', $orderSnapshot->sales_channel_id)
                ->where(fn ($query) => $query->whereKey($rootOrderId)->orWhere('split_root_order_id', $rootOrderId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $order = $family->firstWhere('id', $orderSnapshot->id);

            if (! $order instanceof ExternalOrder) {
                throw new RuntimeException('Nie znaleziono zamówienia do podziału.');
            }

            $existingResult = $family->first(fn (ExternalOrder $member): bool => (string) data_get(
                $member->raw_payload,
                'sempre_erp_split.request_uuid',
            ) === $requestUuid);

            if ($existingResult instanceof ExternalOrder) {
                if (! hash_equals(
                    (string) data_get($existingResult->raw_payload, 'sempre_erp_split.request_fingerprint', ''),
                    $requestFingerprint,
                )) {
                    throw new RuntimeException('Ten identyfikator operacji został już użyty do innego podziału. Odśwież stronę i spróbuj ponownie.');
                }

                return $existingResult;
            }

            $reasons = $this->splitBlockers($order, $family);

            if ($reasons !== []) {
                throw new RuntimeException(implode(' ', $reasons));
            }

            $lockedFamilyLines = ExternalOrderLine::query()
                ->whereIn('external_order_id', $family->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();
            $familyLines = $lockedFamilyLines->groupBy('external_order_id');
            $sourceBalances = $this->lockSourceBalances(
                $lockedFamilyLines,
                (int) $order->sales_channel_id,
            );
            $order->setRelation('lines', $familyLines->get($order->id, new EloquentCollection));
            $rootOrder = $family->firstWhere('id', $rootOrderId) ?? $order;
            $rootOrder->setRelation('lines', $familyLines->get($rootOrder->id, new EloquentCollection));

            if ($family->count() === 1 && data_get($rootOrder->raw_payload, 'sempre_erp_split_original') === null) {
                $this->captureOriginalSnapshot($rootOrder, $sourceBalances);

                if ((int) $rootOrder->id === (int) $order->id) {
                    $order->raw_payload = $rootOrder->raw_payload;
                }
            }

            $allocations = [];

            foreach ($quantities as $lineId => $requestedQuantity) {
                $line = $order->lines->firstWhere('id', $lineId);

                if (! $line instanceof ExternalOrderLine) {
                    continue;
                }

                $currentQuantity = (float) $line->quantity;
                $splitQuantity = min($requestedQuantity, $currentQuantity);

                if ($splitQuantity <= 0) {
                    continue;
                }

                $allocations[] = [
                    'line' => $line,
                    'source_quantity' => $currentQuantity,
                    'split_quantity' => $splitQuantity,
                ];
            }

            if ($allocations === []) {
                throw new RuntimeException('Żadna wskazana pozycja nie należy do tego zamówienia albo nie ma ilości do wydzielenia.');
            }

            [$sourceTotal, $childTotal] = $this->allocatedTotals($order, $allocations);
            $splitIndex = $this->nextSplitIndex($order);
            [$childPayload, $removedShippingReferences] = $this->payloadForSplitChild((array) $order->raw_payload);
            $childPayload['sempre_erp_split'] = [
                'parent_order_id' => $order->id,
                'parent_external_id' => $order->external_id,
                'root_order_id' => $rootOrder->id,
                'root_external_id' => $rootOrder->external_id,
                'note' => $note,
                'source' => $source,
                'request_uuid' => $requestUuid,
                'request_fingerprint' => $requestFingerprint,
                'removed_shipping_references' => $removedShippingReferences,
                'created_at' => now()->toISOString(),
            ];

            $splitOrder = ExternalOrder::query()->create([
                'split_parent_order_id' => $order->id,
                'split_root_order_id' => $rootOrder->id,
                'sales_channel_id' => $order->sales_channel_id,
                'customer_id' => $order->customer_id,
                'customer_external_account_id' => $order->customer_external_account_id,
                'wordpress_integration_id' => $order->wordpress_integration_id,
                'customer_match_method' => $order->customer_match_method,
                'external_id' => $order->external_id.'-SPLIT-'.$splitIndex,
                'external_number' => ($order->external_number ?: $order->external_id).'/S'.$splitIndex,
                'status' => in_array($order->status, self::MUTABLE_STATUSES, true) ? $order->status : 'processing',
                'currency' => $order->currency,
                'total_gross' => $childTotal,
                'billing_data' => $order->billing_data,
                'shipping_data' => $order->shipping_data,
                'raw_payload' => $childPayload,
                'external_created_at' => $order->external_created_at,
                'external_updated_at' => now(),
            ]);
            $wasCreated = true;
            $splitAllocations = (array) data_get($order->raw_payload, 'sempre_erp_split_allocations', []);

            foreach ($allocations as $allocation) {
                /** @var ExternalOrderLine $line */
                $line = $allocation['line'];
                $currentQuantity = (float) $allocation['source_quantity'];
                $splitQuantity = (float) $allocation['split_quantity'];
                $canonicalExternalLineId = $this->canonicalExternalLineId($line);

                $splitOrder->lines()->create([
                    'product_id' => $line->product_id,
                    'external_line_id' => $line->external_line_id ? $line->external_line_id.'-S'.$splitIndex : null,
                    'canonical_external_line_id' => $canonicalExternalLineId,
                    'sku' => $line->sku,
                    'name' => $line->name,
                    'quantity' => $splitQuantity,
                    'unit_net_price' => $line->unit_net_price,
                    'unit_gross_price' => $line->unit_gross_price,
                    'vat_rate' => $line->vat_rate,
                    'raw_payload' => array_replace_recursive((array) $line->raw_payload, [
                        'sempre_erp_split' => [
                            'source_order_line_id' => $line->id,
                            'source_external_line_id' => $line->external_line_id,
                            'root_external_line_id' => $canonicalExternalLineId,
                            'source_quantity' => $currentQuantity,
                            'split_quantity' => $splitQuantity,
                        ],
                    ]),
                ]);

                $splitAllocations[] = [
                    'child_external_id' => $splitOrder->external_id,
                    'child_external_number' => $splitOrder->external_number,
                    'source_order_line_id' => $line->id,
                    'source_external_line_id' => $line->external_line_id,
                    'root_external_line_id' => $canonicalExternalLineId,
                    'sku' => $line->sku,
                    'product_id' => $line->product_id,
                    'source_quantity' => $currentQuantity,
                    'split_quantity' => $splitQuantity,
                    'created_at' => now()->toISOString(),
                ];

                $remainingQuantity = $currentQuantity - $splitQuantity;

                if ($remainingQuantity <= 0) {
                    $line->delete();
                } else {
                    $line->update(['quantity' => $remainingQuantity]);
                }
            }

            $rawPayload = (array) $order->raw_payload;
            $rawPayload['sempre_erp_split_child_orders'] = array_values(array_unique(array_filter([
                ...((array) data_get($order->raw_payload, 'sempre_erp_split_child_orders', [])),
                $splitOrder->external_id,
            ])));
            $rawPayload['sempre_erp_split_allocations'] = $splitAllocations;

            if (is_array($context['shipping_decision'] ?? null)) {
                $rawPayload['sempre_erp_shipping_decision'] = $context['shipping_decision'];
            }

            $order->update([
                'total_gross' => $sourceTotal,
                'raw_payload' => $rawPayload,
            ]);

            $this->reallocateReflectedQuantityForSplit(
                $sourceBalances,
                $order,
                $splitOrder,
                $allocations,
            );

            $this->audit->record('order.split_created', $rootOrder, null, [
                'parent_order_id' => $order->id,
                'child_order_id' => $splitOrder->id,
                'child_order_number' => $splitOrder->external_number,
                'parent_total_gross' => $sourceTotal,
                'child_total_gross' => $childTotal,
                'allocations' => collect($allocations)->map(fn (array $allocation): array => [
                    'source_order_line_id' => $allocation['line']->id,
                    'quantity' => $allocation['split_quantity'],
                ])->values()->all(),
            ], [
                'source' => $source,
                'note' => $note,
                'request_uuid' => $requestUuid,
                'request_fingerprint' => $requestFingerprint,
            ]);

            // The child, totals, reservations and packing tasks are one local
            // operation. If any synchronization fails, the outer transaction
            // removes the child as well, so submitting the form again cannot
            // create a second split for the same operator action.
            $this->reservations->syncForOrder($order);
            $this->reservations->syncForOrder($splitOrder);
            $this->packingTasks->syncForOrder($order);
            $this->packingTasks->syncForOrder($splitOrder);

            return $splitOrder;
        }, 3);

        if (! $wasCreated) {
            return $splitOrder;
        }

        try {
            $this->communication->sendOrderStatus($order->fresh() ?? $order, 'order_partial_created', [
                'child_order_id' => $splitOrder->id,
                'child_order_number' => $splitOrder->external_number ?: $splitOrder->external_id,
                'source' => $source,
                'note' => $note,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $splitOrder;
    }

    /**
     * A browser retry may safely return the previously created child only when
     * the complete split command is identical. Reusing the UUID with changed
     * quantities or options is an operator conflict, not an idempotent retry.
     *
     * @param  array<int,float>  $quantities
     * @param  array<string,mixed>  $context
     */
    private function splitRequestFingerprint(
        int $orderId,
        array $quantities,
        ?string $note,
        string $source,
        array $context,
    ): string {
        ksort($quantities, SORT_NUMERIC);

        return hash('sha256', json_encode([
            'order_id' => $orderId,
            'quantities' => $quantities,
            'note' => filled($note) ? trim((string) $note) : null,
            'source' => $source,
            'context' => $this->canonicalFingerprintValue($context),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalFingerprintValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalFingerprintValue($item), $value);
    }

    /**
     * @param  EloquentCollection<int, ExternalOrder>  $family
     * @return list<string>
     */
    private function splitBlockers(ExternalOrder $order, EloquentCollection $family): array
    {
        $reasons = $this->familyIntegrityBlockers($order, $family);

        if ($reasons !== []) {
            return $reasons;
        }

        $orderIds = $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $lineIds = ExternalOrderLine::query()->whereIn('external_order_id', $orderIds)->pluck('id');

        if (! in_array(mb_strtolower((string) $order->status), self::MUTABLE_STATUSES, true)) {
            $reasons[] = 'Zamówienie można podzielić tylko przed zakończeniem realizacji (status: oczekujące, w realizacji albo wstrzymane).';
        }

        if ($order->lines()->where('quantity', '>', 0)->doesntExist()) {
            $reasons[] = 'Zamówienie nie ma pozycji, które można wydzielić.';
        }

        if (OrderCancellation::query()
            ->whereIn('external_order_id', $orderIds)
            ->where('status', '!=', 'rejected')
            ->exists()) {
            $reasons[] = 'Dla tej rodziny rozpoczęto już proces anulowania.';
        }

        if ($family->contains(fn (ExternalOrder $member): bool => in_array(
            mb_strtolower((string) $member->status),
            ['cancellation-pending', 'cancelled', 'canceled', 'refunded', 'completed'],
            true,
        ))) {
            $reasons[] = 'Jedna z części jest anulowana, zwrócona albo zakończona.';
        }

        if ($family->contains(fn (ExternalOrder $member): bool => in_array(
            mb_strtolower((string) $member->fulfillment_status),
            ['awaiting_courier', 'shipped', 'problem'],
            true,
        ))) {
            $reasons[] = 'Jedna z części została już spakowana, przekazana kurierowi albo ma problem kompletacji.';
        }

        if ($family->contains(fn (ExternalOrder $member): bool => $member->shipmentLabels()->exists())) {
            $reasons[] = 'Najpierw anuluj i usuń wszystkie etykiety przesyłek tej rodziny.';
        }

        if ($family->contains(fn (ExternalOrder $member): bool => $this->hasShipmentIdentity((array) $member->raw_payload))) {
            $reasons[] = 'Zamówienie ma już identyfikator przesyłki zapisany przez sklep. Podział po utworzeniu przesyłki jest zablokowany.';
        }

        if (PackingTask::query()
            ->whereIn('external_order_id', $orderIds)
            ->where('status', '!=', 'cancelled')
            ->where(fn ($query) => $query->where('quantity_picked', '>', 0)
                ->orWhereIn('status', ['picked', 'packed', 'shipped', 'problem']))
            ->exists()) {
            $reasons[] = 'Nie można podzielić zamówienia po rozpoczęciu kompletacji lub pakowania.';
        }

        if ($family->contains(fn (ExternalOrder $member): bool => $this->fulfillmentStatus
            ->wzDocumentsForOrder($member)
            ->where('status', '!=', 'cancelled')
            ->exists())) {
            $reasons[] = 'Nie można podzielić zamówienia, dla którego istnieje aktywny dokument WZ. Anulowany WZ nie blokuje podziału.';
        }

        if (Invoice::withTrashed()->whereIn('external_order_id', $orderIds)->exists()) {
            $reasons[] = 'Nie można podzielić zamówienia po utworzeniu faktury lub proformy.';
        }

        if (ReturnCase::withTrashed()
            ->where(fn ($query) => $query->whereIn('external_order_id', $orderIds)
                ->orWhereHas('lines', fn ($lines) => $lines->whereIn('external_order_line_id', $lineIds)))
            ->exists()) {
            $reasons[] = 'Nie można podzielić zamówienia, dla którego rozpoczęto zwrot.';
        }

        $childIds = $family->whereNotIn('id', [(int) ($order->split_root_order_id ?: $order->id)])->pluck('id');

        if (($childIds->isNotEmpty() && CustomerPayment::query()->whereIn('external_order_id', $childIds)->exists())
            || CustomerPayment::query()->whereIn('external_order_id', $orderIds)->where('direction', 'outgoing')->exists()) {
            $reasons[] = 'Nie można dalej dzielić rodziny z płatnością przypisaną do części albo ze zwrotem środków.';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  list<array{line:ExternalOrderLine,source_quantity:float,split_quantity:float}>  $allocations
     * @return array{0:float,1:float} pozostała kwota rodzica, kwota potomka
     */
    private function allocatedTotals(ExternalOrder $order, array $allocations): array
    {
        $sourceCents = max(0, (int) round((float) $order->total_gross * 100));
        $allocatedByLine = collect($allocations)->keyBy(fn (array $allocation): int => (int) $allocation['line']->id);
        $allMoved = $order->lines->every(function (ExternalOrderLine $line) use ($allocatedByLine): bool {
            $allocated = (float) ($allocatedByLine->get($line->id)['split_quantity'] ?? 0);

            return (float) $line->quantity - $allocated <= 0.00001;
        });

        if ($allMoved) {
            return [0.0, $sourceCents / 100];
        }

        $totalWeight = $order->lines->sum(
            fn (ExternalOrderLine $line): float => $this->lineUnitGrossWeight($line) * (float) $line->quantity,
        );
        $movedWeight = collect($allocations)->sum(
            fn (array $allocation): float => $this->lineUnitGrossWeight($allocation['line']) * (float) $allocation['split_quantity'],
        );

        if ($totalWeight <= 0 || $movedWeight <= 0) {
            $totalWeight = (float) $order->lines->sum(fn (ExternalOrderLine $line): float => (float) $line->quantity);
            $movedWeight = (float) collect($allocations)->sum('split_quantity');
        }

        if ($totalWeight <= 0 || $movedWeight <= 0) {
            throw new RuntimeException('Nie udało się wyliczyć udziału kwoty dla wydzielanej części zamówienia.');
        }

        $childCents = min($sourceCents, max(0, (int) round($sourceCents * ($movedWeight / $totalWeight))));

        return [($sourceCents - $childCents) / 100, $childCents / 100];
    }

    private function lineUnitGrossWeight(ExternalOrderLine $line): float
    {
        $raw = (array) $line->raw_payload;
        $rawTotal = data_get($raw, 'total');
        $rawTax = data_get($raw, 'total_tax', 0);
        $sourceQuantity = (float) (
            data_get($raw, 'sempre_erp_source_quantity')
            ?: data_get($raw, 'quantity')
            ?: $line->quantity
        );

        if (is_numeric($rawTotal) && is_numeric($rawTax) && $sourceQuantity > 0) {
            return max(0, ((float) $rawTotal + (float) $rawTax) / $sourceQuantity);
        }

        return max(0, (float) ($line->unit_gross_price ?? 0));
    }

    /**
     * @param  EloquentCollection<int,StockBalance>  $sourceBalances
     */
    private function captureOriginalSnapshot(ExternalOrder $root, EloquentCollection $sourceBalances): void
    {
        $raw = (array) $root->raw_payload;
        $shippingDecisionExists = array_key_exists('sempre_erp_shipping_decision', $raw);
        $ledgerMaxIds = StockLedgerEntry::query()
            ->whereIn('warehouse_id', $sourceBalances->pluck('warehouse_id'))
            ->whereIn('product_id', $sourceBalances->pluck('product_id'))
            ->selectRaw('warehouse_id, product_id, MAX(id) as max_id')
            ->groupBy('warehouse_id', 'product_id')
            ->get()
            ->mapWithKeys(fn (StockLedgerEntry $entry): array => [
                ((int) $entry->warehouse_id).':'.((int) $entry->product_id) => (int) $entry->getAttribute('max_id'),
            ]);
        $reflectedQuantities = $sourceBalances
            ->mapWithKeys(function (StockBalance $balance) use ($ledgerMaxIds, $root): array {
                $quantities = (array) $balance->source_reflected_order_quantities;
                $externalId = (string) $root->external_id;

                return [(string) $balance->id => [
                    'warehouse_id' => (int) $balance->warehouse_id,
                    'product_id' => (int) $balance->product_id,
                    'exists' => array_key_exists($externalId, $quantities),
                    'quantity' => array_key_exists($externalId, $quantities)
                        ? (string) $quantities[$externalId]
                        : null,
                    'source' => [
                        'sales_channel_id' => $balance->source_sales_channel_id !== null
                            ? (int) $balance->source_sales_channel_id
                            : null,
                        'available_quantity' => $balance->source_available_quantity !== null
                            ? (string) $balance->source_available_quantity
                            : null,
                        'observed_at' => $balance->getRawOriginal('source_observed_at'),
                        'reflected_order_quantities' => $quantities,
                    ],
                    'ledger_max_id' => (int) ($ledgerMaxIds[
                        ((int) $balance->warehouse_id).':'.((int) $balance->product_id)
                    ] ?? 0),
                ]];
            })
            ->all();
        $raw['sempre_erp_split_original'] = [
            'version' => 4,
            'captured_at' => now()->startOfSecond()->toISOString(),
            'status' => $root->status,
            'fulfillment_status' => $root->fulfillment_status,
            'total_gross' => (string) $root->total_gross,
            'order' => [
                'sales_channel_id' => (int) $root->sales_channel_id,
                'customer_id' => $root->customer_id,
                'customer_external_account_id' => $root->customer_external_account_id,
                'wordpress_integration_id' => $root->wordpress_integration_id,
                'customer_match_method' => $root->customer_match_method,
                'external_id' => $root->external_id,
                'external_number' => $root->external_number,
                'status' => $root->status,
                'fulfillment_status' => $root->fulfillment_status,
                'currency' => $root->currency,
                'total_gross' => (string) $root->total_gross,
                'billing_data' => $root->billing_data,
                'shipping_data' => $root->shipping_data,
                'external_created_at' => $root->getRawOriginal('external_created_at'),
                'external_updated_at' => $root->getRawOriginal('external_updated_at'),
            ],
            'shipping_decision_exists' => $shippingDecisionExists,
            'shipping_decision' => $shippingDecisionExists ? $raw['sempre_erp_shipping_decision'] : null,
            'raw_payload' => $raw,
            'operational' => [
                'label_generation_attempts' => (int) $root->label_generation_attempts,
                'label_generation_next_at' => $root->label_generation_next_at?->toISOString(),
                'label_generation_last_error' => $root->label_generation_last_error,
                'woo_shipped_sync_status' => $root->woo_shipped_sync_status,
                'woo_shipped_sync_attempts' => (int) $root->woo_shipped_sync_attempts,
                'woo_shipped_sync_next_at' => $root->woo_shipped_sync_next_at?->toISOString(),
                'woo_shipped_sync_error' => $root->woo_shipped_sync_error,
            ],
            'source_reflected_order_quantities' => $reflectedQuantities,
            'lines' => $root->lines->map(fn (ExternalOrderLine $line): array => [
                'product_id' => $line->product_id,
                'external_line_id' => $line->external_line_id,
                'canonical_external_line_id' => $line->canonical_external_line_id,
                'sku' => $line->sku,
                'name' => $line->name,
                'quantity' => (string) $line->quantity,
                'unit_net_price' => $line->unit_net_price !== null ? (string) $line->unit_net_price : null,
                'unit_gross_price' => $line->unit_gross_price !== null ? (string) $line->unit_gross_price : null,
                'vat_rate' => $line->vat_rate !== null ? (string) $line->vat_rate : null,
                'raw_payload' => $line->raw_payload,
            ])->values()->all(),
        ];
        $root->update(['raw_payload' => $raw]);
    }

    /**
     * @param  EloquentCollection<int,ExternalOrderLine>  $lines
     * @return EloquentCollection<int,StockBalance>
     */
    private function lockSourceBalances(EloquentCollection $lines, int $salesChannelId): EloquentCollection
    {
        $productIds = $lines
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->map(fn (mixed $productId): int => (int) $productId)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return new EloquentCollection;
        }

        return StockBalance::query()
            ->whereIn('product_id', $productIds)
            ->where('source_sales_channel_id', $salesChannelId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Keep a Woo stock snapshot's already-reflected quantity constant while
     * moving part of an order to a new local external ID. Without this, the
     * reservation recalculation would add the child on top of the frozen root
     * entry and temporarily create stock that does not exist.
     *
     * @param  EloquentCollection<int,StockBalance>  $sourceBalances
     * @param  list<array{line:ExternalOrderLine,source_quantity:float,split_quantity:float}>  $allocations
     */
    private function reallocateReflectedQuantityForSplit(
        EloquentCollection $sourceBalances,
        ExternalOrder $sourceOrder,
        ExternalOrder $childOrder,
        array $allocations,
    ): void {
        $allocationsByLine = collect($allocations)->keyBy(
            fn (array $allocation): int => (int) $allocation['line']->id,
        );
        $sourceQuantitiesByProduct = [];
        $movedQuantitiesByProduct = [];

        foreach ($sourceOrder->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $productId = (int) $line->product_id;
            $allocation = $allocationsByLine->get((int) $line->id);
            $sourceQuantity = is_array($allocation)
                ? (float) $allocation['source_quantity']
                : (float) $line->quantity;
            $movedQuantity = is_array($allocation)
                ? (float) $allocation['split_quantity']
                : 0.0;
            $sourceQuantitiesByProduct[$productId] = (float) ($sourceQuantitiesByProduct[$productId] ?? 0)
                + $sourceQuantity;
            $movedQuantitiesByProduct[$productId] = (float) ($movedQuantitiesByProduct[$productId] ?? 0)
                + $movedQuantity;
        }

        foreach ($sourceBalances as $balance) {
            $productId = (int) $balance->product_id;
            $sourceQuantity = (float) ($sourceQuantitiesByProduct[$productId] ?? 0);
            $movedQuantity = (float) ($movedQuantitiesByProduct[$productId] ?? 0);
            $quantities = (array) $balance->source_reflected_order_quantities;
            $sourceExternalId = (string) $sourceOrder->external_id;

            if ($sourceQuantity <= 0 || $movedQuantity <= 0
                || ! array_key_exists($sourceExternalId, $quantities)
                || ! is_numeric($quantities[$sourceExternalId])) {
                continue;
            }

            $reflectedQuantity = max(0, (float) $quantities[$sourceExternalId]);
            $childQuantity = $reflectedQuantity * min(1, $movedQuantity / $sourceQuantity);
            $remainingQuantity = max(0, $reflectedQuantity - $childQuantity);
            unset($quantities[$sourceExternalId]);

            if ($remainingQuantity > 0.000001) {
                $quantities[$sourceExternalId] = $remainingQuantity;
            }

            if ($childQuantity > 0.000001) {
                $quantities[(string) $childOrder->external_id] = $childQuantity;
            }

            ksort($quantities, SORT_NATURAL);
            $balance->update(['source_reflected_order_quantities' => $quantities]);
        }
    }

    private function nextSplitIndex(ExternalOrder $order): int
    {
        return ExternalOrder::withTrashed()
            ->where('split_parent_order_id', $order->id)
            ->pluck('external_id')
            ->reduce(function (int $max, mixed $externalId): int {
                return preg_match('/-SPLIT-(\d+)$/', (string) $externalId, $matches) === 1
                    ? max($max, (int) $matches[1])
                    : $max;
            }, 0) + 1;
    }

    /** @return array{0:array<string,mixed>,1:int} */
    private function payloadForSplitChild(array $payload): array
    {
        $removed = 0;
        unset(
            $payload['sempre_erp_split_original'],
            $payload['sempre_erp_split_child_orders'],
            $payload['sempre_erp_split_allocations'],
            $payload['sempre_erp_split_import_adjusted_at'],
        );

        foreach (array_keys($payload) as $key) {
            if ($this->isShipmentIdentityKey((string) $key)) {
                unset($payload[$key]);
                $removed++;
            }
        }

        $payload['meta_data'] = $this->withoutShipmentMetadata((array) ($payload['meta_data'] ?? []), $removed);

        foreach ((array) ($payload['shipping_lines'] ?? []) as $index => $shippingLine) {
            if (! is_array($shippingLine)) {
                continue;
            }

            $shippingLine['meta_data'] = $this->withoutShipmentMetadata((array) ($shippingLine['meta_data'] ?? []), $removed);
            $payload['shipping_lines'][$index] = $shippingLine;
        }

        return [$payload, $removed];
    }

    /** @param list<mixed> $metadata @return list<mixed> */
    private function withoutShipmentMetadata(array $metadata, int &$removed): array
    {
        return collect($metadata)
            ->reject(function (mixed $meta) use (&$removed): bool {
                $reject = is_array($meta) && $this->isShipmentIdentityKey((string) ($meta['key'] ?? ''));

                if ($reject) {
                    $removed++;
                }

                return $reject;
            })
            ->values()
            ->all();
    }

    private function hasShipmentIdentity(array $payload): bool
    {
        foreach (array_keys($payload) as $key) {
            if (filled($payload[$key] ?? null) && $this->isShipmentIdentityKey((string) $key)) {
                return true;
            }
        }

        $sources = [(array) ($payload['meta_data'] ?? [])];

        foreach ((array) ($payload['shipping_lines'] ?? []) as $shippingLine) {
            if (is_array($shippingLine)) {
                $sources[] = (array) ($shippingLine['meta_data'] ?? []);
            }
        }

        return collect($sources)->flatten(1)->contains(
            fn (mixed $meta): bool => is_array($meta)
                && filled($meta['value'] ?? null)
                && $this->isShipmentIdentityKey((string) ($meta['key'] ?? '')),
        );
    }

    private function isShipmentIdentityKey(string $key): bool
    {
        $key = mb_strtolower(trim($key));

        if ($key === '') {
            return false;
        }

        $isInPostKey = str_contains($key, 'inpost')
            || str_contains($key, 'shipx')
            || str_contains($key, 'easypack');

        if ($isInPostKey && (str_contains($key, 'point') || str_contains($key, 'locker')
            || str_contains($key, 'machine') || str_contains($key, 'target'))) {
            return false;
        }

        if (str_contains($key, 'tracking') || str_contains($key, 'waybill') || str_contains($key, 'list_przewoz')) {
            return true;
        }

        if (str_contains($key, 'blpaczka')) {
            return str_contains($key, 'order_id')
                || str_contains($key, 'shipment')
                || str_contains($key, 'label');
        }

        return $isInPostKey
            && (str_contains($key, 'shipment') || str_contains($key, 'label')
                || str_contains($key, 'parcel_number') || str_contains($key, 'id'));
    }

    /** @return EloquentCollection<int, ExternalOrder> */
    private function familyOrders(ExternalOrder $order): EloquentCollection
    {
        $fresh = ExternalOrder::query()->find($order->id) ?? $order;
        $rootId = (int) ($fresh->split_root_order_id ?: $fresh->id);

        return ExternalOrder::query()
            ->where('sales_channel_id', $fresh->sales_channel_id)
            ->where(fn ($query) => $query
                ->whereKey($rootId)
                ->orWhere('split_root_order_id', $rootId)
                ->orWhere('id', $fresh->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @return list<string>
     */
    private function familyIntegrityBlockers(ExternalOrder $requested, EloquentCollection $family): array
    {
        $declaredRootId = (int) ($requested->split_root_order_id ?: $requested->id);
        $root = $family->firstWhere('id', $declaredRootId);

        if (! $root instanceof ExternalOrder || $root->split_root_order_id !== null) {
            return ['Powązania rodziny podziału są niespójne: nie znaleziono prawidłowego zamówienia głównego. Operację zablokowano, aby nie zmienić innego zamówienia.'];
        }

        if (ExternalOrder::query()
            ->where('split_root_order_id', $root->id)
            ->where('sales_channel_id', '!=', $root->sales_channel_id)
            ->exists()) {
            return ['Powązania rodziny podziału wskazują zamówienie z innego kanału sprzedaży. Wymagana jest ręczna korekta danych.'];
        }

        $byId = $family->keyBy('id');

        foreach ($family as $member) {
            if ((int) $member->sales_channel_id !== (int) $root->sales_channel_id) {
                return ['Części rodziny należą do różnych kanałów sprzedaży. Operację zablokowano.'];
            }

            if ((int) $member->id === (int) $root->id) {
                if ($member->split_parent_order_id !== null) {
                    return ['Zamówienie główne ma nieprawidłowe wskazanie rodzica podziału. Wymagana jest ręczna korekta danych.'];
                }

                continue;
            }

            if ((int) $member->split_root_order_id !== (int) $root->id) {
                return ['Jedna z części wskazuje inne zamówienie główne. Operację zablokowano.'];
            }

            $parent = $byId->get((int) $member->split_parent_order_id);
            $lineage = data_get($member->raw_payload, 'sempre_erp_split');

            if (! $parent instanceof ExternalOrder || ! is_array($lineage)
                || (int) ($lineage['parent_order_id'] ?? 0) !== (int) $parent->id
                || (string) ($lineage['parent_external_id'] ?? '') !== (string) $parent->external_id
                || (int) ($lineage['root_order_id'] ?? 0) !== (int) $root->id
                || (string) ($lineage['root_external_id'] ?? '') !== (string) $root->external_id) {
                return ['Nie można jednoznacznie potwierdzić pochodzenia jednej z części zamówienia. Operację zablokowano, aby nie objąć innego zamówienia.'];
            }

            if (($root->customer_id !== null && $member->customer_id !== null
                    && (int) $root->customer_id !== (int) $member->customer_id)
                || ($root->wordpress_integration_id !== null && $member->wordpress_integration_id !== null
                    && (int) $root->wordpress_integration_id !== (int) $member->wordpress_integration_id)) {
                return ['Jedna z części ma inną tożsamość klienta lub integracji. Wymagana jest ręczna weryfikacja rodziny.'];
            }

            $visited = [];
            $cursor = $member;

            while ((int) $cursor->id !== (int) $root->id) {
                if (isset($visited[$cursor->id])) {
                    return ['Powązania rodziny podziału zawierają cykl. Operację zablokowano.'];
                }

                $visited[$cursor->id] = true;
                $cursor = $byId->get((int) $cursor->split_parent_order_id);

                if (! $cursor instanceof ExternalOrder) {
                    return ['Łańcuch rodziców jednej z części jest niekompletny. Operację zablokowano.'];
                }
            }
        }

        return [];
    }

    /** @param list<int> $orderIds */
    private function withShippingLocks(array $orderIds, int $offset, callable $operation): mixed
    {
        if (! isset($orderIds[$offset])) {
            return $operation();
        }

        return Cache::lock('shipping-label-order-'.$orderIds[$offset], self::SHIPPING_LOCK_SECONDS)
            ->block(
                self::SHIPPING_LOCK_WAIT_SECONDS,
                fn (): mixed => $this->withShippingLocks($orderIds, $offset + 1, $operation),
            );
    }

    private function canonicalExternalLineId(ExternalOrderLine $line): ?string
    {
        $canonical = trim((string) (
            $line->canonical_external_line_id
            ?: data_get($line->raw_payload, 'sempre_erp_split.root_external_line_id')
            ?: data_get($line->raw_payload, 'id')
            ?: data_get($line->raw_payload, 'sempre_erp_split.source_external_line_id')
            ?: $line->external_line_id
        ));

        if ($canonical === '') {
            return null;
        }

        do {
            $previous = $canonical;
            $canonical = (string) preg_replace('/-S\d+$/', '', $canonical);
        } while ($canonical !== $previous);

        return $canonical;
    }
}
