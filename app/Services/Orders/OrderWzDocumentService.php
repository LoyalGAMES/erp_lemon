<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\StockReservation;
use App\Models\WarehouseDocument;
use App\Models\WarehouseDocumentLine;
use App\Services\Inventory\WarehouseDocumentNumberService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class OrderWzDocumentService
{
    /** @var list<string> */
    private const ADDRESS_FIELDS = [
        'first_name',
        'last_name',
        'company',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'email',
        'phone',
    ];

    /** @var list<string> */
    private const TAX_ID_FIELDS = [
        'nip',
        'vat_number',
        'billing_nip',
        'billing_vat_number',
        '_billing_nip',
        '_billing_vat_number',
        '_lemon_erp_billing_nip',
    ];

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
        $order->refresh();

        if ($order->hasCancellationOperation()
            || in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
            throw new RuntimeException('Nie można utworzyć WZ dla anulowanego zamówienia ani podczas trwającej anulacji.');
        }

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

            $reservationsByWarehouse = $reservations->groupBy('warehouse_id');
            $activeWarehouseIds = $reservationsByWarehouse
                ->keys()
                ->map(fn (mixed $warehouseId): int => (int) $warehouseId)
                ->all();

            $this->cancelStaleManagedDrafts(
                $existingDocuments,
                $activeWarehouseIds,
                $order,
                $source,
            );

            if ($reservations->isEmpty()) {
                return [];
            }

            $documents = [];

            foreach ($reservationsByWarehouse as $warehouseId => $warehouseReservations) {
                $warehouseId = (int) $warehouseId;
                $fulfillmentKey = $this->fulfillmentKey($order, $warehouseId);
                $document = $existingDocuments->firstWhere('order_fulfillment_key', $fulfillmentKey)
                    ?? WarehouseDocument::query()
                        ->where('order_fulfillment_key', $fulfillmentKey)
                        ->lockForUpdate()
                        ->first();

                if (! $document instanceof WarehouseDocument) {
                    $document = $this->legacyDocumentForWarehouse(
                        $order,
                        $legacyDocuments,
                        $warehouseId,
                    );
                }

                if ($document instanceof WarehouseDocument && $document->status === 'posted') {
                    // A posted WZ is an immutable stock record. Order edits must never rewrite it.
                    $documents[] = $document;

                    continue;
                }

                if ($document instanceof WarehouseDocument && $document->status === 'cancelled') {
                    if (! $this->wasAutoCancelled($document)) {
                        // A person may have cancelled this document intentionally. Do not undo that decision.
                        continue;
                    }

                    $this->reactivate($document);
                }

                if (! $document instanceof WarehouseDocument) {
                    $document = WarehouseDocument::query()->create([
                        'number' => $this->documentNumbers->next('WZ'),
                        'type' => 'WZ',
                        'status' => 'draft',
                        'source_warehouse_id' => $warehouseId,
                        'document_date' => now(),
                        'external_reference' => $order->external_number,
                        'order_fulfillment_key' => $fulfillmentKey,
                        'notes' => $notes ?: 'WZ z zamówienia WooCommerce '.$order->external_number,
                        'metadata' => $this->documentMetadata($order, $source),
                    ]);
                }

                if ($document->status !== 'draft') {
                    continue;
                }

                $this->syncDraft(
                    $document,
                    $warehouseReservations,
                    $order,
                    $source,
                    $fulfillmentKey,
                );

                $documents[] = $document->fresh();
            }

            return $documents;
        }, 3);
    }

    /**
     * @param  EloquentCollection<int, WarehouseDocument>  $documents
     * @param  list<int>  $activeWarehouseIds
     */
    private function cancelStaleManagedDrafts(
        EloquentCollection $documents,
        array $activeWarehouseIds,
        ExternalOrder $order,
        string $source,
    ): void {
        $documents
            ->filter(fn (WarehouseDocument $document): bool => $document->status === 'draft'
                && $this->isManaged($document)
                && $document->source_warehouse_id !== null
                && ! in_array((int) $document->source_warehouse_id, $activeWarehouseIds, true))
            ->each(function (WarehouseDocument $document) use ($order, $source): void {
                $metadata = $this->documentMetadata($order, $source, (array) $document->metadata);
                $syncMetadata = (array) ($metadata['order_wz_sync'] ?? []);
                $syncMetadata['auto_cancelled'] = true;
                $syncMetadata['auto_cancelled_at'] = now()->toISOString();
                $metadata['order_wz_sync'] = $syncMetadata;

                $document->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancelled_by' => null,
                    'metadata' => $metadata,
                ]);
            });
    }

    private function reactivate(WarehouseDocument $document): void
    {
        $metadata = (array) $document->metadata;
        $syncMetadata = (array) ($metadata['order_wz_sync'] ?? []);
        $syncMetadata['auto_cancelled'] = false;
        $syncMetadata['reactivated_at'] = now()->toISOString();
        unset($syncMetadata['auto_cancelled_at']);
        $metadata['order_wz_sync'] = $syncMetadata;

        $document->update([
            'status' => 'draft',
            'cancelled_at' => null,
            'cancelled_by' => null,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  Collection<int, StockReservation>  $reservations
     */
    private function syncDraft(
        WarehouseDocument $document,
        Collection $reservations,
        ExternalOrder $order,
        string $source,
        string $fulfillmentKey,
    ): void {
        $document->update([
            'order_fulfillment_key' => $fulfillmentKey,
            'metadata' => $this->documentMetadata($order, $source, (array) $document->metadata),
        ]);

        $existingLines = $document->lines()
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('product_id');
        $retainedLineIds = [];

        foreach ($reservations->groupBy('product_id') as $productId => $productReservations) {
            /** @var WarehouseDocumentLine|null $line */
            $line = $existingLines->get($productId)?->first(
                fn (WarehouseDocumentLine $candidate): bool => data_get($candidate->metadata, 'source') === 'stock_reservation',
            ) ?? $existingLines->get($productId)?->first();
            $lineMetadata = array_merge((array) $line?->metadata, [
                'source' => 'stock_reservation',
                'reservation_ids' => $productReservations->pluck('id')->values()->all(),
            ]);
            $quantity = $productReservations->sum(
                fn (StockReservation $reservation): float => (float) $reservation->quantity,
            );

            if ($line instanceof WarehouseDocumentLine) {
                // Update only the reservation-owned values; keep location, prices, lot and operator notes.
                $line->update([
                    'quantity' => $quantity,
                    'metadata' => $lineMetadata,
                ]);
            } else {
                $line = $document->lines()->create([
                    'product_id' => (int) $productId,
                    'quantity' => $quantity,
                    'metadata' => $lineMetadata,
                ]);
            }

            $retainedLineIds[] = $line->id;
        }

        $document->lines()
            ->whereNotIn('id', $retainedLineIds)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function documentMetadata(
        ExternalOrder $order,
        string $source,
        array $existing = [],
    ): array {
        $syncMetadata = array_merge((array) ($existing['order_wz_sync'] ?? []), [
            'managed' => true,
            'auto_cancelled' => false,
            'last_synced_at' => now()->toISOString(),
        ]);

        return array_merge($existing, [
            'source' => $existing['source'] ?? $source,
            'external_order_id' => $order->external_id,
            'external_order_number' => $order->external_number,
            'sales_channel_id' => $order->sales_channel_id,
            'order_snapshot' => $this->orderSnapshot($order),
            'order_wz_sync' => $syncMetadata,
        ]);
    }

    /**
     * @return array{
     *     billing: array<string, string>,
     *     shipping: array<string, string>,
     *     payment: array<string, string>,
     *     delivery: array<string, string>,
     *     customer_note: string|null,
     *     nip: string|null,
     *     pickup_point: string|null
     * }
     */
    private function orderSnapshot(ExternalOrder $order): array
    {
        $rawPayload = (array) $order->raw_payload;

        return [
            'billing' => $this->addressSnapshot($order->billing_data),
            'shipping' => $this->addressSnapshot($order->shipping_data),
            'payment' => $this->paymentSnapshot($rawPayload),
            'delivery' => $this->deliverySnapshot($order, $rawPayload),
            'customer_note' => $this->cleanString($rawPayload['customer_note'] ?? null, 2000),
            'nip' => $this->taxId($order, $rawPayload),
            'pickup_point' => $this->pickupPoint($order, $rawPayload),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function addressSnapshot(mixed $address): array
    {
        $address = is_array($address) ? $address : [];
        $snapshot = [];

        foreach (self::ADDRESS_FIELDS as $field) {
            $value = $this->cleanString($address[$field] ?? null, 500);

            if ($value !== null) {
                $snapshot[$field] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, string>
     */
    private function deliverySnapshot(ExternalOrder $order, array $rawPayload): array
    {
        $shippingLine = collect((array) ($rawPayload['shipping_lines'] ?? []))
            ->first(fn (mixed $line): bool => is_array($line));

        if (! is_array($shippingLine)) {
            return [];
        }

        $fields = [
            'method_id' => $shippingLine['method_id'] ?? null,
            'method_title' => $shippingLine['method_title'] ?? null,
            'total' => $shippingLine['total'] ?? null,
            'currency' => $order->currency,
        ];
        $snapshot = [];

        foreach ($fields as $field => $value) {
            $value = $this->cleanString($value, 500);

            if ($value !== null) {
                $snapshot[$field] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array<string, string>
     */
    private function paymentSnapshot(array $rawPayload): array
    {
        $fields = [
            'method' => 'payment_method',
            'method_title' => 'payment_method_title',
            'transaction_id' => 'transaction_id',
            'paid_at' => 'date_paid',
        ];
        $snapshot = [];

        foreach ($fields as $snapshotField => $payloadField) {
            $value = $this->cleanString($rawPayload[$payloadField] ?? null, 500);

            if ($value !== null) {
                $snapshot[$snapshotField] = $value;
            }
        }

        if (! isset($snapshot['paid_at'])) {
            $paidAt = $this->cleanString($rawPayload['date_paid_gmt'] ?? null, 500);

            if ($paidAt !== null) {
                $snapshot['paid_at'] = $paidAt;
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function taxId(ExternalOrder $order, array $rawPayload): ?string
    {
        $billing = is_array($order->billing_data) ? $order->billing_data : [];

        foreach (self::TAX_ID_FIELDS as $field) {
            $value = $this->cleanString($billing[$field] ?? null, 100);

            if ($value !== null) {
                return $value;
            }
        }

        foreach ((array) ($rawPayload['meta_data'] ?? []) as $meta) {
            if (! is_array($meta) || ! in_array((string) ($meta['key'] ?? ''), self::TAX_ID_FIELDS, true)) {
                continue;
            }

            $value = $this->cleanString($meta['value'] ?? null, 100);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function pickupPoint(ExternalOrder $order, array $rawPayload): ?string
    {
        $shipping = is_array($order->shipping_data) ? $order->shipping_data : [];
        $candidates = [
            $rawPayload['sempre_erp_target_point'] ?? null,
            $shipping['pickup_point'] ?? null,
            $shipping['paczkomat'] ?? null,
            $shipping['target_point'] ?? null,
            $shipping['parcel_machine'] ?? null,
            $shipping['locker'] ?? null,
        ];
        $metaSources = [(array) ($rawPayload['meta_data'] ?? [])];

        foreach ((array) ($rawPayload['shipping_lines'] ?? []) as $shippingLine) {
            if (is_array($shippingLine)) {
                $metaSources[] = (array) ($shippingLine['meta_data'] ?? []);
            }
        }

        foreach ($metaSources as $metaData) {
            foreach ($metaData as $meta) {
                if (! is_array($meta)) {
                    continue;
                }

                $key = mb_strtolower((string) ($meta['key'] ?? ''));

                if ($this->isPickupPointKey($key)) {
                    $candidates[] = $meta['value'] ?? null;
                }
            }
        }

        foreach ($candidates as $candidate) {
            $value = $this->cleanString($candidate, 120);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function isPickupPointKey(string $key): bool
    {
        if (str_contains($key, 'blpaczka') && str_contains($key, 'point')) {
            return true;
        }

        foreach (['pickup_point', 'paczkomat', 'target_point', 'parcel_machine', 'locker', 'easypack'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function cleanString(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function isManaged(WarehouseDocument $document): bool
    {
        return data_get($document->metadata, 'order_wz_sync.managed') === true
            || filled($document->order_fulfillment_key);
    }

    /**
     * @param  EloquentCollection<int, WarehouseDocument>  $knownLegacyDocuments
     */
    private function legacyDocumentForWarehouse(
        ExternalOrder $order,
        EloquentCollection $knownLegacyDocuments,
        int $warehouseId,
    ): ?WarehouseDocument {
        $unscopedCandidates = $this->fulfillmentStatus
            ->unscopedLegacyWzCandidatesForOrder($order)
            ->where('source_warehouse_id', $warehouseId)
            ->lockForUpdate()
            ->get()
            ->filter(fn (WarehouseDocument $document): bool => $this->isEligibleLegacyCandidate($document));

        if ($unscopedCandidates->isNotEmpty()
            && ! $this->fulfillmentStatus->canAssociateUnscopedLegacyDocuments($order)) {
            $numbers = $unscopedCandidates->pluck('number')->implode(', ');

            throw new \RuntimeException(
                "Nie można automatycznie przypisać starszego WZ ({$numbers}) do zamówienia {$order->external_number}: "
                .'jego numer lub identyfikator występuje w więcej niż jednym kanale sprzedaży. '
                .'Uzupełnij kanał sprzedaży w dokumencie WZ i ponów synchronizację.',
            );
        }

        $candidates = $knownLegacyDocuments
            ->where('source_warehouse_id', $warehouseId)
            ->filter(fn (WarehouseDocument $document): bool => $this->isEligibleLegacyCandidate($document))
            ->merge($unscopedCandidates)
            ->unique('id')
            ->values();

        if ($candidates->count() > 1) {
            $numbers = $candidates->pluck('number')->implode(', ');

            throw new \RuntimeException(
                "Nie można automatycznie zsynchronizować WZ dla zamówienia {$order->external_number} "
                ."w magazynie #{$warehouseId}: znaleziono więcej niż jeden starszy dokument ({$numbers}). "
                .'Pozostaw jeden właściwy szkic albo uzupełnij przypisanie dokumentu i ponów synchronizację.',
            );
        }

        /** @var WarehouseDocument|null $candidate */
        $candidate = $candidates->first();

        return $candidate;
    }

    private function isEligibleLegacyCandidate(WarehouseDocument $document): bool
    {
        return blank($document->order_fulfillment_key)
            && ($document->status === 'posted'
                || $document->status === 'draft'
                || $this->isManaged($document));
    }

    private function wasAutoCancelled(WarehouseDocument $document): bool
    {
        return data_get($document->metadata, 'order_wz_sync.auto_cancelled') === true;
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
