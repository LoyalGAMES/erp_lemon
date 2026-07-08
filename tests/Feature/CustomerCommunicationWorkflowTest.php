<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\CustomerMessageMail;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Services\Communication\CustomerCommunicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
