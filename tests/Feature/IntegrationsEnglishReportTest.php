<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * The Integracje page must answer "why is the EN catalog smaller?" with the
 * repair scheduler's persisted classification: counts per class and the exact
 * SKUs waiting for a human decision.
 */
class IntegrationsEnglishReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_breaks_down_missing_english_translations(): void
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
        ]);

        $make = function (string $sku, array $master, array $repair = []) use ($channel): void {
            $product = Product::query()->create([
                'sku' => $sku,
                'name' => 'Produkt '.$sku,
                'unit' => 'szt',
                'vat_rate' => 23,
                'quantity_precision' => 0,
                'is_active' => true,
                'attributes' => ['master' => array_merge(['source' => 'erp'], $master)],
            ]);
            ProductChannelMapping::query()->create([
                'product_id' => $product->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => (string) (1000 + $product->id),
                'external_sku' => $sku,
                'stock_sync_enabled' => true,
                'metadata' => $repair === [] ? [] : ['english_translation_repair' => $repair],
            ]);

            if ($sku === 'SKU-OK') {
                ProductChannelAlias::query()->create([
                    'product_id' => $product->id,
                    'sales_channel_id' => $channel->id,
                    'external_product_id' => '900',
                    'external_sku' => $sku,
                    'language' => 'en',
                ]);
            }
        };

        $en = ['content' => ['en' => ['name' => 'EN']]];
        $make('SKU-OK', $en);
        $make('SKU-MONO', []);
        $make('SKU-LIVEREF', $en, ['status' => 'live_ref_manual', 'live_ref' => '556', 'checked_at' => now()->toISOString()]);
        $make('SKU-QUEUED', $en, ['status' => 'queued', 'checked_at' => now()->toISOString()]);
        $make('SKU-3FAILS', $en, ['status' => 'queued', 'checked_at' => now()->toISOString(), 'failure_count' => 3, 'last_error' => 'atrybut SEMPRE zduplikowany']);
        $make('SKU-FRESH', $en);

        $page = $this->get(route('integrations.index'));

        $page->assertOk();
        $page->assertSee('Tłumaczenia EN');
        // 6 rodzin, 1 zdrowa → 5 bez tłumaczenia (w tym 1 jednojęzyczna).
        $page->assertSee('1 / 6 rodzin');
        $page->assertSee('SKU-LIVEREF');
        $page->assertSee('post EN #556');
        $page->assertSee('SKU-3FAILS');
        $page->assertSee('atrybut SEMPRE zduplikowany');
        $page->assertDontSee('SKU-OK—');
    }
}
