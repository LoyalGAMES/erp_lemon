<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Services\Packing\PackingSettingsService;
use App\Services\Packing\PackingTaskService;
use App\Services\Shipping\CourierPickupTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackingLogisticsUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_order_is_kept_together_in_clothing_segment(): void
    {
        $this->createMixedOrder();

        $collect = $this->get(route('packing.index', ['view' => 'collect', 'segment' => 'all']));
        $collect->assertOk()
            ->assertSee('Sukienka LENA')
            ->assertSee('Sneakersy VIKI')
            ->assertSee('Obuwie')
            ->assertSee('Odzież');

        $this->get(route('packing.index', ['view' => 'collect', 'segment' => 'footwear']))
            ->assertOk()
            ->assertDontSee('Sneakersy VIKI')
            ->assertDontSee('Sukienka LENA');

        $this->get(route('packing.index', ['view' => 'collect', 'segment' => 'clothing']))
            ->assertOk()
            ->assertSee('Sukienka LENA')
            ->assertSee('Sneakersy VIKI');
    }

    public function test_packer_can_split_an_order_from_the_collection_modal(): void
    {
        [$order] = $this->createMixedOrder();
        $shoeLine = $order->lines()->where('sku', 'SKU-SHOES')->sole();
        $packer = User::query()->create([
            'name' => 'Pakujący',
            'email' => 'packing-split@example.test',
            'password' => 'test-password',
            'role' => User::ROLE_PACKER,
            'is_active' => true,
        ]);
        $this->actingAs($packer);

        $this->get(route('packing.index', ['view' => 'collect', 'segment' => 'all']))
            ->assertOk()
            ->assertSee('Podziel zamówienie')
            ->assertSee('Wskaż produkty i ilości, które mają trafić do nowego zamówienia.')
            ->assertSee('Utwórz nowe zamówienie')
            ->assertSee(route('packing.orders.split', $order), false)
            ->assertSee('name="split_lines['.$shoeLine->id.'][quantity]"', false);

        $this->getJson(route('packing.orders.split.availability', $order))
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('reasons', []);

        $this->post(route('packing.orders.split', $order), [
            'split_request_uuid' => (string) Str::uuid(),
            'segment' => 'all',
            'split_lines' => [
                $shoeLine->id => ['quantity' => 1],
            ],
            'note' => 'Wydzielone podczas kompletacji',
        ])
            ->assertRedirect(route('packing.index', ['view' => 'collect', 'segment' => 'all']))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Kompletacja obu części została przeliczona'));

        $splitOrder = ExternalOrder::query()
            ->where('external_id', '701-SPLIT-1')
            ->with('lines')
            ->sole();

        $this->assertSame('701/S1', $splitOrder->external_number);
        $this->assertSame('SKU-SHOES', $splitOrder->lines->sole()->sku);
        $this->assertSame('packing', data_get($splitOrder->raw_payload, 'sempre_erp_split.source'));
    }

    public function test_operator_can_select_station_with_printer_and_it_filters_collect_view(): void
    {
        $this->createMixedOrder();

        $this->post(route('packing.station'), ['station' => 'station-2'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('station-2', session('packing_station'));
        $this->assertTrue(session('packing_station_initialized'));

        $collect = $this->get(route('packing.index', ['view' => 'collect']));
        $collect->assertOk()
            ->assertSee('Stanowisko 2')
            ->assertSee('Drukarka 2')
            ->assertDontSee('Sneakersy VIKI')
            ->assertDontSee('Sukienka LENA');

        $this->post(route('packing.station'), ['station' => 'station-1'])->assertRedirect();
        $this->get(route('packing.index', ['view' => 'collect']))
            ->assertOk()
            ->assertSee('Sneakersy VIKI')
            ->assertSee('Sukienka LENA');
    }

    public function test_pack_view_explains_when_automatic_printing_has_no_active_station(): void
    {
        $this->createMixedOrder();

        $this->post(route('packing.station'))
            ->assertRedirect()
            ->assertSessionHas('packing_station_initialized', true)
            ->assertSessionMissing('packing_station');

        $this->get(route('packing.index', ['view' => 'pack']))
            ->assertOk()
            ->assertSessionMissing('packing_station')
            ->assertSee('Automatyczny wydruk jest wyłączony dla tej sesji');
    }

    public function test_stations_configuration_can_be_updated_with_custom_printers(): void
    {
        $this->get(route('settings.packing'))
            ->assertOk()
            ->assertSee('Stanowiska i drukarki etykiet')
            ->assertSee('Most wydruku Windows')
            ->assertSee('Kod stanowiska')
            ->assertSee('Wybierz drukarkę z Windows')
            ->assertSee('Sprawdź połączenie')
            ->assertSee('Lista pojawi się po połączeniu aplikacji Windows')
            ->assertSee('Dane do instalatora')
            ->assertSee('Token mostu wydruku')
            ->assertSee('Podpisany instalator nie został jeszcze opublikowany')
            ->assertDontSee('Wpisz ręcznie dokładną nazwę drukarki z Windows')
            ->assertDontSee('listener_url')
            ->assertDontSee('Pobierz lemon-print-listener.exe');

        $this->put(route('settings.packing.update'), [
            'stations' => [
                ['code' => 'station-1', 'name' => 'Stanowisko odzież', 'printer_name' => 'Zebra ZD421', 'listener_url' => 'http://192.168.1.25:17777', 'segment' => 'clothing'],
                ['code' => 'station-2', 'name' => 'Stanowisko obuwie', 'printer_name' => 'Zebra ZD621', 'listener_url' => '', 'segment' => 'footwear'],
            ],
            'footwear_keywords' => "obuwie, buty\nsneakersy",
        ])->assertRedirect()->assertSessionHas('status');

        $settings = app(PackingSettingsService::class)->data();

        $this->assertSame('Zebra ZD421', $settings['stations'][0]['printer_name']);
        $this->assertArrayNotHasKey('listener_url', $settings['stations'][0]);
        $this->assertSame('Zebra ZD621', $settings['stations'][1]['printer_name']);
        $this->assertContains('sneakersy', $settings['footwear_keywords']);
        $this->assertTrue(app('router')->has('settings.packing.print-bridge.status'));
    }

    public function test_legacy_raw_windows_executable_is_never_downloaded(): void
    {
        $this->get(route('settings.packing.windows-listener.download'))
            ->assertNotFound();
    }

    public function test_mixed_order_shows_shipping_decision_and_footwear_split_creates_partial_order(): void
    {
        [$order] = $this->createMixedOrder();

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Wysyłka częściowa')
            ->assertSee('Wyślij buty od razu')
            ->assertSee('Czekaj na resztę zamówienia');

        $this->post(route('orders.shipping-decision', $order), ['decision' => 'ship_footwear_now'])
            ->assertRedirect();

        $splitOrder = ExternalOrder::query()
            ->where('external_id', 'like', $order->external_id.'-SPLIT-%')
            ->firstOrFail();

        $this->assertCount(1, $splitOrder->lines);
        $this->assertSame('SKU-SHOES', $splitOrder->lines->first()->sku);
        $this->assertSame(
            'ship_footwear_now',
            data_get($order->fresh()->raw_payload, 'sempre_erp_shipping_decision.decision'),
        );
        $this->assertSame(
            'ship_footwear_now',
            data_get($splitOrder->raw_payload, 'sempre_erp_split.source'),
        );

        $parentSkus = $order->fresh()->lines->pluck('sku');
        $this->assertFalse($parentSkus->contains('SKU-SHOES'));
        $this->assertTrue($parentSkus->contains('SKU-DRESS'));

        $this->assertTrue(
            PackingTask::query()
                ->where('external_order_id', $splitOrder->id)
                ->where('sku', 'SKU-SHOES')
                ->exists(),
        );
    }

    public function test_wait_for_all_decision_is_recorded_without_splitting(): void
    {
        [$order] = $this->createMixedOrder();

        $this->post(route('orders.shipping-decision', $order), ['decision' => 'wait_for_all'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(
            'wait_for_all',
            data_get($order->fresh()->raw_payload, 'sempre_erp_shipping_decision.decision'),
        );
        $this->assertSame(0, ExternalOrder::query()->where('external_id', 'like', '%-SPLIT-%')->count());
    }

    public function test_label_can_be_generated_via_selected_inpost_account(): void
    {
        Http::fake([
            '*/v1/organizations/222/shipments?*' => Http::response(['items' => []], 200),
            '*/v1/organizations/222/shipments' => Http::response(['id' => 'SHIP-2', 'status' => 'created'], 201),
            '*/v1/shipments/SHIP-2/label*' => Http::response('%PDF-1.4 test-label', 200, ['Content-Type' => 'application/pdf']),
            '*/v1/shipments/SHIP-2' => Http::response([
                'id' => 'SHIP-2',
                'status' => 'confirmed',
                'tracking_number' => '520000123456789012345678',
            ], 200),
        ]);

        [$order] = $this->createMixedOrder();
        $this->pickAllTasks($order);
        $secondAccount = $this->createInPostAccounts()[1];
        $this->put(route('settings.packing.update'), [
            'stations' => [
                ['code' => 'station-1', 'name' => 'Stanowisko pakowania', 'printer_name' => 'Zebra ZD421', 'segment' => 'all'],
            ],
            'footwear_keywords' => 'obuwie, buty',
        ])->assertRedirect();
        $this->post(route('packing.station'), ['station' => 'station-1'])->assertRedirect();

        $this->post(route('packing.orders.label', $order), [
            'courier_account_id' => $secondAccount->id,
            'parcel_template' => 'large',
        ])->assertRedirect()->assertSessionHas('status');

        $label = ShippingLabel::query()->firstOrFail();

        $this->assertSame('inpost', $label->provider);
        $this->assertSame($secondAccount->id, $label->courier_account_id);
        $this->assertSame('520000123456789012345678', $label->tracking_number);
        $this->assertSame('drugie', data_get($label->response_payload, 'courier_account'));
        $this->assertSame('large', data_get($label->response_payload, 'parcel_template'));
        Http::assertSent(fn ($request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/shipments')
            && $request->method() === 'POST'
            && data_get($request->data(), 'parcels.0.template') === 'large');
        $this->assertDatabaseMissing('print_jobs', [
            'shipping_label_id' => $label->id,
        ]);

        $this->post(route('packing.orders.pack', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('print_jobs', [
            'shipping_label_id' => $label->id,
            'status' => 'pending',
            'station_code' => 'station-1',
            'printer_name' => 'Zebra ZD421',
            'source' => 'packing.order.packed',
        ]);
    }

    public function test_packing_label_rejects_an_account_that_is_not_active_inpost(): void
    {
        [$order] = $this->createMixedOrder();
        $account = new CourierAccount([
            'provider' => 'blpaczka',
            'code' => 'other-provider',
            'name' => 'Inny przewoźnik',
            'organization_id' => '999',
            'is_default' => false,
            'is_active' => true,
        ]);
        $account->setApiToken('token-other');
        $account->save();

        $this->post(route('packing.orders.label', $order), [
            'courier_account_id' => $account->id,
            'parcel_template' => 'large',
        ])->assertRedirect()->assertSessionHasErrors('courier_account_id');

        $this->assertDatabaseCount('shipping_labels', 0);
    }

    public function test_idempotent_label_retry_reports_the_size_of_the_existing_label(): void
    {
        Http::fake();
        [$order] = $this->createMixedOrder();
        ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:order:'.$order->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'tracking_number' => '520000999888777666555444',
            'disk' => 'local',
            'path' => 'shipping-labels/existing.pdf',
            'response_payload' => ['parcel_template' => 'small'],
            'generated_at' => now(),
        ]);

        $this->post(route('packing.orders.label', $order), [
            'parcel_template' => 'large',
        ])->assertRedirect()->assertSessionHas(
            'status',
            fn (string $message): bool => str_contains($message, 'gabaryt A')
                && ! str_contains($message, 'gabaryt C'),
        );

        $this->assertDatabaseCount('shipping_labels', 1);
        Http::assertNothingSent();
    }

    public function test_tracking_command_marks_picked_up_parcels_as_shipped(): void
    {
        [$order] = $this->createMixedOrder();
        $account = $this->createInPostAccounts()[0];

        app(PackingTaskService::class)->syncReadyOrders();
        PackingTask::query()
            ->where('external_order_id', $order->id)
            ->update(['status' => 'packed', 'packed_at' => now()]);

        $label = ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'tracking_number' => '520000999888777666555444',
            'disk' => 'local',
            'path' => 'shipping-labels/test.pdf',
            'generated_at' => now(),
        ]);

        Http::fake([
            '*/v1/tracking/520000999888777666555444' => Http::sequence()
                ->push([
                    'tracking_number' => '520000999888777666555444',
                    'status' => 'collected_from_sender',
                    'tracking_details' => [
                        ['status' => 'confirmed', 'datetime' => now()->subHour()->toISOString()],
                        ['status' => 'collected_from_sender', 'datetime' => now()->toISOString()],
                    ],
                ], 200)
                ->push([
                    'tracking_number' => '520000999888777666555444',
                    'status' => 'delivered',
                    'tracking_details' => [
                        ['status' => 'delivered', 'datetime' => now()->toISOString()],
                    ],
                ], 200),
        ]);

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(1, $result['picked_up']);

        $this->assertSame('picked_up', $label->fresh()->status);
        $this->assertTrue(
            PackingTask::query()
                ->where('external_order_id', $order->id)
                ->get()
                ->every(fn (PackingTask $task): bool => $task->status === 'shipped'),
        );
        $this->assertSame(
            'inpost_tracking',
            data_get(PackingTask::query()->where('external_order_id', $order->id)->first()->metadata, 'courier_pickup.source'),
        );

        $delivery = app(CourierPickupTrackingService::class)->trackPackedOrders(force: true);

        $this->assertSame(1, $delivery['delivered'], json_encode($delivery, JSON_UNESCAPED_UNICODE));
        $this->assertSame('delivered', $label->fresh()->status);
        $this->assertDatabaseHas('customer_messages', [
            'external_order_id' => $order->id,
            'trigger' => 'order_delivered',
        ]);
    }

    public function test_tracking_command_skips_parcels_not_picked_up_yet(): void
    {
        [$order] = $this->createMixedOrder();

        app(PackingTaskService::class)->syncReadyOrders();
        PackingTask::query()
            ->where('external_order_id', $order->id)
            ->update(['status' => 'packed', 'packed_at' => now()]);

        ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'tracking_number' => '520000111222333444555666',
            'disk' => 'local',
            'path' => 'shipping-labels/test2.pdf',
            'generated_at' => now(),
        ]);

        Http::fake([
            '*/v1/tracking/*' => Http::response([
                'status' => 'confirmed',
                'tracking_details' => [
                    ['status' => 'created', 'datetime' => now()->subHour()->toISOString()],
                ],
            ], 200),
        ]);

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders();

        $this->assertSame(1, $result['checked']);
        $this->assertSame(0, $result['picked_up']);
        $this->assertTrue(
            PackingTask::query()
                ->where('external_order_id', $order->id)
                ->get()
                ->every(fn (PackingTask $task): bool => $task->status === 'packed'),
        );
    }

    public function test_courier_accounts_can_be_managed_in_settings(): void
    {
        $this->post(route('settings.shipping.accounts.store'), [
            'provider' => 'inpost',
            'name' => 'Konto główne',
            'code' => 'glowne',
            'organization_id' => '111',
            'api_token' => 'token-abc',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => 1,
        ])->assertRedirect()->assertSessionHas('status');

        $account = CourierAccount::query()->firstOrFail();

        $this->assertSame('token-abc', $account->apiToken());
        $this->assertTrue($account->is_default);

        $this->get(route('settings.shipping'))
            ->assertOk()
            ->assertSee('Konto główne')
            ->assertDontSee('token-abc');
    }

    /**
     * @return array{0:ExternalOrder}
     */
    private function createMixedOrder(): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $dress = Product::query()->create([
            'sku' => 'SKU-DRESS',
            'name' => 'Sukienka LENA Czarna - M',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['woocommerce_categories' => ['Odzież', 'Sukienki']],
        ]);

        $shoes = Product::query()->create([
            'sku' => 'SKU-SHOES',
            'name' => 'Sneakersy VIKI Białe - 38',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['woocommerce_categories' => ['Obuwie', 'Sneakersy']],
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '701',
            'external_number' => '701',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 399,
            'billing_data' => [
                'first_name' => 'Ewa',
                'last_name' => 'Nowak',
                'email' => 'ewa@example.test',
                'phone' => '+48500600700',
                'address_1' => 'ul. Prosta 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'shipping_data' => [
                'first_name' => 'Ewa',
                'last_name' => 'Nowak',
                'address_1' => 'ul. Krzywa 2',
                'postcode' => '30-002',
                'city' => 'Kraków',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'shipping_lines' => [['method_title' => 'InPost Paczkomaty']],
            ],
            'external_created_at' => now()->subDay(),
        ]);

        $order->lines()->create([
            'product_id' => $dress->id,
            'external_line_id' => '11',
            'sku' => 'SKU-DRESS',
            'name' => 'Sukienka LENA Czarna',
            'quantity' => 1,
            'unit_gross_price' => 199,
        ]);

        $order->lines()->create([
            'product_id' => $shoes->id,
            'external_line_id' => '12',
            'sku' => 'SKU-SHOES',
            'name' => 'Sneakersy VIKI Białe',
            'quantity' => 1,
            'unit_gross_price' => 200,
        ]);

        return [$order];
    }

    private function pickAllTasks(ExternalOrder $order): void
    {
        $this->get(route('packing.index'));

        PackingTask::query()
            ->where('external_order_id', $order->id)
            ->update([
                'status' => 'picked',
                'picked_at' => now(),
            ]);
    }

    /**
     * @return list<CourierAccount>
     */
    private function createInPostAccounts(): array
    {
        $main = new CourierAccount([
            'provider' => 'inpost',
            'code' => 'glowne',
            'name' => 'Konto główne',
            'organization_id' => '111',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => true,
            'is_active' => true,
        ]);
        $main->setApiToken('token-main');
        $main->save();

        $second = new CourierAccount([
            'provider' => 'inpost',
            'code' => 'drugie',
            'name' => 'Konto drugie',
            'organization_id' => '222',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => false,
            'is_active' => true,
        ]);
        $second->setApiToken('token-second');
        $second->save();

        return [$main, $second];
    }
}
