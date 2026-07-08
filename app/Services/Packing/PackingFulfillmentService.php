<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\PackingTask;
use App\Models\ShippingLabel;
use App\Models\WarehouseDocument;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderWzDocumentService;
use App\Services\Shipping\ShippingLabelService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class PackingFulfillmentService
{
    public function __construct(
        private readonly PackingTaskService $packingTasks,
        private readonly ShippingLabelService $shippingLabels,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly OrderWzDocumentService $orderWzDocuments,
        private readonly WarehouseDocumentPostingService $documentPosting,
        private readonly OrderInvoiceService $invoices,
        private readonly InvoiceWooCommerceUploadService $invoiceUpload,
        private readonly WooCommerceOrderStatusService $orderStatuses,
        private readonly AuditLogService $audit,
        private readonly DocumentAutomationSettingsService $automationSettings,
    ) {
    }

    /**
     * @return array{packed:int,label:?ShippingLabel,wz:list<WarehouseDocument>,invoice:?Invoice,woo_status:?string,warnings:list<string>}
     */
    public function completePackedOrder(ExternalOrder $order): array
    {
        $warnings = [];
        $createWzIfMissing = $this->automationSettings->actionEnabled('packing.order.packed', 'order.wz.create_if_missing');
        $postWz = $this->automationSettings->actionEnabled('packing.order.packed', 'order.wz.post');
        $issueInvoiceOnPack = $this->automationSettings->actionEnabled('packing.order.packed', 'order.invoice.create_upload');
        $packed = $this->packingTasks->markOrderPacked($order);
        $order = ExternalOrder::query()->with(['shippingLabels', 'invoices'])->findOrFail($order->id);

        $label = $order->shippingLabels->firstWhere('status', 'generated');
        if (! $label instanceof ShippingLabel) {
            try {
                $label = $this->shippingLabels->generateForOrder($order);
            } catch (Throwable $exception) {
                $warnings[] = 'Etykieta: ' . $exception->getMessage();
            }
        }

        $wzDocuments = [];
        if ($postWz) {
            try {
                $wzDocuments = $this->ensurePostedWz($order, $createWzIfMissing);
            } catch (Throwable $exception) {
                $warnings[] = 'WZ: ' . $exception->getMessage();
            }
        } elseif ($createWzIfMissing) {
            try {
                $wzDocuments = $this->orderWzDocuments->ensureDrafts(
                    $order,
                    'packing',
                    'Automatyczne WZ z pakowania zamówienia WooCommerce ' . $order->external_number,
                );
            } catch (Throwable $exception) {
                $warnings[] = 'WZ: ' . $exception->getMessage();
            }
        }

        $invoice = null;
        if ($issueInvoiceOnPack && ($wzDocuments !== [] || $this->fulfillmentStatus->hasPostedWz($order))) {
            try {
                $invoice = $this->invoices->createForOrder($order);
                if (data_get($invoice->metadata, 'woocommerce_upload.status') !== 'success') {
                    $this->invoiceUpload->upload($invoice);
                }
            } catch (Throwable $exception) {
                $warnings[] = 'Faktura/WooCommerce: ' . $exception->getMessage();
            }
        }

        $wooStatus = null;
        try {
            $result = $this->orderStatuses->markReadyForShipment($order);
            $wooStatus = (string) ($result['status'] ?? '');
        } catch (Throwable $exception) {
            $warnings[] = 'Status WooCommerce: ' . $exception->getMessage();
        }

        $this->markPackedTasksMetadata($order, [
            'label_id' => $label?->id,
            'wz_document_ids' => collect($wzDocuments)->pluck('id')->values()->all(),
            'invoice_id' => $invoice?->id,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ]);

        $this->audit->record('packing.order_completed', $order, null, [
            'packed_tasks' => $packed,
            'label_id' => $label?->id,
            'wz_document_ids' => collect($wzDocuments)->pluck('id')->values()->all(),
            'invoice_id' => $invoice?->id,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ]);

        return [
            'packed' => $packed,
            'label' => $label,
            'wz' => $wzDocuments,
            'invoice' => $invoice,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{orders:int,tasks:int,warnings:list<string>}
     */
    public function markCourierPickedUp(string $courier): array
    {
        $courier = trim($courier);

        if ($courier === '') {
            throw new RuntimeException('Nie wskazano kuriera.');
        }

        $orders = ExternalOrder::query()
            ->whereHas('packingTasks', fn ($query) => $query
                ->where('status', 'packed')
                ->where('courier', $courier))
            ->with(['packingTasks' => fn ($query) => $query
                ->where('status', 'packed')
                ->where('courier', $courier)])
            ->get();

        if ($orders->isEmpty()) {
            throw new RuntimeException('Brak paczek oczekujących na tego kuriera.');
        }

        $warnings = [];
        $taskCount = 0;
        $invoiceCount = 0;

        foreach ($orders as $order) {
            try {
                $this->orderStatuses->markShipped($order);
            } catch (Throwable $exception) {
                $warnings[] = "Zamówienie {$order->external_number}: {$exception->getMessage()}";
                continue;
            }

            if ($this->automationSettings->actionEnabled('packing.courier.picked_up', 'order.invoice.create_upload')) {
                try {
                    $invoice = $this->invoices->createForOrder($order);
                    if (data_get($invoice->metadata, 'woocommerce_upload.status') !== 'success') {
                        $this->invoiceUpload->upload($invoice);
                    }
                    $invoiceCount++;
                } catch (Throwable $exception) {
                    $warnings[] = "Faktura {$order->external_number}: {$exception->getMessage()}";
                }
            }

            foreach ($order->packingTasks as $task) {
                $metadata = (array) $task->metadata;
                $metadata['courier_pickup'] = [
                    'courier' => $courier,
                    'picked_up_at' => now()->toISOString(),
                ];

                $task->update([
                    'status' => 'shipped',
                    'metadata' => $metadata,
                ]);
                $taskCount++;
            }
        }

        $this->audit->record('packing.courier_picked_up', null, null, [
            'courier' => $courier,
            'orders' => $orders->count(),
            'tasks' => $taskCount,
            'invoices' => $invoiceCount,
            'warnings' => $warnings,
        ]);

        return [
            'orders' => $orders->count(),
            'tasks' => $taskCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Oznacza pojedyncze zamówienie jako odebrane przez kuriera — używane przez
     * automatyczne śledzenie przesyłek. Zwraca liczbę zaktualizowanych pozycji.
     *
     * @param array<string, mixed> $context np. tracking_number, tracking_status, source
     * @return array{tasks:int,warnings:list<string>}
     */
    public function markOrderPickedUpByCourier(ExternalOrder $order, array $context = []): array
    {
        $tasks = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', 'packed')
            ->get();

        if ($tasks->isEmpty()) {
            return ['tasks' => 0, 'warnings' => []];
        }

        $warnings = [];

        try {
            $this->orderStatuses->markShipped($order);
        } catch (Throwable $exception) {
            $warnings[] = "Status WooCommerce {$order->external_number}: {$exception->getMessage()}";
        }

        if ($this->automationSettings->actionEnabled('packing.courier.picked_up', 'order.invoice.create_upload')) {
            try {
                $invoice = $this->invoices->createForOrder($order);
                if (data_get($invoice->metadata, 'woocommerce_upload.status') !== 'success') {
                    $this->invoiceUpload->upload($invoice);
                }
            } catch (Throwable $exception) {
                $warnings[] = "Faktura {$order->external_number}: {$exception->getMessage()}";
            }
        }

        foreach ($tasks as $task) {
            $metadata = (array) $task->metadata;
            $metadata['courier_pickup'] = array_merge([
                'courier' => $task->courier,
                'picked_up_at' => now()->toISOString(),
                'source' => 'tracking',
            ], $context);

            $task->update([
                'status' => 'shipped',
                'metadata' => $metadata,
            ]);
        }

        $this->audit->record('packing.courier_picked_up_tracked', $order, null, [
            'tasks' => $tasks->count(),
            'context' => $context,
            'warnings' => $warnings,
        ]);

        return ['tasks' => $tasks->count(), 'warnings' => $warnings];
    }

    /**
     * @return array{tasks:int,woo_status:?string,warnings:list<string>}
     */
    public function undoPackedOrder(ExternalOrder $order, ?string $reason = null): array
    {
        $reason = trim((string) $reason);
        $rolledBackAt = now();

        $tasksCount = DB::transaction(function () use ($order, $reason, $rolledBackAt): int {
            $tasks = PackingTask::query()
                ->where('external_order_id', $order->id)
                ->where('status', 'packed')
                ->lockForUpdate()
                ->get();

            if ($tasks->isEmpty()) {
                throw new RuntimeException('To zamówienie nie ma paczek oczekujących na kuriera do cofnięcia.');
            }

            foreach ($tasks as $task) {
                $metadata = (array) $task->metadata;
                $metadata['packing_rollback'] = [
                    'reason' => $reason !== '' ? $reason : null,
                    'rolled_back_at' => $rolledBackAt->toISOString(),
                    'previous_packed_at' => $task->packed_at?->toISOString(),
                    'previous_packing_completion' => $metadata['packing_completion'] ?? null,
                ];
                unset($metadata['courier_pickup']);

                $task->update([
                    'status' => 'picked',
                    'packed_at' => null,
                    'metadata' => $metadata,
                ]);
            }

            return $tasks->count();
        });

        $warnings = [];
        $wooStatus = null;

        try {
            $result = $this->orderStatuses->markPackingRollback($order);
            $wooStatus = (string) ($result['status'] ?? '');
        } catch (Throwable $exception) {
            $wooStatus = 'processing';
            $warnings[] = 'Status WooCommerce: ' . $exception->getMessage();

            $order->refresh();
            $raw = (array) $order->raw_payload;
            $raw['sempre_erp_status_sync'] = [
                'operation' => 'order_packing_rollback_local_only',
                'status' => $wooStatus,
                'synced_at' => now()->toISOString(),
                'warning' => $exception->getMessage(),
            ];

            $order->update([
                'status' => $wooStatus,
                'raw_payload' => $raw,
                'external_updated_at' => now(),
            ]);
        }

        $this->audit->record('packing.order_unpacked', $order->refresh(), null, [
            'tasks' => $tasksCount,
            'reason' => $reason !== '' ? $reason : null,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ]);

        return [
            'tasks' => $tasksCount,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<WarehouseDocument>
     */
    private function ensurePostedWz(ExternalOrder $order, bool $createIfMissing): array
    {
        $existing = $this->fulfillmentStatus->latestWz($order);

        if ($existing instanceof WarehouseDocument) {
            if ($existing->status === 'draft') {
                $this->documentPosting->post($existing);
                $existing->refresh();
            }

            return $existing->status === 'posted' ? [$existing] : [];
        }

        if (! $createIfMissing) {
            return [];
        }

        $documents = $this->orderWzDocuments->ensureDrafts(
            $order,
            'packing',
            'Automatyczne WZ z pakowania zamówienia WooCommerce ' . $order->external_number,
        );

        if ($documents === []) {
            throw new RuntimeException('Brak aktywnych rezerwacji dla tego zamówienia.');
        }

        foreach ($documents as $document) {
            $this->documentPosting->post($document);
            $document->refresh();
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function markPackedTasksMetadata(ExternalOrder $order, array $result): void
    {
        $order->packingTasks()
            ->where('status', 'packed')
            ->get()
            ->each(function ($task) use ($result): void {
                $metadata = (array) $task->metadata;
                $metadata['packing_completion'] = array_merge($result, [
                    'completed_at' => now()->toISOString(),
                ]);

                $task->update(['metadata' => $metadata]);
            });
    }
}
