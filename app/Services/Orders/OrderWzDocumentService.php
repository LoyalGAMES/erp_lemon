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
    ) {
    }

    /**
     * @return list<WarehouseDocument>
     */
    public function ensureDrafts(
        ExternalOrder $order,
        string $source = 'external_order',
        ?string $notes = null,
    ): array {
        $existing = $this->fulfillmentStatus->latestWz($order);

        if ($existing instanceof WarehouseDocument) {
            return [$existing];
        }

        return $this->createDrafts($order, $source, $notes);
    }

    /**
     * @return list<WarehouseDocument>
     */
    private function createDrafts(ExternalOrder $order, string $source, ?string $notes): array
    {
        return DB::transaction(function () use ($order, $source, $notes): array {
            $reservations = StockReservation::query()
                ->with(['product', 'warehouse'])
                ->where('sales_channel_id', $order->sales_channel_id)
                ->where('external_order_id', $order->external_id)
                ->where('status', 'active')
                ->get();

            if ($reservations->isEmpty()) {
                return [];
            }

            $documents = [];

            foreach ($reservations->groupBy('warehouse_id') as $warehouseId => $warehouseReservations) {
                $document = WarehouseDocument::query()->create([
                    'number' => $this->documentNumbers->next('WZ'),
                    'type' => 'WZ',
                    'status' => 'draft',
                    'source_warehouse_id' => (int) $warehouseId,
                    'document_date' => now(),
                    'external_reference' => $order->external_number,
                    'notes' => $notes ?: 'WZ z zamówienia WooCommerce ' . $order->external_number,
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

                $documents[] = $document;
            }

            return $documents;
        });
    }
}
