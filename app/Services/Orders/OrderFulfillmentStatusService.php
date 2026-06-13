<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\WarehouseDocument;
use Illuminate\Database\Eloquent\Builder;

final class OrderFulfillmentStatusService
{
    public function latestWz(ExternalOrder $order): ?WarehouseDocument
    {
        return $this->wzDocumentsForOrder($order)
            ->orderByRaw("case when status = 'posted' then 0 else 1 end")
            ->latest()
            ->first();
    }

    public function hasPostedWz(ExternalOrder $order): bool
    {
        return $this->wzDocumentsForOrder($order)
            ->where('status', 'posted')
            ->exists();
    }

    public function wzDocumentsForOrder(ExternalOrder $order): Builder
    {
        return WarehouseDocument::query()
            ->where('type', 'WZ')
            ->where(function (Builder $query) use ($order): void {
                $query
                    ->where('metadata->external_order_id', (string) $order->external_id)
                    ->orWhere('metadata->external_order_number', (string) $order->external_number)
                    ->orWhere('external_reference', (string) $order->external_number);
            })
            ->where(function (Builder $query) use ($order): void {
                $query
                    ->where('metadata->sales_channel_id', $order->sales_channel_id)
                    ->orWhereNull('metadata->sales_channel_id');
            });
    }
}
