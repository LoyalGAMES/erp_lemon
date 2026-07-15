<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RetryWooCommerceProductCreationJob;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooCommerceProductCreationRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FailedWooCommerceProductExportRecoveryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const RECOVERY_REVISION = 'global_attribute_term_recovery_2026_07_15_000009';

    public function test_migration_re_marks_pending_creating_and_latest_failed_mapped_products(): void
    {
        [$channel] = $this->createWooIntegration();

        $pending = $this->createProduct('RECOVERY-PENDING');
        $pendingMapping = $this->createPrimaryMapping($pending, $channel, '8101', [
            'product_data_export' => [
                'legacy_variant_backfill' => [
                    'status' => 'pending',
                    'reason' => LegacyVariantFamilyBackfillService::REASON,
                    'revision' => LegacyVariantFamilyBackfillService::MISSING_PRODUCT_TRANSLATIONS_REVISION,
                    'requested_at' => now()->subHour()->toISOString(),
                    'next_attempt_at' => now()->addMinutes(15)->toISOString(),
                ],
            ],
        ]);

        $creating = $this->createProduct('RECOVERY-CREATING');
        $creatingMapping = $this->createPrimaryMapping($creating, $channel, '8102', [
            'creation_state' => 'creating',
        ]);

        $failed = $this->createProduct('RECOVERY-FAILED');
        $failedMapping = $this->createPrimaryMapping($failed, $channel, '8103', [
            'product_data_export' => [
                'completed_at' => now()->subHour()->toISOString(),
                'failed_at' => now()->toISOString(),
                'error' => 'WooCommerce zawiera kilka wartości SEMPRE globalnego atrybutu #90.',
            ],
        ]);

        $this->runRecoveryMigration();

        foreach ([$pendingMapping, $creatingMapping, $failedMapping] as $mapping) {
            $metadata = $mapping->refresh()->metadata;

            $this->assertSame('pending', data_get(
                $metadata,
                'product_data_export.legacy_variant_backfill.status',
            ));
            $this->assertSame(LegacyVariantFamilyBackfillService::REASON, data_get(
                $metadata,
                'product_data_export.legacy_variant_backfill.reason',
            ));
            $this->assertSame(self::RECOVERY_REVISION, data_get(
                $metadata,
                'product_data_export.legacy_variant_backfill.revision',
            ));
            $this->assertNull(data_get(
                $metadata,
                'product_data_export.legacy_variant_backfill.next_attempt_at',
            ));
        }
    }

    public function test_migration_skips_a_healthy_mapping_with_a_newer_success(): void
    {
        [$channel] = $this->createWooIntegration();
        $product = $this->createProduct('RECOVERY-HEALTHY');
        $mapping = $this->createPrimaryMapping($product, $channel, '8201', [
            'creation_state' => 'completed',
            'last_product_export_status' => 'success',
            'product_data_export' => [
                'failed_at' => now()->subHour()->toISOString(),
                'error' => 'Dawny błąd, po którym eksport już się udał.',
                'completed_at' => now()->toISOString(),
            ],
        ]);

        $this->runRecoveryMigration();

        $this->assertNull(data_get(
            $mapping->refresh()->metadata,
            'product_data_export.legacy_variant_backfill',
        ));
    }

    public function test_migration_queues_one_retry_for_a_fresh_matching_unmapped_product_creation_failure(): void
    {
        Bus::fake();
        [$channel, $integration] = $this->createWooIntegration();
        $product = $this->createProduct('RECOVERY-UNMAPPED-CREATE');

        $audit = AuditLog::query()->create([
            'action' => 'product.woocommerce_create_failed',
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'metadata' => [
                'wordpress_integration_id' => $integration->id,
                'sales_channel_id' => $channel->id,
                'error' => 'WooCommerce zawiera kilka wartości SEMPRE globalnego atrybutu #90 (702, 703); eksport został przerwany.',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseMissing('product_channel_mappings', [
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
        ]);

        $this->runRecoveryMigration();
        $this->runRecoveryMigration();

        Bus::assertNotDispatched(RetryWooCommerceProductCreationJob::class);
        $path = app(WooCommerceProductCreationRecoveryService::class)
            ->metadataPath($integration->id);
        $this->assertSame('pending', data_get($product->refresh()->attributes, $path.'.status'));
        $this->assertSame($channel->id, data_get($product->attributes, $path.'.sales_channel_id'));

        $firstDispatch = app(WooCommerceProductCreationRecoveryService::class)
            ->dispatchPending(10, 120);
        $secondDispatch = app(WooCommerceProductCreationRecoveryService::class)
            ->dispatchPending(10, 120);

        $this->assertSame(1, $firstDispatch['dispatched']);
        $this->assertSame(1, $secondDispatch['active']);
        Bus::assertDispatched(RetryWooCommerceProductCreationJob::class, 1);
        Bus::assertDispatched(
            RetryWooCommerceProductCreationJob::class,
            fn (RetryWooCommerceProductCreationJob $job): bool => $job->productId === $product->id
                && $job->integrationId === $integration->id
                && $job->recoveryToken !== '',
        );
        $this->assertNotNull($audit->fresh());
        $this->assertDatabaseMissing('product_channel_mappings', [
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
        ]);
    }

    public function test_migration_ignores_a_failure_whose_audit_channel_does_not_match_the_integration(): void
    {
        Bus::fake();
        [$channel, $integration] = $this->createWooIntegration();
        $product = $this->createProduct('RECOVERY-CHANNEL-MISMATCH');

        AuditLog::query()->create([
            'action' => 'product.woocommerce_create_failed',
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'metadata' => [
                'wordpress_integration_id' => $integration->id,
                'sales_channel_id' => $channel->id + 1000,
                'error' => 'WooCommerce zawiera kilka wartości SEMPRE globalnego atrybutu #90; eksport został przerwany.',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runRecoveryMigration();
        app(WooCommerceProductCreationRecoveryService::class)->dispatchPending();

        $path = app(WooCommerceProductCreationRecoveryService::class)
            ->metadataPath($integration->id);
        $this->assertNull(data_get($product->refresh()->attributes, $path));
        Bus::assertNotDispatched(RetryWooCommerceProductCreationJob::class);
    }

    public function test_migration_ignores_a_failure_followed_by_a_successful_creation(): void
    {
        [$channel, $integration] = $this->createWooIntegration();
        $product = $this->createProduct('RECOVERY-LATER-SUCCESS');

        $failed = AuditLog::query()->create([
            'action' => 'product.woocommerce_create_failed',
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'metadata' => [
                'wordpress_integration_id' => $integration->id,
                'sales_channel_id' => $channel->id,
                'error' => 'WooCommerce zawiera kilka wartości SEMPRE globalnego atrybutu #90; eksport został przerwany.',
            ],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
        AuditLog::query()->create([
            'action' => 'product.woocommerce_created',
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'metadata' => [
                'wordpress_integration_id' => $integration->id,
                'sales_channel_id' => $channel->id,
                'external_product_id' => '9001',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotNull($failed->fresh());
        $this->runRecoveryMigration();

        $path = app(WooCommerceProductCreationRecoveryService::class)
            ->metadataPath($integration->id);
        $this->assertNull(data_get($product->refresh()->attributes, $path));
    }

    public function test_reserved_recovery_job_creates_the_product_and_completes_its_state(): void
    {
        Bus::fake();
        [$channel, $integration] = $this->createWooIntegration(['pl']);
        $product = $this->createProduct('RECOVERY-JOB-CREATE');
        $recovery = app(WooCommerceProductCreationRecoveryService::class);
        $recovery->markPending($product, $integration, 1234);
        $recovery->dispatchPending();

        $path = $recovery->metadataPath($integration->id);
        $token = (string) data_get($product->refresh()->attributes, $path.'.token');
        $this->assertNotSame('', $token);

        Http::fake([
            $integration->base_url.'/wp-json/wc/v3/products' => Http::response([
                'id' => 9301,
                'sku' => $product->sku,
                'name' => $product->name,
            ]),
        ]);

        $job = new RetryWooCommerceProductCreationJob(
            $product->id,
            $integration->id,
            $token,
        );
        $job->handle(app(ProductDataExportService::class));

        $mapping = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->where('sales_channel_id', $channel->id)
            ->firstOrFail();
        $state = data_get($product->refresh()->attributes, $path);

        $this->assertSame('9301', $mapping->external_product_id);
        $this->assertSame('completed', data_get($mapping->metadata, 'creation_state'));
        $this->assertSame('completed', data_get($state, 'status'));
        $this->assertSame('9301', data_get($state, 'external_product_id'));
        $this->assertNull(data_get($state, 'token'));
    }

    public function test_reserved_recovery_job_skips_a_product_that_became_a_variation(): void
    {
        Bus::fake();
        [$channel, $integration] = $this->createWooIntegration(['pl']);
        $product = $this->createProduct('RECOVERY-JOB-VARIATION');
        $recovery = app(WooCommerceProductCreationRecoveryService::class);
        $recovery->markPending($product, $integration, 1235);
        $recovery->dispatchPending();

        $path = $recovery->metadataPath($integration->id);
        $token = (string) data_get($product->refresh()->attributes, $path.'.token');
        $attributes = (array) $product->attributes;
        data_set($attributes, 'master.product_type', 'variation');
        $product->forceFill(['attributes' => $attributes])->save();
        Http::preventStrayRequests();

        $job = new RetryWooCommerceProductCreationJob(
            $product->id,
            $integration->id,
            $token,
        );
        $job->handle(app(ProductDataExportService::class));

        $this->assertSame('skipped', data_get($product->refresh()->attributes, $path.'.status'));
        $this->assertDatabaseMissing('product_channel_mappings', [
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
        ]);
    }

    /**
     * @return array{SalesChannel, WordpressIntegration}
     */
    private function createWooIntegration(array $languages = ['pl', 'en']): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C-FAILED-EXPORT-RECOVERY-'.str()->lower(str()->random(8)),
            'name' => 'Sklep B2C recovery',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo recovery '.$channel->id,
            'base_url' => 'https://recovery-'.$channel->id.'.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => $languages]],
        ]);

        return [$channel, $integration];
    }

    private function createProduct(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Produkt '.$sku,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'is_translation' => false,
            'attributes' => ['master' => [
                'source' => 'erp',
                'product_type' => 'simple',
                'content' => [
                    'pl' => ['name' => 'Produkt '.$sku],
                    'en' => ['name' => 'Product '.$sku],
                ],
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function createPrimaryMapping(
        Product $product,
        SalesChannel $channel,
        string $externalProductId,
        array $metadata,
    ): ProductChannelMapping {
        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => $externalProductId,
            'external_variation_id' => null,
            'external_sku' => $product->sku,
            'stock_sync_enabled' => true,
            'metadata' => array_replace_recursive([
                'mapping_role' => 'primary',
                'language' => 'pl',
            ], $metadata),
        ]);
    }

    private function runRecoveryMigration(): void
    {
        (require database_path(
            'migrations/2026_07_15_000009_retry_global_attribute_term_recovery_with_language_slugs.php',
        ))->up();
    }
}
