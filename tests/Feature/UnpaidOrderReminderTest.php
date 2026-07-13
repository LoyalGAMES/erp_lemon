<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\CustomerMessage;
use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Communication\MailSettingsService;
use App\Services\Communication\UnpaidOrderReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class UnpaidOrderReminderTest extends TestCase
{
    use RefreshDatabase;

    private SalesChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-13 10:00:00');
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
        $this->channel = SalesChannel::query()->create([
            'code' => 'woo',
            'name' => 'WooCommerce',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        app(UnpaidOrderReminderService::class)->dispatchDue();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_online_reminder_is_delayed_while_cod_and_paid_orders_are_skipped(): void
    {
        $online = $this->order('online', 'payu', 'PayU');
        $cod = $this->order('cod', 'cod', 'Płatność za pobraniem');
        $paid = $this->order('paid', 'payu', 'PayU');
        CustomerPayment::query()->create([
            'external_order_id' => $paid->id,
            'direction' => 'incoming',
            'method' => 'bank_transfer',
            'status' => 'booked',
            'amount' => 129.99,
            'currency' => 'PLN',
            'booked_at' => now(),
        ]);

        $early = app(UnpaidOrderReminderService::class)->dispatchDue();
        $this->assertSame(0, $early['created']);

        $this->travel(31)->minutes();
        $due = app(UnpaidOrderReminderService::class)->dispatchDue();

        $this->assertSame(1, $due['sent']);
        $this->assertDatabaseHas('customer_messages', [
            'external_order_id' => $online->id,
            'trigger' => 'order_on_hold',
            'status' => 'sent',
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'external_order_id' => $cod->id,
            'trigger' => 'order_on_hold',
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'external_order_id' => $paid->id,
            'trigger' => 'order_on_hold',
        ]);
        Mail::assertSent(CustomerMessageMail::class, 1);
    }

    public function test_bank_transfer_uses_the_longer_delay_and_is_sent_only_once(): void
    {
        $bank = $this->order('bank', 'bacs', 'Przelew tradycyjny');

        $this->travel(31)->minutes();
        $this->assertSame(0, app(UnpaidOrderReminderService::class)->dispatchDue()['created']);

        $this->travel(24)->hours();
        $this->assertSame(1, app(UnpaidOrderReminderService::class)->dispatchDue()['sent']);
        $this->assertSame(0, app(UnpaidOrderReminderService::class)->dispatchDue()['created']);

        $message = CustomerMessage::query()
            ->where('external_order_id', $bank->id)
            ->where('trigger', 'order_on_hold')
            ->firstOrFail();
        $this->assertSame('bank_transfer', data_get($message->metadata, 'payment_method_type'));
        $this->assertNull(data_get($message->metadata, 'action_url'));
    }

    public function test_payment_failed_mail_is_not_emitted_for_cod_or_bank_transfer(): void
    {
        $communication = app(CustomerCommunicationService::class);
        $cod = $this->order('cod-failed', 'cod', 'Za pobraniem', 'failed');
        $bank = $this->order('bank-failed', 'bacs', 'Przelew tradycyjny', 'failed');
        $online = $this->order('online-failed', 'payu', 'PayU', 'failed');

        $this->assertNull($communication->sendOrderStatus($cod, 'order_payment_failed'));
        $this->assertNull($communication->sendOrderStatus($bank, 'order_payment_failed'));
        $this->assertNotNull($communication->sendOrderStatus($online, 'order_payment_failed'));
        $this->assertSame(1, CustomerMessage::query()->where('trigger', 'order_payment_failed')->count());
    }

    public function test_manually_booked_payment_mail_uses_the_amount_that_was_just_booked(): void
    {
        $order = $this->order('manual-payment', 'bacs', 'Przelew tradycyjny', 'processing');

        $message = app(CustomerCommunicationService::class)->sendOrderStatus($order, 'order_payment_received', [
            'amount' => '25,00',
            'currency' => 'PLN',
            'payment_reference' => 'BANK-25',
        ]);

        $this->assertNotNull($message);
        $this->assertSame('25,00', data_get($message->metadata, 'amount'));
        $this->assertStringContainsString('25,00 PLN', $message->body);
    }

    public function test_only_incoming_payments_in_the_order_currency_mark_it_as_paid(): void
    {
        $order = $this->order('multi-currency', 'payu', 'PayU');
        CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'incoming',
            'method' => 'bank_transfer',
            'status' => 'booked',
            'amount' => 129.99,
            'currency' => 'EUR',
            'booked_at' => now(),
        ]);

        $service = app(UnpaidOrderReminderService::class);

        $this->assertFalse($service->isPaid($order));

        CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'direction' => 'incoming',
            'method' => 'bank_transfer',
            'status' => 'booked',
            'amount' => 129.99,
            'currency' => 'PLN',
            'booked_at' => now(),
        ]);

        $this->assertTrue($service->isPaid($order));
    }

    public function test_historical_woocommerce_order_imported_after_activation_is_not_reminded(): void
    {
        $order = $this->order('historical-import', 'payu', 'PayU');
        $order->update(['external_created_at' => now()->subDays(30)]);

        $this->travel(31)->minutes();

        $this->assertSame(0, app(UnpaidOrderReminderService::class)->dispatchDue()['created']);
        $this->assertDatabaseMissing('customer_messages', [
            'external_order_id' => $order->id,
            'trigger' => 'order_on_hold',
        ]);
    }

    private function order(
        string $externalId,
        string $method,
        string $title,
        string $status = 'pending',
    ): ExternalOrder {
        return ExternalOrder::query()->create([
            'sales_channel_id' => $this->channel->id,
            'external_id' => $externalId,
            'external_number' => $externalId,
            'status' => $status,
            'currency' => 'PLN',
            'total_gross' => 129.99,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => $externalId.'@example.test',
            ],
            'shipping_data' => [],
            'raw_payload' => [
                'payment_method' => $method,
                'payment_method_title' => $title,
            ],
        ]);
    }
}
