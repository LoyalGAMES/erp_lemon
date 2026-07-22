<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationStep;
use App\Models\PackingTask;
use App\Models\ReturnCase;
use App\Models\ShippingLabel;
use App\Models\StockReservation;
use App\Models\WarehouseDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\StockReservationService;
use App\Services\Inventory\WarehouseDocumentNumberService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Packing\PackingTaskService;
use App\Services\Payments\WooCommerceRefundService;
use App\Services\Shipping\ShippingCancellationService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class OrderCancellationService
{
    public function __construct(
        private readonly OrderMutationLock $orderLock,
        private readonly WooCommerceRefundService $refunds,
        private readonly ShippingCancellationService $shipping,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly WarehouseDocumentPostingService $documentPosting,
        private readonly WarehouseDocumentNumberService $documentNumbers,
        private readonly PackingTaskService $packingTasks,
        private readonly StockReservationService $reservations,
        private readonly OrderCancellationInvoiceService $invoiceReversal,
        private readonly InvoiceWooCommerceUploadService $invoiceUpload,
        private readonly WooCommerceOrderStatusService $orderStatuses,
        private readonly CustomerCommunicationService $communication,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array{source?:string,preserve_packing_problem?:bool,suppress_default_customer_notification?:bool,restore_stock?:bool}  $context
     * @return array{cancellation:OrderCancellation,already_completed:bool,attention_required:bool,warnings:list<string>}
     */
    public function cancel(
        ExternalOrder $order,
        string $reason,
        ?int $requestedBy = null,
        array $context = [],
    ): array {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Podaj powód anulowania zamówienia (minimum 3 znaki).');
        }

        [$root, $family] = $this->familyFor($order);

        return $this->orderLock->forOrders(
            $family,
            fn (): array => $this->cancelWhileLocked($root, $reason, $requestedBy, $context),
        );
    }

    /**
     * The packer cannot access the financial cancellation endpoint directly,
     * but the existing packing-problem flow still has to execute the same safe
     * refund, shipment and document rollback saga.
     *
     * @return array{cancellation:OrderCancellation,already_completed:bool,attention_required:bool,warnings:list<string>}
     */
    public function cancelForPackingProblem(
        ExternalOrder $order,
        string $reason,
        ?int $requestedBy = null,
        bool $restoreStock = true,
    ): array {
        return $this->cancel($order, $reason, $requestedBy, [
            'source' => 'packing_problem',
            'preserve_packing_problem' => true,
            'suppress_default_customer_notification' => true,
            'restore_stock' => $restoreStock,
        ]);
    }

    /**
     * Resume a cancellation only after an operator has confirmed that every
     * shipment listed by the shipping preflight was cancelled outside the ERP.
     * The shared order lock is released before cancel() acquires it again.
     *
     * @return array{cancellation:OrderCancellation,already_completed:bool,attention_required:bool,warnings:list<string>}
     */
    public function confirmManualShippingCancellation(
        ExternalOrder $order,
        ?int $userId,
        string $note = '',
    ): array {
        [$root, $family] = $this->familyFor($order);
        $resume = $this->orderLock->forOrders(
            $family,
            fn (): array => $this->confirmManualShippingWhileLocked($root, $userId, $note),
        );

        if ($resume['confirmed_now']) {
            $this->audit->record(
                'order.cancellation_shipping_manually_confirmed',
                $root->fresh() ?? $root,
                null,
                [
                    'cancellation_status' => 'requested',
                    'shipping_step_status' => 'completed',
                ],
                [
                    'order_cancellation_id' => $resume['cancellation_id'],
                    'order_cancellation_uuid' => $resume['cancellation_uuid'],
                    'confirmed_by' => $userId,
                    'confirmation_note' => $resume['note'],
                    'resolved_manual_required' => $resume['resolved_manual_required'],
                ],
            );
        }

        return $this->cancel(
            $root->fresh() ?? $root,
            $resume['reason'],
            $resume['requested_by'] ?? $userId,
            $resume['context'],
        );
    }

    /**
     * Explicitly reconcile an ambiguous WooCommerce refund without opening a
     * path to another refund POST. The existing protected CustomerPayment is
     * required before the refund step may be resumed.
     *
     * @return array{cancellation:OrderCancellation,already_completed:bool,attention_required:bool,warnings:list<string>}
     */
    public function reconcileUnknownRefund(
        ExternalOrder $order,
        ?int $userId,
    ): array {
        [$root, $family] = $this->familyFor($order);
        $resume = $this->orderLock->forOrders(
            $family,
            fn (): array => $this->prepareUnknownRefundReconciliationWhileLocked($root),
        );

        $this->audit->record(
            'order.cancellation_refund_reconciliation_requested',
            $root->fresh() ?? $root,
            [
                'cancellation_status' => $resume['previous_cancellation_status'],
                'refund_status' => 'unknown',
                'refund_step_status' => 'unknown',
                'customer_payment_status' => $resume['customer_payment_status'],
            ],
            [
                'cancellation_status' => 'requested',
                'refund_status' => 'unknown',
                'refund_step_status' => 'pending',
                'customer_payment_status' => $resume['customer_payment_status'],
            ],
            [
                'order_cancellation_id' => $resume['cancellation_id'],
                'order_cancellation_uuid' => $resume['cancellation_uuid'],
                'customer_payment_id' => $resume['customer_payment_id'],
                'idempotency_key' => $resume['idempotency_key'],
                'requested_by' => $userId,
                'mode' => 'read_only_remote_reconciliation',
            ],
        );

        // Release the family lock before cancel() acquires the same locks.
        return $this->cancel(
            $root->fresh() ?? $root,
            $resume['reason'],
            $resume['requested_by'] ?? $userId,
            $resume['context'],
        );
    }

    /**
     * @return array{cancellation:OrderCancellation,already_completed:bool,attention_required:bool,warnings:list<string>}
     */
    private function cancelWhileLocked(
        ExternalOrder $root,
        string $reason,
        ?int $requestedBy,
        array $context,
    ): array {
        [$root, $family] = $this->familyFor($root->fresh() ?? $root);
        $cancellation = $this->claimCancellation($root, $family, $reason, $requestedBy, $context);
        $context = $this->effectiveContext($cancellation, $context);

        if ($cancellation->status === 'completed') {
            return [
                'cancellation' => $cancellation->load('steps'),
                'already_completed' => true,
                'attention_required' => false,
                'warnings' => [],
            ];
        }

        try {
            $completedPreflight = $cancellation->steps()
                ->where('step', 'preflight')
                ->whereIn('status', ['completed', 'attention_required'])
                ->exists();

            // Re-check an already recorded preflight on every retry. This
            // upgrades old in-progress cancellations to the current safety
            // contract and proves that the integration still has write access
            // before any remaining operational step is resumed.
            if ($completedPreflight) {
                $this->assertCancellationPreflight($root, $family, $cancellation, $context);
            }

            $this->runStep($cancellation, 'preflight', function () use ($root, $family, $cancellation, $context): array {
                $this->assertCancellationPreflight($root, $family, $cancellation, $context);

                return [
                    'root_order_id' => $root->id,
                    'root_external_id' => $root->external_id,
                    'family_order_ids' => $family->pluck('id')->all(),
                    'wz_count' => $this->wzDocuments($family)->count(),
                    'invoice_count' => Invoice::query()->whereIn('external_order_id', $family->pluck('id'))->count(),
                    'label_count' => ShippingLabel::query()->shipments()->whereIn('external_order_id', $family->pluck('id'))->count(),
                ];
            });
        } catch (Throwable $exception) {
            $cancellation->update([
                'status' => 'rejected',
                'last_error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->runStep($cancellation, 'hold_fulfillment', function () use ($family): array {
            DB::transaction(function () use ($family): void {
                $ids = $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
                ExternalOrder::query()
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (ExternalOrder $member) => $member->update([
                        'status' => 'cancellation-pending',
                        'woo_shipped_sync_status' => 'skipped',
                        'woo_shipped_sync_next_at' => null,
                        'woo_shipped_sync_error' => 'Synchronizacja wysyłki wyłączona przez anulowanie zamówienia.',
                    ]));
            }, 3);

            return ['held_order_ids' => $family->pluck('id')->all()];
        });

        $warnings = [];
        $attentionRequired = false;

        // Najpierw zatrzymaj fizyczną wysyłkę. Dzięki temu zwrot pieniędzy
        // nie wyprzedzi próby anulowania paczki, która mogłaby właśnie zostać odebrana.
        $shipping = $this->runStep($cancellation, 'shipping', function () use ($root, $cancellation): array {
            $result = $this->shipping->cancelForOrder($root, $cancellation->uuid, $cancellation->reason);

            return $result + [
                '_step_status' => ($result['manual_required'] ?? []) === []
                    ? 'completed'
                    : 'attention_required',
            ];
        });

        foreach ((array) ($shipping['manual_required'] ?? []) as $manual) {
            $attentionRequired = true;
            $warnings[] = is_array($manual)
                ? (string) ($manual['message'] ?? 'Etykieta kurierska wymaga ręcznego anulowania.')
                : (string) $manual;
        }

        if ($attentionRequired) {
            $warnings = array_values(array_unique(array_filter($warnings)));
            $cancellation->update([
                'status' => 'attention_required',
                'last_error' => implode(' | ', $warnings),
                'completed_at' => null,
            ]);

            return [
                'cancellation' => $cancellation->fresh('steps'),
                'already_completed' => false,
                'attention_required' => true,
                'warnings' => $warnings,
            ];
        }

        $refund = $this->runStep($cancellation, 'refund', function () use ($cancellation): array {
            $result = $this->refunds->refund(
                $cancellation,
                'order-cancellation:'.$cancellation->uuid,
            );
            $status = (string) ($result['status'] ?? 'failed');

            return $this->serializableRefundResult($result) + [
                '_step_status' => match ($status) {
                    'not_required', 'submitted' => 'completed',
                    'unknown' => 'unknown',
                    'failed' => 'failed',
                    default => 'attention_required',
                },
            ];
        });
        $refundStatus = (string) ($refund['status'] ?? 'failed');
        $refundAmount = (float) ($refund['amount'] ?? 0);

        if ($refundAmount <= 0 && $refundStatus !== 'not_required') {
            $refundAmount = (float) ($refund['refundable'] ?? 0);
        }
        $cancellation->update([
            'refund_status' => $refundStatus,
            'refund_amount' => $refundAmount,
            'currency' => filled($refund['currency'] ?? null)
                ? mb_strtoupper((string) $refund['currency'])
                : $cancellation->currency,
            'payment_method' => (string) ($refund['payment_method'] ?? $cancellation->payment_method),
            'woo_refund_id' => filled($refund['woo_refund_id'] ?? null)
                ? (string) $refund['woo_refund_id']
                : $cancellation->woo_refund_id,
        ]);

        if ($refundStatus === 'unknown') {
            $warning = (string) ($refund['message'] ?? 'Wynik zwrotu płatności jest nieznany. Wymagana jest weryfikacja u operatora płatności.');
            $attentionRequired = true;
            $warnings[] = $warning;
        }

        if (in_array($refundStatus, ['manual_required', 'failed'], true)) {
            $attentionRequired = true;
            $warnings[] = (string) ($refund['message'] ?? 'Zwrot płatności wymaga ręcznej obsługi.');
        }

        $this->runStep(
            $cancellation,
            'warehouse_documents',
            fn (): array => $this->cancelWarehouseDocuments(
                $family,
                $cancellation,
                (bool) ($context['restore_stock'] ?? true),
            ),
        );

        $this->runStep($cancellation, 'inventory_and_packing', function () use ($family, $cancellation, $context): array {
            $tasks = 0;
            $released = 0;

            foreach ($family as $member) {
                $tasks += $this->packingTasks->cancelForOrderCancellation(
                    $member,
                    $cancellation->uuid,
                    $cancellation->reason,
                    (bool) ($context['preserve_packing_problem'] ?? false),
                );
                $reservationResult = $this->reservations->syncForOrder($member->fresh() ?? $member);
                $released += (int) ($reservationResult['released'] ?? 0);
            }

            return [
                'cancelled_tasks' => $tasks,
                'released_reservation_pairs' => $released,
            ];
        });

        $invoiceResult = $this->runStep($cancellation, 'invoices', function () use ($family, $cancellation): array {
            $result = ['cancelled' => [], 'corrections' => [], 'upload_warnings' => []];

            foreach ($family as $member) {
                $memberResult = $this->invoiceReversal->reverseForCancellation($member, $cancellation);
                $result['cancelled'] = [...$result['cancelled'], ...$memberResult['cancelled']];
                $result['corrections'] = [...$result['corrections'], ...$memberResult['corrections']];
            }

            foreach (array_values(array_unique($result['corrections'])) as $invoiceId) {
                $invoice = Invoice::query()->find($invoiceId);

                if (! $invoice instanceof Invoice || ! $this->originalInvoiceWasUploaded($invoice)) {
                    continue;
                }

                try {
                    $this->invoiceUpload->upload($invoice);
                } catch (Throwable $exception) {
                    $result['upload_warnings'][] = "Korekta {$invoice->number}: {$exception->getMessage()}";
                }
            }

            $result['cancelled'] = array_values(array_unique($result['cancelled']));
            $result['corrections'] = array_values(array_unique($result['corrections']));
            $result['_step_status'] = $result['upload_warnings'] === [] ? 'completed' : 'attention_required';

            return $result;
        });

        foreach ((array) ($invoiceResult['upload_warnings'] ?? []) as $warning) {
            $attentionRequired = true;
            $warnings[] = (string) $warning;
        }

        $this->runStep($cancellation, 'woocommerce_and_local_status', function () use ($root, $family, $cancellation, $context): array {
            $result = $this->orderStatuses->markCancelledForOrderCancellation(
                $root->fresh() ?? $root,
                (bool) ($context['restore_stock'] ?? true),
                (string) $cancellation->uuid,
            );
            $ids = $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            ExternalOrder::query()->whereIn('id', $ids)->update([
                'status' => 'cancelled',
                'fulfillment_status' => null,
                'woo_shipped_sync_status' => 'skipped',
                'woo_shipped_sync_next_at' => null,
                'woo_shipped_sync_error' => null,
            ]);

            return [
                'woo_status' => (string) ($result['status'] ?? 'cancelled'),
                'cancelled_order_ids' => $ids,
            ];
        });

        $finalStatus = $attentionRequired ? 'attention_required' : 'completed';
        $cancellation->update([
            'status' => $finalStatus,
            'last_error' => $warnings === [] ? null : implode(' | ', array_values(array_unique($warnings))),
            'completed_at' => now(),
        ]);
        $cancellation->refresh();

        $notificationTrigger = $context['source'] === 'packing_problem'
            ? 'order_cancelled_problem'
            : (($context['suppress_default_customer_notification'] ?? false) ? null : 'order_cancelled');

        if ($notificationTrigger !== null) {
            try {
                $this->communication->sendOrderStatus($root->fresh() ?? $root, $notificationTrigger, [
                    'cancellation_uuid' => $cancellation->uuid,
                    'cancellation_reason' => $cancellation->reason,
                    'refund_status' => $cancellation->refund_status,
                    'stock_restored' => (bool) ($context['restore_stock'] ?? true),
                    'problem_note' => $context['source'] === 'packing_problem'
                        ? $cancellation->reason
                        : null,
                ]);
            } catch (Throwable $exception) {
                $warnings[] = 'Powiadomienie klienta: '.$exception->getMessage();
            }
        }

        $this->audit->record('order.cancelled', $root->fresh() ?? $root, null, [
            'status' => 'cancelled',
            'cancellation_status' => $finalStatus,
            'refund_status' => $cancellation->refund_status,
            'stock_restored' => (bool) ($context['restore_stock'] ?? true),
        ], [
            'order_cancellation_id' => $cancellation->id,
            'order_cancellation_uuid' => $cancellation->uuid,
            'source' => $context['source'],
            'restore_stock' => (bool) ($context['restore_stock'] ?? true),
            'family_order_ids' => $family->pluck('id')->all(),
            'warnings' => array_values(array_unique($warnings)),
        ]);

        return [
            'cancellation' => $cancellation->fresh('steps'),
            'already_completed' => false,
            'attention_required' => $attentionRequired,
            'warnings' => array_values(array_unique(array_filter($warnings))),
        ];
    }

    /**
     * Keep the warehouse decision atomic. In particular, a posted WZ must
     * never remain cancelled without its compensating RW when the operator
     * explicitly chose not to return the goods to saleable stock.
     *
     * For an unposted order the saleable quantity is held by active stock
     * reservations rather than a posted WZ. Those reservations therefore
     * become the source of the RW before they are released.
     *
     * @param  EloquentCollection<int, ExternalOrder>  $family
     * @return array{cancelled_document_ids:list<int>,write_off_document_ids:list<int>,released_reservation_pairs:int}
     */
    private function cancelWarehouseDocuments(
        EloquentCollection $family,
        OrderCancellation $cancellation,
        bool $restoreStock,
    ): array {
        return DB::transaction(function () use ($family, $cancellation, $restoreStock): array {
            $documentIds = $this->wzDocuments($family)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            $documents = WarehouseDocument::query()
                ->whereIn('id', $documentIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            // Lock both active and waiting rows before taking the quantity
            // snapshot. A concurrent receipt must not be able to promote a
            // waiting reservation after the RW was calculated but before
            // syncForOrder() releases it.
            $openReservations = $restoreStock
                ? new EloquentCollection
                : $this->openReservationsForFamily($family);
            $reservations = $openReservations
                ->filter(fn (StockReservation $reservation): bool => $reservation->status === 'active')
                ->values();
            $cancelled = [];
            $writeOffs = [];
            $postedReservationCoverage = [];
            $ambiguousPostedCoverage = [];

            foreach ($documents as $document) {
                $hadPostedStockMovement = $document->status === 'posted'
                    || ($document->status === 'cancelled' && $this->cancelledWzHadPostedStockMovement($document));
                $quantities = $hadPostedStockMovement
                    ? $this->warehouseDocumentQuantities($document)
                    : [];

                if ($hadPostedStockMovement) {
                    $reservationIdentity = $this->warehouseDocumentReservationIdentity($document);

                    if ($reservationIdentity !== null) {
                        foreach ($quantities as $productId => $quantity) {
                            $postedReservationCoverage[$reservationIdentity][$productId]
                                = ($postedReservationCoverage[$reservationIdentity][$productId] ?? 0.0) + $quantity;
                        }
                    } elseif ($document->source_warehouse_id !== null) {
                        foreach ($quantities as $productId => $quantity) {
                            $ambiguousPostedCoverage[(int) $document->source_warehouse_id][$productId]
                                = ($ambiguousPostedCoverage[(int) $document->source_warehouse_id][$productId] ?? 0.0) + $quantity;
                        }
                    }
                }

                if ($document->status !== 'cancelled') {
                    $this->documentPosting->cancel($document);
                    $document->refresh();
                }

                $cancelled[] = (int) $document->id;

                if (! $restoreStock && $hadPostedStockMovement) {
                    if ($document->source_warehouse_id === null) {
                        throw new RuntimeException(
                            "Dokument {$document->number} nie wskazuje magazynu źródłowego potrzebnego do rozchodu bez przywracania stanu.",
                        );
                    }

                    $writeOffs[] = $this->ensureCancellationWriteOff(
                        cancellation: $cancellation,
                        sourceKey: 'wz:'.$document->id,
                        warehouseId: (int) $document->source_warehouse_id,
                        quantities: $quantities,
                        externalReference: (string) ($document->external_reference ?: $family->first()?->external_number),
                        sourceMetadata: [
                            'source' => 'cancelled_posted_wz',
                            'source_wz_id' => (int) $document->id,
                            'source_wz_number' => (string) $document->number,
                        ],
                    );
                }
            }

            if (! $restoreStock) {
                $reservationGroups = $reservations->groupBy(
                    fn (StockReservation $reservation): string => $this->reservationIdentity($reservation),
                );

                foreach ($reservationGroups->sortKeys() as $reservationIdentity => $warehouseReservations) {
                    /** @var StockReservation $firstReservation */
                    $firstReservation = $warehouseReservations->first();
                    $warehouseId = (int) $firstReservation->warehouse_id;
                    $quantities = [];

                    foreach ($warehouseReservations as $reservation) {
                        $productId = (int) $reservation->product_id;
                        $quantities[$productId] = ($quantities[$productId] ?? 0.0) + (float) $reservation->quantity;
                    }

                    foreach ($quantities as $productId => $quantity) {
                        if (($ambiguousPostedCoverage[$warehouseId][$productId] ?? 0.0) > 0) {
                            throw new RuntimeException(
                                'Starszy zaksięgowany WZ i aktywna rezerwacja obejmują ten sam produkt, ale WZ nie wskazuje jednoznacznie zamówienia. '
                                .'Automatyczny rozchód został zatrzymany, aby nie odjąć towaru dwa razy.',
                            );
                        }

                        $coveredByPostedWz = (float) ($postedReservationCoverage[$reservationIdentity][$productId] ?? 0.0);
                        $quantities[$productId] = max(0.0, $quantity - $coveredByPostedWz);
                    }

                    $quantities = $this->normalizedWriteOffQuantities($quantities);

                    if ($quantities === []) {
                        continue;
                    }

                    $member = $family->first(
                        fn (ExternalOrder $candidate): bool => (int) $candidate->sales_channel_id === (int) $firstReservation->sales_channel_id
                            && (string) $candidate->external_id === (string) $firstReservation->external_order_id,
                    );

                    $writeOffs[] = $this->ensureCancellationWriteOff(
                        cancellation: $cancellation,
                        sourceKey: 'reservations:'.hash('sha256', (string) $reservationIdentity),
                        warehouseId: $warehouseId,
                        quantities: $quantities,
                        externalReference: (string) ($member?->external_number ?: $member?->external_id),
                        sourceMetadata: [
                            'source' => 'active_stock_reservations',
                            'source_sales_channel_id' => (int) $firstReservation->sales_channel_id,
                            'source_external_order_id' => (string) $firstReservation->external_order_id,
                            'source_reservation_ids' => $warehouseReservations
                                ->pluck('id')
                                ->map(fn (mixed $id): int => (int) $id)
                                ->values()
                                ->all(),
                        ],
                    );
                }
            }

            // Releasing the reservations in the same outer transaction as the
            // RW prevents the stock-sync worker from observing the temporary
            // quantity between the write-off and reservation release.
            $released = 0;

            if (! $restoreStock) {
                foreach ($family as $member) {
                    $reservationResult = $this->reservations->syncForOrder($member->fresh() ?? $member);
                    $released += (int) ($reservationResult['released'] ?? 0);
                }
            }

            return [
                'cancelled_document_ids' => array_values(array_unique($cancelled)),
                'write_off_document_ids' => array_values(array_unique(array_map('intval', $writeOffs))),
                'released_reservation_pairs' => $released,
            ];
        }, 3);
    }

    /**
     * @param  EloquentCollection<int, ExternalOrder>  $family
     * @return EloquentCollection<int, StockReservation>
     */
    private function openReservationsForFamily(EloquentCollection $family): EloquentCollection
    {
        $identities = $family
            ->map(fn (ExternalOrder $member): array => [
                'sales_channel_id' => (int) $member->sales_channel_id,
                'external_order_id' => (string) $member->external_id,
            ])
            ->unique(fn (array $identity): string => $identity['sales_channel_id'].'|'.$identity['external_order_id'])
            ->values();

        if ($identities->isEmpty()) {
            return new EloquentCollection;
        }

        return StockReservation::query()
            ->whereIn('status', ['active', 'waiting'])
            ->where(function ($query) use ($identities): void {
                foreach ($identities as $identity) {
                    $query->orWhere(function ($pair) use ($identity): void {
                        $pair
                            ->where('sales_channel_id', $identity['sales_channel_id'])
                            ->where('external_order_id', $identity['external_order_id']);
                    });
                }
            })
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    private function reservationIdentity(StockReservation $reservation): string
    {
        return implode('|', [
            (int) $reservation->sales_channel_id,
            (string) $reservation->external_order_id,
            (int) $reservation->warehouse_id,
        ]);
    }

    private function warehouseDocumentReservationIdentity(WarehouseDocument $document): ?string
    {
        $salesChannelId = (int) data_get($document->metadata, 'sales_channel_id');
        $externalOrderId = trim((string) data_get($document->metadata, 'external_order_id'));
        $warehouseId = (int) $document->source_warehouse_id;

        if ($salesChannelId <= 0 || $externalOrderId === '' || $warehouseId <= 0) {
            return null;
        }

        return implode('|', [$salesChannelId, $externalOrderId, $warehouseId]);
    }

    private function cancelledWzHadPostedStockMovement(WarehouseDocument $document): bool
    {
        if ($document->posted_at !== null) {
            return true;
        }

        return $document->ledgerEntries()
            ->orderBy('id')
            ->get()
            ->contains(fn ($entry): bool => data_get($entry->metadata, 'source') !== 'warehouse_document_cancelled');
    }

    /** @return array<int, float> */
    private function warehouseDocumentQuantities(WarehouseDocument $document): array
    {
        $quantities = [];

        foreach ($document->lines()->orderBy('id')->lockForUpdate()->get() as $line) {
            $productId = (int) $line->product_id;
            $quantities[$productId] = ($quantities[$productId] ?? 0.0) + (float) $line->quantity;
        }

        return $this->normalizedWriteOffQuantities($quantities);
    }

    /**
     * @param  array<int, float>  $quantities
     * @param  array<string, mixed>  $sourceMetadata
     */
    private function ensureCancellationWriteOff(
        OrderCancellation $cancellation,
        string $sourceKey,
        int $warehouseId,
        array $quantities,
        string $externalReference,
        array $sourceMetadata,
    ): int {
        $quantities = $this->normalizedWriteOffQuantities($quantities);

        if ($quantities === []) {
            throw new RuntimeException('Nie można utworzyć rozchodu anulacji bez dodatnich ilości towaru.');
        }

        $fulfillmentKey = mb_substr(
            'order-cancellation-rw:'.$cancellation->uuid.':'.$sourceKey,
            0,
            191,
        );
        $candidates = WarehouseDocument::withTrashed()
            ->where('order_fulfillment_key', $fulfillmentKey)
            ->lockForUpdate()
            ->get();

        // Reconcile documents created by the pre-contract implementation,
        // which linked the RW only through JSON metadata.
        if (str_starts_with($sourceKey, 'wz:')) {
            $sourceWzId = (int) substr($sourceKey, 3);
            $legacy = WarehouseDocument::withTrashed()
                ->where('type', 'RW')
                ->where('metadata->order_cancellation_uuid', $cancellation->uuid)
                ->where('metadata->source_wz_id', $sourceWzId)
                ->lockForUpdate()
                ->get();
            $candidates = $candidates
                ->concat($legacy)
                ->unique('id')
                ->values();
        }

        if ($candidates->count() > 1) {
            throw new RuntimeException(
                'Znaleziono więcej niż jeden dokument RW dla tego samego źródła anulacji. Wymagana jest ręczna weryfikacja magazynu.',
            );
        }

        /** @var WarehouseDocument|null $writeOff */
        $writeOff = $candidates->first();

        if (! $writeOff instanceof WarehouseDocument) {
            $writeOff = WarehouseDocument::query()->create([
                'number' => $this->documentNumbers->next('RW'),
                'type' => 'RW',
                'status' => 'draft',
                'source_warehouse_id' => $warehouseId,
                'document_date' => now(),
                'external_reference' => mb_substr(trim($externalReference), 0, 255),
                'order_fulfillment_key' => $fulfillmentKey,
                'notes' => 'Rozchód po anulowaniu zamówienia bez przywracania towaru do sprzedaży.',
                'metadata' => array_merge($sourceMetadata, [
                    'order_cancellation_uuid' => (string) $cancellation->uuid,
                    'order_cancellation_id' => (int) $cancellation->id,
                    'stock_disposition' => 'do_not_restore',
                ]),
            ]);
        } elseif ($writeOff->trashed()) {
            throw new RuntimeException(
                "Powiązany dokument RW {$writeOff->number} został usunięty. Wymagana jest ręczna weryfikacja magazynu.",
            );
        }

        if (filled($writeOff->order_fulfillment_key)
            && $writeOff->order_fulfillment_key !== $fulfillmentKey
        ) {
            throw new RuntimeException(
                "Powiązany dokument RW {$writeOff->number} należy do innej operacji magazynowej.",
            );
        }

        $linkedCancellationUuid = trim((string) data_get(
            $writeOff->metadata,
            'order_cancellation_uuid',
        ));

        if ($linkedCancellationUuid !== '' && $linkedCancellationUuid !== (string) $cancellation->uuid) {
            throw new RuntimeException(
                "Powiązany dokument RW {$writeOff->number} należy do innej anulacji.",
            );
        }

        if ($writeOff->type !== 'RW'
            || (int) $writeOff->source_warehouse_id !== $warehouseId
            || ! in_array($writeOff->status, ['draft', 'posted'], true)
        ) {
            throw new RuntimeException(
                "Powiązany dokument {$writeOff->number} nie jest bezpiecznym RW do wznowienia anulacji.",
            );
        }

        $existingQuantities = $this->normalizedWriteOffQuantities(
            $writeOff->lines()
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('product_id')
                ->map(fn ($lines): float => (float) $lines->sum(fn ($line): float => (float) $line->quantity))
                ->mapWithKeys(fn (float $quantity, mixed $productId): array => [(int) $productId => $quantity])
                ->all(),
        );

        if ($existingQuantities === [] && $writeOff->status === 'draft') {
            foreach ($quantities as $productId => $quantity) {
                $writeOff->lines()->create([
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'metadata' => ['source' => 'order_cancellation_no_restock'],
                ]);
            }
        } elseif (! $this->writeOffQuantitiesMatch($existingQuantities, $quantities)) {
            throw new RuntimeException(
                "Powiązany dokument RW {$writeOff->number} ma inne ilości niż źródło anulacji. Wymagana jest ręczna weryfikacja magazynu.",
            );
        }

        $writeOff->update([
            'order_fulfillment_key' => $fulfillmentKey,
            'metadata' => array_merge((array) $writeOff->metadata, $sourceMetadata, [
                'order_cancellation_uuid' => (string) $cancellation->uuid,
                'order_cancellation_id' => (int) $cancellation->id,
                'stock_disposition' => 'do_not_restore',
            ]),
        ]);

        if ($writeOff->status === 'draft') {
            $this->documentPosting->post($writeOff);
            $writeOff->refresh();
        }

        return (int) $writeOff->id;
    }

    /** @param array<int, float|int|string> $quantities @return array<int, float> */
    private function normalizedWriteOffQuantities(array $quantities): array
    {
        $normalized = [];

        foreach ($quantities as $productId => $quantity) {
            $productId = (int) $productId;
            $quantity = round((float) $quantity, 4);

            if ($productId > 0 && $quantity > 0) {
                $normalized[$productId] = $quantity;
            }
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /** @param array<int, float> $left @param array<int, float> $right */
    private function writeOffQuantitiesMatch(array $left, array $right): bool
    {
        if (array_keys($left) !== array_keys($right)) {
            return false;
        }

        foreach ($left as $productId => $quantity) {
            if (abs($quantity - $right[$productId]) > 0.00005) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  EloquentCollection<int, ExternalOrder>  $family
     */
    private function assertCancellable(EloquentCollection $family): void
    {
        $ids = $family->pluck('id');

        if (ReturnCase::query()->whereIn('external_order_id', $ids)->exists()) {
            throw new RuntimeException('Dla tego zamówienia rozpoczęto już obsługę zwrotu. Dokończ zwrot zamiast anulowania zamówienia.');
        }

        if ($family->contains(fn (ExternalOrder $member): bool => $member->fulfillment_status === 'shipped'
            || in_array($member->status, ['completed'], true))
            || PackingTask::query()->whereIn('external_order_id', $ids)->where('status', 'shipped')->exists()
            || ShippingLabel::query()->shipments()->whereIn('external_order_id', $ids)
                ->where(function ($query): void {
                    $query->whereIn('status', ['picked_up', 'delivered'])->orWhereNotNull('picked_up_at');
                })->exists()) {
            throw new RuntimeException('Zamówienie zostało już zrealizowane albo paczka została odebrana przez kuriera. Użyj procesu zwrotu zamiast anulowania.');
        }
    }

    /**
     * @param  EloquentCollection<int, ExternalOrder>  $family
     * @param  array<string, mixed>  $context
     */
    private function assertCancellationPreflight(
        ExternalOrder $root,
        EloquentCollection $family,
        OrderCancellation $cancellation,
        array $context,
    ): void {
        $this->assertCancellable($family);
        $this->orderStatuses->assertCancellationStockDispositionSupported(
            $root,
            (bool) ($context['restore_stock'] ?? true),
            (string) $cancellation->uuid,
        );
    }

    /**
     * @param  EloquentCollection<int, ExternalOrder>  $family
     */
    private function claimCancellation(
        ExternalOrder $root,
        EloquentCollection $family,
        string $reason,
        ?int $requestedBy,
        array $context,
    ): OrderCancellation {
        return DB::transaction(function () use ($root, $family, $reason, $requestedBy, $context): OrderCancellation {
            ExternalOrder::query()->lockForUpdate()->findOrFail($root->id);
            $cancellation = OrderCancellation::query()
                ->where('external_order_id', $root->id)
                ->lockForUpdate()
                ->first();

            if (! $cancellation instanceof OrderCancellation) {
                return OrderCancellation::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'external_order_id' => $root->id,
                    'requested_by' => $requestedBy,
                    'status' => 'requested',
                    'reason' => $reason,
                    'refund_status' => 'pending',
                    'currency' => strtoupper((string) ($root->currency ?: 'PLN')),
                    'started_at' => now(),
                    'metadata' => [
                        'requested_order_id' => $root->id,
                        'source' => $this->normalizedSource($context['source'] ?? null),
                        'context' => [
                            'preserve_packing_problem' => (bool) ($context['preserve_packing_problem'] ?? false),
                            'suppress_default_customer_notification' => (bool) ($context['suppress_default_customer_notification'] ?? false),
                            'restore_stock' => (bool) ($context['restore_stock'] ?? true),
                        ],
                        'family_before' => $family->map(fn (ExternalOrder $member): array => [
                            'id' => $member->id,
                            'external_id' => $member->external_id,
                            'external_number' => $member->external_number,
                            'status' => $member->status,
                            'fulfillment_status' => $member->fulfillment_status,
                        ])->values()->all(),
                    ],
                ]);
            }

            if ($cancellation->status === 'rejected') {
                $metadata = (array) $cancellation->metadata;
                $persistedContext = (array) ($metadata['context'] ?? []);
                $metadata['source'] = $this->normalizedSource($context['source'] ?? null);
                $metadata['context'] = [
                    'preserve_packing_problem' => (bool) ($context['preserve_packing_problem'] ?? false),
                    'suppress_default_customer_notification' => (bool) ($context['suppress_default_customer_notification'] ?? false),
                    // A retry from a screen without this field must not silently
                    // reverse the operator's earlier no-restock decision.
                    'restore_stock' => array_key_exists('restore_stock', $context)
                        ? (bool) $context['restore_stock']
                        : (array_key_exists('restore_stock', $persistedContext)
                            ? (bool) $persistedContext['restore_stock']
                            : true),
                ];
                $cancellation->update([
                    'status' => 'requested',
                    'reason' => $reason,
                    'requested_by' => $requestedBy ?? $cancellation->requested_by,
                    'last_error' => null,
                    'started_at' => now(),
                    'completed_at' => null,
                    'metadata' => $metadata,
                ]);
            }

            return $cancellation->refresh();
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function runStep(OrderCancellation $cancellation, string $name, callable $operation): array
    {
        $step = OrderCancellationStep::query()->firstOrCreate(
            [
                'order_cancellation_id' => $cancellation->id,
                'step' => $name,
            ],
            [
                'status' => 'pending',
                'idempotency_key' => 'order-cancellation:'.$cancellation->uuid.':'.$name,
            ],
        );

        if (in_array($step->status, ['completed', 'attention_required'], true)) {
            return (array) $step->response_payload + ['_step_status' => $step->status];
        }

        if ($step->status === 'unknown') {
            return array_merge((array) $step->response_payload, ['_step_status' => 'unknown']);
        }

        $step->update([
            'status' => 'processing',
            'attempts' => (int) $step->attempts + 1,
            'last_error' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);
        $cancellation->update(['status' => 'processing', 'last_error' => null]);

        try {
            $result = (array) $operation();
            $stepStatus = (string) ($result['_step_status'] ?? 'completed');
            unset($result['_step_status']);
            $step->update([
                'status' => $stepStatus,
                'response_payload' => $result,
                'last_error' => in_array($stepStatus, ['attention_required', 'unknown', 'failed'], true)
                    ? (string) ($result['message'] ?? '')
                    : null,
                'completed_at' => in_array($stepStatus, ['completed', 'attention_required'], true) ? now() : null,
            ]);

            return $result + ['_step_status' => $stepStatus];
        } catch (Throwable $exception) {
            $step->update([
                'status' => 'failed',
                'last_error' => $exception->getMessage(),
            ]);
            $this->markAttentionRequired($cancellation, $exception->getMessage());

            throw $exception;
        }
    }

    private function markAttentionRequired(OrderCancellation $cancellation, string $error): void
    {
        $cancellation->update([
            'status' => 'attention_required',
            'last_error' => $error,
        ]);
    }

    /**
     * @return array{
     *     cancellation_id:int,
     *     cancellation_uuid:string,
     *     reason:string,
     *     requested_by:?int,
     *     context:array{source:string,preserve_packing_problem:bool,suppress_default_customer_notification:bool},
     *     confirmed_now:bool,
     *     note:string,
     *     resolved_manual_required:list<mixed>
     * }
     */
    private function confirmManualShippingWhileLocked(
        ExternalOrder $root,
        ?int $userId,
        string $note,
    ): array {
        $note = mb_substr(trim($note), 0, 500);

        return DB::transaction(function () use ($root, $userId, $note): array {
            ExternalOrder::query()->lockForUpdate()->findOrFail($root->id);
            $cancellation = OrderCancellation::query()
                ->where('external_order_id', $root->id)
                ->lockForUpdate()
                ->first();

            if (! $cancellation instanceof OrderCancellation) {
                throw new RuntimeException('Dla tego zamówienia nie rozpoczęto anulowania.');
            }

            $context = $this->effectiveContext($cancellation, []);
            $base = [
                'cancellation_id' => (int) $cancellation->id,
                'cancellation_uuid' => (string) $cancellation->uuid,
                'reason' => (string) $cancellation->reason,
                'requested_by' => $cancellation->requested_by !== null
                    ? (int) $cancellation->requested_by
                    : null,
                'context' => $context,
                'note' => $note,
            ];

            if ($cancellation->status === 'completed') {
                return $base + [
                    'confirmed_now' => false,
                    'resolved_manual_required' => [],
                ];
            }

            $shippingStep = OrderCancellationStep::query()
                ->where('order_cancellation_id', $cancellation->id)
                ->where('step', 'shipping')
                ->lockForUpdate()
                ->first();

            if (! $shippingStep instanceof OrderCancellationStep) {
                throw new RuntimeException('Anulowanie nie dotarło jeszcze do etapu cofania wysyłki.');
            }

            $response = (array) $shippingStep->response_payload;
            $existingConfirmation = (array) ($response['manual_confirmation'] ?? []);
            $resolved = array_values((array) ($response['resolved_manual_required'] ?? []));

            if ($shippingStep->status === 'completed' && $existingConfirmation !== []) {
                return $base + [
                    'confirmed_now' => false,
                    'resolved_manual_required' => $resolved,
                ];
            }

            $manualRequired = array_values((array) ($response['manual_required'] ?? []));

            if ($shippingStep->status !== 'attention_required' || $manualRequired === []) {
                throw new RuntimeException('Etap wysyłki nie oczekuje na ręczne potwierdzenie cofnięcia przesyłki.');
            }

            $resolved = array_values([...$resolved, ...$manualRequired]);
            $confirmedAt = now();
            $response['manual_required'] = [];
            $response['resolved_manual_required'] = $resolved;
            $response['manual_confirmation'] = [
                'confirmed_by' => $userId,
                'confirmed_at' => $confirmedAt->toISOString(),
                'note' => $note !== '' ? $note : null,
            ];

            $shippingStep->update([
                'status' => 'completed',
                'response_payload' => $response,
                'last_error' => null,
                'completed_at' => $confirmedAt,
            ]);

            OrderCancellationStep::query()
                ->where('order_cancellation_id', $cancellation->id)
                ->where('step', 'preflight')
                ->update([
                    'status' => 'pending',
                    'response_payload' => null,
                    'last_error' => null,
                    'completed_at' => null,
                ]);

            $cancellation->update([
                'status' => 'requested',
                'last_error' => null,
                'completed_at' => null,
            ]);

            return $base + [
                'confirmed_now' => true,
                'resolved_manual_required' => $resolved,
            ];
        }, 3);
    }

    /**
     * @return array{
     *     cancellation_id:int,
     *     cancellation_uuid:string,
     *     customer_payment_id:int,
     *     idempotency_key:string,
     *     previous_cancellation_status:string,
     *     customer_payment_status:string,
     *     reason:string,
     *     requested_by:?int,
     *     context:array{source:string,preserve_packing_problem:bool,suppress_default_customer_notification:bool}
     * }
     */
    private function prepareUnknownRefundReconciliationWhileLocked(ExternalOrder $root): array
    {
        return DB::transaction(function () use ($root): array {
            ExternalOrder::query()->lockForUpdate()->findOrFail($root->id);
            $cancellation = OrderCancellation::query()
                ->where('external_order_id', $root->id)
                ->lockForUpdate()
                ->first();

            if (! $cancellation instanceof OrderCancellation) {
                throw new RuntimeException('Dla tego zamówienia nie rozpoczęto anulowania.');
            }

            if (mb_strtolower((string) $cancellation->refund_status) !== 'unknown') {
                throw new RuntimeException('Zwrot tej anulacji nie ma nieznanego wyniku wymagającego uzgodnienia.');
            }

            $refundStep = OrderCancellationStep::query()
                ->where('order_cancellation_id', $cancellation->id)
                ->where('step', 'refund')
                ->lockForUpdate()
                ->first();

            if (! $refundStep instanceof OrderCancellationStep || $refundStep->status !== 'unknown') {
                throw new RuntimeException('Etap zwrotu nie oczekuje na uzgodnienie nieznanego wyniku.');
            }

            $idempotencyKey = 'order-cancellation:'.$cancellation->uuid;
            $payment = CustomerPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $payment instanceof CustomerPayment) {
                throw new RuntimeException('Brak istniejącej operacji zwrotu. Uzgodnienie zostało zablokowane, aby nie wysłać nowego żądania cashbacku.');
            }

            if ((int) $payment->external_order_id !== (int) $root->id
                || (int) $payment->order_cancellation_id !== (int) $cancellation->id) {
                throw new RuntimeException('Istniejąca operacja zwrotu nie jest powiązana z tą anulacją. Uzgodnienie zostało zablokowane.');
            }

            $paymentStatus = mb_strtolower(trim((string) $payment->status));

            if (! in_array($paymentStatus, ['unknown', 'pending', 'processing'], true)) {
                throw new RuntimeException('Istniejąca operacja zwrotu nie ma bezpiecznego statusu do uzgodnienia bez ponownego wysłania.');
            }

            $previousCancellationStatus = (string) $cancellation->status;
            $refundStep->update(['status' => 'pending']);
            $cancellation->update(['status' => 'requested']);

            return [
                'cancellation_id' => (int) $cancellation->id,
                'cancellation_uuid' => (string) $cancellation->uuid,
                'customer_payment_id' => (int) $payment->id,
                'idempotency_key' => $idempotencyKey,
                'previous_cancellation_status' => $previousCancellationStatus,
                'customer_payment_status' => $paymentStatus,
                'reason' => (string) $cancellation->reason,
                'requested_by' => $cancellation->requested_by !== null
                    ? (int) $cancellation->requested_by
                    : null,
                'context' => $this->effectiveContext($cancellation, []),
            ];
        }, 3);
    }

    /**
     * Context is persisted with the first successful claim. A retry must keep
     * the same packing and notification semantics even when it is initiated
     * from a different screen.
     *
     * @param  array<string, mixed>  $requested
     * @return array{source:string,preserve_packing_problem:bool,suppress_default_customer_notification:bool,restore_stock:bool}
     */
    private function effectiveContext(OrderCancellation $cancellation, array $requested): array
    {
        $metadata = (array) $cancellation->metadata;
        $persisted = (array) ($metadata['context'] ?? []);

        return [
            'source' => $this->normalizedSource($metadata['source'] ?? $requested['source'] ?? null),
            'preserve_packing_problem' => array_key_exists('preserve_packing_problem', $persisted)
                ? (bool) $persisted['preserve_packing_problem']
                : (bool) ($requested['preserve_packing_problem'] ?? false),
            'suppress_default_customer_notification' => array_key_exists('suppress_default_customer_notification', $persisted)
                ? (bool) $persisted['suppress_default_customer_notification']
                : (bool) ($requested['suppress_default_customer_notification'] ?? false),
            'restore_stock' => array_key_exists('restore_stock', $persisted)
                ? (bool) $persisted['restore_stock']
                : (bool) ($requested['restore_stock'] ?? true),
        ];
    }

    private function normalizedSource(mixed $source): string
    {
        $source = mb_strtolower(trim((string) $source));
        $source = preg_replace('/[^a-z0-9_-]+/', '_', $source) ?: '';

        return mb_substr(trim($source, '_'), 0, 64) ?: 'order_edit';
    }

    /**
     * @return array{0:ExternalOrder,1:EloquentCollection<int, ExternalOrder>}
     */
    private function familyFor(ExternalOrder $order): array
    {
        $rootId = (int) ($order->split_root_order_id ?: $order->id);
        $family = ExternalOrder::query()
            ->whereKey($rootId)
            ->orWhere('split_root_order_id', $rootId)
            ->orderBy('id')
            ->get();
        $root = $family->firstWhere('id', $rootId);

        if (! $root instanceof ExternalOrder) {
            throw new RuntimeException('Nie znaleziono głównego zamówienia dla tej rodziny.');
        }

        return [$root, $family];
    }

    /**
     * @param  EloquentCollection<int, ExternalOrder>  $family
     * @return EloquentCollection<int, WarehouseDocument>
     */
    private function wzDocuments(EloquentCollection $family): EloquentCollection
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

    private function originalInvoiceWasUploaded(Invoice $correction): bool
    {
        $originalId = (int) data_get($correction->metadata, 'corrected_invoice_id');

        if ($originalId <= 0) {
            return false;
        }

        return Invoice::query()
            ->whereKey($originalId)
            ->get()
            ->contains(fn (Invoice $original): bool => data_get($original->metadata, 'woocommerce_upload.status') === 'success');
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function serializableRefundResult(array $result): array
    {
        $payment = $result['payment'] ?? null;
        unset($result['payment'], $result['woo_order'], $result['woo_refund']);

        if ($payment instanceof CustomerPayment) {
            $result['payment_id'] = $payment->id;
            $result['amount'] ??= (float) $payment->amount;
            $result['payment_method'] ??= $payment->method;
            $result['woo_refund_id'] ??= $payment->external_transaction_id;
        }

        return $result;
    }
}
