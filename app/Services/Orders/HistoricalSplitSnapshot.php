<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\PackingTask;
use App\Models\ShippingLabel;
use App\Models\WarehouseDocument;

final class HistoricalSplitSnapshot
{
    public const VERSION = 5;

    /** @param array<string,mixed>|null $snapshot */
    public static function isVerified(?array $snapshot): bool
    {
        return is_array($snapshot)
            && (int) ($snapshot['version'] ?? 0) === self::VERSION
            && (string) data_get($snapshot, 'legacy_adoption.type') === 'verified_historical_split'
            && is_array($snapshot['lines'] ?? null)
            && is_array($snapshot['packing_tasks'] ?? null)
            && is_array(data_get($snapshot, 'preserved_artifacts.shipping_labels'))
            && is_array(data_get($snapshot, 'preserved_artifacts.warehouse_documents'));
    }

    public static function packingTaskFingerprint(PackingTask $task): string
    {
        return self::fingerprint([
            'id' => (int) $task->id,
            'external_order_id' => (int) $task->external_order_id,
            'external_line_id' => (string) $task->external_line_id,
            'product_id' => $task->product_id !== null ? (int) $task->product_id : null,
            'sku' => (string) $task->sku,
            'quantity_required' => (string) $task->quantity_required,
            'quantity_picked' => (string) $task->quantity_picked,
            'status' => (string) $task->status,
            'picked_at' => $task->picked_at?->toISOString(),
            'packed_at' => $task->packed_at?->toISOString(),
            'metadata' => (array) $task->metadata,
        ]);
    }

    public static function shippingLabelFingerprint(ShippingLabel $label): string
    {
        return self::shippingLabelFingerprintForStatus($label, (string) $label->status);
    }

    public static function shippingLabelFingerprintForStatus(ShippingLabel $label, string $status): string
    {
        return self::fingerprint([
            'id' => (int) $label->id,
            'external_order_id' => (int) $label->external_order_id,
            'purpose' => (string) $label->purpose,
            'status' => $status,
            'provider' => (string) $label->provider,
            'label_number' => (string) $label->label_number,
            'tracking_number' => (string) $label->tracking_number,
            'tracking_status' => (string) $label->tracking_status,
            'picked_up_at' => $label->picked_up_at?->toISOString(),
            'generated_at' => $label->generated_at?->toISOString(),
            'created_at' => $label->created_at?->toISOString(),
            'idempotency_key' => (string) $label->idempotency_key,
            'sha256' => (string) $label->sha256,
            'path' => (string) $label->path,
            'has_courier_pickup_evidence' => $label->hasCourierPickupEvidence(),
            'financial' => [
                'cash_on_delivery' => data_get($label->response_payload, 'financial.cash_on_delivery'),
                'requested_cod_amount' => data_get($label->response_payload, 'financial.requested_cod_amount'),
                'order_total' => data_get($label->response_payload, 'financial.order_total'),
                'currency' => data_get($label->response_payload, 'financial.currency'),
                'generation_requested_cod_amount' => data_get(
                    $label->response_payload,
                    'generation.request.cod_amount',
                ),
            ],
        ]);
    }

    public static function warehouseDocumentFingerprint(WarehouseDocument $document): string
    {
        $document->loadMissing(['lines', 'ledgerEntries']);

        return self::fingerprint([
            'id' => (int) $document->id,
            'number' => (string) $document->number,
            'type' => (string) $document->type,
            'status' => (string) $document->status,
            'source_warehouse_id' => $document->source_warehouse_id !== null
                ? (int) $document->source_warehouse_id
                : null,
            'destination_warehouse_id' => $document->destination_warehouse_id !== null
                ? (int) $document->destination_warehouse_id
                : null,
            'external_reference' => (string) $document->external_reference,
            'order_fulfillment_key' => (string) $document->order_fulfillment_key,
            'posted_at' => $document->posted_at?->toISOString(),
            'cancelled_at' => $document->cancelled_at?->toISOString(),
            'created_at' => $document->created_at?->toISOString(),
            'metadata' => (array) $document->metadata,
            'lines' => $document->lines->sortBy('id')->map(fn ($line): array => [
                'id' => (int) $line->id,
                'product_id' => (int) $line->product_id,
                'quantity' => (string) $line->quantity,
                'metadata' => (array) $line->metadata,
            ])->values()->all(),
            'ledger_entries' => $document->ledgerEntries->sortBy('id')->map(fn ($entry): array => [
                'id' => (int) $entry->id,
                'warehouse_document_line_id' => (int) $entry->warehouse_document_line_id,
                'warehouse_id' => (int) $entry->warehouse_id,
                'product_id' => (int) $entry->product_id,
                'quantity_change' => (string) $entry->quantity_change,
                'direction' => (string) $entry->direction,
                'posted_at' => $entry->posted_at?->toISOString(),
                'metadata' => (array) $entry->metadata,
            ])->values()->all(),
        ]);
    }

    /** @param array<string,mixed> $snapshot @return array<int,string> */
    public static function preservedLabelFingerprints(array $snapshot): array
    {
        return collect((array) data_get($snapshot, 'preserved_artifacts.shipping_labels', []))
            ->filter(fn (mixed $item): bool => is_array($item)
                && (int) ($item['id'] ?? 0) > 0
                && filled($item['fingerprint'] ?? null))
            ->mapWithKeys(fn (array $item): array => [
                (int) $item['id'] => (string) $item['fingerprint'],
            ])
            ->all();
    }

    /** @param array<string,mixed> $snapshot @return array<int,string> */
    public static function preservedWarehouseDocumentFingerprints(array $snapshot): array
    {
        return collect((array) data_get($snapshot, 'preserved_artifacts.warehouse_documents', []))
            ->filter(fn (mixed $item): bool => is_array($item)
                && (int) ($item['id'] ?? 0) > 0
                && filled($item['fingerprint'] ?? null))
            ->mapWithKeys(fn (array $item): array => [
                (int) $item['id'] => (string) $item['fingerprint'],
            ])
            ->all();
    }

    /** @param array<string,mixed> $snapshot @return array<int,array<string,mixed>> */
    public static function preservedPackingTasks(array $snapshot): array
    {
        return collect((array) ($snapshot['packing_tasks'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item)
                && (int) ($item['original_task_id'] ?? 0) > 0
                && filled($item['fingerprint'] ?? null))
            ->mapWithKeys(fn (array $item): array => [
                (int) $item['original_task_id'] => $item,
            ])
            ->all();
    }

    /** @param array<string,mixed> $value */
    public static function fingerprint(array $value): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($value),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn (mixed $item): mixed => self::canonicalize($item), $value);
    }
}
