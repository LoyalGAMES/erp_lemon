<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\WordpressIntegration;
use App\Services\Orders\OrderMutationLock;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Reflect a refund which was already paid outside ERP in WooCommerce's
 * accounting. This service never sends money: `api_refund` is always false.
 *
 * A refund POST is deliberately attempted at most once. Its checkpoint is
 * persisted on the manual CustomerPayment before the request leaves ERP. Any
 * later call only reconciles WooCommerce by the stable token.
 */
final class WooCommerceManualRefundSyncService
{
    private const METADATA_KEY = 'woocommerce_manual_refund_sync';

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly OrderSettlementService $settlements,
        private readonly OrderMutationLock $orderLock,
    ) {}

    /**
     * @return array{status:string,message:string,accounted_amount:float,remainder_amount:float,woo_refund_id:?string,payment:CustomerPayment}
     */
    public function sync(CustomerPayment $manualPayment): array
    {
        $manualPayment->loadMissing('order');
        $order = $manualPayment->order;

        if (! $order instanceof ExternalOrder) {
            throw new RuntimeException('Ręczny zwrot nie jest powiązany z zamówieniem.');
        }

        $root = $this->settlements->rootOrder($order);

        return $this->orderLock->forOrders(
            $this->settlements->familyOrderIds($root),
            fn (): array => $this->syncLocked((int) $manualPayment->id, $root),
        );
    }

    /**
     * @return array{status:string,message:string,accounted_amount:float,remainder_amount:float,woo_refund_id:?string,payment:CustomerPayment}
     */
    private function syncLocked(int $paymentId, ExternalOrder $root): array
    {
        $payment = CustomerPayment::query()->findOrFail($paymentId);
        $this->assertManualPayment($payment, $root);

        $amount = round(abs((float) $payment->amount), 2);
        $currency = $this->currency($payment->currency);
        $idempotencyKey = trim((string) $payment->idempotency_key);
        $token = '[ERP-MANUAL-REFUND:'.$idempotencyKey.']';
        $existingSync = (array) data_get($payment->metadata, self::METADATA_KEY, []);
        $postWasCheckpointed = trim((string) ($existingSync['post_started_at'] ?? '')) !== '';

        try {
            $integration = $this->integrationFor($root);
            $externalOrderId = trim((string) $root->external_id);

            if ($externalOrderId === '') {
                throw new RuntimeException('Zamówienie nie ma identyfikatora WooCommerce.');
            }

            $wooOrder = $this->client->order($integration, $externalOrderId);
            $wooRefunds = $this->client->orderRefunds($integration, $externalOrderId);
        } catch (Throwable $exception) {
            $message = $postWasCheckpointed
                ? 'Nie udało się uzgodnić księgowania w WooCommerce po wcześniejszym wysłaniu. Nie ponowiono POST; sprawdź rekord zwrotu po tokenie.'
                : 'Nie udało się pobrać aktualnego rozliczenia WooCommerce. Ręczny zwrot pozostaje potwierdzony w ERP, ale księgowanie Woo wymaga sprawdzenia.';

            $payment = $this->mergeSyncMetadata($payment, [
                'status' => 'unknown',
                'token' => $token,
                'woo_order_id' => (string) $root->external_id,
                'requested_manual_amount' => $amount,
                'currency' => $currency,
                'last_checked_at' => now()->toISOString(),
                'last_error' => $exception->getMessage(),
                'message' => $message,
            ]);

            return $this->result('unknown', $message, 0.0, $amount, null, $payment);
        }

        $linkedRefunds = $this->linkedManualRefunds($payment, $root, $wooRefunds);
        $linkedIds = $linkedRefunds
            ->map(fn (array $refund): string => trim((string) ($refund['id'] ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $linkedAmount = round(min(
            $amount,
            (float) $linkedRefunds->sum(fn (array $refund): float => $this->refundAmount($refund)),
        ), 2);
        $tokenRefund = $this->refundWithToken($wooRefunds, $token);

        if ($tokenRefund !== null) {
            return $this->reconcileTokenRefund(
                $payment,
                $root,
                $token,
                $tokenRefund,
                $linkedIds,
                $linkedAmount,
                $amount,
                $currency,
                $existingSync,
            );
        }

        if ($postWasCheckpointed) {
            $accounted = round(min($amount, $linkedAmount), 2);
            $remainder = round(max(0, $amount - $accounted), 2);
            $message = 'W WooCommerce nie znaleziono jeszcze rekordu z tokenem wcześniej wysłanego księgowania. Nie wykonano drugiego POST; wynik wymaga ręcznego uzgodnienia.';
            $payment = $this->mergeSyncMetadata($payment, [
                'status' => 'unknown',
                'token' => $token,
                'linked_woo_refund_ids' => $linkedIds,
                'linked_accounted_amount' => $linkedAmount,
                'accounted_amount' => $accounted,
                'unaccounted_remainder' => $remainder,
                'last_checked_at' => now()->toISOString(),
                'last_error' => 'Brak rekordu WooCommerce z tokenem po checkpointowanym POST.',
                'message' => $message,
            ]);

            return $this->result('unknown', $message, $accounted, $remainder, null, $payment);
        }

        $settlement = $this->settlements->summary($root, $wooOrder, $wooRefunds);
        $wooCapacity = round(max(0, (float) data_get($settlement, 'woo.refundable', 0)), 2);
        $notYetAccounted = round(max(0, $amount - $linkedAmount), 2);
        $plannedAmount = round(min($notYetAccounted, $wooCapacity), 2);

        if ($plannedAmount <= 0) {
            $accounted = round(min($amount, $linkedAmount), 2);
            $remainder = round(max(0, $amount - $accounted), 2);
            $message = $linkedAmount > 0
                ? 'WooCommerce ma już powiązany ręczny zapis zwrotu. Nie utworzono duplikatu.'
                : 'WooCommerce nie ma już księgowej kwoty dostępnej do zwrotu. Nie utworzono rekordu ponad wartość zamówienia.';

            if ($remainder > 0) {
                $message .= sprintf(
                    ' Pozostałe %.2f %s jest zapisane w ERP jako rzeczywiście wypłacone, ale nie może zwiększyć sumy refundów Woo ponad wartość zamówienia.',
                    $remainder,
                    $currency,
                );
            }

            $payment = $this->mergeSyncMetadata($payment, [
                'status' => 'skipped',
                'token' => $token,
                'woo_order_id' => (string) $root->external_id,
                'requested_manual_amount' => $amount,
                'currency' => $currency,
                'linked_woo_refund_ids' => $linkedIds,
                'linked_accounted_amount' => $linkedAmount,
                'accounting_capacity_before' => $wooCapacity,
                'accounted_amount' => $accounted,
                'unaccounted_remainder' => $remainder,
                'last_checked_at' => now()->toISOString(),
                'last_error' => null,
                'warning' => $remainder > 0 ? 'Kwota przekraczająca pojemność księgową Woo pozostała wyłącznie w rozliczeniu ERP.' : null,
                'message' => $message,
            ]);

            return $this->result(
                'skipped',
                $message,
                $accounted,
                $remainder,
                $linkedIds[0] ?? null,
                $payment,
            );
        }

        $payload = [
            'amount' => number_format($plannedAmount, 2, '.', ''),
            'reason' => $this->reasonWithToken($payment, $token),
            'api_refund' => false,
            'api_restock' => false,
        ];

        // This durable checkpoint is written before the one allowed POST.
        $payment = $this->mergeSyncMetadata($payment, [
            'status' => 'processing',
            'token' => $token,
            'woo_order_id' => (string) $root->external_id,
            'requested_manual_amount' => $amount,
            'currency' => $currency,
            'linked_woo_refund_ids' => $linkedIds,
            'linked_accounted_amount' => $linkedAmount,
            'accounting_capacity_before' => $wooCapacity,
            'planned_accounting_amount' => $plannedAmount,
            'request_payload' => $payload,
            'post_started_at' => now()->toISOString(),
            'last_checked_at' => now()->toISOString(),
            'last_error' => null,
            'message' => 'Wysłano jednokrotne żądanie utworzenia księgowego rekordu zwrotu w WooCommerce.',
        ]);

        try {
            $remoteRefund = $this->client->createOrderRefund(
                $integration,
                (string) $root->external_id,
                $payload,
            );
        } catch (Throwable $exception) {
            $accounted = round(min($amount, $linkedAmount), 2);
            $remainder = round(max(0, $amount - $accounted), 2);
            $message = 'Wynik księgowego POST do WooCommerce jest nieznany. Ręczna wypłata pozostaje opłacona w ERP; nie ponawiaj POST, tylko uzgodnij rekord po tokenie.';
            $payment = $this->mergeSyncMetadata($payment, [
                'status' => 'unknown',
                'accounted_amount' => $accounted,
                'unaccounted_remainder' => $remainder,
                'last_checked_at' => now()->toISOString(),
                'last_error' => $exception->getMessage(),
                'message' => $message,
            ]);

            return $this->result('unknown', $message, $accounted, $remainder, null, $payment);
        }

        return $this->reconcileTokenRefund(
            $payment,
            $root,
            $token,
            $remoteRefund,
            $linkedIds,
            $linkedAmount,
            $amount,
            $currency,
            (array) data_get($payment->metadata, self::METADATA_KEY, []),
        );
    }

    /**
     * @param  array<string, mixed>  $refund
     * @param  list<string>  $linkedIds
     * @param  array<string, mixed>  $syncMetadata
     * @return array{status:string,message:string,accounted_amount:float,remainder_amount:float,woo_refund_id:?string,payment:CustomerPayment}
     */
    private function reconcileTokenRefund(
        CustomerPayment $payment,
        ExternalOrder $root,
        string $token,
        array $refund,
        array $linkedIds,
        float $linkedAmount,
        float $manualAmount,
        string $currency,
        array $syncMetadata,
    ): array {
        $refundId = trim((string) ($refund['id'] ?? ''));
        $refundAmount = $this->refundAmount($refund);
        $expectedAmount = round((float) ($syncMetadata['planned_accounting_amount'] ?? min(
            max(0, $manualAmount - $linkedAmount),
            $refundAmount,
        )), 2);
        $amountMatches = $refundAmount > 0
            && $expectedAmount > 0
            && abs($refundAmount - $expectedAmount) <= 0.005;
        $isAccountingOnly = ($refund['refunded_payment'] ?? null) === false;
        $success = $refundId !== '' && $amountMatches && $isAccountingOnly;
        $accounted = round(min(
            $manualAmount,
            $linkedAmount + ($success ? $refundAmount : 0),
        ), 2);
        $remainder = round(max(0, $manualAmount - $accounted), 2);

        if (! $success) {
            $message = ($refund['refunded_payment'] ?? null) === true
                ? 'WooCommerce oznaczył rekord jako zwrócony przez bramkę mimo żądania api_refund=false. Sprawdź ryzyko podwójnej wypłaty; nie ponawiaj operacji.'
                : 'Rekord WooCommerce znaleziony po tokenie ma niezgodną kwotę albo nie potwierdza trybu księgowego. Nie ponawiaj operacji; wynik wymaga uzgodnienia.';
            $payment = $this->mergeSyncMetadata($payment, [
                'status' => 'unknown',
                'token' => $token,
                'woo_order_id' => (string) $root->external_id,
                'linked_woo_refund_ids' => $linkedIds,
                'linked_accounted_amount' => $linkedAmount,
                'woo_refund_id' => $refundId !== '' ? $refundId : null,
                'observed_accounting_amount' => $refundAmount,
                'amount_matches' => $amountMatches,
                'accounting_only' => $isAccountingOnly,
                'accounted_amount' => $accounted,
                'unaccounted_remainder' => $remainder,
                'response_payload' => $refund,
                'last_checked_at' => now()->toISOString(),
                'last_error' => $message,
                'message' => $message,
            ]);

            return $this->result(
                'unknown',
                $message,
                $accounted,
                $remainder,
                $refundId !== '' ? $refundId : null,
                $payment,
            );
        }

        $message = 'Ręczny cashback został odzwierciedlony w WooCommerce jako zapis księgowy bez ponownego wysyłania pieniędzy.';

        if ($remainder > 0) {
            $message .= sprintf(
                ' %.2f %s przekracza księgową pojemność zamówienia Woo i pozostaje udokumentowane wyłącznie w ERP.',
                $remainder,
                $currency,
            );
        }

        $payment = $this->mergeSyncMetadata($payment, [
            'status' => 'success',
            'token' => $token,
            'woo_order_id' => (string) $root->external_id,
            'linked_woo_refund_ids' => $linkedIds,
            'linked_accounted_amount' => $linkedAmount,
            'woo_refund_id' => $refundId,
            'observed_accounting_amount' => $refundAmount,
            'amount_matches' => true,
            'accounting_only' => true,
            'accounted_amount' => $accounted,
            'unaccounted_remainder' => $remainder,
            'response_payload' => $refund,
            'reconciled_at' => now()->toISOString(),
            'last_checked_at' => now()->toISOString(),
            'last_error' => null,
            'warning' => $remainder > 0 ? 'Część wypłaty wykracza poza wartość możliwą do zaksięgowania w WooCommerce.' : null,
            'message' => $message,
        ]);

        return $this->result('success', $message, $accounted, $remainder, $refundId, $payment);
    }

    /**
     * @param  list<array<string, mixed>>  $refunds
     * @return Collection<int, array<string, mixed>>
     */
    private function linkedManualRefunds(
        CustomerPayment $manualPayment,
        ExternalOrder $root,
        array $refunds,
    ): Collection {
        $requiredPaymentIds = collect((array) data_get(
            $manualPayment->metadata,
            'settlement.manual_required_payment_ids',
            [],
        ))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $familyIds = $this->settlements->familyOrderIds($root);
        $referenceIds = CustomerPayment::query()
            ->whereIn('external_order_id', $familyIds)
            ->where('direction', 'outgoing')
            ->where('source', 'woocommerce')
            ->where(function ($query) use ($requiredPaymentIds, $manualPayment): void {
                if ($requiredPaymentIds->isNotEmpty()) {
                    $query->whereIn('id', $requiredPaymentIds->all());
                } else {
                    $query->whereRaw('1 = 0');
                }

                $query->orWhere('metadata->manual_resolution->customer_payment_id', $manualPayment->id);
            })
            ->pluck('external_transaction_id')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        $cancellationRefundId = trim((string) $root->cancellation?->woo_refund_id);

        if ($cancellationRefundId !== ''
            && data_get($manualPayment->metadata, 'settlement.manual_required') === true) {
            $referenceIds->push($cancellationRefundId);
        }

        $referenceIds = $referenceIds->unique()->values();

        return collect($refunds)
            ->filter(fn (mixed $refund): bool => is_array($refund)
                && ($refund['refunded_payment'] ?? null) === false
                && $referenceIds->contains(trim((string) ($refund['id'] ?? ''))))
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $refunds
     * @return array<string, mixed>|null
     */
    private function refundWithToken(array $refunds, string $token): ?array
    {
        $refund = collect($refunds)
            ->filter(fn (mixed $item): bool => is_array($item)
                && str_contains((string) ($item['reason'] ?? ''), $token))
            ->sortByDesc(fn (array $item): int => (int) ($item['id'] ?? 0))
            ->first();

        return is_array($refund) ? $refund : null;
    }

    private function assertManualPayment(CustomerPayment $payment, ExternalOrder $root): void
    {
        if ((int) $payment->external_order_id !== (int) $root->id
            || $payment->direction !== 'outgoing'
            || mb_strtolower((string) $payment->source) !== 'manual'
            || mb_strtolower((string) $payment->purpose) !== 'manual_order_refund'
            || ! in_array(mb_strtolower((string) $payment->status), ['paid', 'booked', 'settled'], true)
            || round(abs((float) $payment->amount), 2) <= 0
            || trim((string) $payment->idempotency_key) === '') {
            throw new RuntimeException('Do WooCommerce można zsynchronizować wyłącznie opłacony ręczny zwrot zamówienia głównego.');
        }
    }

    private function integrationFor(ExternalOrder $order): WordpressIntegration
    {
        $order->loadMissing(['wordpressIntegration', 'salesChannel.integrations', 'cancellation']);

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

    /** @param array<string, mixed> $values */
    private function mergeSyncMetadata(CustomerPayment $payment, array $values): CustomerPayment
    {
        $metadata = (array) $payment->metadata;
        $metadata[self::METADATA_KEY] = array_merge(
            (array) ($metadata[self::METADATA_KEY] ?? []),
            $values,
        );
        $payment->update(['metadata' => $metadata]);

        return $payment->refresh();
    }

    /**
     * @return array{status:string,message:string,accounted_amount:float,remainder_amount:float,woo_refund_id:?string,payment:CustomerPayment}
     */
    private function result(
        string $status,
        string $message,
        float $accountedAmount,
        float $remainderAmount,
        ?string $wooRefundId,
        CustomerPayment $payment,
    ): array {
        return [
            'status' => $status,
            'message' => $message,
            'accounted_amount' => round(max(0, $accountedAmount), 2),
            'remainder_amount' => round(max(0, $remainderAmount), 2),
            'woo_refund_id' => $wooRefundId,
            'payment' => $payment,
        ];
    }

    private function reasonWithToken(CustomerPayment $payment, string $token): string
    {
        $reason = trim(preg_replace('/\s+/', ' ', (string) $payment->description) ?? '');

        if ($reason === '') {
            $reason = 'Cashback wykonany ręcznie, referencja '.trim((string) $payment->reference);
        }

        return $token.' '.$reason;
    }

    /** @param array<string, mixed> $refund */
    private function refundAmount(array $refund): float
    {
        return round(abs((float) ($refund['amount'] ?? $refund['total'] ?? 0)), 2);
    }

    private function currency(mixed $value): string
    {
        $currency = mb_strtoupper(trim((string) $value));

        return $currency !== '' ? $currency : 'PLN';
    }
}
