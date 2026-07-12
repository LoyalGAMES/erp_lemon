<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\ExternalOrder;
use App\Models\PrintJob;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Services\Printing\PrintBridgeTokenService;
use App\Services\Printing\ShippingLabelPrintQueueService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintBridgeWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
