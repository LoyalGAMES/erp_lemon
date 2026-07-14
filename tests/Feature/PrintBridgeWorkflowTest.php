<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ExternalOrder;
use App\Models\PrintBridgeClient;
use App\Models\PrintJob;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Services\Packing\PackingSettingsService;
use App\Services\Printing\PrintBridgeTokenService;
use App\Services\Printing\ShippingLabelPrintQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintBridgeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_bridge_reports_service_visible_printers_and_settings_can_verify_mapping(): void
    {
        config(['erp.print_bridge_token' => 'bridge-secret']);
        app(PackingSettingsService::class)->update([
            'stations' => [[
                'code' => 'station-1',
                'name' => 'Stanowisko pakowania',
                'printer_name' => 'Zebra ZD421',
                'segment' => 'all',
            ]],
        ]);

        $this->postJson('/api/print-bridge/status', [
            'station' => 'Station-1',
            'worker' => 'PACK-PC-1',
            'version' => '2.1.0',
            'printers' => [
                ['name' => 'Microsoft Print to PDF', 'driver' => 'Microsoft Print To PDF', 'port' => 'PORTPROMPT:', 'default' => false],
                ['name' => 'Zebra ZD421', 'driver' => 'ZDesigner ZD421', 'port' => 'USB001', 'default' => true],
            ],
        ], ['Authorization' => 'Bearer bridge-secret'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $client = PrintBridgeClient::query()->sole();
        $this->assertSame('station-1', $client->station_code);
        $this->assertSame('PACK-PC-1', $client->worker_name);
        $this->assertSame('Zebra ZD421', $client->printers[0]['name']);

        $this->getJson(route('settings.packing.print-bridge.status'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('success', true)
            ->assertJsonPath('stations.station-1.connected', true)
            ->assertJsonPath('stations.station-1.status', 'online')
            ->assertJsonPath('stations.station-1.worker', 'PACK-PC-1')
            ->assertJsonPath('stations.station-1.version', '2.1.0')
            ->assertJsonPath('stations.station-1.mapped_printer', 'Zebra ZD421')
            ->assertJsonPath('stations.station-1.mapped_printer_available', true)
            ->assertJsonPath('stations.station-1.printers.0.name', 'Zebra ZD421');
    }

    public function test_bridge_status_distinguishes_stale_connection_and_missing_mapped_printer(): void
    {
        app(PackingSettingsService::class)->update([
            'stations' => [[
                'code' => 'station-1',
                'name' => 'Stanowisko pakowania',
                'printer_name' => 'Zebra Missing',
                'segment' => 'all',
            ]],
        ]);
        PrintBridgeClient::query()->create([
            'station_code' => 'station-1',
            'worker_name' => 'PACK-PC-1',
            'version' => '2.1.0',
            'printers' => [['name' => 'Zebra ZD421', 'driver' => '', 'port' => 'USB001', 'default' => true]],
            'last_seen_at' => now()->subMinutes(2),
        ]);

        $this->getJson(route('settings.packing.print-bridge.status', ['station' => 'station-1']))
            ->assertOk()
            ->assertJsonPath('stations.station-1.connected', false)
            ->assertJsonPath('stations.station-1.status', 'offline')
            ->assertJsonPath('stations.station-1.mapped_printer_available', false);
    }

    public function test_status_heartbeat_requires_the_shared_token_and_valid_inventory_payload(): void
    {
        config(['erp.print_bridge_token' => 'bridge-secret']);
        $payload = [
            'station' => 'station-1',
            'worker' => 'PACK-PC-1',
            'version' => '0.2.3',
            'printers' => [[
                'name' => 'Zebra ZD421',
                'driver' => 'ZDesigner',
                'port' => 'USB001',
                'default' => true,
            ]],
        ];

        $this->postJson('/api/print-bridge/status', $payload)
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
        $this->postJson('/api/print-bridge/status', $payload, [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();
        $this->assertDatabaseCount('print_bridge_clients', 0);

        $this->postJson('/api/print-bridge/status', [
            'station' => 'station-1',
            'worker' => 'PACK-PC-1',
        ], ['Authorization' => 'Bearer bridge-secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('printers');
        $this->assertDatabaseCount('print_bridge_clients', 0);

        $this->postJson('/api/print-bridge/status', array_replace($payload, [
            'station' => 'warehouse-typo',
        ]), ['Authorization' => 'Bearer bridge-secret'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('station');
        $this->getJson('/api/print-bridge/jobs/next?station=warehouse-typo&worker=PACK-PC-1', [
            'Authorization' => 'Bearer bridge-secret',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('station');
        $this->assertDatabaseCount('print_bridge_clients', 0);

        $this->postJson('/api/print-bridge/status', $payload, [
            'Authorization' => 'Bearer bridge-secret',
        ])->assertOk();
        $this->assertDatabaseHas('print_bridge_clients', [
            'station_code' => 'station-1',
            'worker_name' => 'PACK-PC-1',
            'version' => '0.2.3',
        ]);
    }

    public function test_settings_status_requires_an_admin_session_and_uses_exact_online_ttl(): void
    {
        $this->travelTo(Carbon::parse('2026-07-13 10:00:00'));
        app(PackingSettingsService::class)->update([
            'stations' => [[
                'code' => 'station-1',
                'name' => 'Stanowisko pakowania',
                'printer_name' => 'Zebra ZD421',
                'segment' => 'all',
            ]],
        ]);
        PrintBridgeClient::query()->create([
            'station_code' => 'station-1',
            'worker_name' => 'PACK-PC-1',
            'version' => '0.2.3',
            'printers' => [['name' => 'Zebra ZD421', 'driver' => '', 'port' => 'USB001', 'default' => true]],
            'last_seen_at' => now(),
        ]);

        $administrator = auth()->user();
        auth()->logout();
        $this->getJson(route('settings.packing.print-bridge.status'))
            ->assertUnauthorized();

        $operator = User::query()->create([
            'name' => 'Operator bez ustawień',
            'email' => 'operator-print-bridge@sempre.invalid',
            'password' => 'test-password-not-for-production',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);
        $this->actingAs($operator);
        $this->getJson(route('settings.packing.print-bridge.status'))
            ->assertForbidden();

        $this->actingAs($administrator);

        $this->travelTo(Carbon::parse('2026-07-13 10:01:30'));
        $this->getJson(route('settings.packing.print-bridge.status'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('stations.station-1.status', 'online')
            ->assertJsonPath('stations.station-1.connected', true);

        $this->travelTo(Carbon::parse('2026-07-13 10:01:31'));
        $this->getJson(route('settings.packing.print-bridge.status'))
            ->assertOk()
            ->assertJsonPath('stations.station-1.status', 'offline')
            ->assertJsonPath('stations.station-1.connected', false);
    }

    public function test_print_bridge_claims_downloads_and_marks_label_printed(): void
    {
        config(['erp.print_bridge_token' => 'bridge-secret']);

        $label = $this->createLabel();

        $job = app(ShippingLabelPrintQueueService::class)->enqueueForStation($label, [
            'code' => 'station-1',
            'name' => 'Stanowisko pakowania',
            'printer_name' => 'Zebra ZD421',
            'segment' => 'all',
        ], 'test');

        $this->assertInstanceOf(PrintJob::class, $job);

        $headers = ['Authorization' => 'Bearer bridge-secret'];

        $claim = $this->getJson('/api/print-bridge/jobs/next?station=station-1&worker=PACK-PC-1', $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.printer_name', 'Zebra ZD421')
            ->assertJsonPath('job.label.order_number', '9001');
        $leaseToken = (string) $claim->json('job.lease_token');
        $this->assertSame(64, strlen($leaseToken));

        $this->assertDatabaseHas('print_jobs', [
            'id' => $job->id,
            'status' => 'printing',
            'attempts' => 1,
            'reserved_by' => 'PACK-PC-1',
            'reserved_station' => 'station-1',
            'lease_token' => $leaseToken,
        ]);

        $leaseHeaders = array_merge($headers, [
            'X-Print-Lease' => $leaseToken,
            'X-Print-Worker' => 'PACK-PC-1',
            'X-Print-Station' => 'station-1',
        ]);

        $this->get('/api/print-bridge/jobs/'.$job->id.'/file', $leaseHeaders)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->postJson('/api/print-bridge/jobs/'.$job->id.'/printed', [
            'worker' => 'PACK-PC-1',
            'station' => 'station-1',
            'lease_token' => $leaseToken,
            'message' => 'Printed by test bridge',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('job.status', 'printed');

        // Lost HTTP responses are safe: the durable local journal can repeat
        // the same acknowledgement without physically printing a second time.
        $this->postJson('/api/print-bridge/jobs/'.$job->id.'/printed', [
            'worker' => 'PACK-PC-1',
            'station' => 'station-1',
            'lease_token' => $leaseToken,
            'message' => 'Repeated durable acknowledgement',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('job.status', 'printed');

        $this->assertDatabaseHas('print_jobs', [
            'id' => $job->id,
            'status' => 'printed',
            'reserved_by' => 'PACK-PC-1',
            'last_error' => null,
        ]);
    }

    public function test_print_bridge_requires_configured_token(): void
    {
        config(['erp.print_bridge_token' => 'bridge-secret']);

        $this->getJson('/api/print-bridge/jobs/next')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_print_bridge_has_a_stable_secure_fallback_token_visible_to_the_erp(): void
    {
        config([
            'erp.print_bridge_token' => '',
            'app.key' => 'base64:print-bridge-fallback-test-key',
        ]);

        $token = app(PrintBridgeTokenService::class)->token();

        $this->assertSame(64, strlen($token));
        $this->assertSame($token, app(PrintBridgeTokenService::class)->token());
        $this->getJson('/api/print-bridge/jobs/next?station=station-1&worker=PACK-PC-1', [
            'Authorization' => 'Bearer '.$token,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('job', null);
    }

    public function test_print_bridge_rejects_a_file_request_from_another_worker_or_lease(): void
    {
        config(['erp.print_bridge_token' => 'bridge-secret']);
        $label = $this->createLabel();
        $job = app(ShippingLabelPrintQueueService::class)->enqueueForStation($label, [
            'code' => 'station-1',
            'name' => 'Stanowisko pakowania',
            'printer_name' => 'Zebra ZD421',
            'segment' => 'all',
        ], 'test');
        $headers = ['Authorization' => 'Bearer bridge-secret'];
        $claim = $this->getJson('/api/print-bridge/jobs/next?station=station-1&worker=PACK-PC-1', $headers)
            ->assertOk();

        $this->get('/api/print-bridge/jobs/'.$job->id.'/file', array_merge($headers, [
            'X-Print-Lease' => str_repeat('a', 64),
            'X-Print-Worker' => 'OTHER-PC',
            'X-Print-Station' => 'station-1',
        ]))->assertConflict();

        $this->assertSame(64, strlen((string) $claim->json('job.lease_token')));
        $this->assertDatabaseHas('print_jobs', ['id' => $job->id, 'status' => 'printing']);
    }

    public function test_enqueuing_the_same_label_is_idempotent(): void
    {
        $label = $this->createLabel();
        $station = [
            'code' => 'station-1',
            'name' => 'Stanowisko pakowania',
            'printer_name' => 'Zebra ZD421',
            'segment' => 'all',
        ];
        $queue = app(ShippingLabelPrintQueueService::class);

        $first = $queue->enqueueForStation($label, $station, 'test');
        $second = $queue->enqueueForStation($label, $station, 'test-repeat');

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, PrintJob::query()->where('shipping_label_id', $label->id)->count());
        $this->assertSame(64, strlen((string) $first?->deduplication_key));
    }

    public function test_operator_can_requeue_a_printed_label_without_creating_a_duplicate_job(): void
    {
        $label = $this->createLabel();
        $station = [
            'code' => 'station-1',
            'name' => 'Stanowisko pakowania',
            'printer_name' => 'Zebra ZD421',
            'segment' => 'all',
        ];
        $queue = app(ShippingLabelPrintQueueService::class);
        $printed = $queue->enqueueForStation($label, $station, 'packing.order.packed');
        $oldLease = str_repeat('a', 64);
        $requestToken = '15f8cb67-7970-4e67-8db6-334b1411aa11';

        $printed?->forceFill([
            'status' => 'printed',
            'attempts' => 2,
            'reserved_by' => 'PACK-PC-1',
            'reserved_station' => 'station-1',
            'lease_token' => $oldLease,
            'printed_at' => now(),
            'last_error' => 'historyczny komunikat',
        ])->save();

        $requeued = $queue->requeueForStation($label, $station, 'packing.waiting.manual', $requestToken);
        $secondClick = $queue->requeueForStation($label, $station, 'packing.waiting.manual', $requestToken);

        $this->assertNotSame($printed?->id, $requeued?->id);
        $this->assertSame($requeued?->id, $secondClick?->id);
        $this->assertSame('pending', $requeued?->status);
        $this->assertSame('packing.waiting.manual', $requeued?->source);
        $this->assertSame(0, $requeued?->attempts);
        $this->assertNull($requeued?->printed_at);
        $this->assertNull($requeued?->last_error);
        $this->assertSame([$requestToken], data_get($requeued?->metadata, 'manual_request_tokens'));
        $this->assertSame('printed', $printed?->fresh()->status);
        $this->assertSame($oldLease, $printed?->fresh()->lease_token);
        $this->assertSame(2, PrintJob::query()->where('shipping_label_id', $label->id)->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'print_job.requeued',
            'auditable_type' => PrintJob::class,
            'auditable_id' => $requeued?->id,
        ]);

        $requeued?->forceFill([
            'status' => 'printed',
            'reserved_by' => 'PACK-PC-1',
            'reserved_station' => 'station-1',
            'lease_token' => str_repeat('b', 64),
            'printed_at' => now(),
        ])->save();

        $terminalRetry = $queue->requeueForStation($label, $station, 'packing.waiting.manual', $requestToken);

        $this->assertSame($requeued?->id, $terminalRetry?->id);
        $this->assertSame('printed', $terminalRetry?->status);
        $this->assertSame(2, PrintJob::query()->where('shipping_label_id', $label->id)->count());
    }

    public function test_manual_print_token_bound_to_an_active_job_cannot_create_a_later_duplicate(): void
    {
        $label = $this->createLabel();
        $station = [
            'code' => 'station-1',
            'name' => 'Stanowisko pakowania',
            'printer_name' => 'Zebra ZD421',
            'segment' => 'all',
        ];
        $requestToken = 'af458851-1fe1-4116-aec8-42353e3ec444';
        $queue = app(ShippingLabelPrintQueueService::class);
        $active = $queue->enqueueForStation($label, $station, 'packing.order.packed');
        $active?->forceFill([
            'status' => 'printing',
            'reserved_by' => 'PACK-PC-1',
            'reserved_station' => 'station-1',
            'reserved_at' => now(),
            'lease_token' => str_repeat('c', 64),
        ])->save();
        $staleAcknowledgementModel = $active?->fresh();

        $bound = $queue->requeueForStation($label, $station, 'packing.waiting.manual', $requestToken);

        $this->assertSame($active?->id, $bound?->id);
        $this->assertSame([$requestToken], data_get($bound?->metadata, 'manual_request_tokens'));

        $printed = $queue->markPrinted($staleAcknowledgementModel, 'PACK-PC-1');

        $lostResponseRetry = $queue->requeueForStation($label, $station, 'packing.waiting.manual', $requestToken);

        $this->assertSame($active?->id, $lostResponseRetry?->id);
        $this->assertSame([$requestToken], data_get($printed->metadata, 'manual_request_tokens'));
        $this->assertSame('printed', $lostResponseRetry?->status);
        $this->assertSame(1, PrintJob::query()->where('shipping_label_id', $label->id)->count());
    }

    public function test_legacy_listener_url_is_ignored_and_job_waits_for_outbound_bridge(): void
    {
        Http::fake();

        $label = $this->createLabel();

        $job = app(ShippingLabelPrintQueueService::class)->enqueueForStation($label, [
            'code' => 'station-1',
            'name' => 'Stanowisko pakowania',
            'printer_name' => 'Zebra ZD421',
            'listener_url' => 'http://192.168.1.25:17777',
            'segment' => 'all',
        ], 'test');

        $this->assertInstanceOf(PrintJob::class, $job);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->reserved_by);
        $this->assertArrayNotHasKey('listener_url', $job->getAttributes());
        $this->assertSame(0, $job->attempts);
        $this->assertArrayNotHasKey('listener_url', app(ShippingLabelPrintQueueService::class)->apiPayload($job));
        Http::assertNothingSent();
    }

    public function test_existing_direct_listener_reservation_is_released_for_outbound_bridge(): void
    {
        $label = $this->createLabel();
        $legacy = PrintJob::query()->create([
            'shipping_label_id' => $label->id,
            'status' => 'printing',
            'source' => 'legacy',
            'station_code' => 'station-1',
            'printer_name' => 'Zebra ZD421',
            'format' => 'pdf',
            'attempts' => 1,
            'reserved_by' => 'direct-listener',
            'reserved_at' => now(),
        ]);

        $job = app(ShippingLabelPrintQueueService::class)->enqueueForStation($label, [
            'code' => 'station-1',
            'name' => 'Stanowisko pakowania',
            'printer_name' => 'Zebra ZD421',
            'segment' => 'all',
        ], 'test');

        $this->assertSame($legacy->id, $job?->id);
        $this->assertSame('pending', $job?->status);
        $this->assertArrayNotHasKey('listener_url', $job?->getAttributes() ?? []);
        $this->assertNull($job?->reserved_by);
        $this->assertNull($job?->reserved_at);
        $this->assertSame(0, $job?->attempts);
    }

    public function test_legacy_listener_data_migration_clears_urls_and_releases_jobs(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->string('listener_url', 180)->nullable();
        });

        $label = $this->createLabel();
        $legacy = PrintJob::query()->create([
            'shipping_label_id' => $label->id,
            'status' => 'printing',
            'source' => 'legacy',
            'station_code' => 'station-1',
            'printer_name' => 'Zebra ZD421',
            'format' => 'pdf',
            'attempts' => 1,
            'reserved_by' => 'direct-listener',
            'reserved_at' => now(),
        ]);
        $legacy->forceFill(['listener_url' => 'http://192.168.1.25:17777'])->save();

        AppSetting::query()->create([
            'key' => 'packing_settings',
            'value' => [
                'stations' => [[
                    'code' => 'station-1',
                    'name' => 'Stanowisko',
                    'printer_name' => 'Zebra ZD421',
                    'listener_url' => 'http://192.168.1.25:17777',
                    'segment' => 'all',
                ]],
            ],
        ]);

        $migration = require database_path('migrations/2026_07_12_000010_disable_legacy_direct_print_listener.php');
        $migration->up();

        $legacy->refresh();
        $this->assertSame('pending', $legacy->status);
        $this->assertFalse(Schema::hasColumn('print_jobs', 'listener_url'));
        $this->assertNull($legacy->reserved_by);
        $this->assertNull($legacy->reserved_at);
        $this->assertSame(0, $legacy->attempts);

        $settings = AppSetting::query()->where('key', 'packing_settings')->firstOrFail()->value;
        $this->assertArrayNotHasKey('listener_url', $settings['stations'][0]);
    }

    public function test_lease_migration_consolidates_duplicates_and_quarantines_ambiguous_inflight_jobs(): void
    {
        $migration = require database_path('migrations/2026_07_12_000011_harden_print_bridge_leases.php');
        $migration->down();

        $label = $this->createLabel();
        $printed = PrintJob::query()->create([
            'shipping_label_id' => $label->id,
            'status' => 'printed',
            'source' => 'legacy',
            'station_code' => 'station-1',
            'printer_name' => 'Zebra ZD421',
            'format' => 'pdf',
            'printed_at' => now()->subMinute(),
        ]);
        $duplicatePending = PrintJob::query()->create([
            'shipping_label_id' => $label->id,
            'status' => 'pending',
            'source' => 'legacy',
            'station_code' => 'station-1',
            'printer_name' => 'Zebra ZD421',
            'format' => 'pdf',
        ]);
        $inflight = PrintJob::query()->create([
            'shipping_label_id' => $label->id,
            'status' => 'printing',
            'source' => 'legacy',
            'station_code' => 'station-1',
            'printer_name' => 'Zebra ZD620',
            'format' => 'pdf',
            'reserved_by' => 'OLD-PC',
            'reserved_at' => now(),
        ]);

        $migration->up();

        $natural = hash('sha256', implode("\0", [$label->id, 'station-1', 'Zebra ZD421']));
        $this->assertSame($natural, $printed->fresh()->deduplication_key);
        $this->assertSame('failed', $duplicatePending->fresh()->status);
        $this->assertStringContainsString('zduplikowany', (string) $duplicatePending->fresh()->last_error);
        $this->assertSame('failed', $inflight->fresh()->status);
        $this->assertNull($inflight->fresh()->reserved_by);
        $this->assertStringContainsString('ręcznej weryfikacji', (string) $inflight->fresh()->last_error);
    }

    private function createLabel(): ShippingLabel
    {
        Storage::disk('local')->put('shipping-labels/test-bridge.pdf', '%PDF-1.4 test-label');

        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001',
            'external_number' => '9001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 99,
        ]);

        return ShippingLabel::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'tracking_number' => '520000000000000000000001',
            'disk' => 'local',
            'path' => 'shipping-labels/test-bridge.pdf',
            'mime_type' => 'application/pdf',
            'size' => 19,
            'sha256' => hash('sha256', '%PDF-1.4 test-label'),
            'generated_at' => now(),
        ]);
    }
}
