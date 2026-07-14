<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\OrderCancellation;
use App\Models\SalesChannel;
use App\Models\User;
use App\Models\WordpressIntegration;
use App\Services\Orders\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderCancellationRefundReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_operator_reconciles_remote_refund_after_ambiguous_result_without_second_post(): void
    {
        $order = $this->order(1701);
        $operator = $this->user('operator-reconcile@example.test', User::ROLE_OPERATOR);
        $wooOrder = $this->wooOrder($order);
        $refundPostCount = 0;
        $statusPutCount = 0;
        $remoteRefunds = [];

        $this->actingAs($operator);
        $this->fakeRefundWorkflow($wooOrder, $remoteRefunds, $refundPostCount, $statusPutCount);

        $first = app(OrderCancellationService::class)->cancel(
            $order,
            'Klientka zrezygnowała z opłaconego zamówienia',
            $operator->id,
        );

        $this->assertTrue($first['attention_required']);
        $this->assertSame(1, $refundPostCount);
        $this->assertSame(1, $statusPutCount);
        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee(route('orders.cancellation.refund-reconcile', $order), false)
            ->assertSee('Uzgodnij wynik zwrotu z WooCommerce');

        $cancellation = OrderCancellation::query()->sole();
        $token = '[ERP-REFUND:order-cancellation:'.$cancellation->uuid.']';
        $remoteRefunds = [[
            'id' => 91701,
            'amount' => '125.00',
            'currency' => 'PLN',
            'reason' => $token.' Klientka zrezygnowała z opłaconego zamówienia',
            'refunded_payment' => true,
        ]];

        $this->post(route('orders.cancellation.refund-reconcile', $order), [
            'confirm_reconciliation' => '1',
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'Wynik zwrotu uzgodniono z WooCommerce bez ponownego wysyłania cashbacku.');

        $cancellation->refresh();
        $payment = CustomerPayment::query()->sole();
        $refundStep = $cancellation->steps()->where('step', 'refund')->sole();

        $this->assertSame(1, $refundPostCount);
        $this->assertSame(1, $statusPutCount);
        $this->assertSame('completed', $cancellation->status);
        $this->assertSame('submitted', $cancellation->refund_status);
        $this->assertSame('91701', $cancellation->woo_refund_id);
        $this->assertSame('completed', $refundStep->status);
        $this->assertSame(2, $refundStep->attempts);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('91701', $payment->external_transaction_id);
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'order.cancellation_refund_reconciliation_requested')->count(),
        );
    }

    public function test_reconciliation_without_matching_remote_token_stays_unknown_without_second_post(): void
    {
        $order = $this->order(1702);
        $operator = $this->user('operator-no-token@example.test', User::ROLE_OPERATOR);
        $wooOrder = $this->wooOrder($order);
        $refundPostCount = 0;
        $statusPutCount = 0;
        $remoteRefunds = [];

        $this->actingAs($operator);
        $this->fakeRefundWorkflow($wooOrder, $remoteRefunds, $refundPostCount, $statusPutCount);

        app(OrderCancellationService::class)->cancel(
            $order,
            'Zwrot o niepewnym wyniku do późniejszego uzgodnienia',
            $operator->id,
        );

        $this->post(route('orders.cancellation.refund-reconcile', $order), [
            'confirm_reconciliation' => 'yes',
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('error');

        $cancellation = OrderCancellation::query()->sole();
        $refundStep = $cancellation->steps()->where('step', 'refund')->sole();

        $this->assertSame(1, $refundPostCount);
        $this->assertSame(1, $statusPutCount);
        $this->assertSame('attention_required', $cancellation->status);
        $this->assertSame('unknown', $cancellation->refund_status);
        $this->assertSame('unknown', $refundStep->status);
        $this->assertSame(2, $refundStep->attempts);
        $this->assertSame('unknown', CustomerPayment::query()->sole()->status);
    }

    public function test_reconciliation_is_refused_without_existing_protected_payment(): void
    {
        $order = $this->order(1703);
        $operator = $this->user('operator-no-payment@example.test', User::ROLE_OPERATOR);
        $cancellation = OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $order->id,
            'requested_by' => $operator->id,
            'status' => 'attention_required',
            'reason' => 'Nieznany wynik zwrotu bez bezpiecznego rekordu płatności',
            'refund_status' => 'unknown',
            'currency' => 'PLN',
            'started_at' => now(),
            'metadata' => [
                'source' => 'order_edit',
                'context' => [],
            ],
        ]);
        $step = $cancellation->steps()->create([
            'step' => 'refund',
            'status' => 'unknown',
            'attempts' => 1,
            'idempotency_key' => 'order-cancellation:'.$cancellation->uuid.':refund',
        ]);
        Http::fake();

        $this->actingAs($operator)
            ->post(route('orders.cancellation.refund-reconcile', $order), [
                'confirm_reconciliation' => '1',
            ])->assertSessionHas('error', function (string $message): bool {
                return str_contains($message, 'Brak istniejącej operacji zwrotu');
            });

        $this->assertSame('attention_required', $cancellation->fresh()->status);
        $this->assertSame('unknown', $step->fresh()->status);
        $this->assertDatabaseCount('customer_payments', 0);
        Http::assertNothingSent();
    }

    public function test_packer_cannot_request_refund_reconciliation(): void
    {
        $order = $this->order(1704);
        $packer = $this->user('packer-reconcile@example.test', User::ROLE_PACKER);

        $this->actingAs($packer)
            ->post(route('orders.cancellation.refund-reconcile', $order), [
                'confirm_reconciliation' => '1',
            ])->assertForbidden();

        $this->assertDatabaseCount('order_cancellations', 0);
    }

    /**
     * @param  array<string, mixed>  $wooOrder
     */
    private function fakeRefundWorkflow(
        array $wooOrder,
        array &$remoteRefunds,
        int &$refundPostCount,
        int &$statusPutCount,
    ): void {
        $externalId = (string) $wooOrder['id'];

        Http::fake(function (Request $request) use ($wooOrder, $externalId, &$remoteRefunds, &$refundPostCount, &$statusPutCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), "/orders/{$externalId}/refunds")) {
                return Http::response($remoteRefunds);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), "/orders/{$externalId}")) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), "/orders/{$externalId}/refunds")) {
                $refundPostCount++;

                throw new ConnectionException('Connection reset after refund was accepted');
            }

            if ($request->method() === 'PUT' && str_ends_with($this->path($request), "/orders/{$externalId}")) {
                $statusPutCount++;

                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });
    }

    private function order(int $externalId): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'code' => 'RECON-'.$externalId,
            'name' => 'Sklep uzgodnienia '.$externalId,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo uzgodnienia '.$externalId,
            'base_url' => 'https://reconcile-'.$externalId.'.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_reconcile_'.$externalId),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_reconcile_'.$externalId),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $rawPayload = [
            'id' => $externalId,
            'number' => (string) $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '125.00',
            'date_paid' => '2026-07-14T10:00:00',
            'date_paid_gmt' => '2026-07-14T08:00:00',
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'transaction_id' => 'TX-'.$externalId,
            'refunds' => [],
        ];

        return ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => (string) $externalId,
            'external_number' => (string) $externalId,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 125,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'email' => 'anna-'.$externalId.'@example.test',
            ],
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
            ],
            'raw_payload' => $rawPayload,
            'external_created_at' => now()->subHour(),
        ]);
    }

    /** @return array<string, mixed> */
    private function wooOrder(ExternalOrder $order): array
    {
        return (array) $order->raw_payload;
    }

    private function user(string $email, string $role): User
    {
        return User::query()->create([
            'name' => $role.' refund reconciliation test',
            'email' => $email,
            'password' => 'test-password-not-for-production',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }
}
