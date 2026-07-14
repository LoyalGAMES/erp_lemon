<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WooCommerceRefundClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_refunds_for_an_order(): void
    {
        $integration = $this->integration();

        Http::fake([
            'https://refunds.shop.test/wp-json/wc/v3/orders/123/refunds*' => Http::response([
                [
                    'id' => 501,
                    'amount' => '129.90',
                    'reason' => 'Anulowanie zamówienia',
                    'refunded_payment' => true,
                ],
                'invalid-entry',
            ]),
        ]);

        $refunds = app(WooCommerceClient::class)->orderRefunds($integration, 123);

        $this->assertSame([
            [
                'id' => 501,
                'amount' => '129.90',
                'reason' => 'Anulowanie zamówienia',
                'refunded_payment' => true,
            ],
        ], $refunds);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://refunds.shop.test/wp-json/wc/v3/orders/123/refunds?')
            && str_contains($request->url(), 'context=view')
            && str_contains($request->url(), 'per_page=100'));
    }

    public function test_it_creates_a_refund_with_the_exact_payload(): void
    {
        $integration = $this->integration();
        $payload = [
            'amount' => '129.90',
            'reason' => '[ERP-CANCEL:operation-1] Anulowanie zamówienia',
            'api_refund' => true,
            'api_restock' => false,
        ];

        Http::fake([
            'https://refunds.shop.test/wp-json/wc/v3/orders/123/refunds' => Http::response([
                'id' => 502,
                'amount' => '129.90',
                'refunded_payment' => true,
            ], 201),
        ]);

        $refund = app(WooCommerceClient::class)->createOrderRefund($integration, '123', $payload);

        $this->assertSame(502, $refund['id']);
        $this->assertTrue($refund['refunded_payment']);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://refunds.shop.test/wp-json/wc/v3/orders/123/refunds'
            && $request->data() === $payload);
    }

    public function test_listing_failure_includes_the_woo_error_after_safe_read_retries(): void
    {
        $integration = $this->integration();

        Http::fake(Http::response([
            'code' => 'woocommerce_rest_cannot_view',
            'message' => 'Nie masz uprawnień do przeglądania zwrotów.',
            'data' => ['status' => 403],
        ], 403));

        try {
            app(WooCommerceClient::class)->orderRefunds($integration, 123);
            $this->fail('Oczekiwano błędu odczytu zwrotów WooCommerce.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Pobranie zwrotów zamówienia z WooCommerce nie powiodło się '
                .'(HTTP 403, woocommerce_rest_cannot_view): '
                .'Nie masz uprawnień do przeglądania zwrotów.',
                $exception->getMessage(),
            );
        }
    }

    public function test_refund_failure_includes_the_woo_message_and_is_not_retried(): void
    {
        $integration = $this->integration();

        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'code' => 'woocommerce_rest_cannot_create_order_refund',
                    'message' => 'Ta bramka płatności nie obsługuje automatycznych zwrotów.',
                    'data' => ['status' => 500],
                ], 500)
                ->push([
                    'id' => 999,
                    'refunded_payment' => true,
                ], 201),
        ]);

        try {
            app(WooCommerceClient::class)->createOrderRefund($integration, 123, [
                'amount' => '129.90',
                'api_refund' => true,
            ]);
            $this->fail('Oczekiwano błędu zwrotu WooCommerce.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Utworzenie zwrotu płatności w WooCommerce nie powiodło się '
                .'(HTTP 500, woocommerce_rest_cannot_create_order_refund): '
                .'Ta bramka płatności nie obsługuje automatycznych zwrotów.',
                $exception->getMessage(),
            );
        }

        Http::assertSentCount(1);
    }

    private function integration(): WordpressIntegration
    {
        $channel = SalesChannel::query()->create([
            'code' => 'REFUNDS',
            'name' => 'Sklep zwroty',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo zwroty',
            'base_url' => 'https://refunds.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_refunds'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_refunds'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
    }
}
