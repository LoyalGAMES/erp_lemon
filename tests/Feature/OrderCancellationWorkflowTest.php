<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\OrderCancellation;
use App\Models\OrderCancellationStep;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Orders\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderCancellationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_unpaid_order_is_fully_cancelled_through_http_and_retry_is_idempotent(): void
    {
        $context = $this->orderContext(1101, withOperations: true);
        $order = $context['order'];
        $wooOrder = $this->wooOrder($order, paid: false);
        $statusPutCount = 0;
        $refundPostCount = 0;

        Http::fake(function (Request $request) use ($wooOrder, &$statusPutCount, &$refundPostCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1101/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1101')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/1101/refunds')) {
                $refundPostCount++;

                return Http::response([], 500);
            }

            if ($request->method() === 'PUT' && str_ends_with($this->path($request), '/orders/1101')) {
                $statusPutCount++;

                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $this->post(route('orders.cancel', $order), [
            'reason' => 'Klientka zrezygnowała przed wysyłką',
            'confirm_cancellation' => '1',
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status');

        $cancellation = OrderCancellation::query()->where('external_order_id', $order->id)->firstOrFail();

        $this->assertSame('completed', $cancellation->status);
        $this->assertSame('not_required', $cancellation->refund_status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $context['wz']->fresh()->status);
        $this->assertNotNull($context['wz']->fresh()->cancelled_at);
        $this->assertSame('released', $context['reservation']->fresh()->status);
        $this->assertNotNull($context['reservation']->fresh()->released_at);
        $this->assertSame('0.0000', (string) $context['balance']->fresh()->quantity_reserved);
        $this->assertSame('cancelled', $context['packingTask']->fresh()->status);
        $this->assertSame(
            $cancellation->uuid,
            data_get($context['packingTask']->fresh()->metadata, 'order_cancellation.operation_uuid'),
        );
        $this->assertSame('cancelled', $context['proforma']->fresh()->status);
        $this->assertSame(
            $cancellation->uuid,
            data_get($context['proforma']->fresh()->metadata, 'order_cancellation.operation_uuid'),
        );
        $this->assertSame(8, $cancellation->steps()->count());
        $this->assertSame(8, $cancellation->steps()->where('status', 'completed')->count());
        $this->assertSame(1, $statusPutCount);
        $this->assertSame(0, $refundPostCount);
        $this->assertDatabaseCount('customer_payments', 0);

        $this->post(route('orders.cancel', $order), [
            'reason' => 'Ponowienie tego samego polecenia',
            'confirm_cancellation' => '1',
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', 'To zamówienie było już w pełni anulowane.');

        $this->assertSame(1, OrderCancellation::query()->count());
        $this->assertSame(8, OrderCancellationStep::query()->count());
        $this->assertSame(1, WarehouseDocument::query()->count());
        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, $statusPutCount);
        $this->assertSame(0, $refundPostCount);
    }

    public function test_paid_order_is_refunded_via_original_gateway_exactly_once(): void
    {
        $order = $this->orderContext(1201)['order'];
        $wooOrder = $this->wooOrder($order, paid: true);
        $refundPostCount = 0;
        $statusPutCount = 0;

        Http::fake(function (Request $request) use ($wooOrder, &$refundPostCount, &$statusPutCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1201/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1201')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/1201/refunds')) {
                $refundPostCount++;

                return Http::response([
                    'id' => 91201,
                    'amount' => '125.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => true,
                ], 201);
            }

            if ($request->method() === 'PUT' && str_ends_with($this->path($request), '/orders/1201')) {
                $statusPutCount++;

                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $service = app(OrderCancellationService::class);
        $first = $service->cancel($order, 'Rezygnacja klientki', auth()->id());
        $second = $service->cancel($order, 'Ponowienie anulowania', auth()->id());

        $this->assertFalse($first['already_completed']);
        $this->assertTrue($second['already_completed']);
        $this->assertFalse($first['attention_required']);
        $this->assertSame(1, $refundPostCount);
        $this->assertSame(1, $statusPutCount);

        $cancellation = $first['cancellation']->fresh();
        $payment = CustomerPayment::query()->sole();

        $this->assertSame('completed', $cancellation->status);
        $this->assertSame('submitted', $cancellation->refund_status);
        $this->assertSame('125.00', (string) $cancellation->refund_amount);
        $this->assertSame('payu', $cancellation->payment_method);
        $this->assertSame('91201', $cancellation->woo_refund_id);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('outgoing', $payment->direction);
        $this->assertSame('woocommerce', $payment->source);
        $this->assertSame('order_cancellation', $payment->purpose);
        $this->assertSame($cancellation->id, $payment->order_cancellation_id);
        $this->assertSame('payu', $payment->method);
        $this->assertStringContainsString('order-cancellation:', (string) $payment->idempotency_key);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_cancellation_stays_attention_required_until_manual_top_up_is_returned(): void
    {
        $order = $this->orderContext(1221)['order'];
        $wooOrder = $this->wooOrder($order, paid: true);
        CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'incoming',
            'source' => 'manual',
            'purpose' => 'manual_order_payment',
            'method' => 'bank_transfer',
            'status' => 'paid',
            'amount' => 25,
            'currency' => 'PLN',
            'booked_at' => now(),
            'paid_at' => now(),
        ]);
        $refundPostCount = 0;
        $remoteRefunds = [];

        Http::fake(function (Request $request) use ($wooOrder, &$refundPostCount, &$remoteRefunds) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1221/refunds')) {
                return Http::response($remoteRefunds);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1221')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/1221/refunds')) {
                $refundPostCount++;
                $remoteRefund = [
                    'id' => 91221,
                    'amount' => '125.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => true,
                ];
                $remoteRefunds = [$remoteRefund];

                return Http::response($remoteRefund, 201);
            }

            if ($request->method() === 'PUT' && str_ends_with($this->path($request), '/orders/1221')) {
                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $result = app(OrderCancellationService::class)->cancel(
            $order,
            'Anulowanie zamówienia z ręczną dopłatą',
        );

        $cancellation = $result['cancellation']->fresh();
        $this->assertTrue($result['attention_required']);
        $this->assertSame(1, $refundPostCount);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('attention_required', $cancellation->status);
        $this->assertSame('manual_required', $cancellation->refund_status);
        $this->assertSame('25.00', (string) $cancellation->refund_amount);
        $this->assertSame(
            'attention_required',
            $cancellation->steps()->where('step', 'refund')->sole()->status,
        );

        $this->post(route('orders.refunds.manual', $order), [
            'amount' => '25.00',
            'currency' => 'PLN',
            'method' => 'bank_transfer',
            'reference' => 'TOPUP-RETURN-1221',
            'reason' => 'Zwrot ręcznej dopłaty po anulowaniu',
            'operation_id' => (string) Str::uuid(),
            'confirm_completed' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $cancellation->refresh();
        $this->assertSame('completed', $cancellation->status);
        $this->assertSame('manual_completed', $cancellation->refund_status);
        $this->assertSame(1, $refundPostCount);
        $this->assertSame(
            25.0,
            (float) CustomerPayment::query()
                ->where('purpose', 'manual_order_refund')
                ->sole()
                ->amount,
        );
    }

    public function test_manual_shipping_gate_stops_before_refund_and_resumes_once_after_confirmation(): void
    {
        $context = $this->orderContext(1251, withOperations: true);
        $order = $context['order'];
        $wooOrder = $this->wooOrder($order, paid: true);
        $refundPostCount = 0;
        $statusPutCount = 0;

        $pickedTask = $this->createPackingTask($order, $context['product']);
        $pickedTask->update([
            'external_line_id' => 'LINE-1251-PICKED',
            'quantity_picked' => 1,
            'status' => 'picked',
            'picked_at' => now(),
        ]);
        $problemTask = $this->createPackingTask($order, $context['product']);
        $problemTask->update([
            'external_line_id' => 'LINE-1251-PROBLEM',
            'status' => 'problem',
            'metadata' => [
                'packing_problem' => [
                    'reason' => 'Brak produktu na półce',
                    'reported_at' => now()->toISOString(),
                ],
            ],
        ]);

        ShippingLabel::query()->create([
            'sales_channel_id' => $context['channel']->id,
            'external_order_id' => $order->id,
            'wordpress_integration_id' => $context['integration']->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:manual-gate:'.$order->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'SHIP-CONFIRMED-'.$order->id,
            'disk' => 'local',
            'path' => 'shipping-labels/manual-gate-'.$order->id.'.zpl',
            'mime_type' => 'application/zpl',
            'response_payload' => ['shipment' => ['status' => 'confirmed']],
            'generated_at' => now(),
        ]);

        Http::fake(function (Request $request) use ($wooOrder, &$refundPostCount, &$statusPutCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1251/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1251')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/1251/refunds')) {
                $refundPostCount++;

                return Http::response([
                    'id' => 91251,
                    'amount' => '125.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => true,
                ], 201);
            }

            if ($request->method() === 'PUT' && str_ends_with($this->path($request), '/orders/1251')) {
                $statusPutCount++;

                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $service = app(OrderCancellationService::class);
        $stopped = $service->cancel($order, 'Anulowanie z potwierdzoną etykietą InPost');

        $this->assertTrue($stopped['attention_required']);
        $this->assertSame(0, $refundPostCount);
        $this->assertSame(0, $statusPutCount);
        $this->assertSame('cancellation-pending', $order->fresh()->status);
        $this->assertSame('draft', $context['wz']->fresh()->status);
        $this->assertSame('open', $context['packingTask']->fresh()->status);
        $this->assertDatabaseCount('customer_payments', 0);

        $cancellation = OrderCancellation::query()->sole();
        $shippingStep = $cancellation->steps()->where('step', 'shipping')->sole();
        $this->assertSame('attention_required', $cancellation->status);
        $this->assertSame('attention_required', $shippingStep->status);
        $this->assertNotEmpty(data_get($shippingStep->response_payload, 'manual_required'));
        $this->assertFalse($cancellation->steps()->where('step', 'refund')->exists());
        $this->assertFalse($cancellation->steps()->where('step', 'woocommerce_and_local_status')->exists());

        $this->post(route('packing.scan'), ['code' => $context['product']->sku])
            ->assertRedirect()
            ->assertSessionHas('error', fn (mixed $message): bool => str_contains(
                mb_strtolower((string) $message),
                'anulowan',
            ));

        $this->postJson(route('packing.groups.pick'), ['task_ids' => [$context['packingTask']->id]])
            ->assertConflict()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', fn (mixed $message): bool => str_contains(
                mb_strtolower((string) $message),
                'anulowan',
            ));

        $this->post(route('packing.tasks.pack', $pickedTask))
            ->assertRedirect()
            ->assertSessionHas('error', fn (mixed $message): bool => str_contains(
                mb_strtolower((string) $message),
                'anulowan',
            ));

        $this->post(route('packing.tasks.reopen', $problemTask))
            ->assertRedirect()
            ->assertSessionHas('error', fn (mixed $message): bool => str_contains(
                mb_strtolower((string) $message),
                'anulowan',
            ));

        $this->assertSame('open', $context['packingTask']->fresh()->status);
        $this->assertSame('0.0000', (string) $context['packingTask']->fresh()->quantity_picked);
        $this->assertSame('picked', $pickedTask->fresh()->status);
        $this->assertNull($pickedTask->fresh()->packed_at);
        $this->assertSame('problem', $problemTask->fresh()->status);
        $this->assertSame('Brak produktu na półce', data_get($problemTask->fresh()->metadata, 'packing_problem.reason'));

        $completed = $service->confirmManualShippingCancellation(
            $order->fresh(),
            null,
            'Przesyłkę anulowano ręcznie w panelu InPost.',
        );

        $this->assertFalse($completed['attention_required']);
        $this->assertSame(1, $refundPostCount);
        $this->assertSame(1, $statusPutCount);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $context['wz']->fresh()->status);
        $this->assertSame('cancelled', $context['packingTask']->fresh()->status);
        $this->assertSame('cancelled', $pickedTask->fresh()->status);
        $this->assertSame('cancelled', $problemTask->fresh()->status);
        $this->assertDatabaseCount('customer_payments', 1);

        $cancellation->refresh();
        $shippingStep->refresh();
        $this->assertSame('completed', $cancellation->status);
        $this->assertSame('submitted', $cancellation->refund_status);
        $this->assertSame('completed', $shippingStep->status);
        $this->assertSame([], data_get($shippingStep->response_payload, 'manual_required'));
        $this->assertNotEmpty(data_get($shippingStep->response_payload, 'resolved_manual_required'));
        $this->assertSame(
            'Przesyłkę anulowano ręcznie w panelu InPost.',
            data_get($shippingStep->response_payload, 'manual_confirmation.note'),
        );
        $this->assertSame(2, $cancellation->steps()->where('step', 'preflight')->sole()->attempts);
    }

    public function test_rejected_cancellation_does_not_block_packing_scan(): void
    {
        $context = $this->orderContext(1261, withOperations: true);

        OrderCancellation::query()->create([
            'uuid' => (string) Str::uuid(),
            'external_order_id' => $context['order']->id,
            'status' => 'rejected',
            'reason' => 'Nieudana próba anulacji',
            'refund_status' => 'not_required',
            'refund_amount' => 0,
            'currency' => 'PLN',
        ]);

        $this->post(route('packing.scan'), ['code' => $context['product']->sku])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('picked', $context['packingTask']->fresh()->status);
        $this->assertSame('1.0000', (string) $context['packingTask']->fresh()->quantity_picked);
    }

    public function test_gateway_without_automatic_refund_is_marked_for_manual_attention_without_double_post(): void
    {
        $order = $this->orderContext(1301)['order'];
        $wooOrder = $this->wooOrder($order, paid: true, paymentMethod: 'bacs', paymentTitle: 'Przelew bankowy');
        $refundPostCount = 0;
        $statusPutCount = 0;

        Http::fake(function (Request $request) use ($wooOrder, &$refundPostCount, &$statusPutCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1301/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1301')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/1301/refunds')) {
                $refundPostCount++;

                return Http::response([
                    'id' => 91301,
                    'amount' => '125.00',
                    'reason' => $request['reason'],
                    'refunded_payment' => false,
                ], 201);
            }

            if ($request->method() === 'PUT' && str_ends_with($this->path($request), '/orders/1301')) {
                $statusPutCount++;

                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $service = app(OrderCancellationService::class);
        $first = $service->cancel($order, 'Anulowanie opłaconego przelewem zamówienia', auth()->id());
        $second = $service->cancel($order, 'Kontynuacja po ostrzeżeniu', auth()->id());

        $this->assertTrue($first['attention_required']);
        $this->assertTrue($second['attention_required']);
        $this->assertSame(1, $refundPostCount);
        $this->assertSame(1, $statusPutCount);

        $cancellation = OrderCancellation::query()->sole();
        $refundStep = $cancellation->steps()->where('step', 'refund')->sole();
        $payment = CustomerPayment::query()->sole();

        $this->assertSame('attention_required', $cancellation->status);
        $this->assertSame('manual_required', $cancellation->refund_status);
        $this->assertSame('attention_required', $refundStep->status);
        $this->assertSame(1, $refundStep->attempts);
        $this->assertSame('manual_required', $payment->status);
        $this->assertSame('bacs', $payment->method);
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_ambiguous_refund_timeout_is_recorded_and_is_never_posted_again(): void
    {
        $order = $this->orderContext(1401, withOperations: true)['order'];
        $wooOrder = $this->wooOrder($order, paid: true);
        $refundPostCount = 0;
        $statusPutCount = 0;

        Http::fake(function (Request $request) use ($wooOrder, &$refundPostCount, &$statusPutCount) {
            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1401/refunds')) {
                return Http::response([]);
            }

            if ($request->method() === 'GET' && str_ends_with($this->path($request), '/orders/1401')) {
                return Http::response($wooOrder);
            }

            if ($request->method() === 'POST' && str_ends_with($this->path($request), '/orders/1401/refunds')) {
                $refundPostCount++;

                throw new ConnectionException('Connection reset after accepting the refund');
            }

            if ($request->method() === 'PUT') {
                $statusPutCount++;

                return Http::response(array_replace($wooOrder, ['status' => 'cancelled']));
            }

            return Http::response(['message' => 'unexpected request'], 500);
        });

        $service = app(OrderCancellationService::class);
        $first = $service->cancel($order, 'Anulowanie z niepewnym wynikiem bramki', auth()->id());
        $second = $service->cancel($order, 'Nie wysyłaj zwrotu ponownie', auth()->id());

        $this->assertTrue($first['attention_required']);
        $this->assertTrue($second['attention_required']);
        $this->assertSame(1, $refundPostCount);
        $this->assertSame(1, $statusPutCount);

        $cancellation = OrderCancellation::query()->sole();
        $refundStep = $cancellation->steps()->where('step', 'refund')->sole();

        $this->assertSame('attention_required', $cancellation->status);
        $this->assertSame('unknown', $cancellation->refund_status);
        $this->assertSame('unknown', $refundStep->status);
        $this->assertSame(1, $refundStep->attempts);
        $this->assertSame('unknown', CustomerPayment::query()->sole()->status);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', WarehouseDocument::query()->sole()->status);
        $this->assertSame('cancelled', PackingTask::query()->sole()->status);
        $this->assertSame('released', StockReservation::query()->sole()->status);
        $this->assertSame('cancelled', Invoice::query()->sole()->status);
        $this->assertSame('completed', OrderCancellationStep::query()->where('step', 'shipping')->sole()->status);
        $this->assertSame('completed', OrderCancellationStep::query()->where('step', 'woocommerce_and_local_status')->sole()->status);
    }

    #[DataProvider('blockedCancellationCases')]
    public function test_dispatched_or_returned_order_is_rejected_before_any_side_effect(string $block): void
    {
        $number = match ($block) {
            'shipped' => 1501,
            'picked_up' => 1502,
            'return_case' => 1503,
        };
        $context = $this->orderContext($number);
        $order = $context['order'];

        if ($block === 'shipped') {
            $order->update(['fulfillment_status' => 'shipped']);
        } elseif ($block === 'picked_up') {
            ShippingLabel::query()->create([
                'sales_channel_id' => $context['channel']->id,
                'external_order_id' => $order->id,
                'wordpress_integration_id' => $context['integration']->id,
                'purpose' => 'shipment',
                'status' => 'picked_up',
                'provider' => 'inpost',
                'label_number' => 'SHIP-'.$number,
                'picked_up_at' => now(),
                'disk' => 'local',
                'path' => 'labels/'.$number.'.pdf',
                'generated_at' => now()->subHour(),
            ]);
        } else {
            ReturnCase::query()->create([
                'number' => 'RET/'.$number,
                'store_return_reference' => 'RET-'.$number,
                'external_order_id' => $order->id,
                'status' => 'opened',
                'reason' => 'Zwrot już rozpoczęty',
            ]);
        }

        Http::fake();

        try {
            app(OrderCancellationService::class)->cancel($order, 'Próba niedozwolonej anulacji', auth()->id());
            $this->fail('Anulowanie powinno zostać zablokowane przez preflight.');
        } catch (RuntimeException $exception) {
            $expected = $block === 'return_case' ? 'zwrot' : 'odebrana przez kuriera';

            $this->assertStringContainsString($expected, mb_strtolower($exception->getMessage()));
        }

        $cancellation = OrderCancellation::query()->sole();

        $this->assertSame('rejected', $cancellation->status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('failed', $cancellation->steps()->where('step', 'preflight')->sole()->status);
        $this->assertSame(1, $cancellation->steps()->count());
        $this->assertDatabaseCount('customer_payments', 0);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function blockedCancellationCases(): array
    {
        return [
            'status wysłany' => ['shipped'],
            'paczka odebrana' => ['picked_up'],
            'rozpoczęty zwrot' => ['return_case'],
        ];
    }

    public function test_operator_can_see_and_submit_cancellation_but_packer_cannot(): void
    {
        $context = $this->orderContext(1601);
        $order = $context['order'];
        $this->createPackingTask($order, $context['product']);
        $operator = $this->user('operator@example.test', User::ROLE_OPERATOR);
        $packer = $this->user('packer@example.test', User::ROLE_PACKER);

        $this->actingAs($operator)
            ->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('<button class="button order-cancel-button" type="button" data-order-cancel-open>', escape: false)
            ->assertSee('Anuluj zamówienie')
            ->assertSee(route('orders.cancel', $order), escape: false);

        $this->post(route('orders.cancel', $order), [])
            ->assertSessionHasErrors(['reason', 'confirm_cancellation']);

        $this->actingAs($packer)
            ->get(route('orders.edit', ['order' => $order, 'return_to' => 'packing']))
            ->assertOk()
            ->assertDontSee('<button class="button order-cancel-button" type="button" data-order-cancel-open>', escape: false)
            ->assertDontSee(route('orders.cancel', $order), escape: false);

        $this->post(route('orders.cancel', $order), [
            'reason' => 'Pakujący nie może anulować finansowo',
            'confirm_cancellation' => '1',
        ])->assertForbidden();

        $this->assertDatabaseCount('order_cancellations', 0);
    }

    /**
     * @return array{
     *     channel:SalesChannel,
     *     integration:WordpressIntegration,
     *     warehouse:Warehouse,
     *     product:Product,
     *     balance:StockBalance,
     *     order:ExternalOrder,
     *     reservation?:StockReservation,
     *     packingTask?:PackingTask,
     *     wz?:WarehouseDocument,
     *     proforma?:Invoice
     * }
     */
    private function orderContext(int $number, bool $withOperations = false): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'CANCEL-'.$number,
            'name' => 'Sklep anulowanie '.$number,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo anulowanie '.$number,
            'base_url' => 'https://cancel-'.$number.'.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_cancel_'.$number),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_cancel_'.$number),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'MAG-'.$number,
            'name' => 'Magazyn '.$number,
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);
        $product = Product::query()->create([
            'sku' => 'CANCEL-SKU-'.$number,
            'name' => 'Koszula anulowanie '.$number,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $balance = StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => 10,
            'quantity_reserved' => $withOperations ? 1 : 0,
            'quantity_available' => $withOperations ? 9 : 10,
        ]);
        $rawPayload = [
            'id' => $number,
            'number' => (string) $number,
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '125.00',
            'date_paid' => null,
            'date_paid_gmt' => null,
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'transaction_id' => '',
            'refunds' => [],
        ];
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => (string) $number,
            'external_number' => (string) $number,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 125,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'email' => 'anna-'.$number.'@example.test',
                'country' => 'PL',
            ],
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Nowak',
                'address_1' => 'Testowa 1',
                'city' => 'Warszawa',
                'postcode' => '00-001',
                'country' => 'PL',
            ],
            'raw_payload' => $rawPayload,
            'external_created_at' => now()->subHour(),
        ]);
        $line = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'LINE-'.$number,
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_net_price' => 101.63,
            'unit_gross_price' => 125,
            'vat_rate' => 23,
            'raw_payload' => [
                'id' => $number * 10,
                'product_id' => $number * 100,
                'sku' => $product->sku,
                'name' => $product->name,
                'quantity' => 1,
                'subtotal' => '125.00',
                'total' => '125.00',
            ],
        ]);
        $context = compact('channel', 'integration', 'warehouse', 'product', 'balance', 'order');

        if (! $withOperations) {
            return $context;
        }

        $reservation = StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->external_id,
            'quantity' => 1,
            'status' => 'active',
            'reserved_at' => now()->subHour(),
        ]);
        $packingTask = $this->createPackingTask($order, $product, $line->id);
        $wz = WarehouseDocument::query()->create([
            'number' => 'WZ/2026/'.$number,
            'type' => 'WZ',
            'status' => 'draft',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'external_reference' => $order->external_number,
            'order_fulfillment_key' => 'order:'.$channel->id.':'.$order->external_id,
            'metadata' => [
                'external_order_id' => $order->external_id,
                'external_order_number' => $order->external_number,
                'sales_channel_id' => $channel->id,
            ],
        ]);
        $proforma = Invoice::query()->create([
            'number' => 'PRO/2026/'.$number,
            'type' => 'proforma',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
            'payment_due_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => ['name' => 'Sempre Moda'],
            'buyer_data' => ['name' => 'Anna Nowak'],
            'net_total' => 101.63,
            'vat_total' => 23.37,
            'gross_total' => 125,
            'payment_method' => 'payu',
            'issued_at' => now(),
        ]);

        return $context + compact('reservation', 'packingTask', 'wz', 'proforma');
    }

    private function createPackingTask(
        ExternalOrder $order,
        Product $product,
        ?int $externalOrderLineId = null,
    ): PackingTask {
        return PackingTask::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'external_order_line_id' => $externalOrderLineId,
            'product_id' => $product->id,
            'external_line_id' => 'LINE-'.$order->external_id,
            'order_number' => $order->external_number,
            'customer_name' => 'Anna Nowak',
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_required' => 1,
            'quantity_picked' => 0,
            'status' => 'open',
            'order_date' => now()->subHour(),
        ]);
    }

    /** @return array<string, mixed> */
    private function wooOrder(
        ExternalOrder $order,
        bool $paid,
        string $paymentMethod = 'payu',
        string $paymentTitle = 'PayU',
    ): array {
        return [
            'id' => (int) $order->external_id,
            'number' => (string) $order->external_number,
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '125.00',
            'date_paid' => $paid ? '2026-07-14T10:00:00' : null,
            'date_paid_gmt' => $paid ? '2026-07-14T08:00:00' : null,
            'payment_method' => $paymentMethod,
            'payment_method_title' => $paymentTitle,
            'transaction_id' => $paid ? 'TX-'.$order->external_id : '',
            'refunds' => [],
        ];
    }

    private function user(string $email, string $role): User
    {
        return User::query()->create([
            'name' => $role.' cancellation test',
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
