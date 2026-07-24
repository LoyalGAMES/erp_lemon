<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Communication\MailSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderEditingExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        app(MailSettingsService::class)->update([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'sklep@example.test',
            'from_name' => 'Sempre',
            'timeout' => 15,
        ]);
    }

    public function test_order_page_exposes_full_width_product_editor_status_and_payment_actions(): void
    {
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        $product = $this->createMappedProduct($channel, 'SKU-DRESS', 'Sukienka LENA', '501', [
            'master' => [
                'media' => [['src' => 'https://images.example.test/lena.jpg']],
            ],
        ]);
        $order = $this->createOrder($channel, $product, 'pending');
        $order->update([
            'raw_payload' => array_merge($order->raw_payload, [
                'customer_note' => 'Proszę zapakować bez plastiku.',
            ]),
        ]);
        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-order-lines-form', false)
            ->assertSee('order-product-table', false)
            ->assertSee('order-product-thumb', false)
            ->assertSee('<img', false)
            ->assertSee('Zapisz zmiany produktów')
            ->assertSee('Zmień status zamówienia')
            ->assertSee(route('orders.status.update', $order), false)
            ->assertSee('Ponów prośbę o wpłatę')
            ->assertSee(route('orders.payment-reminder.send', $order), false)
            ->assertSee('https://shop.test/pay/9001', false)
            ->assertSee('Notatka klienta do zamówienia')
            ->assertSee('Proszę zapakować bez plastiku.');

        $this->getJson(route('orders.products.lookup', ['order' => $order, 'q' => 'LENA']))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $product->id,
                'sku' => 'SKU-DRESS',
                'name' => 'Sukienka LENA',
            ]);

        $returnCase = ReturnCase::query()->create([
            'number' => 'RET/2026/000123',
            'external_order_id' => $order->id,
            'status' => 'document_created',
        ]);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('RET/2026/000123')
            ->assertSee('Przyjęcie przygotowane')
            ->assertSee('Przejdź do zwrotu')
            ->assertSee(route('returns.show', $returnCase), false);
    }

    public function test_operator_can_change_order_status_in_woocommerce_and_erp(): void
    {
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        $product = $this->createMappedProduct($channel, 'SKU-STATUS', 'Produkt statusowy', '601');
        $order = $this->createOrder($channel, $product, 'pending');

        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/9001*' => Http::response([
                'id' => 9001,
                'number' => '9001',
                'status' => 'processing',
            ], 200),
        ]);

        $this->patch(route('orders.status.update', $order), [
            'status' => 'processing',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertDatabaseHas('integration_sync_logs', [
            'external_id' => '9001',
            'operation' => 'order_status_manual_update',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('packing_tasks', [
            'external_order_id' => $order->id,
            'product_id' => $product->id,
        ]);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/wp-json/wc/v3/orders/9001')
            && $request->data()['status'] === 'processing');
    }

    public function test_operator_can_replace_order_product_upstream_and_locally(): void
    {
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        $oldProduct = $this->createMappedProduct($channel, 'SKU-OLD', 'Stary produkt', '701');
        $newProduct = $this->createMappedProduct($channel, 'SKU-NEW', 'Nowy produkt', '702');
        $order = $this->createOrder($channel, $oldProduct, 'processing');
        $line = $order->lines()->firstOrFail();

        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/9001*' => Http::response([
                'id' => 9001,
                'number' => '9001',
                'status' => 'processing',
                'total' => '250.00',
                'line_items' => [[
                    'id' => 7001,
                    'product_id' => 702,
                    'variation_id' => 0,
                    'sku' => 'SKU-NEW',
                    'name' => 'Nowy produkt',
                    'quantity' => 2,
                    'subtotal' => '200.00',
                    'total' => '250.00',
                ]],
            ], 200),
        ]);

        $this->put(route('orders.lines.update', $order), [
            'lines' => [
                $line->id => [
                    'product_id' => $newProduct->id,
                    'quantity' => 2,
                ],
            ],
        ])->assertRedirect()->assertSessionHas('status');

        $freshLine = $order->fresh()->lines()->sole();
        $this->assertSame($newProduct->id, $freshLine->product_id);
        $this->assertSame('SKU-NEW', $freshLine->sku);
        $this->assertSame('2.0000', (string) $freshLine->quantity);
        $this->assertSame('250.00', (string) $order->fresh()->total_gross);
        $this->assertSame('success', IntegrationSyncLog::query()->where('operation', 'order_lines_manual_update')->sole()->status);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && (int) data_get($request->data(), 'line_items.0.id') === 7001
            && (int) data_get($request->data(), 'line_items.0.product_id') === 702
            && (float) data_get($request->data(), 'line_items.0.quantity') === 2.0);
    }

    public function test_payment_reminder_contains_clickable_payment_link(): void
    {
        Mail::fake();
        $channel = $this->createChannel();
        $this->createIntegration($channel);
        $product = $this->createMappedProduct($channel, 'SKU-PAY', 'Produkt do opłacenia', '801');
        $order = $this->createOrder($channel, $product, 'pending');

        $this->post(route('orders.payment-reminder.send', $order), [
            'payment_url' => 'https://shop.test/pay/9001',
        ])->assertRedirect()->assertSessionHas('status');

        $message = CustomerMessage::query()->sole();
        $this->assertSame('manual_payment_reminder', $message->trigger);
        $this->assertSame('https://shop.test/pay/9001', data_get($message->metadata, 'payment_url'));
        $this->assertStringContainsString('Dokończ płatność', $message->subject);
        $this->assertStringContainsString('Przejdź do płatności', (new CustomerMessageMail($message))->render());
        Mail::assertSent(CustomerMessageMail::class, fn (CustomerMessageMail $mail): bool => $mail->customerMessage->is($message));
    }

    private function createChannel(): SalesChannel
    {
        return SalesChannel::query()->create([
            'code' => 'WC_EDIT',
            'name' => 'WooCommerce edycja',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
    }

    private function createIntegration(SalesChannel $channel): WordpressIntegration
    {
        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sklep testowy',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMappedProduct(
        SalesChannel $channel,
        string $sku,
        string $name,
        string $externalProductId,
        array $attributes = [],
    ): Product {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'attributes' => $attributes,
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

    private function createOrder(SalesChannel $channel, Product $product, string $status): ExternalOrder
    {
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001',
            'external_number' => '9001',
            'status' => $status,
            'currency' => 'PLN',
            'total_gross' => 125,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => 'anna@example.test',
            ],
            'shipping_data' => [],
            'raw_payload' => [
                'payment_method_title' => 'Przelew online',
                'payment_url' => 'https://shop.test/pay/9001',
                'order_key' => 'wc_order_test',
            ],
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '7001',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 125,
            'vat_rate' => 23,
            'raw_payload' => [
                'id' => 7001,
                'product_id' => ProductChannelMapping::query()->where('product_id', $product->id)->value('external_product_id'),
                'quantity' => 1,
            ],
        ]);

        return $order;
    }
}
