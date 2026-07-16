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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FailedWooCommerceProductExportRecoveryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const RECOVERY_REVISION = 'translated_global_attribute_taxonomy_2026_07_15_000010';

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
        [$channel, $integration] = $this->createWooIntegration(['pl']);
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

    public function test_retryable_failure_predicate_covers_duplicate_invalid_taxonomy_and_old_plugin_messages_idempotently(): void
    {
        [$channel, $integration] = $this->createWooIntegration(['pl']);
        $recovery = app(WooCommerceProductCreationRecoveryService::class);
        $messages = [
            'WooCommerce zawiera kilka wartości SEMPRE globalnego atrybutu #5; eksport został przerwany.',
            'WordPress nie powiązał tłumaczeń wartości globalnego atrybutu: ID nie wskazuje tłumaczonego globalnego atrybutu WooCommerce.',
            'Powiązanie tłumaczeń wartości globalnych atrybutów wymaga wtyczki Lemon ERP for WooCommerce co najmniej 0.5.2.',
            'WooCommerce nie jest gotowy do bezpiecznego utworzenia wersji językowych produktu. Wymagana jest wtyczka Lemon ERP WooCommerce 0.5.2 lub nowsza oraz gotowy bootstrap tłumaczeń globalnych atrybutów.',
        ];

        foreach ($messages as $index => $message) {
            $product = $this->createProduct('RECOVERY-PREDICATE-'.$index);
            $audit = AuditLog::query()->create([
                'action' => 'product.woocommerce_create_failed',
                'auditable_type' => $product->getMorphClass(),
                'auditable_id' => $product->id,
                'metadata' => [
                    'wordpress_integration_id' => $integration->id,
                    'sales_channel_id' => $channel->id,
                    'error' => $message,
                ],
            ]);

            $this->assertTrue($recovery->markPendingForFailure(
                $product,
                $integration,
                $audit,
                $message,
            ));
            $firstState = data_get(
                $product->refresh()->attributes,
                $recovery->metadataPath($integration->id),
            );
            $this->assertTrue($recovery->markPendingForFailure(
                $product,
                $integration,
                $audit,
                $message,
            ));

            $this->assertSame($firstState, data_get(
                $product->refresh()->attributes,
                $recovery->metadataPath($integration->id),
            ));
            $this->assertSame('pending', data_get($firstState, 'status'));
            $this->assertSame($audit->id, data_get($firstState, 'source_audit_log_id'));
        }
    }

    public function test_migration_collects_existing_invalid_taxonomy_and_old_plugin_failures(): void
    {
        [$channel, $integration] = $this->createWooIntegration(['pl']);
        $messages = [
            'WordPress nie powiązał tłumaczeń wartości globalnego atrybutu: ID nie wskazuje tłumaczonego globalnego atrybutu WooCommerce.',
            'Powiązanie tłumaczeń wartości globalnych atrybutów wymaga wtyczki Lemon ERP for WooCommerce co najmniej 0.5.2.',
            'WooCommerce nie jest gotowy do bezpiecznego utworzenia wersji językowych produktu. Wymagana jest wtyczka Lemon ERP WooCommerce 0.5.2 lub nowsza oraz gotowy bootstrap tłumaczeń globalnych atrybutów.',
        ];
        $products = [];

        foreach ($messages as $index => $message) {
            $product = $this->createProduct('RECOVERY-MIGRATION-PREDICATE-'.$index);
            $products[] = $product;
            AuditLog::query()->create([
                'action' => 'product.woocommerce_create_failed',
                'auditable_type' => $product->getMorphClass(),
                'auditable_id' => $product->id,
                'metadata' => [
                    'wordpress_integration_id' => $integration->id,
                    'sales_channel_id' => $channel->id,
                    'error' => $message,
                ],
            ]);
        }

        $this->runRecoveryMigration();
        $this->runRecoveryMigration();
        $path = app(WooCommerceProductCreationRecoveryService::class)
            ->metadataPath($integration->id);

        foreach ($products as $product) {
            $this->assertSame('pending', data_get(
                $product->refresh()->attributes,
                $path.'.status',
            ));
        }
    }

    public function test_manual_bilingual_creation_is_marked_pending_without_mutating_woo_when_plugin_is_unready(): void
    {
        Bus::fake();
        [$channel, $integration] = $this->createWooIntegration();
        $product = $this->createProduct('RECOVERY-DIRECT-UNREADY');
        Http::fake(fn ($request) => str_ends_with(
            $request->url(),
            '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities',
        ) ? Http::response([
            'available' => true,
            'plugin_version' => '0.5.1',
            'languages' => ['pl', 'en'],
            'attribute_term_translation_link_available' => true,
        ]) : Http::response(['unexpected' => $request->url()], 500));

        $this->post(route('products.woocommerce.create', [$product, $integration]))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                'Wymagana jest wtyczka Lemon ERP WooCommerce 0.5.3',
            ));

        $audit = AuditLog::query()
            ->where('action', 'product.woocommerce_create_failed')
            ->where('auditable_id', $product->id)
            ->sole();
        $path = app(WooCommerceProductCreationRecoveryService::class)
            ->metadataPath($integration->id);
        $state = data_get($product->refresh()->attributes, $path);

        $this->assertSame('pending', data_get($state, 'status'));
        $this->assertSame($audit->id, data_get($state, 'source_audit_log_id'));
        $this->assertDatabaseMissing('product_channel_mappings', [
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
        ]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains(
            $request->url(),
            '/wp-json/wc/v3/',
        ));
        Bus::assertNotDispatched(RetryWooCommerceProductCreationJob::class);
    }

    public function test_unready_recovery_survives_a_day_then_dispatches_once_and_completes_after_plugin_upgrade(): void
    {
        Bus::fake();
        [$channel, $integration] = $this->createWooIntegration();
        $product = $this->createProduct('RECOVERY-DURABLE-UPGRADE');
        $recovery = app(WooCommerceProductCreationRecoveryService::class);
        $recovery->markPending($product, $integration, 8181);
        $pluginReady = false;

        Http::fake(function ($request) use (&$pluginReady, $product) {
            $url = $request->url();

            if (str_ends_with(
                $url,
                '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities',
            )) {
                return Http::response([
                    'available' => true,
                    'plugin_version' => $pluginReady ? '0.5.3' : '0.5.1',
                    'languages' => ['pl', 'en'],
                    'attribute_term_translation_link_available' => true,
                ]);
            }

            if ($request->method() === 'POST' && str_ends_with($url, '/wp-json/wc/v3/products')) {
                return Http::response(['id' => 9301, 'sku' => $product->sku], 201);
            }

            if ($request->method() === 'POST'
                && str_contains($url, '/wp-json/wc/v3/products?lang=en')
            ) {
                return Http::response(['id' => 9302, 'sku' => ''], 201);
            }

            if ($request->method() === 'PUT'
                && $url === 'https://recovery-1.test/wp-json/wc/v3/products/9302?lang=en'
            ) {
                return Http::response(['id' => 9302, 'sku' => $product->sku]);
            }

            if ($request->method() === 'POST'
                && str_ends_with($url, '/wp-json/wc-lemon-erp/v1/catalog/products/translations')
            ) {
                return Http::response([
                    'linked' => true,
                    'translations' => ['pl' => 9301, 'en' => 9302],
                    'translation_group' => 'product:9301|9302',
                ]);
            }

            return Http::response(['unexpected' => $url], 500);
        });

        $first = $recovery->dispatchPending();
        $this->travel(25)->hours();
        $afterOneDay = $recovery->dispatchPending();
        $path = $recovery->metadataPath($integration->id);

        $this->assertSame(1, $first['unready']);
        $this->assertSame(1, $afterOneDay['unready']);
        $this->assertSame('pending', data_get($product->refresh()->attributes, $path.'.status'));
        $this->assertSame(0, (int) data_get($product->attributes, $path.'.attempts', 0));
        $this->assertNull(data_get($product->attributes, $path.'.token'));
        Bus::assertNotDispatched(RetryWooCommerceProductCreationJob::class);

        $pluginReady = true;
        $ready = $recovery->dispatchPending();
        $token = (string) data_get($product->refresh()->attributes, $path.'.token');

        $this->assertSame(1, $ready['dispatched']);
        $this->assertNotSame('', $token);
        Bus::assertDispatched(RetryWooCommerceProductCreationJob::class, 1);

        (new RetryWooCommerceProductCreationJob(
            $product->id,
            $integration->id,
            $token,
        ))->handle(app(ProductDataExportService::class), $recovery);

        $this->assertSame('completed', data_get(
            $product->refresh()->attributes,
            $path.'.status',
        ));
        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => '9301',
        ]);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_already_reserved_job_returns_to_pending_when_plugin_becomes_unready(): void
    {
        Bus::fake();
        [, $integration] = $this->createWooIntegration();
        $product = $this->createProduct('RECOVERY-QUEUED-BECOMES-UNREADY');
        $recovery = app(WooCommerceProductCreationRecoveryService::class);
        $pluginReady = true;
        Http::fake(function ($request) use (&$pluginReady) {
            if (str_ends_with(
                $request->url(),
                '/wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities',
            )) {
                return Http::response([
                    'available' => true,
                    'plugin_version' => $pluginReady ? '0.5.3' : '0.5.1',
                    'languages' => ['pl', 'en'],
                    'attribute_term_translation_link_available' => true,
                ]);
            }

            return Http::response([], 500);
        });
        $recovery->markPending($product, $integration, 8282);
        $recovery->dispatchPending();
        $path = $recovery->metadataPath($integration->id);
        $token = (string) data_get($product->refresh()->attributes, $path.'.token');
        $this->assertNotSame('', $token);

        $pluginReady = false;
        (new RetryWooCommerceProductCreationJob(
            $product->id,
            $integration->id,
            $token,
        ))->handle(app(ProductDataExportService::class), $recovery);

        $state = data_get($product->refresh()->attributes, $path);
        $this->assertSame('pending', data_get($state, 'status'));
        $this->assertSame(0, (int) data_get($state, 'attempts', 0));
        $this->assertNull(data_get($state, 'token'));
        $this->assertNull(data_get($state, 'queued_at'));
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_recovery_inspection_reports_state_without_dispatching_or_changing_it(): void
    {
        Bus::fake();
        [$channel, $integration] = $this->createWooIntegration();
        $product = $this->createProduct('RECOVERY-INSPECT');
        $audit = AuditLog::query()->create([
            'action' => 'product.woocommerce_create_failed',
            'auditable_type' => $product->getMorphClass(),
            'auditable_id' => $product->id,
            'metadata' => [
                'wordpress_integration_id' => $integration->id,
                'sales_channel_id' => $channel->id,
                'error' => 'WooCommerce zawiera kilka wartości SEMPRE globalnego atrybutu #90; eksport został przerwany.',
            ],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(WooCommerceProductCreationRecoveryService::class)->markPending(
            $product,
            $integration,
            $audit->id,
        );
        $before = $product->refresh()->attributes;

        $exitCode = Artisan::call('erp:inspect-woocommerce-product-creation-recovery', [
            '--limit' => 5,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('RECOVERY-INSPECT', $output);
        $this->assertStringContainsString('pending', $output);
        $this->assertStringContainsString(WooCommerceProductCreationRecoveryService::REVISION, $output);
        $this->assertSame($before, $product->refresh()->attributes);
        Bus::assertNotDispatched(RetryWooCommerceProductCreationJob::class);
        $this->assertDatabaseCount('jobs', 0);
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

    public function test_pending_recovery_is_completed_without_dispatch_when_mapping_succeeded_later(): void
    {
        Bus::fake();
        [$channel, $integration] = $this->createWooIntegration(['pl']);
        $product = $this->createProduct('RECOVERY-LATER-MAPPING');
        $recovery = app(WooCommerceProductCreationRecoveryService::class);
        $recovery->markPending($product, $integration, 9000);
        $this->createPrimaryMapping($product, $channel, '9401', [
            'creation_state' => 'completed',
        ]);

        $result = $recovery->dispatchPending();
        $path = $recovery->metadataPath($integration->id);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame('completed', data_get(
            $product->refresh()->attributes,
            $path.'.status',
        ));
        $this->assertSame('9401', data_get($product->attributes, $path.'.external_product_id'));
        Bus::assertNotDispatched(RetryWooCommerceProductCreationJob::class);
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
        $job->handle(
            app(ProductDataExportService::class),
            app(WooCommerceProductCreationRecoveryService::class),
        );

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
        $job->handle(
            app(ProductDataExportService::class),
            app(WooCommerceProductCreationRecoveryService::class),
        );

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
            'migrations/2026_07_15_000010_retry_woocommerce_exports_with_translated_attribute_taxonomies.php',
        ))->up();
    }
}
