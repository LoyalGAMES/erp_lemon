<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\StockReservationService;
use App\Services\Packing\PackingTaskService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OrderSplitService
{
    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly PackingTaskService $packingTasks,
        private readonly CustomerCommunicationService $communication,
        private readonly OrderMutationLock $orderLock,
    ) {}

    /**
     * Wydziela pozycje zamówienia do nowego zamówienia potomnego i przelicza
     * rezerwacje oraz zadania pakowania obu zamówień.
     *
     * @param  array<int, float>  $quantities  mapa line_id => ilość do wydzielenia
     */
    public function split(ExternalOrder $order, array $quantities, ?string $note = null, string $source = 'manual'): ExternalOrder
    {
        return $this->orderLock->forOrder(
            $order,
            fn (): ExternalOrder => $this->splitWhileLocked($order, $quantities, $note, $source),
        );
    }

    /**
     * @param  array<int, float>  $quantities
     */
    private function splitWhileLocked(
        ExternalOrder $order,
        array $quantities,
        ?string $note,
        string $source,
    ): ExternalOrder {
        $quantities = collect($quantities)
            ->mapWithKeys(fn ($quantity, $lineId): array => [(int) $lineId => (float) $quantity])
            ->filter(fn (float $quantity): bool => $quantity > 0)
            ->all();

        if ($quantities === []) {
            throw new RuntimeException('Podaj ilość co najmniej jednej pozycji do wydzielenia.');
        }

        $splitOrder = DB::transaction(function () use ($order, $quantities, $note, $source): ExternalOrder {
            $orderSnapshot = ExternalOrder::query()->findOrFail($order->id);
            $rootOrderId = (int) ($orderSnapshot->split_root_order_id ?: $orderSnapshot->id);
            $lockedOrders = ExternalOrder::query()
                ->whereIn('id', array_values(array_unique([$rootOrderId, (int) $orderSnapshot->id])))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $order = $lockedOrders->get($orderSnapshot->id);

            if (! $order instanceof ExternalOrder) {
                throw new RuntimeException('Nie znaleziono zamówienia do podziału.');
            }

            if ($order->hasCancellationOperation()
                || in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
                throw new RuntimeException('Nie można podzielić anulowanego zamówienia ani zamówienia w trakcie anulacji.');
            }

            $order->load('lines');
            $rootOrder = $lockedOrders->get($rootOrderId) ?: $order;

            $splitIndex = ExternalOrder::query()
                ->where('sales_channel_id', $order->sales_channel_id)
                ->where('external_id', 'like', $order->external_id.'-SPLIT-%')
                ->count() + 1;

            $splitOrder = ExternalOrder::query()->create([
                'split_parent_order_id' => $order->id,
                'split_root_order_id' => $rootOrder->id,
                'sales_channel_id' => $order->sales_channel_id,
                'external_id' => $order->external_id.'-SPLIT-'.$splitIndex,
                'external_number' => ($order->external_number ?: $order->external_id).'/S'.$splitIndex,
                'status' => in_array($order->status, ['pending', 'processing', 'on-hold'], true) ? $order->status : 'processing',
                'currency' => $order->currency,
                'total_gross' => 0,
                'billing_data' => $order->billing_data,
                'shipping_data' => $order->shipping_data,
                'raw_payload' => array_replace_recursive((array) $order->raw_payload, [
                    'sempre_erp_split' => [
                        'parent_order_id' => $order->id,
                        'parent_external_id' => $order->external_id,
                        'root_order_id' => $rootOrder->id,
                        'root_external_id' => $rootOrder->external_id,
                        'note' => $note,
                        'source' => $source,
                        'created_at' => now()->toISOString(),
                    ],
                ]),
                'external_created_at' => $order->external_created_at,
                'external_updated_at' => now(),
            ]);
            $splitAllocations = (array) data_get($order->raw_payload, 'sempre_erp_split_allocations', []);

            foreach ($quantities as $lineId => $quantity) {
                /** @var ExternalOrderLine|null $line */
                $line = $order->lines->firstWhere('id', $lineId);

                if (! $line instanceof ExternalOrderLine) {
                    continue;
                }

                $currentQuantity = (float) $line->quantity;
                $splitQuantity = min($quantity, $currentQuantity);

                if ($splitQuantity <= 0) {
                    continue;
                }

                $splitOrder->lines()->create([
                    'product_id' => $line->product_id,
                    'external_line_id' => $line->external_line_id ? $line->external_line_id.'-S'.$splitIndex : null,
                    'canonical_external_line_id' => $this->canonicalExternalLineId($line),
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
                            'root_external_line_id' => $this->canonicalExternalLineId($line),
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
            $rawPayload['sempre_erp_split_child_orders'] = array_values(array_filter([
                ...((array) data_get($order->raw_payload, 'sempre_erp_split_child_orders', [])),
                $splitOrder->external_id,
            ]));
            $rawPayload['sempre_erp_split_allocations'] = $splitAllocations;

            $order->update([
                'total_gross' => $this->grossTotalFromLines($order->refresh()->lines),
                'raw_payload' => $rawPayload,
            ]);
            $splitOrder->update(['total_gross' => $this->grossTotalFromLines($splitOrder->lines)]);

            return $splitOrder;
        });

        $this->reservations->syncForOrder($order);
        $this->reservations->syncForOrder($splitOrder);
        $this->packingTasks->syncForOrder($order);
        $this->packingTasks->syncForOrder($splitOrder);
        $this->communication->sendOrderStatus($order->fresh() ?? $order, 'order_partial_created', [
            'child_order_id' => $splitOrder->id,
            'child_order_number' => $splitOrder->external_number ?: $splitOrder->external_id,
            'source' => $source,
            'note' => $note,
        ]);

        return $splitOrder;
    }

    /**
     * @param  iterable<int, ExternalOrderLine>  $lines
     */
    private function grossTotalFromLines(iterable $lines): float
    {
        return (float) collect($lines)->sum(
            fn (ExternalOrderLine $line): float => (float) ($line->unit_gross_price ?? 0) * (float) $line->quantity,
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
