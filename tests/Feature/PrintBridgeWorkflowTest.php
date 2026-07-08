<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\PrintJob;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Services\Printing\ShippingLabelPrintQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        $this->getJson('/api/print-bridge/jobs/next?station=station-1&worker=PACK-PC-1', $headers)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.printer_name', 'Zebra ZD421')
            ->assertJsonPath('job.label.order_number', '9001');

        $this->assertDatabaseHas('print_jobs', [
            'id' => $job->id,
            'status' => 'printing',
            'attempts' => 1,
            'reserved_by' => 'PACK-PC-1',
        ]);

        $this->get('/api/print-bridge/jobs/'.$job->id.'/file', $headers)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->postJson('/api/print-bridge/jobs/'.$job->id.'/printed', [
            'worker' => 'PACK-PC-1',
            'message' => 'Printed by test bridge',
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

    public function test_label_can_be_pushed_directly_to_windows_listener(): void
    {
        Http::fake([
            'http://192.168.1.25:17777/print' => Http::response(['success' => true, 'message' => 'printed'], 200),
        ]);

        $label = $this->createLabel();

        $job = app(ShippingLabelPrintQueueService::class)->enqueueForStation($label, [
            'code' => 'station-1',
            'name' => 'Stanowisko pakowania',
            'printer_name' => 'Zebra ZD421',
            'listener_url' => 'http://192.168.1.25:17777',
            'segment' => 'all',
        ], 'test');

        $this->assertInstanceOf(PrintJob::class, $job);
        $this->assertSame('printed', $job->status);
        $this->assertSame('direct-listener', $job->reserved_by);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://192.168.1.25:17777/print'
                && data_get($request->data(), 'printer_name') === 'Zebra ZD421'
                && data_get($request->data(), 'filename') === 'test-bridge.pdf'
                && base64_decode((string) data_get($request->data(), 'content_base64'), true) === '%PDF-1.4 test-label';
        });
    }

    public function test_erp_can_fetch_printer_list_from_windows_listener(): void
    {
        Http::fake([
            'http://192.168.1.25:17777/printers' => Http::response([
                'success' => true,
                'printers' => [
                    ['name' => 'Zebra ZD421', 'driver' => 'ZDesigner ZD421-203dpi ZPL', 'port' => 'USB001', 'default' => true],
                    ['name' => 'Microsoft Print to PDF', 'driver' => 'Microsoft Print To PDF', 'port' => 'PORTPROMPT:', 'default' => false],
                ],
            ], 200),
        ]);

        $this->postJson(route('packing.listener.printers'), [
            'listener_url' => 'http://192.168.1.25:17777',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('printers.0.name', 'Zebra ZD421')
            ->assertJsonPath('printers.0.default', true);

        Http::assertSent(fn ($request): bool => $request->url() === 'http://192.168.1.25:17777/printers');
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
