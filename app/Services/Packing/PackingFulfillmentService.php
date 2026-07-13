<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\PackingTask;
use App\Models\PrintJob;
use App\Models\ShippingLabel;
use App\Models\WarehouseDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderWzDocumentService;
use App\Services\Printing\ShippingLabelPrintQueueService;
use App\Services\Shipping\ShippingLabelService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class PackingFulfillmentService
{
    private const ORDER_LOCK_SECONDS = 900;

    public function __construct(
        private readonly PackingTaskService $packingTasks,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly OrderWzDocumentService $orderWzDocuments,
        private readonly WarehouseDocumentPostingService $documentPosting,
        private readonly OrderInvoiceService $invoices,
        private readonly InvoiceWooCommerceUploadService $invoiceUpload,
        private readonly WooCommerceOrderStatusService $orderStatuses,
        private readonly AuditLogService $audit,
        private readonly DocumentAutomationSettingsService $automationSettings,
        private readonly ShippingLabelPrintQueueService $printQueue,
        private readonly CustomerCommunicationService $communication,
        private readonly ShippingLabelService $shippingLabels,
    ) {}

    /**
     * @param  array{code:string,name:string,printer_name:string,segment:string}|null  $printStation
     * @return array{packed:int,label:?ShippingLabel,print_job:?PrintJob,wz:list<WarehouseDocument>,invoice:?Invoice,woo_status:?string,warnings:list<string>}
     */
    public function completePackedOrder(ExternalOrder $order, ?array $printStation = null): array
    {
        try {
            return Cache::lock($this->orderLockKey($order), self::ORDER_LOCK_SECONDS)
                ->block(15, fn (): array => $this->completePackedOrderWhileLocked($order, $printStation));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'To zamówienie jest właśnie pakowane albo aktualizowane po odbiorze przez kuriera. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * Generuje etykietę, kolejkuje jej wydruk i kończy pakowanie jako jedną
     * idempotentną operację użytkownika.
     *
     * @param  array{code:string,name:string,printer_name:string,segment:string}|null  $printStation
     * @return array{packed:int,label:ShippingLabel,print_job:PrintJob,wz:list<WarehouseDocument>,invoice:?Invoice,woo_status:?string,warnings:list<string>,already_completed:bool}
     */
    public function completePackedOrderWithLabel(
        ExternalOrder $order,
        ?CourierAccount $courierAccount,
        string $parcelTemplate,
        ?array $printStation,
    ): array {
        if ($printStation === null) {
            throw new RuntimeException('Wybierz stanowisko pakowania z przypisaną drukarką Windows.');
        }

        if (trim((string) ($printStation['printer_name'] ?? '')) === '') {
            throw new RuntimeException('Wybrane stanowisko nie ma przypisanej drukarki Windows.');
        }

        try {
            return Cache::lock($this->orderLockKey($order), self::ORDER_LOCK_SECONDS)
                ->block(15, fn (): array => $this->completePackedOrderWithLabelWhileLocked(
                    $order,
                    $courierAccount,
                    $parcelTemplate,
                    $printStation,
                ));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'To zamówienie jest właśnie pakowane. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  array{code:string,name:string,printer_name:string,segment:string}  $printStation
     * @return array{packed:int,label:ShippingLabel,print_job:PrintJob,wz:list<WarehouseDocument>,invoice:?Invoice,woo_status:?string,warnings:list<string>,already_completed:bool}
     */
    private function completePackedOrderWithLabelWhileLocked(
        ExternalOrder $order,
        ?CourierAccount $courierAccount,
        string $parcelTemplate,
        array $printStation,
    ): array {
        $order = ExternalOrder::query()->with('invoices')->findOrFail($order->id);
        $tasks = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->get();

        if ($tasks->isEmpty()) {
            throw new RuntimeException('Brak pozycji pakowania dla tego zamówienia.');
        }

        if ($order->fulfillment_status === 'awaiting_courier'
            && $tasks->every(fn (PackingTask $task): bool => $task->status === 'packed')) {
            $label = $this->generatedLabelFor($order);

            if (! $label instanceof ShippingLabel) {
                throw new RuntimeException('Zamówienie jest spakowane, ale nie ma zapisanej etykiety wysyłkowej.');
            }

            $printJob = $this->printQueue->enqueueForStation($label, $printStation, 'packing.order.packed');

            if (! $printJob instanceof PrintJob) {
                throw new RuntimeException('Nie udało się dodać etykiety do kolejki automatycznego wydruku.');
            }

            return [
                'packed' => $tasks->count(),
                'label' => $label,
                'print_job' => $printJob,
                'wz' => [],
                'invoice' => $order->invoices->sortByDesc('id')->first(),
                'woo_status' => $order->status,
                'warnings' => [],
                'already_completed' => true,
            ];
        }

        if ($tasks->contains(fn (PackingTask $task): bool => $task->status !== 'picked')) {
            throw new RuntimeException('Najpierw zbierz wszystkie pozycje z tego zamówienia.');
        }

        $label = $this->shippingLabels->generateForOrder($order, $courierAccount, $parcelTemplate);

        [$packed, $printJob] = DB::transaction(function () use ($order, $label, $printStation): array {
            $packed = $this->packingTasks->markOrderPacked($order);
            $printJob = $this->printQueue->enqueueForStation($label, $printStation, 'packing.order.packed');

            if (! $printJob instanceof PrintJob) {
                throw new RuntimeException('Nie udało się dodać etykiety do kolejki automatycznego wydruku.');
            }

            return [$packed, $printJob];
        });

        return $this->finishPackedOrderAutomation(
            $order,
            $printStation,
            $label,
            $packed,
            $printJob,
            [],
        ) + ['already_completed' => false];
    }

    /**
     * @param  array{code:string,name:string,printer_name:string,segment:string}|null  $printStation
     * @return array{packed:int,label:?ShippingLabel,print_job:?PrintJob,wz:list<WarehouseDocument>,invoice:?Invoice,woo_status:?string,warnings:list<string>}
     */
    private function completePackedOrderWhileLocked(ExternalOrder $order, ?array $printStation = null): array
    {
        $warnings = [];
        $label = $this->generatedLabelFor($order);

        $packed = $this->packingTasks->markOrderPacked($order);

        $printJob = null;
        if (! $label instanceof ShippingLabel) {
            $warnings[] = 'Wydruk: najpierw wygeneruj etykietę, wybierając gabaryt paczki A, B albo C.';
        } elseif ($printStation === null) {
            $warnings[] = 'Wydruk: nie wybrano stanowiska pakowania dla tej sesji.';
        } elseif (trim((string) ($printStation['printer_name'] ?? '')) === '') {
            $warnings[] = 'Wydruk: stanowisko nie ma przypisanej drukarki Windows.';
        } else {
            try {
                $printJob = $this->printQueue->enqueueForStation($label, $printStation, 'packing.order.packed');
            } catch (Throwable $exception) {
                $warnings[] = 'Wydruk: '.$exception->getMessage();
            }
        }

        return $this->finishPackedOrderAutomation(
            $order,
            $printStation,
            $label,
            $packed,
            $printJob,
            $warnings,
        );
    }

    private function generatedLabelFor(ExternalOrder $order): ?ShippingLabel
    {
        return ShippingLabel::query()
            ->shipments()
            ->where('external_order_id', $order->id)
            ->where('status', 'generated')
            ->latest('generated_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  array{code:string,name:string,printer_name:string,segment:string}|null  $printStation
     * @param  list<string>  $warnings
     * @return array{packed:int,label:?ShippingLabel,print_job:?PrintJob,wz:list<WarehouseDocument>,invoice:?Invoice,woo_status:?string,warnings:list<string>}
     */
    private function finishPackedOrderAutomation(
        ExternalOrder $order,
        ?array $printStation,
        ?ShippingLabel $label,
        int $packed,
        ?PrintJob $printJob,
        array $warnings,
    ): array {
        $createWzIfMissing = $this->automationSettings->actionEnabled('packing.order.packed', 'order.wz.create_if_missing');
        $postWz = $this->automationSettings->actionEnabled('packing.order.packed', 'order.wz.post');
        $issueInvoiceOnPack = $this->automationSettings->actionEnabled('packing.order.packed', 'order.invoice.create_upload');
        $order = ExternalOrder::query()->with('invoices')->findOrFail($order->id);

        $wzDocuments = [];
        if ($postWz) {
            try {
                $wzDocuments = $this->ensurePostedWz($order, $createWzIfMissing);
            } catch (Throwable $exception) {
                $warnings[] = 'WZ: '.$exception->getMessage();
            }
        } elseif ($createWzIfMissing) {
            try {
                $wzDocuments = $this->orderWzDocuments->ensureDrafts(
                    $order,
                    'packing',
                    'Automatyczne WZ z pakowania zamówienia WooCommerce '.$order->external_number,
                );
            } catch (Throwable $exception) {
                $warnings[] = 'WZ: '.$exception->getMessage();
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
                $warnings[] = 'Faktura/WooCommerce: '.$exception->getMessage();
            }
        }

        $wooStatus = null;
        try {
            $result = $this->orderStatuses->markReadyForShipment($order);
            $wooStatus = (string) ($result['status'] ?? '');
        } catch (Throwable $exception) {
            $warnings[] = 'Status WooCommerce: '.$exception->getMessage();
        }

        $order->update(['fulfillment_status' => 'awaiting_courier']);

        $this->markPackedTasksMetadata($order, [
            'label_id' => $label?->id,
            'print_job_id' => $printJob?->id,
            'print_station' => $printStation,
            'wz_document_ids' => collect($wzDocuments)->pluck('id')->values()->all(),
            'invoice_id' => $invoice?->id,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ]);

        $this->audit->record('packing.order_completed', $order, null, [
            'packed_tasks' => $packed,
            'label_id' => $label?->id,
            'print_job_id' => $printJob?->id,
            'wz_document_ids' => collect($wzDocuments)->pluck('id')->values()->all(),
            'invoice_id' => $invoice?->id,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ]);

        $this->communication->sendOrderStatus($order, 'order_packed', [
            'label_id' => $label?->id,
            'tracking_number' => $label?->tracking_number,
        ]);

        return [
            'packed' => $packed,
            'label' => $label,
            'print_job' => $printJob,
            'wz' => $wzDocuments,
            'invoice' => $invoice,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{orders:int,tasks:int,warnings:list<string>}
     */
    public function markCourierPickedUp(string $courier, array $orderIds = []): array
    {
        $courier = trim($courier);

        if ($courier === '') {
            throw new RuntimeException('Nie wskazano kuriera.');
        }

        $orderIds = collect($orderIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $ordersQuery = ExternalOrder::query()
            ->when(
                $orderIds->isNotEmpty(),
                fn ($query) => $query->whereIn('id', $orderIds),
                fn ($query) => $query->whereHas('packingTasks', fn ($query) => $query
                    ->where('status', 'packed')
                    ->where('courier', $courier)),
            )
            ->whereHas('packingTasks', fn ($query) => $query->where('status', 'packed'))
            ->whereDoesntHave('packingTasks', fn ($query) => $query->whereNotIn('status', ['packed', 'shipped', 'cancelled']));

        $orders = $ordersQuery->get();

        if ($orderIds->isNotEmpty() && $orders->pluck('id')->sort()->values()->all() !== $orderIds->sort()->values()->all()) {
            throw new RuntimeException('Nie wszystkie wskazane zamówienia są w całości spakowane i gotowe do odbioru przez kuriera. Odśwież listę.');
        }

        if ($orders->isEmpty()) {
            throw new RuntimeException('Brak paczek oczekujących na tego kuriera.');
        }

        $warnings = [];
        $taskCount = 0;
        $orderCount = 0;
        $pickedUpAt = now();

        foreach ($orders as $order) {
            $result = $this->markOrderPickedUpByCourier($order, [
                'courier' => $courier,
                'picked_up_at' => $pickedUpAt->toISOString(),
                'source' => 'manual_confirmation',
            ]);

            $taskCount += $result['tasks'];
            $warnings = array_merge($warnings, $result['warnings']);

            if ($result['tasks'] === 0) {
                continue;
            }

            $orderCount++;
            ShippingLabel::query()
                ->shipments()
                ->where('external_order_id', $order->id)
                ->where('status', 'generated')
                ->update([
                    'status' => 'picked_up',
                    'tracking_status' => 'manual_confirmation',
                    'tracking_checked_at' => $pickedUpAt,
                    'next_tracking_check_at' => null,
                    'picked_up_at' => $pickedUpAt,
                ]);
        }

        $this->audit->record('packing.courier_picked_up', null, null, [
            'courier' => $courier,
            'orders' => $orderCount,
            'tasks' => $taskCount,
            'warnings' => $warnings,
        ]);

        return [
            'orders' => $orderCount,
            'tasks' => $taskCount,
            'warnings' => $warnings,
        ];
    }

    /**
     * Oznacza pojedyncze zamówienie jako odebrane przez kuriera — używane przez
     * automatyczne śledzenie przesyłek. Zwraca liczbę zaktualizowanych pozycji.
     *
     * @param  array<string, mixed>  $context  np. tracking_number, tracking_status, source
     * @return array{tasks:int,warnings:list<string>}
     */
    public function markOrderPickedUpByCourier(ExternalOrder $order, array $context = []): array
    {
        try {
            return Cache::lock($this->orderLockKey($order), self::ORDER_LOCK_SECONDS)
                ->block(5, fn (): array => $this->markOrderPickedUpWhileLocked($order, $context));
        } catch (LockTimeoutException) {
            return [
                'tasks' => 0,
                'warnings' => ["Zamówienie {$order->external_number}: obsługa odbioru przez kuriera już trwa."],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{tasks:int,warnings:list<string>}
     */
    private function markOrderPickedUpWhileLocked(ExternalOrder $order, array $context): array
    {
        $order->refresh();

        $tasks = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->get();

        if ($tasks->isEmpty()) {
            return ['tasks' => 0, 'warnings' => []];
        }

        if ($tasks->contains(fn (PackingTask $task): bool => ! in_array($task->status, ['packed', 'shipped'], true))) {
            return [
                'tasks' => 0,
                'warnings' => ["Zamówienie {$order->external_number} nie jest jeszcze w całości spakowane."],
            ];
        }

        if ($tasks->every(fn (PackingTask $task): bool => $task->status === 'shipped')
            && $order->fulfillment_status === 'shipped') {
            return ['tasks' => 0, 'warnings' => []];
        }

        $warnings = [];
        $pickedUpAt = (string) ($context['picked_up_at'] ?? now()->toISOString());
        $wooSync = [
            'woo_shipped_sync_status' => 'pending',
            'woo_shipped_sync_attempts' => max(0, (int) $order->woo_shipped_sync_attempts),
            'woo_shipped_sync_next_at' => now(),
            'woo_shipped_sync_error' => null,
        ];

        try {
            $wooResult = $this->orderStatuses->markShipped($order);
            $wooSync = [
                'woo_shipped_sync_status' => ($wooResult['skipped'] ?? false) ? 'skipped' : 'success',
                'woo_shipped_sync_attempts' => 0,
                'woo_shipped_sync_next_at' => null,
                'woo_shipped_sync_error' => null,
            ];
        } catch (Throwable $exception) {
            $warnings[] = "Status WooCommerce {$order->external_number}: {$exception->getMessage()}";
            $attempts = max(0, (int) $order->woo_shipped_sync_attempts) + 1;
            $wooSync = [
                'woo_shipped_sync_status' => 'failed',
                'woo_shipped_sync_attempts' => $attempts,
                'woo_shipped_sync_next_at' => now()->addMinutes(min(360, 5 * (2 ** min(6, $attempts - 1)))),
                'woo_shipped_sync_error' => $exception->getMessage(),
            ];
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

        $transition = DB::transaction(function () use ($order, $context, $pickedUpAt, $wooSync): array {
            $lockedOrder = ExternalOrder::query()->lockForUpdate()->findOrFail($order->id);
            $lockedTasks = PackingTask::query()
                ->where('external_order_id', $lockedOrder->id)
                ->where('status', '!=', 'cancelled')
                ->lockForUpdate()
                ->get();

            if ($lockedTasks->isEmpty()
                || $lockedTasks->contains(fn (PackingTask $task): bool => ! in_array($task->status, ['packed', 'shipped'], true))) {
                throw new RuntimeException("Zamówienie {$lockedOrder->external_number} zmieniło się podczas potwierdzania odbioru. Operacja zostanie ponowiona.");
            }

            $updated = 0;

            foreach ($lockedTasks as $task) {
                if ($task->status === 'shipped') {
                    continue;
                }

                $metadata = (array) $task->metadata;
                $metadata['courier_pickup'] = array_merge([
                    'courier' => $task->courier,
                    'picked_up_at' => $pickedUpAt,
                    'source' => 'tracking',
                ], $context);

                $task->update([
                    'status' => 'shipped',
                    'metadata' => $metadata,
                ]);
                $updated++;
            }

            $lockedOrder->update(array_merge(['fulfillment_status' => 'shipped'], $wooSync));

            return [
                'tasks' => $updated,
                'courier' => $lockedTasks->first()?->courier,
                'order' => $lockedOrder,
            ];
        }, 3);

        $order = $transition['order'];

        try {
            $this->communication->sendOrderStatus($order, 'order_courier_picked_up', [
                'courier' => $transition['courier'],
                'tracking_number' => $context['tracking_number'] ?? $this->latestTrackingNumber($order),
                'source' => $context['source'] ?? 'tracking',
            ]);
        } catch (Throwable $exception) {
            $warnings[] = "Powiadomienie klienta {$order->external_number}: {$exception->getMessage()}";
        }

        $this->audit->record('packing.courier_picked_up_tracked', $order, null, [
            'tasks' => $transition['tasks'],
            'context' => $context,
            'warnings' => $warnings,
        ]);

        return ['tasks' => $transition['tasks'], 'warnings' => $warnings];
    }

    /**
     * @return array{tasks:int,woo_status:?string,warnings:list<string>}
     */
    public function undoPackedOrder(ExternalOrder $order, ?string $reason = null): array
    {
        try {
            return Cache::lock($this->orderLockKey($order), self::ORDER_LOCK_SECONDS)
                ->block(15, fn (): array => $this->undoPackedOrderWhileLocked($order, $reason));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'To zamówienie jest właśnie pakowane albo aktualizowane po odbiorze przez kuriera. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array{tasks:int,woo_status:?string,warnings:list<string>}
     */
    private function undoPackedOrderWhileLocked(ExternalOrder $order, ?string $reason = null): array
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

            $order->update(['fulfillment_status' => 'ready_to_pack']);

            return $tasks->count();
        });

        $warnings = [];
        $wooStatus = null;

        try {
            $result = $this->orderStatuses->markPackingRollback($order);
            $wooStatus = (string) ($result['status'] ?? '');
        } catch (Throwable $exception) {
            $wooStatus = 'processing';
            $warnings[] = 'Status WooCommerce: '.$exception->getMessage();

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

        $this->communication->sendOrderStatus($order->fresh() ?? $order, 'order_packing_rollback', [
            'rollback_reason' => $reason !== '' ? $reason : null,
        ]);

        return [
            'tasks' => $tasksCount,
            'woo_status' => $wooStatus,
            'warnings' => $warnings,
        ];
    }

    private function orderLockKey(ExternalOrder $order): string
    {
        return 'packing-fulfillment-order-'.$order->id;
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
            'Automatyczne WZ z pakowania zamówienia WooCommerce '.$order->external_number,
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
     * @param  array<string, mixed>  $result
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

    private function latestTrackingNumber(ExternalOrder $order): ?string
    {
        $label = ShippingLabel::query()
            ->shipments()
            ->where('external_order_id', $order->id)
            ->whereNotNull('tracking_number')
            ->latest('generated_at')
            ->first();

        return $label instanceof ShippingLabel ? $label->tracking_number : null;
    }
}
