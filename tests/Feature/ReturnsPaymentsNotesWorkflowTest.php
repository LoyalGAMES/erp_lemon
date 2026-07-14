<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\CourierAccount;
use App\Models\CustomerMessage;
use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\InternalNote;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\Warehouse;
use App\Services\Communication\MailSettingsService;
use App\Services\Payments\MbankTransferBasketSettingsService;
use App\Services\Payments\PayuRefundService;
use App\Services\Payments\PayuRefundSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReturnsPaymentsNotesWorkflowTest extends TestCase
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

    public function test_operator_can_add_internal_note_and_manual_payment_to_order(): void
    {
        $order = $this->createOrder();

        $this->post(route('orders.notes.store', $order), [
            'body' => 'Klient prosi o kontakt przed wysyłką.',
        ])->assertRedirect()->assertSessionHas('status');

        $this->post(route('orders.payments.store', $order), [
            'amount' => '19.99',
            'currency' => 'PLN',
            'method' => 'blik',
            'reference' => 'BLIK-123',
            'description' => 'Dopłata za przesyłkę wymienną.',
            'operation_id' => (string) Str::uuid(),
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, InternalNote::query()->where('external_order_id', $order->id)->count());
        $payment = CustomerPayment::query()->firstOrFail();
        $this->assertSame($order->id, $payment->external_order_id);
        $this->assertSame('incoming', $payment->direction);
        $this->assertSame('blik', $payment->method);
        $this->assertSame('19.99', (string) $payment->amount);
    }

    public function test_return_can_generate_additional_exchange_label_to_customer(): void
    {
        Http::fake([
            '*/v1/organizations/111/shipments' => Http::response(['id' => 'SHIP-EX', 'status' => 'created'], 201),
            '*/v1/shipments/SHIP-EX/label*' => Http::response('%PDF-1.4 exchange-label', 200, ['Content-Type' => 'application/pdf']),
            '*/v1/shipments/SHIP-EX' => Http::response([
                'id' => 'SHIP-EX',
                'status' => 'confirmed',
                'tracking_number' => '520000888888888888888888',
            ], 200),
        ]);

        $order = $this->createOrder();
        $returnCase = $this->createReturnCase($order);
        $account = $this->createInpostAccount();

        $this->post(route('returns.shipping-label.create', $returnCase), [
            'courier_account_id' => $account->id,
            'purpose' => 'exchange',
        ])->assertRedirect()->assertSessionHas('status');

        $label = ShippingLabel::query()->firstOrFail();

        $this->assertSame($returnCase->id, $label->return_case_id);
        $this->assertSame('exchange', $label->purpose);
        $this->assertSame('exchange_to_customer', data_get($label->response_payload, 'direction'));
        $this->assertDatabaseHas('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'exchange_label_ready',
        ]);

        Http::assertSent(function ($request): bool {
            if ($request->method() !== 'POST' || ! str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/shipments')) {
                return true;
            }

            return str_starts_with((string) data_get($request->data(), 'reference'), 'WYMIANA ')
                && data_get($request->data(), 'receiver.email') === 'jan@example.test';
        });
    }

    public function test_return_payment_can_send_exchange_surcharge_request(): void
    {
        Mail::fake();

        $order = $this->createOrder();
        $returnCase = $this->createReturnCase($order);

        $this->post(route('returns.payments.store', $returnCase), [
            'amount' => '24.99',
            'currency' => 'PLN',
            'method' => 'blik',
            'reference' => 'DOPLATA-1',
            'payment_url' => 'https://pay.example.test/doplata/1',
            'description' => 'Dopłata do wymiany rozmiaru.',
            'send_payment_request' => '1',
        ])->assertRedirect()->assertSessionHas('status');

        $payment = CustomerPayment::query()->firstOrFail();
        $this->assertSame($returnCase->id, $payment->return_case_id);
        $this->assertSame('24.99', (string) $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->booked_at);
        $this->assertSame('https://pay.example.test/doplata/1', data_get($payment->metadata, 'payment_url'));

        $message = CustomerMessage::query()
            ->where('trigger', 'exchange_payment_requested')
            ->firstOrFail();

        $this->assertSame($returnCase->id, $message->return_case_id);
        $this->assertSame('sent', $message->status);
        $this->assertStringContainsString('24,99 PLN', $message->body);
        $this->assertSame('https://pay.example.test/doplata/1', data_get($message->metadata, 'payment_url'));
        $this->assertStringContainsString('Opłać dopłatę', (new CustomerMessageMail($message))->render());
    }

    public function test_return_detail_card_groups_operational_information_outside_index(): void
    {
        $order = $this->createOrder();
        $returnCase = $this->createReturnCase($order, [
            'notes' => 'Klient zgłosił wymianę rozmiaru.',
        ]);
        $orderLine = $order->lines()->firstOrFail();

        $returnCase->lines()->create([
            'product_id' => $orderLine->product_id,
            'external_order_line_id' => $orderLine->id,
            'quantity_expected' => 1,
            'quantity_accepted' => 1,
            'condition' => 'opened',
            'disposition' => 'exchange',
            'target_warehouse_id' => $returnCase->target_warehouse_id,
            'notes' => 'Rozmiar za mały.',
        ]);

        CustomerMessage::query()->create([
            'return_case_id' => $returnCase->id,
            'type' => 'manual',
            'status' => 'sent',
            'recipient_email' => 'jan@example.test',
            'subject' => 'Instrukcja wymiany {{return_number}}',
            'body' => 'Wyślij paczkę do magazynu dla {{order_number}}.',
            'metadata' => [
                'return_number' => $returnCase->number,
                'order_number' => $order->external_number,
            ],
            'sent_at' => now(),
        ]);

        CustomerPayment::query()->create([
            'return_case_id' => $returnCase->id,
            'direction' => 'incoming',
            'method' => 'blik',
            'status' => 'booked',
            'amount' => 24.99,
            'currency' => 'PLN',
            'description' => 'Dopłata do wymiany.',
            'booked_at' => now(),
        ]);

        InternalNote::query()->create([
            'return_case_id' => $returnCase->id,
            'author_name' => 'BOK',
            'body' => 'Sprawdzić kompletność przed wysyłką wymiany.',
        ]);

        ShippingLabel::query()->create([
            'return_case_id' => $returnCase->id,
            'external_order_id' => $order->id,
            'purpose' => 'exchange',
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'LBL-EX-1',
            'tracking_number' => '520000111111111111111111',
            'disk' => 'local',
            'path' => 'labels/exchange.pdf',
            'generated_at' => now(),
        ]);

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertSee('Otwórz kartę')
            ->assertDontSee('Mail do klienta');

        $this->get(route('returns.show', $returnCase))
            ->assertOk()
            ->assertSee('Karta zwrotu '.$returnCase->number)
            ->assertSee('Produkty w zwrocie')
            ->assertSee('SKU-RET')
            ->assertSee('Rozmiar za mały')
            ->assertSee('Wypłaty i rozliczenia')
            ->assertSee('24,99 PLN')
            ->assertSee('Komunikacja z klientem')
            ->assertSee('Instrukcja wymiany '.$returnCase->number)
            ->assertSee('Wyślij paczkę do magazynu dla '.$order->external_number)
            ->assertDontSee('Instrukcja wymiany {{return_number}}')
            ->assertSee('Notatki wewnętrzne')
            ->assertSee('Sprawdzić kompletność')
            ->assertSee('Etykiety wymiany i zwrotu')
            ->assertSee('LBL-EX-1')
            ->assertSee('Historia zwrotu');
    }

    public function test_mbank_payout_export_contains_cod_returns_in_elixir_record(): void
    {
        app(MbankTransferBasketSettingsService::class)->update([
            'source_account' => '49114020040000330200112177',
            'source_bank_code' => '11402004',
            'source_name' => 'Sempre Test',
            'encoding' => 'UTF-8',
        ]);

        $order = $this->createOrder([
            'payment_method' => 'cod',
            'payment_method_title' => 'Płatność za pobraniem',
        ]);
        $returnCase = $this->createReturnCase($order, [
            'status' => 'corrected',
            'metadata' => [
                'refund_recipient_name' => 'Jan Klient',
                'refund_bank_account' => '11102033520000205312345060',
            ],
        ]);
        $invoice = $this->createCorrectionInvoice($order, -123.45);
        $returnCase->update(['correction_invoice_id' => $invoice->id]);

        $this->get(route('returns.payouts.mbank'))
            ->assertOk()
            ->assertSee('RET/2026/000001')
            ->assertSee('123,45 PLN');

        $response = $this->get(route('returns.payouts.mbank.download'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringStartsWith('110,', $content);
        $this->assertStringContainsString('"49114020040000330200112177"', $content);
        $this->assertStringContainsString('"11102033520000205312345060"', $content);
        $this->assertStringContainsString(',12345,', $content);
        $this->assertStringContainsString('"51"', $content);
    }

    public function test_payu_refund_creates_customer_payment_from_return(): void
    {
        Mail::fake();

        app(PayuRefundSettingsService::class)->update([
            'enabled' => true,
            'auto_refund_enabled' => false,
            'environment' => 'sandbox',
            'client_id' => '300746',
            'client_secret' => 'secret',
            'refund_type' => 'REFUND_PAYMENT_STANDARD',
        ]);

        Http::fake([
            'https://secure.snd.payu.com/pl/standard/user/oauth/authorize' => Http::response([
                'access_token' => 'token-123',
                'token_type' => 'bearer',
            ]),
            'https://secure.snd.payu.com/api/v2_1/orders/PAYU123/refunds' => Http::response([
                'orderId' => 'PAYU123',
                'refund' => [
                    'refundId' => 'REF-1',
                    'amount' => '12345',
                    'status' => 'PENDING',
                ],
                'status' => ['statusCode' => 'SUCCESS'],
            ]),
            'https://secure.snd.payu.com/api/v2_1/orders/PAYU123/refunds/REF-1' => Http::response([
                'refundId' => 'REF-1',
                'extRefundId' => 'ERP-RET-1-1-12345',
                'status' => 'FINALIZED',
            ]),
        ]);

        $order = $this->createOrder([
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'payu_order_id' => 'PAYU123',
        ]);
        $returnCase = $this->createReturnCase($order, ['status' => 'corrected']);
        $invoice = $this->createCorrectionInvoice($order, -123.45);
        $returnCase->update(['correction_invoice_id' => $invoice->id]);

        $this->post(route('returns.payu-refund', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $payment = CustomerPayment::query()->firstOrFail();
        $this->assertSame('payu', $payment->method);
        $this->assertSame('outgoing', $payment->direction);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('REF-1', $payment->reference);
        $this->assertSame('ERP-RET-'.$returnCase->id.'-'.$invoice->id.'-12345', data_get($payment->metadata, 'payu.ext_refund_id'));
        $this->assertDatabaseHas('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'return_payout_queued',
            'status' => 'sent',
            'recipient_email' => 'jan@example.test',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://secure.snd.payu.com/api/v2_1/orders/PAYU123/refunds'
                && data_get($request->data(), 'refund.amount') === '12345';
        });

        $refresh = app(PayuRefundService::class)->refreshPending();

        $this->assertSame(1, $refresh['checked']);
        $this->assertSame(1, $refresh['finalized']);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertDatabaseHas('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'return_refunded',
            'status' => 'sent',
        ]);
    }

    public function test_payu_refund_can_retry_the_same_payment_after_oauth_failure(): void
    {
        Mail::fake();

        app(PayuRefundSettingsService::class)->update([
            'enabled' => true,
            'auto_refund_enabled' => false,
            'environment' => 'sandbox',
            'client_id' => '300746',
            'client_secret' => 'secret',
            'refund_type' => 'REFUND_PAYMENT_STANDARD',
        ]);

        $order = $this->createOrder([
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'payu_order_id' => 'PAYU123',
        ]);
        $returnCase = $this->createReturnCase($order, ['status' => 'corrected']);
        $invoice = $this->createCorrectionInvoice($order, -123.45);
        $returnCase->update(['correction_invoice_id' => $invoice->id]);

        Http::fake([
            'https://secure.snd.payu.com/pl/standard/user/oauth/authorize' => Http::sequence()
                ->push(['error' => 'temporarily_unavailable'], 503)
                ->push(['access_token' => 'token-123']),
            'https://secure.snd.payu.com/api/v2_1/orders/PAYU123/refunds' => Http::response([
                'refund' => [
                    'refundId' => 'REF-RETRY',
                    'status' => 'PENDING',
                ],
                'status' => ['statusCode' => 'SUCCESS'],
            ]),
        ]);

        $this->post(route('returns.payu-refund', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('error');

        $payment = CustomerPayment::query()->firstOrFail();
        $this->assertSame('failed', $payment->status);
        $this->assertStringContainsString('temporarily_unavailable', (string) data_get($payment->metadata, 'payu.error'));

        $this->post(route('returns.payu-refund', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $payment->refresh();
        $this->assertSame(1, CustomerPayment::query()->count());
        $this->assertSame('pending', $payment->status);
        $this->assertSame('REF-RETRY', $payment->reference);
        $this->assertSame(2, data_get($payment->metadata, 'payu.attempts'));
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function createOrder(array $rawPayload = []): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001',
            'external_number' => '9001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 150,
            'billing_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Klient',
                'email' => 'jan@example.test',
                'phone' => '+48111222333',
                'address_1' => 'Prosta 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'shipping_data' => [
                'first_name' => 'Jan',
                'last_name' => 'Klient',
                'address_1' => 'Krzywa 2',
                'postcode' => '30-002',
                'city' => 'Kraków',
                'country' => 'PL',
            ],
            'raw_payload' => $rawPayload,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-RET',
            'name' => 'Sukienka',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 1,
            'unit_gross_price' => 150,
        ]);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createReturnCase(ExternalOrder $order, array $attributes = []): ReturnCase
    {
        $warehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);

        return ReturnCase::query()->create(array_merge([
            'number' => 'RET/2026/000001',
            'external_order_id' => $order->id,
            'target_warehouse_id' => $warehouse->id,
            'status' => 'opened',
            'customer_email' => 'jan@example.test',
            'metadata' => ['source' => 'test'],
        ], $attributes));
    }

    private function createCorrectionInvoice(ExternalOrder $order, float $grossTotal): Invoice
    {
        return Invoice::query()->create([
            'number' => 'FK/1/2026',
            'type' => 'correction',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => ['name' => 'Sempre'],
            'buyer_data' => ['name' => 'Jan Klient'],
            'net_total' => round($grossTotal / 1.23, 2),
            'vat_total' => round($grossTotal - ($grossTotal / 1.23), 2),
            'gross_total' => $grossTotal,
            'payment_method' => 'Zwrot',
            'issued_at' => now(),
        ]);
    }

    private function createInpostAccount(): CourierAccount
    {
        $account = new CourierAccount([
            'provider' => 'inpost',
            'code' => 'glowne',
            'name' => 'Konto główne',
            'organization_id' => '111',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => true,
            'is_active' => true,
        ]);
        $account->setApiToken('token-main');
        $account->save();

        return $account;
    }
}
