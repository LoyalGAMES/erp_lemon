<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ImportWooCommerceCustomersJob;
use App\Jobs\ImportWooCommerceOrdersJob;
use App\Jobs\ImportWooCommerceProductsJob;
use App\Models\IntegrationSyncLog;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Integrations\WooCommerceImportQueueService;
use App\Services\WooCommerce\WooCommerceImportService;
use App\Services\Wordpress\LemonErpWooCommercePluginPackageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use ZipArchive;

class IntegrationRetryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_product_import_can_be_retried_from_integration_logs(): void
    {
        Queue::fake();

        [$integration] = $this->createIntegration();
        $failedLog = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'failed',
            'error_message' => 'WooCommerce timeout',
            'attempts' => 2,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('WooCommerce timeout')
            ->assertSee('Ponów import');

        $this->post(route('integrations.logs.retry', $failedLog))
            ->assertRedirect()
            ->assertSessionHas('status', 'Import został ponownie dodany do kolejki.');

        $failedLog->refresh();
        $this->assertSame('failed', $failedLog->status);
        $this->assertSame('WooCommerce timeout', $failedLog->error_message);

        $retryLog = IntegrationSyncLog::query()
            ->where('operation', 'import_products')
            ->where('status', 'queued')
            ->whereKeyNot($failedLog->id)
            ->firstOrFail();

        $this->assertSame($failedLog->id, $retryLog->request_payload['retry_of_log_id']);

        Queue::assertPushed(ImportWooCommerceProductsJob::class);
        Queue::assertNotPushed(ImportWooCommerceOrdersJob::class);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration_sync.retry_requested',
            'auditable_type' => IntegrationSyncLog::class,
            'auditable_id' => $retryLog->id,
        ]);
    }

    public function test_operator_can_download_lemon_woocommerce_plugin_zip(): void
    {
        $response = $this->get(route('integrations.woocommerce-plugin.download'));

        $response->assertOk();
        $this->assertStringContainsString(
            'lemon-erp-woocommerce-',
            (string) $response->headers->get('content-disposition'),
        );

        $package = app(LemonErpWooCommercePluginPackageService::class)->build();
        $zip = new ZipArchive;

        $this->assertTrue($zip->open($package['path']) === true);
        $this->assertNotFalse($zip->locateName('lemon-erp-woocommerce/lemon-erp-woocommerce.php'));
        $this->assertNotFalse($zip->locateName('lemon-erp-woocommerce/README.md'));
        $zip->close();
    }

    public function test_failed_order_import_can_be_retried_and_non_failed_log_is_rejected(): void
    {
        Queue::fake();

        [$integration] = $this->createIntegration();
        $failedLog = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'failed',
            'error_message' => 'Błąd API zamówień',
            'attempts' => 1,
            'started_at' => now()->subMinutes(6),
            'finished_at' => now()->subMinutes(5),
        ]);
        $successLog = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'success',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->post(route('integrations.logs.retry', $successLog))
            ->assertRedirect()
            ->assertSessionHas('error', 'Ponowić można tylko nieudany import.');

        $this->post(route('integrations.logs.retry', $failedLog))
            ->assertRedirect()
            ->assertSessionHas('status', 'Import został ponownie dodany do kolejki.');

        Queue::assertPushed(ImportWooCommerceOrdersJob::class);
    }

    public function test_order_import_job_splits_full_backfill_into_continuation_jobs(): void
    {
        Queue::fake();

        [$integration] = $this->createIntegration();
        $log = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'queued',
            'attempts' => 1,
            'started_at' => now(),
        ]);
        $orders = collect(range(1, 100))
            ->map(fn (int $id): array => [
                'id' => $id,
                'number' => (string) $id,
                'status' => 'pending',
                'currency' => 'PLN',
                'total' => '0.00',
                'line_items' => [],
            ])
            ->all();

        Http::fake([
            '*' => function ($request) use ($orders) {
                if (str_contains($request->url(), '/notes')) {
                    return Http::response([]);
                }

                return Http::response($orders);
            },
        ]);

        (new ImportWooCommerceOrdersJob($integration->id, $log->id))->handle(
            app(WooCommerceImportService::class),
            app(WooCommerceImportQueueService::class),
        );

        $log->refresh();
        $this->assertSame('success', $log->status);
        $this->assertSame(1, $log->response_payload['pages']);
        $this->assertTrue($log->response_payload['has_more']);
        $this->assertSame('backfill', $log->response_payload['mode']);

        $continuation = IntegrationSyncLog::query()
            ->where('wordpress_integration_id', $integration->id)
            ->where('operation', 'import_orders')
            ->where('status', 'queued')
            ->firstOrFail();

        $this->assertSame('continuation', $continuation->request_payload['source']);
        $this->assertSame('backfill', $continuation->request_payload['mode']);
        $this->assertSame(2, $continuation->request_payload['page']);
        $this->assertSame(2, data_get($integration->fresh()->settings, 'order_import.continuation.next_page'));

        Queue::assertPushed(ImportWooCommerceOrdersJob::class, 1);
    }

    public function test_order_import_job_uses_last_successful_order_import_as_incremental_cutoff(): void
    {
        Queue::fake();

        [$integration] = $this->createIntegration();
        $finishedAt = CarbonImmutable::parse('2026-07-10 12:00:00', 'Europe/Warsaw');
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'success',
            'attempts' => 1,
            'started_at' => $finishedAt->subMinute(),
            'finished_at' => $finishedAt,
        ]);
        $log = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'queued',
            'attempts' => 1,
            'started_at' => now(),
        ]);

        Http::fake(['*' => Http::response([])]);

        (new ImportWooCommerceOrdersJob($integration->id, $log->id))->handle(
            app(WooCommerceImportService::class),
            app(WooCommerceImportQueueService::class),
        );

        Http::assertSent(function ($request) use ($finishedAt): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['modified_after'] === $finishedAt->subMinutes(30)->toIso8601String();
        });

        $this->assertSame('incremental', $log->refresh()->response_payload['mode']);
        $this->assertFalse($log->response_payload['has_more']);
        Queue::assertNotPushed(ImportWooCommerceOrdersJob::class);
    }

    public function test_import_buttons_do_not_duplicate_active_jobs(): void
    {
        Queue::fake();

        [$integration] = $this->createIntegration();

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'queued',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'running',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
        ]);

        $this->post(route('integrations.import-products', $integration))
            ->assertRedirect()
            ->assertSessionHas('status', 'Import produktów dla tej integracji jest już w kolejce albo w toku.');

        $this->post(route('integrations.import-orders', $integration))
            ->assertRedirect()
            ->assertSessionHas('status', 'Import zamówień dla tej integracji jest już w kolejce albo w toku.');

        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'import_products')->count());
        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'import_orders')->count());

        Queue::assertNotPushed(ImportWooCommerceProductsJob::class);
        Queue::assertNotPushed(ImportWooCommerceOrdersJob::class);
    }

    public function test_stale_running_import_is_released_when_operator_queues_same_import(): void
    {
        Queue::fake();

        [$integration] = $this->createIntegration();

        $staleLog = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'running',
            'attempts' => 1,
            'started_at' => now()->subMinutes(75),
        ]);

        $this->post(route('integrations.import-products', $integration))
            ->assertRedirect()
            ->assertSessionHas('status', 'Import produktów został dodany do kolejki. Status będzie widoczny w logach synchronizacji.');

        $staleLog->refresh();
        $this->assertSame('failed', $staleLog->status);
        $this->assertStringContainsString('przerwany po 60 minut', (string) $staleLog->error_message);
        $this->assertSame(60, data_get($staleLog->response_payload, 'stale_recovery.stale_after_minutes'));

        $retryLog = IntegrationSyncLog::query()
            ->where('operation', 'import_products')
            ->where('status', 'queued')
            ->whereKeyNot($staleLog->id)
            ->firstOrFail();

        $this->assertSame('erp_panel', $retryLog->request_payload['source']);
        Queue::assertPushed(ImportWooCommerceProductsJob::class);
        Queue::assertNotPushed(ImportWooCommerceOrdersJob::class);
    }

    public function test_console_command_releases_stale_running_imports(): void
    {
        [$integration] = $this->createIntegration();

        $staleLog = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'running',
            'attempts' => 1,
            'started_at' => now()->subMinutes(45),
        ]);

        $freshLog = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'running',
            'attempts' => 1,
            'started_at' => now()->subMinutes(5),
        ]);

        $this->artisan('erp:release-stale-woocommerce-imports', ['--minutes' => 30])
            ->expectsOutput('WooCommerce stale imports released: 1, threshold: 30 minutes.')
            ->assertExitCode(0);

        $this->assertSame('failed', $staleLog->refresh()->status);
        $this->assertSame(30, data_get($staleLog->response_payload, 'stale_recovery.stale_after_minutes'));
        $this->assertSame('running', $freshLog->refresh()->status);
    }

    public function test_scheduler_registers_woocommerce_import_maintenance_tasks(): void
    {
        Artisan::call('schedule:list');

        $output = Artisan::output();

        $this->assertStringContainsString('erp:queue-woocommerce-imports --orders', $output);
        $this->assertStringContainsString('erp:queue-woocommerce-imports --customers', $output);
        $this->assertStringContainsString('queue:work --stop-when-empty', $output);
        $this->assertStringContainsString('erp:release-stale-woocommerce-imports --minutes=60', $output);
    }

    public function test_console_command_queues_enabled_woocommerce_imports_without_duplicates(): void
    {
        Queue::fake();

        [$mainIntegration] = $this->createIntegration();

        $ordersOnlyChannel = SalesChannel::query()->create([
            'code' => 'B2B',
            'name' => 'Sklep B2B',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $ordersOnlyIntegration = WordpressIntegration::query()->create([
            'sales_channel_id' => $ordersOnlyChannel->id,
            'name' => 'Sempre B2B Woo',
            'base_url' => 'https://b2b.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_b2b'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_b2b'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
            'invoice_upload_enabled' => true,
        ]);

        $productsOnlyChannel = SalesChannel::query()->create([
            'code' => 'B2C_PRODUCTS',
            'name' => 'Sklep tylko produkty',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $productsOnlyIntegration = WordpressIntegration::query()->create([
            'sales_channel_id' => $productsOnlyChannel->id,
            'name' => 'Sempre Products Woo',
            'base_url' => 'https://products.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_products'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_products'),
            'order_import_enabled' => false,
            'stock_export_enabled' => false,
            'invoice_upload_enabled' => false,
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $mainIntegration->sales_channel_id,
            'wordpress_integration_id' => $mainIntegration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'queued',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
        ]);

        $this->artisan('erp:queue-woocommerce-imports', ['--all' => true])
            ->assertExitCode(0);

        $this->assertSame(7, IntegrationSyncLog::query()->count());
        $this->assertSame(3, IntegrationSyncLog::query()->where('operation', 'import_products')->count());
        $this->assertSame(2, IntegrationSyncLog::query()->where('operation', 'import_orders')->count());
        $this->assertSame(2, IntegrationSyncLog::query()->where('operation', 'import_customers')->count());

        $this->assertDatabaseHas('integration_sync_logs', [
            'wordpress_integration_id' => $ordersOnlyIntegration->id,
            'operation' => 'import_orders',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('integration_sync_logs', [
            'wordpress_integration_id' => $productsOnlyIntegration->id,
            'operation' => 'import_products',
            'status' => 'queued',
        ]);

        $scheduledLogs = IntegrationSyncLog::query()
            ->whereNotNull('request_payload')
            ->get();

        $this->assertCount(6, $scheduledLogs);
        $this->assertTrue($scheduledLogs->every(
            fn (IntegrationSyncLog $log): bool => data_get($log->request_payload, 'source') === 'scheduled_command',
        ));

        Queue::assertPushed(ImportWooCommerceProductsJob::class, 3);
        Queue::assertPushed(ImportWooCommerceOrdersJob::class, 1);
        Queue::assertPushed(ImportWooCommerceCustomersJob::class, 2);

        $this->artisan('erp:queue-woocommerce-imports', ['--all' => true])
            ->assertExitCode(0);

        $this->assertSame(7, IntegrationSyncLog::query()->count());
    }

    public function test_integration_log_times_are_displayed_in_warsaw_timezone(): void
    {
        $this->assertSame('Europe/Warsaw', config('app.timezone'));

        [$integration] = $this->createIntegration();
        $log = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_orders',
            'status' => 'queued',
            'attempts' => 1,
        ]);
        $log->created_at = CarbonImmutable::create(2026, 7, 9, 8, 45, 28, 'Europe/Warsaw');
        $log->updated_at = $log->created_at;
        $log->save();

        $this->get(route('integrations.index', ['tab' => 'logs']))
            ->assertOk()
            ->assertSee('2026-07-09 08:45:28')
            ->assertDontSee('2026-07-09 06:45:28');
    }

    public function test_retry_does_not_duplicate_active_import(): void
    {
        Queue::fake();

        [$integration] = $this->createIntegration();

        $failedLog = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'failed',
            'error_message' => 'Poprzedni import nie przeszedł',
            'attempts' => 1,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'queued',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
        ]);

        $this->post(route('integrations.logs.retry', $failedLog))
            ->assertRedirect()
            ->assertSessionHas('status', 'Taki import jest już w kolejce albo w toku. Nie dodano duplikatu.');

        $this->assertSame(2, IntegrationSyncLog::query()->where('operation', 'import_products')->count());
        $this->assertFalse(IntegrationSyncLog::query()
            ->get()
            ->contains(fn (IntegrationSyncLog $log): bool => data_get($log->request_payload, 'retry_of_log_id') === $failedLog->id));
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'integration_sync.retry_requested',
        ]);

        Queue::assertNotPushed(ImportWooCommerceProductsJob::class);
    }

    public function test_integration_delete_is_blocked_during_active_import_and_audited_afterwards(): void
    {
        [$integration] = $this->createIntegration();

        $activeLog = IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_products',
            'status' => 'queued',
            'attempts' => 1,
            'started_at' => now()->subMinute(),
        ]);

        $this->delete(route('integrations.destroy', $integration))
            ->assertRedirect()
            ->assertSessionHas('error', 'Nie można usunąć integracji, gdy import jest w kolejce albo w toku.');

        $this->assertNotSoftDeleted('wordpress_integrations', ['id' => $integration->id]);

        $activeLog->update([
            'status' => 'failed',
            'error_message' => 'Przerwany import testowy',
            'finished_at' => now(),
        ]);

        $this->delete(route('integrations.destroy', $integration))
            ->assertRedirect()
            ->assertSessionHas('status', 'Integracja została usunięta.');

        $this->assertSoftDeleted('wordpress_integrations', ['id' => $integration->id]);

        $deleted = WordpressIntegration::withTrashed()->findOrFail($integration->id);
        $this->assertFalse($deleted->order_import_enabled);
        $this->assertFalse($deleted->stock_export_enabled);
        $this->assertFalse($deleted->invoice_upload_enabled);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.deleted',
            'auditable_type' => WordpressIntegration::class,
            'auditable_id' => $integration->id,
        ]);
    }

    public function test_operator_can_edit_integration_without_recreating_channel_or_secrets(): void
    {
        [$integration, $channel] = $this->createIntegration();

        $originalSecret = $integration->consumer_secret_encrypted;

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('Edytuj')
            ->assertSee('Statusy Woo');

        $this->put(route('integrations.update', $integration), [
            'channel_code' => 'b2b',
            'channel_name' => 'Sklep B2B',
            'name' => 'Sempre B2B Woo',
            'base_url' => 'https://b2b.shop.test/',
            'consumer_key' => 'ck_new',
            'consumer_secret' => '',
            'order_import_enabled' => '1',
            'invoice_upload_enabled' => '1',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Konfiguracja integracji WooCommerce została zapisana.');

        $integration->refresh();
        $channel->refresh();

        $this->assertSame('B2B', $channel->code);
        $this->assertSame('Sklep B2B', $channel->name);
        $this->assertSame('Sempre B2B Woo', $integration->name);
        $this->assertSame('https://b2b.shop.test', $integration->base_url);
        $this->assertTrue($integration->order_import_enabled);
        $this->assertFalse($integration->stock_export_enabled);
        $this->assertTrue($integration->invoice_upload_enabled);
        $this->assertSame('ck_new', Crypt::decryptString($integration->consumer_key_encrypted));
        $this->assertSame($originalSecret, $integration->consumer_secret_encrypted);

        $this->put(route('integrations.order-statuses.update', $integration), [
            'ready_to_ship_status' => 'gotowe-do-wysylki',
            'shipped_status' => 'wyslane',
            'packing_rollback_status' => 'processing',
        ])->assertRedirect()
            ->assertSessionHas('status', 'Statusy WooCommerce dla pakowania zostały zapisane.');

        $this->assertSame([
            'ready_to_ship' => 'gotowe-do-wysylki',
            'shipped' => 'wyslane',
            'packing_rollback' => 'processing',
        ], $integration->refresh()->orderStatusSettings());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.updated',
            'auditable_type' => WordpressIntegration::class,
            'auditable_id' => $integration->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.order_statuses_updated',
            'auditable_type' => WordpressIntegration::class,
            'auditable_id' => $integration->id,
        ]);
    }

    /**
     * @return array{0:WordpressIntegration,1:SalesChannel}
     */
    private function createIntegration(): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);

        return [$integration, $channel];
    }
}
