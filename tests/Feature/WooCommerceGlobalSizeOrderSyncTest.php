<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ImportWooCommerceProductsJob;
use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use App\Models\IntegrationSyncLog;
use App\Models\ProductParameterDefinition;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Observers\ProductParameterDefinitionObserver;
use App\Services\WooCommerce\WooCommerceGlobalSizeOrderSyncService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class WooCommerceGlobalSizeOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_canonicalizes_and_orders_existing_polish_terms_when_language_filters_return_every_language(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-SYNC');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $englishBefore = collect($terms)->only([110008, 110014])->all();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        $service = app(WooCommerceGlobalSizeOrderSyncService::class);
        $first = $service->sync($integration);

        $this->assertSame([
            'status' => 'synchronized',
            'attribute_id' => 1,
            'languages' => 2,
            'matched_terms' => 4,
            'updated_terms' => 2,
            'renamed_terms' => 2,
        ], $first);
        $this->assertSame('menu_order', $attribute['order_by']);
        $this->assertSame('S/M', $terms[58]['name']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame('M/L', $terms[57]['name']);
        $this->assertSame(20, $terms[57]['menu_order']);
        $this->assertSame($englishBefore, collect($terms)->only([110008, 110014])->all());
        $this->assertSame([
            [
                'method' => 'PUT',
                'path' => '/wp-json/wc/v3/products/attributes/1/terms/58',
                'payload' => ['name' => 'S/M', 'menu_order' => 10],
            ],
            [
                'method' => 'PUT',
                'path' => '/wp-json/wc/v3/products/attributes/1/terms/57',
                'payload' => ['name' => 'M/L', 'menu_order' => 20],
            ],
            [
                'method' => 'PUT',
                'path' => '/wp-json/wc/v3/products/attributes/1',
                'payload' => ['order_by' => 'menu_order'],
            ],
        ], $mutations);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            (string) parse_url($request->url(), PHP_URL_PATH),
            '/products/variations',
        ));

        $requestCount = Http::recorded()->count();
        $second = $service->sync($integration);

        $this->assertSame([
            'status' => 'synchronized',
            'attribute_id' => 1,
            'languages' => 2,
            'matched_terms' => 4,
            'updated_terms' => 0,
            'renamed_terms' => 0,
        ], $second);
        $this->assertCount(3, $mutations);
        $this->assertSame($requestCount, Http::recorded()->count());
    }

    public function test_an_ambiguous_existing_term_aborts_before_the_first_mutation(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-AMBIGUOUS');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $terms[59] = [
            'id' => 59,
            'name' => 'S/M',
            'slug' => 's-m-duplicate',
            'menu_order' => 30,
            'lang' => 'pl',
        ];
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Niejednoznaczna wartość S/M powinna przerwać synchronizację.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('kilka wartości S/M języka PL', $exception->getMessage());
        }

        $this->assertSame('name', $attribute['order_by']);
        $this->assertSame([], $mutations);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request): bool => in_array(
            $request->method(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true,
        ));
    }

    public function test_english_terms_without_any_unambiguous_polish_source_abort_before_the_first_mutation(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-MISSING-PL');
        $attribute = $this->sizeAttribute();
        $terms = collect($this->allLanguageTerms())
            ->only([110008, 110014])
            ->all();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Brak jednoznacznych polskich terminów powinien przerwać synchronizację.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('dla języka: PL', $exception->getMessage());
        }

        $this->assertSame('name', $attribute['order_by']);
        $this->assertSame([], $mutations);
        Http::assertNotSent(fn (Request $request): bool => in_array(
            $request->method(),
            ['POST', 'PUT', 'PATCH', 'DELETE'],
            true,
        ));
    }

    public function test_a_persistent_second_term_failure_never_exposes_partial_order_through_the_taxonomy(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-SECOND-PUT-FAILURE');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations, failingTermId: 57);

        try {
            app(WooCommerceGlobalSizeOrderSyncService::class)->sync($integration);
            $this->fail('Trwałe HTTP 500 drugiego terminu powinno przerwać synchronizację.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('status code 500', $exception->getMessage());
        }

        $this->assertSame('S/M', $terms[58]['name']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame('m-l', $terms[57]['name']);
        $this->assertSame(0, $terms[57]['menu_order']);
        $this->assertSame('name', $attribute['order_by']);
        $this->assertTrue(collect($mutations)->contains(
            fn (array $mutation): bool => $mutation['path']
                === '/wp-json/wc/v3/products/attributes/1/terms/58',
        ));
        $this->assertTrue(collect($mutations)->contains(
            fn (array $mutation): bool => $mutation['path']
                === '/wp-json/wc/v3/products/attributes/1/terms/57',
        ));
        $this->assertFalse(collect($mutations)->contains(
            fn (array $mutation): bool => $mutation['path']
                === '/wp-json/wc/v3/products/attributes/1',
        ));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT'
            && (string) parse_url($request->url(), PHP_URL_PATH)
                === '/wp-json/wc/v3/products/attributes/1');
    }

    public function test_the_job_uses_the_catalog_lock_and_records_a_successful_existing_term_only_sync(): void
    {
        $this->createSizeDefinition();
        $integration = $this->createWooIntegration('GLOBAL-SIZE-JOB');
        $attribute = $this->sizeAttribute(['order_by' => 'menu_order']);
        $terms = $this->allLanguageTerms(canonicalPolish: true);
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);
        $job = new SyncWooCommerceGlobalSizeOrderJob(
            (int) $integration->id,
            'feature_test',
            'dictionary-fingerprint',
        );

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertSame(
            ImportWooCommerceProductsJob::catalogLockKey((int) $integration->id),
            $middleware[0]->key,
        );
        $this->assertTrue($middleware[0]->shareKey);
        $this->assertSame(60, $middleware[0]->releaseAfter);
        $this->assertSame(
            ImportWooCommerceProductsJob::CATALOG_LOCK_SECONDS,
            $middleware[0]->expiresAfter,
        );
        $this->assertSame(
            "woocommerce-global-size-order:{$integration->id}:dictionary-fingerprint",
            $job->uniqueId(),
        );

        $job->handle(app(WooCommerceGlobalSizeOrderSyncService::class));

        $this->assertSame([], $mutations);
        $log = IntegrationSyncLog::query()
            ->where('operation', 'sync_woocommerce_global_size_order')
            ->sole();
        $this->assertSame('success', $log->status);
        $this->assertSame('1', $log->external_id);
        $this->assertSame([
            'trigger' => 'feature_test',
            'existing_terms_only' => true,
        ], $log->request_payload);
        $this->assertSame('synchronized', data_get($log->response_payload, 'status'));
        $this->assertSame(4, data_get($log->response_payload, 'matched_terms'));
        $this->assertSame(0, data_get($log->response_payload, 'updated_terms'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_the_migration_queues_only_active_woocommerce_integrations_after_commit(): void
    {
        $this->createSizeDefinition();
        $active = $this->createWooIntegration('GLOBAL-SIZE-MIGRATION-ACTIVE');
        $this->createWooIntegration('GLOBAL-SIZE-MIGRATION-INACTIVE', active: false);
        $this->createWooIntegration('GLOBAL-SIZE-MIGRATION-MARKETPLACE', type: 'marketplace');
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);

        (require database_path(
            'migrations/2026_07_15_000021_sync_existing_woo_size_term_order.php',
        ))->up();

        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
        Bus::assertDispatched(
            SyncWooCommerceGlobalSizeOrderJob::class,
            function (SyncWooCommerceGlobalSizeOrderJob $job) use ($active): bool {
                $this->assertSame((int) $active->id, $job->integrationId);
                $this->assertSame(
                    'historical_size_term_order_2026_07_15_000021',
                    $job->trigger,
                );
                $this->assertSame('database', $job->connection);
                $this->assertSame(SyncWooCommerceGlobalSizeOrderJob::QUEUE, $job->queue);
                $this->assertTrue($job->afterCommit);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $job->dictionaryFingerprint);

                return true;
            },
        );
    }

    public function test_the_followup_migration_promotes_only_the_exact_unreserved_size_order_job(): void
    {
        $this->createSizeDefinition();
        $active = $this->createWooIntegration('GLOBAL-SIZE-QUEUE-PROMOTION');
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);
        $timestamp = now()->timestamp;
        $delayedUntil = $timestamp + 3600;
        $waitingId = DB::table('jobs')->insertGetId([
            'queue' => 'woocommerce-critical',
            'payload' => json_encode([
                'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $delayedUntil,
            'created_at' => $timestamp,
        ]);
        $reservedId = DB::table('jobs')->insertGetId([
            'queue' => 'woocommerce-critical',
            'payload' => json_encode([
                'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'reserved_at' => $timestamp,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
        ]);
        $unrelatedId = DB::table('jobs')->insertGetId([
            'queue' => 'woocommerce-critical',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ExportWooCommerceProductDataJob',
                'command' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $timestamp,
            'created_at' => $timestamp,
        ]);

        (require database_path(
            'migrations/2026_07_16_000022_promote_woo_size_order_sync_queue.php',
        ))->up();

        $this->assertSame(
            SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            DB::table('jobs')->where('id', $waitingId)->value('queue'),
        );
        $this->assertLessThanOrEqual(
            now()->timestamp,
            DB::table('jobs')->where('id', $waitingId)->value('available_at'),
        );
        $this->assertSame(
            'woocommerce-critical',
            DB::table('jobs')->where('id', $reservedId)->value('queue'),
        );
        $this->assertSame(
            'woocommerce-critical',
            DB::table('jobs')->where('id', $unrelatedId)->value('queue'),
        );
        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
        Bus::assertDispatched(
            SyncWooCommerceGlobalSizeOrderJob::class,
            function (SyncWooCommerceGlobalSizeOrderJob $job) use ($active): bool {
                $this->assertSame((int) $active->id, $job->integrationId);
                $this->assertSame(
                    'dedicated_size_order_queue_2026_07_16_000022',
                    $job->trigger,
                );
                $this->assertSame('database', $job->connection);
                $this->assertSame(SyncWooCommerceGlobalSizeOrderJob::QUEUE, $job->queue);
                $this->assertTrue($job->afterCommit);

                return true;
            },
        );
    }

    public function test_the_deploy_postcondition_requires_a_fresh_success_for_every_active_integration(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION');
        $since = now()->subMinute();
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'response_payload' => ['status' => 'synchronized'],
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => $since->toIso8601String(),
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'active=1, fresh_success=1, missing=-, pending=0, failed=0',
            Artisan::output(),
        );
    }

    public function test_the_deploy_sync_command_refuses_to_bypass_the_catalog_lock_outside_maintenance(): void
    {
        $this->createSizeDefinition();
        $this->createWooIntegration('GLOBAL-SIZE-DEPLOY-NOT-DOWN');
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);

        $exitCode = Artisan::call('erp:sync-woocommerce-global-size-order-during-maintenance', [
            '--trigger' => 'deploy_abcdef123456-123-1',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'allowed only while the application is in maintenance mode',
            Artisan::output(),
        );
        Bus::assertNotDispatched(SyncWooCommerceGlobalSizeOrderJob::class);
        $this->assertSame(0, IntegrationSyncLog::query()
            ->where('operation', 'sync_woocommerce_global_size_order')
            ->count());
        Http::assertNothingSent();
    }

    public function test_the_deploy_sync_command_runs_each_active_integration_directly_during_maintenance(): void
    {
        $this->createSizeDefinition();
        $active = $this->createWooIntegration('GLOBAL-SIZE-DEPLOY-SYNC');
        $this->createWooIntegration('GLOBAL-SIZE-DEPLOY-SYNC-INACTIVE', active: false);
        $this->createWooIntegration('GLOBAL-SIZE-DEPLOY-SYNC-MARKETPLACE', type: 'marketplace');
        $attribute = $this->sizeAttribute();
        $terms = $this->allLanguageTerms();
        $mutations = [];
        $this->fakeWooCatalog($attribute, $terms, $mutations);
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);
        Artisan::call('down', ['--retry' => 60]);

        try {
            $exitCode = Artisan::call('erp:sync-woocommerce-global-size-order-during-maintenance', [
                '--trigger' => 'deploy_abcdef123456-123-1',
            ]);
            $output = Artisan::output();
        } finally {
            Artisan::call('up');
        }

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            "completed for integration {$active->id}",
            $output,
        );
        $this->assertStringContainsString(
            'active=1, succeeded=1, failed=0, trigger=deploy_abcdef123456-123-1',
            $output,
        );
        Bus::assertNotDispatched(SyncWooCommerceGlobalSizeOrderJob::class);
        $log = IntegrationSyncLog::query()
            ->where('operation', 'sync_woocommerce_global_size_order')
            ->sole();
        $this->assertSame((int) $active->id, (int) $log->wordpress_integration_id);
        $this->assertSame('success', $log->status);
        $this->assertSame('deploy_abcdef123456-123-1', data_get($log->request_payload, 'trigger'));
        $this->assertSame('synchronized', data_get($log->response_payload, 'status'));
        $this->assertSame('menu_order', $attribute['order_by']);
        $this->assertSame(10, $terms[58]['menu_order']);
        $this->assertSame(20, $terms[57]['menu_order']);
    }

    public function test_the_deploy_sync_command_rejects_an_empty_audit_trigger(): void
    {
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);

        $exitCode = Artisan::call('erp:sync-woocommerce-global-size-order-during-maintenance', [
            '--trigger' => '   ',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('trigger cannot be empty', Artisan::output());
        Bus::assertNotDispatched(SyncWooCommerceGlobalSizeOrderJob::class);
    }

    public function test_an_exact_deploy_trigger_is_not_satisfied_by_another_success_from_the_same_second(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION-TRIGGER');
        $since = now()->startOfSecond();
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'request_payload' => ['trigger' => 'deploy_previous-release'],
            'response_payload' => ['status' => 'synchronized'],
            'attempts' => 1,
            'started_at' => $since,
            'finished_at' => $since,
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => $since->toIso8601String(),
            '--trigger' => 'deploy_current-release',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "active=1, fresh_success=0, missing={$integration->id}, pending=0, failed=0",
            $output,
        );
        $this->assertStringContainsString('trigger=deploy_current-release', $output);
    }

    public function test_an_exact_deploy_success_supersedes_async_queue_rows_from_the_same_second(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION-SUPERSEDE');
        $since = now()->startOfSecond();
        $payload = json_encode([
            'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
        ], JSON_THROW_ON_ERROR);
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'request_payload' => ['trigger' => 'deploy_current-release'],
            'response_payload' => ['status' => 'synchronized'],
            'attempts' => 1,
            'started_at' => $since,
            'finished_at' => $since,
        ]);
        DB::table('jobs')->insert([
            'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            'payload' => $payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $since->timestamp + 60,
            'created_at' => $since->timestamp,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'global-size-order-superseded',
            'connection' => 'database',
            'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            'payload' => $payload,
            'exception' => 'superseded fixture',
            'failed_at' => $since,
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => $since->toIso8601String(),
            '--trigger' => 'deploy_current-release',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'active=1, fresh_success=1, missing=-, pending=0, failed=0',
            Artisan::output(),
        );
    }

    public function test_the_deploy_postcondition_rejects_a_pending_exact_job_and_missing_success(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION-PENDING');
        $timestamp = now()->timestamp;
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'response_payload' => ['status' => 'skipped_no_size_definition'],
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        DB::table('jobs')->insert([
            'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            'payload' => json_encode([
                'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => $timestamp + 60,
            'created_at' => $timestamp,
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => now()->subMinute()->toIso8601String(),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            "active=1, fresh_success=0, missing={$integration->id}, pending=1, failed=0",
            Artisan::output(),
        );
    }

    public function test_the_postcondition_without_an_exact_trigger_still_rejects_an_old_pending_job(): void
    {
        $integration = $this->createWooIntegration('GLOBAL-SIZE-POSTCONDITION-OLD-PENDING');
        $since = now()->subMinute();
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'response_payload' => ['status' => 'synchronized'],
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        DB::table('jobs')->insert([
            'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
            'payload' => json_encode([
                'displayName' => SyncWooCommerceGlobalSizeOrderJob::class,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 1,
            'reserved_at' => null,
            'available_at' => now()->timestamp + 60,
            'created_at' => now()->subHours(2)->timestamp,
        ]);

        $exitCode = Artisan::call('erp:verify-woocommerce-global-size-order-sync', [
            '--since' => $since->toIso8601String(),
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'active=1, fresh_success=1, missing=-, pending=1, failed=0',
            Artisan::output(),
        );
    }

    public function test_the_observer_queues_a_new_fingerprint_for_size_dictionary_changes_but_not_metadata_only_changes(): void
    {
        $definition = $this->createSizeDefinition();
        $active = $this->createWooIntegration('GLOBAL-SIZE-OBSERVER');
        Bus::fake([SyncWooCommerceGlobalSizeOrderJob::class]);
        $observer = app(ProductParameterDefinitionObserver::class);
        $this->assertInstanceOf(ShouldHandleEventsAfterCommit::class, $observer);

        $definition->update([
            'values' => ['XS', 'S/M', 'M/L'],
            'values_en' => ['XS', 'S/M', 'M/L'],
        ]);
        $observer->saved($definition);

        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
        Bus::assertDispatched(
            SyncWooCommerceGlobalSizeOrderJob::class,
            function (SyncWooCommerceGlobalSizeOrderJob $job) use ($active): bool {
                $this->assertSame((int) $active->id, $job->integrationId);
                $this->assertSame('erp_size_dictionary_changed', $job->trigger);
                $this->assertSame('database', $job->connection);
                $this->assertSame(SyncWooCommerceGlobalSizeOrderJob::QUEUE, $job->queue);
                $this->assertTrue($job->afterCommit);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $job->dictionaryFingerprint);

                return true;
            },
        );

        $definition->update(['metadata' => ['note' => 'does not affect storefront order']]);
        $observer->saved($definition);

        Bus::assertDispatchedTimes(SyncWooCommerceGlobalSizeOrderJob::class, 1);
    }

    private function createSizeDefinition(): ProductParameterDefinition
    {
        return ProductParameterDefinition::query()->create([
            'name' => 'Rozmiar',
            'name_en' => 'Size',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => ['S/M', 'M/L'],
            'values_en' => ['S/M', 'M/L'],
            // Historical production dictionaries can drive a size axis even
            // when this flag was never enabled.
            'is_variant' => false,
            'is_required' => false,
            'sort_order' => 10,
        ]);
    }

    private function createWooIntegration(
        string $code,
        bool $active = true,
        string $type = 'woocommerce',
    ): WordpressIntegration {
        $channel = SalesChannel::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'is_active' => $active,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => $code,
            'base_url' => 'https://'.mb_strtolower($code).'.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'settings' => ['product_export' => ['languages' => ['pl', 'en']]],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function sizeAttribute(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'name' => 'Rozmiar',
            'slug' => 'pa_rozmiar',
            'order_by' => 'name',
        ], $overrides);
    }

    /** @return array<int, array<string, mixed>> */
    private function allLanguageTerms(bool $canonicalPolish = false): array
    {
        return [
            57 => [
                'id' => 57,
                'name' => $canonicalPolish ? 'M/L' : 'm-l',
                'slug' => 'm-l',
                'menu_order' => $canonicalPolish ? 20 : 0,
            ],
            58 => [
                'id' => 58,
                'name' => $canonicalPolish ? 'S/M' : 's-m',
                'slug' => 's-m',
                'menu_order' => $canonicalPolish ? 10 : 0,
            ],
            110014 => [
                'id' => 110014,
                'name' => 'M/L',
                'slug' => 'm-l-en',
                'menu_order' => 20,
            ],
            110008 => [
                'id' => 110008,
                'name' => 'S/M',
                'slug' => 's-m-en',
                'menu_order' => 10,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @param  array<int, array<string, mixed>>  $terms
     * @param  list<array{method:string,path:string,payload:array<string,mixed>}>  $mutations
     */
    private function fakeWooCatalog(
        array &$attribute,
        array &$terms,
        array &$mutations,
        ?int $failingTermId = null,
    ): void {
        Http::fake(function (Request $request) use (
            &$attribute,
            &$terms,
            &$mutations,
            $failingTermId,
        ) {
            $method = $request->method();
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($method === 'GET' && $path === '/wp-json/wc/v3/products/attributes') {
                return Http::response([$attribute]);
            }

            if ($method === 'GET' && $path === '/wp-json/wc/v3/products/attributes/1/terms') {
                // Deliberately ignore the lang query just like the affected
                // WooCommerce/Polylang endpoint and return both languages.
                return Http::response(array_values($terms));
            }

            if ($method === 'PUT' && $path === '/wp-json/wc/v3/products/attributes/1') {
                $payload = $request->data();
                $attribute = array_merge($attribute, $payload);
                $mutations[] = compact('method', 'path', 'payload');

                return Http::response($attribute);
            }

            if ($method === 'PUT'
                && preg_match('#^/wp-json/wc/v3/products/attributes/1/terms/(\d+)$#', $path, $matches) === 1
            ) {
                $termId = (int) $matches[1];

                if (! isset($terms[$termId])) {
                    throw new RuntimeException("Test otrzymał aktualizację nieistniejącego terminu #{$termId}.");
                }

                $payload = $request->data();
                $mutations[] = compact('method', 'path', 'payload');

                if ($termId === $failingTermId) {
                    return Http::response(['message' => 'persistent test failure'], 500);
                }

                $terms[$termId] = array_merge($terms[$termId], $payload);

                return Http::response($terms[$termId]);
            }

            throw new RuntimeException("Nieoczekiwane żądanie WooCommerce: {$method} {$path}");
        });
    }
}
