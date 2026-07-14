<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\OrderCancellation;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class WooCommerceRefundService
{
    private const PROTECTED_PAYMENT_STATUSES = [
        'processing',
        'pending',
        'paid',
        'settled',
        'unknown',
        'manual_required',
    ];

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly OrderSettlementService $settlements,
    ) {}

    /**
     * Settle the full confirmed customer balance for a cancellation. The part
     * covered by the original Woo transaction is sent through its gateway;
     * any remaining manual/offline balance is returned as manual_required.
     *
     * @return array<string, mixed>
     */
    public function refund(OrderCancellation $cancellation, string $idempotencyKey): array
    {
        $cancellation->loadMissing('order');
        $order = $cancellation->order;

        if (! $order instanceof ExternalOrder) {
            throw new RuntimeException('Anulacja nie jest powiązana z zamówieniem.');
        }

        $result = $this->refundOrder(
            $order,
            null,
            'Anulowanie zamówienia: '.trim((string) $cancellation->reason),
            $idempotencyKey,
            'order_cancellation',
            $cancellation,
        );

        $status = (string) ($result['status'] ?? 'failed');

        // A cancellation must settle every confirmed customer payment, not
        // only the amount which the original Woo transaction can still return.
        // Unknown and failed gateway outcomes remain untouched: suggesting a
        // manual payout in those states could return the same money twice.
        if (! in_array($status, ['submitted', 'not_required', 'manual_required'], true)) {
            return $result;
        }

        $settlement = is_array($result['settlement'] ?? null)
            ? (array) $result['settlement']
            : $this->settlements->summary($this->settlements->rootOrder($order));
        $balanceBefore = round(max(0, (float) ($settlement['balance'] ?? 0)), 2);
        $newlyRefunded = round(max(0, (float) ($result['newly_refunded_amount'] ?? 0)), 2);
        $remaining = round(max(0, $balanceBefore - $newlyRefunded), 2);

        if ($remaining <= 0) {
            if ($status === 'manual_required') {
                $result['status'] = 'not_required';
                $result['amount'] = 0.0;
                $result['message'] = 'Saldo klienta zostało już w całości rozliczone poza bramką WooCommerce.';
            }

            return $result + [
                'manual_required_amount' => 0.0,
                'balance_before_refund' => $balanceBefore,
            ];
        }

        $gatewayMessage = trim((string) ($result['message'] ?? ''));
        $result['automatic_refund_status'] = $status;
        $result['manual_required_amount'] = $remaining;
        $result['balance_before_refund'] = $balanceBefore;
        $result['status'] = 'manual_required';
        $result['amount'] = $remaining;
        $result['message'] = match ($status) {
            'submitted' => sprintf(
                'Część możliwa przez pierwotną bramkę została zwrócona. Pozostałe %.2f %s pochodzi z płatności poza tą transakcją i wymaga zwrotu ręcznego.',
                $remaining,
                (string) ($result['currency'] ?? $settlement['currency'] ?? $order->currency),
            ),
            'not_required' => sprintf(
                'WooCommerce nie ma transakcji możliwej do automatycznego refundu, ale klient ma potwierdzone saldo %.2f %s. Wykonaj zwrot ręczny i potwierdź go w ERP.',
                $remaining,
                (string) ($result['currency'] ?? $settlement['currency'] ?? $order->currency),
            ),
            default => $gatewayMessage.' '.sprintf(
                'Łączna kwota wymagająca potwierdzonego zwrotu ręcznego: %.2f %s.',
                $remaining,
                (string) ($result['currency'] ?? $settlement['currency'] ?? $order->currency),
            ),
        };

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function refundOrder(
        ExternalOrder $order,
        ?float $amount,
        string $reason,
        string $idempotencyKey,
        string $purpose = 'order_refund',
        ?OrderCancellation $cancellation = null,
    ): array {
        $order = $this->settlements->rootOrder($order);
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 191) {
            throw new RuntimeException('Klucz idempotencji zwrotu musi mieć od 1 do 191 znaków.');
        }

        $token = '[ERP-REFUND:'.$idempotencyKey.']';
        $familyOrderIds = $this->settlements->familyOrderIds($order);
        $existingPayment = CustomerPayment::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingPayment instanceof CustomerPayment
            && ! in_array((int) $existingPayment->external_order_id, $familyOrderIds, true)) {
            return $this->result(
                'failed',
                null,
                'Klucz idempotencji zwrotu jest już używany przez inne zamówienie.',
            );
        }

        $otherAmbiguousPayment = CustomerPayment::query()
            ->whereIn('external_order_id', $familyOrderIds)
            ->where('direction', 'outgoing')
            ->whereIn('status', ['pending', 'processing', 'unknown'])
            ->where(function ($query) use ($idempotencyKey): void {
                $query
                    ->whereNull('idempotency_key')
                    ->orWhere('idempotency_key', '!=', $idempotencyKey);
            })
            ->first();

        if ($otherAmbiguousPayment instanceof CustomerPayment) {
            return $this->result(
                'failed',
                null,
                'Inna wypłata w tej rodzinie zamówienia ma status pending, processing lub unknown. '
                    .'Bieżącego żądania nie wysłano; po uzgodnieniu obcej operacji można je bezpiecznie wznowić.',
            );
        }

        try {
            $integration = $this->integrationFor($order);
            $externalOrderId = trim((string) $order->external_id);

            if ($externalOrderId === '') {
                throw new RuntimeException('Zamówienie nie ma identyfikatora WooCommerce.');
            }

            // Both reads are intentionally fresh. In particular, the refund
            // collection is reconciled before any payment-changing POST.
            $wooOrder = $this->client->order($integration, $externalOrderId);
            $wooRefunds = $this->client->orderRefunds($integration, $externalOrderId);
        } catch (Throwable $exception) {
            if ($existingPayment instanceof CustomerPayment
                && in_array(mb_strtolower((string) $existingPayment->status), self::PROTECTED_PAYMENT_STATUSES, true)) {
                return $this->existingPaymentResult(
                    $existingPayment,
                    'Nie udało się odświeżyć WooCommerce; istniejąca operacja nie została wysłana ponownie. '.$exception->getMessage(),
                );
            }

            return $this->result(
                'failed',
                null,
                'Nie udało się pobrać aktualnego rozliczenia WooCommerce: '.$exception->getMessage(),
            );
        }

        $settlement = $this->settlements->summary($order, $wooOrder, $wooRefunds);
        $woo = (array) $settlement['woo'];
        $remoteRefund = $this->refundWithToken($wooRefunds, $token);

        if ($remoteRefund !== null) {
            $remoteAmount = $this->refundAmount($remoteRefund);
            $remoteGatewayOutcome = $this->remoteRefundOutcome($remoteRefund);
            $preRefundWooCapacity = round((float) ($woo['refundable'] ?? 0) + $remoteAmount, 2);
            $preRefundCashBalance = round(
                (float) ($settlement['balance'] ?? 0)
                    + ($remoteGatewayOutcome === 'submitted' ? $remoteAmount : 0),
                2,
            );
            $expectedAmount = $existingPayment instanceof CustomerPayment
                ? round(abs((float) $existingPayment->amount), 2)
                : round(min(
                    $amount !== null ? max(0, $amount) : $preRefundWooCapacity,
                    $preRefundWooCapacity,
                    max(0, $preRefundCashBalance),
                ), 2);
            $amountMatches = $expectedAmount > 0
                && $remoteAmount > 0
                && abs($expectedAmount - $remoteAmount) <= 0.005;
            $outcome = $amountMatches ? $remoteGatewayOutcome : 'unknown';
            $payment = $this->reconcileRemoteRefund(
                $order,
                $cancellation,
                $idempotencyKey,
                $purpose,
                $reason,
                $token,
                $woo,
                $remoteRefund,
                $outcome,
                $expectedAmount,
            );

            return $this->result(
                $outcome,
                $payment,
                ! $amountMatches
                    ? sprintf(
                        'Znaleziony zwrot z tokenem ma kwotę %.2f %s, a oczekiwano %.2f %s. Wynik wymaga uzgodnienia i nie wolno ponawiać operacji.',
                        $remoteAmount,
                        $settlement['currency'],
                        $expectedAmount,
                        $settlement['currency'],
                    )
                    : match ($outcome) {
                        'submitted' => 'Zwrot był już zapisany w WooCommerce i został uzgodniony bez ponownego wysłania.',
                        'manual_required' => 'WooCommerce ma zapis zwrotu i jednoznacznie wskazuje, że środki nie zostały zwrócone przez bramkę płatności.',
                        default => 'WooCommerce ma zapis zwrotu, ale nie podał, czy bramka zwróciła środki. Wynik wymaga uzgodnienia i nie wolno wykonywać ręcznego cashbacku.',
                    },
                $settlement,
                $wooOrder,
                $remoteRefund,
            );
        }

        if ($existingPayment instanceof CustomerPayment
            && in_array(mb_strtolower((string) $existingPayment->status), self::PROTECTED_PAYMENT_STATUSES, true)) {
            return $this->existingPaymentResult(
                $existingPayment,
                'Istniejąca operacja zwrotu nie została wysłana ponownie.',
                $settlement,
                $wooOrder,
            );
        }

        $refundable = round((float) ($woo['refundable'] ?? 0), 2);
        $cashBalance = round(max(0, (float) ($settlement['balance'] ?? 0)), 2);

        if (($woo['paid'] ?? false) !== true || $refundable <= 0 || $cashBalance <= 0) {
            return $this->result(
                'not_required',
                null,
                match (true) {
                    ($woo['paid'] ?? false) !== true => 'WooCommerce nie wykazuje opłacenia zamówienia; zwrot środków nie jest wymagany.',
                    $cashBalance <= 0 => 'Potwierdzone saldo zamówienia wynosi zero; kolejny zwrot spowodowałby nadpłatę.',
                    default => 'WooCommerce nie wykazuje kwoty pozostałej do zwrotu.',
                },
                $settlement,
                $wooOrder,
            );
        }

        $requestedAmount = round($amount ?? $refundable, 2);
        $refundAmount = round(min($requestedAmount, $refundable, $cashBalance), 2);

        if ($requestedAmount <= 0 || $refundAmount <= 0) {
            return $this->result(
                'not_required',
                null,
                'Kwota zwrotu wynosi zero.',
                $settlement,
                $wooOrder,
            );
        }

        [$payment, $shouldSend] = $this->claimPayment(
            $order,
            $cancellation,
            $idempotencyKey,
            $purpose,
            $reason,
            $token,
            $refundAmount,
            (string) $settlement['currency'],
            $woo,
        );

        if (! $shouldSend) {
            return $this->existingPaymentResult(
                $payment,
                'Istniejąca operacja zwrotu nie została wysłana ponownie.',
                $settlement,
                $wooOrder,
            );
        }

        $payload = [
            'amount' => number_format($refundAmount, 2, '.', ''),
            'reason' => $this->reasonWithToken($reason, $token, $order),
            'api_refund' => true,
            'api_restock' => false,
        ];
        $this->mergeWooMetadata($payment, [
            'request_payload' => $payload,
            'post_started_at' => now()->toISOString(),
        ]);

        try {
            // WooCommerceClient deliberately performs this POST once, without
            // HTTP retry. A connection failure leaves an ambiguous outcome.
            $remoteRefund = $this->client->createOrderRefund(
                $integration,
                $externalOrderId,
                $payload,
            );
        } catch (ConnectionException $exception) {
            $message = 'Połączenie z WooCommerce zostało przerwane podczas wysyłania zwrotu. Wynik wymaga uzgodnienia przed ponowieniem.';
            $payment->update([
                'status' => 'unknown',
                'failed_at' => null,
                'error_message' => $message.' '.$exception->getMessage(),
            ]);
            $this->mergeWooMetadata($payment, [
                'connection_error' => $exception->getMessage(),
                'outcome' => 'unknown',
            ]);

            return $this->result(
                'unknown',
                $payment->refresh(),
                $message,
                $settlement,
                $wooOrder,
            );
        } catch (RuntimeException $exception) {
            $manualRequired = $this->manualRefundRequired($exception->getMessage());
            $unknown = ! $manualRequired
                && $this->ambiguousRefundFailure($exception->getMessage())
                && ! $this->definitiveRefundRejection($exception->getMessage());
            $status = $manualRequired ? 'manual_required' : ($unknown ? 'unknown' : 'failed');
            $message = $unknown
                ? 'WooCommerce zwrócił niejednoznaczny błąd po wysłaniu zwrotu. Sprawdź operatora płatności przed jakąkolwiek kolejną próbą. '.$exception->getMessage()
                : $exception->getMessage();
            $payment->update([
                'status' => $status,
                'failed_at' => $unknown ? null : now(),
                'error_message' => $message,
            ]);
            $this->mergeWooMetadata($payment, [
                'gateway_error' => $exception->getMessage(),
                'outcome' => $status,
            ]);

            return $this->result(
                $status,
                $payment->refresh(),
                $message,
                $settlement,
                $wooOrder,
            );
        } catch (Throwable $exception) {
            $message = 'Nie udało się jednoznacznie potwierdzić wyniku po wysłaniu zwrotu. Sprawdź operatora płatności przed jakąkolwiek kolejną próbą. '.$exception->getMessage();
            $payment->update([
                'status' => 'unknown',
                'failed_at' => null,
                'error_message' => $message,
            ]);
            $this->mergeWooMetadata($payment, [
                'unexpected_error' => $exception->getMessage(),
                'outcome' => 'unknown',
            ]);

            return $this->result(
                'unknown',
                $payment->refresh(),
                $message,
                $settlement,
                $wooOrder,
            );
        }

        $observedRefundAmount = $this->refundAmount($remoteRefund);
        $responseAmountMatches = $observedRefundAmount > 0
            && abs($observedRefundAmount - $refundAmount) <= 0.005;
        $remoteOutcome = $responseAmountMatches
            ? $this->remoteRefundOutcome($remoteRefund)
            : 'unknown';
        $refundId = trim((string) ($remoteRefund['id'] ?? ''));

        if ($remoteOutcome !== 'submitted') {
            $manualRequired = $remoteOutcome === 'manual_required';
            $message = ! $responseAmountMatches
                ? sprintf(
                    'WooCommerce zwrócił kwotę %.2f %s przy oczekiwanej %.2f %s. Wynik jest nieznany — nie ponawiaj operacji ani nie wykonuj ręcznego cashbacku przed uzgodnieniem.',
                    $observedRefundAmount,
                    $settlement['currency'],
                    $refundAmount,
                    $settlement['currency'],
                )
                : ($manualRequired
                    ? 'WooCommerce utworzył zapis zwrotu i jednoznacznie wskazał, że środki nie zostały przekazane przez bramkę. Wymagany jest zwrot ręczny.'
                    : 'WooCommerce utworzył zapis zwrotu, ale nie podał, czy bramka przekazała środki. Wynik jest nieznany — nie wykonuj zwrotu ręcznego przed uzgodnieniem.');
            $payment->update([
                'status' => $remoteOutcome,
                'external_transaction_id' => $refundId !== '' ? $refundId : null,
                'reference' => $refundId !== '' ? $refundId : $payment->reference,
                'failed_at' => $manualRequired ? now() : null,
                'error_message' => $message,
            ]);
            $this->mergeWooMetadata($payment, [
                'response_payload' => $remoteRefund,
                'expected_refund_amount' => $refundAmount,
                'observed_refund_amount' => $observedRefundAmount,
                'amount_matches' => $responseAmountMatches,
                'outcome' => $remoteOutcome,
            ]);

            return $this->result(
                $remoteOutcome,
                $payment->refresh(),
                $message,
                $settlement,
                $wooOrder,
                $remoteRefund,
            );
        }

        $payment->update([
            'status' => 'paid',
            'external_transaction_id' => $refundId !== '' ? $refundId : null,
            'reference' => $refundId !== '' ? $refundId : $payment->reference,
            'booked_at' => now(),
            'paid_at' => now(),
            'failed_at' => null,
            'error_message' => null,
        ]);
        $this->mergeWooMetadata($payment, [
            'response_payload' => $remoteRefund,
            'expected_refund_amount' => $refundAmount,
            'observed_refund_amount' => $observedRefundAmount,
            'amount_matches' => true,
            'outcome' => 'submitted',
        ]);

        return $this->result(
            'submitted',
            $payment->refresh(),
            'Zwrot został przekazany przez WooCommerce do pierwotnej bramki płatności.',
            $settlement,
            $wooOrder,
            $remoteRefund,
            $refundAmount,
        );
    }

    private function integrationFor(ExternalOrder $order): WordpressIntegration
    {
        $order->loadMissing(['wordpressIntegration', 'salesChannel.integrations']);

        if ($order->wordpressIntegration instanceof WordpressIntegration) {
            return $order->wordpressIntegration;
        }

        $integrations = $order->salesChannel?->integrations
            ?->filter(fn (WordpressIntegration $integration): bool => ! $integration->trashed())
            ->values();

        if ($integrations?->count() === 1) {
            return $integrations->first();
        }

        throw new RuntimeException('Nie można jednoznacznie ustalić integracji WooCommerce dla zamówienia.');
    }

    /**
     * @param  list<array<string, mixed>>  $refunds
     * @return array<string, mixed>|null
     */
    private function refundWithToken(array $refunds, string $token): ?array
    {
        $matching = collect($refunds)
            ->filter(fn (mixed $refund): bool => is_array($refund)
                && str_contains((string) ($refund['reason'] ?? ''), $token))
            ->sortByDesc(fn (array $refund): int => (int) ($refund['id'] ?? 0))
            ->first();

        return is_array($matching) ? $matching : null;
    }

    /**
     * @param  array<string, mixed>  $woo
     * @return array{0:CustomerPayment,1:bool}
     */
    private function claimPayment(
        ExternalOrder $order,
        ?OrderCancellation $cancellation,
        string $idempotencyKey,
        string $purpose,
        string $reason,
        string $token,
        float $amount,
        string $currency,
        array $woo,
    ): array {
        return DB::transaction(function () use (
            $order,
            $cancellation,
            $idempotencyKey,
            $purpose,
            $reason,
            $token,
            $amount,
            $currency,
            $woo,
        ): array {
            ExternalOrder::query()->lockForUpdate()->findOrFail($order->id);
            $payment = CustomerPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($payment instanceof CustomerPayment) {
                if (! in_array(
                    (int) $payment->external_order_id,
                    $this->settlements->familyOrderIds($order),
                    true,
                )) {
                    throw new RuntimeException('Klucz idempotencji zwrotu jest już używany przez inne zamówienie.');
                }

                if (in_array(mb_strtolower((string) $payment->status), self::PROTECTED_PAYMENT_STATUSES, true)) {
                    return [$payment, false];
                }

                $payment->update([
                    'external_order_id' => $order->id,
                    'status' => 'processing',
                    'requested_at' => now(),
                    'failed_at' => null,
                    'error_message' => null,
                ]);

                return [$payment->refresh(), true];
            }

            $payment = CustomerPayment::query()->create([
                'external_order_id' => $order->id,
                'order_cancellation_id' => $cancellation?->id,
                'idempotency_key' => $idempotencyKey,
                'direction' => 'outgoing',
                'source' => 'woocommerce',
                'purpose' => mb_substr(trim($purpose) ?: 'order_refund', 0, 40),
                'method' => mb_substr(trim((string) ($woo['payment_method'] ?? '')) ?: 'woocommerce', 0, 40),
                'status' => 'processing',
                'amount' => $amount,
                'currency' => $currency,
                'reference' => $this->nullableString($woo['transaction_id'] ?? null),
                'description' => trim($reason) !== '' ? trim($reason) : 'Zwrot zamówienia '.$order->external_number,
                'requested_at' => now(),
                'metadata' => [
                    'woocommerce' => [
                        'order_id' => (string) $order->external_id,
                        'order_status' => $woo['status'] ?? null,
                        'payment_method' => $woo['payment_method'] ?? null,
                        'payment_method_title' => $woo['payment_method_title'] ?? null,
                        'original_transaction_id' => $woo['transaction_id'] ?? null,
                        'refund_token' => $token,
                    ],
                ],
            ]);

            return [$payment, true];
        });
    }

    /**
     * @param  array<string, mixed>  $woo
     * @param  array<string, mixed>  $remoteRefund
     */
    private function reconcileRemoteRefund(
        ExternalOrder $order,
        ?OrderCancellation $cancellation,
        string $idempotencyKey,
        string $purpose,
        string $reason,
        string $token,
        array $woo,
        array $remoteRefund,
        string $outcome,
        float $expectedAmount,
    ): CustomerPayment {
        return DB::transaction(function () use (
            $order,
            $cancellation,
            $idempotencyKey,
            $purpose,
            $reason,
            $token,
            $woo,
            $remoteRefund,
            $outcome,
            $expectedAmount,
        ): CustomerPayment {
            ExternalOrder::query()->lockForUpdate()->findOrFail($order->id);
            $payment = CustomerPayment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($payment instanceof CustomerPayment && ! in_array(
                (int) $payment->external_order_id,
                $this->settlements->familyOrderIds($order),
                true,
            )) {
                throw new RuntimeException('Klucz idempotencji zwrotu jest już używany przez inne zamówienie.');
            }

            $refundedViaGateway = $outcome === 'submitted';
            $status = match ($outcome) {
                'submitted' => 'paid',
                'manual_required' => 'manual_required',
                default => 'unknown',
            };
            $refundId = $this->nullableString($remoteRefund['id'] ?? null);
            $observedAmount = $this->refundAmount($remoteRefund);
            $amountMatches = $expectedAmount > 0
                && $observedAmount > 0
                && abs($expectedAmount - $observedAmount) <= 0.005;
            $amount = $payment instanceof CustomerPayment
                ? round(abs((float) $payment->amount), 2)
                : ($expectedAmount > 0 ? $expectedAmount : $observedAmount);
            $attributes = [
                'external_order_id' => $order->id,
                'order_cancellation_id' => $cancellation?->id,
                'idempotency_key' => $idempotencyKey,
                'direction' => 'outgoing',
                'source' => 'woocommerce',
                'purpose' => mb_substr(trim($purpose) ?: 'order_refund', 0, 40),
                'method' => mb_substr(trim((string) ($woo['payment_method'] ?? '')) ?: 'woocommerce', 0, 40),
                'status' => $status,
                'amount' => $amount,
                'currency' => $this->currency($remoteRefund['currency'] ?? $order->currency),
                'reference' => $refundId,
                'external_transaction_id' => $refundId,
                'description' => trim($reason) !== '' ? trim($reason) : 'Zwrot zamówienia '.$order->external_number,
                'requested_at' => $payment?->requested_at ?? now(),
                'booked_at' => $refundedViaGateway ? now() : null,
                'paid_at' => $refundedViaGateway ? now() : null,
                'failed_at' => $outcome === 'manual_required' ? now() : null,
                'error_message' => match ($outcome) {
                    'submitted' => null,
                    'manual_required' => 'WooCommerce jednoznacznie wskazuje, że środki nie zostały zwrócone przez bramkę płatności.',
                    'unknown' => ! $amountMatches
                        ? sprintf(
                            'Kwota zwrotu znaleziona po tokenie (%.2f) nie odpowiada oczekiwanej kwocie (%.2f). Wynik wymaga uzgodnienia.',
                            $observedAmount,
                            $expectedAmount,
                        )
                        : 'WooCommerce nie podał, czy środki zostały zwrócone przez bramkę. Wynik wymaga uzgodnienia.',
                    default => 'WooCommerce nie podał, czy środki zostały zwrócone przez bramkę. Wynik wymaga uzgodnienia.',
                },
                'metadata' => array_merge($payment?->metadata ?? [], [
                    'woocommerce' => array_merge(
                        (array) data_get($payment?->metadata, 'woocommerce', []),
                        [
                            'order_id' => (string) $order->external_id,
                            'refund_token' => $token,
                            'response_payload' => $remoteRefund,
                            'expected_refund_amount' => $expectedAmount,
                            'observed_refund_amount' => $observedAmount,
                            'amount_matches' => $amountMatches,
                            'outcome' => $outcome,
                            'reconciled_at' => now()->toISOString(),
                        ],
                    ),
                ]),
            ];

            if ($payment instanceof CustomerPayment) {
                $payment->update($attributes);

                return $payment->refresh();
            }

            return CustomerPayment::query()->create($attributes);
        });
    }

    /** @param array<string, mixed> $values */
    private function mergeWooMetadata(CustomerPayment $payment, array $values): void
    {
        $metadata = $payment->metadata ?? [];
        $metadata['woocommerce'] = array_merge(
            (array) ($metadata['woocommerce'] ?? []),
            $values,
        );
        $payment->update(['metadata' => $metadata]);
    }

    /**
     * @param  array<string, mixed>|null  $settlement
     * @param  array<string, mixed>|null  $wooOrder
     * @return array<string, mixed>
     */
    private function existingPaymentResult(
        CustomerPayment $payment,
        string $message,
        ?array $settlement = null,
        ?array $wooOrder = null,
    ): array {
        $status = match (mb_strtolower((string) $payment->status)) {
            'unknown', 'pending', 'processing' => 'unknown',
            'manual_required' => 'manual_required',
            'failed' => 'failed',
            'paid', 'booked', 'settled' => 'submitted',
            default => 'unknown',
        };

        return $this->result($status, $payment, $message, $settlement, $wooOrder);
    }

    /**
     * @param  array<string, mixed>|null  $settlement
     * @param  array<string, mixed>|null  $wooOrder
     * @param  array<string, mixed>|null  $wooRefund
     * @return array<string, mixed>
     */
    private function result(
        string $status,
        ?CustomerPayment $payment,
        string $message,
        ?array $settlement = null,
        ?array $wooOrder = null,
        ?array $wooRefund = null,
        float $newlyRefundedAmount = 0.0,
    ): array {
        return [
            'status' => $status,
            'payment' => $payment,
            'message' => $message,
            'amount' => $payment instanceof CustomerPayment ? (float) $payment->amount : 0.0,
            'currency' => $payment?->currency ?? ($settlement['currency'] ?? null),
            'refundable' => (float) data_get($settlement, 'woo.refundable', 0),
            'payment_method' => $payment?->method
                ?? $this->nullableString(data_get($settlement, 'woo.payment_method')),
            'woo_refund_id' => $payment?->external_transaction_id
                ?? $this->nullableString($wooRefund['id'] ?? null),
            'newly_refunded_amount' => round(max(0, $newlyRefundedAmount), 2),
            'settlement' => $settlement,
            'woo_order' => $wooOrder,
            'woo_refund' => $wooRefund,
        ];
    }

    private function reasonWithToken(string $reason, string $token, ExternalOrder $order): string
    {
        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? $reason);

        if ($reason === '') {
            $reason = 'Zwrot zamówienia '.$order->external_number;
        }

        return $token.' '.$reason;
    }

    private function manualRefundRequired(string $message): bool
    {
        $message = mb_strtolower($message);

        foreach ([
            'does not support automatic refunds',
            'does not support refunds',
            'payment gateway for this order does not exist',
            'nie obsługuje automatycznych zwrot',
            'nie obsługuje zwrot',
            'bramka płatności dla tego zamówienia nie istnieje',
            'bramka płatności nie istnieje',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function ambiguousRefundFailure(string $message): bool
    {
        return preg_match('/HTTP\s+(?:408|425|429|5\d\d)\b/i', $message) === 1;
    }

    private function definitiveRefundRejection(string $message): bool
    {
        $message = mb_strtolower($message);

        foreach (['odrzucił zwrot', 'zwrot został odrzucony', 'refund was declined', 'refund was rejected'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $refund */
    private function remoteRefundOutcome(array $refund): string
    {
        return match ($refund['refunded_payment'] ?? null) {
            true => 'submitted',
            false => 'manual_required',
            default => 'unknown',
        };
    }

    /** @param array<string, mixed> $refund */
    private function refundAmount(array $refund): float
    {
        return round(abs((float) ($refund['amount'] ?? $refund['total'] ?? 0)), 2);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function currency(mixed $value): string
    {
        $currency = mb_strtoupper(trim((string) $value));

        return $currency !== '' ? $currency : 'PLN';
    }
}
