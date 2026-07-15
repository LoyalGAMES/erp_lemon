<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderEditingService;
use App\Services\Orders\OrderWzDocumentService;
use App\Services\Packing\PackingTaskService;
use App\Services\Shipping\ShippingProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderFullEditingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_gls_number_can_be_added_from_order_editor_with_gls_tracking_link(): void
    {
        [, , , , $order] = $this->createOrderContext();
        app(PackingTaskService::class)->syncForOrder($order);

        $trackingNumber = 'GLS123456789PL';

        $this->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('Ręczna przesyłka')
            ->assertSee(route('orders.labels.manual.store', $order), false);

        $this->post(route('orders.labels.manual.store', $order), [
            'provider' => 'gls',
            'tracking_number' => $trackingNumber,
        ])->assertRedirect()->assertSessionHas('status');

        $label = ShippingLabel::query()->sole();
        $this->assertSame('gls', $label->provider);
        $this->assertSame('generated', $label->status);
        $this->assertSame($trackingNumber, $label->tracking_number);
        $this->assertSame('manual_tracking_number', data_get($label->response_payload, 'source'));
        $this->assertSame(
            'https://gls-group.com/PL/pl/sledzenie-paczek/?match='.rawurlencode($trackingNumber),
            app(ShippingProviderResolver::class)->trackingUrl($label),
        );
        $this->assertNotNull($label->next_tracking_check_at);
        $this->assertSame('', $label->path);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_packer_can_open_the_editor_from_shipping_and_payment_details(): void
    {
        [$channel, , $product, , $order] = $this->createOrderContext();
        app(PackingTaskService::class)->syncForOrder($order);

        PackingTask::query()->where('external_order_id', $order->id)->update([
            'quantity_picked' => 1,
            'status' => 'picked',
            'picked_at' => now(),
        ]);

        $packer = User::query()->create([
            'name' => 'Pakujący',
            'email' => 'packer-order-edit@example.test',
            'password' => 'test-password',
            'role' => User::ROLE_PACKER,
            'is_active' => true,
        ]);
        $this->actingAs($packer);

        $editUrl = route('orders.edit', ['order' => $order, 'return_to' => 'packing']);

        $this->get(route('packing.index', ['view' => 'pack']))
            ->assertOk()
            ->assertSee('Dane wysyłki i płatności')
            ->assertSee('Edytuj zamówienie')
            ->assertSee($editUrl, false);

        $this->get($editUrl)
            ->assertOk()
            ->assertSee('Edycja zamówienia 9001')
            ->assertSee('Zapisz i zsynchronizuj zamówienie')
            ->assertSee('name="billing[first_name]"', false)
            ->assertSee('name="shipping[address_1]"', false)
            ->assertSee('name="lines['.$order->lines()->sole()->id.'][product_id]"', false)
            ->assertSee($product->sku);

        $this->getJson(route('orders.products.lookup', ['order' => $order, 'q' => $product->sku]))
            ->assertOk()
            ->assertJsonFragment(['id' => $product->id, 'sku' => $product->sku]);

        $this->get(route('orders.show', $order))->assertForbidden();

        $unassignedOrder = $order->replicate();
        $unassignedOrder->forceFill([
            'external_id' => '9002',
            'external_number' => '9002',
            'status' => 'cancelled',
        ])->save();
        $originalLine = $order->lines()->sole();
        $unassignedOrder->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '7002',
            'canonical_external_line_id' => '7002',
            'sku' => $originalLine->sku,
            'name' => $originalLine->name,
            'quantity' => 1,
            'unit_net_price' => $originalLine->unit_net_price,
            'unit_gross_price' => $originalLine->unit_gross_price,
            'vat_rate' => $originalLine->vat_rate,
            'raw_payload' => array_merge((array) $originalLine->raw_payload, ['id' => 7002]),
        ]);

        $this->get(route('orders.edit', $unassignedOrder))->assertForbidden();
    }

    public function test_full_edit_uses_one_woo_put_and_refreshes_local_order_draft_wz_and_picking(): void
    {
        [$channel, , $oldProduct, $newProduct, $order, $warehouse] = $this->createOrderContext();
        $oldLine = $order->lines()->sole();

        app(StockReservationService::class)->syncForOrder($order);
        $draft = app(OrderWzDocumentService::class)->ensureDrafts($order)[0];
        app(PackingTaskService::class)->syncForOrder($order);
        PackingTask::query()->where('external_order_id', $order->id)->update([
            'quantity_picked' => 1,
            'status' => 'picked',
            'picked_at' => now(),
        ]);

        $billing = [
            'first_name' => 'Joanna',
            'last_name' => 'Nowak',
            'company' => 'Nowak Moda',
            'address_1' => 'Nowa 12',
            'address_2' => 'lok. 4',
            'city' => 'Poznań',
            'state' => 'wielkopolskie',
            'postcode' => '60-001',
            'country' => 'PL',
            'email' => 'joanna.nowak@example.test',
            'phone' => '+48600100200',
        ];
        $shipping = [
            'first_name' => 'Joanna',
            'last_name' => 'Nowak',
            'company' => '',
            'address_1' => 'Magazynowa 8',
            'address_2' => '',
            'city' => 'Poznań',
            'state' => 'wielkopolskie',
            'postcode' => '60-002',
            'country' => 'PL',
            'phone' => '+48600900800',
        ];
        $wooResponse = [
            'id' => 9001,
            'number' => '9001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total' => '264.50',
            'date_modified_gmt' => '2026-07-14T10:20:30',
            // Some WooCommerce extensions return partial address objects.
            'billing' => ['first_name' => $billing['first_name']],
            'shipping' => ['address_1' => $shipping['address_1']],
            'customer_note' => 'Proszę zadzwonić przed dostawą.',
            'payment_method' => 'cod',
            'payment_method_title' => 'Płatność przy odbiorze',
            'shipping_lines' => [[
                'id' => 81,
                'method_id' => 'flat_rate:4',
                'method_title' => 'Kurier ekspresowy',
                'total' => '14.50',
            ]],
            'meta_data' => [
                ['id' => 31, 'key' => 'billing_nip', 'value' => '7791234567'],
                ['id' => 32, 'key' => 'paczkomat_id', 'value' => 'POZ010'],
            ],
            'line_items' => [[
                'id' => 7001,
                'product_id' => 702,
                'variation_id' => 0,
                'sku' => $newProduct->sku,
                'name' => $newProduct->name,
                'quantity' => 2,
                'subtotal' => '220.00',
                'total' => '250.00',
            ]],
        ];

        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/9001*' => Http::response($wooResponse, 200),
        ]);

        $this->put(route('orders.update', $order), [
            'expected_version' => app(OrderEditingService::class)->version($order),
            'expected_remote_modified_at' => '',
            'return_to' => 'order',
            'billing' => $billing,
            'shipping' => $shipping,
            'billing_tax_id' => '7791234567',
            'target_point' => 'POZ010',
            'customer_note' => 'Proszę zadzwonić przed dostawą.',
            'payment_method' => 'cod',
            'payment_method_title' => 'Płatność przy odbiorze',
            'shipping_line' => [
                'id' => 81,
                'method_id' => 'flat_rate:4',
                'method_title' => 'Kurier ekspresowy',
                'total' => '14.50',
            ],
            'lines' => [
                $oldLine->id => [
                    'product_id' => $newProduct->id,
                    'quantity' => 2,
                    'subtotal' => '220.00',
                    'total' => '250.00',
                ],
            ],
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/orders/9001');
        Http::assertSent(function ($request) use ($billing, $shipping): bool {
            $data = $request->data();

            return $request->method() === 'PUT'
                && $request->url() === 'https://shop.test/wp-json/wc/v3/orders/9001'
                && data_get($data, 'billing.email') === $billing['email']
                && data_get($data, 'shipping.address_1') === $shipping['address_1']
                && data_get($data, 'shipping.phone') === $shipping['phone']
                && data_get($data, 'customer_note') === 'Proszę zadzwonić przed dostawą.'
                && data_get($data, 'payment_method') === 'cod'
                && data_get($data, 'shipping_lines.0.id') === 81
                && data_get($data, 'shipping_lines.0.total') === '14.50'
                && data_get($data, 'line_items.0.id') === 7001
                && data_get($data, 'line_items.0.product_id') === 702
                && data_get($data, 'line_items.0.quantity') === 2.0
                && data_get($data, 'line_items.0.total') === '250.00';
        });

        $freshOrder = $order->fresh();
        $freshLine = $freshOrder->lines()->sole();
        $this->assertSame('Joanna', data_get($freshOrder->billing_data, 'first_name'));
        $this->assertSame('7791234567', data_get($freshOrder->billing_data, 'billing_nip'));
        $this->assertSame('Magazynowa 8', data_get($freshOrder->shipping_data, 'address_1'));
        $this->assertSame('+48600900800', data_get($freshOrder->shipping_data, 'phone'));
        $this->assertSame('Proszę zadzwonić przed dostawą.', data_get($freshOrder->raw_payload, 'customer_note'));
        $this->assertSame('Płatność przy odbiorze', data_get($freshOrder->raw_payload, 'payment_method_title'));
        $this->assertSame('POZ010', data_get($freshOrder->raw_payload, 'sempre_erp_target_point'));
        $this->assertSame('264.50', (string) $freshOrder->total_gross);
        $this->assertSame($newProduct->id, $freshLine->product_id);
        $this->assertSame('2.0000', (string) $freshLine->quantity);
        $this->assertSame('7001', $freshLine->external_line_id);

        $this->assertDatabaseHas('stock_reservations', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $oldProduct->id,
            'external_order_id' => '9001',
            'status' => 'released',
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $newProduct->id,
            'external_order_id' => '9001',
            'status' => 'active',
            'quantity' => 2,
        ]);

        $freshDraft = $draft->fresh('lines');
        $this->assertSame('draft', $freshDraft->status);
        $this->assertCount(1, $freshDraft->lines);
        $this->assertSame($newProduct->id, $freshDraft->lines->sole()->product_id);
        $this->assertSame('2.0000', (string) $freshDraft->lines->sole()->quantity);
        $this->assertSame('Joanna', data_get($freshDraft->metadata, 'order_snapshot.billing.first_name'));
        $this->assertSame('Magazynowa 8', data_get($freshDraft->metadata, 'order_snapshot.shipping.address_1'));
        $this->assertSame('Proszę zadzwonić przed dostawą.', data_get($freshDraft->metadata, 'order_snapshot.customer_note'));

        $packingTask = PackingTask::query()->where('external_order_id', $order->id)->sole();
        $this->assertSame('open', $packingTask->status);
        $this->assertSame('0.0000', (string) $packingTask->quantity_picked);
        $this->assertSame('2.0000', (string) $packingTask->quantity_required);
        $this->assertSame($newProduct->id, $packingTask->product_id);
        $this->assertNull($packingTask->picked_at);

        $this->assertDatabaseHas('integration_sync_logs', [
            'external_id' => '9001',
            'operation' => 'order_manual_update',
            'status' => 'success',
        ]);
    }

    public function test_contact_edit_keeps_unmapped_woo_line_and_picking_and_updates_blpaczka_point_meta(): void
    {
        [$channel, , $oldProduct, , $order] = $this->createOrderContext();
        ProductChannelMapping::query()
            ->where('sales_channel_id', $channel->id)
            ->where('product_id', $oldProduct->id)
            ->delete();

        $line = $order->lines()->sole();
        $line->update([
            'product_id' => null,
            'sku' => 'WOO-ORPHAN',
            'name' => 'Pozycja tylko z WooCommerce',
            'raw_payload' => [
                'id' => 7001,
                'product_id' => 9911,
                'variation_id' => 0,
                'sku' => 'WOO-ORPHAN',
                'name' => 'Pozycja tylko z WooCommerce',
                'quantity' => 1,
                'subtotal' => '100.00',
                'total' => '125.00',
            ],
        ]);
        $raw = (array) $order->raw_payload;
        $raw['shipping_lines'][0]['meta_data'] = [[
            'id' => 91,
            'key' => 'BLPACZKA_blpaczka_point_id',
            'value' => 'WAW001',
        ]];
        $order->update(['raw_payload' => $raw]);

        app(PackingTaskService::class)->syncForOrder($order);
        $packingTask = PackingTask::query()->where('external_order_id', $order->id)->sole();
        $packingTask->update([
            'quantity_picked' => 1,
            'status' => 'picked',
            'picked_at' => now(),
        ]);
        $localLineId = $line->id;

        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/9001*' => Http::response([
                'id' => 9001,
                'number' => '9001',
                'status' => 'processing',
                'currency' => 'PLN',
                'total' => '125.00',
                'line_items' => [[
                    'id' => 7001,
                    'product_id' => 9911,
                    'variation_id' => 0,
                    'sku' => 'WOO-ORPHAN',
                    'name' => 'Pozycja tylko z WooCommerce',
                    'quantity' => 1,
                    'subtotal' => '100.00',
                    'total' => '125.00',
                ]],
            ], 200),
        ]);

        $this->put(route('orders.update', $order), [
            'expected_version' => app(OrderEditingService::class)->version($order),
            'expected_remote_modified_at' => '',
            'billing' => [
                'first_name' => 'Maria',
                'last_name' => 'Zielińska',
                'email' => 'maria@example.test',
                'country' => 'pl',
            ],
            'shipping' => [
                'first_name' => 'Maria',
                'last_name' => 'Zielińska',
                'address_1' => 'Kwiatowa 5',
                'city' => 'Kraków',
                'postcode' => '30-001',
                'country' => 'pl',
            ],
            'target_point' => 'KRK123',
            'customer_note' => 'Nowe dane kontaktowe.',
            'payment_method' => 'cod',
            'payment_method_title' => 'Za pobraniem',
            'shipping_line' => [
                'id' => 81,
                'method_id' => 'flat_rate:1',
                'method_title' => 'Kurier standardowy',
                'total' => '10.00',
            ],
            'lines' => [
                $line->id => [
                    'product_id' => '',
                    'quantity' => 1,
                    'subtotal' => '100.00',
                    'total' => '125.00',
                ],
            ],
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/orders/9001');
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && ! array_key_exists('line_items', $request->data())
            && data_get($request->data(), 'shipping_lines.0.meta_data.0.id') === 91
            && data_get($request->data(), 'shipping_lines.0.meta_data.0.key') === 'BLPACZKA_blpaczka_point_id'
            && data_get($request->data(), 'shipping_lines.0.meta_data.0.value') === 'KRK123');

        $freshLine = $order->fresh()->lines()->sole();
        $this->assertSame($localLineId, $freshLine->id);
        $this->assertNull($freshLine->product_id);
        $this->assertSame('9911', (string) data_get($freshLine->raw_payload, 'product_id'));
        $this->assertSame('Maria', data_get($order->fresh()->billing_data, 'first_name'));
        $this->assertSame('PL', data_get($order->fresh()->billing_data, 'country'));
        $this->assertSame('KRK123', data_get($order->fresh()->raw_payload, 'shipping_lines.0.meta_data.0.value'));

        $packingTask->refresh();
        $this->assertSame('picked', $packingTask->status);
        $this->assertSame('1.0000', (string) $packingTask->quantity_picked);
        $this->assertSame($localLineId, $packingTask->external_order_line_id);
        $this->assertNotNull($packingTask->picked_at);
    }

    public function test_price_only_edit_keeps_picking_and_reuses_legacy_draft_wz(): void
    {
        [, , $product, , $order, $warehouse] = $this->createOrderContext();
        $line = $order->lines()->sole();
        app(StockReservationService::class)->syncForOrder($order);

        $legacyDraft = WarehouseDocument::query()->create([
            'number' => 'WZ/2026/000777',
            'type' => 'WZ',
            'status' => 'draft',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'external_reference' => $order->external_number,
            'notes' => 'Ręcznie utworzony szkic',
            'metadata' => [
                'external_order_id' => $order->external_id,
                'sales_channel_id' => $order->sales_channel_id,
            ],
        ]);
        $legacyDraft->lines()->create([
            'product_id' => $product->id,
            'quantity' => 0.5,
            'notes' => 'Sprawdzić opakowanie',
        ]);

        app(PackingTaskService::class)->syncForOrder($order);
        $packingTask = PackingTask::query()->where('external_order_id', $order->id)->sole();
        $packingTask->update([
            'quantity_picked' => 1,
            'status' => 'picked',
            'picked_at' => now(),
        ]);
        $pickedAt = $packingTask->picked_at?->format('Y-m-d H:i:s');
        $localLineId = $line->id;

        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/9001*' => Http::response([
                'id' => 9001,
                'number' => '9001',
                'status' => 'processing',
                'currency' => 'PLN',
                'total' => '130.00',
                'line_items' => [[
                    'id' => 7001,
                    'product_id' => 701,
                    'variation_id' => 0,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity' => 1,
                    'subtotal' => '110.00',
                    'total' => '130.00',
                ]],
            ], 200),
        ]);

        $this->put(route('orders.update', $order), [
            'expected_version' => app(OrderEditingService::class)->version($order),
            'expected_remote_modified_at' => '',
            'billing' => (array) $order->billing_data,
            'shipping' => (array) $order->shipping_data,
            'customer_note' => 'Stara uwaga',
            'payment_method' => 'przelewy24',
            'payment_method_title' => 'Przelewy24',
            'lines' => [
                $line->id => [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'subtotal' => '110.00',
                    'total' => '130.00',
                ],
            ],
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/orders/9001');
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'line_items.0.subtotal') === '110.00'
            && data_get($request->data(), 'line_items.0.total') === '130.00');

        $freshLine = $order->fresh()->lines()->sole();
        $this->assertSame($localLineId, $freshLine->id);
        $this->assertSame('130.0000', (string) $freshLine->unit_gross_price);

        $packingTask->refresh();
        $this->assertSame('picked', $packingTask->status);
        $this->assertSame('1.0000', (string) $packingTask->quantity_picked);
        $this->assertSame($pickedAt, $packingTask->picked_at?->format('Y-m-d H:i:s'));

        $this->assertSame(1, WarehouseDocument::query()->where('type', 'WZ')->count());
        $freshDraft = $legacyDraft->fresh('lines');
        $this->assertSame($legacyDraft->id, $freshDraft->id);
        $this->assertNotNull($freshDraft->order_fulfillment_key);
        $this->assertSame('Ręcznie utworzony szkic', $freshDraft->notes);
        $this->assertSame('1.0000', (string) $freshDraft->lines->sole()->quantity);
        $this->assertSame('Sprawdzić opakowanie', $freshDraft->lines->sole()->notes);
    }

    public function test_posted_wz_and_generated_shipment_label_keep_order_editing_blocked(): void
    {
        [$channel, $integration, , , $order, $warehouse] = $this->createOrderContext();
        $line = $order->lines()->sole();
        $document = WarehouseDocument::query()->create([
            'number' => 'WZ/2026/000001',
            'type' => 'WZ',
            'status' => 'posted',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'posted_at' => now(),
            'external_reference' => $order->external_number,
            'metadata' => [
                'external_order_id' => $order->external_id,
                'sales_channel_id' => $channel->id,
            ],
        ]);

        $this->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('To zamówienie jest teraz tylko do odczytu')
            ->assertSee('dokument WZ został już zaksięgowany');

        Http::fake();
        $this->put(route('orders.update', $order), [
            'expected_version' => app(OrderEditingService::class)->version($order),
            'expected_remote_modified_at' => '',
            'billing' => ['country' => 'PL'],
            'shipping' => ['country' => 'PL'],
            'lines' => [
                $line->id => [
                    'product_id' => $line->product_id,
                    'quantity' => 1,
                ],
            ],
        ])->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'WZ został już zaksięgowany'));
        Http::assertNothingSent();

        $document->update(['status' => 'draft', 'posted_at' => null]);
        ShippingLabel::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'wordpress_integration_id' => $integration->id,
            'purpose' => 'shipment',
            'status' => 'generated',
            'provider' => 'inpost',
            'disk' => 'local',
            'path' => 'labels/9001.pdf',
            'generated_at' => now(),
        ]);

        $this->get(route('orders.edit', $order))
            ->assertOk()
            ->assertSee('To zamówienie jest teraz tylko do odczytu')
            ->assertSee('Edycja jest zablokowana po wygenerowaniu etykiety');
    }

    /**
     * @return array{SalesChannel, WordpressIntegration, Product, Product, ExternalOrder, Warehouse}
     */
    private function createOrderContext(): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'WC_ORDER_EDIT',
            'name' => 'Sklep edycja zamówień',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sklep testowy',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'M1',
            'name' => 'Magazyn główny',
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
        $oldProduct = $this->createMappedProduct($channel, 'SKU-OLD', 'Stara koszula', '701');
        $newProduct = $this->createMappedProduct($channel, 'SKU-NEW', 'Nowa koszula', '702');

        foreach ([$oldProduct, $newProduct] as $product) {
            StockBalance::query()->create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity_on_hand' => 10,
                'quantity_reserved' => 0,
                'quantity_available' => 10,
            ]);
        }

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => '9001',
            'external_number' => '9001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 125,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => 'anna@example.test',
                'phone' => '+48123123123',
                'country' => 'PL',
            ],
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'address_1' => 'Stara 1',
                'city' => 'Warszawa',
                'postcode' => '00-001',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'customer_note' => 'Stara uwaga',
                'payment_method' => 'przelewy24',
                'payment_method_title' => 'Przelewy24',
                'shipping_lines' => [[
                    'id' => 81,
                    'method_id' => 'flat_rate:1',
                    'method_title' => 'Kurier standardowy',
                    'total' => '10.00',
                ]],
                'meta_data' => [
                    ['id' => 31, 'key' => 'billing_nip', 'value' => '1234567890'],
                    ['id' => 32, 'key' => 'paczkomat_id', 'value' => 'WAW001'],
                ],
            ],
            'external_created_at' => now()->subHour(),
        ]);
        $order->lines()->create([
            'product_id' => $oldProduct->id,
            'external_line_id' => '7001',
            'canonical_external_line_id' => '7001',
            'sku' => $oldProduct->sku,
            'name' => $oldProduct->name,
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 125,
            'vat_rate' => 23,
            'raw_payload' => [
                'id' => 7001,
                'product_id' => 701,
                'variation_id' => 0,
                'sku' => $oldProduct->sku,
                'name' => $oldProduct->name,
                'quantity' => 1,
                'subtotal' => '100.00',
                'total' => '125.00',
            ],
        ]);

        return [$channel, $integration, $oldProduct, $newProduct, $order, $warehouse];
    }

    private function createMappedProduct(
        SalesChannel $channel,
        string $sku,
        string $name,
        string $externalProductId,
    ): Product {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalProductId,
            'external_sku' => $sku,
            'stock_sync_enabled' => true,
        ]);

        return $product;
    }
}
