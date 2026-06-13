<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\SalesChannel;
use App\Models\Warehouse;
use Illuminate\Support\Str;

final class SalesChannelWarehouseResolver
{
    public function resolve(int $salesChannelId): Warehouse
    {
        $warehouse = Warehouse::query()
            ->whereHas('routes', fn ($query) => $query
                ->where('sales_channel_id', $salesChannelId)
                ->where('push_stock', true))
            ->orderBy('code')
            ->first();

        if ($warehouse !== null) {
            return $warehouse;
        }

        $channel = SalesChannel::query()->find($salesChannelId);
        $channelCode = $channel?->code ?? ('CH' . $salesChannelId);
        $code = Str::upper(Str::limit('WC_' . preg_replace('/[^A-Za-z0-9_]/', '_', $channelCode), 40, ''));

        $warehouse = Warehouse::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => 'WooCommerce ' . $channelCode,
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

        return $warehouse;
    }
}
