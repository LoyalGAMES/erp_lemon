<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\WordpressIntegration;
use App\Services\Packing\PackingSettingsService;
use App\Services\Shipping\CourierPickupTrackingService;
use App\Services\Shipping\ShippedOrderWooSyncService;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PackingShipmentAutomationTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 1200;

    public function test_finishing_collection_requires_manual_gabaryt_selection_before_generating_label(): void
    {
        Storage::fake('local');
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        [$order, $task] = $this->createOrderWithTask($channel, 'open', 'InPost');
        $trackingNumber = '520000000000000000001201';

        Http::fake([
            'https://shop.test/wp-json/ship/v1/orders/*/label' => Http::response([
                'label_base64' => base64_encode('%PDF-1.4 manual-label'),
                'filename' => 'manual-label.pdf',
                'provider' => 'inpost',
                'label_number' => 'AUTO-1201',
                'tracking_number' => $trackingNumber,
            ], 200),
            'https://shop.test/wp-json/wc/v3/orders/*' => Http::response([
                'status' => 'ready-to-ship',
            ], 200),
        ]);

        app(PackingSettingsService::class)->update([
            'stations' => [[
                'code' => 'station-auto',
                'name' => 'Stanowisko automatyczne',
                'printer_name' => 'Zebra AUTO',
                'segment' => 'all',
            ]],
        ]);
        $this->post(route('packing.station'), ['station' => 'station-auto'])
            ->assertRedirect();

        $this->post(route('packing.groups.pick'), ['task_ids' => [$task->id]])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'trafiły do kolejki pakowania'));

        $task->refresh();
        $order->refresh();

        $this->assertSame('picked', $task->status);
        $this->assertSame('ready_to_pack', $order->fulfillment_status);
        $this->assertDatabaseCount('shipping_labels', 0);

        $this->get(route('packing.index', ['view' => 'pack']))
            ->assertOk()
            ->assertSee('gabaryt A', false)
            ->assertSee('gabaryt B', false)
            ->assertSee('gabaryt C', false);

        $this->post(route('packing.orders.label', $order), ['parcel_template' => 'large'])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'gabaryt C'));

        $label = ShippingLabel::query()->sole();
        $this->assertSame('shipment', $label->purpose);
        $this->assertSame('generated', $label->status);
        $this->assertSame($trackingNumber, $label->tracking_number);
        $this->assertSame('large', data_get($label->response_payload, 'parcel_template'));
        $this->assertDatabaseMissing('print_jobs', ['shipping_label_id' => $label->id]);
        Storage::disk('local')->assertExists($label->path);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/wp-json/ship/v1/orders/')
            && data_get($request->data(), 'parcel_template') === 'large');

        $this->post(route('packing.orders.pack', $order))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'oczekujących na kuriera'));

        $this->assertDatabaseHas('print_jobs', [
            'shipping_label_id' => $label->id,
            'status' => 'pending',
            'source' => 'packing.order.packed',
            'station_code' => 'station-auto',
            'printer_name' => 'Zebra AUTO',
        ]);
        $this->assertSame('packed', $task->fresh()->status);
        $this->assertSame('awaiting_courier', $order->fresh()->fulfillment_status);
        $this->assertSame(1, ShippingLabel::query()->shipments()->count());
    }

    public function test_tracker_does_not_call_inpost_for_return_dpd_or_gls_labels(): void
    {
        $this->travelTo(Carbon::parse('2026-07-12 10:00:00'));
        $channel = $this->createChannel();
        [$order] = $this->createOrderWithTask($channel, 'packed', 'Kurier');

        $returnLabel = $this->createLabel($order, [
            'purpose' => 'return',
            'provider' => 'inpost',
            'tracking_number' => '520000000000000000009901',
        ]);
        $dpdLabel = $this->createLabel($order, [
            'provider' => 'dpd',
            'tracking_number' => '520000000000000000009902',
        ]);
        $glsLabel = $this->createLabel($order, [
            'provider' => 'gls',
            'tracking_number' => '520000000000000000009903',
        ]);

        Http::preventStrayRequests();

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders();

        Http::assertNothingSent();
        $this->assertSame(0, $result['checked']);
        $this->assertSame(0, $result['picked_up']);
        $this->assertCount(2, $result['warnings']);
        $this->assertNull($returnLabel->fresh()->tracking_status);

        foreach ([$dpdLabel, $glsLabel] as $unsupportedLabel) {
            $unsupportedLabel->refresh();
            $this->assertSame('unsupported_provider', $unsupportedLabel->tracking_status);
            $this->assertNotNull($unsupportedLabel->tracking_checked_at);
            $this->assertSame(
                now()->addDay()->toDateTimeString(),
                $unsupportedLabel->next_tracking_check_at?->toDateTimeString(),
            );
            $this->assertStringContainsString('nie ma skonfigurowanego adaptera', (string) $unsupportedLabel->tracking_last_error);
        }
    }

    public function test_tracker_persists_tracking_state_and_applies_exponential_backoff(): void
    {
        $this->travelTo(Carbon::parse('2026-07-12 11:00:00'));
        $channel = $this->createChannel();
        [$readyOrder] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        [$failingOrder] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        $readyNumber = '520000000000000000007701';
        $failingNumber = '520000000000000000007702';
        $readyLabel = $this->createLabel($readyOrder, [
            'provider' => 'inpost',
            'tracking_number' => $readyNumber,
        ]);
        $failingLabel = $this->createLabel($failingOrder, [
            'provider' => 'inpost',
            'tracking_number' => $failingNumber,
        ]);

        Http::fake([
            "*/v1/tracking/{$readyNumber}" => Http::response([
                'tracking_number' => $readyNumber,
                'status' => 'confirmed',
                'tracking_details' => [],
            ], 200),
            "*/v1/tracking/{$failingNumber}" => Http::response([], 503),
        ]);

        $firstRun = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(1, $firstRun['checked']);
        $this->assertSame(0, $firstRun['picked_up']);
        $this->assertCount(1, $firstRun['warnings']);

        $readyLabel->refresh();
        $this->assertSame('confirmed', $readyLabel->tracking_status);
        $this->assertSame(now()->toDateTimeString(), $readyLabel->tracking_checked_at?->toDateTimeString());
        $this->assertSame(now()->addMinutes(5)->toDateTimeString(), $readyLabel->next_tracking_check_at?->toDateTimeString());
        $this->assertSame(0, $readyLabel->tracking_attempts);
        $this->assertNull($readyLabel->tracking_last_error);
        $this->assertSame('confirmed', data_get($readyLabel->response_payload, 'tracking.status'));

        $failingLabel->refresh();
        $this->assertSame(1, $failingLabel->tracking_attempts);
        $this->assertSame(now()->toDateTimeString(), $failingLabel->tracking_checked_at?->toDateTimeString());
        $this->assertSame(now()->addMinutes(5)->toDateTimeString(), $failingLabel->next_tracking_check_at?->toDateTimeString());
        $this->assertStringContainsString('HTTP 503', (string) $failingLabel->tracking_last_error);

        $immediateRun = app(CourierPickupTrackingService::class)->trackPackedOrders();
        $this->assertSame(0, $immediateRun['checked']);
        $this->assertSame(1, $failingLabel->fresh()->tracking_attempts);

        $this->travel(5)->minutes();
        app(CourierPickupTrackingService::class)->trackPackedOrders();

        $failingLabel->refresh();
        $this->assertSame(2, $failingLabel->tracking_attempts);
        $this->assertSame(now()->addMinutes(10)->toDateTimeString(), $failingLabel->next_tracking_check_at?->toDateTimeString());
    }

    public function test_inpost_tracking_uses_label_number_when_a_separate_tracking_number_is_missing(): void
    {
        $channel = $this->createChannel();
        [$order] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        $trackingNumber = '520000000000000000004444';
        $label = $this->createLabel($order, [
            'provider' => 'inpost',
            'label_number' => $trackingNumber,
            'tracking_number' => null,
        ]);

        Http::fake([
            "*/v1/tracking/{$trackingNumber}" => Http::response([
                'status' => 'confirmed',
                'tracking_details' => [],
            ], 200),
        ]);

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(1, $result['checked']);
        $this->assertSame('confirmed', $label->fresh()->tracking_status);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/tracking/'.$trackingNumber));
    }

    public function test_manual_pickup_check_rechecks_waiting_shipments_immediately(): void
    {
        $this->travelTo(Carbon::parse('2026-07-12 11:30:00'));
        $channel = $this->createChannel();
        [$order] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        $trackingNumber = '520000000000000000006060';
        $label = $this->createLabel($order, [
            'provider' => 'inpost',
            'tracking_number' => $trackingNumber,
            'next_tracking_check_at' => now()->addMinutes(5),
        ]);

        Http::fake([
            "*/v1/tracking/{$trackingNumber}" => Http::response([
                'tracking_number' => $trackingNumber,
                'status' => 'confirmed',
                'tracking_details' => [],
            ], 200),
        ]);

        $this->get(route('packing.index', ['view' => 'waiting']))
            ->assertOk()
            ->assertSee('Sprawdź odbiory')
            ->assertSee(route('packing.couriers.check-pickups'), false);

        $this->post(route('packing.couriers.check-pickups'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Ręcznie sprawdzono odbiory: 1 paczek'));

        $label->refresh();
        $this->assertSame('confirmed', $label->tracking_status);
        $this->assertSame(now()->toDateTimeString(), $label->tracking_checked_at?->toDateTimeString());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/v1/tracking/'.$trackingNumber));
    }

    public function test_tracker_and_manual_pickup_ignore_an_order_until_every_active_task_is_packed(): void
    {
        $channel = $this->createChannel();
        [$order, $packedTask] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        $openTask = $this->addTaskToOrder($order, $channel, 'open', 'InPost');
        $label = $this->createLabel($order, [
            'provider' => 'inpost',
            'tracking_number' => '520000000000000000003333',
        ]);

        Http::preventStrayRequests();

        $tracked = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(0, $tracked['checked']);
        $this->assertSame('generated', $label->fresh()->status);

        $this->post(route('packing.couriers.pickup'), [
            'courier' => 'InPost',
            'order_ids' => [$order->id],
            'pickup_token' => $this->pickupToken('InPost', [$order->id]),
        ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'w całości spakowane'));

        $this->assertSame('packed', $packedTask->fresh()->status);
        $this->assertSame('open', $openTask->fresh()->status);
        $this->assertNotSame('shipped', $order->fresh()->fulfillment_status);
        $this->get(route('packing.index', ['view' => 'waiting']))
            ->assertOk()
            ->assertDontSee('Zamówienie '.$order->external_number);
    }

    public function test_label_files_from_different_orders_cannot_overwrite_each_other(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-07-12 14:00:00'));
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        [$firstOrder] = $this->createOrderWithTask($channel, 'picked', 'InPost');
        [$secondOrder] = $this->createOrderWithTask($channel, 'picked', 'InPost');

        Http::fake(function ($request) use ($firstOrder) {
            $isFirst = str_contains($request->url(), '/orders/'.$firstOrder->external_id.'/label');

            return Http::response([
                'label_base64' => base64_encode($isFirst ? '%PDF first-order' : '%PDF second-order'),
                'filename' => 'label.pdf',
                'provider' => 'inpost',
            ], 200);
        });

        $firstLabel = app(ShippingLabelService::class)->generateForOrder($firstOrder);
        $secondLabel = app(ShippingLabelService::class)->generateForOrder($secondOrder);

        $this->assertNotSame($firstLabel->path, $secondLabel->path);
        $this->assertStringContainsString('-order-'.$firstOrder->id.'-', $firstLabel->path);
        $this->assertStringContainsString('-order-'.$secondOrder->id.'-', $secondLabel->path);
        $this->assertSame('%PDF first-order', Storage::disk('local')->get($firstLabel->path));
        $this->assertSame('%PDF second-order', Storage::disk('local')->get($secondLabel->path));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/orders/'.$firstOrder->external_id.'/label')
            && $request->hasHeader('Idempotency-Key', 'sempre-shipment:1:'.$firstOrder->external_id)
            && $request['idempotency_key'] === 'sempre-shipment:1:'.$firstOrder->external_id);
    }

    public function test_mutating_woocommerce_label_request_is_not_retried_after_an_ambiguous_failure(): void
    {
        Storage::fake('local');
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        [$order] = $this->createOrderWithTask($channel, 'picked', 'InPost');

        Http::fake([
            'https://shop.test/wp-json/ship/v1/orders/*/label' => Http::response([
                'message' => 'upstream timeout after dispatch',
            ], 503),
        ]);

        try {
            app(ShippingLabelService::class)->generateForOrder($order);
            $this->fail('Generowanie etykiety powinno zgłosić błąd HTTP 503.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('HTTP 503', $exception->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_manual_pickup_updates_only_submitted_orders_and_their_labels(): void
    {
        $this->travelTo(Carbon::parse('2026-07-12 12:00:00'));
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        [$selectedOrder, $selectedTask] = $this->createOrderWithTask($channel, 'packed', 'DPD');
        [$otherOrder, $otherTask] = $this->createOrderWithTask($channel, 'packed', 'DPD');
        $selectedLabel = $this->createLabel($selectedOrder, [
            'provider' => 'dpd',
            'tracking_number' => 'DPD-SELECTED',
        ]);
        $otherLabel = $this->createLabel($otherOrder, [
            'provider' => 'dpd',
            'tracking_number' => 'DPD-OTHER',
        ]);

        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/*' => Http::response(['status' => 'completed'], 200),
        ]);

        $this->post(route('packing.couriers.pickup'), [
            'courier' => 'DPD',
            'order_ids' => [$selectedOrder->id],
            'pickup_token' => $this->pickupToken('DPD', [$selectedOrder->id]),
        ])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, '1 zamówień'));

        $this->assertSame('shipped', $selectedTask->fresh()->status);
        $this->assertSame('shipped', $selectedOrder->fresh()->fulfillment_status);
        $this->assertSame('completed', $selectedOrder->fresh()->status);
        $selectedLabel->refresh();
        $this->assertSame('picked_up', $selectedLabel->status);
        $this->assertSame('manual_confirmation', $selectedLabel->tracking_status);
        $this->assertSame(now()->toDateTimeString(), $selectedLabel->picked_up_at?->toDateTimeString());
        $this->assertNull($selectedLabel->next_tracking_check_at);

        $this->assertSame('packed', $otherTask->fresh()->status);
        $this->assertSame('awaiting_courier', $otherOrder->fresh()->fulfillment_status);
        $this->assertSame('processing', $otherOrder->fresh()->status);
        $this->assertSame('generated', $otherLabel->fresh()->status);
        $this->assertNull($otherLabel->fresh()->tracking_status);

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === "https://shop.test/wp-json/wc/v3/orders/{$selectedOrder->external_id}");
        Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === "https://shop.test/wp-json/wc/v3/orders/{$otherOrder->external_id}");
    }

    public function test_manual_pickup_rejects_modified_order_list(): void
    {
        $channel = $this->createChannel();
        [$firstOrder, $firstTask] = $this->createOrderWithTask($channel, 'packed', 'DPD');
        [$secondOrder, $secondTask] = $this->createOrderWithTask($channel, 'packed', 'DPD');

        $this->post(route('packing.couriers.pickup'), [
            'courier' => 'DPD',
            'order_ids' => [$firstOrder->id, $secondOrder->id],
            'pickup_token' => $this->pickupToken('DPD', [$firstOrder->id]),
        ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Lista paczek zmieniła się'));

        $this->assertSame('packed', $firstTask->fresh()->status);
        $this->assertSame('packed', $secondTask->fresh()->status);
        $this->assertSame('awaiting_courier', $firstOrder->fresh()->fulfillment_status);
        $this->assertSame('awaiting_courier', $secondOrder->fresh()->fulfillment_status);
    }

    public function test_inpost_collected_by_courier_event_moves_order_to_shipped(): void
    {
        $this->travelTo(Carbon::parse('2026-07-12 12:30:00'));
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        [$order, $task] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        $trackingNumber = '520000000000000000007777';
        $label = $this->createLabel($order, [
            'provider' => 'inpost',
            'tracking_number' => $trackingNumber,
        ]);

        Http::fake([
            "*/v1/tracking/{$trackingNumber}" => Http::response([
                'tracking_number' => $trackingNumber,
                'status' => 'FMD.1002',
                'events' => [[
                    'eventCode' => 'FMD.1002',
                    'timestamp' => now()->toISOString(),
                ]],
            ], 200),
            'https://shop.test/wp-json/wc/v3/orders/*' => Http::response(['status' => 'completed'], 200),
        ]);

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['picked_up']);
        $this->assertSame(1, $result['orders']);
        $this->assertSame('picked_up', $label->fresh()->status);
        $this->assertSame('FMD.1002', $label->fresh()->tracking_status);
        $this->assertNotNull($label->fresh()->picked_up_at);
        $this->assertSame('shipped', $task->fresh()->status);
        $this->assertSame('shipped', $order->fresh()->fulfillment_status);
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_tracking_recovers_an_order_left_with_mixed_packed_and_shipped_tasks(): void
    {
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        [$order, $packedTask] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        $shippedTask = $this->addTaskToOrder($order, $channel, 'shipped', 'InPost');
        $trackingNumber = '520000000000000000004242';
        $label = $this->createLabel($order, [
            'provider' => 'inpost',
            'tracking_number' => $trackingNumber,
        ]);

        Http::fake([
            "*/v1/tracking/{$trackingNumber}" => Http::response([
                'tracking_number' => $trackingNumber,
                'status' => 'collected_from_sender',
                'tracking_details' => [[
                    'status' => 'collected_from_sender',
                    'datetime' => now()->toISOString(),
                ]],
            ], 200),
            'https://shop.test/wp-json/wc/v3/orders/*' => Http::response(['status' => 'completed'], 200),
        ]);

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(1, $result['picked_up']);
        $this->assertSame(1, $result['orders']);
        $this->assertSame('picked_up', $label->fresh()->status);
        $this->assertSame('shipped', $packedTask->fresh()->status);
        $this->assertSame('shipped', $shippedTask->fresh()->status);
        $this->assertSame('shipped', $order->fresh()->fulfillment_status);
    }

    public function test_packing_without_a_manually_generated_label_does_not_generate_or_print_one(): void
    {
        Storage::fake('local');
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        [$order, $task] = $this->createOrderWithTask($channel, 'open', 'InPost');

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/wp-json/ship/v1/orders/')) {
                return Http::response(['message' => 'label endpoint must not be called automatically'], 500);
            }

            if (str_contains($request->url(), '/wp-json/wc/v3/orders/')) {
                return Http::response(['status' => 'ready-to-ship'], 200);
            }

            return Http::response([], 404);
        });

        app(PackingSettingsService::class)->update([
            'stations' => [[
                'code' => 'station-retry',
                'name' => 'Stanowisko retry',
                'printer_name' => 'Zebra RETRY',
                'segment' => 'all',
            ]],
        ]);
        $this->post(route('packing.station'), ['station' => 'station-retry'])->assertRedirect();
        $this->post(route('packing.groups.pick'), ['task_ids' => [$task->id]])->assertRedirect();

        $this->assertSame(0, ShippingLabel::query()->count());

        $this->post(route('packing.orders.pack', $order))
            ->assertRedirect()
            ->assertSessionHas(
                'status',
                fn (string $message): bool => str_contains($message, 'najpierw wygeneruj etykietę'),
            );
        $this->assertSame('packed', $task->fresh()->status);
        $this->assertSame('station-retry', data_get($task->fresh()->metadata, 'packing_completion.print_station.code'));
        $this->assertDatabaseCount('print_jobs', 0);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/wp-json/ship/v1/orders/'));
    }

    public function test_failed_woo_shipped_status_is_retried_without_returning_order_to_courier_queue(): void
    {
        $this->travelTo(Carbon::parse('2026-07-12 13:00:00'));
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        [$order, $task] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        $trackingNumber = '520000000000000000005555';
        $this->createLabel($order, [
            'provider' => 'inpost',
            'tracking_number' => $trackingNumber,
        ]);
        $wooAvailable = false;

        Http::fake(function ($request) use ($trackingNumber, &$wooAvailable) {
            if (str_ends_with($request->url(), '/v1/tracking/'.$trackingNumber)) {
                return Http::response([
                    'status' => 'collected_from_sender',
                    'tracking_details' => [[
                        'status' => 'collected_from_sender',
                        'datetime' => now()->toISOString(),
                    ]],
                ], 200);
            }

            if (str_contains($request->url(), '/wp-json/wc/v3/orders/')) {
                return $wooAvailable
                    ? Http::response(['status' => 'completed'], 200)
                    : Http::response(['message' => 'temporary Woo outage'], 503);
            }

            return Http::response([], 404);
        });

        app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame('shipped', $task->fresh()->status);
        $this->assertSame('shipped', $order->fresh()->fulfillment_status);
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->woo_shipped_sync_status);
        $this->assertNotNull($order->fresh()->woo_shipped_sync_error);

        $this->get(route('packing.index'))->assertOk();
        $this->assertSame('shipped', $task->fresh()->status);
        $this->assertSame('shipped', $order->fresh()->fulfillment_status);

        $wooAvailable = true;
        $this->travel(5)->minutes();
        $result = app(ShippedOrderWooSyncService::class)->retry();

        $this->assertSame(1, $result['synced']);
        $this->assertSame('success', $order->fresh()->woo_shipped_sync_status);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNull($order->fresh()->woo_shipped_sync_error);
    }

    public function test_shipment_number_and_links_are_visible_on_order_packing_and_orders_views(): void
    {
        $channel = $this->createChannel();
        [$order] = $this->createOrderWithTask($channel, 'packed', 'InPost');
        $trackingNumber = '520000000000000000008801';
        $label = $this->createLabel($order, [
            'provider' => 'inpost',
            'label_number' => 'SHIP-VIEW-1',
            'tracking_number' => $trackingNumber,
            'tracking_status' => 'confirmed',
            'tracking_checked_at' => now(),
        ]);
        $trackingUrl = 'https://inpost.pl/sledzenie-przesylek?number='.$trackingNumber;

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee("Nr etykiety / przesyłki: {$trackingNumber}")
            ->assertSee($trackingUrl, false)
            ->assertSee(route('packing.labels.download', $label), false)
            ->assertSee('Oczekuje na kuriera');

        $this->get(route('packing.index', ['view' => 'waiting']))
            ->assertOk()
            ->assertSee("Etykieta: <strong>{$trackingNumber}</strong>", false)
            ->assertSee($trackingUrl, false)
            ->assertSee(route('orders.show', $order), false)
            ->assertSee(route('packing.labels.download', $label), false);

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee($trackingNumber)
            ->assertSee($trackingUrl, false)
            ->assertSee(route('orders.show', $order), false)
            ->assertSee('oczekuje na kuriera');
    }

    private function createChannel(): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    private function createIntegration(SalesChannel $channel): WordpressIntegration
    {
        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre WooCommerce',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
            'settings' => [
                'shipping_labels' => [
                    'enabled' => true,
                    'endpoint' => '/wp-json/ship/v1/orders/{order_id}/label',
                    'method' => 'POST',
                    'auth' => 'woocommerce',
                ],
                'order_statuses' => [
                    'ready_to_ship' => 'ready-to-ship',
                    'shipped' => 'completed',
                    'packing_rollback' => 'processing',
                ],
            ],
        ]);
    }

    /**
     * @return array{0:ExternalOrder,1:PackingTask,2:Product}
     */
    private function createOrderWithTask(SalesChannel $channel, string $taskStatus, string $courier): array
    {
        $number = (string) ++$this->sequence;
        $product = Product::query()->create([
            'sku' => 'SKU-'.$number,
            'ean' => '590000000'.$number,
            'name' => 'Produkt automatyzacji '.$number,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $fulfillmentStatus = match ($taskStatus) {
            'picked' => 'ready_to_pack',
            'packed' => 'awaiting_courier',
            'shipped' => 'shipped',
            default => null,
        };
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => $number,
            'external_number' => $number,
            'status' => $taskStatus === 'shipped' ? 'completed' : 'processing',
            'fulfillment_status' => $fulfillmentStatus,
            'currency' => 'PLN',
            'total_gross' => 123,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Testowa',
                'email' => "anna.{$number}@example.test",
                'phone' => '+48500123123',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Testowa',
                'address_1' => 'Magazynowa 2',
                'postcode' => '00-002',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'shipping_lines' => [['method_title' => $courier]],
            ],
            'external_created_at' => now()->subMinute(),
        ]);
        $line = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-'.$number,
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'raw_payload' => [],
        ]);
        $isPicked = in_array($taskStatus, ['picked', 'packed', 'shipped'], true);
        $task = PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'external_order_line_id' => $line->id,
            'product_id' => $product->id,
            'external_line_id' => 'line-'.$number,
            'order_number' => $number,
            'customer_name' => 'Anna Testowa',
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_required' => 1,
            'quantity_picked' => $isPicked ? 1 : 0,
            'status' => $taskStatus,
            'courier' => $courier,
            'size_label' => 'M',
            'order_date' => now()->subMinute(),
            'picked_at' => $isPicked ? now()->subMinute() : null,
            'packed_at' => in_array($taskStatus, ['packed', 'shipped'], true) ? now()->subSeconds(30) : null,
        ]);

        return [$order, $task, $product];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLabel(ExternalOrder $order, array $overrides = []): ShippingLabel
    {
        $defaults = [
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'LABEL-'.$order->external_number.'-'.$this->sequence,
            'tracking_number' => '52000000000000000000'.str_pad((string) $order->id, 4, '0', STR_PAD_LEFT),
            'disk' => 'local',
            'path' => 'shipping-labels/test-'.$order->id.'-'.$this->sequence.'.pdf',
            'mime_type' => 'application/pdf',
            'generated_at' => now(),
        ];

        return ShippingLabel::query()->create(array_merge($defaults, $overrides));
    }

    private function addTaskToOrder(
        ExternalOrder $order,
        SalesChannel $channel,
        string $status,
        string $courier,
    ): PackingTask {
        $number = (string) ++$this->sequence;
        $product = Product::query()->create([
            'sku' => 'SKU-'.$number,
            'ean' => '590000000'.$number,
            'name' => 'Dodatkowy produkt '.$number,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $line = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-'.$number,
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'raw_payload' => [],
        ]);

        return PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'external_order_line_id' => $line->id,
            'product_id' => $product->id,
            'external_line_id' => 'line-'.$number,
            'order_number' => $order->external_number,
            'customer_name' => 'Anna Testowa',
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_required' => 1,
            'quantity_picked' => $status === 'open' ? 0 : 1,
            'status' => $status,
            'courier' => $courier,
            'size_label' => 'L',
            'order_date' => now()->subMinute(),
            'picked_at' => $status === 'open' ? null : now()->subMinute(),
            'packed_at' => $status === 'packed' ? now()->subSeconds(30) : null,
        ]);
    }

    /**
     * @param  list<int>  $orderIds
     */
    private function pickupToken(string $courier, array $orderIds): string
    {
        sort($orderIds);

        return hash_hmac('sha256', $courier.'|'.implode(',', $orderIds), (string) config('app.key'));
    }
}
