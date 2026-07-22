<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\AuditLog;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\Invoice;
use App\Models\PackingTask;
use App\Models\PrintJob;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\WarehouseDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\StockReservationService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Orders\HistoricalSplitSnapshot;
use App\Services\Orders\OrderFulfillmentStatusService;
use App\Services\Orders\OrderMutationLock;
use App\Services\Orders\OrderWzDocumentService;
use App\Services\Payments\PaymentMethodClassifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PackedOrderPickingResetService
{
    private const LABEL_LOCK_SECONDS = 900;

    private const LABEL_LOCK_WAIT_SECONDS = 15;

    public function __construct(
        private readonly OrderMutationLock $orderLock,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly WarehouseDocumentPostingService $documentPosting,
        private readonly StockReservationService $reservations,
        private readonly OrderWzDocumentService $wzDocuments,
        private readonly AuditLogService $audit,
        private readonly PaymentMethodClassifier $paymentMethods,
    ) {}

    /**
     * @return array{
     *     available:bool,
     *     completed:bool,
     *     reasons:list<string>,
     *     version:string,
     *     plan:array<string,mixed>
     * }
     */
    public function preview(ExternalOrder $order): array
    {
        $order = ExternalOrder::query()->findOrFail($order->id);
        $state = $this->state($order);
        $completed = (string) data_get($order->raw_payload, 'sempre_erp_picking_reset.status') === 'completed';

        return [
            'available' => ! $completed && $this->blockers($state) === [],
            'completed' => $completed,
            'reasons' => $completed ? [] : $this->blockers($state),
            'version' => $this->version($state),
            'plan' => $this->plan($state),
        ];
    }

    /**
     * @return array{
     *     order:ExternalOrder,
     *     tasks:int,
     *     archived_wz_ids:list<int>,
     *     draft_wz_ids:list<int>,
     *     preserved_label_ids:list<int>,
     *     suspended_print_job_ids:list<int>,
     *     reservations:int,
     *     operation_uuid:string
     * }
     */
    public function reset(
        ExternalOrder $order,
        string $expectedVersion,
        string $requestUuid,
        string $typedOrderNumber,
        string $reason,
        bool $goodsReturnedToShelf,
        bool $preserveExistingLabel,
        bool $codAmountVerified,
        User $administrator,
    ): array {
        if (! $administrator->isAdministrator()) {
            throw new RuntimeException('Tylko administrator może cofnąć spakowane zamówienie do kompletacji.');
        }

        $reason = trim($reason);
        $requestUuid = Str::lower(trim($requestUuid));
        $requestFingerprint = HistoricalSplitSnapshot::fingerprint([
            'order_id' => (int) $order->id,
            'expected_version' => $expectedVersion,
            'request_uuid' => $requestUuid,
            'typed_order_number' => trim($typedOrderNumber),
            'reason' => $reason,
            'goods_returned_to_shelf' => $goodsReturnedToShelf,
            'preserve_existing_label' => $preserveExistingLabel,
            'cod_amount_verified' => $codAmountVerified,
            'administrator_id' => (int) $administrator->id,
        ]);

        if ($reason === '') {
            throw new RuntimeException('Podaj powód cofnięcia zamówienia do kompletacji.');
        }

        if (! $goodsReturnedToShelf || ! $preserveExistingLabel) {
            throw new RuntimeException('Nie zaznaczono wszystkich wymaganych potwierdzeń magazynowych i kurierskich.');
        }

        try {
            return $this->orderLock->forOrderFamily($order, function () use (
                $order,
                $expectedVersion,
                $requestUuid,
                $requestFingerprint,
                $typedOrderNumber,
                $reason,
                $goodsReturnedToShelf,
                $preserveExistingLabel,
                $codAmountVerified,
                $administrator,
            ): array {
                return Cache::lock('shipping-label-order-'.$order->id, self::LABEL_LOCK_SECONDS)
                    ->block(self::LABEL_LOCK_WAIT_SECONDS, function () use (
                        $order,
                        $expectedVersion,
                        $requestUuid,
                        $requestFingerprint,
                        $typedOrderNumber,
                        $reason,
                        $goodsReturnedToShelf,
                        $preserveExistingLabel,
                        $codAmountVerified,
                        $administrator,
                    ): array {
                        return DB::transaction(function () use (
                            $order,
                            $expectedVersion,
                            $requestUuid,
                            $requestFingerprint,
                            $typedOrderNumber,
                            $reason,
                            $goodsReturnedToShelf,
                            $preserveExistingLabel,
                            $codAmountVerified,
                            $administrator,
                        ): array {
                            $lockedOrder = ExternalOrder::query()->lockForUpdate()->findOrFail($order->id);
                            $existing = data_get($lockedOrder->raw_payload, 'sempre_erp_picking_reset');

                            if (is_array($existing)
                                && (string) ($existing['request_uuid'] ?? '') === $requestUuid
                                && (string) ($existing['status'] ?? '') === 'completed') {
                                if (! is_string($existing['request_fingerprint'] ?? null)
                                    || ! hash_equals((string) $existing['request_fingerprint'], $requestFingerprint)) {
                                    throw new RuntimeException('Ten identyfikator korekty został już użyty z innymi danymi żądania.');
                                }

                                return $this->completedResult($lockedOrder, $existing);
                            }

                            if (is_array($existing) && (string) ($existing['status'] ?? '') === 'completed') {
                                throw new RuntimeException('To zamówienie zostało już cofnięte do kompletacji.');
                            }

                            $preview = $this->preview($lockedOrder);

                            if ($expectedVersion === '' || ! hash_equals($preview['version'], $expectedVersion)) {
                                throw new RuntimeException('Stan zamówienia zmienił się od wyświetlenia planu. Odśwież stronę i sprawdź korektę ponownie.');
                            }

                            if (! $preview['available']) {
                                throw new RuntimeException(implode(' ', $preview['reasons']));
                            }

                            if (! hash_equals((string) $lockedOrder->external_number, trim($typedOrderNumber))) {
                                throw new RuntimeException('Wpisany numer nie zgadza się z numerem korygowanego zamówienia.');
                            }

                            if (($preview['plan']['cash_on_delivery'] ?? false) && ! $codAmountVerified) {
                                throw new RuntimeException('Potwierdź kwotę pobrania na zachowywanej etykiecie.');
                            }

                            $state = $this->state($lockedOrder);
                            $operationUuid = $requestUuid;
                            $startedAt = now();
                            $before = $this->auditState($state);
                            $preservedLabelIds = $this->preservedLabels($state)
                                ->pluck('id')
                                ->map(fn (mixed $id): int => (int) $id)
                                ->all();
                            $labels = ShippingLabel::query()
                                ->whereIn('id', $preservedLabelIds)
                                ->orderBy('id')
                                ->lockForUpdate()
                                ->get();

                            if ($labels->count() !== count($preservedLabelIds)
                                || $labels->contains(fn (ShippingLabel $label): bool => (string) $label->status !== 'generated'
                                    || $label->hasCourierPickupEvidence())) {
                                throw new RuntimeException('Zachowywana etykieta zmieniła się podczas korekty. Odśwież zamówienie i sprawdź status przewoźnika.');
                            }
                            $labelFingerprints = $labels->mapWithKeys(fn (ShippingLabel $label): array => [
                                (int) $label->id => $this->preservedLabelFingerprint($label),
                            ])->all();
                            $stockBefore = $this->stockBefore($state['documents']);
                            $expectedAllocations = $this->expectedAllocations($state['documents']);
                            $suspendedPrintJobIds = $this->suspendPrintJobs($labels, $operationUuid, $reason);
                            $archivedWzIds = $this->reverseAndArchiveWz(
                                $state['documents'],
                                $operationUuid,
                                $reason,
                                $administrator,
                            );
                            $tasksCount = $this->resetTasks(
                                $state['tasks'],
                                $operationUuid,
                                $reason,
                                $labels->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                                $archivedWzIds,
                                $administrator,
                            );

                            $lockedOrder->refresh();
                            $raw = (array) $lockedOrder->raw_payload;
                            $raw['sempre_erp_picking_reset'] = [
                                'version' => 1,
                                'status' => 'processing',
                                'request_uuid' => $operationUuid,
                                'request_fingerprint' => $requestFingerprint,
                                'reason' => $reason,
                                'started_at' => $startedAt->toISOString(),
                                'administrator_id' => (int) $administrator->id,
                                'administrator_name' => (string) $administrator->name,
                                'goods_returned_to_shelf' => $goodsReturnedToShelf,
                                'preserve_existing_label' => $preserveExistingLabel,
                                'cod_amount_verified' => $codAmountVerified,
                                'financial_snapshot' => [
                                    'cash_on_delivery' => (bool) ($preview['plan']['cash_on_delivery'] ?? false),
                                    'order_total' => round((float) $lockedOrder->total_gross, 2),
                                    'currency' => strtoupper(trim((string) $lockedOrder->currency)),
                                    'cod_amount' => $preview['plan']['cod_amount'] ?? null,
                                    'cod_currency' => $preview['plan']['cod_currency'] ?? null,
                                    'cod_verification_source' => $preview['plan']['cod_verification_source'] ?? null,
                                ],
                                'preserved_label_ids' => $labels->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                                'preserved_tracking_numbers' => $labels->map(fn (ShippingLabel $label): string => (string) $label->trackingIdentifier())->values()->all(),
                                'archived_wz_ids' => $archivedWzIds,
                                'suspended_print_job_ids' => $suspendedPrintJobIds,
                                'previous_fulfillment_status' => (string) $state['order']->fulfillment_status,
                            ];
                            $lockedOrder->update([
                                'fulfillment_status' => 'picking',
                                'raw_payload' => $raw,
                            ]);

                            $reservationResult = $this->reservations->syncForOrder($lockedOrder->fresh());
                            $this->assertReservations($lockedOrder, $expectedAllocations);
                            $this->assertStockBalances($stockBefore, $expectedAllocations);

                            $drafts = $this->wzDocuments->ensureDrafts(
                                $lockedOrder->fresh(),
                                'packed_order_picking_reset',
                                'WZ po cofnięciu zamówienia '.$lockedOrder->external_number.' do kompletacji',
                            );
                            $this->assertDrafts($drafts, $expectedAllocations);
                            $this->assertPreservedLabels($labels, $labelFingerprints);

                            $lockedOrder->refresh();
                            $raw = (array) $lockedOrder->raw_payload;
                            $marker = (array) data_get($raw, 'sempre_erp_picking_reset', []);
                            $marker['status'] = 'completed';
                            $marker['completed_at'] = now()->toISOString();
                            $marker['tasks_reset'] = $tasksCount;
                            $marker['draft_wz_ids'] = collect($drafts)->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
                            $marker['reservation_result'] = $reservationResult;
                            $raw['sempre_erp_picking_reset'] = $marker;
                            $lockedOrder->update(['raw_payload' => $raw]);
                            $lockedOrder->refresh();

                            $after = [
                                'fulfillment_status' => (string) $lockedOrder->fulfillment_status,
                                'tasks' => PackingTask::query()
                                    ->where('external_order_id', $lockedOrder->id)
                                    ->where('status', '!=', 'cancelled')
                                    ->orderBy('id')
                                    ->get()
                                    ->map(fn (PackingTask $task): array => [
                                        'id' => (int) $task->id,
                                        'status' => (string) $task->status,
                                        'quantity_picked' => (string) $task->quantity_picked,
                                    ])->values()->all(),
                                'active_reservations' => StockReservation::query()
                                    ->where('sales_channel_id', $lockedOrder->sales_channel_id)
                                    ->where('external_order_id', $lockedOrder->external_id)
                                    ->where('status', 'active')
                                    ->orderBy('id')
                                    ->get()
                                    ->map(fn (StockReservation $reservation): array => [
                                        'id' => (int) $reservation->id,
                                        'warehouse_id' => (int) $reservation->warehouse_id,
                                        'product_id' => (int) $reservation->product_id,
                                        'quantity' => (string) $reservation->quantity,
                                    ])->values()->all(),
                                'archived_wz_ids' => $archivedWzIds,
                                'draft_wz_ids' => $marker['draft_wz_ids'],
                                'preserved_label_ids' => $marker['preserved_label_ids'],
                                'suspended_print_job_ids' => $suspendedPrintJobIds,
                            ];

                            $this->audit->record(
                                'packing.order_reset_to_picking',
                                $lockedOrder,
                                $before,
                                $after,
                                [
                                    'operation_uuid' => $operationUuid,
                                    'reason' => $reason,
                                    'administrator_id' => (int) $administrator->id,
                                    'customer_notification_sent' => false,
                                    'woocommerce_status_changed' => false,
                                ],
                            );

                            return [
                                'order' => $lockedOrder,
                                'tasks' => $tasksCount,
                                'archived_wz_ids' => $archivedWzIds,
                                'draft_wz_ids' => $marker['draft_wz_ids'],
                                'preserved_label_ids' => $marker['preserved_label_ids'],
                                'suspended_print_job_ids' => $suspendedPrintJobIds,
                                'reservations' => StockReservation::query()
                                    ->where('sales_channel_id', $lockedOrder->sales_channel_id)
                                    ->where('external_order_id', $lockedOrder->external_id)
                                    ->where('status', 'active')
                                    ->count(),
                                'operation_uuid' => $operationUuid,
                            ];
                        }, 3);
                    });
            });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Zamówienie albo etykieta są właśnie aktualizowane. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /** @return array<string,mixed> */
    private function state(ExternalOrder $order): array
    {
        $lines = ExternalOrderLine::query()
            ->with('product')
            ->where('external_order_id', $order->id)
            ->orderBy('id')
            ->get();
        $tasks = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get();
        $labels = ShippingLabel::query()
            ->with(['printJobs'])
            ->shipments()
            ->where('external_order_id', $order->id)
            ->orderBy('id')
            ->get();
        $documents = $this->fulfillmentStatus
            ->wzDocumentsForOrder($order)
            ->with(['sourceWarehouse', 'lines.product', 'ledgerEntries'])
            ->orderBy('id')
            ->get();
        $reservations = StockReservation::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->where('external_order_id', $order->external_id)
            ->orderBy('id')
            ->get();
        $rootId = (int) ($order->split_root_order_id ?: $order->id);
        $historicalFamily = ExternalOrder::withTrashed()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->where(function ($query) use ($rootId): void {
                $query->whereKey($rootId)
                    ->orWhere('split_root_order_id', $rootId)
                    ->orWhere('split_parent_order_id', $rootId)
                    ->orWhere('raw_payload->sempre_erp_split_reversal->root_order_id', $rootId);
            })
            ->orderBy('id')
            ->get();
        $familyIds = $historicalFamily
            ->filter(fn (ExternalOrder $member): bool => $member->deleted_at === null)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $archivedFamily = $historicalFamily
            ->filter(fn (ExternalOrder $member): bool => $member->deleted_at !== null)
            ->values();
        $invoices = Invoice::withTrashed()
            ->whereIn('external_order_id', $historicalFamily->pluck('id'))
            ->orderBy('id')
            ->get();

        return [
            'order' => $order,
            'lines' => $lines,
            'tasks' => $tasks,
            'labels' => $labels,
            'documents' => $documents,
            'reservations' => $reservations,
            'family_ids' => $familyIds,
            'historical_family_ids' => $historicalFamily->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'archived_family_safe' => $this->archivedFamilyIsClean($order, $archivedFamily),
            'invoices' => $invoices,
            'active_invoice_count' => $invoices->count(),
        ];
    }

    /** @param EloquentCollection<int,ExternalOrder> $archivedFamily */
    private function archivedFamilyIsClean(ExternalOrder $root, EloquentCollection $archivedFamily): bool
    {
        if ($root->split_parent_order_id !== null || $root->split_root_order_id !== null) {
            return false;
        }

        if ($archivedFamily->isEmpty()) {
            return true;
        }

        if ($archivedFamily->contains(fn (ExternalOrder $child): bool => (string) $child->status !== 'split-reverted'
            || (int) data_get($child->raw_payload, 'sempre_erp_split_reversal.root_order_id', 0) !== (int) $root->id)) {
            return false;
        }

        $childIds = $archivedFamily->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $childExternalIds = $archivedFamily->pluck('external_id')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values()
            ->all();
        $tasks = PackingTask::query()->whereIn('external_order_id', $childIds)->get();

        if ($tasks->contains(fn (PackingTask $task): bool => (string) $task->status !== 'cancelled'
            || abs((float) $task->quantity_picked) > 0.00001
            || $task->picked_at !== null
            || $task->packed_at !== null)) {
            return false;
        }

        if (StockReservation::query()
            ->where('sales_channel_id', $root->sales_channel_id)
            ->whereIn('external_order_id', $childExternalIds)
            ->whereIn('status', ['active', 'waiting'])
            ->exists()) {
            return false;
        }

        $labels = ShippingLabel::query()
            ->shipments()
            ->whereIn('external_order_id', $childIds)
            ->get();

        if ($labels->contains(fn (ShippingLabel $label): bool => ! $this->isVerifiedCancelledShipment($label, $root))
            || PrintJob::query()
                ->whereIn('shipping_label_id', $labels->pluck('id'))
                ->whereIn('status', ['pending', 'reserved', 'printing', 'failed'])
                ->exists()) {
            return false;
        }

        foreach ($archivedFamily as $child) {
            $documents = $this->fulfillmentStatus
                ->wzDocumentsForOrder($child)
                ->withTrashed()
                ->with('ledgerEntries')
                ->get();

            if ($documents->contains(fn (WarehouseDocument $document): bool => $document->deleted_at === null
                || ! $this->cancelledWzHasCompleteLedgerPair($document))) {
                return false;
            }
        }

        return true;
    }

    private function isVerifiedCancelledShipment(ShippingLabel $label, ?ExternalOrder $root = null): bool
    {
        if ((string) $label->status !== 'cancelled' || $label->hasCourierPickupEvidence()) {
            return false;
        }

        $remoteStatus = (string) data_get($label->response_payload, 'cancellation.remote.status');

        if (in_array($remoteStatus, ['cancelled', 'already_cancelled'], true)) {
            return true;
        }

        if ($remoteStatus !== 'manual_required' || ! $root instanceof ExternalOrder) {
            return false;
        }

        $operationUuid = trim((string) data_get($label->response_payload, 'cancellation.operation_uuid', ''));

        if ($operationUuid === '') {
            return false;
        }

        return AuditLog::query()
            ->where('action', 'order.split_reverted')
            ->where('auditable_type', ExternalOrder::class)
            ->where('auditable_id', $root->id)
            ->latest('id')
            ->get()
            ->contains(function (AuditLog $audit) use ($label, $operationUuid): bool {
                $manualLabelIds = collect((array) data_get(
                    $audit->after,
                    'reversed_effects.shipping.manual_required',
                    [],
                ))
                    ->filter(fn (mixed $warning): bool => is_array($warning))
                    ->pluck('label_id')
                    ->map(fn (mixed $id): int => (int) $id);

                return (string) data_get($audit->metadata, 'split_reversal_uuid') === $operationUuid
                    && data_get($audit->after, 'reversed_effects.manual_shipping_confirmation') === true
                    && $manualLabelIds->contains((int) $label->id);
            });
    }

    private function cancelledWzHasCompleteLedgerPair(WarehouseDocument $document): bool
    {
        if ((string) $document->status !== 'cancelled'
            || $document->type !== 'WZ'
            || $document->source_warehouse_id === null
            || $document->lines->isEmpty()
            || $document->ledgerEntries->count() !== $document->lines->count() * 2) {
            return false;
        }

        $lineIds = $document->lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values();
        $ledgerLineIds = $document->ledgerEntries->pluck('warehouse_document_line_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values();

        if ($ledgerLineIds->all() !== $lineIds->flatMap(fn (int $id): array => [$id, $id])->sort()->values()->all()) {
            return false;
        }

        return $document->lines->every(function ($line) use ($document): bool {
            $entries = $document->ledgerEntries->where('warehouse_document_line_id', $line->id);
            $changes = $entries->pluck('quantity_change')->map(fn (mixed $quantity): float => (float) $quantity)->sort()->values();

            return $entries->count() === 2
                && $entries->every(fn ($entry): bool => (int) $entry->warehouse_id === (int) $document->source_warehouse_id
                    && (int) $entry->product_id === (int) $line->product_id)
                && abs((float) $changes->get(0) + (float) $line->quantity) < 0.00001
                && abs((float) $changes->get(1) - (float) $line->quantity) < 0.00001;
        });
    }

    /** @param array<string,mixed> $state @return list<string> */
    private function blockers(array $state): array
    {
        /** @var ExternalOrder $order */
        $order = $state['order'];
        /** @var EloquentCollection<int,PackingTask> $tasks */
        $tasks = $state['tasks'];
        /** @var EloquentCollection<int,ShippingLabel> $labels */
        $labels = $state['labels'];
        /** @var EloquentCollection<int,WarehouseDocument> $documents */
        $documents = $state['documents'];
        /** @var EloquentCollection<int,StockReservation> $reservations */
        $reservations = $state['reservations'];
        $reasons = [];

        if ($order->hasCancellationOperation()
            || $order->familyHasSplitReversalOperation()
            || in_array(mb_strtolower((string) $order->status), ['cancellation-pending', 'cancelled', 'canceled', 'refunded'], true)) {
            $reasons[] = 'Nie można cofnąć anulowanego zamówienia ani zamówienia w trakcie innej korekty.';
        }

        if ((string) $order->status !== 'processing') {
            $reasons[] = 'Bezpieczna korekta bez dodatkowej synchronizacji WooCommerce wymaga statusu processing.';
        }

        if ((string) $order->fulfillment_status !== 'awaiting_courier') {
            $reasons[] = 'Zamówienie nie znajduje się w etapie oczekiwania na kuriera.';
        }

        if (count($state['family_ids']) !== 1 || (int) ($state['family_ids'][0] ?? 0) !== (int) $order->id) {
            $reasons[] = 'Najpierw zakończ rozdzielenie albo scal aktywne części zamówienia.';
        }

        if (! (bool) $state['archived_family_safe']) {
            $reasons[] = 'Historyczna część podziału ma niezamknięte zadanie, rezerwację, etykietę albo dokument WZ. Wymagana jest ręczna weryfikacja.';
        }

        if ($tasks->isEmpty() || $tasks->contains(fn (PackingTask $task): bool => (string) $task->status !== 'packed')) {
            $reasons[] = 'Wszystkie aktywne pozycje zamówienia muszą być spakowane i niewysłane.';
        }

        if (! $this->tasksMatchLines($state['lines'], $tasks)) {
            $reasons[] = 'Pozycje pakowania nie odpowiadają dokładnie pozycjom zamówienia.';
        }

        $activeLabels = $labels->filter(fn (ShippingLabel $label): bool => (string) $label->status !== 'cancelled')->values();
        $unverifiedCancelledLabels = $labels
            ->filter(fn (ShippingLabel $label): bool => (string) $label->status === 'cancelled')
            ->reject(fn (ShippingLabel $label): bool => $this->isVerifiedCancelledShipment($label, $order));

        if ($unverifiedCancelledLabels->isNotEmpty()) {
            $reasons[] = 'Jedna z wcześniejszych etykiet nie ma potwierdzonego anulowania u przewoźnika.';
        }

        if ($activeLabels->count() !== 1 || (string) $activeLabels->first()?->status !== 'generated') {
            $reasons[] = 'Do zachowania wymagana jest dokładnie jedna aktywna, wygenerowana etykieta wysyłkowa.';
        } elseif ($activeLabels->first()->hasCourierPickupEvidence()) {
            $reasons[] = 'Przewoźnik odebrał przesyłkę albo jej status nie pozwala bezpiecznie wrócić do kompletacji.';
        } elseif ($activeLabels->first()->tracking_checked_at === null
            || $activeLabels->first()->tracking_checked_at->lt(now()->subMinutes(15))
            || filled($activeLabels->first()->tracking_last_error)) {
            $reasons[] = 'Status przesyłki nie został pomyślnie sprawdzony w ostatnich 15 minutach. Odśwież odbiory kuriera i ponownie otwórz zamówienie.';
        }

        if ($activeLabels->flatMap(fn (ShippingLabel $label) => $label->printJobs)
            ->contains(fn (PrintJob $job): bool => (string) $job->status === 'printing')) {
            $reasons[] = 'Etykieta jest właśnie drukowana. Poczekaj na potwierdzenie wydruku albo błąd i ponownie odśwież zamówienie.';
        }

        if ($documents->count() !== 1 || (string) $documents->first()?->status !== 'posted') {
            $reasons[] = 'Korekta wymaga dokładnie jednego aktywnego, zaksięgowanego dokumentu WZ.';
        } elseif (! $this->postedWzIsComplete($documents->first(), $state['lines'])) {
            $reasons[] = 'Dokument WZ lub jego zapisy magazynowe nie odpowiadają dokładnie pozycjom zamówienia.';
        }

        if ($reservations->contains(fn (StockReservation $reservation): bool => in_array((string) $reservation->status, ['active', 'waiting'], true))) {
            $reasons[] = 'Spakowane zamówienie ma nieoczekiwaną aktywną albo oczekującą rezerwację.';
        }

        if ((int) $state['active_invoice_count'] > 0) {
            $reasons[] = 'Dla historycznej rodziny zamówienia istnieje faktura, proforma albo korekta. Najpierw wymagana jest decyzja księgowa.';
        }

        if ($activeLabels->count() === 1) {
            $label = $activeLabels->first();
            $isCashOnDelivery = $this->paymentMethods->isCashOnDelivery($order);
            $codClaims = $this->recordedCodClaims($label, $order);
            $codEvidence = $codClaims[0] ?? null;
            $recordedCashOnDelivery = data_get($label->response_payload, 'financial.cash_on_delivery');

            if (collect($codClaims)->unique(fn (array $claim): string => $claim['currency'].':'.number_format($claim['amount'], 2, '.', ''))->count() > 1) {
                $reasons[] = 'Zapisy kwoty COD w danych etykiety są ze sobą sprzeczne.';
            }

            if ($isCashOnDelivery) {
                if ($recordedCashOnDelivery === false || $recordedCashOnDelivery === 0) {
                    $reasons[] = 'Zapis finansowy etykiety wskazuje przesyłkę bez pobrania, choć zamówienie jest COD.';
                } elseif ($codEvidence === null) {
                    $reasons[] = 'Brak wiarygodnego zapisu kwoty i waluty COD na zachowywanej etykiecie.';
                } elseif (abs($codEvidence['amount'] - (float) $order->total_gross) > 0.009
                    || $codEvidence['currency'] !== strtoupper(trim((string) $order->currency))) {
                    $reasons[] = 'Kwota lub waluta COD zachowywanej etykiety nie zgadza się z zamówieniem.';
                }
            } elseif ($recordedCashOnDelivery === true || ($codEvidence['amount'] ?? 0) > 0) {
                $reasons[] = 'Etykieta zawiera pobranie COD, ale zamówienie nie jest oznaczone jako płatne przy odbiorze.';
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @param array<string,mixed> $state */
    private function version(array $state): string
    {
        /** @var ExternalOrder $order */
        $order = $state['order'];

        return HistoricalSplitSnapshot::fingerprint([
            'order' => [
                'id' => (int) $order->id,
                'external_id' => (string) $order->external_id,
                'external_number' => (string) $order->external_number,
                'status' => (string) $order->status,
                'fulfillment_status' => (string) $order->fulfillment_status,
                'total_gross' => (string) $order->total_gross,
                'updated_at' => $order->updated_at?->toISOString(),
            ],
            'lines' => $state['lines']->map(fn (ExternalOrderLine $line): array => [
                'id' => (int) $line->id,
                'product_id' => $line->product_id !== null ? (int) $line->product_id : null,
                'external_line_id' => (string) $line->external_line_id,
                'quantity' => (string) $line->quantity,
                'updated_at' => $line->updated_at?->toISOString(),
            ])->values()->all(),
            'tasks' => $state['tasks']->map(
                fn (PackingTask $task): string => HistoricalSplitSnapshot::packingTaskFingerprint($task),
            )->values()->all(),
            'labels' => $state['labels']->map(fn (ShippingLabel $label): array => [
                'fingerprint' => HistoricalSplitSnapshot::shippingLabelFingerprint($label),
                'print_jobs' => $label->printJobs->sortBy('id')->map(fn (PrintJob $job): array => [
                    'id' => (int) $job->id,
                    'status' => (string) $job->status,
                    'deduplication_key' => (string) $job->deduplication_key,
                    'lease_token' => (string) $job->lease_token,
                    'updated_at' => $job->updated_at?->toISOString(),
                ])->values()->all(),
            ])->values()->all(),
            'documents' => $state['documents']->map(
                fn (WarehouseDocument $document): string => HistoricalSplitSnapshot::warehouseDocumentFingerprint($document),
            )->values()->all(),
            'reservations' => $state['reservations']->map(fn (StockReservation $reservation): array => [
                'id' => (int) $reservation->id,
                'warehouse_id' => (int) $reservation->warehouse_id,
                'product_id' => (int) $reservation->product_id,
                'quantity' => (string) $reservation->quantity,
                'status' => (string) $reservation->status,
                'updated_at' => $reservation->updated_at?->toISOString(),
            ])->values()->all(),
            'family_ids' => $state['family_ids'],
            'historical_family_ids' => $state['historical_family_ids'],
            'archived_family_safe' => (bool) $state['archived_family_safe'],
            'stock_balances' => $this->balancePlan($state['documents']),
            'active_invoice_count' => (int) $state['active_invoice_count'],
            'invoices' => $state['invoices']->map(fn (Invoice $invoice): array => [
                'id' => (int) $invoice->id,
                'type' => (string) $invoice->type,
                'status' => (string) $invoice->status,
                'updated_at' => $invoice->updated_at?->toISOString(),
                'deleted_at' => $invoice->deleted_at?->toISOString(),
            ])->values()->all(),
        ]);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function plan(array $state): array
    {
        /** @var ExternalOrder $order */
        $order = $state['order'];
        $labels = $this->preservedLabels($state);
        $codEvidence = $labels->count() === 1 ? $this->recordedCodEvidence($labels->first(), $order) : null;

        return [
            'order_id' => (int) $order->id,
            'order_number' => (string) $order->external_number,
            'total_gross' => (string) $order->total_gross,
            'currency' => (string) $order->currency,
            'cash_on_delivery' => $this->paymentMethods->isCashOnDelivery($order),
            'cod_amount' => $codEvidence['amount'] ?? null,
            'cod_currency' => $codEvidence['currency'] ?? null,
            'cod_verification_source' => $codEvidence['source'] ?? null,
            'cod_confirmation_required' => $this->paymentMethods->isCashOnDelivery($order),
            'task_ids' => $state['tasks']->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'wz_ids' => $state['documents']->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'wz_numbers' => $state['documents']->pluck('number')->map(fn (mixed $number): string => (string) $number)->values()->all(),
            'preserved_label_ids' => $labels->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'preserved_tracking_numbers' => $labels->map(fn (ShippingLabel $label): string => (string) $label->trackingIdentifier())->values()->all(),
            'suspendable_print_job_ids' => $labels->flatMap(fn (ShippingLabel $label) => $label->printJobs
                ->whereIn('status', ['pending', 'reserved', 'failed'])
                ->pluck('id'))
                ->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'printed_print_job_ids' => $labels->flatMap(fn (ShippingLabel $label) => $label->printJobs
                ->where('status', 'printed')
                ->pluck('id'))
                ->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'balance_changes' => $this->balancePlan($state['documents']),
        ];
    }

    /** @param array<string,mixed> $state */
    private function preservedLabels(array $state): EloquentCollection
    {
        return $state['labels']
            ->filter(fn (ShippingLabel $label): bool => (string) $label->status === 'generated')
            ->values();
    }

    private function tasksMatchLines(EloquentCollection $lines, EloquentCollection $tasks): bool
    {
        $lineQuantities = $lines->groupBy(fn (ExternalOrderLine $line): string => (string) $line->product_id)
            ->map(fn ($group): float => (float) $group->sum(fn (ExternalOrderLine $line): float => (float) $line->quantity));
        $taskQuantities = $tasks->groupBy(fn (PackingTask $task): string => (string) $task->product_id)
            ->map(fn ($group): float => (float) $group->sum(fn (PackingTask $task): float => (float) $task->quantity_required));

        if ($lines->contains(fn (ExternalOrderLine $line): bool => $line->product_id === null)
            || $tasks->contains(fn (PackingTask $task): bool => $task->product_id === null)
            || $lineQuantities->keys()->sort()->values()->all() !== $taskQuantities->keys()->sort()->values()->all()) {
            return false;
        }

        return $lineQuantities->every(
            fn (float $quantity, string $productId): bool => abs($quantity - (float) $taskQuantities->get($productId, -1)) < 0.00001,
        );
    }

    private function postedWzIsComplete(WarehouseDocument $document, EloquentCollection $lines): bool
    {
        if ($document->type !== 'WZ'
            || $document->source_warehouse_id === null
            || $document->lines->isEmpty()) {
            return false;
        }

        $documentLineIds = $document->lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values();
        $ledgerLineIds = $document->ledgerEntries
            ->pluck('warehouse_document_line_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values();

        if ($document->ledgerEntries->count() !== $document->lines->count()
            || $ledgerLineIds->all() !== $documentLineIds->all()) {
            return false;
        }

        $lineQuantities = $lines->groupBy(fn (ExternalOrderLine $line): string => (string) $line->product_id)
            ->map(fn ($group): float => (float) $group->sum(fn (ExternalOrderLine $line): float => (float) $line->quantity));
        $documentQuantities = $document->lines->groupBy(fn ($line): string => (string) $line->product_id)
            ->map(fn ($group): float => (float) $group->sum(fn ($line): float => (float) $line->quantity));

        if ($lineQuantities->keys()->sort()->values()->all() !== $documentQuantities->keys()->sort()->values()->all()
            || ! $lineQuantities->every(fn (float $quantity, string $productId): bool => abs($quantity - (float) $documentQuantities->get($productId, -1)) < 0.00001)) {
            return false;
        }

        foreach ($document->lines as $line) {
            $entries = $document->ledgerEntries->where('warehouse_document_line_id', $line->id);

            if ($entries->count() !== 1
                || $entries->contains(fn ($entry): bool => (int) $entry->warehouse_id !== (int) $document->source_warehouse_id
                    || (int) $entry->product_id !== (int) $line->product_id)
                || abs((float) $entries->sum('quantity_change') + (float) $line->quantity) > 0.00001) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,array{warehouse_id:int,product_id:int,quantity:float}> */
    private function expectedAllocations(EloquentCollection $documents): array
    {
        $allocations = [];

        foreach ($documents as $document) {
            foreach ($document->lines as $line) {
                $key = ((int) $document->source_warehouse_id).':'.((int) $line->product_id);
                $allocations[$key] ??= [
                    'warehouse_id' => (int) $document->source_warehouse_id,
                    'product_id' => (int) $line->product_id,
                    'quantity' => 0.0,
                ];
                $allocations[$key]['quantity'] += (float) $line->quantity;
            }
        }

        ksort($allocations);

        return $allocations;
    }

    /** @return array<string,array{warehouse_id:int,product_id:int,on_hand:float,reserved:float,available:float}> */
    private function stockBefore(EloquentCollection $documents): array
    {
        $stock = [];

        foreach ($this->expectedAllocations($documents) as $key => $allocation) {
            $balance = StockBalance::query()
                ->where('warehouse_id', $allocation['warehouse_id'])
                ->where('product_id', $allocation['product_id'])
                ->lockForUpdate()
                ->first();

            if (! $balance instanceof StockBalance) {
                throw new RuntimeException('Brak bilansu magazynowego dla jednej z pozycji zamówienia.');
            }

            $stock[$key] = [
                'warehouse_id' => (int) $balance->warehouse_id,
                'product_id' => (int) $balance->product_id,
                'on_hand' => (float) $balance->quantity_on_hand,
                'reserved' => (float) $balance->quantity_reserved,
                'available' => (float) $balance->quantity_available,
            ];
        }

        return $stock;
    }

    /** @return list<int> */
    private function suspendPrintJobs(EloquentCollection $labels, string $operationUuid, string $reason): array
    {
        $labelIds = $labels->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $allJobs = PrintJob::query()
            ->whereIn('shipping_label_id', $labelIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($allJobs->contains(fn (PrintJob $job): bool => (string) $job->status === 'printing')) {
            throw new RuntimeException('Etykieta zaczęła się drukować. Korekta została wycofana; poczekaj na zakończenie wydruku i spróbuj ponownie.');
        }

        $jobs = $allJobs
            ->filter(fn (PrintJob $job): bool => in_array((string) $job->status, ['pending', 'reserved', 'failed'], true))
            ->values();

        foreach ($jobs as $job) {
            $metadata = (array) $job->metadata;
            $metadata['packing_reset'] = [
                'operation_uuid' => $operationUuid,
                'reason' => $reason,
                'suspended_at' => now()->toISOString(),
                'previous_status' => (string) $job->status,
                'previous_deduplication_key' => $job->deduplication_key,
                'label_preserved' => true,
            ];
            $job->forceFill([
                'deduplication_key' => hash('sha256', implode("\0", ['packing-reset', $operationUuid, (int) $job->id])),
                'status' => 'cancelled',
                'next_attempt_at' => null,
                'reserved_by' => null,
                'reserved_station' => null,
                'reserved_at' => null,
                'lease_token' => null,
                'failed_at' => null,
                'last_error' => null,
                'metadata' => $metadata,
            ])->save();
        }

        return $jobs->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
    }

    /** @return list<int> */
    private function reverseAndArchiveWz(
        EloquentCollection $documents,
        string $operationUuid,
        string $reason,
        User $administrator,
    ): array {
        $archived = [];

        foreach ($documents->sortByDesc('id') as $document) {
            $originalKey = $document->order_fulfillment_key;
            $this->documentPosting->cancel($document);
            $document = WarehouseDocument::query()
                ->with(['lines', 'ledgerEntries'])
                ->findOrFail($document->id);

            $documentLineIds = $document->lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values();
            $ledgerLineIds = $document->ledgerEntries
                ->pluck('warehouse_document_line_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values();

            if ($document->ledgerEntries->count() !== $document->lines->count() * 2
                || $ledgerLineIds->all() !== $documentLineIds->flatMap(fn (int $id): array => [$id, $id])->sort()->values()->all()
                || abs((float) $document->ledgerEntries->sum('quantity_change')) > 0.00001) {
                throw new RuntimeException("Kontrola końcowa dokumentu {$document->number} wykryła dodatkowy albo niezbilansowany ruch magazynowy.");
            }

            foreach ($document->lines as $line) {
                $entries = $document->ledgerEntries->where('warehouse_document_line_id', $line->id);

                if ($entries->count() !== 2 || abs((float) $entries->sum('quantity_change')) > 0.00001) {
                    throw new RuntimeException("Kontrola końcowa dokumentu {$document->number} wykryła niepełne odwrócenie ruchu magazynowego.");
                }
            }

            $metadata = (array) $document->metadata;
            $metadata['packing_reset'] = [
                'operation_uuid' => $operationUuid,
                'reason' => $reason,
                'original_order_fulfillment_key' => $originalKey,
                'archived_at' => now()->toISOString(),
                'administrator_id' => (int) $administrator->id,
            ];
            $document->forceFill([
                'order_fulfillment_key' => mb_substr('packing-reset:'.$operationUuid.':'.$document->id, 0, 191),
                'metadata' => $metadata,
            ])->save();
            $document->delete();
            $archived[] = (int) $document->id;
        }

        return $archived;
    }

    private function resetTasks(
        EloquentCollection $tasks,
        string $operationUuid,
        string $reason,
        array $preservedLabelIds,
        array $archivedWzIds,
        User $administrator,
    ): int {
        $taskIds = $tasks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $tasks = PackingTask::query()->whereIn('id', $taskIds)->orderBy('id')->lockForUpdate()->get();

        foreach ($tasks as $task) {
            $metadata = (array) $task->metadata;
            $history = (array) ($metadata['picking_reset_history'] ?? []);
            $history[] = [
                'operation_uuid' => $operationUuid,
                'reason' => $reason,
                'reset_at' => now()->toISOString(),
                'administrator_id' => (int) $administrator->id,
                'previous' => [
                    'status' => (string) $task->status,
                    'quantity_picked' => (string) $task->quantity_picked,
                    'picked_at' => $task->picked_at?->toISOString(),
                    'packed_at' => $task->packed_at?->toISOString(),
                    'packing_completion' => $metadata['packing_completion'] ?? null,
                    'courier_pickup' => $metadata['courier_pickup'] ?? null,
                ],
                'preserved_label_ids' => $preservedLabelIds,
                'archived_wz_ids' => $archivedWzIds,
            ];
            $metadata['picking_reset_history'] = $history;
            unset($metadata['packing_completion'], $metadata['courier_pickup']);
            $task->update([
                'status' => 'open',
                'quantity_picked' => 0,
                'picked_at' => null,
                'packed_at' => null,
                'metadata' => $metadata,
            ]);
        }

        return $tasks->count();
    }

    /** @param array<string,array{warehouse_id:int,product_id:int,quantity:float}> $expected */
    private function assertReservations(ExternalOrder $order, array $expected): void
    {
        $actual = StockReservation::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->where('external_order_id', $order->external_id)
            ->whereIn('status', ['active', 'waiting'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (StockReservation $reservation): string => $reservation->warehouse_id.':'.$reservation->product_id)
            ->map(fn ($group): array => [
                'status' => $group->pluck('status')->unique()->values()->all(),
                'quantity' => (float) $group->sum(fn (StockReservation $reservation): float => (float) $reservation->quantity),
            ]);

        if ($actual->keys()->sort()->values()->all() !== collect(array_keys($expected))->sort()->values()->all()) {
            throw new RuntimeException('Rezerwacje po korekcie trafiły do innego magazynu lub nie obejmują wszystkich produktów.');
        }

        foreach ($expected as $key => $allocation) {
            $reservation = $actual->get($key);

            if (! is_array($reservation)
                || $reservation['status'] !== ['active']
                || abs((float) $reservation['quantity'] - $allocation['quantity']) > 0.00001) {
                throw new RuntimeException('Nie udało się utworzyć pełnej aktywnej rezerwacji dla jednej z pozycji zamówienia.');
            }
        }
    }

    /**
     * @param  array<string,array{warehouse_id:int,product_id:int,on_hand:float,reserved:float,available:float}>  $before
     * @param  array<string,array{warehouse_id:int,product_id:int,quantity:float}>  $allocations
     */
    private function assertStockBalances(array $before, array $allocations): void
    {
        foreach ($allocations as $key => $allocation) {
            $balance = StockBalance::query()
                ->where('warehouse_id', $allocation['warehouse_id'])
                ->where('product_id', $allocation['product_id'])
                ->firstOrFail();
            $expectedOnHand = $before[$key]['on_hand'] + $allocation['quantity'];
            $expectedReserved = $before[$key]['reserved'] + $allocation['quantity'];
            $expectedAvailable = max(0, $expectedOnHand - $expectedReserved);

            if (abs((float) $balance->quantity_on_hand - $expectedOnHand) > 0.00001
                || abs((float) $balance->quantity_reserved - $expectedReserved) > 0.00001
                || abs((float) $balance->quantity_available - $expectedAvailable) > 0.00001) {
                throw new RuntimeException('Kontrola końcowa wykryła nieprawidłowy stan albo rezerwację magazynową. Cała korekta została wycofana.');
            }
        }
    }

    /** @param list<WarehouseDocument> $drafts @param array<string,array{warehouse_id:int,product_id:int,quantity:float}> $expected */
    private function assertDrafts(array $drafts, array $expected): void
    {
        $actual = collect($drafts)
            ->each(fn (WarehouseDocument $document) => $document->loadMissing('lines'))
            ->flatMap(fn (WarehouseDocument $document) => $document->lines->map(fn ($line): array => [
                'key' => $document->source_warehouse_id.':'.$line->product_id,
                'quantity' => (float) $line->quantity,
                'status' => (string) $document->status,
            ]))
            ->groupBy('key')
            ->map(fn ($rows): array => [
                'quantity' => (float) $rows->sum('quantity'),
                'statuses' => $rows->pluck('status')->unique()->values()->all(),
            ]);

        if ($actual->keys()->sort()->values()->all() !== collect(array_keys($expected))->sort()->values()->all()) {
            throw new RuntimeException('Nowy szkic WZ nie obejmuje wszystkich rezerwacji zamówienia.');
        }

        foreach ($expected as $key => $allocation) {
            $row = $actual->get($key);

            if (! is_array($row)
                || $row['statuses'] !== ['draft']
                || abs((float) $row['quantity'] - $allocation['quantity']) > 0.00001) {
                throw new RuntimeException('Nowy szkic WZ ma nieprawidłowy status albo ilość.');
            }
        }
    }

    /** @param array<int,string> $fingerprints */
    private function assertPreservedLabels(EloquentCollection $labels, array $fingerprints): void
    {
        foreach ($labels as $label) {
            $fresh = ShippingLabel::query()->findOrFail($label->id);

            if (! isset($fingerprints[$fresh->id])
                || ! hash_equals($fingerprints[$fresh->id], $this->preservedLabelFingerprint($fresh))
                || $fresh->hasCourierPickupEvidence()) {
                throw new RuntimeException('Zachowywana etykieta zmieniła się podczas korekty. Cała operacja została wycofana.');
            }
        }
    }

    private function preservedLabelFingerprint(ShippingLabel $label): string
    {
        return HistoricalSplitSnapshot::fingerprint([
            'id' => (int) $label->id,
            'external_order_id' => (int) $label->external_order_id,
            'purpose' => (string) $label->purpose,
            'idempotency_key' => (string) $label->idempotency_key,
            'status' => (string) $label->status,
            'provider' => (string) $label->provider,
            'label_number' => (string) $label->label_number,
            'tracking_number' => (string) $label->tracking_number,
            'tracking_status' => (string) $label->tracking_status,
            'picked_up_at' => $label->picked_up_at?->toISOString(),
            'disk' => (string) $label->disk,
            'path' => (string) $label->path,
            'sha256' => (string) $label->sha256,
            'generated_at' => $label->generated_at?->toISOString(),
            'cod_amount' => $this->recordedCodAmount($label),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function balancePlan(EloquentCollection $documents): array
    {
        $plan = [];

        foreach ($this->expectedAllocations($documents) as $allocation) {
            $balance = StockBalance::query()
                ->with(['warehouse', 'product'])
                ->where('warehouse_id', $allocation['warehouse_id'])
                ->where('product_id', $allocation['product_id'])
                ->first();

            $onHand = (float) ($balance?->quantity_on_hand ?? 0);
            $reserved = (float) ($balance?->quantity_reserved ?? 0);
            $afterOnHand = $onHand + $allocation['quantity'];
            $afterReserved = $reserved + $allocation['quantity'];
            $plan[] = [
                'warehouse_id' => $allocation['warehouse_id'],
                'warehouse_code' => (string) ($balance?->warehouse?->code ?? ''),
                'product_id' => $allocation['product_id'],
                'sku' => (string) ($balance?->product?->sku ?? ''),
                'quantity' => $allocation['quantity'],
                'on_hand_before' => $onHand,
                'reserved_before' => $reserved,
                'available_before' => (float) ($balance?->quantity_available ?? 0),
                'on_hand_after' => $afterOnHand,
                'reserved_after' => $afterReserved,
                'available_after' => max(0, $afterOnHand - $afterReserved),
            ];
        }

        return $plan;
    }

    private function recordedCodAmount(ShippingLabel $label): ?float
    {
        $value = collect([
            data_get($label->response_payload, 'financial.requested_cod_amount'),
            data_get($label->response_payload, 'generation.request.cod_amount'),
            data_get($label->response_payload, 'generation.remote_checkpoint.request_payload.cod_amount'),
        ])->first(fn (mixed $candidate): bool => is_numeric($candidate));

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /** @return array{amount:float,currency:string,source:string}|null */
    private function recordedCodEvidence(ShippingLabel $label, ExternalOrder $order): ?array
    {
        return $this->recordedCodClaims($label, $order)[0] ?? null;
    }

    /** @return list<array{amount:float,currency:string,source:string}> */
    private function recordedCodClaims(ShippingLabel $label, ExternalOrder $order): array
    {
        $payload = (array) $label->response_payload;
        $reusedExistingShipment = (bool) data_get($payload, 'reused_existing_shipment', false)
            || (bool) data_get($payload, 'shipment.reused_existing_shipment', false)
            || (bool) data_get($payload, 'generation.remote_checkpoint.reused_existing_shipment', false);
        $candidates = [
            ['shipment.cod.amount', 'shipment.cod.currency', 'shipment.cod'],
            ['shipment.cod_amount.amount', 'shipment.cod_amount.currency', 'shipment.cod_amount'],
            ['generation.remote_checkpoint.response_payload.cod.amount', 'generation.remote_checkpoint.response_payload.cod.currency', 'generation.remote_checkpoint.response_payload.cod'],
        ];
        $claims = [];

        foreach ($candidates as [$amountPath, $currencyPath, $source]) {
            $amount = data_get($payload, $amountPath);
            $currency = strtoupper(trim((string) data_get($payload, $currencyPath, '')));

            if (is_numeric($amount) && $currency !== '') {
                $claims[] = [
                    'amount' => round((float) $amount, 2),
                    'currency' => $currency,
                    'source' => $source,
                ];
            }
        }

        if ($reusedExistingShipment) {
            return $claims;
        }

        $legacyAmount = data_get($payload, 'generation.request.cod_amount');
        $legacyCurrency = strtoupper(trim((string) data_get($payload, 'generation.request.cod_currency', '')));

        if (is_numeric($legacyAmount)
            && mb_strtolower(trim((string) $label->provider)) === 'inpost'
            && ($legacyCurrency !== '' || strtoupper(trim((string) $order->currency)) === 'PLN')) {
            $claims[] = [
                'amount' => round((float) $legacyAmount, 2),
                'currency' => $legacyCurrency !== '' ? $legacyCurrency : 'PLN',
                'source' => 'generation.request.legacy_inpost',
            ];
        }

        $financialAmount = data_get($payload, 'financial.requested_cod_amount');
        $financialCurrency = strtoupper(trim((string) data_get($payload, 'financial.currency', '')));

        if ($label->courier_account_id !== null
            && data_get($payload, 'financial.cash_on_delivery') === true
            && is_numeric($financialAmount)
            && $financialCurrency !== '') {
            $claims[] = [
                'amount' => round((float) $financialAmount, 2),
                'currency' => $financialCurrency,
                'source' => 'financial.direct_generation',
            ];
        }

        return $claims;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function auditState(array $state): array
    {
        /** @var ExternalOrder $order */
        $order = $state['order'];

        return [
            'order' => [
                'id' => (int) $order->id,
                'external_number' => (string) $order->external_number,
                'status' => (string) $order->status,
                'fulfillment_status' => (string) $order->fulfillment_status,
                'total_gross' => (string) $order->total_gross,
            ],
            'tasks' => $state['tasks']->map(fn (PackingTask $task): array => [
                'id' => (int) $task->id,
                'status' => (string) $task->status,
                'quantity_picked' => (string) $task->quantity_picked,
                'picked_at' => $task->picked_at?->toISOString(),
                'packed_at' => $task->packed_at?->toISOString(),
            ])->values()->all(),
            'wz' => $state['documents']->map(fn (WarehouseDocument $document): array => [
                'id' => (int) $document->id,
                'number' => (string) $document->number,
                'status' => (string) $document->status,
            ])->values()->all(),
            'labels' => $state['labels']->map(fn (ShippingLabel $label): array => [
                'id' => (int) $label->id,
                'status' => (string) $label->status,
                'tracking_number' => (string) $label->trackingIdentifier(),
                'fingerprint' => $this->preservedLabelFingerprint($label),
            ])->values()->all(),
            'balance_plan' => $this->balancePlan($state['documents']),
        ];
    }

    /** @param array<string,mixed> $marker @return array<string,mixed> */
    private function completedResult(ExternalOrder $order, array $marker): array
    {
        return [
            'order' => $order,
            'tasks' => (int) ($marker['tasks_reset'] ?? 0),
            'archived_wz_ids' => array_map('intval', (array) ($marker['archived_wz_ids'] ?? [])),
            'draft_wz_ids' => array_map('intval', (array) ($marker['draft_wz_ids'] ?? [])),
            'preserved_label_ids' => array_map('intval', (array) ($marker['preserved_label_ids'] ?? [])),
            'suspended_print_job_ids' => array_map('intval', (array) ($marker['suspended_print_job_ids'] ?? [])),
            'reservations' => StockReservation::query()
                ->where('sales_channel_id', $order->sales_channel_id)
                ->where('external_order_id', $order->external_id)
                ->where('status', 'active')
                ->count(),
            'operation_uuid' => (string) ($marker['request_uuid'] ?? ''),
        ];
    }
}
