<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\StockBalance;
use App\Models\WarehouseChannelRoute;

final class ChannelStockAvailabilityService
{
    /**
     * @return array{quantity:float,breakdown:list<array{warehouse_id:int,warehouse_code:?string,available:float,buffer:float,contribution:float}>}
     */
    public function availabilityForProduct(int $salesChannelId, int $productId): array
    {
        $routes = WarehouseChannelRoute::query()
            ->with('warehouse')
            ->where('sales_channel_id', $salesChannelId)
            ->where('push_stock', true)
            ->orderBy('priority')
            ->get();

        $warehouseIds = $routes->pluck('warehouse_id')->values()->all();
        $balances = StockBalance::query()
            ->where('product_id', $productId)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get()
            ->keyBy('warehouse_id');

        $breakdown = $routes
            ->map(function (WarehouseChannelRoute $route) use ($balances): array {
                $available = (float) ($balances->get($route->warehouse_id)?->quantity_available ?? 0);
                $buffer = max(0, (float) $route->stock_buffer);
                $contribution = max(0, $available - $buffer);

                return [
                    'warehouse_id' => (int) $route->warehouse_id,
                    'warehouse_code' => $route->warehouse?->code,
                    'available' => $available,
                    'buffer' => $buffer,
                    'contribution' => $contribution,
                ];
            })
            ->values()
            ->all();

        return [
            'quantity' => array_sum(array_column($breakdown, 'contribution')),
            'breakdown' => $breakdown,
        ];
    }
}
