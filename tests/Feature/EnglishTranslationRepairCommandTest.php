<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The scheduler-driven repair must heal only the safe class (dead or missing
 * translation reference with English content present) and leave every
 * ambiguous family to the operator, re-checking at most daily.
 */
class EnglishTranslationRepairCommandTest extends TestCase
{
    use RefreshDatabase;

    private SalesChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        WordpressIntegration::query()->create([
            'sales_channel_id' => $this->channel->id,
            'name' => 'Test Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
    }

    private function mappedProduct(string $sku, array $master, array $extraAttributes = []): array
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => 'Produkt '.$sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => array_merge(['master' => array_merge(['source' => 'erp'], $master)], $extraAttributes),
        ]);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $this->channel->id,
            'external_product_id' => (string) (1000 + $product->id),
            'external_sku' => $sku,
            'stock_sync_enabled' => true,
        ]);

        return [$product, $mapping];
    }

    public function test_heals_dead_ref_families_and_skips_every_ambiguous_class(): void
    {
        Bus::fake();

        // Healthy: EN alias already present.
        [$healthy] = $this->mappedProduct('SKU-HEALTHY', ['content' => ['en' => ['name' => 'EN']]]);
        ProductChannelAlias::query()->create([
            'product_id' => $healthy->id,
            'sales_channel_id' => $this->channel->id,
            'external_product_id' => '900',
            'external_sku' => 'SKU-HEALTHY',
            'language' => 'en',
        ]);

        // Deliberately monolingual: no content.en bucket.
        $this->mappedProduct('SKU-MONO', []);

        // Dead snapshot ref: EN post permanently deleted in Woo.
        [$dead, $deadMapping] = $this->mappedProduct(
            'SKU-DEAD',
            ['content' => ['en' => ['name' => 'EN']]],
            ['woocommerce_translations' => ['en' => ['product_id' => '555']]],
        );

        // Live but unlinked EN post: operator decision, never auto-touched.
        [$live, $liveMapping] = $this->mappedProduct(
            'SKU-LIVE',
            ['content' => ['en' => ['name' => 'EN']]],
            ['woocommerce_translations' => ['en' => ['product_id' => '556']]],
        );

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/wp-json/wc/v3/products/555') {
                return Http::response(['code' => 'woocommerce_rest_product_invalid_id', 'data' => ['status' => 404]], 404);
            }

            if ($path === '/wp-json/wc/v3/products/556') {
                return Http::response(['id' => 556], 200);
            }

            return Http::response([], 200);
        });

        $this->artisan('erp:dispatch-english-translation-repair', ['--limit' => 10])
            ->assertExitCode(0);

        // Only the dead-ref family got queued; its snapshot was pruned.
        Bus::assertDispatchedTimes(ExportWooCommerceProductDataJob::class, 1);
        $this->assertSame(
            [],
            (array) data_get($dead->fresh()->attributes, 'woocommerce_translations'),
        );
        $this->assertNotNull(data_get($deadMapping->fresh()->metadata, 'english_translation_repair.checked_at'));

        // Live-ref family: snapshot intact, marker set, no export queued for it.
        $this->assertSame(
            '556',
            (string) data_get($live->fresh()->attributes, 'woocommerce_translations.en.product_id'),
        );
        $this->assertNotNull(data_get($liveMapping->fresh()->metadata, 'english_translation_repair.checked_at'));

        // Second run within the daily window queues nothing new.
        $this->artisan('erp:dispatch-english-translation-repair', ['--limit' => 10])
            ->assertExitCode(0);
        Bus::assertDispatchedTimes(ExportWooCommerceProductDataJob::class, 1);
    }

    public function test_dry_run_reports_candidates_without_http_or_writes(): void
    {
        Bus::fake();
        Http::fake();

        [$dead] = $this->mappedProduct(
            'SKU-DRY',
            ['content' => ['en' => ['name' => 'EN']]],
            ['woocommerce_translations' => ['en' => ['product_id' => '555']]],
        );

        $this->artisan('erp:dispatch-english-translation-repair', ['--dry-run' => true, '--limit' => 10])
            ->assertExitCode(0);

        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertSame(
            '555',
            (string) data_get($dead->fresh()->attributes, 'woocommerce_translations.en.product_id'),
        );
    }

    public function test_limit_bounds_the_batch(): void
    {
        Bus::fake();
        Http::fake(fn () => Http::response(['code' => 'x', 'data' => ['status' => 404]], 404));

        foreach (['SKU-L1', 'SKU-L2', 'SKU-L3'] as $sku) {
            $this->mappedProduct(
                $sku,
                ['content' => ['en' => ['name' => 'EN']]],
                ['woocommerce_translations' => ['en' => ['product_id' => '555']]],
            );
        }

        $this->artisan('erp:dispatch-english-translation-repair', ['--limit' => 2])
            ->assertExitCode(0);

        Bus::assertDispatchedTimes(ExportWooCommerceProductDataJob::class, 2);
    }
}
