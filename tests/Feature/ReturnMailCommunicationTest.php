<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendReturnReceivedMailJob;
use App\Mail\CustomerMessageMail;
use App\Models\CourierAccount;
use App\Models\CustomerMessage;
use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Communication\MailSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ReturnMailCommunicationTest extends TestCase
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

    public function test_return_mail_contains_only_returned_items_and_the_configured_return_address(): void
    {
        [$order, $firstLine, $secondLine] = $this->orderWithTwoLines();
        $returnCase = $this->returnCase($order, 'RET/2026/1');
        $returnCase->lines()->create([
            'external_order_line_id' => $firstLine->id,
            'quantity_expected' => 1,
            'quantity_accepted' => 0,
            'condition' => 'unchecked',
            'disposition' => 'restock',
        ]);
        CourierAccount::query()->create([
            'provider' => 'inpost',
            'code' => 'main',
            'name' => 'InPost główny',
            'api_token_encrypted' => Crypt::encryptString('token'),
            'organization_id' => '12345',
            'is_default' => true,
            'is_active' => true,
            'metadata' => [
                'return' => [
                    'name' => 'Sempre — Magazyn zwrotów',
                    'street' => 'Magazynowa',
                    'building_number' => '12',
                    'post_code' => '60-001',
                    'city' => 'Poznań',
                    'country_code' => 'PL',
                    'phone' => '+48 500 600 700',
                ],
            ],
        ]);

        $message = app(CustomerCommunicationService::class)
            ->sendReturnStatus($returnCase, 'return_waiting_for_package');

        $this->assertNotNull($message);
        $this->assertSame(1, data_get($message->metadata, 'items_count'));
        $this->assertSame('Sukienka Luna', data_get($message->metadata, 'items.0.name'));
        $this->assertSame('Magazynowa 12', data_get($message->metadata, 'return_address.line1'));
        $this->assertSame([], data_get($message->metadata, 'shipping_address'));
        $this->assertNotSame($firstLine->id, $secondLine->id);

        $html = (new CustomerMessageMail($message))->render();
        $this->assertStringContainsString('Adres do odesłania paczki', $html);
        $this->assertStringContainsString('Sempre — Magazyn zwrotów', $html);
        $this->assertStringContainsString('Sukienka Luna', $html);
        $this->assertStringNotContainsString('Buty Nova', $html);
        $this->assertStringNotContainsString('Dostawa</div>', $html);
    }

    public function test_received_mail_is_skipped_when_a_settlement_mail_already_exists(): void
    {
        [$order] = $this->orderWithTwoLines();
        $returnCase = $this->returnCase($order, 'RET/2026/2');
        $payment = CustomerPayment::query()->create([
            'external_order_id' => $order->id,
            'return_case_id' => $returnCase->id,
            'direction' => 'outgoing',
            'method' => 'payu',
            'status' => 'paid',
            'amount' => 129.99,
            'currency' => 'PLN',
            'reference' => 'PAYU-REF-1',
            'booked_at' => now(),
            'paid_at' => now(),
        ]);

        app(CustomerCommunicationService::class)
            ->sendReturnSettlement($returnCase, $payment, 'KOR/2026/1');
        (new SendReturnReceivedMailJob($returnCase->id))
            ->handle(app(CustomerCommunicationService::class));

        $this->assertSame(1, CustomerMessage::query()->where('return_case_id', $returnCase->id)->count());
        $this->assertDatabaseHas('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'return_refunded',
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'return_received_warehouse',
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'return_payout_queued',
        ]);
    }

    public function test_received_mail_is_sent_after_the_grace_period_when_settlement_has_not_started(): void
    {
        [$order] = $this->orderWithTwoLines();
        $returnCase = $this->returnCase($order, 'RET/2026/3');
        $returnCase->update(['status' => 'completed']);

        (new SendReturnReceivedMailJob($returnCase->id))
            ->handle(app(CustomerCommunicationService::class));

        $this->assertDatabaseHas('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'return_received_warehouse',
            'status' => 'sent',
        ]);
    }

    public function test_received_mail_is_not_sent_if_the_return_was_reopened_before_the_delayed_job_runs(): void
    {
        [$order] = $this->orderWithTwoLines();
        $returnCase = $this->returnCase($order, 'RET/2026/4');

        (new SendReturnReceivedMailJob($returnCase->id))
            ->handle(app(CustomerCommunicationService::class));

        $this->assertDatabaseMissing('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'return_received_warehouse',
        ]);
    }

    public function test_processing_or_failed_refund_does_not_announce_that_the_payout_started(): void
    {
        [$order] = $this->orderWithTwoLines();
        $returnCase = $this->returnCase($order, 'RET/2026/5');
        $communication = app(CustomerCommunicationService::class);

        foreach (['processing', 'failed'] as $status) {
            $payment = new CustomerPayment([
                'status' => $status,
                'reference' => 'PAYU-'.$status,
            ]);

            $this->assertNull($communication->sendReturnSettlement($returnCase, $payment, 'KOR/2026/5'));
        }

        $this->assertDatabaseMissing('customer_messages', [
            'return_case_id' => $returnCase->id,
            'trigger' => 'return_payout_queued',
        ]);
    }

    /** @return array{ExternalOrder, mixed, mixed} */
    private function orderWithTwoLines(): array
    {
        $channel = SalesChannel::query()->firstOrCreate([
            'code' => 'woo',
        ], [
            'name' => 'WooCommerce',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => uniqid('order-', true),
            'external_number' => '10001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 299.98,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => 'anna@example.test',
                'address_1' => 'Klientowska 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
            ],
            'shipping_data' => [],
            'raw_payload' => ['payment_method' => 'payu', 'payment_method_title' => 'PayU'],
        ]);
        $first = $order->lines()->create([
            'external_line_id' => 'line-1',
            'sku' => 'LUNA-M',
            'name' => 'Sukienka Luna',
            'quantity' => 1,
            'unit_gross_price' => 129.99,
            'raw_payload' => [],
        ]);
        $second = $order->lines()->create([
            'external_line_id' => 'line-2',
            'sku' => 'NOVA-38',
            'name' => 'Buty Nova',
            'quantity' => 1,
            'unit_gross_price' => 169.99,
            'raw_payload' => [],
        ]);

        return [$order, $first, $second];
    }

    private function returnCase(ExternalOrder $order, string $number): ReturnCase
    {
        return ReturnCase::query()->create([
            'number' => $number,
            'external_order_id' => $order->id,
            'status' => 'opened',
            'customer_email' => 'anna@example.test',
            'metadata' => [],
        ]);
    }
}
