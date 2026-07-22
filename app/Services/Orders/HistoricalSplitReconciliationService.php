<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\PackingTask;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Models\WarehouseDocument;
use App\Services\Audit\AuditLogService;
use App\Services\Payments\PaymentMethodClassifier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class HistoricalSplitReconciliationService
{
    public function __construct(
        private readonly OrderSplitReversalService $reversal,
        private readonly OrderMutationLock $orderLock,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly PaymentMethodClassifier $paymentMethods,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{
     *     available:bool,
     *     reasons:list<string>,
     *     root:ExternalOrder,
     *     family:EloquentCollection<int,ExternalOrder>,
     *     version:string,
     *     plan:array<string,mixed>,
     *     plan_digest:string,
     *     snapshot:array<string,mixed>|null
     * }
     */
    public function preview(ExternalOrder $order): array
    {
        $current = $this->reversal->availability($order);
        $root = $current['root'];
        $family = $current['family'];
        $lines = ExternalOrderLine::query()
            ->whereIn('external_order_id', $family->pluck('id'))
            ->orderBy('id')
            ->get();
        [$snapshot, $buildReasons, $plan] = $this->buildSnapshot($root, $family, $lines, $current['version']);
        $reasons = $buildReasons;

        if ($snapshot !== null) {
            $simulated = $this->reversal->availabilityAgainstSnapshot($order, $snapshot);
            $reasons = [...$reasons, ...$simulated['reasons']];
        }

        $reasons = array_values(array_unique(array_filter($reasons)));
        $planDigest = HistoricalSplitSnapshot::fingerprint($plan);

        return [
            'available' => $snapshot !== null && $reasons === [],
            'reasons' => $reasons,
            'root' => $root,
            'family' => $family,
            'version' => (string) $current['version'],
            'plan' => $plan,
            'plan_digest' => $planDigest,
            'snapshot' => $snapshot,
        ];
    }

    /** @param array<string,bool> $confirmations */
    public function adopt(
        ExternalOrder $order,
        User $administrator,
        string $expectedFamilyVersion,
        string $expectedPlanDigest,
        string $requestUuid,
        string $typedOrderNumber,
        string $reason,
        array $confirmations,
    ): ExternalOrder {
        if (! $administrator->isAdministrator()) {
            throw new RuntimeException('Tylko administrator może uzgodnić historyczny stan sprzed podziału.');
        }

        if (! Str::isUuid($requestUuid)) {
            throw new RuntimeException('Identyfikator żądania uzgodnienia jest nieprawidłowy.');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new RuntimeException('Powód uzgodnienia musi mieć co najmniej 10 znaków.');
        }

        return $this->orderLock->forOrderFamily($order, function () use (
            $order,
            $administrator,
            $expectedFamilyVersion,
            $expectedPlanDigest,
            $requestUuid,
            $typedOrderNumber,
            $reason,
            $confirmations,
        ): ExternalOrder {
            return DB::transaction(function () use (
                $order,
                $administrator,
                $expectedFamilyVersion,
                $expectedPlanDigest,
                $requestUuid,
                $typedOrderNumber,
                $reason,
                $confirmations,
            ): ExternalOrder {
                $fresh = ExternalOrder::query()->lockForUpdate()->findOrFail($order->id);
                $rootId = (int) ($fresh->split_root_order_id ?: $fresh->id);
                $root = ExternalOrder::query()->lockForUpdate()->findOrFail($rootId);
                $existing = data_get($root->raw_payload, 'sempre_erp_split_original');

                if (HistoricalSplitSnapshot::isVerified(is_array($existing) ? $existing : null)
                    && hash_equals(
                        $requestUuid,
                        (string) data_get($existing, 'legacy_adoption.request_uuid', ''),
                    )) {
                    return $root;
                }

                if (is_array($existing)) {
                    throw new RuntimeException('Stan początkowy tego podziału został już zapisany. Odśwież zamówienie.');
                }

                $preview = $this->preview($fresh);

                if ($expectedFamilyVersion === ''
                    || ! hash_equals((string) $preview['version'], $expectedFamilyVersion)
                    || $expectedPlanDigest === ''
                    || ! hash_equals((string) $preview['plan_digest'], $expectedPlanDigest)) {
                    throw new RuntimeException('Dane rodziny zmieniły się od wyświetlenia podglądu. Odśwież zamówienie i sprawdź plan ponownie.');
                }

                if (! $preview['available'] || ! is_array($preview['snapshot'])) {
                    throw new RuntimeException(implode(' ', $preview['reasons']));
                }

                if (! hash_equals((string) $preview['root']->external_number, trim($typedOrderNumber))) {
                    throw new RuntimeException('Wpisany numer zamówienia nie zgadza się z numerem zamówienia głównego.');
                }

                foreach ([
                    'carrier_not_handed_over',
                    'package_matches_preserved_wz',
                    'duplicate_items_returned',
                    'financial_total_verified',
                ] as $requiredConfirmation) {
                    if (($confirmations[$requiredConfirmation] ?? false) !== true) {
                        throw new RuntimeException('Nie zaznaczono wszystkich wymaganych potwierdzeń uzgodnienia.');
                    }
                }

                $snapshot = $preview['snapshot'];
                $snapshot['legacy_adoption'] = array_merge(
                    (array) ($snapshot['legacy_adoption'] ?? []),
                    [
                        'adopted_at' => now()->toISOString(),
                        'adopted_by_user_id' => (int) $administrator->id,
                        'adopted_by_role' => (string) $administrator->role,
                        'family_version_before' => $expectedFamilyVersion,
                        'plan_digest' => $expectedPlanDigest,
                        'request_uuid' => $requestUuid,
                        'typed_order_number' => trim($typedOrderNumber),
                        'reason' => trim($reason),
                        'confirmations' => $confirmations,
                    ],
                );
                $raw = (array) $root->raw_payload;
                $raw['sempre_erp_split_original'] = $snapshot;
                $root->update(['raw_payload' => $raw]);

                $this->audit->record(
                    'order.split_historical_baseline_adopted',
                    $root,
                    [
                        'root_order_id' => (int) $root->id,
                        'snapshot_present' => false,
                        'family_version' => $expectedFamilyVersion,
                    ],
                    [
                        'root_order_id' => (int) $root->id,
                        'snapshot_version' => HistoricalSplitSnapshot::VERSION,
                        'plan' => $preview['plan'],
                    ],
                    [
                        'request_uuid' => $requestUuid,
                        'plan_digest' => $expectedPlanDigest,
                        'reason' => trim($reason),
                        'typed_order_number' => trim($typedOrderNumber),
                        'confirmations' => $confirmations,
                    ],
                );

                return $root->fresh() ?? $root;
            }, 3);
        });
    }

    /**
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @param  EloquentCollection<int,ExternalOrderLine>  $lines
     * @return array{0:array<string,mixed>|null,1:list<string>,2:array<string,mixed>}
     */
    private function buildSnapshot(
        ExternalOrder $root,
        EloquentCollection $family,
        EloquentCollection $lines,
        string $familyVersion,
    ): array {
        $reasons = [];
        $emptyPlan = [
            'root_order_id' => (int) $root->id,
            'root_order_number' => (string) $root->external_number,
            'family_order_ids' => $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
        ];

        if (is_array(data_get($root->raw_payload, 'sempre_erp_split_original'))) {
            return [null, ['Ten podział ma już zapisany stan początkowy.'], $emptyPlan];
        }

        if ($family->count() <= 1) {
            return [null, ['To zamówienie nie ma aktywnych części do scalenia.'], $emptyPlan];
        }

        $baselineOrder = $family
            ->filter(fn (ExternalOrder $member): bool => (int) $member->split_parent_order_id === (int) $root->id)
            ->sortBy(fn (ExternalOrder $member): string => sprintf(
                '%s:%020d',
                $member->created_at?->format('Y-m-d H:i:s.u') ?? '',
                (int) $member->id,
            ))
            ->first();

        if (! $baselineOrder instanceof ExternalOrder || $baselineOrder->created_at === null) {
            return [null, ['Nie znaleziono pierwszej historycznej części potrzebnej do odtworzenia stanu źródłowego.'], $emptyPlan];
        }

        $baselinePayload = (array) $baselineOrder->raw_payload;

        if (! $this->completeBaselinePayload($root, $baselinePayload)) {
            return [null, ['Pierwsza część nie zawiera pełnego zapisu WooCommerce potrzebnego do uzgodnienia.'], $emptyPlan];
        }

        $total = data_get($baselinePayload, 'total');
        $cashOnDelivery = $this->paymentMethods->isCashOnDelivery($baselineOrder);

        if (! is_numeric($total)) {
            $reasons[] = 'Nie można potwierdzić pierwotnej kwoty zamówienia.';
        }

        $splitAt = CarbonImmutable::instance($baselineOrder->created_at)->startOfSecond();
        $declaredSplitAt = data_get($baselinePayload, 'sempre_erp_split.created_at');

        if (filled($declaredSplitAt)) {
            try {
                if (abs(CarbonImmutable::parse((string) $declaredSplitAt)->diffInSeconds($splitAt, false)) > 1) {
                    $reasons[] = 'Znacznik czasu historycznego podziału nie zgadza się z utworzeniem pierwszej części.';
                }
            } catch (Throwable) {
                $reasons[] = 'Historyczny znacznik czasu podziału jest nieprawidłowy.';
            }
        }

        $mergedLines = $this->mergedLines($root, $lines, $reasons);
        $this->validateBaselineFinancials($baselinePayload, $reasons);
        $packingTasks = $this->baselinePackingTasks($root, $family, $mergedLines, $splitAt, $reasons);
        $labels = ShippingLabel::query()
            ->shipments()
            ->whereIn('external_order_id', $family->pluck('id'))
            ->orderBy('id')
            ->get();

        foreach ($labels as $label) {
            if ($this->sameSecond($label->created_at, $splitAt)) {
                $reasons[] = 'Etykieta #'.$label->id.' powstała dokładnie w sekundzie podziału i nie można jednoznacznie ustalić jej strony granicy.';
            }

            if ($label->created_at?->lt($splitAt) === true
                && $label->generated_at?->gte($splitAt) === true) {
                $reasons[] = 'Etykieta #'.$label->id.' została utworzona przed podziałem, ale wygenerowana po nim.';
            }

            if ($label->created_at?->lt($splitAt) === true
                && (string) $label->status === 'generated'
                && $label->generated_at === null) {
                $reasons[] = 'Wygenerowana etykieta #'.$label->id.' sprzed podziału nie ma wiarygodnego czasu wygenerowania.';
            }

            if ($label->created_at?->lt($splitAt) === true
                && (string) $label->status !== 'generated') {
                $reasons[] = 'Etykieta #'.$label->id.' sprzed podziału nie jest w jednoznacznym stanie wygenerowanym.';
            }
        }

        $preservedLabels = $labels
            ->filter(fn (ShippingLabel $label): bool => $label->created_at?->lt($splitAt) === true
                && (string) $label->status !== 'cancelled')
            ->values();

        foreach ($preservedLabels as $label) {
            if ((int) $label->external_order_id !== (int) $root->id) {
                $reasons[] = 'Aktywna etykieta sprzed podziału nie jest przypisana do zamówienia głównego.';
            }

            if ($label->hasCourierPickupEvidence()) {
                $reasons[] = 'Etykieta sprzed podziału zawiera dowód odbioru przesyłki przez kuriera.';
            }

            $recordedCodAmount = collect([
                data_get($label->response_payload, 'financial.requested_cod_amount'),
                data_get($label->response_payload, 'generation.request.cod_amount'),
            ])->first(fn (mixed $value): bool => is_numeric($value));

            if ($cashOnDelivery && is_numeric($total) && is_numeric($recordedCodAmount)
                && abs((float) $recordedCodAmount - (float) $total) > 0.009) {
                $reasons[] = 'Kwota COD zapisana przy etykiecie sprzed podziału nie zgadza się z pierwotną kwotą zamówienia.';
            }
        }

        if ($preservedLabels->count() > 1) {
            $reasons[] = 'Przed podziałem istniała więcej niż jedna aktywna etykieta. Wymagana jest indywidualna weryfikacja przewoźnika.';
        }

        $documents = $this->documentsForFamily($family);

        foreach ($documents as $document) {
            if ($this->sameSecond($document->created_at, $splitAt)) {
                $reasons[] = "Dokument {$document->number} powstał dokładnie w sekundzie podziału i nie można jednoznacznie ustalić jego strony granicy.";
            }

            if ($document->created_at?->lt($splitAt) === true
                && $document->posted_at?->gte($splitAt) === true) {
                $reasons[] = "Dokument {$document->number} utworzono przed podziałem, ale zaksięgowano po nim.";
            }

            if ($document->created_at?->lt($splitAt) === true
                && (string) $document->status === 'posted'
                && $document->posted_at === null) {
                $reasons[] = "Zaksięgowany dokument {$document->number} sprzed podziału nie ma wiarygodnego czasu księgowania.";
            }

            if ($document->created_at?->lt($splitAt) === true
                && (string) $document->status === 'cancelled') {
                $reasons[] = "Dokument {$document->number} sprzed podziału jest anulowany i nie można go bezpiecznie odtworzyć.";
            }
        }

        $preservedDocuments = $documents
            ->filter(fn (WarehouseDocument $document): bool => $document->created_at?->lt($splitAt) === true
                && (string) $document->status !== 'cancelled')
            ->values();

        if ($preservedDocuments->count() > 1) {
            $reasons[] = 'Przed podziałem istniał więcej niż jeden aktywny dokument WZ. Nie można jednoznacznie ustalić stanu bazowego.';
        }

        foreach ($preservedDocuments as $document) {
            if ((string) $document->status !== 'posted') {
                $reasons[] = "Dokument {$document->number} sprzed podziału nie jest zaksięgowany.";

                continue;
            }

            $this->validatePostedWz($document, $reasons);
        }

        $this->validatePreservedWarehouseCoverage(
            $preservedDocuments,
            $mergedLines,
            $packingTasks,
            $reasons,
        );

        $reversibleDocuments = $documents
            ->filter(fn (WarehouseDocument $document): bool => $document->created_at?->gte($splitAt) === true)
            ->values();
        $balanceDeltas = [];

        foreach ($reversibleDocuments as $document) {
            if (! in_array((string) $document->status, ['draft', 'posted', 'cancelled'], true)) {
                $reasons[] = "Dokument {$document->number} ma status, którego nie można odwrócić.";

                continue;
            }

            match ((string) $document->status) {
                'draft' => $this->validateDraftWz($document, $reasons),
                'posted' => $this->validatePostedWz($document, $reasons),
                'cancelled' => $this->validateCancelledWz($document, $reasons),
                default => null,
            };

            if ((string) $document->status !== 'posted') {
                continue;
            }

            foreach ($document->ledgerEntries as $entry) {
                if (data_get($entry->metadata, 'source') === 'warehouse_document_cancelled') {
                    continue;
                }

                $key = ((int) $entry->warehouse_id).':'.((int) $entry->product_id);
                $balanceDeltas[$key] = ($balanceDeltas[$key] ?? 0.0) - (float) $entry->quantity_change;
            }
        }

        $balancePreview = collect($balanceDeltas)->map(function (float $delta, string $key): array {
            [$warehouseId, $productId] = array_map('intval', explode(':', $key, 2));
            $balance = StockBalance::query()
                ->with('product')
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->first();

            return [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'sku' => (string) ($balance?->product?->sku ?? ''),
                'quantity_on_hand_before' => (string) ($balance?->quantity_on_hand ?? 0),
                'change' => (string) $delta,
                'quantity_on_hand_after' => (string) ((float) ($balance?->quantity_on_hand ?? 0) + $delta),
            ];
        })->values()->all();

        $fulfillmentStatus = $this->baselineFulfillmentStatus(
            $packingTasks,
            $preservedDocuments,
            $preservedLabels,
            $reasons,
        );
        $shippingDecisionExists = array_key_exists('sempre_erp_shipping_decision', $baselinePayload);
        $snapshot = [
            'version' => HistoricalSplitSnapshot::VERSION,
            'captured_at' => $splitAt->toISOString(),
            'status' => (string) ($baselinePayload['status'] ?? $root->status),
            'fulfillment_status' => $fulfillmentStatus,
            'total_gross' => is_numeric($total) ? number_format((float) $total, 2, '.', '') : null,
            'order' => [
                'sales_channel_id' => (int) $root->sales_channel_id,
                'customer_id' => $root->customer_id,
                'customer_external_account_id' => $root->customer_external_account_id,
                'wordpress_integration_id' => $root->wordpress_integration_id,
                'customer_match_method' => $root->customer_match_method,
                'external_id' => $root->external_id,
                'external_number' => $root->external_number,
                'status' => (string) ($baselinePayload['status'] ?? $root->status),
                'fulfillment_status' => $fulfillmentStatus,
                'currency' => $root->currency,
                'total_gross' => is_numeric($total) ? number_format((float) $total, 2, '.', '') : null,
                'billing_data' => $baselineOrder->billing_data ?: $root->billing_data,
                'shipping_data' => $baselineOrder->shipping_data ?: $root->shipping_data,
                'external_created_at' => $baselineOrder->getRawOriginal('external_created_at')
                    ?: $root->getRawOriginal('external_created_at'),
                'external_updated_at' => $baselineOrder->getRawOriginal('external_updated_at')
                    ?: $root->getRawOriginal('external_updated_at'),
            ],
            'shipping_decision_exists' => $shippingDecisionExists,
            'shipping_decision' => $shippingDecisionExists
                ? $baselinePayload['sempre_erp_shipping_decision']
                : null,
            'raw_payload' => $baselinePayload,
            'operational' => [
                'label_generation_attempts' => (int) $root->label_generation_attempts,
                'label_generation_next_at' => $root->label_generation_next_at?->toISOString(),
                'label_generation_last_error' => $root->label_generation_last_error,
                'woo_shipped_sync_status' => $root->woo_shipped_sync_status,
                'woo_shipped_sync_attempts' => (int) $root->woo_shipped_sync_attempts,
                'woo_shipped_sync_next_at' => $root->woo_shipped_sync_next_at?->toISOString(),
                'woo_shipped_sync_error' => $root->woo_shipped_sync_error,
            ],
            'source_reflected_order_quantities' => [],
            'lines' => $mergedLines,
            'packing_tasks' => $packingTasks,
            'preserved_artifacts' => [
                'shipping_labels' => $preservedLabels->map(fn (ShippingLabel $label): array => [
                    'id' => (int) $label->id,
                    'external_order_id' => (int) $label->external_order_id,
                    'provider' => (string) $label->provider,
                    'label_number' => (string) $label->label_number,
                    'tracking_number' => (string) $label->tracking_number,
                    'status' => (string) $label->status,
                    'created_at' => $label->created_at?->toISOString(),
                    'fingerprint' => HistoricalSplitSnapshot::shippingLabelFingerprint($label),
                ])->values()->all(),
                'warehouse_documents' => $preservedDocuments->map(fn (WarehouseDocument $document): array => [
                    'id' => (int) $document->id,
                    'number' => (string) $document->number,
                    'status' => (string) $document->status,
                    'created_at' => $document->created_at?->toISOString(),
                    'posted_at' => $document->posted_at?->toISOString(),
                    'fingerprint' => HistoricalSplitSnapshot::warehouseDocumentFingerprint($document),
                ])->values()->all(),
            ],
            'reversed_artifacts' => [
                'packing_tasks' => PackingTask::query()
                    ->whereIn('external_order_id', $family->pluck('id'))
                    ->whereNotIn('id', collect($packingTasks)->pluck('original_task_id'))
                    ->orderBy('id')
                    ->get()
                    ->map(fn (PackingTask $task): array => [
                        'id' => (int) $task->id,
                        'fingerprint' => HistoricalSplitSnapshot::packingTaskFingerprint($task),
                    ])->values()->all(),
                'shipping_labels' => $labels
                    ->filter(fn (ShippingLabel $label): bool => $label->created_at?->gte($splitAt) === true)
                    ->map(fn (ShippingLabel $label): array => [
                        'id' => (int) $label->id,
                        'fingerprint' => HistoricalSplitSnapshot::shippingLabelFingerprint($label),
                        'cancelled_fingerprint' => HistoricalSplitSnapshot::shippingLabelFingerprintForStatus(
                            $label,
                            'cancelled',
                        ),
                    ])->values()->all(),
                'warehouse_documents' => $reversibleDocuments
                    ->map(fn (WarehouseDocument $document): array => [
                        'id' => (int) $document->id,
                        'fingerprint' => HistoricalSplitSnapshot::warehouseDocumentFingerprint($document),
                    ])->values()->all(),
            ],
            'legacy_adoption' => [
                'type' => 'verified_historical_split',
                'root_order_id' => (int) $root->id,
                'family_order_ids' => $family->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                'family_version_before' => $familyVersion,
                'source_order_id' => (int) $baselineOrder->id,
                'source' => 'earliest_direct_child_woo_payload',
                'warehouse_verification' => [
                    'reversible_wz_ids' => $reversibleDocuments
                        ->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
                    'expected_balance_deltas' => $balancePreview,
                ],
            ],
        ];
        $plan = [
            ...$emptyPlan,
            'family_order_numbers' => $family->pluck('external_number')->map(fn (mixed $number): string => (string) $number)->values()->all(),
            'split_at' => $splitAt->toISOString(),
            'restored_total_gross' => $snapshot['total_gross'],
            'cash_on_delivery' => $cashOnDelivery,
            'restored_cod_amount' => $cashOnDelivery ? $snapshot['total_gross'] : null,
            'restored_status' => $snapshot['status'],
            'restored_fulfillment_status' => $snapshot['fulfillment_status'],
            'restored_lines' => collect($mergedLines)->map(fn (array $line): array => [
                'canonical_external_line_id' => $line['canonical_external_line_id'],
                'sku' => $line['sku'],
                'quantity' => $line['quantity'],
            ])->values()->all(),
            'preserve_task_ids' => collect($packingTasks)->pluck('original_task_id')->values()->all(),
            'preserve_label_ids' => $preservedLabels->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'preserve_label_numbers' => $preservedLabels->map(fn (ShippingLabel $label): string => (string) $label->trackingIdentifier())->values()->all(),
            'preserve_wz_ids' => $preservedDocuments->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'preserve_wz_numbers' => $preservedDocuments->pluck('number')->values()->all(),
            'preserved_artifact_fingerprints' => [
                'packing_tasks' => collect($packingTasks)->mapWithKeys(fn (array $task): array => [
                    (string) $task['original_task_id'] => (string) $task['fingerprint'],
                ])->all(),
                'shipping_labels' => $preservedLabels->mapWithKeys(fn (ShippingLabel $label): array => [
                    (string) $label->id => HistoricalSplitSnapshot::shippingLabelFingerprint($label),
                ])->all(),
                'warehouse_documents' => $preservedDocuments->mapWithKeys(fn (WarehouseDocument $document): array => [
                    (string) $document->id => HistoricalSplitSnapshot::warehouseDocumentFingerprint($document),
                ])->all(),
            ],
            'reverse_task_ids' => PackingTask::query()
                ->whereIn('external_order_id', $family->pluck('id'))
                ->whereNotIn('id', collect($packingTasks)->pluck('original_task_id'))
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'reverse_label_ids' => $labels
                ->filter(fn (ShippingLabel $label): bool => $label->created_at?->gte($splitAt) === true)
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'reverse_label_numbers' => $labels
                ->filter(fn (ShippingLabel $label): bool => $label->created_at?->gte($splitAt) === true)
                ->map(fn (ShippingLabel $label): string => (string) $label->trackingIdentifier())->values()->all(),
            'reverse_wz_ids' => $reversibleDocuments->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'reverse_wz_numbers' => $reversibleDocuments->pluck('number')->values()->all(),
            'reversed_artifact_fingerprints' => $snapshot['reversed_artifacts'],
            'archive_child_order_ids' => $family->where('id', '!=', $root->id)
                ->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all(),
            'balance_changes' => $balancePreview,
        ];

        return [$reasons === [] ? $snapshot : null, array_values(array_unique($reasons)), $plan];
    }

    /** @param array<string,mixed> $payload */
    private function completeBaselinePayload(ExternalOrder $root, array $payload): bool
    {
        $payloadId = trim((string) ($payload['id'] ?? ''));
        $payloadNumber = trim((string) ($payload['number'] ?? ''));
        $identityMatches = $payloadId !== ''
            ? $payloadId === trim((string) $root->external_id)
            : ($payloadNumber !== '' && $payloadNumber === trim((string) $root->external_number));

        return $identityMatches
            && is_numeric($payload['total'] ?? null)
            && filled($payload['status'] ?? null)
            && is_array($payload['line_items'] ?? null)
            && (blank($payload['currency'] ?? null)
                || strtoupper((string) $payload['currency']) === strtoupper((string) $root->currency));
    }

    /**
     * @param  EloquentCollection<int,ExternalOrderLine>  $lines
     * @param  list<string>  $reasons
     * @return list<array<string,mixed>>
     */
    private function mergedLines(ExternalOrder $root, EloquentCollection $lines, array &$reasons): array
    {
        $groups = $lines->groupBy(function (ExternalOrderLine $line) use (&$reasons): string {
            $canonical = $this->canonicalExternalLineId($line);

            if ($canonical === null) {
                $reasons[] = 'Jedna z pozycji nie ma kanonicznego identyfikatora WooCommerce.';

                return 'missing-'.$line->id;
            }

            return $canonical;
        });

        return $groups->map(function (Collection $group, string $canonical) use ($root, &$reasons): array {
            $representative = $group->firstWhere('external_order_id', $root->id) ?? $group->first();
            $signatures = $group->map(fn (ExternalOrderLine $line): string => HistoricalSplitSnapshot::fingerprint([
                'product_id' => $line->product_id,
                'sku' => (string) $line->sku,
                'unit_net_price' => (string) $line->unit_net_price,
                'unit_gross_price' => (string) $line->unit_gross_price,
                'vat_rate' => (string) $line->vat_rate,
            ]))->unique();

            if ($signatures->count() !== 1) {
                $reasons[] = "Pozycja {$canonical} ma w częściach różne produkty, ceny albo VAT.";
            }

            $raw = (array) $representative->raw_payload;
            unset($raw['sempre_erp_split'], $raw['sempre_erp_source_quantity'], $raw['sempre_erp_split_quantity']);
            $quantity = (float) $group->sum(fn (ExternalOrderLine $line): float => (float) $line->quantity);
            $raw['id'] = $canonical;
            $raw['quantity'] = $quantity;

            return [
                'product_id' => $representative->product_id,
                'external_line_id' => $canonical,
                'canonical_external_line_id' => $canonical,
                'sku' => $representative->sku,
                'name' => $representative->name,
                'quantity' => (string) $quantity,
                'unit_net_price' => $representative->unit_net_price !== null ? (string) $representative->unit_net_price : null,
                'unit_gross_price' => $representative->unit_gross_price !== null ? (string) $representative->unit_gross_price : null,
                'vat_rate' => $representative->vat_rate !== null ? (string) $representative->vat_rate : null,
                'raw_payload' => $raw,
            ];
        })->values()->all();
    }

    /**
     * @param  EloquentCollection<int,ExternalOrder>  $family
     * @param  list<array<string,mixed>>  $mergedLines
     * @param  list<string>  $reasons
     * @return list<array<string,mixed>>
     */
    private function baselinePackingTasks(
        ExternalOrder $root,
        EloquentCollection $family,
        array $mergedLines,
        CarbonImmutable $splitAt,
        array &$reasons,
    ): array {
        $tasks = PackingTask::query()
            ->whereIn('external_order_id', $family->pluck('id'))
            ->orderBy('id')
            ->get();

        if ($tasks->contains(fn (PackingTask $task): bool => $this->sameSecond($task->created_at, $splitAt))) {
            $reasons[] = 'Zadanie pakowania powstało dokładnie w sekundzie podziału i nie można jednoznacznie ustalić jego strony granicy.';
        }
        $preSplitChildren = $tasks->filter(fn (PackingTask $task): bool => (int) $task->external_order_id !== (int) $root->id
            && $task->created_at?->lt($splitAt) === true);

        if ($preSplitChildren->isNotEmpty()) {
            $reasons[] = 'Zadanie sprzed podziału jest przypisane do zamówienia częściowego.';
        }

        $baseline = [];
        $seenCanonical = [];

        foreach ($tasks->filter(fn (PackingTask $task): bool => (int) $task->external_order_id === (int) $root->id
            && $task->created_at?->lt($splitAt) === true) as $task) {
            if (! in_array((string) $task->status, ['open', 'picked', 'packed'], true)) {
                $reasons[] = "Zadanie pakowania #{$task->id} sprzed podziału ma nieobsługiwany status {$task->status}.";

                continue;
            }

            $canonical = $this->canonicalTaskLineId($task, $mergedLines);

            if ($canonical === null) {
                $reasons[] = "Nie można powiązać zadania pakowania #{$task->id} z pierwotną pozycją.";

                continue;
            }

            if (isset($seenCanonical[$canonical])) {
                $reasons[] = "Dla pozycji {$canonical} istnieje więcej niż jedno zadanie bazowe.";

                continue;
            }

            $seenCanonical[$canonical] = true;
            $mergedLine = collect($mergedLines)->first(
                fn (array $line): bool => (string) $line['canonical_external_line_id'] === $canonical,
            );
            $required = (float) $task->quantity_required;
            $picked = (float) $task->quantity_picked;
            $pickedBefore = $task->picked_at?->lt($splitAt) === true;
            $packedBefore = $task->packed_at?->lt($splitAt) === true;

            if (! is_array($mergedLine)
                || abs($required - (float) ($mergedLine['quantity'] ?? 0)) > 0.00001
                || ($task->product_id !== null
                    && (int) $task->product_id !== (int) ($mergedLine['product_id'] ?? 0))
                || (filled($task->sku)
                    && (string) $task->sku !== (string) ($mergedLine['sku'] ?? ''))) {
                $reasons[] = "Zadanie pakowania #{$task->id} nie odpowiada ilości albo produktowi pierwotnej pozycji {$canonical}.";
            }

            if ((string) $task->status === 'open'
                && ($picked < 0 || $picked >= $required || $task->picked_at !== null || $task->packed_at !== null)) {
                $reasons[] = "Stan otwartego zadania #{$task->id} nie jest spójny.";
            } elseif ((string) $task->status === 'picked'
                && (abs($picked - $required) > 0.00001 || ! $pickedBefore || $task->packed_at !== null)) {
                $reasons[] = "Nie można potwierdzić stanu zadania #{$task->id} dokładnie sprzed podziału.";
            } elseif ((string) $task->status === 'packed'
                && (abs($picked - $required) > 0.00001 || ! $pickedBefore || ! $packedBefore)) {
                $reasons[] = "Nie można potwierdzić zapakowania zadania #{$task->id} przed podziałem.";
            }

            $baseline[] = [
                'original_task_id' => (int) $task->id,
                'canonical_external_line_id' => $canonical,
                'external_line_id' => $canonical,
                'product_id' => $task->product_id !== null ? (int) $task->product_id : null,
                'sku' => $task->sku,
                'quantity_required' => (string) $task->quantity_required,
                'quantity_picked' => (string) $task->quantity_picked,
                'status' => (string) $task->status,
                'courier' => $task->courier,
                'size_label' => $task->size_label,
                'order_date' => $task->order_date?->toISOString(),
                'picked_at' => $task->picked_at?->toISOString(),
                'packed_at' => $task->packed_at?->toISOString(),
                'metadata' => (array) $task->metadata,
                'fingerprint' => HistoricalSplitSnapshot::packingTaskFingerprint($task),
            ];
        }

        if ($baseline !== []) {
            $expectedCanonical = collect($mergedLines)
                ->pluck('canonical_external_line_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->sort()->values()->all();
            $taskCanonical = collect(array_keys($seenCanonical))
                ->map(fn (mixed $id): string => (string) $id)
                ->sort()->values()->all();

            if ($expectedCanonical !== $taskCanonical) {
                $reasons[] = 'Zadania pakowania sprzed podziału nie pokrywają dokładnie wszystkich pierwotnych pozycji.';
            }
        }

        return $baseline;
    }

    /** @param array<string,mixed> $payload @param list<string> $reasons */
    private function validateBaselineFinancials(array $payload, array &$reasons): void
    {
        if (! is_numeric($payload['total'] ?? null)) {
            $reasons[] = 'Nie można potwierdzić pierwotnej kwoty zamówienia.';

            return;
        }

        $gross = 0.0;
        $tax = 0.0;

        foreach (['line_items' => 'pozycji', 'fee_lines' => 'opłaty'] as $key => $label) {
            foreach ((array) ($payload[$key] ?? []) as $item) {
                if (! is_array($item)
                    || ! is_numeric($item['total'] ?? null)
                    || (array_key_exists('total_tax', $item) && ! is_numeric($item['total_tax']))) {
                    $reasons[] = "Historyczny zapis {$label} nie zawiera pełnych kwot potrzebnych do kontroli sumy.";

                    return;
                }

                $itemTax = (float) ($item['total_tax'] ?? 0);
                $gross += (float) $item['total'] + $itemTax;
                $tax += $itemTax;
            }
        }

        if (array_key_exists('discount_total', $payload) || array_key_exists('discount_tax', $payload)) {
            $subtotal = 0.0;
            $subtotalTax = 0.0;
            $lineTotal = 0.0;
            $lineTax = 0.0;

            foreach ((array) ($payload['line_items'] ?? []) as $item) {
                if (! is_array($item)
                    || ! is_numeric($item['subtotal'] ?? null)
                    || ! is_numeric($item['total'] ?? null)
                    || (array_key_exists('subtotal_tax', $item) && ! is_numeric($item['subtotal_tax']))
                    || (array_key_exists('total_tax', $item) && ! is_numeric($item['total_tax']))) {
                    $reasons[] = 'Historyczny zapis rabatu nie zawiera pełnych kwot pozycji.';

                    return;
                }

                $subtotal += (float) $item['subtotal'];
                $subtotalTax += (float) ($item['subtotal_tax'] ?? 0);
                $lineTotal += (float) $item['total'];
                $lineTax += (float) ($item['total_tax'] ?? 0);
            }

            if (array_key_exists('discount_total', $payload)
                && (! is_numeric($payload['discount_total'])
                    || abs(
                        (int) round((float) $payload['discount_total'] * 100)
                        - (int) round(($subtotal - $lineTotal) * 100),
                    ) > 1)) {
                $reasons[] = 'Rabat netto w historycznym zapisie nie zgadza się z pozycjami zamówienia.';
            }

            if (array_key_exists('discount_tax', $payload)
                && (! is_numeric($payload['discount_tax'])
                    || abs(
                        (int) round((float) $payload['discount_tax'] * 100)
                        - (int) round(($subtotalTax - $lineTax) * 100),
                    ) > 1)) {
                $reasons[] = 'Podatek rabatu w historycznym zapisie nie zgadza się z pozycjami zamówienia.';
            }
        }

        $shippingLines = (array) ($payload['shipping_lines'] ?? []);
        $shippingNet = 0.0;
        $shippingTaxTotal = 0.0;

        if ($shippingLines !== []) {
            foreach ($shippingLines as $shippingLine) {
                if (! is_array($shippingLine)
                    || ! is_numeric($shippingLine['total'] ?? null)
                    || (array_key_exists('total_tax', $shippingLine) && ! is_numeric($shippingLine['total_tax']))) {
                    $reasons[] = 'Historyczny zapis wysyłki nie zawiera pełnych kwot potrzebnych do kontroli sumy.';

                    return;
                }

                $shippingTax = (float) ($shippingLine['total_tax'] ?? 0);
                $shippingNet += (float) $shippingLine['total'];
                $shippingTaxTotal += $shippingTax;
                $gross += (float) $shippingLine['total'] + $shippingTax;
                $tax += $shippingTax;
            }

            if ((array_key_exists('shipping_total', $payload)
                    && (! is_numeric($payload['shipping_total'])
                        || abs((int) round((float) $payload['shipping_total'] * 100) - (int) round($shippingNet * 100)) > 1))
                || (array_key_exists('shipping_tax', $payload)
                    && (! is_numeric($payload['shipping_tax'])
                        || abs((int) round((float) $payload['shipping_tax'] * 100) - (int) round($shippingTaxTotal * 100)) > 1))) {
                $reasons[] = 'Łączna kwota wysyłki nie zgadza się z historycznymi liniami wysyłki.';
            }
        } else {
            foreach (['shipping_total', 'shipping_tax'] as $key) {
                if (array_key_exists($key, $payload) && ! is_numeric($payload[$key])) {
                    $reasons[] = 'Historyczny zapis wysyłki ma nieprawidłową kwotę.';

                    return;
                }
            }

            $shippingTax = (float) ($payload['shipping_tax'] ?? 0);
            $gross += (float) ($payload['shipping_total'] ?? 0) + $shippingTax;
            $tax += $shippingTax;
        }

        $expectedCents = (int) round((float) $payload['total'] * 100);
        $calculatedCents = (int) round($gross * 100);

        if (abs($expectedCents - $calculatedCents) > 1) {
            $reasons[] = sprintf(
                'Kwota źródłowa %.2f nie zgadza się z sumą pozycji, podatku, wysyłki i opłat %.2f.',
                $expectedCents / 100,
                $calculatedCents / 100,
            );
        }

        if (array_key_exists('total_tax', $payload) && is_numeric($payload['total_tax'])) {
            $expectedTaxCents = (int) round((float) $payload['total_tax'] * 100);
            $calculatedTaxCents = (int) round($tax * 100);

            if (abs($expectedTaxCents - $calculatedTaxCents) > 1) {
                $reasons[] = 'Łączny podatek w historycznym zapisie nie zgadza się z podatkiem pozycji, wysyłki i opłat.';
            }
        }
    }

    /**
     * @param  EloquentCollection<int,WarehouseDocument>  $documents
     * @param  list<array<string,mixed>>  $mergedLines
     * @param  list<array<string,mixed>>  $packingTasks
     * @param  list<string>  $reasons
     */
    private function validatePreservedWarehouseCoverage(
        EloquentCollection $documents,
        array $mergedLines,
        array $packingTasks,
        array &$reasons,
    ): void {
        $hasPackedTasks = collect($packingTasks)->contains(
            fn (array $task): bool => (string) ($task['status'] ?? '') === 'packed',
        );

        if ($documents->isEmpty()) {
            if ($hasPackedTasks) {
                $reasons[] = 'Zapakowane zadania sprzed podziału nie mają zachowywanego, zaksięgowanego dokumentu WZ.';
            }

            return;
        }

        $expected = [];

        foreach ($mergedLines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);

            if ($productId <= 0 || ! is_numeric($line['quantity'] ?? null)) {
                $reasons[] = 'Nie można powiązać wszystkich pierwotnych pozycji z produktami dokumentu WZ.';

                return;
            }

            $expected[$productId] = ($expected[$productId] ?? 0.0) + (float) $line['quantity'];
        }

        $actual = [];

        foreach ($documents as $document) {
            foreach ($document->lines as $line) {
                $actual[(int) $line->product_id] = ($actual[(int) $line->product_id] ?? 0.0)
                    + (float) $line->quantity;
            }
        }

        ksort($expected);
        ksort($actual);

        if (array_keys($expected) !== array_keys($actual)
            || collect($expected)->contains(
                fn (float $quantity, int $productId): bool => abs($quantity - (float) ($actual[$productId] ?? 0)) > 0.00001,
            )) {
            $reasons[] = 'Zachowywany dokument WZ nie pokrywa dokładnie produktów i ilości pierwotnego zamówienia.';
        }
    }

    /** @param list<array<string,mixed>> $mergedLines */
    private function canonicalTaskLineId(PackingTask $task, array $mergedLines): ?string
    {
        $externalLineId = trim((string) $task->external_line_id);

        if ($externalLineId !== '') {
            do {
                $previous = $externalLineId;
                $externalLineId = (string) preg_replace('/-S\d+$/', '', $externalLineId);
            } while ($externalLineId !== $previous);

            if (collect($mergedLines)->contains(fn (array $line): bool => (string) $line['canonical_external_line_id'] === $externalLineId)) {
                return $externalLineId;
            }
        }

        $matches = collect($mergedLines)->filter(fn (array $line): bool => ($task->product_id === null
                || (int) ($line['product_id'] ?? 0) === (int) $task->product_id)
            && (blank($task->sku) || (string) ($line['sku'] ?? '') === (string) $task->sku));

        return $matches->count() === 1
            ? (string) $matches->first()['canonical_external_line_id']
            : null;
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @param  EloquentCollection<int,WarehouseDocument>  $preservedDocuments
     * @param  EloquentCollection<int,ShippingLabel>  $preservedLabels
     * @param  list<string>  $reasons
     */
    private function baselineFulfillmentStatus(
        array $tasks,
        EloquentCollection $preservedDocuments,
        EloquentCollection $preservedLabels,
        array &$reasons,
    ): ?string {
        $statuses = collect($tasks)->pluck('status');

        if ($statuses->isEmpty()) {
            if ($preservedDocuments->isEmpty() && $preservedLabels->isEmpty()) {
                return null;
            }

            if ($preservedDocuments->count() === 1
                && $preservedLabels->count() === 1
                && (string) $preservedDocuments->first()?->status === 'posted'
                && (string) $preservedLabels->first()?->status === 'generated') {
                return 'awaiting_courier';
            }

            $reasons[] = 'Brak zadań pakowania sprzed podziału, a zachowane artefakty nie pozwalają jednoznacznie odtworzyć etapu pakowania.';

            return null;
        }

        return match (true) {
            $statuses->every(fn (mixed $status): bool => $status === 'packed') => 'awaiting_courier',
            ! $statuses->contains('open') && $statuses->every(fn (mixed $status): bool => $status === 'picked') => 'ready_to_pack',
            $statuses->contains('open') => 'picking',
            default => null,
        };
    }

    /** @param EloquentCollection<int,ExternalOrder> $family @return EloquentCollection<int,WarehouseDocument> */
    private function documentsForFamily(EloquentCollection $family): EloquentCollection
    {
        $ids = $family->flatMap(fn (ExternalOrder $member) => $this->fulfillmentStatus
            ->wzDocumentsForOrder($member)->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()->unique()->values();

        return WarehouseDocument::query()
            ->with(['lines.product', 'ledgerEntries'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();
    }

    /** @param list<string> $reasons */
    private function validatePostedWz(WarehouseDocument $document, array &$reasons): void
    {
        $document->loadMissing(['lines.product', 'ledgerEntries']);

        if (mb_strtoupper((string) $document->type) !== 'WZ'
            || $document->source_warehouse_id === null
            || $document->destination_warehouse_id !== null) {
            $reasons[] = "Dokument {$document->number} nie ma prawidłowej topologii wydania WZ.";

            return;
        }

        $cancellations = $document->ledgerEntries->filter(
            fn (StockLedgerEntry $entry): bool => data_get($entry->metadata, 'source') === 'warehouse_document_cancelled',
        );

        if ($cancellations->isNotEmpty()) {
            $reasons[] = "Dokument {$document->number} ma już częściowe lub pełne ruchy anulujące.";

            return;
        }

        if ($document->lines->isEmpty() || $document->ledgerEntries->isEmpty()) {
            $reasons[] = "Dokument {$document->number} nie ma kompletnego zapisu pozycji i ruchów magazynowych.";

            return;
        }

        foreach ($document->lines as $line) {
            $entries = $document->ledgerEntries->filter(fn (StockLedgerEntry $entry): bool => (int) $entry->warehouse_document_line_id === (int) $line->id
                && (int) $entry->product_id === (int) $line->product_id
                && (int) $entry->warehouse_id === (int) $document->source_warehouse_id);
            $change = (float) $entries->sum('quantity_change');

            if ($entries->isEmpty() || abs($change + (float) $line->quantity) > 0.00001) {
                $reasons[] = "Ruch magazynowy dokumentu {$document->number} nie zgadza się z jego pozycją {$line->product?->sku}.";
            }
        }

        $lineProductIds = $document->lines->mapWithKeys(fn ($line): array => [
            (int) $line->id => (int) $line->product_id,
        ]);

        if ($document->ledgerEntries->contains(function (StockLedgerEntry $entry) use ($document, $lineProductIds): bool {
            $lineId = (int) $entry->warehouse_document_line_id;

            return ! $lineProductIds->has($lineId)
                || (int) $entry->product_id !== (int) $lineProductIds->get($lineId)
                || (int) $entry->warehouse_id !== (int) $document->source_warehouse_id;
        })) {
            $reasons[] = "Dokument {$document->number} zawiera dodatkowy ruch, którego nie można jednoznacznie odwrócić.";
        }
    }

    /** @param list<string> $reasons */
    private function validateDraftWz(WarehouseDocument $document, array &$reasons): void
    {
        $document->loadMissing('ledgerEntries');

        if ($document->posted_at !== null || $document->ledgerEntries->isNotEmpty()) {
            $reasons[] = "Szkic dokumentu {$document->number} zawiera ruch albo czas księgowania i nie można go bezpiecznie cofnąć.";
        }
    }

    /** @param list<string> $reasons */
    private function validateCancelledWz(WarehouseDocument $document, array &$reasons): void
    {
        $document->loadMissing(['lines', 'ledgerEntries']);
        $entries = $document->ledgerEntries;

        if ($entries->isEmpty()) {
            if ($document->posted_at !== null) {
                $reasons[] = "Anulowany dokument {$document->number} ma czas księgowania, ale nie ma kompletnej pary ruchów magazynowych.";
            }

            return;
        }

        $lineProductIds = $document->lines->mapWithKeys(fn ($line): array => [
            (int) $line->id => (int) $line->product_id,
        ]);
        $hasInvalidEntry = $document->lines->isEmpty()
            || $entries->contains(function (StockLedgerEntry $entry) use ($document, $lineProductIds): bool {
                $lineId = (int) $entry->warehouse_document_line_id;

                return ! $lineProductIds->has($lineId)
                    || (int) $entry->product_id !== (int) $lineProductIds->get($lineId)
                    || (int) $entry->warehouse_id !== (int) $document->source_warehouse_id;
            });

        if ($hasInvalidEntry) {
            $reasons[] = "Anulowany dokument {$document->number} zawiera ruch niezgodny z jego pozycjami.";

            return;
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
        $completePair = $original->isNotEmpty()
            && $cancellations->isNotEmpty()
            && $original->keys()->sort()->values()->all() === $cancellations->keys()->sort()->values()->all()
            && $original->every(fn (float $quantity, string $key): bool => abs(
                $quantity + (float) $cancellations->get($key, 0),
            ) <= 0.00001);

        if (! $completePair) {
            $reasons[] = "Anulowany dokument {$document->number} nie ma kompletnej, wzajemnie znoszącej się pary ruchów magazynowych.";
        }
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

    private function sameSecond(?CarbonInterface $eventAt, CarbonInterface $cutoff): bool
    {
        return $eventAt instanceof CarbonInterface
            && CarbonImmutable::instance($eventAt)->startOfSecond()->equalTo(
                CarbonImmutable::instance($cutoff)->startOfSecond(),
            );
    }
}
