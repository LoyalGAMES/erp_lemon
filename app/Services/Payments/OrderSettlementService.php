<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use Illuminate\Support\Collection;

final class OrderSettlementService
{
    private const CONFIRMED_STATUSES = ['booked', 'paid', 'settled'];

    public function __construct(
        private readonly PaymentMethodClassifier $paymentMethods,
    ) {}

    /**
     * Build a read-only settlement snapshot. When no fresh WooCommerce payload
     * is supplied, the last imported order payload is used and no network call
     * is made.
     *
     * @param  array<string, mixed>|null  $wooOrder
     * @param  list<array<string, mixed>>|null  $wooRefunds
     * @return array<string, mixed>
     */
    public function summary(
        ExternalOrder $order,
        ?array $wooOrder = null,
        ?array $wooRefunds = null,
    ): array {
        $order = $this->rootOrder($order);
        $wooOrder ??= is_array($order->raw_payload) ? $order->raw_payload : [];
        $currency = $this->currency($wooOrder['currency'] ?? $order->currency);
        $woo = $this->wooSummary($order, $wooOrder, $wooRefunds);
        $familyPayments = $this->familyPayments($order);
        $allPayments = $familyPayments
            ->filter(fn (CustomerPayment $payment): bool => $this->currency($payment->currency) === $currency);
        $woocommercePayments = $allPayments
            ->filter(fn (CustomerPayment $payment): bool => mb_strtolower((string) $payment->source) === 'woocommerce');

        // Woo-originated CustomerPayment rows are an audit trail for remote
        // refunds. Counting them here as ERP money would duplicate the same
        // refund already represented by the Woo refund collection.
        $erpPayments = $allPayments
            ->reject(fn (CustomerPayment $payment): bool => mb_strtolower((string) $payment->source) === 'woocommerce')
            ->values();
        $confirmed = $this->bucket($erpPayments, self::CONFIRMED_STATUSES);
        $pending = $this->bucket($erpPayments, ['pending']);
        $processing = $this->bucket($erpPayments, ['processing']);
        $unknown = $this->bucket($erpPayments, ['unknown']);
        $failed = $this->bucket($erpPayments, ['failed', 'manual_required']);
        $confirmedWoo = $this->bucket($woocommercePayments, self::CONFIRMED_STATUSES);
        $remoteGatewayRefundIds = collect((array) ($woo['refunds'] ?? []))
            ->filter(fn (mixed $refund): bool => is_array($refund)
                && ($refund['refunded_payment'] ?? null) === true)
            ->map(fn (array $refund): string => trim((string) ($refund['id'] ?? '')))
            ->filter()
            ->values();
        $locallyConfirmedWooNotInRemote = round((float) $woocommercePayments
            ->filter(fn (CustomerPayment $payment): bool => $payment->direction === 'outgoing'
                && in_array(mb_strtolower((string) $payment->status), self::CONFIRMED_STATUSES, true))
            ->reject(function (CustomerPayment $payment) use ($remoteGatewayRefundIds): bool {
                $externalId = trim((string) $payment->external_transaction_id);

                return $externalId !== '' && $remoteGatewayRefundIds->contains($externalId);
            })
            ->sum(fn (CustomerPayment $payment): float => abs((float) $payment->amount)), 2);
        $confirmedGatewayRefunded = round(
            (float) $woo['gateway_refunded'] + $locallyConfirmedWooNotInRemote,
            2,
        );
        // A manually booked incoming payment is a top-up in addition to the
        // original Woo payment. Woo-origin reconciliation records use source
        // `woocommerce` and are excluded above, so adding here does not count
        // the same gateway payment twice.
        $recognizedPaid = round(
            ($woo['paid'] ? $woo['total'] : 0.0) + $confirmed['incoming'],
            2,
        );
        // A Woo refund row is an accounting record, not proof that the gateway
        // returned money. Actual cash movement consists of gateway-confirmed
        // Woo refunds plus confirmed non-Woo outgoing payments. The local Woo
        // audit row is counted only when its remote refund is not in the fresh
        // response, which keeps stale imports accurate without double counting.
        $recognizedRefunded = round($confirmedGatewayRefunded + $confirmed['outgoing'], 2);
        $balance = round($recognizedPaid - $recognizedRefunded, 2);

        return [
            'currency' => $currency,
            'order_total' => $woo['total'],
            'paid' => $recognizedPaid + 0.005 >= $woo['total'] && $woo['total'] > 0,
            'payment_state' => $this->paymentState(
                $woo['total'],
                $recognizedPaid,
                $recognizedRefunded,
            ),
            'confirmed_paid_amount' => $recognizedPaid,
            'confirmed_refunded_amount' => $recognizedRefunded,
            'confirmed_gateway_refunded_amount' => $confirmedGatewayRefunded,
            'accounting_refunded_amount' => $woo['refunded'],
            'unconfirmed_woo_refund_amount' => round(max(0, $woo['refunded'] - $recognizedRefunded), 2),
            'balance' => $balance,
            'woo' => $woo,
            'erp' => [
                'confirmed' => $confirmed,
                'pending' => $pending,
                'processing' => $processing,
                'unknown' => $unknown,
                'failed_or_manual' => $failed,
                'excluded_other_currency_count' => $familyPayments->count() - $allPayments->count(),
            ],
            'woocommerce_payment_records' => [
                'count' => $woocommercePayments->count(),
                'confirmed' => $confirmedWoo,
                'pending' => $this->bucket($woocommercePayments, ['pending']),
                'processing' => $this->bucket($woocommercePayments, ['processing']),
                'unknown' => $this->bucket($woocommercePayments, ['unknown']),
                'manual_required' => $this->bucket($woocommercePayments, ['manual_required']),
            ],
        ];
    }

    /**
     * Describe whether an already executed manual refund may be registered.
     * Online payments require a prior `manual_required` result from Woo; known
     * offline methods may be settled manually up to their confirmed balance.
     *
     * @param  array<string, mixed>|null  $summary
     * @return array{allowed:bool,maximum:float,currency:string,category:string,manual_required:bool,manual_required_amount:float,manual_recorded_amount:float,payment_ids:list<int>,reason:string}
     */
    public function manualRefundAvailability(ExternalOrder $order, ?array $summary = null): array
    {
        $order = $this->rootOrder($order);
        $summary ??= $this->summary($order);
        $currency = $this->currency($summary['currency'] ?? $order->currency);
        $category = $this->paymentMethods->category($order);
        $payments = $this->familyPayments($order)
            ->filter(fn (CustomerPayment $payment): bool => $this->currency($payment->currency) === $currency);
        $manualRequiredPayments = $payments
            ->filter(fn (CustomerPayment $payment): bool => $payment->direction === 'outgoing'
                && mb_strtolower((string) $payment->source) === 'woocommerce'
                && mb_strtolower((string) $payment->status) === 'manual_required')
            ->values();
        $completedManualRequired = $payments
            ->filter(fn (CustomerPayment $payment): bool => $payment->direction === 'outgoing'
                && mb_strtolower((string) $payment->source) === 'manual'
                && mb_strtolower((string) $payment->purpose) === 'manual_order_refund'
                && in_array(mb_strtolower((string) $payment->status), self::CONFIRMED_STATUSES, true)
                && data_get($payment->metadata, 'settlement.manual_required') === true)
            ->sum(fn (CustomerPayment $payment): float => abs((float) $payment->amount));
        $manualRequiredAmount = round((float) $manualRequiredPayments
            ->sum(fn (CustomerPayment $payment): float => abs((float) $payment->amount)), 2);
        $cancellation = $order->cancellationOperation();

        if ($cancellation !== null
            && mb_strtolower((string) $cancellation->refund_status) === 'manual_required'
            && $this->currency($cancellation->currency) === $currency) {
            $manualRequiredAmount = max($manualRequiredAmount, round(abs((float) $cancellation->refund_amount), 2));
        }

        $manualRequired = $manualRequiredPayments->isNotEmpty()
            || ($cancellation !== null && mb_strtolower((string) $cancellation->refund_status) === 'manual_required');
        $manualRecordedAmount = round((float) $completedManualRequired, 2);
        $remainingRequired = round(max(0, $manualRequiredAmount - $manualRecordedAmount), 2);
        $offline = in_array($category, [
            PaymentMethodClassifier::CASH_ON_DELIVERY,
            PaymentMethodClassifier::BANK_TRANSFER,
            PaymentMethodClassifier::OTHER,
        ], true);
        $confirmedBalance = round(max(
            0,
            (float) ($summary['confirmed_paid_amount'] ?? 0)
                - (float) ($summary['confirmed_refunded_amount'] ?? 0),
        ), 2);
        $wooGatewayCapacity = data_get($summary, 'woo.paid') === true
            ? (float) data_get($summary, 'woo.refundable', 0)
            : 0.0;
        // A manual top-up sits above what the original Woo transaction can
        // return. It remains manually refundable even when the order's base
        // payment method was online.
        $manualOnlyBalance = round(max(0, $confirmedBalance - $wooGatewayCapacity), 2);
        $maximum = $manualRequired
            ? max($remainingRequired, $manualOnlyBalance)
            : ($offline ? $confirmedBalance : $manualOnlyBalance);
        $allowed = $maximum > 0 && ($offline || $manualRequired || $manualOnlyBalance > 0);

        return [
            'allowed' => $allowed,
            'maximum' => $maximum,
            'currency' => $currency,
            'category' => $category,
            'manual_required' => $manualRequired,
            'manual_required_amount' => $manualRequiredAmount,
            'manual_recorded_amount' => $manualRecordedAmount,
            'payment_ids' => $manualRequiredPayments
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
            'reason' => match (true) {
                $manualRequired && $remainingRequired <= 0 && $maximum <= 0 => 'Wymagany ręczny zwrot został już w całości potwierdzony w ERP.',
                $manualRequired => 'WooCommerce nie potwierdził zwrotu przez bramkę. Można zarejestrować wyłącznie przelew lub wypłatę już wykonaną poza ERP.',
                $manualOnlyBalance > 0 => 'Ta kwota pochodzi z ręcznej dopłaty, której pierwotna transakcja WooCommerce nie może zwrócić.',
                ! $offline => 'Dla płatności online najpierw wykonaj zwrot przez WooCommerce.',
                $maximum <= 0 => 'Brak potwierdzonej kwoty pozostającej do ręcznego zwrotu.',
                default => 'Ta metoda płatności wymaga ręcznego wykonania zwrotu poza ERP.',
            },
        ];
    }

    /**
     * All financial operations for a split family belong to its root order.
     */
    public function rootOrder(ExternalOrder $order): ExternalOrder
    {
        $rootId = (int) ($order->split_root_order_id ?: $order->id);

        if ($rootId === (int) $order->id) {
            return $order;
        }

        return ExternalOrder::query()->findOrFail($rootId);
    }

    /** @return list<int> */
    public function familyOrderIds(ExternalOrder $order): array
    {
        $root = $this->rootOrder($order);

        return ExternalOrder::query()
            ->whereKey($root->id)
            ->orWhere('split_root_order_id', $root->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @return Collection<int, CustomerPayment> */
    public function familyPayments(ExternalOrder $order): Collection
    {
        return CustomerPayment::query()
            ->whereIn('external_order_id', $this->familyOrderIds($order))
            ->orderByDesc('booked_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $wooOrder
     * @param  list<array<string, mixed>>|null  $wooRefunds
     * @return array{total:float,refunded:float,refundable:float,paid:bool,date_paid:?string,status:string,payment_method:string,payment_method_title:string,transaction_id:string,refund_count:int,gateway_refunded:float,manual_recorded_refunds:float,unknown_refund_method:float,refunds:list<array<string,mixed>>}
     */
    public function wooSummary(
        ExternalOrder $order,
        array $wooOrder,
        ?array $wooRefunds = null,
    ): array {
        $total = $this->money($wooOrder['total'] ?? $order->total_gross);
        $refunds = $wooRefunds ?? array_values(array_filter(
            (array) ($wooOrder['refunds'] ?? []),
            fn (mixed $refund): bool => is_array($refund),
        ));
        $refunded = 0.0;
        $gatewayRefunded = 0.0;
        $manualRecorded = 0.0;
        $unknownRefundMethod = 0.0;

        foreach ($refunds as $refund) {
            $amount = $this->money($refund['amount'] ?? $refund['total'] ?? 0);
            $refunded += $amount;

            if (($refund['refunded_payment'] ?? null) === true) {
                $gatewayRefunded += $amount;
            } elseif (array_key_exists('refunded_payment', $refund)) {
                $manualRecorded += $amount;
            } else {
                $unknownRefundMethod += $amount;
            }
        }

        $refunded = round(min($total, $refunded), 2);
        $datePaid = $this->nullableString($wooOrder['date_paid'] ?? null)
            ?? $this->nullableString($wooOrder['date_paid_gmt'] ?? null);

        return [
            'total' => $total,
            'refunded' => $refunded,
            'refundable' => round(max(0, $total - $refunded), 2),
            'paid' => $datePaid !== null,
            'date_paid' => $datePaid,
            'status' => mb_strtolower(trim((string) ($wooOrder['status'] ?? $order->status))),
            'payment_method' => trim((string) ($wooOrder['payment_method'] ?? data_get($order->raw_payload, 'payment_method', ''))),
            'payment_method_title' => trim((string) ($wooOrder['payment_method_title'] ?? data_get($order->raw_payload, 'payment_method_title', ''))),
            'transaction_id' => trim((string) ($wooOrder['transaction_id'] ?? data_get($order->raw_payload, 'transaction_id', ''))),
            'refund_count' => count($refunds),
            'gateway_refunded' => round($gatewayRefunded, 2),
            'manual_recorded_refunds' => round($manualRecorded, 2),
            'unknown_refund_method' => round($unknownRefundMethod, 2),
            'refunds' => $refunds,
        ];
    }

    /**
     * @param  Collection<int, CustomerPayment>  $payments
     * @param  list<string>  $statuses
     * @return array{incoming:float,outgoing:float,balance:float,count:int}
     */
    private function bucket(Collection $payments, array $statuses): array
    {
        $selected = $payments->filter(
            fn (CustomerPayment $payment): bool => in_array(
                mb_strtolower((string) $payment->status),
                $statuses,
                true,
            ),
        );
        $incoming = round((float) $selected
            ->where('direction', 'incoming')
            ->sum(fn (CustomerPayment $payment): float => abs((float) $payment->amount)), 2);
        $outgoing = round((float) $selected
            ->where('direction', 'outgoing')
            ->sum(fn (CustomerPayment $payment): float => abs((float) $payment->amount)), 2);

        return [
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'balance' => round($incoming - $outgoing, 2),
            'count' => $selected->count(),
        ];
    }

    private function paymentState(float $total, float $paid, float $refunded): string
    {
        if ($refunded > 0 && $refunded + 0.005 >= $paid && $paid > 0) {
            return 'refunded';
        }

        if ($refunded > 0) {
            return 'partially_refunded';
        }

        if ($paid + 0.005 >= $total && $total > 0) {
            return 'paid';
        }

        if ($paid > 0) {
            return 'partially_paid';
        }

        return 'unpaid';
    }

    private function money(mixed $value): float
    {
        return round(abs((float) $value), 2);
    }

    private function currency(mixed $value): string
    {
        $currency = mb_strtoupper(trim((string) $value));

        return $currency !== '' ? $currency : 'PLN';
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
