<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IntegrationSyncLog;
use App\Models\SalesChannel;
use App\Models\User;
use App\Models\WordpressIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class IntegrationFailedLogCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_failed_logs_can_be_cleared_without_deleting_other_sync_statuses(): void
    {
        $integration = $this->createIntegration();
        $administrator = $this->createUser(User::ROLE_ADMINISTRATOR, 'administrator@example.test');

        foreach (range(1, 25) as $index) {
            $this->createLog(
                $integration,
                $index === 25
                    ? 'export_product_data'
                    : ($index % 2 === 0 ? 'import_orders' : 'import_products'),
                'failed',
            );
        }

        foreach (['queued', 'running', 'success', 'pending'] as $status) {
            $this->createLog($integration, 'import_products', $status);
        }

        $this->actingAs($administrator)
            ->get(route('integrations.index', ['tab' => 'logs']))
            ->assertOk()
            ->assertSee('Wyczyść historię błędów (25)');

        $this->actingAs($administrator)
            ->delete(route('integrations.logs.failed.destroy'))
            ->assertRedirect(route('integrations.index').'#logs')
            ->assertSessionHas('status', 'Usunięto nieudane logi synchronizacji: 25.');

        $this->assertSame(0, IntegrationSyncLog::query()->where('status', 'failed')->count());
        $this->assertSame(1, IntegrationSyncLog::query()->where('status', 'queued')->count());
        $this->assertSame(1, IntegrationSyncLog::query()->where('status', 'running')->count());
        $this->assertSame(1, IntegrationSyncLog::query()->where('status', 'success')->count());
        $this->assertSame(1, IntegrationSyncLog::query()->where('status', 'pending')->count());

        $audit = AuditLog::query()
            ->where('action', 'integration_sync.failed_logs_cleared')
            ->firstOrFail();

        $this->assertNull($audit->auditable_type);
        $this->assertNull($audit->auditable_id);
        $this->assertSame('failed', $audit->before['status']);
        $this->assertSame(25, $audit->before['count']);
        $this->assertSame(25, $audit->after['deleted_count']);
        $this->assertSame(0, $audit->after['remaining_failed_count']);
        $this->assertSame([
            'export_product_data' => 1,
            'import_orders' => 12,
            'import_products' => 12,
        ], $audit->metadata['operation_counts']);
        $this->assertFalse($audit->metadata['sample_ids_truncated']);
        $this->assertCount(25, $audit->metadata['sample_ids']);
    }

    public function test_clearing_failed_logs_is_restricted_to_integration_administrators(): void
    {
        $integration = $this->createIntegration();
        $failedLog = $this->createLog($integration, 'import_products', 'failed');
        $operator = $this->createUser(User::ROLE_OPERATOR, 'operator@example.test');

        $this->actingAs($operator)
            ->delete(route('integrations.logs.failed.destroy'))
            ->assertForbidden();

        $this->assertDatabaseHas('integration_sync_logs', [
            'id' => $failedLog->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'integration_sync.failed_logs_cleared',
        ]);
    }

    public function test_clearing_when_there_are_no_failed_logs_is_a_safe_no_op(): void
    {
        $integration = $this->createIntegration();
        $successLog = $this->createLog($integration, 'import_orders', 'success');

        $this->get(route('integrations.index', ['tab' => 'logs']))
            ->assertOk()
            ->assertDontSee('Wyczyść historię błędów');

        $this->delete(route('integrations.logs.failed.destroy'))
            ->assertRedirect(route('integrations.index').'#logs')
            ->assertSessionHas('status', 'Brak błędów synchronizacji do usunięcia.');

        $this->assertDatabaseHas('integration_sync_logs', [
            'id' => $successLog->id,
            'status' => 'success',
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'integration_sync.failed_logs_cleared',
        ]);
    }

    private function createUser(string $role, string $email): User
    {
        return User::query()->create([
            'name' => $role === User::ROLE_ADMINISTRATOR ? 'Administrator ERP' : 'Operator ERP',
            'email' => $email,
            'password' => 'secret-password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    private function createIntegration(): WordpressIntegration
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Sempre Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);
    }

    private function createLog(
        WordpressIntegration $integration,
        string $operation,
        string $status,
    ): IntegrationSyncLog {
        return IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'in',
            'operation' => $operation,
            'status' => $status,
            'attempts' => 1,
            'error_message' => $status === 'failed' ? 'Testowy błąd synchronizacji' : null,
            'started_at' => now()->subMinute(),
            'finished_at' => in_array($status, ['success', 'failed'], true) ? now() : null,
        ]);
    }
}
