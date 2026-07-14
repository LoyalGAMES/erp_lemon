<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Models\WarehouseDocument;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class OrderMutationLock
{
    private const LOCK_SECONDS = 900;

    private const WAIT_SECONDS = 15;

    public function forOrder(ExternalOrder $order, callable $operation): mixed
    {
        return $this->forOrderIds([(int) $order->id], $operation);
    }

    /**
     * Serialize a mutation against the root order and every physical order in
     * its split family. Locking only the order referenced by a return would
     * leave cancellation of another split member able to race the mutation.
     */
    public function forOrderFamily(ExternalOrder $order, callable $operation): mixed
    {
        return $this->forOrderIds($this->familyOrderIds([(int) $order->id]), $operation);
    }

    /**
     * @param  iterable<int, ExternalOrder|int>  $orders
     */
    public function forOrders(iterable $orders, callable $operation): mixed
    {
        $orderIds = collect($orders)
            ->map(fn (ExternalOrder|int $order): int => $order instanceof ExternalOrder
                ? (int) $order->id
                : (int) $order)
            ->all();

        return $this->forOrderIds($orderIds, $operation);
    }

    public function forWarehouseDocument(WarehouseDocument $document, callable $operation): mixed
    {
        return $this->forOrderIds($this->linkedOrderIds($document), $operation);
    }

    /**
     * @param  list<int>  $orderIds
     */
    private function forOrderIds(array $orderIds, callable $operation): mixed
    {
        $orderIds = collect($orderIds)
            ->filter(fn (int $orderId): bool => $orderId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        try {
            return $this->acquire($orderIds, 0, $operation);
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'To zamówienie jest właśnie aktualizowane lub obsługiwane w procesie pakowania. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<int>  $orderIds
     */
    private function acquire(array $orderIds, int $offset, callable $operation): mixed
    {
        if (! isset($orderIds[$offset])) {
            return $operation();
        }

        return Cache::lock($this->lockKey($orderIds[$offset]), self::LOCK_SECONDS)
            ->block(
                self::WAIT_SECONDS,
                fn (): mixed => $this->acquire($orderIds, $offset + 1, $operation),
            );
    }

    /**
     * @return list<int>
     */
    private function linkedOrderIds(WarehouseDocument $document): array
    {
        if ($document->type === 'RX') {
            $orderIds = ReturnCase::query()
                ->where(function (Builder $query) use ($document): void {
                    $query
                        ->where('warehouse_document_id', $document->id)
                        ->orWhereHas('lines', fn (Builder $lineQuery): Builder => $lineQuery
                            ->where('warehouse_document_id', $document->id));
                })
                ->whereNotNull('external_order_id')
                ->pluck('external_order_id')
                ->map(fn (mixed $orderId): int => (int) $orderId)
                ->all();

            return $this->familyOrderIds($orderIds);
        }

        if ($document->type !== 'WZ') {
            return [];
        }

        $metadata = (array) $document->metadata;
        $externalId = trim((string) ($metadata['external_order_id'] ?? ''));
        $externalNumber = trim((string) ($metadata['external_order_number'] ?? ''));
        $externalReference = trim((string) $document->external_reference);
        $identifiers = array_values(array_unique(array_filter([
            $externalId,
            $externalNumber,
            $externalReference,
        ], fn (string $value): bool => $value !== '')));

        if ($identifiers === []) {
            return [];
        }

        $salesChannelId = filter_var(
            $metadata['sales_channel_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        return ExternalOrder::query()
            ->when(
                $salesChannelId !== false,
                fn (Builder $query): Builder => $query->where('sales_channel_id', $salesChannelId),
            )
            ->where(function (Builder $query) use ($identifiers): void {
                $query
                    ->whereIn('external_id', $identifiers)
                    ->orWhereIn('external_number', $identifiers);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->all();
    }

    /**
     * @param  list<int>  $orderIds
     * @return list<int>
     */
    private function familyOrderIds(array $orderIds): array
    {
        $rootIds = ExternalOrder::query()
            ->whereKey($orderIds)
            ->get(['id', 'split_root_order_id'])
            ->map(fn (ExternalOrder $order): int => (int) ($order->split_root_order_id ?: $order->id))
            ->unique()
            ->values()
            ->all();

        if ($rootIds === []) {
            return [];
        }

        return ExternalOrder::query()
            ->whereIn('id', $rootIds)
            ->orWhereIn('split_root_order_id', $rootIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->all();
    }

    private function lockKey(int $orderId): string
    {
        return 'packing-fulfillment-order-'.$orderId;
    }
}
