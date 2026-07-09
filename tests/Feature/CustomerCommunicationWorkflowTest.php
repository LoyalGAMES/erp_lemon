<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Communication\CustomerEmailWorkflowSettingsService;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CustomerCommunicationWorkflowTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_disabled_woocommerce_status_workflow_skips_indirect_store_email_trigger(): void
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

        $this->assertTrue((bool) ($result['skipped'] ?? false));
        $this->assertNull($result['status']);
        $this->assertSame('ready-to-ship', $result['target_status']);
        Http::assertNothingSent();

        $log = IntegrationSyncLog::query()->where('operation', 'order_ready_for_shipment')->firstOrFail();
        $this->assertSame('skipped', $log->status);
        $this->assertTrue((bool) data_get($log->response_payload, 'workflow_disabled'));
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
}
