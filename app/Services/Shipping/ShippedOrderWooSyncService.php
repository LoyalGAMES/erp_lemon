<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\ExternalOrder;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Throwable;

final class ShippedOrderWooSyncService
{
    public function __construct(
        private readonly WooCommerceOrderStatusService $statuses,
    ) {}

    /**
     * @return array{checked:int,synced:int,failed:int,warnings:list<string>}
     */
    public function retry(int $limit = 25): array
    {
        $orders = ExternalOrder::query()
            ->whereNotIn('status', ['cancellation-pending', 'cancelled', 'refunded'])
            ->whereDoesntHave('cancellation', fn ($query) => $query->where('status', '!=', 'rejected'))
            ->where('fulfillment_status', 'shipped')
            ->whereIn('woo_shipped_sync_status', ['pending', 'failed'])
            ->where(function ($query): void {
                $query->whereNull('woo_shipped_sync_next_at')
                    ->orWhere('woo_shipped_sync_next_at', '<=', now());
            })
            ->orderBy('woo_shipped_sync_next_at')
            ->oldest('id')
            ->limit(max(1, $limit))
            ->get();

        $summary = ['checked' => 0, 'synced' => 0, 'failed' => 0, 'warnings' => []];

        foreach ($orders as $order) {
            $claimed = ExternalOrder::query()
                ->whereKey($order->id)
                ->whereNotIn('status', ['cancellation-pending', 'cancelled', 'refunded'])
                ->whereDoesntHave('cancellation', fn ($query) => $query->where('status', '!=', 'rejected'))
                ->whereIn('woo_shipped_sync_status', ['pending', 'failed'])
                ->where(function ($query): void {
                    $query->whereNull('woo_shipped_sync_next_at')
                        ->orWhere('woo_shipped_sync_next_at', '<=', now());
                })
                ->update(['woo_shipped_sync_next_at' => now()->addMinutes(10)]);

            if ($claimed !== 1) {
                continue;
            }

            $summary['checked']++;

            $order->refresh();
            if ($order->hasCancellationOperation()
                || in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
                $order->update([
                    'woo_shipped_sync_status' => 'skipped',
                    'woo_shipped_sync_next_at' => null,
                    'woo_shipped_sync_error' => 'Synchronizacja wysyłki wyłączona z powodu anulowania zamówienia.',
                ]);

                continue;
            }

            try {
                $result = $this->statuses->markShipped($order);
                $order->update([
                    'woo_shipped_sync_status' => ($result['skipped'] ?? false) ? 'skipped' : 'success',
                    'woo_shipped_sync_attempts' => 0,
                    'woo_shipped_sync_next_at' => null,
                    'woo_shipped_sync_error' => null,
                ]);
                $summary['synced']++;
            } catch (Throwable $exception) {
                $attempts = max(0, (int) $order->woo_shipped_sync_attempts) + 1;
                $order->update([
                    'woo_shipped_sync_status' => 'failed',
                    'woo_shipped_sync_attempts' => $attempts,
                    'woo_shipped_sync_next_at' => now()->addMinutes(min(360, 5 * (2 ** min(6, $attempts - 1)))),
                    'woo_shipped_sync_error' => $exception->getMessage(),
                ]);
                $summary['failed']++;
                $summary['warnings'][] = "Zamówienie {$order->external_number}: {$exception->getMessage()}";
            }
        }

        return $summary;
    }
}
