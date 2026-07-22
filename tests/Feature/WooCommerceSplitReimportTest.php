<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WooCommerceSplitReimportTest extends TestCase
{
    use RefreshDatabase;

    public function test_reimport_keeps_the_remote_total_across_the_active_split_family_and_preserves_reversal_metadata(): void
    {
        [$integration, $rootOrder] = $this->integrationAndRootOrder('5101');
        $snapshot = [
            'total_gross' => '530.00',
            'lines' => [['external_line_id' => 'line-1', 'quantity' => 5]],
        ];
        $shippingDecision = [
            'decision' => 'ship_footwear_now',
            'decided_by' => 'Operator',
        ];

        $rootOrder->forceFill([
            'raw_payload' => [
                'sempre_erp_split_original' => $snapshot,
                'sempre_erp_shipping_decision' => $shippingDecision,
            ],
        ])->save();

        $firstChild = $this->splitChild($rootOrder, '5101-SPLIT-1', '125.35');
        $firstChild->lines()->create([
            'external_line_id' => 'line-1-S1',
            'canonical_external_line_id' => 'line-1',
            'name' => 'Produkt testowy',
            'quantity' => 1,
            'unit_gross_price' => 100,
        ]);

        $nestedChild = $this->splitChild(
            $rootOrder,
            '5101-SPLIT-1-SPLIT-1',
            '74.65',
            $firstChild,
        );
        $nestedChild->lines()->create([
            'external_line_id' => 'line-1-S1-S1',
            'canonical_external_line_id' => 'line-1',
            'name' => 'Produkt testowy',
            'quantity' => 1,
            'unit_gross_price' => 100,
        ]);

        $this->fakeOrderPages($this->remoteOrder('5101'));

        app(WooCommerceImportService::class)->importOrders($integration);

        $rootOrder->refresh()->load('lines');

        $this->assertSame('330.00', (string) $rootOrder->total_gross);
        $this->assertSame('3.0000', (string) $rootOrder->lines->sole()->quantity);
        $this->assertEqualsWithDelta(
            530.0,
            (float) $rootOrder->total_gross
                + (float) $firstChild->fresh()->total_gross
                + (float) $nestedChild->fresh()->total_gross,
            0.001,
        );
        $this->assertSame($snapshot, data_get($rootOrder->raw_payload, 'sempre_erp_split_original'));
        $this->assertSame($shippingDecision, data_get($rootOrder->raw_payload, 'sempre_erp_shipping_decision'));
        $this->assertCount(2, (array) data_get($rootOrder->raw_payload, 'sempre_erp_split_allocations'));
    }

    public function test_reimport_after_reversal_ignores_archived_children_and_restores_the_full_remote_total(): void
    {
        [$integration, $rootOrder] = $this->integrationAndRootOrder('5201');
        $child = $this->splitChild($rootOrder, '5201-SPLIT-1', '200.00');
        $child->lines()->create([
            'external_line_id' => 'line-1-S1',
            'canonical_external_line_id' => 'line-1',
            'name' => 'Produkt testowy',
            'quantity' => 2,
            'unit_gross_price' => 100,
        ]);

        $child->delete();
        $rootOrder->forceFill(['raw_payload' => ['source' => 'reverted-order']])->save();
        $this->fakeOrderPages($this->remoteOrder('5201'));

        app(WooCommerceImportService::class)->importOrders($integration);

        $rootOrder->refresh()->load('lines');

        $this->assertSame('530.00', (string) $rootOrder->total_gross);
        $this->assertSame('5.0000', (string) $rootOrder->lines->sole()->quantity);
        $this->assertArrayNotHasKey('sempre_erp_split_original', (array) $rootOrder->raw_payload);
        $this->assertArrayNotHasKey('sempre_erp_split_child_orders', (array) $rootOrder->raw_payload);
        $this->assertArrayNotHasKey('sempre_erp_split_allocations', (array) $rootOrder->raw_payload);
        $this->assertNotNull(ExternalOrder::withTrashed()->findOrFail($child->id)->deleted_at);
    }

    public function test_reimport_preserves_completed_picking_reset_marker_without_an_active_split(): void
    {
        [$integration, $rootOrder] = $this->integrationAndRootOrder('5251');
        $marker = [
            'version' => 1,
            'status' => 'completed',
            'request_uuid' => '11111111-2222-4333-8444-555555555555',
            'preserved_label_ids' => [83],
            'preserved_tracking_numbers' => ['523000013688150127510323'],
        ];
        $rootOrder->forceFill([
            'raw_payload' => [
                'source' => 'local-picking-reset',
                'sempre_erp_picking_reset' => $marker,
            ],
        ])->save();
        $this->fakeOrderPages($this->remoteOrder('5251'));

        app(WooCommerceImportService::class)->importOrders($integration);

        $raw = (array) $rootOrder->fresh()->raw_payload;
        $this->assertSame($marker, data_get($raw, 'sempre_erp_picking_reset'));
        $this->assertSame('5251', (string) ($raw['number'] ?? ''));
    }

    public function test_reimport_fails_without_changing_the_order_when_split_parts_exceed_the_remote_total(): void
    {
        [$integration, $rootOrder] = $this->integrationAndRootOrder('5301');
        $rootOrder->lines()->create([
            'external_line_id' => 'original-line',
            'canonical_external_line_id' => 'original-line',
            'name' => 'Pozycja sprzed importu',
            'quantity' => 1,
            'unit_gross_price' => 530,
        ]);
        $child = $this->splitChild($rootOrder, '5301-SPLIT-1', '530.01');
        $child->lines()->create([
            'external_line_id' => 'line-1-S1',
            'canonical_external_line_id' => 'line-1',
            'name' => 'Produkt testowy',
            'quantity' => 5,
            'unit_gross_price' => 106.002,
        ]);
        $this->fakeOrderPages($this->remoteOrder('5301'));

        try {
            app(WooCommerceImportService::class)->importOrders($integration);
            $this->fail('Import powinien zostać przerwany dla niespójnej sumy rodziny podziału.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('suma aktywnych części podziału (530.01)', $exception->getMessage());
            $this->assertStringContainsString('WooCommerce (530.00)', $exception->getMessage());
        }

        $rootOrder->refresh()->load('lines');

        $this->assertSame('530.00', (string) $rootOrder->total_gross);
        $this->assertSame('original-line', $rootOrder->lines->sole()->external_line_id);
    }

    public function test_payload_fetched_before_split_reversal_cannot_overwrite_the_restored_order_after_waiting_for_the_family_lock(): void
    {
        [$integration, $rootOrder] = $this->integrationAndRootOrder('5401');
        $rootOrder->forceFill([
            'status' => 'ready-to-ship',
            'external_updated_at' => now()->subHours(2),
            'raw_payload' => [
                'tracking_number' => 'STALE-TRACKING',
                'source' => 'state-before-reversal',
            ],
        ])->save();
        $rootOrder->lines()->create([
            'external_line_id' => 'restored-line',
            'canonical_external_line_id' => 'restored-line',
            'name' => 'Pozycja przywrócona',
            'quantity' => 1,
            'unit_gross_price' => 530,
        ]);
        $child = $this->splitChild($rootOrder, '5401-SPLIT-1', '100.00');
        $child->lines()->create([
            'external_line_id' => 'line-1-S1',
            'canonical_external_line_id' => 'line-1',
            'name' => 'Pozycja częściowa',
            'quantity' => 1,
            'unit_gross_price' => 100,
        ]);

        $stalePayload = $this->remoteOrder('5401');
        $stalePayload['status'] = 'ready-to-ship';
        $stalePayload['tracking_number'] = 'STALE-TRACKING';
        // Even a Woo clock ahead of ERP cannot bypass the local request/audit
        // ordering barrier when the reversal commits during this request.
        $stalePayload['date_modified_gmt'] = now()->addDay()->utc()->format('Y-m-d\TH:i:s');
        $currentPayload = $stalePayload;
        $reversalCommitted = false;

        Http::fake(function (Request $request) use (&$currentPayload, $rootOrder, $child, &$reversalCommitted) {
            if (str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/notes')) {
                return Http::response([]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if ((int) ($query['page'] ?? 1) !== 1) {
                return Http::response([]);
            }

            if (! $reversalCommitted) {
                // The response body above represents data captured by Woo before
                // reversal. Commit the local reversal while that response is in
                // flight, before the importer obtains the family mutation lock.
                $child->delete();
                $rootOrder->forceFill([
                    'status' => 'processing',
                    'external_updated_at' => now()->subHours(2),
                    'raw_payload' => ['source' => 'restored-after-reversal'],
                ])->save();
                AuditLog::query()->create([
                    'action' => 'order.split_reverted',
                    'auditable_type' => $rootOrder->getMorphClass(),
                    'auditable_id' => $rootOrder->id,
                    'before' => null,
                    'after' => ['status' => 'processing'],
                    'metadata' => ['test' => 'stale_payload_barrier'],
                ]);
                $reversalCommitted = true;
            }

            return Http::response([$currentPayload]);
        });

        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $rootOrder->refresh()->load('lines');

        $this->assertTrue($reversalCommitted);
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['lines']);
        $this->assertSame('processing', $rootOrder->status);
        $this->assertSame(['source' => 'restored-after-reversal'], $rootOrder->raw_payload);
        $this->assertSame('restored-line', $rootOrder->lines->sole()->external_line_id);
        $this->assertNotNull(ExternalOrder::withTrashed()->findOrFail($child->id)->deleted_at);

        // The barrier is scoped to the in-flight page. A payload fetched later
        // with a valid version must still be importable.
        $this->travel(2)->seconds();
        $freshPayload = $this->remoteOrder('5401');
        $freshPayload['status'] = 'on-hold';
        $freshPayload['date_modified_gmt'] = now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s');
        $currentPayload = $freshPayload;

        $freshStats = app(WooCommerceImportService::class)->importOrders($integration);

        $this->assertSame(1, $freshStats['updated']);
        $this->assertSame('on-hold', $rootOrder->fresh()->status);
    }

    public function test_payload_without_a_valid_remote_version_is_rejected_after_split_reversal(): void
    {
        [$integration, $rootOrder] = $this->integrationAndRootOrder('5501');
        $rootOrder->forceFill([
            'status' => 'processing',
            'raw_payload' => ['source' => 'restored-after-reversal'],
        ])->save();
        $rootOrder->lines()->create([
            'external_line_id' => 'restored-line',
            'canonical_external_line_id' => 'restored-line',
            'name' => 'Pozycja przywrócona',
            'quantity' => 5,
            'unit_gross_price' => 106,
        ]);
        AuditLog::query()->create([
            'action' => 'order.split_reverted',
            'auditable_type' => $rootOrder->getMorphClass(),
            'auditable_id' => $rootOrder->id,
            'before' => null,
            'after' => ['status' => 'processing'],
            'metadata' => ['test' => 'missing_remote_version'],
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $unversionedPayload = $this->remoteOrder('5501');
        $unversionedPayload['status'] = 'ready-to-ship';
        $this->fakeOrderPages($unversionedPayload);

        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $rootOrder->refresh()->load('lines');
        $this->assertSame(0, $stats['updated']);
        $this->assertSame(0, $stats['lines']);
        $this->assertSame('processing', $rootOrder->status);
        $this->assertSame(['source' => 'restored-after-reversal'], $rootOrder->raw_payload);
        $this->assertSame('restored-line', $rootOrder->lines->sole()->external_line_id);
    }

    public function test_fresh_import_filters_only_cancelled_shipment_identities_after_reversal(): void
    {
        [$integration, $rootOrder] = $this->integrationAndRootOrder('5601');
        $splitReversalMarker = [
            'operation_uuid' => '60000000-0000-4000-8000-000000000001',
            'cancelled_shipment_identities' => ['OLD-SHIPMENT', 'OLD-TRACKING'],
        ];
        $rootOrder->forceFill(['raw_payload' => ['source' => 'before-reversal-commit']])->save();

        $payload = $this->remoteOrder('5601');
        $payload['date_modified_gmt'] = now()->addMinute()->utc()->format('Y-m-d\TH:i:s');
        $payload['_inpost_shipment_id'] = 'OLD-SHIPMENT';
        $payload['tracking_number'] = 'NEW-TRACKING';
        $payload['meta_data'] = [
            ['key' => '_inpost_tracking_number', 'value' => 'OLD-TRACKING'],
            ['key' => '_inpost_tracking_number', 'value' => 'NEW-META-TRACKING'],
            ['key' => '_inpost_target_point', 'value' => 'OLD-SHIPMENT'],
        ];
        $payload['shipping_lines'] = [[
            'id' => 1,
            'meta_data' => [
                ['key' => '_blpaczka_shipment_id', 'value' => 'OLD-SHIPMENT'],
                ['key' => '_blpaczka_shipment_id', 'value' => 'NEW-SHIPMENT'],
            ],
        ]];
        $markerCommittedAfterInitialRead = false;

        Http::fake(function (Request $request) use (
            $payload,
            $rootOrder,
            $splitReversalMarker,
            &$markerCommittedAfterInitialRead,
        ) {
            if (str_contains((string) parse_url($request->url(), PHP_URL_PATH), '/notes')) {
                $freshRoot = $rootOrder->fresh();
                $freshRoot->forceFill(['raw_payload' => [
                    'source' => 'restored-after-reversal',
                    'sempre_erp_split_reversal' => $splitReversalMarker,
                ]])->save();
                $markerCommittedAfterInitialRead = true;

                return Http::response([]);
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response((int) ($query['page'] ?? 1) === 1 ? [$payload] : []);
        });

        $stats = app(WooCommerceImportService::class)->importOrders($integration);

        $raw = (array) $rootOrder->fresh()->raw_payload;
        $this->assertTrue($markerCommittedAfterInitialRead);
        $this->assertSame(1, $stats['updated']);
        $this->assertArrayNotHasKey('_inpost_shipment_id', $raw);
        $this->assertSame('NEW-TRACKING', $raw['tracking_number']);
        $this->assertSame(
            ['NEW-META-TRACKING', 'OLD-SHIPMENT'],
            collect((array) $raw['meta_data'])->pluck('value')->all(),
        );
        $this->assertSame(
            ['NEW-SHIPMENT'],
            collect((array) data_get($raw, 'shipping_lines.0.meta_data', []))->pluck('value')->all(),
        );
        $this->assertSame(
            ['OLD-SHIPMENT', 'OLD-TRACKING'],
            data_get($raw, 'sempre_erp_split_reversal.cancelled_shipment_identities'),
        );
    }

    /**
     * @return array{WordpressIntegration, ExternalOrder}
     */
    private function integrationAndRootOrder(string $externalId): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'SPLIT-'.$externalId,
            'name' => 'Woo split test',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => 'Woo split reimport',
            'base_url' => 'https://split-reimport.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => false,
        ]);
        $rootOrder = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'wordpress_integration_id' => $integration->id,
            'external_id' => $externalId,
            'external_number' => $externalId,
            'status' => 'pending',
            'currency' => 'PLN',
            'total_gross' => 530,
        ]);

        return [$integration, $rootOrder];
    }

    private function splitChild(
        ExternalOrder $rootOrder,
        string $externalId,
        string $totalGross,
        ?ExternalOrder $parentOrder = null,
    ): ExternalOrder {
        $parentOrder ??= $rootOrder;

        return ExternalOrder::query()->create([
            'split_parent_order_id' => $parentOrder->id,
            'split_root_order_id' => $rootOrder->id,
            'sales_channel_id' => $rootOrder->sales_channel_id,
            'wordpress_integration_id' => $rootOrder->wordpress_integration_id,
            'external_id' => $externalId,
            'external_number' => str_replace('-SPLIT-', '/S', $externalId),
            'status' => 'pending',
            'currency' => 'PLN',
            'total_gross' => $totalGross,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteOrder(string $externalId): array
    {
        return [
            'id' => (int) $externalId,
            'number' => $externalId,
            'status' => 'pending',
            'currency' => 'PLN',
            'total' => '530.00',
            'line_items' => [[
                'id' => 'line-1',
                'sku' => '',
                'name' => 'Produkt testowy',
                'quantity' => 5,
                'subtotal' => '500.00',
                'total' => '500.00',
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function fakeOrderPages(array $order): void
    {
        Http::fake(function (Request $request) use ($order) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return Http::response((int) ($query['page'] ?? 1) === 1 ? [$order] : []);
        });
    }
}
