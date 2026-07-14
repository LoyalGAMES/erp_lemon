<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationStep;
use App\Services\Audit\AuditLogService;
use App\Services\Orders\OrderCancellationService;
use App\Services\Orders\OrderMutationLock;
use App\Services\Payments\OrderSettlementService;
use App\Services\Payments\WooCommerceManualRefundSyncService;
use App\Services\Payments\WooCommerceRefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class OrderSettlementController extends Controller
{
    /** @var list<string> */
    private const CANCELLATION_COMPLETION_STEPS = [
        'preflight',
        'hold_fulfillment',
        'shipping',
        'refund',
        'warehouse_documents',
        'inventory_and_packing',
        'invoices',
        'woocommerce_and_local_status',
    ];

    /**
     * Request a partial or full refund through the original WooCommerce
     * payment gateway. The service owns the remote reconciliation token and
     * deliberately never retries an ambiguous payment-changing POST.
     */
    public function refundThroughWoo(
        Request $request,
        ExternalOrder $order,
        OrderMutationLock $orderLock,
        OrderSettlementService $settlements,
        WooCommerceRefundService $refunds,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'operation_id' => ['required', 'uuid'],
            'confirm_refund' => ['accepted'],
        ]);
        $root = $settlements->rootOrder($order);
        $amount = round((float) $validated['amount'], 2);
        $currency = $this->currency($validated['currency']);
        $idempotencyKey = 'order-refund:'.$root->id.':'.$validated['operation_id'];

        try {
            $result = $orderLock->forOrders(
                $settlements->familyOrderIds($root),
                function () use (
                    $root,
                    $settlements,
                    $refunds,
                    $amount,
                    $currency,
                    $idempotencyKey,
                    $validated,
                ): array {
                    $freshOrder = ExternalOrder::query()->findOrFail($root->id);
                    $summary = $settlements->summary($freshOrder);
                    $this->assertCurrency($currency, (string) $summary['currency']);

                    if ($freshOrder->hasCancellationOperation()) {
                        throw new RuntimeException(
                            'Dla zamówienia trwa dedykowany proces anulacji. '
                            .'Zwrot może uruchomić wyłącznie ten proces po bezpiecznym cofnięciu wysyłki i dokumentów.',
                        );
                    }

                    $ambiguous = CustomerPayment::query()
                        ->whereIn('external_order_id', $settlements->familyOrderIds($freshOrder))
                        ->where('direction', 'outgoing')
                        ->whereIn('status', ['pending', 'processing', 'unknown'])
                        ->where(function ($query) use ($idempotencyKey): void {
                            $query
                                ->whereNull('idempotency_key')
                                ->orWhere('idempotency_key', '!=', $idempotencyKey);
                        })
                        ->first();

                    if ($ambiguous instanceof CustomerPayment) {
                        throw new RuntimeException(
                            'Inny zwrot WooCommerce oczekuje na jednoznaczne uzgodnienie. '
                            .'Nie wysłano kolejnego żądania, aby nie zwrócić środków podwójnie.',
                        );
                    }

                    return $refunds->refundOrder(
                        $freshOrder,
                        $amount,
                        (string) $validated['reason'],
                        $idempotencyKey,
                    );
                },
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Nie uruchomiono zwrotu: '.$exception->getMessage());
        }

        try {
            $audit->record('order.refund_woocommerce_requested', $root, null, [
                'status' => $result['status'] ?? 'unknown',
                'amount' => $result['amount'] ?? $amount,
                'currency' => $result['currency'] ?? $currency,
                'woo_refund_id' => $result['woo_refund_id'] ?? null,
            ], [
                'idempotency_key' => $idempotencyKey,
                'reason' => (string) $validated['reason'],
                'message' => $result['message'] ?? null,
            ]);
        } catch (Throwable $exception) {
            // A completed financial call must never look failed solely because
            // the secondary audit write failed; that could encourage a retry.
            report($exception);
        }

        $message = (string) ($result['message'] ?? 'WooCommerce nie zwrócił opisu wyniku.');

        return match ((string) ($result['status'] ?? 'failed')) {
            'submitted' => redirect()->route('orders.show', $root)->with('status', $message),
            'not_required' => redirect()->route('orders.show', $root)->with('status', $message),
            'manual_required' => redirect()->route('orders.show', $root)->with(
                'error',
                $message.' Pieniądze nie zostały potwierdzone jako zwrócone. '
                    .'Po wykonaniu przelewu lub wypłaty poza ERP użyj akcji „Potwierdź wykonany zwrot ręczny”.',
            ),
            'unknown' => redirect()->route('orders.show', $root)->with(
                'error',
                $message.' Nie ponawiaj operacji. Najpierw sprawdź zwrot w WooCommerce i u operatora płatności.',
            ),
            default => redirect()->route('orders.show', $root)->with('error', 'Zwrot nie został wykonany: '.$message),
        };
    }

    /**
     * Register money that an operator already returned outside ERP. This action
     * never talks to a payment gateway and never sends funds itself.
     */
    public function recordManualRefund(
        Request $request,
        ExternalOrder $order,
        OrderMutationLock $orderLock,
        OrderSettlementService $settlements,
        WooCommerceManualRefundSyncService $manualRefundSync,
        OrderCancellationService $cancellations,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'method' => ['required', 'string', 'in:bank_transfer,cash,card,other'],
            'reference' => ['required', 'string', 'max:160'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'operation_id' => ['required', 'uuid'],
            'confirm_completed' => ['accepted'],
        ]);
        $root = $settlements->rootOrder($order);
        $amount = round((float) $validated['amount'], 2);
        $currency = $this->currency($validated['currency']);
        $idempotencyKey = 'manual-order-refund:'.$root->id.':'.$validated['operation_id'];

        try {
            $result = $orderLock->forOrders(
                $settlements->familyOrderIds($root),
                fn (): array => DB::transaction(function () use (
                    $root,
                    $settlements,
                    $validated,
                    $amount,
                    $currency,
                    $idempotencyKey,
                ): array {
                    $freshOrder = ExternalOrder::query()
                        ->lockForUpdate()
                        ->findOrFail($root->id);
                    $existing = CustomerPayment::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof CustomerPayment) {
                        $this->assertSameManualOperation($existing, $freshOrder, $amount, $currency);

                        return [
                            'payment' => $existing,
                            'already_recorded' => true,
                            'resume_cancellation' => $this->cancellationNeedsResume($freshOrder),
                        ];
                    }

                    $summary = $settlements->summary($freshOrder);
                    $this->assertCurrency($currency, (string) $summary['currency']);
                    $activeCancellation = $freshOrder->cancellationOperation();

                    if ($activeCancellation instanceof OrderCancellation) {
                        $shippingStep = $activeCancellation->steps()
                            ->where('step', 'shipping')
                            ->first();

                        if ($shippingStep instanceof OrderCancellationStep
                            && $shippingStep->status !== 'completed') {
                            throw new RuntimeException(
                                'Najpierw potwierdź anulowanie przesyłki i zniszczenie etykiet. '
                                .'Ręczny zwrot nie może ominąć bramki bezpieczeństwa wysyłki.',
                            );
                        }

                        if ($activeCancellation->status !== 'completed'
                            && $activeCancellation->refund_status !== 'manual_required') {
                            throw new RuntimeException(
                                'Najpierw dokończ bezpieczny proces anulacji zamówienia. '
                                .'Ręczne potwierdzenie zwrotu jest dostępne w trakcie anulacji wyłącznie wtedy, gdy krok refundu ma status „manual_required”.',
                            );
                        }
                    }

                    $ambiguous = CustomerPayment::query()
                        ->whereIn('external_order_id', $settlements->familyOrderIds($freshOrder))
                        ->where('direction', 'outgoing')
                        ->whereIn('status', ['pending', 'processing', 'unknown'])
                        ->first();

                    if ($ambiguous instanceof CustomerPayment) {
                        throw new RuntimeException(
                            'Istnieje zwrot o wyniku oczekującym lub nieznanym. '
                            .'Najpierw uzgodnij go z WooCommerce lub operatorem płatności.',
                        );
                    }

                    $availability = $settlements->manualRefundAvailability($freshOrder, $summary);

                    if (! $availability['allowed']) {
                        throw new RuntimeException($availability['reason']);
                    }

                    if ($amount > (float) $availability['maximum'] + 0.005) {
                        throw new RuntimeException(sprintf(
                            'Kwota %.2f %s przekracza bezpieczne saldo ręcznego zwrotu: %.2f %s.',
                            $amount,
                            $currency,
                            (float) $availability['maximum'],
                            $currency,
                        ));
                    }

                    $now = now();
                    $payment = CustomerPayment::query()->create([
                        'external_order_id' => $freshOrder->id,
                        'idempotency_key' => $idempotencyKey,
                        'direction' => 'outgoing',
                        'source' => 'manual',
                        'purpose' => 'manual_order_refund',
                        'method' => (string) $validated['method'],
                        'status' => 'paid',
                        'amount' => $amount,
                        'currency' => $currency,
                        'reference' => trim((string) $validated['reference']),
                        'description' => trim((string) $validated['reason']),
                        'requested_at' => $now,
                        'booked_at' => $now,
                        'paid_at' => $now,
                        'metadata' => [
                            'source' => 'order_settlement',
                            'settlement' => [
                                'operation_id' => (string) $validated['operation_id'],
                                'manual_required' => (bool) $availability['manual_required'],
                                'manual_required_payment_ids' => $availability['payment_ids'],
                                'explicitly_confirmed_as_completed' => true,
                            ],
                            'recorded_by' => [
                                'user_id' => Auth::id(),
                                'name' => Auth::user()?->name,
                            ],
                        ],
                    ]);

                    $resumeCancellation = $this->markManualRequirementResolved(
                        $freshOrder,
                        $settlements,
                        $payment,
                        (array) $availability['payment_ids'],
                    );

                    return [
                        'payment' => $payment,
                        'already_recorded' => false,
                        'resume_cancellation' => $resumeCancellation,
                    ];
                }, 3),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Nie zapisano ręcznego zwrotu: '.$exception->getMessage());
        }

        /** @var CustomerPayment $payment */
        $payment = $result['payment'];
        $cancellationResumeWarning = null;

        if (($result['resume_cancellation'] ?? false) === true) {
            try {
                $activeCancellation = $root->cancellationOperation();

                if ($activeCancellation instanceof OrderCancellation) {
                    $resumeResult = $cancellations->cancel(
                        $root->fresh() ?? $root,
                        (string) $activeCancellation->reason,
                        Auth::id(),
                    );

                    if (($resumeResult['attention_required'] ?? false) === true) {
                        $cancellationResumeWarning = 'Zwrot ręczny zapisano, ale anulowanie nadal wymaga interwencji: '
                            .implode(' | ', (array) ($resumeResult['warnings'] ?? []));
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
                $cancellationResumeWarning = 'Zwrot ręczny zapisano, ale pozostałe kroki anulowania nadal wymagają dokończenia: '.$exception->getMessage();
            }
        }

        // The real-world payout is already committed and the family mutation
        // lock above has been released. Woo accounting is intentionally a
        // follow-up: its failure must never roll back or downgrade this paid
        // CustomerPayment.
        try {
            $syncResult = $manualRefundSync->sync($payment);
            $payment = $syncResult['payment'];
        } catch (Throwable $exception) {
            report($exception);
            $syncResult = [
                'status' => 'unknown',
                'message' => 'Nie udało się uruchomić księgowego uzgodnienia z WooCommerce. Ręczny zwrot pozostaje potwierdzony jako wykonany w ERP.',
                'accounted_amount' => 0.0,
                'remainder_amount' => (float) $payment->amount,
                'woo_refund_id' => null,
            ];
        }

        if (! $result['already_recorded']) {
            try {
                $audit->record('order.manual_refund_recorded', $root, null, [
                    'customer_payment_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'currency' => $payment->currency,
                    'method' => $payment->method,
                    'reference' => $payment->reference,
                    'woocommerce_accounting_sync_status' => $syncResult['status'],
                ], [
                    'idempotency_key' => $idempotencyKey,
                    'money_sent_by_erp' => false,
                    'woocommerce_accounting_sync_message' => $syncResult['message'],
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        try {
            $audit->record('order.manual_refund_woo_accounting_sync', $root, null, [
                'customer_payment_id' => $payment->id,
                'status' => $syncResult['status'],
                'accounted_amount' => $syncResult['accounted_amount'],
                'remainder_amount' => $syncResult['remainder_amount'],
                'woo_refund_id' => $syncResult['woo_refund_id'],
            ], [
                'idempotency_key' => $idempotencyKey,
                'already_recorded' => (bool) $result['already_recorded'],
                'message' => $syncResult['message'],
                'requested_api_refund' => false,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $paymentMessage = $result['already_recorded']
            ? 'Ten wykonany zwrot ręczny był już zarejestrowany. Nie dodano drugiego księgowania ERP.'
            : sprintf(
                'Zarejestrowano wykonany ręcznie zwrot %.2f %s. ERP nie wysłał pieniędzy — zapis potwierdza operację wykonaną wcześniej poza systemem.',
                (float) $payment->amount,
                $payment->currency,
            );
        $syncMessage = 'Synchronizacja księgowa WooCommerce: '.(string) $syncResult['message'];
        $redirect = redirect()->route('orders.show', $root);
        $needsWarning = (string) $syncResult['status'] === 'unknown'
            || (float) $syncResult['remainder_amount'] > 0.005
            || $cancellationResumeWarning !== null;

        if ($needsWarning) {
            $warnings = array_values(array_filter([
                $cancellationResumeWarning,
                $syncMessage,
            ]));

            return $redirect
                ->with('status', $paymentMessage)
                ->with('warning', implode(' ', $warnings));
        }

        return $redirect->with('status', $paymentMessage.' '.$syncMessage);
    }

    /**
     * Reconcile the accounting-only Woo refund created for a manual payout.
     * The service checkpoint decides whether the one allowed POST was never
     * started or whether this action may perform GET-only reconciliation.
     * It never asks a payment gateway to send money.
     */
    public function reconcileManualRefundWooAccounting(
        Request $request,
        ExternalOrder $order,
        CustomerPayment $payment,
        OrderSettlementService $settlements,
        WooCommerceManualRefundSyncService $manualRefundSync,
        AuditLogService $audit,
    ): RedirectResponse {
        $request->validate([
            'confirm_reconciliation' => ['accepted'],
        ]);
        $root = $settlements->rootOrder($order);

        if ((int) $payment->external_order_id !== (int) $root->id
            || ! in_array((int) $payment->external_order_id, $settlements->familyOrderIds($root), true)
            || $payment->direction !== 'outgoing'
            || mb_strtolower((string) $payment->source) !== 'manual'
            || mb_strtolower((string) $payment->purpose) !== 'manual_order_refund') {
            abort(404);
        }

        $previousStatus = mb_strtolower((string) data_get(
            $payment->metadata,
            'woocommerce_manual_refund_sync.status',
            '',
        ));

        if (! in_array($previousStatus, ['unknown', 'processing'], true)) {
            return redirect()
                ->route('orders.show', $root)
                ->with('error', 'To księgowanie WooCommerce nie ma nieznanego wyniku wymagającego uzgodnienia.');
        }

        try {
            $result = $manualRefundSync->sync($payment);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('orders.show', $root)
                ->with(
                    'error',
                    'Nie udało się uzgodnić księgowego wpisu WooCommerce. Ręczny zwrot w ERP pozostaje ważny: '.$exception->getMessage(),
                );
        }

        try {
            $audit->record('order.manual_refund_woo_accounting_reconciled', $root, null, [
                'customer_payment_id' => $payment->id,
                'previous_status' => $previousStatus,
                'status' => $result['status'],
                'accounted_amount' => $result['accounted_amount'],
                'remainder_amount' => $result['remainder_amount'],
                'woo_refund_id' => $result['woo_refund_id'],
            ], [
                'requested_by_user_id' => Auth::id(),
                'message' => $result['message'],
                'money_sent_by_woocommerce' => false,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $message = 'Uzgodnienie księgowe WooCommerce: '.(string) $result['message'];

        if ((string) $result['status'] === 'unknown'
            || (float) $result['remainder_amount'] > 0.005) {
            return redirect()
                ->route('orders.show', $root)
                ->with('warning', $message);
        }

        return redirect()
            ->route('orders.show', $root)
            ->with('status', $message);
    }

    private function assertCurrency(string $requested, string $expected): void
    {
        if ($this->currency($requested) !== $this->currency($expected)) {
            throw new RuntimeException(
                'Waluta formularza nie zgadza się z walutą zamówienia. Odśwież kartę zamówienia.',
            );
        }
    }

    private function assertSameManualOperation(
        CustomerPayment $payment,
        ExternalOrder $order,
        float $amount,
        string $currency,
    ): void {
        if ((int) $payment->external_order_id !== (int) $order->id
            || $payment->direction !== 'outgoing'
            || mb_strtolower((string) $payment->source) !== 'manual'
            || mb_strtolower((string) $payment->purpose) !== 'manual_order_refund'
            || abs((float) $payment->amount - $amount) > 0.005
            || $this->currency($payment->currency) !== $currency) {
            throw new RuntimeException(
                'Identyfikator operacji był już użyty z innymi danymi. Odśwież kartę zamówienia.',
            );
        }
    }

    /**
     * @param  list<int>  $manualRequiredPaymentIds
     */
    private function markManualRequirementResolved(
        ExternalOrder $order,
        OrderSettlementService $settlements,
        CustomerPayment $manualPayment,
        array $manualRequiredPaymentIds,
    ): bool {
        foreach (CustomerPayment::query()->whereKey($manualRequiredPaymentIds)->lockForUpdate()->get() as $requiredPayment) {
            $metadata = (array) $requiredPayment->metadata;
            $metadata['manual_resolution'] = [
                'customer_payment_id' => $manualPayment->id,
                'reference' => $manualPayment->reference,
                'recorded_at' => now()->toISOString(),
                'recorded_by_user_id' => Auth::id(),
            ];
            $requiredPayment->update(['metadata' => $metadata]);
        }

        $remaining = $settlements->manualRefundAvailability($order->fresh() ?? $order);

        if ($remaining['manual_required'] !== true || (float) $remaining['maximum'] > 0.005) {
            return false;
        }

        $cancellation = $order->cancellationOperation();

        if (! $cancellation instanceof OrderCancellation
            || mb_strtolower((string) $cancellation->refund_status) !== 'manual_required') {
            return false;
        }

        /** @var OrderCancellationStep|null $refundStep */
        $refundStep = $cancellation->steps()
            ->where('step', 'refund')
            ->lockForUpdate()
            ->first();

        if ($refundStep instanceof OrderCancellationStep) {
            $response = (array) $refundStep->response_payload;
            $refundStep->update([
                'status' => 'completed',
                'response_payload' => array_merge($response, [
                    'status' => 'manual_completed',
                    'message' => 'Zwrot wykonany ręcznie i potwierdzony w ERP.',
                    'manual_customer_payment_id' => $manualPayment->id,
                    'manual_reference' => $manualPayment->reference,
                ]),
                'last_error' => null,
                'completed_at' => now(),
            ]);
        }

        $steps = $cancellation->steps()->get()->keyBy('step');
        $needsResume = collect(self::CANCELLATION_COMPLETION_STEPS)
            ->contains(function (string $stepName) use ($steps): bool {
                $step = $steps->get($stepName);

                return ! $step instanceof OrderCancellationStep
                    || ! in_array($step->status, ['completed', 'attention_required'], true);
            });
        $attentionSteps = $steps
            ->whereIn('status', ['attention_required', 'unknown', 'failed'])
            ->values();
        $errors = $attentionSteps
            ->map(fn (OrderCancellationStep $step): string => trim((string) ($step->last_error
                ?: data_get($step->response_payload, 'message', ''))))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $cancellation->update([
            'refund_status' => 'manual_completed',
            'payment_method' => $manualPayment->method,
            'status' => $needsResume
                ? 'requested'
                : ($attentionSteps->isEmpty() ? 'completed' : 'attention_required'),
            'last_error' => $errors === [] ? null : implode(' | ', $errors),
            'completed_at' => $needsResume ? null : now(),
        ]);

        return $needsResume;
    }

    private function cancellationNeedsResume(ExternalOrder $order): bool
    {
        $cancellation = $order->cancellationOperation();

        if (! $cancellation instanceof OrderCancellation
            || mb_strtolower((string) $cancellation->refund_status) !== 'manual_completed') {
            return false;
        }

        $steps = $cancellation->steps()->get()->keyBy('step');

        return collect(self::CANCELLATION_COMPLETION_STEPS)
            ->contains(function (string $stepName) use ($steps): bool {
                $step = $steps->get($stepName);

                return ! $step instanceof OrderCancellationStep
                    || ! in_array($step->status, ['completed', 'attention_required'], true);
            });
    }

    private function currency(mixed $value): string
    {
        $currency = mb_strtoupper(trim((string) $value));

        return $currency !== '' ? $currency : 'PLN';
    }
}
