<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ImportWooCommerceCustomersJob;
use App\Models\Customer;
use App\Models\CustomerExternalAccount;
use App\Models\CustomerMessage;
use App\Models\IntegrationSyncLog;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Integrations\WooCommerceImportQueueService;
use App\Services\WooCommerce\WooCommerceCustomerSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WooCommerceCustomerImportQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_import_can_be_queued_without_duplicates_from_service_and_panel(): void
    {
        Queue::fake();
        $integration = $this->createIntegration();

        $result = app(WooCommerceImportQueueService::class)->queueEnabledImports(
            products: false,
            orders: false,
            customers: true,
        );

        $this->assertSame(1, $result['queued']);
        $this->assertSame(['import_customers'], $result['operations']);
        $this->assertDatabaseHas('integration_sync_logs', [
            'wordpress_integration_id' => $integration->id,
            'operation' => 'import_customers',
            'status' => 'queued',
        ]);
        Queue::assertPushed(ImportWooCommerceCustomersJob::class, 1);

        $this->post(route('integrations.import-customers', $integration))
            ->assertRedirect()
            ->assertSessionHas('status', 'Import klientów dla tej integracji jest już w kolejce albo w toku.');

        $this->assertSame(1, IntegrationSyncLog::query()->where('operation', 'import_customers')->count());
        Queue::assertPushed(ImportWooCommerceCustomersJob::class, 1);

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('Kolejkuj klientów')
            ->assertSee(route('integrations.import-customers', $integration), false)
            ->assertSee('Import klientów');
    }

    public function test_customer_import_retry_and_stale_recovery_use_the_same_allowlist(): void
    {
        Queue::fake();
        $integration = $this->createIntegration();
        $stale = $this->createLog($integration, 'running', now()->subMinutes(75));

        $this->post(route('integrations.import-customers', $integration))
            ->assertRedirect()
            ->assertSessionHas('status', 'Import klientów został dodany do kolejki. Status będzie widoczny w logach synchronizacji.');

        $this->assertSame('failed', $stale->refresh()->status);
        Queue::assertPushed(ImportWooCommerceCustomersJob::class, 1);

        $queued = IntegrationSyncLog::query()
            ->where('operation', 'import_customers')
            ->where('status', 'queued')
            ->firstOrFail();
        $queued->update([
            'status' => 'failed',
            'error_message' => 'Błąd API klientów',
            'finished_at' => now(),
        ]);

        $this->post(route('integrations.logs.retry', $queued))
            ->assertRedirect()
            ->assertSessionHas('status', 'Import został ponownie dodany do kolejki.');

        Queue::assertPushed(ImportWooCommerceCustomersJob::class, 2);
    }

    public function test_first_successful_customer_import_is_a_baseline_without_account_emails(): void
    {
        $integration = $this->createIntegration();
        $log = $this->createLog($integration, 'queued', now());
        $this->fakeCustomerPages([
            $this->customerProfile(101, 'historyczny@example.test', '2026-07-13T12:00:00'),
        ]);

        (new ImportWooCommerceCustomersJob($integration->id, $log->id))->handle(
            app(WooCommerceCustomerSyncService::class),
            app(CustomerCommunicationService::class),
        );

        $log->refresh();
        $this->assertSame('success', $log->status);
        $this->assertTrue($log->response_payload['notification_baseline']);
        $this->assertNull($log->response_payload['notification_cutoff']);
        $this->assertSame(0, $log->response_payload['notifications_created']);
        $this->assertSame(1, $log->response_payload['created_customer_ids_count']);
        $this->assertArrayNotHasKey('created_customer_ids', $log->response_payload);
        $this->assertNotNull(data_get(
            $integration->fresh()->settings,
            'customer_import.notification_baseline_at',
        ));
        $this->assertDatabaseHas('customer_external_accounts', [
            'wordpress_integration_id' => $integration->id,
            'external_customer_id' => '101',
            'is_registered' => true,
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'trigger' => 'customer_account_created',
        ]);
    }

    public function test_account_created_during_initial_scan_remains_eligible_on_next_import(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-13 10:00:00', 'Europe/Warsaw'));
        $integration = $this->createIntegration();
        $baselineLog = $this->createLog($integration, 'queued', now());
        $requestNumber = 0;
        Http::fake(function () use (&$requestNumber) {
            $requestNumber++;

            return Http::response(match ($requestNumber) {
                2 => [$this->customerProfile(111, 'w-trakcie@example.test', '2026-07-13T08:05:00Z')],
                default => [],
            });
        });

        (new ImportWooCommerceCustomersJob($integration->id, $baselineLog->id))->handle(
            app(WooCommerceCustomerSyncService::class),
            app(CustomerCommunicationService::class),
        );

        $this->assertSame(
            CarbonImmutable::parse('2026-07-13 10:00:00', 'Europe/Warsaw')->toIso8601String(),
            data_get($integration->fresh()->settings, 'customer_import.notification_baseline_at'),
        );

        $this->travelTo(CarbonImmutable::parse('2026-07-13 10:20:00', 'Europe/Warsaw'));
        $nextLog = $this->createLog($integration, 'queued', now());

        (new ImportWooCommerceCustomersJob($integration->id, $nextLog->id))->handle(
            app(WooCommerceCustomerSyncService::class),
            app(CustomerCommunicationService::class),
        );

        $nextLog->refresh();
        $this->assertSame(1, $nextLog->response_payload['notifications_eligible']);
        $this->assertSame(1, $nextLog->response_payload['notifications_created']);
        $this->assertDatabaseHas('customer_messages', [
            'trigger' => 'customer_account_created',
            'recipient_email' => 'w-trakcie@example.test',
            'status' => 'held',
        ]);

        $this->travelBack();
    }

    public function test_later_import_notifies_only_new_registered_accounts_without_previous_message(): void
    {
        $integration = $this->createIntegration();
        $previousFinishedAt = CarbonImmutable::parse('2026-07-13 10:00:00', 'Europe/Warsaw');
        $previous = $this->createLog($integration, 'success', $previousFinishedAt->subMinute());
        $previous->update(['finished_at' => $previousFinishedAt]);
        $baselineAt = $previousFinishedAt->subMinutes(20);
        $integration->update([
            'settings' => [
                'customer_import' => [
                    'notification_baseline_at' => $baselineAt->toIso8601String(),
                ],
            ],
        ]);

        $alreadyNotified = Customer::query()->create([
            'email' => 'powiadomiony@example.test',
            'email_normalized' => 'powiadomiony@example.test',
            'first_name' => 'Powiadomiony',
            'account_status' => 'registered',
        ]);
        CustomerExternalAccount::query()->create([
            'customer_id' => $alreadyNotified->id,
            'wordpress_integration_id' => $integration->id,
            'external_customer_id' => '303',
            'email' => $alreadyNotified->email,
            'email_normalized' => $alreadyNotified->email_normalized,
            'is_registered' => true,
            'account_created_at' => $previousFinishedAt->addMinute(),
        ]);
        CustomerMessage::query()->create([
            'customer_id' => $alreadyNotified->id,
            'direction' => 'outgoing',
            'type' => 'automated',
            'trigger' => 'customer_account_created',
            'status' => 'sent',
            'recipient_email' => $alreadyNotified->email,
            'subject' => 'Konto utworzone',
            'body' => 'Wiadomość wysłana wcześniej.',
            'sent_at' => $previousFinishedAt->addMinutes(2),
        ]);

        $current = $this->createLog($integration, 'queued', now());
        $this->fakeCustomerPages([
            $this->customerProfile(201, 'nowy@example.test', '2026-07-13T08:45:00Z'),
            $this->customerProfile(202, 'stary@example.test', '2026-07-13T07:00:00Z'),
            $this->customerProfile(303, 'powiadomiony@example.test', '2026-07-13T08:01:00Z'),
        ]);

        (new ImportWooCommerceCustomersJob($integration->id, $current->id))->handle(
            app(WooCommerceCustomerSyncService::class),
            app(CustomerCommunicationService::class),
        );

        $current->refresh();
        $this->assertSame('success', $current->status);
        $this->assertFalse($current->response_payload['notification_baseline']);
        $this->assertSame(30, $current->response_payload['notification_overlap_minutes']);
        $this->assertSame(
            $previousFinishedAt->toIso8601String(),
            $current->response_payload['notification_previous_success_at'],
        );
        $this->assertSame(
            $baselineAt->toIso8601String(),
            $current->response_payload['notification_cutoff'],
        );
        $this->assertSame(2, $current->response_payload['notifications_eligible']);
        $this->assertSame(1, $current->response_payload['notifications_created']);
        $this->assertSame(1, $current->response_payload['notifications_held']);
        $this->assertSame(1, $current->response_payload['notifications_skipped']);

        $this->assertSame(2, CustomerMessage::query()->where('trigger', 'customer_account_created')->count());
        $this->assertDatabaseHas('customer_messages', [
            'trigger' => 'customer_account_created',
            'recipient_email' => 'nowy@example.test',
            'status' => 'held',
        ]);
        $this->assertDatabaseMissing('customer_messages', [
            'trigger' => 'customer_account_created',
            'recipient_email' => 'stary@example.test',
        ]);
    }

    public function test_customer_command_and_scheduler_queue_only_enabled_integrations(): void
    {
        Queue::fake();
        $enabled = $this->createIntegration();
        $disabled = $this->createIntegration('OUTLET', false);

        $this->artisan('erp:queue-woocommerce-imports', ['--customers' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('integration_sync_logs', [
            'wordpress_integration_id' => $enabled->id,
            'operation' => 'import_customers',
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('integration_sync_logs', [
            'wordpress_integration_id' => $disabled->id,
            'operation' => 'import_customers',
        ]);
        Queue::assertPushed(ImportWooCommerceCustomersJob::class, 1);

        Artisan::call('schedule:list');
        $this->assertStringContainsString(
            'erp:queue-woocommerce-imports --customers',
            Artisan::output(),
        );
    }

    private function createIntegration(string $code = 'B2C', bool $orderImportEnabled = true): WordpressIntegration
    {
        $channel = SalesChannel::query()->create([
            'code' => $code,
            'name' => 'Sklep '.$code,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre '.$code.' Woo',
            'base_url' => 'https://'.mb_strtolower($code).'.shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_'.$code),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_'.$code),
            'order_import_enabled' => $orderImportEnabled,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);
    }

    private function createLog(
        WordpressIntegration $integration,
        string $status,
        mixed $startedAt,
    ): IntegrationSyncLog {
        return IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => 'import_customers',
            'status' => $status,
            'attempts' => 1,
            'started_at' => $startedAt,
            'finished_at' => $status === 'success' ? $startedAt : null,
        ]);
    }

    /** @param list<array<string, mixed>> $profiles */
    private function fakeCustomerPages(array $profiles): void
    {
        Http::fake(function (Request $request) use ($profiles) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response(((int) ($query['page'] ?? 1)) === 1 ? $profiles : []);
        });
    }

    /** @return array<string, mixed> */
    private function customerProfile(int $id, string $email, string $createdAt): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'username' => 'customer-'.$id,
            'first_name' => 'Klient',
            'last_name' => (string) $id,
            'display_name' => 'Klient '.$id,
            'role' => 'customer',
            'date_created_gmt' => $createdAt,
            'billing' => [
                'first_name' => 'Klient',
                'last_name' => (string) $id,
                'email' => $email,
            ],
            'shipping' => [],
            'orders_count' => 0,
            'total_spent' => '0.00',
        ];
    }
}
