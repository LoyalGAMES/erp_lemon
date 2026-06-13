<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ExportStockToWooCommerceJob;
use App\Models\SalesChannel;
use App\Models\StockSyncQueueItem;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\StockSyncQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockSyncController extends Controller
{
    public function rebuild(
        Request $request,
        StockSyncQueueService $stockSync,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'sales_channel_id' => [
                'nullable',
                'integer',
                Rule::exists('sales_channels', 'id'),
            ],
        ]);

        $salesChannelId = isset($validated['sales_channel_id']) && $validated['sales_channel_id'] !== ''
            ? (int) $validated['sales_channel_id']
            : null;

        $result = $stockSync->queueFullRebuild($salesChannelId);
        $channel = $salesChannelId !== null
            ? SalesChannel::query()->find($salesChannelId)
            : null;

        $audit->record(
            'stock_sync.full_rebuild_requested',
            null,
            null,
            [
                'queued' => $result['queued'],
                'skipped' => $result['skipped'],
                'sales_channel_ids' => $result['sales_channel_ids'],
                'product_count' => count($result['product_ids']),
            ],
            [
                'requested_sales_channel_id' => $salesChannelId,
                'requested_sales_channel_code' => $channel?->code,
            ],
        );

        $message = $salesChannelId !== null
            ? "Pełna synchronizacja stanów dla kanału {$channel?->code} została dodana do kolejki: {$result['queued']} pozycji."
            : "Pełna synchronizacja stanów dla wszystkich kanałów została dodana do kolejki: {$result['queued']} pozycji.";

        if ($result['skipped'] > 0) {
            $message .= " Pominięto {$result['skipped']} mapowań bez aktywnego routingu magazynu.";
        }

        return back()->with('status', $message);
    }

    public function retry(StockSyncQueueItem $item, AuditLogService $audit): RedirectResponse
    {
        if ($item->status !== 'failed') {
            return back()->with('error', 'Ponowić można tylko nieudany eksport stanu.');
        }

        $before = [
            'status' => $item->status,
            'last_error' => $item->last_error,
            'processed_at' => $item->processed_at?->toDateTimeString(),
            'metadata' => $item->metadata,
        ];

        $metadata = $item->metadata ?? [];
        $metadata['retry_count'] = (int) ($metadata['retry_count'] ?? 0) + 1;
        $metadata['last_retry_requested_at'] = now()->toDateTimeString();

        $item->update([
            'status' => 'pending',
            'last_error' => null,
            'processed_at' => null,
            'available_at' => now(),
            'metadata' => $metadata,
        ]);

        ExportStockToWooCommerceJob::dispatch($item->id);

        $item->refresh();
        $audit->record(
            'stock_sync.retry_requested',
            $item,
            $before,
            [
                'status' => $item->status,
                'available_at' => $item->available_at?->toDateTimeString(),
                'metadata' => $item->metadata,
            ],
            [
                'warehouse_id' => $item->warehouse_id,
                'product_id' => $item->product_id,
                'sales_channel_id' => $item->sales_channel_id,
            ],
        );

        return back()->with('status', 'Eksport stanu został ponownie dodany do kolejki.');
    }
}
