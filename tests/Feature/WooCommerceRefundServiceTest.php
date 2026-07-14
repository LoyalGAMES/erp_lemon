<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\OrderCancellation;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Payments\OrderSettlementService;
use App\Services\Payments\WooCommerceRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WooCommerceRefundServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlement_separates_confirmed_and_unresolved_erp_money_without_duplicating_woo(): void
    {
        [$order] = $this->order('100', [
            'total' => '100.00',
            'currency' => 'PLN',
            'date_paid_gmt' => '2026-07-14T08:00:00',
            'refunds' => [
                ['id' => 11, 'total' => '-20.00', 'reason' => 'Częściowy zwrot'],
            ],
        ]);
        $this->payment($order, 'incoming', 'booked', 20);
        $this->payment($order, 'incoming', 'pending', 25);
        $this->payment($order, 'outgoing', 'processing', 10);
        $this->payment($order, 'outgoing', 'unknown', 5);
        $this->payment($order, 'incoming', 'failed', 30);
        $this->payment($order, 'outgoing', 'paid', 20, source: 'woocommerce');
        $this->payment($order, 'incoming', 'paid', 999, currency: 'EUR');

        $summary = app(OrderSettlementService::class)->summary($order);

        $this->assertSame(100.0, $summary['woo']['total']);
        $this->assertSame(20.0, $summary['woo']['refunded']);
        $this->assertSame(80.0, $summary['woo']['refundable']);
        $this->assertTrue($summary['woo']['paid']);
        $this->assertSame(20.0, $summary['erp']['confirmed']['incoming']);
        $this->assertSame(0.0, $summary['erp']['confirmed']['outgoing']);
        $this->assertSame(25.0, $summary['erp']['pending']['incoming']);
        $this->assertSame(10.0, $summary['erp']['processing']['outgoing']);
        $this->assertSame(5.0, $summary['erp']['unknown']['outgoing']);
        $this->assertSame(30.0, $summary['erp']['failed_or_manual']['incoming']);
        $this->assertSame(1, $summary['erp']['excluded_other_currency_count']);
        $this->assertSame(20.0, $summary['woocommerce_payment_records']['confirmed']['outgoing']);
        $this->assertSame(120.0, $summary['confirmed_paid_amount']);
        $this->assertSame(20.0, $summary['confirmed_refunded_amount']);
        $this->assertSame(100.0, $summary['balance']);
        $this->assertSame('partially_refunded', $summary['payment_state']);
    }

    public function test_manual_gateway_record_and_confirmed_manual_transfer_are_not_counted_twice(): void
    {
        [$order] = $this->order();
        $this->payment($order, 'outgoing', 'paid', 100, source: 'manual');

        $summary = app(OrderSettlementService::class)->summary(
            $order,
            $this->wooOrder(),
            [[
                'id' => 901,
                'amount' => '100.00',
                'reason' => 'Zwrot zapisany w Woo, przelew wykonany ręcznie',
                'refunded_payment' => false,
            ]],
        );

        $this->assertSame(100.0, $summary['woo']['refunded']);
        $this->assertSame(0.0, $summary['woo']['gateway_refunded']);
        $this->assertSame(100.0, $summary['woo']['manual_recorded_refunds']);
        $this->assertSame(100.0, $summary['erp']['confirmed']['outgoing']);
        $this->assertSame(100.0, $summary['confirmed_refunded_amount']);
        $this->assertSame(0.0, $summary['unconfirmed_woo_refund_amount']);
        $this->assertSame(0.0, $summary['balance']);
        $this->assertSame('refunded', $summary['payment_state']);
    }

    public function test_cancellation_keeps_manual_top_up_as_an_explicit_manual_remainder(): void
    {
        [$order] = $this->order();
        $this->payment($order, 'incoming', 'paid', 20);
        $cancellation = $this->cancellation($order, 'Klientka rezygnuje z całości');
        $postedAmount = null;

        Http::fake(function (Request $request) use (&$postedAmount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                $postedAmount = $request['amount'];

                return Http::response([
                    'id' => 705,
                    'amount' => $request['amount'],
                    'reason' => $request['reason'],
                    'refunded_payment' => true,
                ], 201);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refund(
            $cancellation,
            'order-cancellation:'.$cancellation->uuid,
        );

        $this->assertSame('100.00', $postedAmount);
        $this->assertSame('manual_required', $result['status']);
        $this->assertSame('submitted', $result['automatic_refund_status']);
        $this->assertSame(20.0, $result['amount']);
        $this->assertSame(20.0, $result['manual_required_amount']);
        $this->assertSame(100.0, $result['newly_refunded_amount']);
        $this->assertSame('paid', $result['payment']->status);
        $this->assertSame('100.00', (string) $result['payment']->amount);
    }

    public function test_cancellation_requires_manual_refund_for_confirmed_erp_payment_when_woo_is_unpaid(): void
    {
        [$order] = $this->order();
        $this->payment($order, 'incoming', 'paid', 100);
        $cancellation = $this->cancellation($order, 'Płatność przelewem do zwrotu');
        $wooOrder = array_merge($this->wooOrder(), [
            'status' => 'on-hold',
            'date_paid' => null,
            'date_paid_gmt' => null,
            'transaction_id' => '',
        ]);
        $postCount = 0;

        Http::fake(function (Request $request) use ($wooOrder, &$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refund(
            $cancellation,
            'order-cancellation:'.$cancellation->uuid,
        );

        $this->assertSame(0, $postCount);
        $this->assertSame('manual_required', $result['status']);
        $this->assertSame('not_required', $result['automatic_refund_status']);
        $this->assertSame(100.0, $result['amount']);
        $this->assertSame(100.0, $result['manual_required_amount']);
        $this->assertNull($result['payment']);
    }

    public function test_cancellation_never_refunds_more_than_the_confirmed_remaining_balance(): void
    {
        [$order] = $this->order();
        $this->payment($order, 'outgoing', 'paid', 50);
        $cancellation = $this->cancellation($order, 'Pozostała część salda do zwrotu');
        $postedAmount = null;

        Http::fake(function (Request $request) use (&$postedAmount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postedAmount = $request['amount'];

                return Http::response([
                    'id' => 706,
                    'amount' => $request['amount'],
                    'reason' => $request['reason'],
                    'refunded_payment' => true,
                ], 201);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refund(
            $cancellation,
            'order-cancellation:'.$cancellation->uuid,
        );

        $this->assertSame('50.00', $postedAmount);
        $this->assertSame('submitted', $result['status']);
        $this->assertSame(50.0, $result['amount']);
        $this->assertSame(0.0, $result['manual_required_amount']);
    }

    public function test_it_submits_one_gateway_refund_with_a_reconciliation_token(): void
    {
        [$order] = $this->order();
        $postCount = 0;
        $sentPayload = null;

        Http::fake(function (Request $request) use (&$postCount, &$sentPayload) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                $postCount++;
                $sentPayload = $request->data();

                return Http::response([
                    'id' => 701,
                    'amount' => '100.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => true,
                ], 201);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Rezygnacja klientki',
            'refund-operation-1',
        );

        $this->assertSame('submitted', $result['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame('100.00', $sentPayload['amount']);
        $this->assertSame('[ERP-REFUND:refund-operation-1] Rezygnacja klientki', $sentPayload['reason']);
        $this->assertTrue($sentPayload['api_refund']);
        $this->assertFalse($sentPayload['api_restock']);

        $payment = CustomerPayment::query()->firstOrFail();
        $this->assertSame('outgoing', $payment->direction);
        $this->assertSame('woocommerce', $payment->source);
        $this->assertSame('order_refund', $payment->purpose);
        $this->assertSame('payu', $payment->method);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('701', $payment->external_transaction_id);
        $this->assertSame('refund-operation-1', $payment->idempotency_key);
        $this->assertNotNull($payment->requested_at);
        $this->assertNotNull($payment->booked_at);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(100.0, $result['newly_refunded_amount']);
    }

    public function test_existing_remote_refund_is_reconciled_before_any_post(): void
    {
        [$order] = $this->order();
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([
                    [
                        'id' => 702,
                        'amount' => '100.00',
                        'reason' => '[ERP-REFUND:refund-existing] Rezygnacja klientki',
                        'refunded_payment' => true,
                    ],
                ]);
            }

            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response([], 500);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Rezygnacja klientki',
            'refund-existing',
        );

        $this->assertSame('submitted', $result['status']);
        $this->assertSame(0, $postCount);
        $this->assertSame('paid', $result['payment']->status);
        $this->assertSame('702', $result['payment']->external_transaction_id);
        $this->assertSame(1, CustomerPayment::query()->count());
        $this->assertSame(0.0, $result['newly_refunded_amount']);
    }

    public function test_woo_status_or_transaction_id_without_paid_date_does_not_trigger_a_refund(): void
    {
        [$order] = $this->order();
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response(array_merge($this->wooOrder(), [
                    'status' => 'processing',
                    'date_paid' => null,
                    'date_paid_gmt' => null,
                    'transaction_id' => 'PAYU-WITHOUT-PAID-DATE',
                ]));
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response([], 500);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Anulowanie',
            'refund-unpaid',
        );

        $this->assertSame('not_required', $result['status']);
        $this->assertSame(0, $postCount);
        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_ambiguous_connection_failure_becomes_unknown_and_is_never_reposted(): void
    {
        [$order] = $this->order();
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postCount++;
                throw new ConnectionException('Connection reset after refund POST');
            }

            return Http::response([], 404);
        });

        $service = app(WooCommerceRefundService::class);
        $first = $service->refundOrder($order, null, 'Anulowanie', 'refund-unknown');
        $second = $service->refundOrder($order, null, 'Anulowanie', 'refund-unknown');

        $this->assertSame('unknown', $first['status']);
        $this->assertSame('unknown', $second['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame('unknown', CustomerPayment::query()->firstOrFail()->status);
    }

    public function test_generic_http_500_after_post_is_unknown_and_is_not_posted_again(): void
    {
        [$order] = $this->order();
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postCount++;

                return Http::response([
                    'code' => 'woocommerce_rest_cannot_create_order_refund',
                    'message' => 'Internal Server Error',
                    'data' => ['status' => 500],
                ], 500);
            }

            return Http::response([], 404);
        });

        $service = app(WooCommerceRefundService::class);
        $first = $service->refundOrder($order, null, 'Anulowanie', 'refund-generic-500');
        $second = $service->refundOrder($order, null, 'Anulowanie', 'refund-generic-500');

        $this->assertSame('unknown', $first['status']);
        $this->assertSame('unknown', $second['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame('unknown', CustomerPayment::query()->sole()->status);
    }

    public function test_processing_pending_paid_and_unknown_local_records_are_not_reposted(): void
    {
        [$order] = $this->order();
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response([], 500);
        });

        $service = app(WooCommerceRefundService::class);

        foreach (['processing', 'pending', 'paid', 'unknown'] as $status) {
            CustomerPayment::query()->create([
                'external_order_id' => $order->id,
                'idempotency_key' => 'protected-'.$status,
                'direction' => 'outgoing',
                'source' => 'woocommerce',
                'purpose' => 'order_refund',
                'method' => 'payu',
                'status' => $status,
                'amount' => 100,
                'currency' => 'PLN',
            ]);

            $result = $service->refundOrder(
                $order,
                null,
                'Anulowanie',
                'protected-'.$status,
            );

            $this->assertSame(
                in_array($status, ['processing', 'pending', 'unknown'], true) ? 'unknown' : 'submitted',
                $result['status'],
            );
            CustomerPayment::query()->where('idempotency_key', 'protected-'.$status)->delete();
        }

        $this->assertSame(0, $postCount);
    }

    public function test_foreign_ambiguous_family_payout_blocks_post_but_retry_is_safe_after_resolution(): void
    {
        [$order, $integration] = $this->order();
        $child = ExternalOrder::query()->create([
            'split_parent_order_id' => $order->id,
            'split_root_order_id' => $order->id,
            'sales_channel_id' => $order->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => '123-SPLIT-1',
            'external_number' => '123-SPLIT-1',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 25,
            'billing_data' => [],
            'shipping_data' => [],
            'raw_payload' => array_merge($this->wooOrder(), ['id' => '123-SPLIT-1', 'total' => '25.00']),
        ]);
        $foreign = CustomerPayment::query()->create([
            'external_order_id' => $child->id,
            'idempotency_key' => 'foreign-payu-payout',
            'direction' => 'outgoing',
            'source' => 'payu',
            'purpose' => 'return_refund',
            'method' => 'payu',
            'status' => 'unknown',
            'amount' => 25,
            'currency' => 'PLN',
        ]);
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postCount++;

                return Http::response([
                    'id' => 709,
                    'amount' => '100.00',
                    'refunded_payment' => true,
                ], 201);
            }

            return Http::response([], 404);
        });

        $service = app(WooCommerceRefundService::class);
        $blocked = $service->refundOrder($order, null, 'Anulowanie', 'family-retry-key');

        $this->assertSame('failed', $blocked['status']);
        $this->assertSame(0, $postCount);
        $this->assertDatabaseMissing('customer_payments', ['idempotency_key' => 'family-retry-key']);

        $foreign->update(['status' => 'failed']);
        $retried = $service->refundOrder($order, null, 'Anulowanie', 'family-retry-key');

        $this->assertSame('submitted', $retried['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame(100.0, $retried['newly_refunded_amount']);
    }

    public function test_gateway_without_automatic_refunds_requires_manual_action_without_retry(): void
    {
        [$order] = $this->order();
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postCount++;

                return Http::response([
                    'code' => 'woocommerce_rest_cannot_create_order_refund',
                    'message' => 'Ta bramka płatności nie obsługuje automatycznych zwrotów.',
                    'data' => ['status' => 500],
                ], 500);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Anulowanie',
            'refund-manual',
        );

        $this->assertSame('manual_required', $result['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame('manual_required', $result['payment']->status);
        $this->assertStringContainsString('nie obsługuje automatycznych zwrotów', $result['message']);
        $this->assertNotNull($result['payment']->failed_at);
    }

    public function test_refund_record_without_gateway_confirmation_requires_manual_action(): void
    {
        [$order] = $this->order();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                return Http::response([
                    'id' => 703,
                    'amount' => '100.00',
                    'refunded_payment' => false,
                ], 201);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Anulowanie',
            'refund-unconfirmed',
        );

        $this->assertSame('manual_required', $result['status']);
        $this->assertSame('manual_required', $result['payment']->status);
        $this->assertSame('703', $result['payment']->external_transaction_id);
    }

    public function test_successful_post_without_refunded_payment_flag_is_unknown_not_manual_required(): void
    {
        [$order] = $this->order();

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                return Http::response([
                    'id' => 705,
                    'amount' => '100.00',
                ], 201);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Anulowanie',
            'refund-missing-gateway-flag',
        );

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('unknown', $result['payment']->status);
        $this->assertNull($result['payment']->failed_at);
        $this->assertStringContainsString('nie podał', $result['message']);
    }

    public function test_reconciled_refund_without_refunded_payment_flag_is_unknown_without_post(): void
    {
        [$order] = $this->order();
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([[
                    'id' => 706,
                    'amount' => '100.00',
                    'reason' => '[ERP-REFUND:refund-reconcile-missing-flag] Anulowanie',
                ]]);
            }

            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Anulowanie',
            'refund-reconcile-missing-flag',
        );

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('unknown', $result['payment']->status);
        $this->assertNull($result['payment']->failed_at);
        $this->assertSame(0, $postCount);
    }

    public function test_partial_remote_refund_with_matching_token_is_unknown_and_preserves_expected_amount(): void
    {
        [$order] = $this->order();
        CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'idempotency_key' => 'refund-partial-token',
            'direction' => 'outgoing',
            'source' => 'woocommerce',
            'purpose' => 'order_refund',
            'method' => 'payu',
            'status' => 'processing',
            'amount' => 100,
            'currency' => 'PLN',
        ]);
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([[
                    'id' => 707,
                    'amount' => '50.00',
                    'reason' => '[ERP-REFUND:refund-partial-token] Anulowanie',
                    'refunded_payment' => true,
                ]]);
            }

            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Anulowanie',
            'refund-partial-token',
        );

        $this->assertSame('unknown', $result['status']);
        $this->assertSame(0, $postCount);
        $this->assertSame(0.0, $result['newly_refunded_amount']);
        $payment = CustomerPayment::query()->sole();
        $this->assertSame('unknown', $payment->status);
        $this->assertSame('100.00', (string) $payment->amount);
        $this->assertSame(50.0, (float) data_get($payment->metadata, 'woocommerce.observed_refund_amount'));
        $this->assertFalse(data_get($payment->metadata, 'woocommerce.amount_matches'));
    }

    public function test_refund_amount_is_clamped_to_confirmed_cash_balance(): void
    {
        [$order] = $this->order();
        $this->payment($order, 'outgoing', 'paid', 80, source: 'manual');
        $sentAmount = null;

        Http::fake(function (Request $request) use (&$sentAmount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $sentAmount = $request['amount'];

                return Http::response([
                    'id' => 708,
                    'amount' => $request['amount'],
                    'refunded_payment' => true,
                ], 201);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            100,
            'Pozostałe saldo',
            'refund-clamped-to-balance',
        );

        $this->assertSame('submitted', $result['status']);
        $this->assertSame('20.00', $sentAmount);
        $this->assertSame(20.0, $result['newly_refunded_amount']);
        $this->assertSame(20.0, (float) $result['payment']->amount);
    }

    public function test_other_gateway_error_is_failed_and_keeps_a_readable_message(): void
    {
        [$order] = $this->order();
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                $postCount++;

                return Http::response([
                    'code' => 'woocommerce_rest_cannot_create_order_refund',
                    'message' => 'Operator odrzucił zwrot z powodu blokady transakcji.',
                    'data' => ['status' => 500],
                ], 500);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refundOrder(
            $order,
            null,
            'Anulowanie',
            'refund-failed',
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame(1, $postCount);
        $this->assertSame('failed', $result['payment']->status);
        $this->assertStringContainsString('Operator odrzucił zwrot', $result['message']);
    }

    public function test_cancellation_wrapper_links_the_payment_and_uses_cancellation_purpose(): void
    {
        [$order] = $this->order();
        $cancellation = OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $order->id,
            'status' => 'processing',
            'reason' => 'Klientka zrezygnowała',
            'refund_status' => 'pending',
            'refund_amount' => 0,
            'currency' => 'PLN',
        ]);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123')) {
                return Http::response($this->wooOrder());
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/123/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST') {
                return Http::response([
                    'id' => 704,
                    'amount' => '100.00',
                    'refunded_payment' => true,
                ], 201);
            }

            return Http::response([], 404);
        });

        $result = app(WooCommerceRefundService::class)->refund($cancellation, 'cancel-refund-1');

        $this->assertSame('submitted', $result['status']);
        $this->assertSame($cancellation->id, $result['payment']->order_cancellation_id);
        $this->assertSame('order_cancellation', $result['payment']->purpose);
        $this->assertStringContainsString(
            '[ERP-REFUND:cancel-refund-1] Anulowanie zamówienia: Klientka zrezygnowała',
            (string) data_get($result, 'payment.metadata.woocommerce.request_payload.reason'),
        );
    }

    /**
     * @param  array<string, mixed>  $rawOverrides
     * @return array{ExternalOrder,WordpressIntegration}
     */
    private function order(string $externalId = '123', array $rawOverrides = []): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'REFUND-'.$externalId,
            'name' => 'Sklep refund '.$externalId,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo refund '.$externalId,
            'base_url' => 'https://refund-service.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_refund'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_refund'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $raw = array_merge($this->wooOrder($externalId), $rawOverrides);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => $externalId,
            'external_number' => $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'billing_data' => [],
            'shipping_data' => [],
            'raw_payload' => $raw,
        ]);

        return [$order, $integration];
    }

    /** @return array<string, mixed> */
    private function wooOrder(string $externalId = '123'): array
    {
        return [
            'id' => (int) $externalId,
            'number' => $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '100.00',
            'date_paid' => '2026-07-14T10:00:00',
            'date_paid_gmt' => '2026-07-14T08:00:00',
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'transaction_id' => 'PAYU-123',
            'refunds' => [],
        ];
    }

    private function payment(
        ExternalOrder $order,
        string $direction,
        string $status,
        float $amount,
        string $source = 'manual',
        string $currency = 'PLN',
    ): CustomerPayment {
        return CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'direction' => $direction,
            'source' => $source,
            'purpose' => 'manual_adjustment',
            'method' => 'other',
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    private function cancellation(ExternalOrder $order, string $reason): OrderCancellation
    {
        return OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $order->id,
            'status' => 'processing',
            'reason' => $reason,
            'refund_status' => 'pending',
            'refund_amount' => 0,
            'currency' => $order->currency,
        ]);
    }

    private function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }
}
