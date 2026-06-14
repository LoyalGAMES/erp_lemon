<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\InvoiceFile;
use App\Models\KsefSubmission;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\StockReservation;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Invoices\InvoiceSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderInvoiceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_posted_wz_order_can_issue_invoice_and_upload_it_to_woocommerce(): void
    {
        Http::fake([
            'https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice' => Http::response([
                'order_id' => 501,
                'invoice_number' => 'FV/ERP/'.now()->format('Y').'/00001',
                'file_url' => 'https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice/download?token=test-token',
                'stored_file' => true,
                'note_id' => 7001,
            ]),
        ]);

        [$order] = $this->createFulfilledOrder();
        $order->update([
            'billing_data' => array_merge($order->billing_data ?? [], [
                'company' => 'Firma testowa sp. z o.o.',
                'nip' => '5261040828',
            ]),
        ]);

        app(InvoiceSettingsService::class)->updateSellerData([
            'name' => 'Sempre Love sp. z o.o.',
            'tax_id' => '5261040828',
            'address_1' => 'Testowa 1',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'email' => 'biuro@example.test',
            'phone' => '+48123123123',
            'bank_account' => 'PL00111122223333444455556666',
        ]);
        app(InvoiceSettingsService::class)->updateNumberingData([
            'sales_prefix' => 'FV/ERP',
            'correction_prefix' => 'FK/ERP',
            'padding' => 5,
            'payment_due_days' => 7,
        ]);

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('WZ zaksięgowane')
            ->assertSee('Wystaw fakturę')
            ->assertDontSee('Utwórz WZ');

        $this->post(route('orders.invoice.create', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $invoice = Invoice::query()->with(['lines', 'files'])->firstOrFail();

        $this->assertSame($order->id, $invoice->external_order_id);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('FV/ERP/'.now()->format('Y').'/00001', $invoice->number);
        $this->assertSame(now()->addDays(7)->toDateString(), $invoice->payment_due_date->toDateString());
        $this->assertSame('Sempre Love sp. z o.o.', $invoice->seller_data['name']);
        $this->assertSame('5261040828', $invoice->seller_data['tax_id']);
        $this->assertSame('100.00', (string) $invoice->net_total);
        $this->assertSame('23.00', (string) $invoice->vat_total);
        $this->assertSame('123.00', (string) $invoice->gross_total);
        $this->assertSame('success', $invoice->metadata['woocommerce_upload']['status']);
        $this->assertNull($invoice->metadata['woocommerce_upload']['media_id']);
        $this->assertSame('lemon_plugin', $invoice->metadata['woocommerce_upload']['delivery_mode']);
        $this->assertSame('https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice/download?token=test-token', $invoice->metadata['woocommerce_upload']['file_url']);
        $this->assertCount(1, $invoice->lines);

        $this->assertSame(2, InvoiceFile::query()->count());
        $file = InvoiceFile::query()->where('type', 'pdf')->firstOrFail();
        $this->assertSame($invoice->id, $file->invoice_id);
        $this->assertSame('pdf', $file->type);
        $this->assertNotEmpty($file->sha256);
        $this->assertSame('lemon_plugin', $file->metadata['wordpress_invoice_delivery']);
        $this->assertSame('https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice/download?token=test-token', $file->metadata['wordpress_source_url']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice'
            && $request['invoice_number'] === $invoice->number
            && $request['invoice_type'] === 'vat'
            && $request['file_sha256'] === $file->sha256
            && str_starts_with((string) base64_decode((string) $request['file_base64'], true), '%PDF-'));

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://shop.test/wp-json/wp/v2/media');

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('Faktura '.$invoice->number)
            ->assertDontSee('Wystaw fakturę')
            ->assertDontSee('Utwórz WZ');

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Wyślij ponownie')
            ->assertSee('PDF')
            ->assertSee('HTML');

        $this->post(route('invoices.woocommerce.upload', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status', "Faktura {$invoice->number} została wysłana do zamówienia WooCommerce.");
    }

    public function test_invoice_upload_is_blocked_when_required_seller_data_is_missing(): void
    {
        Http::fake();

        [$order] = $this->createFulfilledOrder();

        $this->post(route('orders.invoice.create', $order))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Invoice::query()->count());

        Http::assertNothingSent();
    }

    public function test_invoice_remains_created_when_lemon_plugin_is_missing_in_woocommerce(): void
    {
        Http::fake([
            'https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice' => Http::response([
                'code' => 'rest_no_route',
            ], 404),
        ]);

        [$order] = $this->createFulfilledOrder();
        $order->update([
            'billing_data' => array_merge($order->billing_data ?? [], [
                'company' => 'Firma testowa sp. z o.o.',
                'nip' => '5261040828',
            ]),
        ]);

        app(InvoiceSettingsService::class)->updateSellerData([
            'name' => 'Sempre Love sp. z o.o.',
            'tax_id' => '5261040828',
            'address_1' => 'Testowa 1',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'email' => 'biuro@example.test',
            'phone' => '+48123123123',
            'bank_account' => 'PL00111122223333444455556666',
        ]);

        $this->post(route('orders.invoice.create', $order))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Wystawiono fakturę')
                && str_contains($message, 'nie jest zainstalowana albo aktywna'));

        $invoice = Invoice::query()->firstOrFail();

        $this->assertSame('issued', $invoice->status);
        $this->assertSame('failed', data_get($invoice->metadata, 'woocommerce_upload.status'));
        $this->assertTrue(data_get($invoice->metadata, 'woocommerce_upload.requires_resend'));
        $this->assertStringContainsString('nie jest zainstalowana albo aktywna', (string) data_get($invoice->metadata, 'woocommerce_upload.error'));
    }

    public function test_invoice_issue_can_automatically_queue_ksef_submission(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        Http::fake([
            'https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice' => Http::response([
                'order_id' => 501,
                'file_url' => 'https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice/download?token=ksef-token',
                'stored_file' => true,
                'note_id' => 7101,
            ]),
        ]);

        app(DocumentAutomationSettingsService::class)->updateRules([
            'invoice.issued' => [
                'invoice.ksef.submit' => '1',
            ],
        ]);

        [$order] = $this->createFulfilledOrder();
        $order->update([
            'billing_data' => array_merge($order->billing_data ?? [], [
                'company' => 'Firma testowa sp. z o.o.',
                'nip' => '5261040828',
            ]),
        ]);

        app(InvoiceSettingsService::class)->updateSellerData([
            'name' => 'Sempre Love sp. z o.o.',
            'tax_id' => '5261040828',
            'address_1' => 'Testowa 1',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'email' => 'biuro@example.test',
            'phone' => '+48123123123',
            'bank_account' => 'PL00111122223333444455556666',
        ]);

        $this->post(route('orders.invoice.create', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $invoice = Invoice::query()->firstOrFail();
        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame($invoice->id, $submission->invoice_id);
        $this->assertSame('missing_configuration', $submission->status);
        $this->assertStringContainsString('<P_2>'.$invoice->number.'</P_2>', (string) $submission->xml_payload);
        $this->assertStringContainsString('Brak tokena dostępu KSeF', (string) $submission->last_error);

        $this->post(route('orders.invoice.create', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, Invoice::query()->count());
        $this->assertSame(1, KsefSubmission::query()->count());
    }

    public function test_invoice_issue_skips_automatic_ksef_submission_for_b2c_order(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        Http::fake([
            'https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice' => Http::response([
                'order_id' => 501,
                'file_url' => 'https://shop.test/wp-json/lemon-erp/v1/orders/501/invoice/download?token=b2c-token',
                'stored_file' => true,
                'note_id' => 7102,
            ]),
        ]);

        app(DocumentAutomationSettingsService::class)->updateRules([
            'invoice.issued' => [
                'invoice.ksef.submit' => '1',
            ],
        ]);

        [$order] = $this->createFulfilledOrder();

        app(InvoiceSettingsService::class)->updateSellerData([
            'name' => 'Sempre Love sp. z o.o.',
            'tax_id' => '5261040828',
            'address_1' => 'Testowa 1',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'email' => 'biuro@example.test',
            'phone' => '+48123123123',
            'bank_account' => 'PL00111122223333444455556666',
        ]);

        $this->post(route('orders.invoice.create', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $invoice = Invoice::query()->firstOrFail();

        $this->assertSame('', $invoice->buyer_data['tax_id']);
        $this->assertSame(0, KsefSubmission::query()->count());
    }

    public function test_operator_can_open_order_details_with_lines_reservations_wz_and_woocommerce_notes(): void
    {
        [$order, $document] = $this->createFulfilledOrder();

        $product = Product::query()->where('sku', 'SKU-FV')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'WC_B2C')->firstOrFail();

        StockReservation::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->external_id,
            'quantity' => 1,
            'status' => 'active',
            'reserved_at' => now(),
        ]);

        $order->update([
            'shipping_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'address_1' => 'Magazynowa 8',
                'postcode' => '00-003',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'raw_payload' => [
                'payment_method_title' => 'Przelew online',
                'shipping_lines' => [
                    ['method_title' => 'Kurier testowy'],
                ],
                'erp_imported_order_notes' => [
                    [
                        'id' => 9101,
                        'date_created' => '2026-06-01T10:00:00',
                        'note' => 'Klient prosi o szybką wysyłkę.',
                        'customer_note' => false,
                    ],
                ],
            ],
        ]);

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('Szczegóły');

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Zamówienie 501')
            ->assertSee('Pozycje zamówienia')
            ->assertSee('SKU-FV')
            ->assertSee('Produkt fakturowany')
            ->assertSee('Rezerwacje magazynowe')
            ->assertSee('WC_B2C')
            ->assertSee($document->number)
            ->assertSee('Faktury')
            ->assertSee('Pakowanie i etykiety')
            ->assertSee('Notatki WooCommerce')
            ->assertSee('Klient prosi o szybką wysyłkę.')
            ->assertSee('Wystaw fakturę');
    }

    /**
     * @return array{0:ExternalOrder,1:WarehouseDocument}
     */
    private function createFulfilledOrder(): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'wp_api_username' => 'erp',
            'wp_api_password_encrypted' => Crypt::encryptString('app-password'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);

        $warehouse = Warehouse::query()->create([
            'code' => 'WC_B2C',
            'name' => 'WooCommerce B2C',
            'type' => 'virtual',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-FV',
            'name' => 'Produkt fakturowany',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '501',
            'external_number' => '501',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 123,
            'billing_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Kowalski',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'email' => 'jan@example.test',
            ],
            'raw_payload' => [
                'payment_method_title' => 'Przelew',
            ],
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '9001',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'raw_payload' => [
                'total' => '100.00',
                'total_tax' => '23.00',
            ],
        ]);

        $document = WarehouseDocument::query()->create([
            'number' => 'WZ/'.now()->format('Y').'/000001',
            'type' => 'WZ',
            'status' => 'posted',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'posted_at' => now(),
            'external_reference' => $order->external_number,
            'metadata' => [
                'source' => 'external_order',
                'external_order_id' => $order->external_id,
                'external_order_number' => $order->external_number,
                'sales_channel_id' => $channel->id,
            ],
        ]);

        $document->lines()->create([
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        return [$order, $document];
    }
}
