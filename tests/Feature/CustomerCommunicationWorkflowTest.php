<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\Customer;
use App\Models\CustomerExternalAccount;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Communication\CustomerEmailWorkflowSettingsService;
use App\Services\Communication\MailSettingsService;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerCommunicationWorkflowTest extends TestCase
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

    public function test_operator_can_send_manual_message_from_order(): void
    {
        Mail::fake();
        $order = $this->createOrder('client@example.test');

        $this->post(route('orders.message.send', $order), [
            'subject' => 'Informacja o zamówieniu',
            'body' => 'Dzień dobry, zamówienie jest w realizacji.',
        ])->assertRedirect()->assertSessionHas('status');

        $message = CustomerMessage::query()->firstOrFail();

        $this->assertSame($order->id, $message->external_order_id);
        $this->assertSame('manual', $message->type);
        $this->assertSame('sent', $message->status);
        $this->assertSame('client@example.test', $message->recipient_email);

        Mail::assertSent(CustomerMessageMail::class, function (CustomerMessageMail $mail) use ($message): bool {
            return $mail->customerMessage->is($message);
        });
    }

    public function test_sent_order_message_has_an_immutable_preview_and_accurate_card_status(): void
    {
        $settings = app(MailSettingsService::class);
        $settings->update([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'sklep@example.test',
            'from_name' => 'Sempre przed zmianą',
            'reply_to_address' => 'pomoc@example.test',
            'timeout' => 15,
            'brand_name' => 'Marka z chwili wysyłki',
            'accent_color' => '#315f4b',
            'header_text' => 'Nagłówek z chwili wysyłki',
            'signature' => 'Podpis z chwili wysyłki',
            'footer_text' => 'Stopka z chwili wysyłki',
            'support_email' => 'pomoc@example.test',
            'support_phone' => '+48 500 600 700',
        ]);
        $order = $this->createOrder('client@example.test');

        $this->post(route('orders.message.send', $order), [
            'subject' => 'Stan faktyczny zamówienia',
            'body' => 'To jest dokładnie wysłana treść.',
        ])->assertRedirect()->assertSessionHas('status');

        $message = CustomerMessage::query()->firstOrFail();

        $this->assertSame('sent', $message->status);
        $this->assertSame('Marka z chwili wysyłki', data_get($message->delivery_snapshot, 'layout.brand_name'));
        $this->assertStringContainsString('Nagłówek z chwili wysyłki', (string) $message->rendered_html_snapshot);
        $this->assertStringContainsString('To jest dokładnie wysłana treść.', (string) $message->rendered_text_snapshot);

        $settings->update([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'nowy-sklep@example.test',
            'from_name' => 'Sempre po zmianie',
            'reply_to_address' => 'nowa-pomoc@example.test',
            'timeout' => 15,
            'brand_name' => 'Nowa marka',
            'accent_color' => '#884422',
            'header_text' => 'Nowy nagłówek',
            'signature' => 'Nowy podpis',
            'footer_text' => 'Nowa stopka',
            'support_email' => 'nowa-pomoc@example.test',
        ]);

        $this->get(route('orders.messages.preview', [$order, $message]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('Nagłówek z chwili wysyłki')
            ->assertSee('To jest dokładnie wysłana treść.')
            ->assertDontSee('Nowy nagłówek');

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Wysłane: 1 / 1')
            ->assertSee('Wysłano')
            ->assertSee('Podgląd')
            ->assertSee(route('orders.messages.preview', [$order, $message]), false);
    }

    public function test_order_message_preview_rejects_a_message_from_another_order_or_not_sent(): void
    {
        $order = $this->createOrder('client@example.test');
        $otherOrder = ExternalOrder::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_id' => '9002',
            'external_number' => '9002',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 10,
            'billing_data' => ['email' => 'other@example.test'],
            'shipping_data' => [],
            'raw_payload' => [],
        ]);
        $message = CustomerMessage::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'outgoing',
            'type' => 'manual',
            'status' => 'sent',
            'recipient_email' => 'client@example.test',
            'subject' => 'Wiadomość pierwszego zamówienia',
            'body' => 'Poufna treść pierwszego zamówienia.',
            'sent_at' => now(),
        ]);

        $this->get(route('orders.messages.preview', [$otherOrder, $message]))
            ->assertNotFound();

        $message->update(['status' => 'failed']);

        $this->get(route('orders.messages.preview', [$order, $message]))
            ->assertNotFound();
    }

    public function test_operator_can_send_manual_message_from_return(): void
    {
        Mail::fake();
        $returnCase = ReturnCase::query()->create([
            'number' => 'RET/2026/000001',
            'status' => 'pending',
            'customer_email' => 'return-client@example.test',
            'metadata' => ['source' => 'test'],
        ]);

        $this->post(route('returns.message.send', $returnCase), [
            'subject' => 'Informacja o zwrocie',
            'body' => 'Dzień dobry, zwrot został przyjęty do obsługi.',
        ])->assertRedirect()->assertSessionHas('status');

        $message = CustomerMessage::query()->firstOrFail();

        $this->assertSame($returnCase->id, $message->return_case_id);
        $this->assertSame('manual', $message->type);
        $this->assertSame('sent', $message->status);
        $this->assertSame('return-client@example.test', $message->recipient_email);

        Mail::assertSent(CustomerMessageMail::class, function (CustomerMessageMail $mail) use ($message): bool {
            return $mail->customerMessage->is($message);
        });
    }

    public function test_manual_return_message_renders_template_variables_on_backend(): void
    {
        Mail::fake();
        $order = $this->createOrder('return-client@example.test');
        $returnCase = ReturnCase::query()->create([
            'number' => 'RET/2026/000123',
            'external_order_id' => $order->id,
            'status' => 'pending',
            'customer_email' => 'return-client@example.test',
            'metadata' => ['source' => 'test'],
        ]);

        $this->post(route('returns.message.send', $returnCase), [
            'subject' => 'Informacja o zwrocie {{return_number}}',
            'body' => 'Zwrot {{ return_number }} do zamówienia {{order_number}} dla {{customer_email}}.',
        ])->assertRedirect()->assertSessionHas('status');

        $message = CustomerMessage::query()->firstOrFail();

        $this->assertSame('Informacja o zwrocie RET/2026/000123', $message->subject);
        $this->assertSame('Zwrot RET/2026/000123 do zamówienia 9001 dla return-client@example.test.', $message->body);
        $this->assertSame('RET/2026/000123', $message->metadata['return_number']);
        $this->assertSame('9001', $message->metadata['order_number']);
    }

    public function test_automated_order_status_message_is_deduplicated(): void
    {
        Mail::fake();
        $order = $this->createOrder('client@example.test');
        $communication = app(CustomerCommunicationService::class);

        $first = $communication->sendOrderStatus($order, 'order_received');
        $second = $communication->sendOrderStatus($order, 'order_received');

        $this->assertInstanceOf(CustomerMessage::class, $first);
        $this->assertNull($second);
        $this->assertSame(1, CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->where('type', 'automated')
            ->where('trigger', 'order_received')
            ->count());

        Mail::assertSent(CustomerMessageMail::class, function (CustomerMessageMail $mail): bool {
            return $mail->customerMessage->trigger === 'order_received';
        });
    }

    public function test_failed_automated_message_is_not_recreated_before_explicit_retry(): void
    {
        $order = $this->createOrder('client@example.test');
        CustomerMessage::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'outgoing',
            'type' => 'automated',
            'trigger' => 'order_received',
            'status' => 'failed',
            'recipient_email' => 'client@example.test',
            'subject' => 'Zamówienie przyjęte',
            'body' => 'Pierwsza próba nie została wysłana.',
            'failed_at' => now(),
            'error_message' => 'SMTP timeout',
        ]);

        $message = app(CustomerCommunicationService::class)
            ->sendOrderStatus($order, 'order_received');

        $this->assertNull($message);
        $this->assertSame(1, CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->where('trigger', 'order_received')
            ->count());
        Mail::assertNothingSent();
    }

    public function test_retry_does_not_reselect_a_pending_message_that_was_just_claimed(): void
    {
        $order = $this->createOrder('client@example.test');
        $recentlyClaimed = CustomerMessage::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'outgoing',
            'type' => 'automated',
            'trigger' => 'order_received',
            'status' => 'pending',
            'recipient_email' => 'client@example.test',
            'subject' => 'Niedawno podjęta wiadomość',
            'body' => 'Ta wiadomość jest właśnie wysyłana.',
        ]);
        $stalePending = CustomerMessage::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'outgoing',
            'type' => 'automated',
            'trigger' => 'order_delivered',
            'status' => 'pending',
            'recipient_email' => 'client@example.test',
            'subject' => 'Osierocona wiadomość',
            'body' => 'Tę wiadomość można bezpiecznie ponowić.',
        ]);

        DB::table('customer_messages')->where('id', $recentlyClaimed->id)->update([
            'created_at' => now()->subHour(),
            'updated_at' => now(),
        ]);
        DB::table('customer_messages')->where('id', $stalePending->id)->update([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subMinutes(10),
        ]);

        $result = app(CustomerCommunicationService::class)->retryUnsent();

        $this->assertSame(['selected' => 1, 'sent' => 1, 'failed' => 0], $result);
        $this->assertSame('pending', $recentlyClaimed->fresh()->status);
        $this->assertSame('sent', $stalePending->fresh()->status);
        Mail::assertSent(CustomerMessageMail::class, 1);
    }

    public function test_disabled_workflow_message_is_logged_as_skipped_and_not_sent(): void
    {
        Mail::fake();

        app(CustomerEmailWorkflowSettingsService::class)->update([
            'order_received' => [
                'enabled' => false,
                'stage' => 'Wyłączony',
                'subject' => 'Nie wysyłać {{order_number}}',
                'body' => 'Wyłączone.',
            ],
        ]);

        $order = $this->createOrder('client@example.test');
        $message = app(CustomerCommunicationService::class)->sendOrderStatus($order, 'order_received');

        $this->assertInstanceOf(CustomerMessage::class, $message);
        $this->assertSame('skipped', $message->status);
        $this->assertSame('Wysyłka wyłączona w workflow maili.', $message->error_message);

        Mail::assertNothingSent();
    }

    public function test_workflow_content_overrides_automatic_message_copy(): void
    {
        Mail::fake();

        app(CustomerEmailWorkflowSettingsService::class)->update([
            'order_packed' => [
                'enabled' => true,
                'stage' => 'Po pakowaniu',
                'subject' => 'Spakowaliśmy {{order_number}}',
                'body' => 'Paczka {{order_number}} czeka.',
            ],
        ]);

        $order = $this->createOrder('client@example.test');
        $message = app(CustomerCommunicationService::class)->sendOrderStatus($order, 'order_packed');

        $this->assertInstanceOf(CustomerMessage::class, $message);
        $this->assertSame('sent', $message->status);
        $this->assertSame('Spakowaliśmy 9001', $message->subject);
        $this->assertSame('Paczka 9001 czeka.', $message->body);

        Mail::assertSent(CustomerMessageMail::class, function (CustomerMessageMail $mail) use ($message): bool {
            return $mail->customerMessage->is($message);
        });
    }

    public function test_disabled_smtp_holds_message_until_operator_retries_it(): void
    {
        app(MailSettingsService::class)->update([
            'enabled' => false,
            'encryption' => 'tls',
            'timeout' => 15,
        ]);
        $order = $this->createOrder('client@example.test');

        $message = app(CustomerCommunicationService::class)->sendOrderStatus($order, 'order_received');

        $this->assertSame('held', $message?->status);
        $this->assertStringContainsString('SMTP jest wyłączone', (string) $message?->error_message);
        Mail::assertNothingSent();

        app(MailSettingsService::class)->update([
            'enabled' => true,
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'from_address' => 'sklep@example.test',
            'from_name' => 'Sempre',
            'timeout' => 15,
        ]);

        $this->post(route('settings.mail.retry-unsent'), ['limit' => 100])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('sent', $message->fresh()->status);
        $this->assertSame(1, CustomerMessage::query()->count());
        Mail::assertSent(CustomerMessageMail::class, 1);
    }

    public function test_order_mail_snapshots_products_totals_delivery_and_customer_action(): void
    {
        $order = $this->createOrder('client@example.test');
        $product = Product::query()->create([
            'sku' => 'SKU-LUNA',
            'name' => 'Sukienka Luna',
            'unit' => 'szt.',
            'quantity_precision' => 0,
            'attributes' => [
                'master' => ['media' => [['src' => 'https://cdn.example.test/luna.jpg']]],
            ],
            'is_active' => true,
        ]);
        ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $order->sales_channel_id,
            'external_product_id' => '501',
            'external_sku' => 'SKU-LUNA',
            'stock_sync_enabled' => true,
            'metadata' => ['woocommerce_permalink' => 'https://shop.test/produkt/luna'],
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'name' => 'Sklep',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
        ]);
        $order->update([
            'total_gross' => 264.80,
            'shipping_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'address_1' => 'Kwiatowa 12',
                'postcode' => '62-070',
                'city' => 'Poznań',
                'phone' => '+48 500 600 700',
            ],
            'raw_payload' => [
                'payment_method_title' => 'PayU',
                'shipping_total' => '14.90',
                'shipping_lines' => [['method_title' => 'Kurier InPost']],
            ],
        ]);
        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '7001',
            'sku' => 'SKU-LUNA',
            'name' => 'Sukienka Luna',
            'quantity' => 1,
            'unit_gross_price' => 249.90,
            'raw_payload' => [],
        ]);

        $message = app(CustomerCommunicationService::class)->sendOrderStatus($order->fresh(), 'order_received');

        $this->assertSame('Sukienka Luna', data_get($message?->metadata, 'items.0.name'));
        $this->assertSame('https://cdn.example.test/luna.jpg', data_get($message?->metadata, 'items.0.image_url'));
        $this->assertSame('https://shop.test/produkt/luna', data_get($message?->metadata, 'items.0.product_url'));
        $this->assertSame('264,80', data_get($message?->metadata, 'totals.grand_total_formatted'));
        $this->assertSame('Kurier InPost', data_get($message?->metadata, 'shipping_method'));
        $html = (new CustomerMessageMail($message))->render();
        $this->assertStringContainsString('Twoje produkty', $html);
        $this->assertStringContainsString('Sukienka Luna', $html);
        $this->assertStringContainsString('264,80', $html);
        $this->assertStringContainsString('Sprawdź szczegóły zamówienia', $html);
    }

    public function test_order_mail_uses_gross_woocommerce_amounts_for_items_shipping_and_discounts(): void
    {
        $order = $this->createOrder('client@example.test');
        $order->update([
            'total_gross' => 123.00,
            'raw_payload' => [
                'discount_total' => '10.00',
                'discount_tax' => '2.30',
                'shipping_total' => '10.00',
                'shipping_tax' => '2.30',
                'total_tax' => '23.00',
            ],
        ]);
        $order->lines()->create([
            'external_line_id' => 'VAT-1',
            'sku' => 'SKU-VAT',
            'name' => 'Produkt opodatkowany',
            'quantity' => 1,
            'unit_gross_price' => 90.00,
            'raw_payload' => [
                'quantity' => 1,
                'subtotal' => '100.00',
                'subtotal_tax' => '23.00',
                'total' => '90.00',
                'total_tax' => '20.70',
            ],
        ]);

        $message = app(CustomerCommunicationService::class)
            ->sendOrderStatus($order->fresh(), 'order_received');

        $this->assertSame(110.7, (float) data_get($message?->metadata, 'items.0.line_total'));
        $this->assertSame('110,70', data_get($message?->metadata, 'items.0.line_total_formatted'));
        $this->assertSame('123,00', data_get($message?->metadata, 'totals.subtotal_formatted'));
        $this->assertSame('12,30', data_get($message?->metadata, 'totals.discount_formatted'));
        $this->assertSame('12,30', data_get($message?->metadata, 'totals.shipping_formatted'));
        $this->assertSame('123,00', data_get($message?->metadata, 'totals.grand_total_formatted'));

        $html = (new CustomerMessageMail($message))->render();
        $this->assertStringContainsString('110,70 PLN', $html);
        $this->assertStringContainsString('−12,30 PLN', $html);
    }

    public function test_woocommerce_status_sync_is_independent_from_customer_email_workflow(): void
    {
        Http::fake();
        $order = $this->createOrder('client@example.test');
        WordpressIntegration::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'name' => 'Woo B2C',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => [
                'order_statuses' => [
                    'ready_to_ship' => 'ready-to-ship',
                    'shipped' => 'completed',
                    'packing_rollback' => 'processing',
                ],
            ],
        ]);

        app(CustomerEmailWorkflowSettingsService::class)->update([
            'order_ready_for_shipment' => [
                'enabled' => false,
                'stage' => 'Po pakowaniu',
            ],
        ]);

        $result = app(WooCommerceOrderStatusService::class)->markReadyForShipment($order);

        $this->assertSame('ready-to-ship', $result['status']);
        Http::assertSentCount(1);

        $log = IntegrationSyncLog::query()->where('operation', 'order_ready_for_shipment')->firstOrFail();
        $this->assertSame('success', $log->status);
    }

    public function test_guest_account_invitation_is_sent_once_with_a_secure_claim_action(): void
    {
        [$customer, $order] = $this->createCustomerWithOrder(false);
        $claimUrl = 'https://erp.example.test/customer-account/claim/test?expires=9999999999&signature=test';
        $communication = app(CustomerCommunicationService::class);

        $first = $communication->sendGuestAccountInvitation($customer, $order, $claimUrl);
        $second = $communication->sendGuestAccountInvitation($customer, $order, $claimUrl);

        $this->assertInstanceOf(CustomerMessage::class, $first);
        $this->assertNull($second);
        $this->assertSame($customer->id, $first->customer_id);
        $this->assertSame($order->id, $first->external_order_id);
        $this->assertSame('guest_account_invitation', $first->trigger);
        $this->assertSame('sent', $first->status);
        $this->assertSame($claimUrl, data_get($first->metadata, 'action_url'));
        $this->assertSame('Załóż konto i przypisz zamówienie', data_get($first->metadata, 'action_label'));
        $this->assertStringContainsString('9001', $first->subject);
        $this->assertStringContainsString('automatycznie pojawi się', $first->body);

        Mail::assertSent(CustomerMessageMail::class, 1);
    }

    public function test_account_created_confirmation_is_deduplicated_by_customer(): void
    {
        [$customer, $order] = $this->createCustomerWithOrder(true);
        $communication = app(CustomerCommunicationService::class);

        $first = $communication->sendCustomerAccountCreated($customer, $order);
        $second = $communication->sendCustomerAccountCreated($customer, null);

        $this->assertInstanceOf(CustomerMessage::class, $first);
        $this->assertNull($second);
        $this->assertSame('customer_account_created', $first->trigger);
        $this->assertSame('customer', data_get($first->metadata, 'entity_type'));
        $this->assertSame('https://shop.test/moje-konto/', data_get($first->metadata, 'action_url'));
        $this->assertSame(1, CustomerMessage::query()
            ->where('customer_id', $customer->id)
            ->where('trigger', 'customer_account_created')
            ->count());

        Mail::assertSent(CustomerMessageMail::class, 1);
    }

    private function createOrder(string $email): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'code' => 'woo',
            'name' => 'WooCommerce',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001',
            'external_number' => '9001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 129.99,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => $email,
            ],
            'shipping_data' => [],
            'raw_payload' => [],
        ]);
    }

    /** @return array{Customer,ExternalOrder} */
    private function createCustomerWithOrder(bool $registered): array
    {
        $order = $this->createOrder('anna@example.test');
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'name' => 'Woo B2C',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
        ]);
        $customer = Customer::query()->create([
            'email' => 'anna@example.test',
            'email_normalized' => 'anna@example.test',
            'first_name' => 'Anna',
            'last_name' => 'Kowalska',
            'display_name' => 'Anna Kowalska',
            'account_status' => $registered ? 'registered' : 'guest',
        ]);
        $account = CustomerExternalAccount::query()->create([
            'customer_id' => $customer->id,
            'wordpress_integration_id' => $integration->id,
            'external_customer_id' => $registered ? '321' : null,
            'email' => $customer->email,
            'email_normalized' => $customer->email_normalized,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'display_name' => $customer->display_name,
            'is_registered' => $registered,
        ]);
        $order->update([
            'customer_id' => $customer->id,
            'customer_external_account_id' => $account->id,
            'wordpress_integration_id' => $integration->id,
            'customer_match_method' => $registered ? 'external_id' : 'guest_email',
        ]);

        return [$customer, $order->fresh()];
    }
}
