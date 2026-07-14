<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WooCommerceOrderStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancelled_update_requires_woo_response_to_confirm_cancelled_status(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'STATUS-CANCEL',
            'name' => 'Status cancellation test',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo status test',
            'base_url' => 'https://status.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_status'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_status'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => '501',
            'external_number' => '501',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 100,
            'raw_payload' => ['status' => 'processing'],
        ]);

        Http::fake([
            'https://status.shop.test/wp-json/wc/v3/orders/501' => Http::response([
                'id' => 501,
                'status' => 'processing',
            ], 200),
        ]);

        try {
            app(WooCommerceOrderStatusService::class)->updateManually($order, 'cancelled');
            $this->fail('Odpowiedź WooCommerce bez statusu cancelled powinna zatrzymać anulowanie.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nie potwierdził anulowania', $exception->getMessage());
            $this->assertStringContainsString('processing', $exception->getMessage());
        }

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('processing', data_get($order->fresh()->raw_payload, 'status'));
        $this->assertDatabaseHas('integration_sync_logs', [
            'wordpress_integration_id' => $integration->id,
            'external_id' => '501',
            'operation' => 'order_status_manual_update',
            'status' => 'failed',
        ]);
    }
}
