<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationStep;
use App\Models\SalesChannel;
use App\Models\User;
use App\Models\WordpressIntegration;
use App\Services\Payments\OrderSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderSettlementControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_woocommerce_refund_called_on_split_child_uses_root_and_is_posted_once(): void
    {
        [$root, $integration] = $this->order('5001');
        $child = $this->childOrder($root, $integration, '5001-SPLIT-1');
        $postCount = 0;

        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/5001')) {
                return Http::response($this->wooOrder('5001'));
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/5001/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/5001/refunds')) {
                $postCount++;

                return Http::response([
                    'id' => 9501,
                    'amount' => '40.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => true,
                ], 201);
            }

            return Http::response([], 404);
        });
        $operationId = (string) Str::uuid();
        $payload = [
            'amount' => '40.00',
            'currency' => 'PLN',
            'reason' => 'Częściowy zwrot dla klientki',
            'operation_id' => $operationId,
            'confirm_refund' => '1',
        ];

        $this->post(route('orders.refunds.woocommerce', $child), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->post(route('orders.refunds.woocommerce', $child), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, $postCount);
        $payment = CustomerPayment::query()->sole();
        $this->assertSame($root->id, $payment->external_order_id);
        $this->assertSame('order-refund:'.$root->id.':'.$operationId, $payment->idempotency_key);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('40.00', (string) $payment->amount);
    }

    public function test_manual_refund_is_idempotent_and_resolves_manual_required_cancellation_without_double_count(): void
    {
        $wooRefund = [
            'id' => 9502,
            'total' => '-100.00',
            'reason' => 'Zwrot zapisany bez bramki',
            'refunded_payment' => false,
        ];
        [$order] = $this->order('5002', [
            'refunds' => [$wooRefund],
        ]);
        $requiredPayment = CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'idempotency_key' => 'order-cancellation:manual-5002',
            'direction' => 'outgoing',
            'source' => 'woocommerce',
            'purpose' => 'order_cancellation',
            'method' => 'payu',
            'status' => 'manual_required',
            'amount' => 100,
            'currency' => 'PLN',
            'external_transaction_id' => '9502',
        ]);
        $cancellation = OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $order->id,
            'status' => 'attention_required',
            'reason' => 'Klientka zrezygnowała',
            'refund_status' => 'manual_required',
            'refund_amount' => 100,
            'currency' => 'PLN',
            'payment_method' => 'payu',
            'woo_refund_id' => '9502',
            'last_error' => 'Bramka wymaga zwrotu ręcznego.',
        ]);
        $refundStep = OrderCancellationStep::query()->create([
            'order_cancellation_id' => $cancellation->id,
            'step' => 'refund',
            'status' => 'attention_required',
            'idempotency_key' => 'cancellation-refund-step-5002',
            'last_error' => 'Bramka wymaga zwrotu ręcznego.',
            'response_payload' => ['status' => 'manual_required'],
        ]);
        foreach ([
            'preflight',
            'hold_fulfillment',
            'shipping',
            'warehouse_documents',
            'inventory_and_packing',
            'invoices',
            'woocommerce_and_local_status',
        ] as $stepName) {
            OrderCancellationStep::query()->create([
                'order_cancellation_id' => $cancellation->id,
                'step' => $stepName,
                'status' => 'completed',
                'idempotency_key' => 'cancellation-'.$stepName.'-5002',
                'response_payload' => [],
                'completed_at' => now(),
            ]);
        }
        $operationId = (string) Str::uuid();
        $payload = [
            'amount' => '100.00',
            'currency' => 'PLN',
            'method' => 'bank_transfer',
            'reference' => 'PRZELEW-5002',
            'reason' => 'Zwrot wykonany przelewem przez księgowość',
            'operation_id' => $operationId,
            'confirm_completed' => '1',
        ];
        $accountingPostCount = 0;
        Http::fake(function (Request $request) use ($wooRefund, &$accountingPostCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/5002/refunds')) {
                return Http::response([$wooRefund]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/5002')) {
                return Http::response(array_replace($this->wooOrder('5002'), [
                    'refunds' => [$wooRefund],
                ]));
            }

            if ($request->method() === 'POST') {
                $accountingPostCount++;
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $this->post(route('orders.refunds.manual', $order), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->post(route('orders.refunds.manual', $order), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(2, CustomerPayment::query()->count());
        $manual = CustomerPayment::query()->where('source', 'manual')->sole();
        $this->assertSame('manual-order-refund:'.$order->id.':'.$operationId, $manual->idempotency_key);
        $this->assertSame('paid', $manual->status);
        $this->assertSame($manual->id, data_get($requiredPayment->refresh()->metadata, 'manual_resolution.customer_payment_id'));
        $this->assertSame('completed', $refundStep->refresh()->status);
        $this->assertSame('manual_completed', $cancellation->refresh()->refund_status);
        $this->assertSame('completed', $cancellation->status);
        $this->assertSame(0, $accountingPostCount);
        $this->assertSame(
            'skipped',
            data_get($manual->fresh()->metadata, 'woocommerce_manual_refund_sync.status'),
        );

        $summary = app(OrderSettlementService::class)->summary($order->fresh());
        $this->assertSame(100.0, $summary['accounting_refunded_amount']);
        $this->assertSame(100.0, $summary['confirmed_refunded_amount']);
        $this->assertSame(0.0, $summary['unconfirmed_woo_refund_amount']);
        $this->assertSame(0.0, $summary['balance']);
    }

    public function test_manual_refund_resumes_cancellation_when_process_crashed_before_document_steps(): void
    {
        $wooRefund = [
            'id' => 9509,
            'total' => '-100.00',
            'reason' => 'Zwrot zapisany bez bramki',
            'refunded_payment' => false,
        ];
        [$order] = $this->order('5009', ['refunds' => [$wooRefund]]);
        $order->update(['status' => 'cancellation-pending']);
        $requiredPayment = CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'idempotency_key' => 'order-cancellation:manual-5009',
            'direction' => 'outgoing',
            'source' => 'woocommerce',
            'purpose' => 'order_cancellation',
            'method' => 'payu',
            'status' => 'manual_required',
            'amount' => 100,
            'currency' => 'PLN',
            'external_transaction_id' => '9509',
        ]);
        $cancellation = OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $order->id,
            'status' => 'processing',
            'reason' => 'Przerwane anulowanie po kroku refundu',
            'refund_status' => 'manual_required',
            'refund_amount' => 100,
            'currency' => 'PLN',
            'payment_method' => 'payu',
            'woo_refund_id' => '9509',
        ]);

        foreach (['preflight', 'hold_fulfillment', 'shipping'] as $stepName) {
            OrderCancellationStep::query()->create([
                'order_cancellation_id' => $cancellation->id,
                'step' => $stepName,
                'status' => 'completed',
                'idempotency_key' => 'cancellation-'.$stepName.'-5009',
                'response_payload' => [],
                'completed_at' => now(),
            ]);
        }
        OrderCancellationStep::query()->create([
            'order_cancellation_id' => $cancellation->id,
            'step' => 'refund',
            'status' => 'attention_required',
            'idempotency_key' => 'cancellation-refund-step-5009',
            'last_error' => 'Zwrot wymaga wypłaty ręcznej.',
            'response_payload' => ['status' => 'manual_required'],
        ]);

        $statusPutCount = 0;
        $refundPostCount = 0;
        Http::fake(function (Request $request) use ($wooRefund, &$statusPutCount, &$refundPostCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/5009/refunds')) {
                return Http::response([$wooRefund]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/5009')) {
                return Http::response(array_replace($this->wooOrder('5009'), [
                    'status' => 'cancelled',
                    'refunds' => [$wooRefund],
                ]));
            }

            if ($request->method() === 'PUT' && str_ends_with($this->path($request), '/orders/5009')) {
                $statusPutCount++;

                return Http::response(array_replace($this->wooOrder('5009'), [
                    'status' => 'cancelled',
                    'refunds' => [$wooRefund],
                ]));
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/5009/refunds')) {
                $refundPostCount++;
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $this->post(route('orders.refunds.manual', $order), [
            'amount' => '100.00',
            'currency' => 'PLN',
            'method' => 'bank_transfer',
            'reference' => 'PRZELEW-5009',
            'reason' => 'Zwrot wykonany po przerwanym procesie',
            'operation_id' => (string) Str::uuid(),
            'confirm_completed' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('completed', $cancellation->refresh()->status);
        $this->assertSame('manual_completed', $cancellation->refund_status);
        $this->assertSame(8, $cancellation->steps()->count());
        $this->assertFalse($cancellation->steps()->where('status', '!=', 'completed')->exists());
        $this->assertNotNull(data_get($requiredPayment->refresh()->metadata, 'manual_resolution.customer_payment_id'));
        $this->assertSame(1, $statusPutCount);
        $this->assertSame(0, $refundPostCount);
    }

    public function test_manual_incoming_top_up_is_idempotent_and_belongs_to_split_root(): void
    {
        [$root, $integration] = $this->order('5003');
        $child = $this->childOrder($root, $integration, '5003-SPLIT-1');
        $operationId = (string) Str::uuid();
        $payload = [
            'amount' => '20.00',
            'currency' => 'PLN',
            'method' => 'blik',
            'reference' => 'BLIK-5003',
            'description' => 'Dopłata za zmianę zamówienia',
            'operation_id' => $operationId,
        ];

        $this->post(route('orders.payments.store', $child), $payload)
            ->assertRedirect(route('orders.show', $root))
            ->assertSessionHas('status');
        $this->post(route('orders.payments.store', $child), $payload)
            ->assertRedirect(route('orders.show', $root))
            ->assertSessionHas('status');

        $payment = CustomerPayment::query()->sole();
        $this->assertSame($root->id, $payment->external_order_id);
        $this->assertSame('manual', $payment->source);
        $this->assertSame('manual_order_payment', $payment->purpose);
        $this->assertSame('manual-order-payment:'.$root->id.':'.$operationId, $payment->idempotency_key);

        $summary = app(OrderSettlementService::class)->summary($child);
        $this->assertSame(120.0, $summary['confirmed_paid_amount']);
        $this->assertSame(120.0, $summary['balance']);
    }

    public function test_unknown_manual_refund_woo_accounting_can_be_reconciled_without_second_post(): void
    {
        [$order] = $this->order('5010');
        $idempotencyKey = 'manual-order-refund:'.$order->id.':'.Str::uuid();
        $token = '[ERP-MANUAL-REFUND:'.$idempotencyKey.']';
        $payment = CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'idempotency_key' => $idempotencyKey,
            'direction' => 'outgoing',
            'source' => 'manual',
            'purpose' => 'manual_order_refund',
            'method' => 'bank_transfer',
            'status' => 'paid',
            'amount' => 100,
            'currency' => 'PLN',
            'reference' => 'PRZELEW-5010',
            'description' => 'Zwrot wykonany ręcznie',
            'requested_at' => now(),
            'booked_at' => now(),
            'paid_at' => now(),
            'metadata' => [
                'woocommerce_manual_refund_sync' => [
                    'status' => 'unknown',
                    'token' => $token,
                    'planned_accounting_amount' => 100,
                    'post_started_at' => now()->toISOString(),
                ],
            ],
        ]);
        $remoteRefund = [
            'id' => 9510,
            'amount' => '100.00',
            'reason' => $token.' Zwrot wykonany ręcznie',
            'refunded_payment' => false,
        ];
        $postCount = 0;
        Http::fake(function (Request $request) use ($remoteRefund, &$postCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/5010/refunds')) {
                return Http::response([$remoteRefund]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/5010')) {
                return Http::response(array_replace($this->wooOrder('5010'), [
                    'refunds' => [$remoteRefund],
                ]));
            }

            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Uzgodnij księgowanie Woo')
            ->assertSee(
                route('orders.refunds.manual.woocommerce-reconcile', [$order, $payment]),
                false,
            );

        $this->post(
            route('orders.refunds.manual.woocommerce-reconcile', [$order, $payment]),
            ['confirm_reconciliation' => '1'],
        )->assertRedirect(route('orders.show', $order))->assertSessionHas('status');

        $this->assertSame(0, $postCount);
        $this->assertSame(
            'success',
            data_get($payment->fresh()->metadata, 'woocommerce_manual_refund_sync.status'),
        );
        $this->assertSame(
            '9510',
            (string) data_get($payment->fresh()->metadata, 'woocommerce_manual_refund_sync.woo_refund_id'),
        );
    }

    public function test_online_manual_refund_is_rejected_without_prior_manual_required_result(): void
    {
        [$order] = $this->order('5004');

        $this->post(route('orders.refunds.manual', $order), [
            'amount' => '10.00',
            'currency' => 'PLN',
            'method' => 'bank_transfer',
            'reference' => 'NO-REFUND',
            'reason' => 'Próba ręcznego zwrotu online',
            'operation_id' => (string) Str::uuid(),
            'confirm_completed' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_generic_woo_refund_cannot_bypass_active_cancellation_shipping_gate(): void
    {
        [$order] = $this->order('5007', [
            'payment_method' => 'bacs',
            'payment_method_title' => 'Przelew tradycyjny',
        ]);
        $cancellation = OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $order->id,
            'status' => 'attention_required',
            'reason' => 'Anulacja wymaga potwierdzenia u kuriera',
            'refund_status' => 'pending',
            'refund_amount' => 0,
            'currency' => 'PLN',
        ]);
        OrderCancellationStep::query()->create([
            'order_cancellation_id' => $cancellation->id,
            'step' => 'shipping',
            'status' => 'attention_required',
            'idempotency_key' => 'cancellation-shipping-step-5007',
            'last_error' => 'Etykietę trzeba anulować ręcznie.',
        ]);
        $postCount = 0;
        Http::fake(function (Request $request) use (&$postCount) {
            if ($request->method() === 'POST') {
                $postCount++;
            }

            return Http::response([], 500);
        });

        $this->post(route('orders.refunds.woocommerce', $order), [
            'amount' => '100.00',
            'currency' => 'PLN',
            'reason' => 'Nie wolno ominąć bramki wysyłki',
            'operation_id' => (string) Str::uuid(),
            'confirm_refund' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $this->post(route('orders.refunds.manual', $order), [
            'amount' => '100.00',
            'currency' => 'PLN',
            'method' => 'bank_transfer',
            'reference' => 'SHIPPING-GATE-5007',
            'reason' => 'Nie wolno ominąć bramki wysyłki',
            'operation_id' => (string) Str::uuid(),
            'confirm_completed' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertSame(0, $postCount);
        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_packer_cannot_call_financial_endpoints(): void
    {
        [$order] = $this->order('5005');
        $packer = User::query()->create([
            'name' => 'Pakowanie',
            'email' => 'settlement-packer@example.test',
            'password' => 'test-password',
            'role' => User::ROLE_PACKER,
            'is_active' => true,
        ]);
        $this->actingAs($packer);

        $this->post(route('orders.refunds.woocommerce', $order), [])->assertForbidden();
        $this->post(route('orders.refunds.manual', $order), [])->assertForbidden();
        $this->post(route('orders.payments.store', $order), [])->assertForbidden();
    }

    public function test_operator_and_accounting_can_open_the_settlement_panel(): void
    {
        [$order] = $this->order('5008');

        foreach ([User::ROLE_OPERATOR, User::ROLE_ACCOUNTING] as $role) {
            $user = User::query()->create([
                'name' => ucfirst($role),
                'email' => 'settlement-'.$role.'@example.test',
                'password' => 'test-password',
                'role' => $role,
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->get(route('orders.show', $order))
                ->assertOk()
                ->assertSee('Rozliczenia klienta');
        }
    }

    public function test_show_exposes_cancellation_settlement_and_child_links_to_root_without_actions(): void
    {
        [$root, $integration] = $this->order('5006');
        $child = $this->childOrder($root, $integration, '5006-SPLIT-1');
        OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $root->id,
            'status' => 'attention_required',
            'reason' => 'Anulacja po telefonie klientki',
            'refund_status' => 'unknown',
            'refund_amount' => 100,
            'currency' => 'PLN',
            'payment_method' => 'payu',
            'last_error' => 'Nieznany wynik połączenia z operatorem.',
        ]);
        CustomerPayment::query()->create([
            'external_order_id' => $child->id,
            'direction' => 'outgoing',
            'source' => 'manual',
            'purpose' => 'legacy_child_refund',
            'method' => 'bank_transfer',
            'status' => 'paid',
            'amount' => 15,
            'currency' => 'PLN',
            'reference' => 'CHILD-REFUND-5006',
            'booked_at' => now(),
            'paid_at' => now(),
        ]);

        $summary = app(OrderSettlementService::class)->summary($root);
        $this->assertSame(15.0, $summary['erp']['confirmed']['outgoing']);
        $this->assertSame(15.0, $summary['confirmed_refunded_amount']);

        $response = $this->get(route('orders.show', $child));

        $response
            ->assertOk()
            ->assertSee('Rozliczenia należą wyłącznie do zamówienia głównego')
            ->assertSee(route('orders.show', $root).'#rozliczenia-zamowienia', false)
            ->assertSee('Wynik nieznany — nie ponawiać')
            ->assertSee('Anulacja po telefonie klientki')
            ->assertSee('Nieznany wynik połączenia z operatorem.')
            ->assertSee('CHILD-REFUND-5006')
            ->assertDontSee('action="'.route('orders.refunds.woocommerce', $child).'"', false)
            ->assertDontSee('action="'.route('orders.refunds.manual', $child).'"', false);
    }

    /**
     * @param  array<string, mixed>  $rawOverrides
     * @return array{ExternalOrder,WordpressIntegration}
     */
    private function order(string $externalId, array $rawOverrides = []): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'SET-'.$externalId,
            'name' => 'Settlement '.$externalId,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo settlement '.$externalId,
            'base_url' => 'https://settlement-'.$externalId.'.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_settlement'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_settlement'),
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
            'billing_data' => ['email' => 'client-'.$externalId.'@example.test'],
            'shipping_data' => [],
            'raw_payload' => $raw,
        ]);

        return [$order, $integration];
    }

    private function childOrder(
        ExternalOrder $root,
        WordpressIntegration $integration,
        string $externalId,
    ): ExternalOrder {
        return ExternalOrder::query()->create([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
            'sales_channel_id' => $root->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => $externalId,
            'external_number' => $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 25,
            'billing_data' => $root->billing_data,
            'shipping_data' => [],
            'raw_payload' => array_merge($this->wooOrder($externalId), ['total' => '25.00']),
        ]);
    }

    /** @return array<string, mixed> */
    private function wooOrder(string $externalId): array
    {
        return [
            'id' => $externalId,
            'number' => $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '100.00',
            'date_paid' => '2026-07-14T10:00:00',
            'date_paid_gmt' => '2026-07-14T08:00:00',
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'transaction_id' => 'PAYU-'.$externalId,
            'refunds' => [],
        ];
    }

    private function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }
}
