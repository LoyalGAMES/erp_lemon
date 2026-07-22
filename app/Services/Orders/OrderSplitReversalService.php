<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\CustomerAccountClaim;
use App\Models\CustomerMessage;
use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\InternalNote;
use App\Models\Invoice;
use App\Models\OrderCancellation;
use App\Models\PackingTask;
use App\Models\PrintJob;
use App\Models\ReturnCase;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Audit\AuditLogService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\StockReservationService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Packing\PackingTaskService;
use App\Services\Shipping\ShippingCancellationService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class OrderSplitReversalService
{
    private const SHIPPING_LOCK_SECONDS = 900;

    private const SHIPPING_LOCK_WAIT_SECONDS = 15;

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly PackingTaskService $packingTasks,
        private readonly OrderMutationLock $orderLock,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly ShippingCancellationService $shippingCancellation,
        private readonly WarehouseDocumentPostingService $documentPosting,
        private readonly OrderCancellationInvoiceService $invoiceReversal,
        private readonly InvoiceWooCommerceUploadService $invoiceUpload,
        private readonly WooCommerceOrderStatusService $orderStatuses,
        private readonly CustomerCommunicationService $communication,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{
     *     available:bool,
     *     reasons:list<string>,
     *     root:ExternalOrder,
     *     family:EloquentCollection<int,ExternalOrder>,
     *     version:string,
     *     shipping_confirmation_required:bool,
     *     shipping_confirmation_reasons:list<string>
     * }
     */
    public function availability(ExternalOrder $order): array
    {
        $family = $this->familyOrders($order);
        $root = $this->rootOrder($order, $family);
        $lines = $this->familyLines($family);
        $snapshot = $this->originalSnapshot($root);
        $reasons = $this->blockers($root, $family, $lines, $snapshot);
        $shippingConfirmationReasons = $this->shippingConfirmationReasons($family);

        return [
            'available' => $reasons === [],
            'reasons' => $reasons,
            'root' => $root,
            'family' => $family,
            'version' => $this->familyVersion($family, $lines),
            'shipping_confirmation_required' => $shippingConfirmationReasons !== [],
            'shipping_confirmation_reasons' => $shippingConfirmationReasons,
        ];
    }

    /**
     * Simulate normal reversal against a server-built historical snapshot. This
     * never mutates the order and is used only by the administrator-only
     * reconciliation flow before the snapshot is adopted.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array{available:bool,reasons:list<string>}
     */
    public function availabilityAgainstSnapshot(ExternalOrder $order, array $snapshot): array
    {
        if (! HistoricalSplitSnapshot::isVerified($snapshot)) {
            return [
                'available' => false,
                'reasons' => ['Historyczny zapis stanu początkowego ma nieprawidłowy format.'],
            ];
        }

        $family = $this->familyOrders($order);
        $root = $this->rootOrder($order, $family);
        $lines = $this->familyLines($family);
        $reasons = $this->blockers($root, $family, $lines, $snapshot);

        return [
            'available' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    public function reverse(
        ExternalOrder $order,
        string $expectedFamilyVersion,
        ?string $note = null,
        bool $confirmManualShippingCancellation = false,
        ?User $actor = null,
    ): ExternalOrder {
        $authorizationFamily = $this->familyOrders($order);
        $authorizationRoot = $this->rootOrder($order, $authorizationFamily);

        if (HistoricalSplitSnapshot::isVerified($this->originalSnapshot($authorizationRoot))
            && (! $actor instanceof User || ! $actor->isAdministrator())) {
            throw new RuntimeException('Tylko administrator może wykonać cofnięcie zweryfikowanego historycznego podziału.');
        }

        return $this->orderLock->forSplitReversal($order, function () use ($order, $expectedFamilyVersion, $note, $confirmManualShippingCancellation, $actor): ExternalOrder {
            $familyIds = $this->familyOrders($order)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            try {
                return $this->withShippingLocks(
                    $familyIds,
                    0,
                    fn (): ExternalOrder => $this->reverseWhileLocked(
                        $order,
                        $expectedFamilyVersion,
                        $note,
                        $confirmManualShippingCancellation,
                        $actor,
                    ),
                );
            } catch (LockTimeoutException $exception) {
                throw new RuntimeException(
                    'Dla jednej z części zamówienia trwa generowanie lub anulowanie etykiety. Spróbuj ponownie za chwilę.',
                    previous: $exception,
                );
            }
        });
    }

    private function reverseWhileLocked(
        ExternalOrder $order,
        string $expectedFamilyVersion,
        ?string $note,
        bool $confirmManualShippingCancellation,
        ?User $actor,
    ): ExternalOrder {
        $prepared = $this->prepareReversal($order, $expectedFamilyVersion, $note, $actor);
        $operationUuid = $prepared['operation_uuid'];
        $reason = trim((string) $note) !== ''
            ? trim((string) $note)
            : 'Cofnięcie rozdzielenia zamówienia i pracy wykonanej po podziale.';
        $artifactCutoff = $prepared['artifact_cutoff'];

        if (! $artifactCutoff instanceof CarbonInterface) {
            throw new RuntimeException('Nie można potwierdzić czasu granicznego cofnięcia podziału.');
        }

        $artifactCutoff = CarbonImmutable::instance($artifactCutoff);

        try {
            $shipping = $this->shippingCancellation->cancelForOrderIdsWhileLocked(
                $prepared['family_order_ids'],
                $operationUuid,
                $reason,
                $artifactCutoff,
            );
        } catch (Throwable $exception) {
            $this->recordOperationStep($prepared['root_order_id'], $operationUuid, 'shipping', 'failed', [
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $manualShipping = collect((array) ($shipping['manual_required'] ?? []))
            ->filter(fn (mixed $warning): bool => is_array($warning))
            ->values();
        $this->recordOperationStep(
            $prepared['root_order_id'],
            $operationUuid,
            'shipping',
            $manualShipping->isEmpty() || $confirmManualShippingCancellation ? 'completed' : 'attention_required',
            $shipping + ['manual_confirmation' => $confirmManualShippingCancellation],
        );

        if ($manualShipping->isNotEmpty() && ! $confirmManualShippingCancellation) {
            $messages = $manualShipping
                ->pluck('message')
                ->filter()
                ->map(fn (mixed $message): string => (string) $message)
                ->unique()
                ->implode(' ');

            throw new RuntimeException(
                ($messages !== '' ? $messages.' ' : '')
                .'Rodzina pozostała rozdzielona. Po sprawdzeniu przewoźnika i zniszczeniu ewentualnych wydruków '
                .'odśwież stronę, zaznacz potwierdzenie ręczne i ponów cofnięcie.',
            );
        }

        try {
            $invoiceResult = $this->reverseInvoices(
                $prepared['family_order_ids'],
                $artifactCutoff,
                $operationUuid,
                $reason,
            );
            $this->recordOperationStep(
                $prepared['root_order_id'],
                $operationUuid,
                'invoices',
                'completed',
                $invoiceResult,
            );
        } catch (Throwable $exception) {
            $this->recordOperationStep($prepared['root_order_id'], $operationUuid, 'invoices', 'failed', [
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Nie udało się bezpiecznie cofnąć dokumentów sprzedaży. '.$exception->getMessage(), previous: $exception);
        }

        try {
            $wooStatus = $this->restoreWooStatus(
                $prepared['root_order_id'],
                $prepared['original_status'],
            );
            $this->recordOperationStep(
                $prepared['root_order_id'],
                $operationUuid,
                'woocommerce_status',
                'completed',
                $wooStatus,
            );
        } catch (Throwable $exception) {
            $this->recordOperationStep($prepared['root_order_id'], $operationUuid, 'woocommerce_status', 'failed', [
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                'Nie przywrócono statusu zamówienia w WooCommerce; rodzina pozostaje rozdzielona. '.$exception->getMessage(),
                previous: $exception,
            );
        }

        try {
            $result = $this->finalizeReversal(
                $order,
                $operationUuid,
                $expectedFamilyVersion,
                $note,
                $artifactCutoff,
                $reason,
                [
                    'shipping' => $shipping,
                    'invoices' => $invoiceResult,
                    'woocommerce_status' => $wooStatus,
                    'manual_shipping_confirmation' => $confirmManualShippingCancellation,
                ],
                $prepared['sent_post_split_customer_message'],
            );
        } catch (Throwable $exception) {
            $this->recordOperationStep($prepared['root_order_id'], $operationUuid, 'local_finalization', 'failed', [
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                'Nie udało się bezpiecznie cofnąć lokalnego pakowania i dokumentów WZ. '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if ($prepared['sent_post_split_customer_message']) {
            try {
                $notification = CustomerMessage::query()
                    ->where('external_order_id', $result->id)
                    ->where('trigger', 'order_packing_rollback')
                    ->get()
                    ->first(fn (CustomerMessage $message): bool => hash_equals(
                        $operationUuid,
                        (string) data_get($message->metadata, 'outbox_idempotency_key', ''),
                    ));

                if (! $notification instanceof CustomerMessage) {
                    throw new RuntimeException('Nie znaleziono zapisanej wiadomości w kolejce wysyłkowej.');
                }

                $notification = $this->communication->deliverQueued($notification);

                if ((string) $notification->status === 'failed') {
                    throw new RuntimeException((string) ($notification->error_message ?: 'Nieznany błąd wysyłki.'));
                }
            } catch (Throwable $exception) {
                $this->audit->record('order.split_reversal_notification_failed', $result, null, null, [
                    'split_reversal_uuid' => $operationUuid,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * @return array{
     *     root_order_id:int,
     *     family_order_ids:list<int>,
     *     operation_uuid:string,
     *     split_started_at:string,
     *     artifact_cutoff:CarbonImmutable,
     *     original_status:string,
     *     sent_post_split_customer_message:bool
     * }
     */
    private function prepareReversal(
        ExternalOrder $order,
        string $expectedFamilyVersion,
        ?string $note,
        ?User $actor,
    ): array {
        return DB::transaction(function () use ($order, $expectedFamilyVersion, $note, $actor): array {
            $fresh = ExternalOrder::query()->findOrFail($order->id);
            $rootId = (int) ($fresh->split_root_order_id ?: $fresh->id);
            $family = ExternalOrder::query()
                ->where('sales_channel_id', $fresh->sales_channel_id)
                ->where(fn ($query) => $query->whereKey($rootId)->orWhere('split_root_order_id', $rootId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $root = $family->firstWhere('id', $rootId);

            if (! $root instanceof ExternalOrder) {
                throw new RuntimeException('Nie znaleziono zamówienia głównego rodziny.');
            }

            $lines = ExternalOrderLine::query()
                ->whereIn('external_order_id', $family->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $currentVersion = $this->familyVersion($family, $lines);

            if ($expectedFamilyVersion === '' || ! hash_equals($currentVersion, $expectedFamilyVersion)) {
                throw new RuntimeException('Rodzina zamówienia zmieniła się od otwarcia strony. Odśwież widok i sprawdź ją przed ponownym cofnięciem.');
            }

            $snapshot = $this->originalSnapshot($root);

            if (HistoricalSplitSnapshot::isVerified($snapshot)
                && (! $actor instanceof User || ! $actor->isAdministrator())) {
                throw new RuntimeException('Tylko administrator może wykonać cofnięcie zweryfikowanego historycznego podziału.');
            }

            $reasons = $this->blockers($root, $family, $lines, $snapshot);

            if ($reasons !== []) {
                throw new RuntimeException(implode(' ', $reasons));
            }

            $splitStartedAt = $this->splitStartedAt($root, $family, $snapshot);
            $raw = (array) $root->raw_payload;
            $operation = data_get($raw, 'sempre_erp_split_reversal_operation');

            if (! is_array($operation) || blank($operation['uuid'] ?? null)) {
                $operation = [
                    'uuid' => (string) Str::uuid(),
                    'status' => 'processing',
                    'started_at' => now()->toISOString(),
                    'note' => $note,
                    'steps' => [],
                ];

            } else {
                $operation['status'] = 'processing';
                $operation['resumed_at'] = now()->toISOString();
                $operation['note'] = $note ?: ($operation['note'] ?? null);
            }

            $raw['sempre_erp_split_reversal_operation'] = $operation;
            $root->update(['raw_payload' => $raw]);

            return [
                'root_order_id' => (int) $root->id,
                'family_order_ids' => $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                'operation_uuid' => (string) $operation['uuid'],
                'split_started_at' => $splitStartedAt->toISOString(),
                'artifact_cutoff' => $this->postSplitArtifactCutoff($root, $family, $snapshot),
                'original_status' => $this->originalStatus($root, $snapshot, $family),
                'sent_post_split_customer_message' => ! HistoricalSplitSnapshot::isVerified($snapshot)
                    && CustomerMessage::query()
                        ->whereIn('external_order_id', $family->pluck('id'))
                        ->whereIn('trigger', ['order_partial_created', 'order_packed'])
                        ->where('status', 'sent')
                        ->where('created_at', '>=', $splitStartedAt)
                        ->exists(),
            ];
        }, 3);
    }

    /** @param array<string,mixed> $effects */
    private function finalizeReversal(
        ExternalOrder $order,
        string $operationUuid,
        string $expectedFamilyVersion,
        ?string $note,
        CarbonInterface $artifactCutoff,
        string $reason,
        array $effects,
        bool $shouldNotifyCustomer,
    ): ExternalOrder {
        return DB::transaction(function () use ($order, $operationUuid, $expectedFamilyVersion, $note, $artifactCutoff, $reason, $effects, $shouldNotifyCustomer): ExternalOrder {
            $fresh = ExternalOrder::query()->findOrFail($order->id);
            $rootId = (int) ($fresh->split_root_order_id ?: $fresh->id);
            $family = ExternalOrder::query()
                ->where('sales_channel_id', $fresh->sales_channel_id)
                ->where(fn ($query) => $query->whereKey($rootId)->orWhere('split_root_order_id', $rootId))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $root = $family->firstWhere('id', $rootId);

            if (! $root instanceof ExternalOrder) {
                throw new RuntimeException('Nie znaleziono zamówienia głównego rodziny.');
            }

            if ((string) data_get($root->raw_payload, 'sempre_erp_split_reversal_operation.uuid') !== $operationUuid) {
                throw new RuntimeException('Stan operacji cofnięcia zmienił się. Odśwież zamówienie i spróbuj ponownie.');
            }

            $snapshot = $this->originalSnapshot($root);

            // Cancelling a posted WZ releases physical stock. It must happen
            // in the same database transaction that recreates the restored
            // root reservations; otherwise a later remote-step failure could
            // expose this stock to another order indefinitely.
            $warehouseDocuments = $this->reverseWarehouseDocuments(
                $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                $artifactCutoff,
                $operationUuid,
                $reason,
                $snapshot,
            );
            $effects['warehouse_document_ids'] = $warehouseDocuments;

            $lines = ExternalOrderLine::query()
                ->whereIn('external_order_id', $family->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($this->familyWasShipped($family)) {
                throw new RuntimeException('Jedna z części została już wysłana albo odebrana przez kuriera. Cofnięcie podziału jest zablokowane.');
            }

            $children = $family->where('id', '!=', $root->id)->values();
            $before = $this->auditSnapshot($family, $lines);
            $restoredLines = $snapshot !== null
                ? $this->linesFromSnapshot($snapshot)
                : $this->mergeCurrentLines($root, $lines);
            $restoredTotal = $this->restoredTotal($root, $family, $snapshot);
            $restoredRawPayload = $this->restoredRawPayload($root, $snapshot, $family);
            $this->archiveCancelledShippingLabels($family, $operationUuid, $artifactCutoff);
            $cancelledShipmentIdentities = $this->cancelledShipmentIdentities(
                $family,
                $artifactCutoff,
                $operationUuid,
            );
            $restoredRawPayload = $this->withCancelledShipmentIdentityTombstone(
                $restoredRawPayload,
                $cancelledShipmentIdentities,
                $operationUuid,
            );

            $root->lines()->delete();

            foreach ($restoredLines as $line) {
                $root->lines()->create($line);
            }

            $operational = (array) ($snapshot['operational'] ?? []);
            $snapshotOrder = is_array($snapshot['order'] ?? null)
                ? $snapshot['order']
                : [];

            if ($snapshot === null) {
                $legacyBaselineOrder = $this->legacyBaselineOrder($root, $family);

                if ($legacyBaselineOrder instanceof ExternalOrder) {
                    $snapshotOrder = [
                        'currency' => $legacyBaselineOrder->currency,
                        'billing_data' => $legacyBaselineOrder->billing_data,
                        'shipping_data' => $legacyBaselineOrder->shipping_data,
                        'external_created_at' => $legacyBaselineOrder->getRawOriginal('external_created_at'),
                    ];
                }
            }
            $rootUpdates = [
                'status' => $this->originalStatus($root, $snapshot, $family),
                'total_gross' => $restoredTotal,
                'raw_payload' => $restoredRawPayload,
                'label_generation_attempts' => (int) ($operational['label_generation_attempts'] ?? 0),
                'label_generation_next_at' => $operational['label_generation_next_at'] ?? null,
                'label_generation_last_error' => $operational['label_generation_last_error'] ?? null,
                'woo_shipped_sync_status' => $operational['woo_shipped_sync_status'] ?? null,
                'woo_shipped_sync_attempts' => (int) ($operational['woo_shipped_sync_attempts'] ?? 0),
                'woo_shipped_sync_next_at' => $operational['woo_shipped_sync_next_at'] ?? null,
                'woo_shipped_sync_error' => $operational['woo_shipped_sync_error'] ?? null,
            ];

            foreach ([
                'sales_channel_id',
                'customer_id',
                'customer_external_account_id',
                'wordpress_integration_id',
                'customer_match_method',
                'external_id',
                'external_number',
                'fulfillment_status',
                'currency',
                'billing_data',
                'shipping_data',
                'external_created_at',
                'external_updated_at',
            ] as $attribute) {
                if (array_key_exists($attribute, $snapshotOrder)) {
                    $rootUpdates[$attribute] = $snapshotOrder[$attribute];
                }
            }

            $root->update($rootUpdates);
            $root->refresh()->load('lines');

            $packingTasks = PackingTask::query()
                ->whereIn('external_order_id', $family->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $preservedPackingTasks = HistoricalSplitSnapshot::isVerified($snapshot)
                ? HistoricalSplitSnapshot::preservedPackingTasks($snapshot)
                : [];

            if (HistoricalSplitSnapshot::isVerified($snapshot)) {
                $rootLinesByCanonical = $root->lines->keyBy(
                    fn (ExternalOrderLine $line): string => (string) $this->canonicalExternalLineId($line),
                );
                $restoredTaskIds = [];

                foreach ($packingTasks as $task) {
                    $saved = $preservedPackingTasks[(int) $task->id] ?? null;
                    $previousMetadata = (array) $task->metadata;
                    $auditMetadata = [
                        'root_order_id' => $root->id,
                        'previous_order_id' => $task->external_order_id,
                        'previous_status' => $task->status,
                        'previous_quantity_picked' => (string) $task->quantity_picked,
                        'previous_picked_at' => $task->picked_at?->toISOString(),
                        'previous_packed_at' => $task->packed_at?->toISOString(),
                        'reversed_at' => now()->toISOString(),
                    ];

                    if (is_array($saved)) {
                        $canonical = (string) ($saved['canonical_external_line_id'] ?? '');
                        $rootLine = $rootLinesByCanonical->get($canonical);

                        if (! $rootLine instanceof ExternalOrderLine) {
                            throw new RuntimeException("Nie można ponownie powiązać zachowanego zadania #{$task->id} z pozycją {$canonical}.");
                        }

                        $metadata = (array) ($saved['metadata'] ?? []);
                        $metadata['split_reversal'] = $auditMetadata + [
                            'historical_baseline_preserved' => true,
                        ];
                        $task->update([
                            'external_order_id' => $root->id,
                            'external_order_line_id' => $rootLine->id,
                            'external_line_id' => $rootLine->external_line_id,
                            'product_id' => $rootLine->product_id,
                            'order_number' => $root->external_number,
                            'sku' => $rootLine->sku,
                            'quantity_required' => (float) ($saved['quantity_required'] ?? $rootLine->quantity),
                            'quantity_picked' => (float) ($saved['quantity_picked'] ?? 0),
                            'status' => (string) ($saved['status'] ?? 'open'),
                            'courier' => $saved['courier'] ?? $task->courier,
                            'size_label' => $saved['size_label'] ?? $task->size_label,
                            'order_date' => $this->historicalTaskDate(
                                $saved['order_date'] ?? $task->order_date,
                            ),
                            'picked_at' => $this->historicalTaskDate($saved['picked_at'] ?? null),
                            'packed_at' => $this->historicalTaskDate($saved['packed_at'] ?? null),
                            'metadata' => $metadata,
                        ]);
                        $restoredTaskIds[] = (int) $task->id;

                        continue;
                    }

                    $previousMetadata['split_reversal'] = $auditMetadata;
                    unset(
                        $previousMetadata['packing_completion'],
                        $previousMetadata['packing_problem'],
                        $previousMetadata['courier_pickup'],
                    );
                    $task->update([
                        'status' => 'cancelled',
                        'quantity_picked' => 0,
                        'picked_at' => null,
                        'packed_at' => null,
                        'metadata' => $previousMetadata,
                    ]);
                }

                if (collect($restoredTaskIds)->sort()->values()->all()
                    !== collect(array_keys($preservedPackingTasks))->sort()->values()->all()) {
                    throw new RuntimeException('Nie odtworzono wszystkich zadań pakowania zapisanych przed historycznym podziałem.');
                }
            } else {
                $rootExternalLineIds = $root->lines
                    ->map(fn (ExternalOrderLine $line): string => (string) ($line->external_line_id ?: 'line-'.$line->id))
                    ->all();
                $activatedRootLineIds = [];

                foreach ($packingTasks as $task) {
                    $metadata = (array) $task->metadata;
                    $metadata['split_reversal'] = [
                        'root_order_id' => $root->id,
                        'previous_order_id' => $task->external_order_id,
                        'previous_status' => $task->status,
                        'previous_quantity_picked' => (string) $task->quantity_picked,
                        'previous_picked_at' => $task->picked_at?->toISOString(),
                        'previous_packed_at' => $task->packed_at?->toISOString(),
                        'previous_packing_completion' => $metadata['packing_completion'] ?? null,
                        'previous_packing_problem' => $metadata['packing_problem'] ?? null,
                        'previous_courier_pickup' => $metadata['courier_pickup'] ?? null,
                        'reversed_at' => now()->toISOString(),
                    ];
                    unset($metadata['packing_completion'], $metadata['packing_problem'], $metadata['courier_pickup']);

                    $externalLineId = (string) $task->external_line_id;
                    $activateOnRoot = (int) $task->external_order_id === (int) $root->id
                        && in_array($externalLineId, $rootExternalLineIds, true)
                        && ! isset($activatedRootLineIds[$externalLineId]);

                    if ($activateOnRoot) {
                        $activatedRootLineIds[$externalLineId] = true;
                        $task->update([
                            'status' => 'open',
                            'quantity_picked' => 0,
                            'picked_at' => null,
                            'packed_at' => null,
                            'metadata' => $metadata,
                        ]);
                    } else {
                        $task->update([
                            'status' => 'cancelled',
                            'quantity_picked' => 0,
                            'picked_at' => null,
                            'packed_at' => null,
                            'metadata' => $metadata,
                        ]);
                    }
                }
            }

            foreach ($children as $child) {
                $child->update(['status' => 'cancelled']);
                $this->reservations->syncForOrder($child);
            }

            $splitStartedAt = $this->splitStartedAt($root, $family, $snapshot);
            $artifactCutoff = $this->postSplitArtifactCutoff($root, $family, $snapshot);
            $this->moveCommunicationHistory($root, $children, $splitStartedAt, $operationUuid);
            $this->consolidateReflectedOrderQuantities(
                $root,
                $children,
                $snapshot,
                $lines->pluck('product_id')
                    ->filter()
                    ->map(fn (mixed $productId): int => (int) $productId)
                    ->unique()
                    ->values()
                    ->all(),
            );
            foreach ($children as $child) {
                $raw = (array) $child->raw_payload;
                $raw['sempre_erp_split_reversal'] = [
                    'root_order_id' => $root->id,
                    'root_external_id' => $root->external_id,
                    'note' => $note,
                    'reversed_at' => now()->toISOString(),
                ];
                $child->update([
                    'status' => 'split-reverted',
                    'raw_payload' => $raw,
                ]);
                $child->delete();
            }

            $this->reservations->syncForOrder($root);

            if (! HistoricalSplitSnapshot::isVerified($snapshot) || $preservedPackingTasks !== []) {
                $this->packingTasks->syncForOrder($root);
            }

            $root = $root->fresh('lines') ?? $root;

            if (HistoricalSplitSnapshot::isVerified($snapshot)) {
                $this->verifyHistoricalPostconditions($root, $snapshot, $operationUuid);
            }

            if ($shouldNotifyCustomer) {
                $this->communication->queueOrderStatus(
                    $root,
                    'order_packing_rollback',
                    [
                        'rollback_reason' => $reason,
                        'split_reversal_uuid' => $operationUuid,
                    ],
                    $operationUuid,
                );
            }

            $this->audit->record('order.split_reverted', $root, $before, [
                'root_order_id' => $root->id,
                'root_order_number' => $root->external_number,
                'restored_total_gross' => $restoredTotal,
                'restored_lines' => $root->lines->map(fn (ExternalOrderLine $line): array => [
                    'external_line_id' => $line->external_line_id,
                    'sku' => $line->sku,
                    'quantity' => (string) $line->quantity,
                ])->values()->all(),
                'archived_child_order_ids' => $children->pluck('id')->values()->all(),
                'reversed_effects' => $effects,
            ], [
                'note' => $note,
                'family_version' => $expectedFamilyVersion,
                'split_reversal_uuid' => $operationUuid,
            ]);

            return $root;
        }, 3);
    }

    /**
     * @param  EloquentCollection<int, ExternalOrder>  $family
     * @param  EloquentCollection<int, ExternalOrderLine>  $lines
     * @return list<string>
     */
    private function blockers(
        ExternalOrder $root,
        EloquentCollection $family,
        EloquentCollection $lines,
        ?array $snapshot,
    ): array {
        $integrityReasons = $this->familyIntegrityBlockers($root, $family);

        if ($integrityReasons !== []) {
            return $integrityReasons;
        }

        if ($family->count() <= 1) {
            return ['To zamówienie nie ma aktywnych części do scalenia.'];
        }

        $reasons = [];
        $orderIds = $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $childIds = $family->where('id', '!=', $root->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $lineIds = $lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $splitStartedAt = $this->splitStartedAt($root, $family, $snapshot);
        $artifactCutoff = $this->postSplitArtifactCutoff($root, $family, $snapshot);

        if (HistoricalSplitSnapshot::isVerified($snapshot)) {
            $reasons = [
                ...$reasons,
                ...$this->verifiedHistoricalSnapshotBlockers($root, $family, $lines, $snapshot, $artifactCutoff),
            ];
        }

        if (OrderCancellation::query()
            ->whereIn('external_order_id', $orderIds)
            ->where('status', '!=', 'rejected')
            ->exists()) {
            $reasons[] = 'Rodzina ma zapisaną operację anulowania.';
        }

        if ($family->contains(fn (ExternalOrder $member): bool => in_array(
            mb_strtolower((string) $member->status),
            ['cancellation-pending', 'cancelled', 'refunded'],
            true,
        ))) {
            $reasons[] = 'Jedna z części jest anulowana, zwrócona albo trwa jej anulowanie.';
        }

        if ($this->familyWasShipped($family)) {
            $reasons[] = 'Jedna z części została już wysłana albo odebrana przez kuriera. Cofnięcie podziału jest wtedy niedostępne.';
        }

        $olderActiveLabels = ShippingLabel::query()
            ->shipments()
            ->whereIn('external_order_id', $orderIds)
            ->where('created_at', '<', $artifactCutoff)
            ->where('status', '!=', 'cancelled')
            ->orderBy('id')
            ->get();

        if ($olderActiveLabels->contains(fn (ShippingLabel $label): bool => ! $this->isPreservedHistoricalLabel(
            $label,
            $snapshot,
        ))) {
            $reasons[] = 'Rodzina ma aktywną etykietę starszą niż podział. Wymagana jest ręczna weryfikacja przewoźnika.';
        }

        $preservedShipmentIdentities = $this->preservedShipmentIdentities($snapshot, $olderActiveLabels);

        if ($family->contains(function (ExternalOrder $member) use ($artifactCutoff, $preservedShipmentIdentities): bool {
            $identities = collect($this->shipmentIdentities((array) $member->raw_payload))
                ->reject(fn (string $identity): bool => in_array($identity, $preservedShipmentIdentities, true))
                ->values()
                ->all();

            if ($identities === []) {
                return false;
            }

            return ! ShippingLabel::query()
                ->shipments()
                ->where('external_order_id', $member->id)
                ->where('created_at', '>=', $artifactCutoff)
                ->where(function ($query) use ($identities): void {
                    $query
                        ->whereIn('label_number', $identities)
                        ->orWhereIn('tracking_number', $identities);
                })
                ->exists();
        })) {
            $reasons[] = 'Jedna z części ma identyfikator przesyłki ze sklepu bez dokładnie zgodnej etykiety ERP utworzonej po podziale. Wymagana jest ręczna weryfikacja przewoźnika.';
        }

        $wzDocuments = $this->wzDocumentsForFamily($family);

        if ($wzDocuments->contains(fn (WarehouseDocument $document): bool => $document->created_at?->lt($artifactCutoff)
            && $document->status !== 'cancelled'
            && ! $this->isPreservedHistoricalWarehouseDocument($document, $snapshot))) {
            $reasons[] = 'Rodzina ma aktywny dokument WZ starszy niż podział. Nie można go automatycznie przypisać do pracy po rozdzieleniu.';
        }

        if ($wzDocuments->contains(fn (WarehouseDocument $document): bool => ! in_array(
            (string) $document->status,
            ['draft', 'posted', 'cancelled'],
            true,
        ))) {
            $reasons[] = 'Jeden z dokumentów WZ ma status, którego nie można bezpiecznie cofnąć.';
        }

        if (Invoice::withTrashed()
            ->whereIn('external_order_id', $orderIds)
            ->where('created_at', '<', $artifactCutoff)
            ->exists()) {
            $reasons[] = 'Rodzina ma dokument sprzedaży starszy niż podział. Wymagana jest ręczna weryfikacja księgowa.';
        }

        if (ReturnCase::withTrashed()
            ->where(fn ($query) => $query->whereIn('external_order_id', $orderIds)
                ->orWhereHas('lines', fn ($returnLines) => $returnLines->whereIn('external_order_line_id', $lineIds)))
            ->exists()) {
            $reasons[] = 'Dla jednej z części rozpoczęto obsługę zwrotu.';
        }

        if (CustomerPayment::query()->whereIn('external_order_id', $childIds)->exists()
            || CustomerPayment::query()->whereIn('external_order_id', $orderIds)->where('direction', 'outgoing')->exists()) {
            $reasons[] = 'Rodzina ma płatność przypisaną do części albo operację zwrotu środków.';
        }

        if (CustomerAccountClaim::query()->whereIn('external_order_id', $childIds)->exists()) {
            $reasons[] = 'Jedna z części jest powiązana z aktywacją konta klienta.';
        }

        if ($family->pluck('currency')->map(fn (mixed $currency): string => strtoupper((string) $currency))->unique()->count() !== 1) {
            $reasons[] = 'Części mają różne waluty.';
        }

        $baselineCommercePayload = is_array($snapshot['raw_payload'] ?? null)
            ? $snapshot['raw_payload']
            : $this->legacyBaselinePayload($root, $family);

        if (is_array($baselineCommercePayload)
            && $this->commerceFingerprint($baselineCommercePayload) !== $this->commerceFingerprint((array) $root->raw_payload)) {
            $reasons[] = 'Dane handlowe zamówienia w WooCommerce zmieniły się po podziale. Automatyczne przywrócenie mogłoby zostać nadpisane lub odtworzyć nieaktualne kwoty, adres albo pozycje; wymagana jest ręczna weryfikacja.';
        }

        if ($snapshot === null) {
            $legacyBaselinePayload = $this->legacyBaselinePayload($root, $family);

            if ($legacyBaselinePayload === null) {
                $reasons[] = 'Historyczny podział nie ma pełnego zapisu WooCommerce potrzebnego do bezpiecznego odtworzenia kwoty, statusu i pozycji.';
            }

            if (PackingTask::query()
                ->whereIn('external_order_id', $orderIds)
                ->where(function ($query) use ($artifactCutoff): void {
                    $query
                        ->where('picked_at', '<', $artifactCutoff)
                        ->orWhere('packed_at', '<', $artifactCutoff)
                        ->orWhere(function ($pickedWithoutTimestamp) use ($artifactCutoff): void {
                            $pickedWithoutTimestamp
                                ->where('quantity_picked', '>', 0)
                                ->whereNull('picked_at')
                                ->where('created_at', '<', $artifactCutoff);
                        });
                })
                ->exists()) {
                $reasons[] = 'W historycznym podziale kompletowanie lub pakowanie rozpoczęło się przed utworzeniem części. Bez zapisu stanu początkowego nie można bezpiecznie wyzerować tej wcześniejszej pracy.';
            }

            if ($this->legacyPackingProblemPredatesSplit($orderIds, $artifactCutoff)) {
                $reasons[] = 'W historycznym podziale problem pakowania został zgłoszony przed utworzeniem części. Bez zapisu stanu początkowego nie można go bezpiecznie usunąć.';
            }

            if ($this->legacyPackingCancellationPredatesSplit($orderIds, $artifactCutoff)) {
                $reasons[] = 'W historycznym podziale zadanie pakowania zostało anulowane przed utworzeniem części. Bez zapisu stanu początkowego nie można go bezpiecznie otworzyć ponownie.';
            }

            if ($this->legacyWarehouseDocumentLacksSourceBaseline($wzDocuments, $artifactCutoff)) {
                $reasons[] = 'Historyczny dokument WZ nie zawiera zapisu bazowego stanu magazynowego sprzed wydania ani kompletnej pary zapisów wydania i anulowania. Automatyczne cofnięcie mogłoby zawyżyć dostępny stan; wymagana jest ręczna weryfikacja magazynu.';
            }

            $lineageReason = $legacyBaselinePayload !== null
                ? $this->lineageProblem($lines, $legacyBaselinePayload)
                : null;

            if ($lineageReason !== null) {
                $reasons[] = $lineageReason;
            }
        } elseif (HistoricalSplitSnapshot::isVerified($snapshot)) {
            $baselinePayload = is_array($snapshot['raw_payload'] ?? null)
                ? $snapshot['raw_payload']
                : null;
            $lineageReason = $baselinePayload !== null
                ? $this->lineageProblem($lines, $baselinePayload)
                : 'Zweryfikowany zapis historyczny nie zawiera pełnego payloadu WooCommerce.';

            if ($lineageReason !== null) {
                $reasons[] = $lineageReason;
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @param  EloquentCollection<int,ExternalOrderLine>  $lines
     * @param  array<string,mixed>  $snapshot
     * @return list<string>
     */
    private function verifiedHistoricalSnapshotBlockers(
        ExternalOrder $root,
        EloquentCollection $family,
        EloquentCollection $lines,
        array $snapshot,
        CarbonInterface $artifactCutoff,
    ): array {
        $reasons = [];
        $familyIds = $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $snapshotFamilyIds = collect((array) data_get($snapshot, 'legacy_adoption.family_order_ids', []))
            ->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $sourceOrderId = (int) data_get($snapshot, 'legacy_adoption.source_order_id', 0);
        $sourceOrder = $family->firstWhere('id', $sourceOrderId);

        if ((int) data_get($snapshot, 'legacy_adoption.root_order_id', 0) !== (int) $root->id
            || $snapshotFamilyIds !== $familyIds) {
            $reasons[] = 'Zweryfikowany zapis historyczny nie odpowiada bieżącej rodzinie zamówień.';
        }

        if (! $sourceOrder instanceof ExternalOrder
            || (int) $sourceOrder->id === (int) $root->id
            || (int) $sourceOrder->split_parent_order_id !== (int) $root->id
            || $sourceOrder->created_at === null) {
            $reasons[] = 'Nie można potwierdzić źródłowej części wyznaczającej czas historycznego podziału.';
        }

        $orderIds = $familyIds;
        $preservedLabelFingerprints = HistoricalSplitSnapshot::preservedLabelFingerprints($snapshot);
        $preservedLabels = ShippingLabel::query()
            ->shipments()
            ->whereIn('id', array_keys($preservedLabelFingerprints))
            ->orderBy('id')
            ->get();

        if ($preservedLabels->count() !== count($preservedLabelFingerprints)) {
            $reasons[] = 'Nie znaleziono wszystkich etykiet zatwierdzonych jako stan sprzed podziału.';
        }

        foreach ($preservedLabels as $label) {
            if (! in_array((int) $label->external_order_id, $orderIds, true)
                || $label->created_at?->gte($artifactCutoff) === true
                || (string) $label->status === 'cancelled'
                || $label->hasCourierPickupEvidence()
                || ! hash_equals(
                    (string) ($preservedLabelFingerprints[(int) $label->id] ?? ''),
                    HistoricalSplitSnapshot::shippingLabelFingerprint($label),
                )) {
                $reasons[] = 'Etykieta zatwierdzona jako stan sprzed podziału zmieniła się albo zawiera dowód nadania.';
                break;
            }
        }

        $preservedDocumentFingerprints = HistoricalSplitSnapshot::preservedWarehouseDocumentFingerprints($snapshot);
        $preservedDocuments = WarehouseDocument::query()
            ->with(['lines', 'ledgerEntries'])
            ->whereIn('id', array_keys($preservedDocumentFingerprints))
            ->orderBy('id')
            ->get();

        if ($preservedDocuments->count() !== count($preservedDocumentFingerprints)) {
            $reasons[] = 'Nie znaleziono wszystkich dokumentów WZ zatwierdzonych jako stan sprzed podziału.';
        }

        foreach ($preservedDocuments as $document) {
            if ($document->created_at?->gte($artifactCutoff) === true
                || (string) $document->status !== 'posted'
                || ! hash_equals(
                    (string) ($preservedDocumentFingerprints[(int) $document->id] ?? ''),
                    HistoricalSplitSnapshot::warehouseDocumentFingerprint($document),
                )) {
                $reasons[] = 'Dokument WZ zatwierdzony jako stan sprzed podziału zmienił się.';
                break;
            }
        }

        $preservedTasks = HistoricalSplitSnapshot::preservedPackingTasks($snapshot);
        $tasks = PackingTask::query()
            ->whereIn('id', array_keys($preservedTasks))
            ->orderBy('id')
            ->get();

        if ($tasks->count() !== count($preservedTasks)) {
            $reasons[] = 'Nie znaleziono wszystkich zadań pakowania zatwierdzonych jako stan sprzed podziału.';
        }

        foreach ($tasks as $task) {
            $saved = $preservedTasks[(int) $task->id] ?? [];

            if ((int) $task->external_order_id !== (int) $root->id
                || $task->created_at?->gte($artifactCutoff) === true
                || (string) $task->status === 'shipped'
                || ! hash_equals(
                    (string) ($saved['fingerprint'] ?? ''),
                    HistoricalSplitSnapshot::packingTaskFingerprint($task),
                )) {
                $reasons[] = 'Zadanie pakowania zatwierdzone jako stan sprzed podziału zmieniło się.';
                break;
            }
        }

        $allPreSplitTaskIds = PackingTask::query()
            ->where('external_order_id', $root->id)
            ->where('created_at', '<', $artifactCutoff)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $snapshotTaskIds = collect(array_keys($preservedTasks))->sort()->values()->all();

        if (collect($allPreSplitTaskIds)->sort()->values()->all() !== $snapshotTaskIds) {
            $reasons[] = 'Lista zadań pakowania sprzed podziału zmieniła się.';
        }

        $reversedArtifacts = (array) data_get($snapshot, 'reversed_artifacts', []);
        $reversedTaskFingerprints = $this->artifactFingerprintMap((array) ($reversedArtifacts['packing_tasks'] ?? []));
        $reversedLabels = (array) ($reversedArtifacts['shipping_labels'] ?? []);
        $reversedDocumentFingerprints = $this->artifactFingerprintMap((array) ($reversedArtifacts['warehouse_documents'] ?? []));
        $currentPostSplitTasks = PackingTask::query()
            ->whereIn('external_order_id', $orderIds)
            ->whereNotIn('id', array_keys($preservedTasks))
            ->orderBy('id')
            ->get();
        $currentPostSplitLabels = ShippingLabel::query()
            ->shipments()
            ->whereIn('external_order_id', $orderIds)
            ->where('created_at', '>=', $artifactCutoff)
            ->orderBy('id')
            ->get();
        $currentPostSplitDocuments = $this->wzDocumentsForFamily($family)
            ->filter(fn (WarehouseDocument $document): bool => $document->created_at?->gte($artifactCutoff) === true)
            ->values();

        if (! $this->artifactCollectionMatches(
            $currentPostSplitTasks,
            $reversedTaskFingerprints,
            fn (PackingTask $task): string => HistoricalSplitSnapshot::packingTaskFingerprint($task),
        )) {
            $reasons[] = 'Lista lub stan zadań przeznaczonych do cofnięcia zmieniły się.';
        }

        if (! $this->historicalReversedLabelCollectionMatches(
            $currentPostSplitLabels,
            $reversedLabels,
            $root,
        )) {
            $reasons[] = 'Lista lub stan etykiet przeznaczonych do cofnięcia zmieniły się.';
        }

        if (! $this->artifactCollectionMatches(
            $currentPostSplitDocuments,
            $reversedDocumentFingerprints,
            fn (WarehouseDocument $document): string => HistoricalSplitSnapshot::warehouseDocumentFingerprint($document),
        )) {
            $reasons[] = 'Lista lub stan dokumentów WZ przeznaczonych do cofnięcia zmieniły się.';
        }

        $preservedIdentities = $preservedLabels
            ->flatMap(fn (ShippingLabel $label): array => [
                trim((string) $label->label_number),
                trim((string) $label->tracking_number),
            ])->filter()->unique();
        $reversedIdentities = $currentPostSplitLabels
            ->flatMap(fn (ShippingLabel $label): array => [
                trim((string) $label->label_number),
                trim((string) $label->tracking_number),
            ])->filter()->unique();

        if ($preservedIdentities->intersect($reversedIdentities)->isNotEmpty()) {
            $reasons[] = 'Etykieta zachowywana i etykieta cofana wskazują tę samą przesyłkę. Wymagana jest indywidualna weryfikacja przewoźnika.';
        }

        foreach ((array) data_get($snapshot, 'legacy_adoption.warehouse_verification.expected_balance_deltas', []) as $expectedBalance) {
            if (! is_array($expectedBalance)
                || ! is_numeric($expectedBalance['warehouse_id'] ?? null)
                || ! is_numeric($expectedBalance['product_id'] ?? null)
                || ! is_numeric($expectedBalance['quantity_on_hand_before'] ?? null)) {
                $reasons[] = 'Plan historyczny zawiera niepełną kontrolę stanu magazynowego.';

                continue;
            }

            $balance = StockBalance::query()
                ->where('warehouse_id', (int) $expectedBalance['warehouse_id'])
                ->where('product_id', (int) $expectedBalance['product_id'])
                ->first();

            if (! $balance instanceof StockBalance
                || abs((float) $balance->quantity_on_hand - (float) $expectedBalance['quantity_on_hand_before']) > 0.00001) {
                $reasons[] = 'Stan magazynowy zmienił się od przygotowania historycznego planu. Wymagany jest nowy podgląd.';
                break;
            }
        }

        return array_values(array_unique($reasons));
    }

    /** @param array<string,mixed> $snapshot */
    private function verifyHistoricalPostconditions(
        ExternalOrder $root,
        array $snapshot,
        string $operationUuid,
    ): void {
        if (abs((float) $root->total_gross - (float) ($snapshot['total_gross'] ?? 0)) > 0.009) {
            throw new RuntimeException('Kontrola końcowa wykryła nieprawidłową kwotę po scaleniu.');
        }

        if (! is_numeric(data_get($root->raw_payload, 'total'))
            || abs((float) data_get($root->raw_payload, 'total') - (float) ($snapshot['total_gross'] ?? 0)) > 0.009) {
            throw new RuntimeException('Kontrola końcowa wykryła nieprawidłową kwotę źródłową po scaleniu.');
        }

        $preservedShipmentIdentities = collect((array) data_get(
            $snapshot,
            'preserved_artifacts.shipping_labels',
            [],
        ))->filter(fn (mixed $label): bool => is_array($label))
            ->flatMap(fn (array $label): array => [
                trim((string) ($label['label_number'] ?? '')),
                trim((string) ($label['tracking_number'] ?? '')),
            ])->filter(fn (string $identity): bool => $identity !== '')
            ->unique()->values()->all();
        $unexpectedShipmentIdentities = collect($this->shipmentIdentities((array) $root->raw_payload))
            ->reject(fn (string $identity): bool => in_array($identity, $preservedShipmentIdentities, true));

        if ($unexpectedShipmentIdentities->isNotEmpty()) {
            throw new RuntimeException('Kontrola końcowa wykryła identyfikator anulowanej przesyłki w scalonym zamówieniu.');
        }

        if ((string) $root->fulfillment_status !== (string) ($snapshot['fulfillment_status'] ?? '')) {
            throw new RuntimeException('Kontrola końcowa wykryła nieprawidłowy etap pakowania po scaleniu.');
        }

        $expectedLines = collect((array) ($snapshot['lines'] ?? []))
            ->mapWithKeys(fn (array $line): array => [
                (string) ($line['canonical_external_line_id'] ?? $line['external_line_id'] ?? '') => (string) ($line['quantity'] ?? 0),
            ])->sortKeys()->all();
        $actualLines = $root->lines
            ->mapWithKeys(fn (ExternalOrderLine $line): array => [
                (string) $this->canonicalExternalLineId($line) => (string) $line->quantity,
            ])->sortKeys()->all();

        if (array_keys($expectedLines) !== array_keys($actualLines)
            || collect($expectedLines)->contains(function (mixed $quantity, string $canonical) use ($actualLines): bool {
                return abs((float) $quantity - (float) ($actualLines[$canonical] ?? 0)) > 0.00001;
            })) {
            throw new RuntimeException('Kontrola końcowa wykryła nieprawidłowe pozycje po scaleniu.');
        }

        foreach (HistoricalSplitSnapshot::preservedPackingTasks($snapshot) as $taskId => $saved) {
            $task = PackingTask::query()->find($taskId);
            $canonical = (string) ($saved['canonical_external_line_id'] ?? '');

            if (! $task instanceof PackingTask) {
                throw new RuntimeException("Kontrola końcowa nie znalazła zachowywanego zadania pakowania #{$taskId}.");
            }

            $taskProblems = array_keys(array_filter([
                'zamówienie' => (int) $task->external_order_id !== (int) $root->id,
                'status' => (string) $task->status !== (string) ($saved['status'] ?? ''),
                'ilość wymagana' => abs((float) $task->quantity_required - (float) ($saved['quantity_required'] ?? 0)) > 0.00001,
                'ilość zebrana' => abs((float) $task->quantity_picked - (float) ($saved['quantity_picked'] ?? 0)) > 0.00001,
                'czas zebrania' => $task->picked_at?->toISOString() !== ($saved['picked_at'] ?? null),
                'czas pakowania' => $task->packed_at?->toISOString() !== ($saved['packed_at'] ?? null),
                'powiązanie pozycji' => ! $task->orderLine instanceof ExternalOrderLine
                    || (string) $this->canonicalExternalLineId($task->orderLine) !== $canonical,
            ]));

            if ($taskProblems !== []) {
                throw new RuntimeException(
                    "Kontrola końcowa wykryła nieprawidłowe odtworzenie zadania pakowania #{$taskId}: "
                    .implode(', ', $taskProblems).'.',
                );
            }
        }

        $reversedTasks = $this->artifactFingerprintMap((array) data_get(
            $snapshot,
            'reversed_artifacts.packing_tasks',
            [],
        ));

        foreach (array_keys($reversedTasks) as $taskId) {
            $task = PackingTask::query()->find($taskId);

            if (! $task instanceof PackingTask
                || (string) $task->status !== 'cancelled'
                || abs((float) $task->quantity_picked) > 0.00001
                || $task->picked_at !== null
                || $task->packed_at !== null
                || (int) data_get($task->metadata, 'split_reversal.root_order_id', 0) !== (int) $root->id) {
                throw new RuntimeException("Kontrola końcowa wykryła niepełne cofnięcie zadania pakowania #{$taskId}.");
            }
        }

        foreach (HistoricalSplitSnapshot::preservedWarehouseDocumentFingerprints($snapshot) as $documentId => $fingerprint) {
            $document = WarehouseDocument::query()->with(['lines', 'ledgerEntries'])->find($documentId);

            if (! $document instanceof WarehouseDocument
                || (string) $document->status !== 'posted'
                || ! hash_equals($fingerprint, HistoricalSplitSnapshot::warehouseDocumentFingerprint($document))) {
                throw new RuntimeException("Kontrola końcowa wykryła zmianę zachowywanego dokumentu WZ #{$documentId}.");
            }
        }

        foreach (HistoricalSplitSnapshot::preservedLabelFingerprints($snapshot) as $labelId => $fingerprint) {
            $label = ShippingLabel::query()->find($labelId);

            if (! $label instanceof ShippingLabel
                || (string) $label->status === 'cancelled'
                || ! hash_equals($fingerprint, HistoricalSplitSnapshot::shippingLabelFingerprint($label))) {
                throw new RuntimeException("Kontrola końcowa wykryła zmianę zachowywanej etykiety #{$labelId}.");
            }
        }

        $reversedLabels = $this->artifactFingerprintMap((array) data_get(
            $snapshot,
            'reversed_artifacts.shipping_labels',
            [],
        ));

        foreach (array_keys($reversedLabels) as $labelId) {
            $label = ShippingLabel::query()->find($labelId);

            if (! $label instanceof ShippingLabel
                || (string) $label->status !== 'cancelled'
                || ! hash_equals(
                    $operationUuid,
                    (string) data_get($label->response_payload, 'split_reversal.operation_uuid', ''),
                )
                || ! str_starts_with(
                    (string) $label->idempotency_key,
                    'split-reverted:'.$operationUuid.':',
                )) {
                throw new RuntimeException("Kontrola końcowa wykryła niepełne zarchiwizowanie etykiety #{$labelId}.");
            }
        }

        $reversedDocuments = $this->artifactFingerprintMap((array) data_get(
            $snapshot,
            'reversed_artifacts.warehouse_documents',
            [],
        ));

        foreach (array_keys($reversedDocuments) as $documentId) {
            $document = WarehouseDocument::withTrashed()->with('ledgerEntries')->find($documentId);

            if (! $document instanceof WarehouseDocument
                || (string) $document->status !== 'cancelled'
                || $document->deleted_at === null
                || ! $this->cancelledWarehouseDocumentHasCompleteLedgerPair($document, $document->ledgerEntries)) {
                throw new RuntimeException("Kontrola końcowa wykryła niepełne odwrócenie dokumentu WZ #{$documentId}.");
            }
        }

        foreach ((array) data_get($snapshot, 'legacy_adoption.warehouse_verification.expected_balance_deltas', []) as $expectedBalance) {
            $balance = is_array($expectedBalance)
                ? StockBalance::query()
                    ->where('warehouse_id', (int) ($expectedBalance['warehouse_id'] ?? 0))
                    ->where('product_id', (int) ($expectedBalance['product_id'] ?? 0))
                    ->first()
                : null;

            if (! $balance instanceof StockBalance
                || ! is_numeric($expectedBalance['quantity_on_hand_after'] ?? null)
                || abs((float) $balance->quantity_on_hand - (float) $expectedBalance['quantity_on_hand_after']) > 0.00001) {
                throw new RuntimeException('Kontrola końcowa wykryła inną niż zatwierdzona zmianę stanu magazynowego.');
            }
        }

        $expectedChildIds = collect((array) data_get($snapshot, 'legacy_adoption.family_order_ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === (int) $root->id)
            ->sort()->values()->all();
        $archivedChildren = ExternalOrder::withTrashed()
            ->whereIn('id', $expectedChildIds)
            ->orderBy('id')
            ->get();

        if ($archivedChildren->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all() !== $expectedChildIds
            || $archivedChildren->contains(fn (ExternalOrder $child): bool => $child->deleted_at === null
                || (string) $child->status !== 'split-reverted'
                || (int) data_get($child->raw_payload, 'sempre_erp_split_reversal.root_order_id', 0) !== (int) $root->id)) {
            throw new RuntimeException('Kontrola końcowa wykryła niepełne zarchiwizowanie części zamówienia.');
        }

        if (HistoricalSplitSnapshot::preservedWarehouseDocumentFingerprints($snapshot) !== []
            && StockReservation::query()
                ->where('sales_channel_id', $root->sales_channel_id)
                ->where('external_order_id', $root->external_id)
                ->whereIn('status', ['active', 'waiting'])
                ->exists()) {
            throw new RuntimeException('Kontrola końcowa wykryła rezerwację mimo zachowanego zaksięgowanego WZ.');
        }
    }

    /** @param list<array<string,mixed>> $items @return array<int,string> */
    private function artifactFingerprintMap(array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => is_array($item)
                && (int) ($item['id'] ?? 0) > 0
                && filled($item['fingerprint'] ?? null))
            ->mapWithKeys(fn (array $item): array => [
                (int) $item['id'] => (string) $item['fingerprint'],
            ])->all();
    }

    private function historicalTaskDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->setTimezone((string) config('app.timezone'));
        }

        if (blank($value)) {
            return null;
        }

        return CarbonImmutable::parse((string) $value)
            ->setTimezone((string) config('app.timezone'));
    }

    /** @param Collection<int,mixed> $artifacts @param array<int,string> $expected */
    private function artifactCollectionMatches(
        Collection $artifacts,
        array $expected,
        callable $fingerprint,
    ): bool {
        $actualIds = $artifacts->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $expectedIds = collect(array_keys($expected))->sort()->values()->all();

        if ($actualIds !== $expectedIds) {
            return false;
        }

        return $artifacts->every(fn (mixed $artifact): bool => hash_equals(
            (string) ($expected[(int) $artifact->id] ?? ''),
            (string) $fingerprint($artifact),
        ));
    }

    /** @param Collection<int,ShippingLabel> $labels @param list<array<string,mixed>> $expected */
    private function historicalReversedLabelCollectionMatches(
        Collection $labels,
        array $expected,
        ExternalOrder $root,
    ): bool {
        $expectedById = collect($expected)
            ->filter(fn (mixed $item): bool => is_array($item)
                && (int) ($item['id'] ?? 0) > 0
                && filled($item['fingerprint'] ?? null)
                && filled($item['cancelled_fingerprint'] ?? null))
            ->keyBy(fn (array $item): int => (int) $item['id']);
        $actualIds = $labels->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $expectedIds = $expectedById->keys()->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        if ($actualIds !== $expectedIds) {
            return false;
        }

        $operationUuid = trim((string) data_get(
            $root->raw_payload,
            'sempre_erp_split_reversal_operation.uuid',
            '',
        ));

        return $labels->every(function (ShippingLabel $label) use ($expectedById, $operationUuid): bool {
            $saved = $expectedById->get((int) $label->id);

            if (! is_array($saved)) {
                return false;
            }

            $currentFingerprint = HistoricalSplitSnapshot::shippingLabelFingerprint($label);

            if (hash_equals((string) $saved['fingerprint'], $currentFingerprint)) {
                return true;
            }

            return $operationUuid !== ''
                && (string) $label->status === 'cancelled'
                && hash_equals((string) $saved['cancelled_fingerprint'], $currentFingerprint)
                && hash_equals(
                    $operationUuid,
                    (string) data_get($label->response_payload, 'cancellation.operation_uuid', ''),
                );
        });
    }

    /** @param array<string,mixed>|null $snapshot */
    private function isPreservedHistoricalLabel(ShippingLabel $label, ?array $snapshot): bool
    {
        if (! HistoricalSplitSnapshot::isVerified($snapshot)) {
            return false;
        }

        $fingerprints = HistoricalSplitSnapshot::preservedLabelFingerprints($snapshot);

        return isset($fingerprints[(int) $label->id])
            && hash_equals(
                (string) $fingerprints[(int) $label->id],
                HistoricalSplitSnapshot::shippingLabelFingerprint($label),
            );
    }

    /** @param array<string,mixed>|null $snapshot */
    private function isPreservedHistoricalWarehouseDocument(
        WarehouseDocument $document,
        ?array $snapshot,
    ): bool {
        if (! HistoricalSplitSnapshot::isVerified($snapshot)) {
            return false;
        }

        $fingerprints = HistoricalSplitSnapshot::preservedWarehouseDocumentFingerprints($snapshot);

        return isset($fingerprints[(int) $document->id])
            && hash_equals(
                (string) $fingerprints[(int) $document->id],
                HistoricalSplitSnapshot::warehouseDocumentFingerprint($document),
            );
    }

    /**
     * @param  array<string,mixed>|null  $snapshot
     * @param  EloquentCollection<int,ShippingLabel>  $olderActiveLabels
     * @return list<string>
     */
    private function preservedShipmentIdentities(
        ?array $snapshot,
        EloquentCollection $olderActiveLabels,
    ): array {
        if (! HistoricalSplitSnapshot::isVerified($snapshot)) {
            return [];
        }

        return $olderActiveLabels
            ->filter(fn (ShippingLabel $label): bool => $this->isPreservedHistoricalLabel($label, $snapshot))
            ->flatMap(fn (ShippingLabel $label): array => [
                trim((string) $label->label_number),
                trim((string) $label->tracking_number),
            ])
            ->filter(fn (string $identity): bool => $identity !== '')
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<int> $orderIds */
    private function legacyPackingProblemPredatesSplit(
        array $orderIds,
        CarbonInterface $artifactCutoff,
    ): bool {
        return PackingTask::query()
            ->whereIn('external_order_id', $orderIds)
            ->get()
            ->contains(function (PackingTask $task) use ($artifactCutoff): bool {
                $problem = data_get($task->metadata, 'packing_problem');

                if (! is_array($problem) && (string) $task->status !== 'problem') {
                    return false;
                }

                $reportedAt = null;

                try {
                    if (is_array($problem) && filled($problem['reported_at'] ?? null)) {
                        $reportedAt = CarbonImmutable::parse((string) $problem['reported_at']);
                    }
                } catch (Throwable) {
                    // An invalid legacy timestamp cannot prove that the problem
                    // happened after the split, so fall back to task creation.
                }

                return ($reportedAt ?? $task->created_at)?->lt($artifactCutoff) === true;
            });
    }

    /** @param list<int> $orderIds */
    private function legacyPackingCancellationPredatesSplit(
        array $orderIds,
        CarbonInterface $artifactCutoff,
    ): bool {
        return PackingTask::query()
            ->whereIn('external_order_id', $orderIds)
            ->where('status', 'cancelled')
            ->get()
            ->contains(function (PackingTask $task) use ($artifactCutoff): bool {
                $cancelledAt = collect([
                    data_get($task->metadata, 'packing_sync.cancelled_at'),
                    data_get($task->metadata, 'order_cancellation.cancelled_at'),
                    data_get($task->metadata, 'packing_problem.cancelled_at'),
                ])->first(fn (mixed $value): bool => filled($value));
                $eventAt = null;

                try {
                    if (filled($cancelledAt)) {
                        $eventAt = CarbonImmutable::parse((string) $cancelledAt);
                    }
                } catch (Throwable) {
                    // Missing or corrupt provenance cannot prove that this
                    // cancellation happened after the historical split.
                }

                return ($eventAt ?? $task->created_at)?->lt($artifactCutoff) === true;
            });
    }

    /** @param EloquentCollection<int,WarehouseDocument> $documents */
    private function legacyWarehouseDocumentLacksSourceBaseline(
        EloquentCollection $documents,
        CarbonInterface $artifactCutoff,
    ): bool {
        foreach ($documents as $document) {
            if ($document->created_at?->lt($artifactCutoff)
                || ! in_array((string) $document->status, ['posted', 'cancelled'], true)) {
                continue;
            }

            $entries = StockLedgerEntry::query()
                ->where('warehouse_document_id', $document->id)
                ->orderBy('id')
                ->get();
            $originalEntries = $entries->reject(
                fn (StockLedgerEntry $entry): bool => data_get(
                    $entry->metadata,
                    'source',
                ) === 'warehouse_document_cancelled',
            );

            if ($document->status === 'cancelled') {
                if (! $this->cancelledWarehouseDocumentHasCompleteLedgerPair($document, $entries)) {
                    return true;
                }

                continue;
            }

            if ($originalEntries->isEmpty()
                || $originalEntries->contains(fn (StockLedgerEntry $entry): bool => ! is_array(
                    data_get($entry->metadata, 'source_balance_before_movement'),
                ))) {
                return true;
            }
        }

        return false;
    }

    /** @param EloquentCollection<int,StockLedgerEntry> $entries */
    private function cancelledWarehouseDocumentHasCompleteLedgerPair(
        WarehouseDocument $document,
        EloquentCollection $entries,
    ): bool {
        if ($entries->isEmpty()) {
            // Only a draft WZ can be cancelled without moving stock. A posted
            // timestamp with no ledger is inconsistent and must fail closed.
            return $document->posted_at === null;
        }

        $movementKey = fn (StockLedgerEntry $entry): string => implode(':', [
            (int) $entry->warehouse_document_line_id,
            (int) $entry->warehouse_id,
            (int) $entry->product_id,
        ]);
        $original = $entries
            ->reject(fn (StockLedgerEntry $entry): bool => data_get(
                $entry->metadata,
                'source',
            ) === 'warehouse_document_cancelled')
            ->groupBy($movementKey)
            ->map(fn (Collection $group): float => (float) $group->sum('quantity_change'));
        $cancellations = $entries
            ->filter(fn (StockLedgerEntry $entry): bool => data_get(
                $entry->metadata,
                'source',
            ) === 'warehouse_document_cancelled')
            ->groupBy($movementKey)
            ->map(fn (Collection $group): float => (float) $group->sum('quantity_change'));

        if ($original->isEmpty() || $cancellations->isEmpty()
            || $original->keys()->sort()->values()->all() !== $cancellations->keys()->sort()->values()->all()) {
            return false;
        }

        return $original->every(fn (float $quantity, string $key): bool => abs(
            $quantity + (float) $cancellations->get($key, 0),
        ) <= 0.00001);
    }

    /**
     * A corrupt split_root_order_id must never be enough to merge or archive an
     * order. The immutable lineage written into each child is verified against
     * the physical parent chain and the sales-channel boundary before any
     * external or local side effect starts.
     *
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @return list<string>
     */
    private function familyIntegrityBlockers(ExternalOrder $requestedRoot, EloquentCollection $family): array
    {
        $declaredRootId = (int) ($requestedRoot->split_root_order_id ?: $requestedRoot->id);
        $root = $family->firstWhere('id', $declaredRootId);

        if (! $root instanceof ExternalOrder || $root->split_root_order_id !== null) {
            return ['Powązania rodziny podziału są niespójne: nie znaleziono prawidłowego zamówienia głównego. Cofnięcie zablokowano, aby nie zmienić innego zamówienia.'];
        }

        if (ExternalOrder::query()
            ->where('split_root_order_id', $root->id)
            ->where('sales_channel_id', '!=', $root->sales_channel_id)
            ->exists()) {
            return ['Powązania rodziny podziału wskazują zamówienie z innego kanału sprzedaży. Wymagana jest ręczna korekta danych.'];
        }

        $byId = $family->keyBy('id');

        foreach ($family as $member) {
            if ((int) $member->sales_channel_id !== (int) $root->sales_channel_id) {
                return ['Części rodziny należą do różnych kanałów sprzedaży. Cofnięcie zablokowano.'];
            }

            if ((int) $member->id === (int) $root->id) {
                if ($member->split_parent_order_id !== null) {
                    return ['Zamówienie główne ma nieprawidłowe wskazanie rodzica podziału. Wymagana jest ręczna korekta danych.'];
                }

                continue;
            }

            if ((int) $member->split_root_order_id !== (int) $root->id) {
                return ['Jedna z części wskazuje inne zamówienie główne. Cofnięcie zablokowano.'];
            }

            $parent = $byId->get((int) $member->split_parent_order_id);
            $lineage = data_get($member->raw_payload, 'sempre_erp_split');

            if (! $parent instanceof ExternalOrder || ! is_array($lineage)
                || (int) ($lineage['parent_order_id'] ?? 0) !== (int) $parent->id
                || (string) ($lineage['parent_external_id'] ?? '') !== (string) $parent->external_id
                || (int) ($lineage['root_order_id'] ?? 0) !== (int) $root->id
                || (string) ($lineage['root_external_id'] ?? '') !== (string) $root->external_id) {
                return ['Nie można jednoznacznie potwierdzić pochodzenia jednej z części zamówienia. Cofnięcie zablokowano, aby nie objąć innego zamówienia.'];
            }

            if (($root->customer_id !== null && $member->customer_id !== null
                    && (int) $root->customer_id !== (int) $member->customer_id)
                || ($root->wordpress_integration_id !== null && $member->wordpress_integration_id !== null
                    && (int) $root->wordpress_integration_id !== (int) $member->wordpress_integration_id)) {
                return ['Jedna z części ma inną tożsamość klienta lub integracji. Wymagana jest ręczna weryfikacja rodziny.'];
            }

            $visited = [];
            $cursor = $member;

            while ((int) $cursor->id !== (int) $root->id) {
                if (isset($visited[$cursor->id])) {
                    return ['Powązania rodziny podziału zawierają cykl. Cofnięcie zablokowano.'];
                }

                $visited[$cursor->id] = true;
                $cursor = $byId->get((int) $cursor->split_parent_order_id);

                if (! $cursor instanceof ExternalOrder) {
                    return ['Łańcuch rodziców jednej z części jest niekompletny. Cofnięcie zablokowano.'];
                }
            }
        }

        return [];
    }

    /** @param EloquentCollection<int, ExternalOrderLine> $lines @param array<string,mixed> $baselinePayload */
    private function lineageProblem(EloquentCollection $lines, array $baselinePayload): ?string
    {
        $groups = [];

        foreach ($lines as $line) {
            $canonical = $this->canonicalExternalLineId($line);

            if ($canonical === null) {
                return 'Nie można jednoznacznie odtworzyć pozycji bez kanonicznego identyfikatora. Wymagana jest ręczna weryfikacja.';
            }

            $signature = json_encode([
                $line->product_id,
                trim((string) $line->sku),
                (string) $line->unit_net_price,
                (string) $line->unit_gross_price,
                (string) $line->vat_rate,
            ], JSON_THROW_ON_ERROR);
            $groups[$canonical]['signatures'][$signature] = true;
            $groups[$canonical]['representative'] ??= $line;
            $groups[$canonical]['quantity'] = (float) ($groups[$canonical]['quantity'] ?? 0)
                + (float) $line->quantity;
            $sourceQuantity = data_get($line->raw_payload, 'sempre_erp_split.source_quantity');

            if (is_numeric($sourceQuantity) && (float) $sourceQuantity > 0) {
                $groups[$canonical]['source_quantities'][] = (float) $sourceQuantity;
            }
        }

        foreach ($groups as $group) {
            if (count((array) ($group['signatures'] ?? [])) > 1) {
                return 'Ta sama pozycja kanoniczna ma w częściach różne produkty, ceny albo stawkę VAT. Wymagana jest ręczna weryfikacja.';
            }

            $sourceQuantities = (array) ($group['source_quantities'] ?? []);

            if ($sourceQuantities !== []
                && abs((float) $group['quantity'] - max($sourceQuantities)) > 0.00001) {
                return 'Ilość jednej z historycznych pozycji zmieniła się po podziale i nie można jej jednoznacznie odtworzyć.';
            }
        }

        $expectedQuantities = [];
        $expectedItems = [];

        foreach ((array) data_get($baselinePayload, 'line_items', []) as $rawLine) {
            if (! is_array($rawLine) || ! is_scalar($rawLine['id'] ?? null)
                || ! is_numeric($rawLine['quantity'] ?? null)) {
                return 'Historyczny zapis pozycji WooCommerce jest niepełny. Wymagana jest ręczna weryfikacja.';
            }

            $canonical = trim((string) $rawLine['id']);

            do {
                $previous = $canonical;
                $canonical = (string) preg_replace('/-S\d+$/', '', $canonical);
            } while ($canonical !== $previous);

            if ($canonical === '') {
                return 'Historyczny zapis pozycji WooCommerce nie ma identyfikatora kanonicznego.';
            }

            if (isset($expectedItems[$canonical])) {
                return 'Historyczny zapis WooCommerce zawiera niejednoznaczny identyfikator pozycji '.$canonical.'.';
            }

            $expectedQuantities[$canonical] = (float) ($expectedQuantities[$canonical] ?? 0)
                + (float) $rawLine['quantity'];
            $expectedItems[$canonical] = $rawLine;
        }

        $actualQuantities = collect($groups)
            ->map(fn (array $group): float => (float) ($group['quantity'] ?? 0))
            ->all();
        ksort($expectedQuantities, SORT_NATURAL);
        ksort($actualQuantities, SORT_NATURAL);

        if (array_keys($expectedQuantities) !== array_keys($actualQuantities)) {
            return 'Zestaw historycznych pozycji nie zgadza się z pierwotnym zapisem WooCommerce.';
        }

        foreach ($expectedQuantities as $canonical => $expectedQuantity) {
            if (abs($expectedQuantity - (float) ($actualQuantities[$canonical] ?? 0)) > 0.00001) {
                return 'Ilość historycznej pozycji '.$canonical.' nie zgadza się z pierwotnym zapisem WooCommerce.';
            }

            $representative = $groups[$canonical]['representative'] ?? null;
            $rawLine = $expectedItems[$canonical] ?? null;

            if (! $representative instanceof ExternalOrderLine || ! is_array($rawLine)) {
                return 'Nie można porównać historycznej pozycji '.$canonical.' ze stanem rodziny.';
            }

            $expectedSku = trim((string) ($rawLine['sku'] ?? ''));
            $expectedName = trim((string) ($rawLine['name'] ?? ''));

            if (($expectedSku !== '' && $expectedSku !== trim((string) $representative->sku))
                || ($expectedName !== '' && $expectedName !== trim((string) $representative->name))) {
                return 'SKU albo nazwa historycznej pozycji '.$canonical.' zmieniły się po podziale.';
            }

            foreach (['product_id', 'variation_id'] as $externalProductKey) {
                if (! is_scalar($rawLine[$externalProductKey] ?? null)) {
                    continue;
                }

                $currentExternalProductId = data_get($representative->raw_payload, $externalProductKey);

                if (! is_scalar($currentExternalProductId)
                    || (string) $currentExternalProductId !== (string) $rawLine[$externalProductKey]) {
                    return 'Produkt źródłowy historycznej pozycji '.$canonical.' zmienił się po podziale.';
                }
            }

            if (! is_numeric($rawLine['subtotal'] ?? null)
                || ! is_numeric($rawLine['total'] ?? null)
                || $expectedQuantity <= 0
                || $representative->unit_net_price === null
                || $representative->unit_gross_price === null) {
                return 'Historyczna pozycja '.$canonical.' nie ma pełnych danych cenowych potrzebnych do bezpiecznego odtworzenia.';
            }

            $expectedUnitNet = (float) $rawLine['subtotal'] / $expectedQuantity;
            $expectedUnitGross = (float) $rawLine['total'] / $expectedQuantity;

            if (abs($expectedUnitNet - (float) $representative->unit_net_price) > 0.0001
                || abs($expectedUnitGross - (float) $representative->unit_gross_price) > 0.0001) {
                return 'Cena historycznej pozycji '.$canonical.' zmieniła się po podziale.';
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function originalSnapshot(ExternalOrder $root): ?array
    {
        $snapshot = data_get($root->raw_payload, 'sempre_erp_split_original');

        if (! is_array($snapshot) || ! is_array($snapshot['lines'] ?? null)) {
            return null;
        }

        if (in_array((int) ($snapshot['version'] ?? 0), [1, 2, 3, 4], true)) {
            return $snapshot;
        }

        return HistoricalSplitSnapshot::isVerified($snapshot) ? $snapshot : null;
    }

    /** @param EloquentCollection<int,ExternalOrder> $family */
    private function legacyBaselineOrder(
        ExternalOrder $root,
        EloquentCollection $family,
    ): ?ExternalOrder {
        $earliestDirectChild = $family
            ->filter(fn (ExternalOrder $member): bool => (int) $member->split_parent_order_id === (int) $root->id)
            ->sortBy(fn (ExternalOrder $member): string => sprintf(
                '%s:%020d',
                $member->created_at?->format('Y-m-d H:i:s.u') ?? '',
                (int) $member->id,
            ))
            ->first();

        return $earliestDirectChild instanceof ExternalOrder
            && $this->completeLegacyPayload($root, (array) $earliestDirectChild->raw_payload)
                ? $earliestDirectChild
                : null;
    }

    /** @param EloquentCollection<int,ExternalOrder> $family @return array<string,mixed>|null */
    private function legacyBaselinePayload(
        ExternalOrder $root,
        EloquentCollection $family,
    ): ?array {
        $baselineOrder = $this->legacyBaselineOrder($root, $family);

        if ($baselineOrder instanceof ExternalOrder) {
            return (array) $baselineOrder->raw_payload;
        }

        $rootPayload = (array) $root->raw_payload;

        return blank($rootPayload['sempre_erp_split_import_adjusted_at'] ?? null)
            && $this->completeLegacyPayload($root, $rootPayload)
                ? $rootPayload
                : null;
    }

    /** @param array<string,mixed> $payload */
    private function completeLegacyPayload(ExternalOrder $root, array $payload): bool
    {
        $payloadId = trim((string) ($payload['id'] ?? ''));
        $payloadNumber = trim((string) ($payload['number'] ?? ''));
        $identityMatches = $payloadId !== ''
            ? $payloadId === trim((string) $root->external_id)
            : ($payloadNumber !== '' && $payloadNumber === trim((string) $root->external_number));
        $payloadCurrency = strtoupper(trim((string) ($payload['currency'] ?? '')));

        return is_numeric($payload['total'] ?? null)
            && filled($payload['status'] ?? null)
            && is_array($payload['line_items'] ?? null)
            && $identityMatches
            && ($payloadCurrency === '' || $payloadCurrency === strtoupper((string) $root->currency));
    }

    /** @param array<string,mixed> $payload */
    private function commerceFingerprint(array $payload): string
    {
        $commercialKeys = [
            'id',
            'parent_id',
            'number',
            'currency',
            'version',
            'prices_include_tax',
            'discount_total',
            'discount_tax',
            'shipping_total',
            'shipping_tax',
            'cart_tax',
            'total',
            'total_tax',
            'customer_id',
            'billing',
            'shipping',
            'payment_method',
            'payment_method_title',
            'transaction_id',
            'customer_note',
            'date_paid',
            'date_paid_gmt',
            'sempre_erp_target_point',
            'meta_data',
            'line_items',
            'tax_lines',
            'shipping_lines',
            'fee_lines',
            'coupon_lines',
            'refunds',
        ];
        $commercial = [];

        foreach ($commercialKeys as $key) {
            if (array_key_exists($key, $payload)) {
                $commercial[$key] = $this->canonicalCommerceValue($payload[$key]);
            }
        }

        // The selected parcel locker is commercial fulfilment data: restoring
        // an older snapshot after the customer changed it would send the parcel
        // to the wrong point. Top-level Woo meta also contains shipment IDs and
        // ERP bookkeeping, so fingerprint only the keys consumed as pickup
        // points and deliberately exclude those technical identities.
        $commercial['pickup_point_meta'] = collect((array) ($payload['meta_data'] ?? []))
            ->filter(fn (mixed $meta): bool => is_array($meta))
            ->filter(function (array $meta): bool {
                $key = mb_strtolower(trim((string) ($meta['key'] ?? '')));

                return $key !== ''
                    && ! str_starts_with($key, 'sempre_erp_')
                    && ! $this->isShipmentIdentityKey($key)
                    && $this->isPickupPointMetaKey($key);
            })
            ->map(fn (array $meta): array => [
                'key' => mb_strtolower(trim((string) $meta['key'])),
                'value' => $this->canonicalCommerceValue($meta['value'] ?? null),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode(
            $this->canonicalCommerceValue($commercial),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function canonicalCommerceValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            foreach (array_keys($value) as $key) {
                if (str_starts_with((string) $key, 'sempre_erp_')
                    || $this->isShipmentIdentityKey((string) $key)) {
                    unset($value[$key]);
                }
            }

            ksort($value, SORT_STRING);

            return array_map(fn (mixed $item): mixed => $this->canonicalCommerceValue($item), $value);
        }

        $items = collect($value)
            ->reject(fn (mixed $item): bool => is_array($item)
                && str_starts_with((string) ($item['key'] ?? ''), 'sempre_erp_'))
            ->reject(fn (mixed $item): bool => is_array($item)
                && $this->isShipmentIdentityKey((string) ($item['key'] ?? '')))
            ->map(fn (mixed $item): mixed => $this->canonicalCommerceValue($item))
            ->values()
            ->all();

        if (collect($items)->every(fn (mixed $item): bool => is_array($item))) {
            usort($items, fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                json_encode($right, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            ));
        }

        return $items;
    }

    private function isPickupPointMetaKey(string $key): bool
    {
        foreach (['paczkomat', 'target_point', 'parcel_machine', 'locker', 'easypack'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,mixed>> */
    private function linesFromSnapshot(array $snapshot): array
    {
        return collect((array) $snapshot['lines'])
            ->filter(fn (mixed $line): bool => is_array($line) && (float) ($line['quantity'] ?? 0) > 0)
            ->map(fn (array $line): array => [
                'product_id' => $line['product_id'] ?? null,
                'external_line_id' => $line['external_line_id'] ?? null,
                'canonical_external_line_id' => $line['canonical_external_line_id'] ?? null,
                'sku' => $line['sku'] ?? null,
                'name' => (string) ($line['name'] ?? 'Pozycja zamówienia'),
                'quantity' => (float) $line['quantity'],
                'unit_net_price' => isset($line['unit_net_price']) ? (float) $line['unit_net_price'] : null,
                'unit_gross_price' => isset($line['unit_gross_price']) ? (float) $line['unit_gross_price'] : null,
                'vat_rate' => isset($line['vat_rate']) ? (float) $line['vat_rate'] : null,
                'raw_payload' => is_array($line['raw_payload'] ?? null) ? $line['raw_payload'] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, ExternalOrderLine>  $lines
     * @return list<array<string,mixed>>
     */
    private function mergeCurrentLines(ExternalOrder $root, EloquentCollection $lines): array
    {
        return $lines
            ->groupBy(fn (ExternalOrderLine $line): string => (string) $this->canonicalExternalLineId($line))
            ->map(function (Collection $group, string $canonical) use ($root): array {
                /** @var ExternalOrderLine $representative */
                $representative = $group->firstWhere('external_order_id', $root->id) ?? $group->first();
                $raw = (array) $representative->raw_payload;
                unset(
                    $raw['sempre_erp_split'],
                    $raw['sempre_erp_source_quantity'],
                    $raw['sempre_erp_split_quantity'],
                );

                if (isset($raw['id'])) {
                    $raw['id'] = $canonical;
                }
                $raw['quantity'] = (float) $group->sum(fn (ExternalOrderLine $line): float => (float) $line->quantity);

                return [
                    'product_id' => $representative->product_id,
                    'external_line_id' => $canonical,
                    'canonical_external_line_id' => $canonical,
                    'sku' => $representative->sku,
                    'name' => $representative->name,
                    'quantity' => $raw['quantity'],
                    'unit_net_price' => $representative->unit_net_price,
                    'unit_gross_price' => $representative->unit_gross_price,
                    'vat_rate' => $representative->vat_rate,
                    'raw_payload' => $raw,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string,mixed>|null $snapshot */
    private function restoredTotal(ExternalOrder $root, EloquentCollection $family, ?array $snapshot): float
    {
        $snapshotTotal = $snapshot['total_gross'] ?? null;

        if (is_numeric($snapshotTotal)) {
            return round((float) $snapshotTotal, 2);
        }

        $remoteTotal = data_get($this->legacyBaselinePayload($root, $family) ?? [], 'total');

        if (is_numeric($remoteTotal)) {
            return round((float) $remoteTotal, 2);
        }

        return round((float) $family->sum(fn (ExternalOrder $member): float => (float) $member->total_gross), 2);
    }

    /**
     * @param  array<string,mixed>|null  $snapshot
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @return array<string,mixed>
     */
    private function restoredRawPayload(
        ExternalOrder $root,
        ?array $snapshot,
        EloquentCollection $family,
    ): array {
        $snapshotRaw = $snapshot['raw_payload'] ?? null;
        $legacyRaw = $snapshot === null ? $this->legacyBaselinePayload($root, $family) : null;
        $raw = is_array($snapshotRaw)
            ? $snapshotRaw
            : (is_array($legacyRaw) ? $legacyRaw : (array) $root->raw_payload);
        unset(
            $raw['sempre_erp_split'],
            $raw['sempre_erp_split_original'],
            $raw['sempre_erp_split_child_orders'],
            $raw['sempre_erp_split_allocations'],
            $raw['sempre_erp_split_import_adjusted_at'],
            $raw['sempre_erp_split_reversal_operation'],
        );

        if (is_array($snapshotRaw)) {
            if (HistoricalSplitSnapshot::isVerified($snapshot)) {
                $preservedIdentities = collect((array) data_get(
                    $snapshot,
                    'preserved_artifacts.shipping_labels',
                    [],
                ))->filter(fn (mixed $label): bool => is_array($label))
                    ->flatMap(fn (array $label): array => [
                        trim((string) ($label['label_number'] ?? '')),
                        trim((string) ($label['tracking_number'] ?? '')),
                    ])->filter(fn (string $identity): bool => $identity !== '')
                    ->unique()->values()->all();
                $raw = $this->withOnlyPreservedShipmentIdentities($raw, $preservedIdentities);
            }

            return $raw;
        }

        foreach (array_keys($raw) as $key) {
            if ($this->isShipmentIdentityKey((string) $key)) {
                unset($raw[$key]);
            }
        }

        $raw['meta_data'] = collect((array) ($raw['meta_data'] ?? []))
            ->reject(fn (mixed $meta): bool => is_array($meta)
                && $this->isShipmentIdentityKey((string) ($meta['key'] ?? '')))
            ->values()
            ->all();

        foreach ((array) ($raw['shipping_lines'] ?? []) as $index => $shippingLine) {
            if (! is_array($shippingLine)) {
                continue;
            }

            $shippingLine['meta_data'] = collect((array) ($shippingLine['meta_data'] ?? []))
                ->reject(fn (mixed $meta): bool => is_array($meta)
                    && $this->isShipmentIdentityKey((string) ($meta['key'] ?? '')))
                ->values()
                ->all();
            $raw['shipping_lines'][$index] = $shippingLine;
        }

        unset($raw['sempre_erp_status_sync']);

        if ($snapshot !== null && ($snapshot['shipping_decision_exists'] ?? false) === true) {
            $raw['sempre_erp_shipping_decision'] = $snapshot['shipping_decision'] ?? null;
        } elseif ($snapshot !== null) {
            unset($raw['sempre_erp_shipping_decision']);
        }

        return $raw;
    }

    /** @param array<string,mixed> $raw @param list<string> $preservedIdentities @return array<string,mixed> */
    private function withOnlyPreservedShipmentIdentities(
        array $raw,
        array $preservedIdentities,
    ): array {
        foreach (array_keys($raw) as $key) {
            if (! $this->isShipmentIdentityKey((string) $key)) {
                continue;
            }

            $value = $raw[$key] ?? null;

            if (! is_scalar($value)
                || ! in_array(trim((string) $value), $preservedIdentities, true)) {
                unset($raw[$key]);
            }
        }

        $raw['meta_data'] = collect((array) ($raw['meta_data'] ?? []))
            ->reject(function (mixed $meta) use ($preservedIdentities): bool {
                if (! is_array($meta)
                    || ! $this->isShipmentIdentityKey((string) ($meta['key'] ?? ''))) {
                    return false;
                }

                return ! is_scalar($meta['value'] ?? null)
                    || ! in_array(trim((string) $meta['value']), $preservedIdentities, true);
            })->values()->all();

        foreach ((array) ($raw['shipping_lines'] ?? []) as $index => $shippingLine) {
            if (! is_array($shippingLine)) {
                continue;
            }

            $shippingLine['meta_data'] = collect((array) ($shippingLine['meta_data'] ?? []))
                ->reject(function (mixed $meta) use ($preservedIdentities): bool {
                    if (! is_array($meta)
                        || ! $this->isShipmentIdentityKey((string) ($meta['key'] ?? ''))) {
                        return false;
                    }

                    return ! is_scalar($meta['value'] ?? null)
                        || ! in_array(trim((string) $meta['value']), $preservedIdentities, true);
                })->values()->all();
            $raw['shipping_lines'][$index] = $shippingLine;
        }

        return $raw;
    }

    /**
     * WooCommerce can keep shipment metadata after the carrier shipment has
     * been cancelled. Persist exact cancelled identities so a later order
     * import cannot silently attach the restored order to that old shipment.
     *
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @return list<string>
     */
    private function cancelledShipmentIdentities(
        EloquentCollection $family,
        CarbonInterface $artifactCutoff,
        string $operationUuid,
    ): array {
        $activeIdentities = ShippingLabel::query()
            ->shipments()
            ->whereIn('external_order_id', $family->pluck('id'))
            ->where('status', '!=', 'cancelled')
            ->get(['label_number', 'tracking_number'])
            ->flatMap(fn (ShippingLabel $label): array => [
                trim((string) $label->label_number),
                trim((string) $label->tracking_number),
            ])
            ->filter(fn (string $identity): bool => $identity !== '')
            ->unique()
            ->values()
            ->all();

        return ShippingLabel::query()
            ->shipments()
            ->whereIn('external_order_id', $family->pluck('id'))
            ->where('created_at', '>=', $artifactCutoff)
            ->where('status', 'cancelled')
            ->get(['label_number', 'tracking_number', 'response_payload'])
            ->filter(fn (ShippingLabel $label): bool => hash_equals(
                $operationUuid,
                (string) data_get($label->response_payload, 'split_reversal.operation_uuid', ''),
            ))
            ->flatMap(fn (ShippingLabel $label): array => [
                (string) $label->label_number,
                (string) $label->tracking_number,
            ])
            ->map(fn (mixed $identity): string => trim((string) $identity))
            ->filter(fn (string $identity): bool => $identity !== '')
            ->reject(fn (string $identity): bool => in_array($identity, $activeIdentities, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $raw
     * @param  list<string>  $cancelledShipmentIdentities
     * @return array<string,mixed>
     */
    private function withCancelledShipmentIdentityTombstone(
        array $raw,
        array $cancelledShipmentIdentities,
        string $operationUuid,
    ): array {
        $marker = (array) data_get($raw, 'sempre_erp_split_reversal', []);
        $previousIdentities = (array) ($marker['cancelled_shipment_identities'] ?? []);
        $identities = collect([...$previousIdentities, ...$cancelledShipmentIdentities])
            ->map(fn (mixed $identity): string => trim((string) $identity))
            ->filter(fn (string $identity): bool => $identity !== '')
            ->unique()
            ->values()
            ->all();

        if ($identities === []) {
            return $raw;
        }

        $marker['operation_uuid'] = $operationUuid;
        $marker['reversed_at'] = now()->toISOString();
        $marker['cancelled_shipment_identities'] = $identities;
        $raw['sempre_erp_split_reversal'] = $marker;

        return $raw;
    }

    /** @param EloquentCollection<int,ExternalOrder> $children */
    private function moveCommunicationHistory(
        ExternalOrder $root,
        EloquentCollection $children,
        CarbonInterface $splitStartedAt,
        string $operationUuid,
    ): void {
        $familyIds = collect([$root->id, ...$children->pluck('id')->all()]);
        $messages = CustomerMessage::query()
            ->whereIn('external_order_id', $familyIds)
            ->lockForUpdate()
            ->get();

        foreach ($messages as $message) {
            $metadata = (array) $message->metadata;
            $metadata['split_reversal'] = [
                'original_order_id' => $message->external_order_id,
                'moved_to_root_at' => now()->toISOString(),
                'operation_uuid' => $operationUuid,
            ];

            $updates = [
                'external_order_id' => $root->id,
                'metadata' => $metadata,
            ];

            if ($message->created_at?->gte($splitStartedAt)
                && in_array((string) $message->trigger, ['order_partial_created', 'order_packed'], true)
                && in_array((string) $message->status, ['held', 'failed', 'pending'], true)) {
                $updates['status'] = 'skipped';
                $updates['failed_at'] = null;
                $updates['error_message'] = 'Wiadomość pominięta po cofnięciu rozdzielenia zamówienia.';
                $metadata['split_reversal']['delivery_cancelled'] = true;
                $updates['metadata'] = $metadata;
            }

            $message->update($updates);
        }

        foreach (InternalNote::query()->whereIn('external_order_id', $children->pluck('id'))->lockForUpdate()->get() as $note) {
            $metadata = (array) $note->metadata;
            $metadata['split_reversal'] = [
                'original_order_id' => $note->external_order_id,
                'moved_to_root_at' => now()->toISOString(),
            ];
            $note->update(['external_order_id' => $root->id, 'metadata' => $metadata]);
        }
    }

    /**
     * @param  EloquentCollection<int,ExternalOrder>  $children
     * @param  list<int>  $preRestoreProductIds
     */
    private function consolidateReflectedOrderQuantities(
        ExternalOrder $root,
        EloquentCollection $children,
        ?array $snapshot,
        array $preRestoreProductIds,
    ): void {
        $restoredProductIds = $root->lines
            ->whereNotNull('product_id')
            ->pluck('product_id')
            ->map(fn (mixed $productId): int => (int) $productId)
            ->unique()
            ->values();
        $productIds = $restoredProductIds
            ->merge($preRestoreProductIds)
            ->map(fn (mixed $productId): int => (int) $productId)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        $familyExternalIds = collect([$root->external_id, ...$children->pluck('external_id')->all()])
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->values();

        $snapshotQuantities = (array) ($snapshot['source_reflected_order_quantities'] ?? []);

        foreach (StockBalance::query()
            ->whereIn('product_id', $productIds)
            ->where('source_sales_channel_id', $root->sales_channel_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get() as $balance) {
            $snapshotBalance = $snapshotQuantities[(string) $balance->id] ?? null;
            $quantities = (array) $balance->source_reflected_order_quantities;

            $hasFamilyEntry = $familyExternalIds->contains(fn (string $externalId): bool => array_key_exists($externalId, $quantities));

            if (! $hasFamilyEntry && ! is_array($snapshotBalance)) {
                continue;
            }

            $rootExternalId = (string) $root->external_id;
            $rootEntryExisted = array_key_exists($rootExternalId, $quantities);
            $restoredQuantity = is_array($snapshotBalance)
                ? (float) ($snapshotBalance['quantity'] ?? 0)
                : ($rootEntryExisted
                    ? (float) $quantities[$rootExternalId]
                    : (float) $familyExternalIds->sum(
                        fn (string $externalId): float => (float) ($quantities[$externalId] ?? 0),
                    ));
            $shouldRestore = is_array($snapshotBalance)
                ? (bool) ($snapshotBalance['exists'] ?? false)
                : ($rootEntryExisted || $restoredQuantity > 0);
            $shouldRestore = $shouldRestore
                && $restoredProductIds->contains((int) $balance->product_id);

            foreach ($familyExternalIds as $externalId) {
                unset($quantities[$externalId]);
            }

            if ($shouldRestore) {
                $quantities[$rootExternalId] = $restoredQuantity;
            }

            ksort($quantities, SORT_NATURAL);
            $balance->update(['source_reflected_order_quantities' => $quantities]);
            $this->reservations->recalculateBalance(
                (int) $balance->warehouse_id,
                (int) $balance->product_id,
            );
        }
    }

    /** @param EloquentCollection<int,ExternalOrder> $family @return list<string> */
    private function shippingConfirmationReasons(EloquentCollection $family): array
    {
        $root = $family->first(fn (ExternalOrder $member): bool => $member->split_root_order_id === null)
            ?? $family->first();
        $artifactCutoff = $root instanceof ExternalOrder
            ? $this->postSplitArtifactCutoff($root, $family, $this->originalSnapshot($root))
            : null;
        $labels = ShippingLabel::query()
            ->with('printJobs')
            ->shipments()
            ->whereIn('external_order_id', $family->pluck('id'))
            ->when(
                $artifactCutoff instanceof CarbonInterface,
                fn ($query) => $query->where('created_at', '>=', $artifactCutoff),
            )
            ->orderBy('id')
            ->get();
        $reasons = [];

        foreach ($labels as $label) {
            $manualRemote = (array) data_get($label->response_payload, 'cancellation.remote', []);
            $remoteStatus = mb_strtolower(trim((string) ($manualRemote['status'] ?? '')));

            if ($remoteStatus === 'manual_required') {
                $reasons[] = trim((string) ($manualRemote['message'] ?? ''))
                    ?: 'Przesyłka wymaga ręcznego potwierdzenia anulowania u przewoźnika.';
            } elseif (
                mb_strtolower(trim((string) $label->status)) === 'cancelled'
                && ! in_array($remoteStatus, ['cancelled', 'already_cancelled'], true)
            ) {
                $reasons[] = 'Etykieta '.$label->trackingIdentifier().' jest anulowana tylko lokalnie, '
                    .'ale brak potwierdzenia anulowania przesyłki u przewoźnika. '
                    .'Sprawdź ją ręcznie i potwierdź anulowanie przed scaleniem.';
            }

            if ($label->printJobs->contains(fn (PrintJob $job): bool => in_array(
                (string) $job->status,
                ['printing', 'printed'],
                true,
            ) || in_array(
                (string) data_get($job->metadata, 'shipping_label_cancellation.previous_status', ''),
                ['printing', 'printed'],
                true,
            ))) {
                $reasons[] = 'Etykieta '.$label->trackingIdentifier().' została pobrana do druku albo wydrukowana. '
                    .'Po anulowaniu przesyłki trzeba zniszczyć fizyczny wydruk.';
            }
        }

        return array_values(array_unique(array_filter($reasons)));
    }

    /** @param EloquentCollection<int,ExternalOrder> $family */
    private function familyWasShipped(EloquentCollection $family): bool
    {
        $orderIds = $family->pluck('id');

        if ($family->contains(fn (ExternalOrder $member): bool => in_array(
            mb_strtolower(trim((string) $member->fulfillment_status)),
            ['shipped', 'delivered'],
            true,
        ) || in_array(
            mb_strtolower(trim((string) $member->status)),
            ['shipped'],
            true,
        ) || (string) data_get($member->raw_payload, 'sempre_erp_status_sync.operation') === 'order_shipped')) {
            return true;
        }

        if (PackingTask::query()->whereIn('external_order_id', $orderIds)->where('status', 'shipped')->exists()) {
            return true;
        }

        return ShippingLabel::query()
            ->shipments()
            ->whereIn('external_order_id', $orderIds)
            ->get()
            ->contains(fn (ShippingLabel $label): bool => $label->hasCourierPickupEvidence());
    }

    /** @param EloquentCollection<int,ExternalOrder> $family @param array<string,mixed>|null $snapshot */
    private function splitStartedAt(
        ExternalOrder $root,
        EloquentCollection $family,
        ?array $snapshot,
    ): CarbonImmutable {
        if (HistoricalSplitSnapshot::isVerified($snapshot)) {
            $sourceOrderId = (int) data_get($snapshot, 'legacy_adoption.source_order_id', 0);
            $sourceOrder = $family->firstWhere('id', $sourceOrderId);

            if ($sourceOrder instanceof ExternalOrder && $sourceOrder->created_at !== null) {
                // Historical reconciliation records the exact physical child
                // whose database timestamp marks the split. Use that model
                // timestamp for every database and in-memory comparison so a
                // serialized UTC audit timestamp cannot shift the wall-clock
                // boundary used by timezone-naive datetime columns.
                return CarbonImmutable::instance($sourceOrder->created_at)->startOfSecond();
            }
        }

        $capturedAt = $snapshot['captured_at'] ?? null;

        if (filled($capturedAt)) {
            try {
                // Snapshot timestamps are serialized in UTC, while database
                // datetime columns are formatted in the application timezone.
                // Normalize before second-precision database comparisons.
                return CarbonImmutable::parse((string) $capturedAt)
                    ->setTimezone((string) config('app.timezone'))
                    ->startOfSecond();
            } catch (Throwable) {
                // Legacy snapshot with an invalid timestamp falls back to the
                // first physical child, which is the safest known split time.
            }
        }

        $firstChildCreatedAt = $family
            ->where('id', '!=', $root->id)
            ->min(fn (ExternalOrder $member): mixed => $member->created_at);

        return $firstChildCreatedAt !== null
            ? CarbonImmutable::instance($firstChildCreatedAt)
            : CarbonImmutable::instance($root->created_at ?? now());
    }

    /** @param EloquentCollection<int,ExternalOrder> $family @param array<string,mixed>|null $snapshot */
    private function postSplitArtifactCutoff(
        ExternalOrder $root,
        EloquentCollection $family,
        ?array $snapshot,
    ): CarbonImmutable {
        $splitStartedAt = $this->splitStartedAt($root, $family, $snapshot);

        // Without the immutable snapshot, a document stamped in the same
        // database second as the first child cannot be proven to be post-split.
        // It is therefore treated as pre-existing and blocks automatic reversal.
        return $snapshot === null
            ? $splitStartedAt->addSecond()
            : $splitStartedAt;
    }

    /**
     * @param  array<string,mixed>|null  $snapshot
     * @param  EloquentCollection<int,ExternalOrder>  $family
     */
    private function originalStatus(
        ExternalOrder $root,
        ?array $snapshot,
        EloquentCollection $family,
    ): string {
        $snapshotStatus = trim((string) ($snapshot['status'] ?? ''));

        if ($snapshotStatus !== '') {
            return $snapshotStatus;
        }

        $payloadStatus = trim((string) data_get(
            $this->legacyBaselinePayload($root, $family) ?? $root->raw_payload,
            'status',
            '',
        ));

        if ($payloadStatus !== '') {
            return $payloadStatus;
        }

        $integration = WordpressIntegration::query()
            ->when(
                $root->wordpress_integration_id !== null,
                fn ($query) => $query->whereKey($root->wordpress_integration_id),
                fn ($query) => $query->where('sales_channel_id', $root->sales_channel_id),
            )
            ->where('sales_channel_id', $root->sales_channel_id)
            ->first();
        $fallback = $integration instanceof WordpressIntegration
            ? trim((string) data_get($integration->orderStatusSettings(), 'packing_rollback', 'processing'))
            : '';

        return $fallback !== '' ? $fallback : 'processing';
    }

    /** @param array<string,mixed> $data */
    private function recordOperationStep(
        int $rootOrderId,
        string $operationUuid,
        string $step,
        string $status,
        array $data,
    ): void {
        DB::transaction(function () use ($rootOrderId, $operationUuid, $step, $status, $data): void {
            $root = ExternalOrder::query()->lockForUpdate()->find($rootOrderId);

            if (! $root instanceof ExternalOrder) {
                return;
            }

            $raw = (array) $root->raw_payload;
            $operation = data_get($raw, 'sempre_erp_split_reversal_operation');

            if (! is_array($operation) || (string) ($operation['uuid'] ?? '') !== $operationUuid) {
                return;
            }

            $steps = (array) ($operation['steps'] ?? []);
            $steps[$step] = [
                'status' => $status,
                'recorded_at' => now()->toISOString(),
                'data' => $data,
            ];
            $operation['steps'] = $steps;
            $operation['status'] = $status === 'failed' ? 'failed' : ($status === 'attention_required' ? 'attention_required' : 'processing');
            $operation['updated_at'] = now()->toISOString();
            $raw['sempre_erp_split_reversal_operation'] = $operation;
            $root->update(['raw_payload' => $raw]);
        }, 3);
    }

    /** @param list<int> $familyOrderIds @return list<int> */
    private function reverseWarehouseDocuments(
        array $familyOrderIds,
        CarbonInterface $splitStartedAt,
        string $operationUuid,
        string $reason,
        ?array $snapshot,
    ): array {
        $family = ExternalOrder::query()->whereIn('id', $familyOrderIds)->orderBy('id')->get();
        $archived = [];

        // Undo inventory movements in reverse creation order. If several WZ
        // documents touched the same source-backed balance, only the oldest
        // movement carries the original Woo baseline; reversing it last
        // restores that baseline after all newer movements are gone.
        foreach ($this->wzDocumentsForFamily($family)->sortByDesc('id') as $document) {
            if ($document->created_at?->lt($splitStartedAt)) {
                if ($this->isPreservedHistoricalWarehouseDocument($document, $snapshot)) {
                    continue;
                }

                if ($document->status !== 'cancelled') {
                    throw new RuntimeException("Dokument {$document->number} powstał przed podziałem.");
                }

                continue;
            }

            if (! in_array((string) $document->status, ['draft', 'posted', 'cancelled'], true)) {
                throw new RuntimeException("Dokument {$document->number} ma nieobsługiwany status {$document->status}.");
            }

            $originalKey = $document->order_fulfillment_key;

            if ($document->status !== 'cancelled') {
                $this->documentPosting->cancel($document);
                $document->refresh();
            }

            $metadata = (array) $document->metadata;
            $metadata['split_reversal'] = [
                'operation_uuid' => $operationUuid,
                'reason' => $reason,
                'original_order_fulfillment_key' => $originalKey,
                'archived_at' => now()->toISOString(),
            ];
            $document->update([
                'order_fulfillment_key' => mb_substr('split-reverted:'.$operationUuid.':'.$document->id, 0, 191),
                'metadata' => $metadata,
            ]);
            $document->delete();
            $archived[] = (int) $document->id;
        }

        return array_values(array_unique($archived));
    }

    /**
     * @param  list<int>  $familyOrderIds
     * @return array{cancelled:list<int>,corrections:list<int>,uploaded_corrections:list<int>}
     */
    private function reverseInvoices(
        array $familyOrderIds,
        CarbonInterface $splitStartedAt,
        string $operationUuid,
        string $reason,
    ): array {
        $result = ['cancelled' => [], 'corrections' => [], 'uploaded_corrections' => []];

        foreach (ExternalOrder::query()->whereIn('id', $familyOrderIds)->orderBy('id')->get() as $member) {
            $memberResult = $this->invoiceReversal->reverseForSplitReversal(
                $member,
                $operationUuid,
                $reason,
                $splitStartedAt,
            );
            $result['cancelled'] = [...$result['cancelled'], ...$memberResult['cancelled']];
            $result['corrections'] = [...$result['corrections'], ...$memberResult['corrections']];
        }

        $result['cancelled'] = array_values(array_unique($result['cancelled']));
        $result['corrections'] = array_values(array_unique($result['corrections']));

        foreach ($result['corrections'] as $correctionId) {
            $correction = Invoice::query()->find($correctionId);
            $originalId = (int) data_get($correction?->metadata, 'corrected_invoice_id');
            $original = $originalId > 0 ? Invoice::query()->find($originalId) : null;

            if (! $correction instanceof Invoice
                || ! $original instanceof Invoice
                || data_get($original->metadata, 'woocommerce_upload.status') !== 'success'
                || data_get($correction->metadata, 'woocommerce_upload.status') === 'success') {
                continue;
            }

            $this->invoiceUpload->upload($correction);
            $result['uploaded_corrections'][] = (int) $correction->id;
        }

        $result['uploaded_corrections'] = array_values(array_unique($result['uploaded_corrections']));

        return $result;
    }

    /** @return array{status:string,remote_synced:bool} */
    private function restoreWooStatus(int $rootOrderId, string $status): array
    {
        $root = ExternalOrder::query()->findOrFail($rootOrderId);

        $integrationExists = WordpressIntegration::query()
            ->when(
                $root->wordpress_integration_id !== null,
                fn ($query) => $query->whereKey($root->wordpress_integration_id),
                fn ($query) => $query->where('sales_channel_id', $root->sales_channel_id),
            )
            ->where('sales_channel_id', $root->sales_channel_id)
            ->exists();

        if (! $integrationExists) {
            if ((string) $root->status !== $status) {
                $root->update(['status' => $status]);
            }

            return ['status' => $status, 'remote_synced' => false];
        }

        $result = $this->orderStatuses->updateManually($root, $status);
        $responseStatus = mb_strtolower(trim((string) data_get($result, 'response.status', '')));

        if ($responseStatus !== mb_strtolower(trim($status))) {
            throw new RuntimeException(
                'WooCommerce nie potwierdził przywrócenia statusu zamówienia. '
                .'Odpowiedź zawiera status: '.($responseStatus !== '' ? $responseStatus : 'brak statusu').'.',
            );
        }

        return [
            'status' => (string) ($result['status'] ?? $status),
            'remote_synced' => true,
        ];
    }

    /** @param EloquentCollection<int,ExternalOrder> $family */
    private function archiveCancelledShippingLabels(
        EloquentCollection $family,
        string $operationUuid,
        CarbonInterface $splitStartedAt,
    ): void {
        $labels = ShippingLabel::query()
            ->shipments()
            ->whereIn('external_order_id', $family->pluck('id'))
            ->where('created_at', '>=', $splitStartedAt)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($labels as $label) {
            if ($label->status !== 'cancelled') {
                throw new RuntimeException('Jedna z etykiet rodziny nie została skutecznie anulowana. Scalanie zatrzymano.');
            }

            $payload = (array) $label->response_payload;
            $payload['split_reversal'] = [
                'operation_uuid' => $operationUuid,
                'original_idempotency_key' => $label->idempotency_key,
                'archived_at' => now()->toISOString(),
            ];
            $label->forceFill([
                'idempotency_key' => mb_substr('split-reverted:'.$operationUuid.':'.$label->id, 0, 191),
                'response_payload' => $payload,
            ])->save();
        }
    }

    /** @param EloquentCollection<int,ExternalOrder> $family @return EloquentCollection<int,WarehouseDocument> */
    private function wzDocumentsForFamily(EloquentCollection $family): EloquentCollection
    {
        $ids = $family
            ->flatMap(fn (ExternalOrder $member) => $this->fulfillmentStatus
                ->wzDocumentsForOrder($member)
                ->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return WarehouseDocument::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    /**
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @param  EloquentCollection<int,ExternalOrderLine>  $lines
     * @return array<string,mixed>
     */
    private function auditSnapshot(EloquentCollection $family, EloquentCollection $lines): array
    {
        return [
            'orders' => $family->map(fn (ExternalOrder $member): array => [
                'id' => $member->id,
                'external_id' => $member->external_id,
                'external_number' => $member->external_number,
                'status' => $member->status,
                'total_gross' => (string) $member->total_gross,
            ])->values()->all(),
            'lines' => $lines->map(fn (ExternalOrderLine $line): array => [
                'id' => $line->id,
                'external_order_id' => $line->external_order_id,
                'canonical_external_line_id' => $this->canonicalExternalLineId($line),
                'sku' => $line->sku,
                'quantity' => (string) $line->quantity,
            ])->values()->all(),
        ];
    }

    /**
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @param  EloquentCollection<int,ExternalOrderLine>  $lines
     */
    private function familyVersion(EloquentCollection $family, EloquentCollection $lines): string
    {
        $orderIds = $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        return hash('sha256', json_encode([
            'orders' => $family->sortBy('id')->map(fn (ExternalOrder $member): array => [
                'id' => $member->id,
                'status' => $member->status,
                'total_gross' => (string) $member->total_gross,
                'updated_at' => $member->getRawOriginal('updated_at'),
            ])->values()->all(),
            'lines' => $lines->sortBy('id')->map(fn (ExternalOrderLine $line): array => [
                'id' => $line->id,
                'external_order_id' => $line->external_order_id,
                'quantity' => (string) $line->quantity,
                'updated_at' => $line->getRawOriginal('updated_at'),
            ])->values()->all(),
            'packing_tasks' => PackingTask::query()
                ->whereIn('external_order_id', $orderIds)
                ->orderBy('id')
                ->get()
                ->map(fn (PackingTask $task): array => [
                    'id' => $task->id,
                    'order_id' => $task->external_order_id,
                    'status' => $task->status,
                    'quantity_picked' => (string) $task->quantity_picked,
                    'picked_at' => $task->picked_at?->toISOString(),
                    'packed_at' => $task->packed_at?->toISOString(),
                    'updated_at' => $task->getRawOriginal('updated_at'),
                ])->all(),
            'shipping_labels' => ShippingLabel::query()
                ->shipments()
                ->whereIn('external_order_id', $orderIds)
                ->orderBy('id')
                ->get()
                ->map(fn (ShippingLabel $label): array => [
                    'id' => $label->id,
                    'order_id' => $label->external_order_id,
                    'status' => $label->status,
                    'picked_up_at' => $label->picked_up_at?->toISOString(),
                    'updated_at' => $label->getRawOriginal('updated_at'),
                ])->all(),
            'warehouse_documents' => $this->wzDocumentsForFamily($family)
                ->map(fn (WarehouseDocument $document): array => [
                    'id' => $document->id,
                    'status' => $document->status,
                    'updated_at' => $document->getRawOriginal('updated_at'),
                ])->all(),
            'invoices' => Invoice::withTrashed()
                ->whereIn('external_order_id', $orderIds)
                ->orderBy('id')
                ->get()
                ->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'order_id' => $invoice->external_order_id,
                    'type' => $invoice->type,
                    'status' => $invoice->status,
                    'updated_at' => $invoice->getRawOriginal('updated_at'),
                ])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return EloquentCollection<int,ExternalOrder> */
    private function familyOrders(ExternalOrder $order): EloquentCollection
    {
        $fresh = ExternalOrder::query()->find($order->id) ?? $order;
        $rootId = (int) ($fresh->split_root_order_id ?: $fresh->id);

        return ExternalOrder::query()
            ->where('sales_channel_id', $fresh->sales_channel_id)
            ->where(fn ($query) => $query
                ->whereKey($rootId)
                ->orWhere('split_root_order_id', $rootId)
                ->orWhere('id', $fresh->id))
            ->orderBy('id')
            ->get();
    }

    /** @param EloquentCollection<int,ExternalOrder> $family */
    private function rootOrder(ExternalOrder $order, EloquentCollection $family): ExternalOrder
    {
        $rootId = (int) ($order->split_root_order_id ?: $order->id);

        return $family->firstWhere('id', $rootId) ?? $order;
    }

    /**
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @return EloquentCollection<int,ExternalOrderLine>
     */
    private function familyLines(EloquentCollection $family): EloquentCollection
    {
        return ExternalOrderLine::query()
            ->whereIn('external_order_id', $family->pluck('id'))
            ->orderBy('id')
            ->get();
    }

    /** @param list<int> $orderIds */
    private function withShippingLocks(array $orderIds, int $offset, callable $operation): mixed
    {
        if (! isset($orderIds[$offset])) {
            return $operation();
        }

        return Cache::lock('shipping-label-order-'.$orderIds[$offset], self::SHIPPING_LOCK_SECONDS)
            ->block(
                self::SHIPPING_LOCK_WAIT_SECONDS,
                fn (): mixed => $this->withShippingLocks($orderIds, $offset + 1, $operation),
            );
    }

    private function canonicalExternalLineId(ExternalOrderLine $line): ?string
    {
        $canonical = trim((string) (
            $line->canonical_external_line_id
            ?: data_get($line->raw_payload, 'sempre_erp_split.root_external_line_id')
            ?: data_get($line->raw_payload, 'id')
            ?: data_get($line->raw_payload, 'sempre_erp_split.source_external_line_id')
            ?: $line->external_line_id
        ));

        if ($canonical === '') {
            return null;
        }

        do {
            $previous = $canonical;
            $canonical = (string) preg_replace('/-S\d+$/', '', $canonical);
        } while ($canonical !== $previous);

        return $canonical;
    }

    /** @param array<string,mixed> $payload */
    private function hasShipmentIdentity(array $payload): bool
    {
        return $this->shipmentIdentities($payload) !== [];
    }

    /** @param array<string,mixed> $payload @return list<string> */
    private function shipmentIdentities(array $payload): array
    {
        $identities = [];

        foreach (array_keys($payload) as $key) {
            if (filled($payload[$key] ?? null) && $this->isShipmentIdentityKey((string) $key)) {
                $value = $payload[$key];

                if (is_scalar($value)) {
                    $identities[] = trim((string) $value);
                }
            }
        }

        $sources = [(array) ($payload['meta_data'] ?? [])];

        foreach ((array) ($payload['shipping_lines'] ?? []) as $shippingLine) {
            if (is_array($shippingLine)) {
                $sources[] = (array) ($shippingLine['meta_data'] ?? []);
            }
        }

        foreach (collect($sources)->flatten(1) as $meta) {
            if (! is_array($meta)
                || ! filled($meta['value'] ?? null)
                || ! $this->isShipmentIdentityKey((string) ($meta['key'] ?? ''))
                || ! is_scalar($meta['value'])) {
                continue;
            }

            $identities[] = trim((string) $meta['value']);
        }

        return array_values(array_unique(array_filter(
            $identities,
            fn (string $identity): bool => $identity !== '',
        )));
    }

    private function isShipmentIdentityKey(string $key): bool
    {
        $key = mb_strtolower(trim($key));

        if ($key === '') {
            return false;
        }

        $isInPostKey = str_contains($key, 'inpost')
            || str_contains($key, 'shipx')
            || str_contains($key, 'easypack');

        if ($isInPostKey && (str_contains($key, 'point') || str_contains($key, 'locker')
            || str_contains($key, 'machine') || str_contains($key, 'target'))) {
            return false;
        }

        if (str_contains($key, 'tracking') || str_contains($key, 'waybill') || str_contains($key, 'list_przewoz')) {
            return true;
        }

        if (str_contains($key, 'blpaczka')) {
            return str_contains($key, 'order_id')
                || str_contains($key, 'shipment')
                || str_contains($key, 'label');
        }

        return $isInPostKey
            && (str_contains($key, 'shipment') || str_contains($key, 'label')
                || str_contains($key, 'parcel_number') || str_contains($key, 'id'));
    }
}
