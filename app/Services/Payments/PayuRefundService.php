<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\ReturnCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PayuRefundService
{
    public function __construct(
        private readonly PayuRefundSettingsService $settings,
        private readonly PaymentMethodClassifier $classifier,
    ) {}

    public function attemptAutomaticRefund(ReturnCase $returnCase, Invoice $invoice): ?CustomerPayment
    {
        $settings = $this->settings->data();

        if (! $settings['enabled'] || ! $settings['auto_refund_enabled']) {
            return null;
        }

        $returnCase->loadMissing('externalOrder');

        if ($returnCase->externalOrder === null || ! $this->classifier->isPayuPrepaid($returnCase->externalOrder)) {
            return null;
        }

        return $this->refundReturn($returnCase, $invoice);
    }

    public function refundReturn(ReturnCase $returnCase, ?Invoice $invoice = null): CustomerPayment
    {
        $settings = $this->settings->data();
        $secret = $this->settings->clientSecret();

        if (! $settings['enabled']) {
            throw new RuntimeException('Refundy PayU są wyłączone w ustawieniach płatności.');
        }

        if ($settings['client_id'] === '' || $secret === null) {
            throw new RuntimeException('Uzupełnij client_id i client_secret PayU w ustawieniach płatności.');
        }

        $returnCase->loadMissing(['externalOrder', 'correctionInvoice']);
        $order = $returnCase->externalOrder;

        if ($order === null) {
            throw new RuntimeException('Refund PayU wymaga zwrotu powiązanego z zamówieniem.');
        }

        $payuOrderId = $this->classifier->payuOrderId($order);

        if ($payuOrderId === null) {
            throw new RuntimeException('Nie znaleziono identyfikatora zamówienia PayU w danych WooCommerce.');
        }

        $invoice ??= $returnCase->correctionInvoice;
        $amountCents = $this->amountCents($invoice);

        if ($amountCents <= 0) {
            throw new RuntimeException('Nie można wysłać refundu PayU bez dodatniej kwoty korekty.');
        }

        $extRefundId = $this->extRefundId($returnCase, $invoice, $amountCents);
        [$payment, $shouldSend] = $this->claimPayment(
            $returnCase,
            $invoice,
            $payuOrderId,
            $extRefundId,
            $amountCents,
        );

        if (! $shouldSend) {
            return $payment;
        }

        try {
            $token = $this->token($settings['client_id'], $secret);
            $payload = [
                'refund' => [
                    'description' => 'Zwrot '.$returnCase->number,
                    'amount' => (string) $amountCents,
                    'extRefundId' => $extRefundId,
                    'currencyCode' => $invoice?->currency ?: $order->currency,
                    'bankDescription' => mb_substr('Zwrot '.$returnCase->number, 0, 35),
                    'type' => $settings['refund_type'],
                ],
            ];

            $response = Http::baseUrl($this->settings->baseUrl())
                ->withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(30)
                ->post('/api/v2_1/orders/'.rawurlencode($payuOrderId).'/refunds', $payload);

            $body = $response->json();

            if ($response->failed()) {
                $payment->update([
                    'status' => 'failed',
                    'metadata' => array_merge($payment->metadata ?? [], [
                        'payu' => array_merge((array) data_get($payment->metadata, 'payu', []), [
                            'request_payload' => $payload,
                            'response_payload' => $body,
                            'http_status' => $response->status(),
                        ]),
                    ]),
                ]);

                throw new RuntimeException($this->errorMessage($body, 'PayU odrzuciło refund (HTTP '.$response->status().').'));
            }

            $refund = (array) data_get($body, 'refund', []);
            $status = mb_strtolower((string) ($refund['status'] ?? 'pending'));
            $finalized = in_array($status, ['finalized', 'completed', 'success'], true);

            $payment->update([
                'status' => $finalized ? 'paid' : 'pending',
                'reference' => (string) ($refund['refundId'] ?? $payuOrderId),
                'paid_at' => $finalized ? now() : null,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'payu' => array_merge((array) data_get($payment->metadata, 'payu', []), [
                        'request_payload' => $payload,
                        'response_payload' => $body,
                        'refund_id' => $refund['refundId'] ?? null,
                        'status' => $refund['status'] ?? null,
                    ]),
                ]),
            ]);
        } catch (RuntimeException $exception) {
            $payment->update([
                'status' => 'failed',
                'metadata' => array_merge($payment->metadata ?? [], [
                    'payu' => array_merge((array) data_get($payment->metadata, 'payu', []), [
                        'error' => $exception->getMessage(),
                        'failed_at' => now()->toISOString(),
                    ]),
                ]),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            $payment->update([
                'status' => 'failed',
                'metadata' => array_merge($payment->metadata ?? [], [
                    'payu' => array_merge((array) data_get($payment->metadata, 'payu', []), [
                        'error' => $exception->getMessage(),
                    ]),
                ]),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }

        return $payment->refresh();
    }

    /**
     * @return array{0:CustomerPayment,1:bool}
     */
    private function claimPayment(
        ReturnCase $returnCase,
        ?Invoice $invoice,
        string $payuOrderId,
        string $extRefundId,
        int $amountCents,
    ): array {
        return DB::transaction(function () use ($returnCase, $invoice, $payuOrderId, $extRefundId, $amountCents): array {
            ReturnCase::query()->lockForUpdate()->findOrFail($returnCase->id);

            $payment = CustomerPayment::query()
                ->where('return_case_id', $returnCase->id)
                ->where('method', 'payu')
                ->where('metadata->payu->ext_refund_id', $extRefundId)
                ->lockForUpdate()
                ->first();

            if ($payment instanceof CustomerPayment
                && in_array($payment->status, ['processing', 'pending', 'paid'], true)) {
                return [$payment, false];
            }

            if (! $payment instanceof CustomerPayment) {
                $payment = CustomerPayment::query()->create([
                    'external_order_id' => $returnCase->external_order_id,
                    'return_case_id' => $returnCase->id,
                    'direction' => 'outgoing',
                    'method' => 'payu',
                    'status' => 'processing',
                    'amount' => round($amountCents / 100, 2),
                    'currency' => $invoice?->currency ?: $returnCase->externalOrder?->currency ?: 'PLN',
                    'reference' => $payuOrderId,
                    'description' => 'Refund PayU dla zwrotu '.$returnCase->number,
                    'booked_at' => now(),
                    'metadata' => [
                        'payu' => [
                            'order_id' => $payuOrderId,
                            'ext_refund_id' => $extRefundId,
                            'amount_cents' => $amountCents,
                            'attempts' => 1,
                            'last_attempt_at' => now()->toISOString(),
                        ],
                    ],
                ]);

                return [$payment, true];
            }

            $payu = (array) data_get($payment->metadata, 'payu', []);
            $payu['attempts'] = (int) ($payu['attempts'] ?? 0) + 1;
            $payu['last_attempt_at'] = now()->toISOString();
            unset($payu['error'], $payu['failed_at']);

            $payment->update([
                'status' => 'processing',
                'metadata' => array_merge($payment->metadata ?? [], ['payu' => $payu]),
            ]);

            return [$payment->refresh(), true];
        });
    }

    private function token(string $clientId, string $secret): string
    {
        $response = Http::baseUrl($this->settings->baseUrl())
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->post('/pl/standard/user/oauth/authorize', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $secret,
            ]);

        if ($response->failed() || ! filled($response->json('access_token'))) {
            throw new RuntimeException($this->errorMessage($response->json(), 'Nie udało się pobrać tokenu OAuth PayU.'));
        }

        return (string) $response->json('access_token');
    }

    private function amountCents(?Invoice $invoice): int
    {
        if (! $invoice instanceof Invoice) {
            return 0;
        }

        return (int) round(abs((float) $invoice->gross_total) * 100);
    }

    private function extRefundId(ReturnCase $returnCase, ?Invoice $invoice, int $amountCents): string
    {
        return 'ERP-RET-'.$returnCase->id.'-'.($invoice?->id ?? 'NOINV').'-'.$amountCents;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function errorMessage(?array $body, string $fallback): string
    {
        $message = trim(implode(' ', array_filter([
            (string) data_get($body, 'status.statusDesc', ''),
            (string) data_get($body, 'status.statusCode', ''),
            (string) data_get($body, 'error_description', ''),
            (string) data_get($body, 'error', ''),
        ])));

        return $message !== '' ? $message : $fallback;
    }
}
