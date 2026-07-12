<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\SalesChannel;
use App\Models\Warehouse;
use App\Models\WarehouseChannelRoute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class SalesChannelWarehouseResolver
{
    public function resolve(int $salesChannelId): Warehouse
    {
        $route = $this->allocationRoutes($salesChannelId)->first();

        return $route->warehouse;
    }

    /** @return Collection<int, WarehouseChannelRoute> */
    public function allocationRoutes(int $salesChannelId): Collection
    {
        $routes = WarehouseChannelRoute::query()
            ->with('warehouse')
            ->where('sales_channel_id', $salesChannelId)
            ->where('push_stock', true)
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
            ->orderBy('priority')
            ->orderBy('warehouse_id')
            ->get();

        if ($routes->isNotEmpty()) {
            return $routes;
        }

        $channel = SalesChannel::query()->find($salesChannelId);
        $channelCode = $channel?->code ?? ('CH'.$salesChannelId);
        $code = Str::upper(Str::limit('WC_'.preg_replace('/[^A-Za-z0-9_]/', '_', $channelCode), 40, ''));

        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => 'WooCommerce '.$channelCode,
                'type' => 'virtual',
                'allow_negative_stock' => false,
                'is_active' => true,
                'settings' => ['created_from' => 'sales_channel_resolver'],
            ],
        );

        $warehouse->routes()->firstOrCreate(
            ['sales_channel_id' => $salesChannelId],
            [
                'push_stock' => true,
                'allocation_strategy' => 'warehouse_balance',
                'stock_buffer' => 0,
                'priority' => 100,
            ],
        );

        return WarehouseChannelRoute::query()
            ->with('warehouse')
            ->where('warehouse_id', $warehouse->id)
            ->where('sales_channel_id', $salesChannelId)
            ->get();
    }
}
