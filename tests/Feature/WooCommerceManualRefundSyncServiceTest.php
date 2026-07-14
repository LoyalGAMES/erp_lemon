<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Payments\WooCommerceManualRefundSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WooCommerceManualRefundSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounting_refund_uses_api_refund_false_and_same_operation_posts_once(): void
    {
        $order = $this->order('6101');
        $payment = $this->manualRefund($order, 40);
        $refunds = [];
        $postCount = 0;

        Http::fake(function (Request $request) use ($order, $payment, &$refunds, &$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/6101/refunds')) {
                return Http::response($refunds);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/6101')) {
                return Http::response($this->wooOrder($order));
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/6101/refunds')) {
                $postCount++;
                $this->assertFalse($request['api_refund']);
                $this->assertFalse($request['api_restock']);
                $this->assertSame('40.00', $request['amount']);
                $this->assertStringContainsString(
                    '[ERP-MANUAL-REFUND:'.$payment->idempotency_key.']',
                    (string) $request['reason'],
                );
                $refunds = [[
                    'id' => 96101,
                    'amount' => '40.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => false,
                ]];

                return Http::response($refunds[0], 201);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $service = app(WooCommerceManualRefundSyncService::class);
        $first = $service->sync($payment);
        $second = $service->sync($payment->fresh());

        $this->assertSame('success', $first['status']);
        $this->assertSame('success', $second['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame(40.0, $second['accounted_amount']);
        $this->assertSame(0.0, $second['remainder_amount']);
        $this->assertSame('96101', $second['woo_refund_id']);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(
            'success',
            data_get($payment->fresh()->metadata, 'woocommerce_manual_refund_sync.status'),
        );
    }

    #[DataProvider('ambiguousPostFailureProvider')]
    public function test_ambiguous_post_failure_is_unknown_and_never_posted_twice(string $failure): void
    {
        $order = $this->order('6102');
        $payment = $this->manualRefund($order, 30);
        $postCount = 0;

        Http::fake(function (Request $request) use ($order, $failure, &$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/6102/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/6102')) {
                return Http::response($this->wooOrder($order));
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/6102/refunds')) {
                $postCount++;

                if ($failure === 'connection') {
                    throw new ConnectionException('Connection reset after accepting refund accounting');
                }

                return Http::response(['message' => 'temporary Woo failure'], 500);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $service = app(WooCommerceManualRefundSyncService::class);
        $first = $service->sync($payment);
        $second = $service->sync($payment->fresh());

        $this->assertSame('unknown', $first['status']);
        $this->assertSame('unknown', $second['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertNotNull(data_get(
            $payment->fresh()->metadata,
            'woocommerce_manual_refund_sync.post_started_at',
        ));
    }

    /** @return array<string, array{string}> */
    public static function ambiguousPostFailureProvider(): array
    {
        return [
            'HTTP 500' => ['http_500'],
            'connection reset' => ['connection'],
        ];
    }

    public function test_existing_manual_required_woo_record_is_linked_without_duplicate_post(): void
    {
        $order = $this->order('6103');
        $required = CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'idempotency_key' => 'order-refund:manual-required-6103',
            'direction' => 'outgoing',
            'source' => 'woocommerce',
            'purpose' => 'order_cancellation',
            'method' => 'payu',
            'status' => 'manual_required',
            'amount' => 60,
            'currency' => 'PLN',
            'external_transaction_id' => '96103',
        ]);
        $payment = $this->manualRefund($order, 60, [$required->id]);
        $postCount = 0;
        $refund = [
            'id' => 96103,
            'amount' => '60.00',
            'reason' => '[ERP-REFUND:earlier-operation] Bramka nie obsługuje refundu',
            'refunded_payment' => false,
        ];

        Http::fake(function (Request $request) use ($order, $refund, &$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/6103/refunds')) {
                return Http::response([$refund]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/6103')) {
                return Http::response($this->wooOrder($order));
            }

            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $result = app(WooCommerceManualRefundSyncService::class)->sync($payment);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(0, $postCount);
        $this->assertSame(60.0, $result['accounted_amount']);
        $this->assertSame(0.0, $result['remainder_amount']);
        $this->assertSame('96103', $result['woo_refund_id']);
        $this->assertSame(
            ['96103'],
            data_get($payment->fresh()->metadata, 'woocommerce_manual_refund_sync.linked_woo_refund_ids'),
        );
    }

    public function test_amount_above_woo_capacity_is_capped_without_over_refund(): void
    {
        $order = $this->order('6104');
        $payment = $this->manualRefund($order, 120);
        $postCount = 0;

        Http::fake(function (Request $request) use ($order, &$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/6104/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/6104')) {
                return Http::response($this->wooOrder($order));
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/6104/refunds')) {
                $postCount++;
                $this->assertSame('100.00', $request['amount']);

                return Http::response([
                    'id' => 96104,
                    'amount' => '100.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => false,
                ], 201);
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $result = app(WooCommerceManualRefundSyncService::class)->sync($payment);

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame(100.0, $result['accounted_amount']);
        $this->assertSame(20.0, $result['remainder_amount']);
        $this->assertSame(20.0, (float) data_get(
            $payment->fresh()->metadata,
            'woocommerce_manual_refund_sync.unaccounted_remainder',
        ));
    }

    private function order(string $externalId): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'code' => 'MANUAL-'.$externalId,
            'name' => 'Manual refund '.$externalId,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo manual refund '.$externalId,
            'base_url' => 'https://manual-refund-'.$externalId.'.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_manual_refund'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_manual_refund'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => $externalId,
            'external_number' => $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'billing_data' => ['email' => 'manual-'.$externalId.'@example.test'],
            'shipping_data' => [],
            'raw_payload' => [],
        ]);
        $order->update(['raw_payload' => $this->wooOrder($order)]);

        return $order->fresh();
    }

    /**
     * @param  list<int>  $manualRequiredPaymentIds
     */
    private function manualRefund(
        ExternalOrder $order,
        float $amount,
        array $manualRequiredPaymentIds = [],
    ): CustomerPayment {
        return CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'idempotency_key' => 'manual-order-refund:'.$order->id.':'.Str::uuid(),
            'direction' => 'outgoing',
            'source' => 'manual',
            'purpose' => 'manual_order_refund',
            'method' => 'bank_transfer',
            'status' => 'paid',
            'amount' => $amount,
            'currency' => 'PLN',
            'reference' => 'BANK-'.$order->external_id,
            'description' => 'Cashback wykonany ręcznie',
            'requested_at' => now(),
            'booked_at' => now(),
            'paid_at' => now(),
            'metadata' => [
                'settlement' => [
                    'manual_required' => $manualRequiredPaymentIds !== [],
                    'manual_required_payment_ids' => $manualRequiredPaymentIds,
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function wooOrder(ExternalOrder $order): array
    {
        return [
            'id' => $order->external_id,
            'number' => $order->external_number,
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '100.00',
            'date_paid' => '2026-07-14T10:00:00',
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'transaction_id' => 'PAYU-'.$order->external_id,
            'refunds' => [],
        ];
    }

    private function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }
}
