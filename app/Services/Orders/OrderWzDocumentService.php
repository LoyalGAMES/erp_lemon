<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\StockReservation;
use App\Models\WarehouseDocument;
use App\Services\Inventory\WarehouseDocumentNumberService;
use Illuminate\Support\Facades\DB;

final class OrderWzDocumentService
{
    public function __construct(
        private readonly WarehouseDocumentNumberService $documentNumbers,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
    ) {}

    /**
     * @return list<WarehouseDocument>
     */
    public function ensureDrafts(
        ExternalOrder $order,
        string $source = 'external_order',
        ?string $notes = null,
    ): array {
        return DB::transaction(function () use ($order, $source, $notes): array {
            $order = ExternalOrder::query()
                ->lockForUpdate()
                ->findOrFail($order->id);
            $existingDocuments = $this->fulfillmentStatus
                ->wzDocumentsForOrder($order)
                ->lockForUpdate()
                ->get();
            $legacyDocuments = $existingDocuments
                ->filter(fn (WarehouseDocument $document): bool => blank($document->order_fulfillment_key))
                ->values();

            $reservations = StockReservation::query()
                ->with(['product', 'warehouse'])
                ->where('sales_channel_id', $order->sales_channel_id)
                ->where('external_order_id', $order->external_id)
                ->where('status', 'active')
                ->orderBy('warehouse_id')
                ->orderBy('product_id')
                ->lockForUpdate()
                ->get();

            if ($reservations->isEmpty()) {
                return $existingDocuments->values()->all();
            }

            $documents = [];

            foreach ($reservations->groupBy('warehouse_id') as $warehouseId => $warehouseReservations) {
                $fulfillmentKey = $this->fulfillmentKey($order, (int) $warehouseId);
                $document = $existingDocuments->firstWhere('order_fulfillment_key', $fulfillmentKey)
                    ?? $legacyDocuments
                        ->where('source_warehouse_id', (int) $warehouseId)
                        ->sortByDesc(fn (WarehouseDocument $legacyDocument): int => match ($legacyDocument->status) {
                            'posted' => 2,
                            'draft' => 1,
                            default => 0,
                        })
                        ->first()
                    ?? WarehouseDocument::query()
                        ->where('order_fulfillment_key', $fulfillmentKey)
                        ->lockForUpdate()
                        ->first();

                if (! $document instanceof WarehouseDocument) {
                    $document = WarehouseDocument::query()->create([
                        'number' => $this->documentNumbers->next('WZ'),
                        'type' => 'WZ',
                        'status' => 'draft',
                        'source_warehouse_id' => (int) $warehouseId,
                        'document_date' => now(),
                        'external_reference' => $order->external_number,
                        'order_fulfillment_key' => $fulfillmentKey,
                        'notes' => $notes ?: 'WZ z zamówienia WooCommerce '.$order->external_number,
                        'metadata' => [
                            'source' => $source,
                            'external_order_id' => $order->external_id,
                            'external_order_number' => $order->external_number,
                            'sales_channel_id' => $order->sales_channel_id,
                        ],
                    ]);

                    foreach ($warehouseReservations->groupBy('product_id') as $productId => $productReservations) {
                        $document->lines()->create([
                            'product_id' => (int) $productId,
                            'quantity' => $productReservations->sum(fn (StockReservation $reservation): float => (float) $reservation->quantity),
                            'metadata' => [
                                'source' => 'stock_reservation',
                                'reservation_ids' => $productReservations->pluck('id')->values()->all(),
                            ],
                        ]);
                    }
                }

                $documents[] = $document;
            }

            return $documents;
        }, 3);
    }

    private function fulfillmentKey(ExternalOrder $order, int $warehouseId): string
    {
        return 'order-wz:'.hash(
            'sha256',
            implode('|', [
                (int) $order->sales_channel_id,
                (string) $order->external_id,
                $warehouseId,
            ]),
        );
    }
}
