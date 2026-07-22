<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\PackingTask;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Inventory\StockReservationService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Orders\OrderWzDocumentService;
use App\Services\Packing\PackedOrderPickingResetService;
use App\Services\Packing\PackingFulfillmentService;
use App\Services\Packing\PackingTaskService;
use App\Services\Printing\ShippingLabelPrintQueueService;
use App\Services\Shipping\CourierPickupTrackingService;
use App\Services\Shipping\ShippingCancellationService;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class PackedOrderPickingResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_reset_a_packed_order_to_picking_while_preserving_label_and_reservations(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $oldWz = $fixture['wz'];
        $label = $fixture['label'];
        $printJob = $fixture['print_job'];
        $oldWzKey = (string) $oldWz->order_fulfillment_key;
        $oldLabel = $label->only([
            'idempotency_key',
            'status',
            'provider',
            'label_number',
            'tracking_number',
            'tracking_status',
            'path',
            'sha256',
        ]);
        $oldPrintDeduplicationKey = (string) $printJob->deduplication_key;
        $preview = app(PackedOrderPickingResetService::class)->preview($order);

        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));
        $this->assertSame([$oldWz->id], $preview['plan']['wz_ids']);
        $this->assertSame([$label->id], $preview['plan']['preserved_label_ids']);
        $this->assertSame('1108.50', $preview['plan']['total_gross']);
        $this->assertSame(1108.50, $preview['plan']['cod_amount']);

        $this->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Cofnij do kompletacji')
            ->assertSee($label->tracking_number)
            ->assertSee('stan 7 → 8', false)
            ->assertSee('stan 1 → 2', false);

        $response = $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order));

        $response
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'wróciło do kompletacji'));

        $order->refresh();
        $label->refresh();
        $printJob->refresh();
        $tasks = PackingTask::query()->where('external_order_id', $order->id)->orderBy('id')->get();

        $this->assertSame('processing', $order->status);
        $this->assertSame('picking', $order->fulfillment_status);
        $this->assertSame('completed', data_get($order->raw_payload, 'sempre_erp_picking_reset.status'));
        $this->assertTrue(data_get($order->raw_payload, 'sempre_erp_picking_reset.preserve_existing_label'));
        $this->assertCount(2, $tasks);
        $this->assertTrue($tasks->every(fn (PackingTask $task): bool => $task->status === 'open'));
        $this->assertTrue($tasks->every(fn (PackingTask $task): bool => (float) $task->quantity_picked === 0.0));
        $this->assertTrue($tasks->every(fn (PackingTask $task): bool => $task->picked_at === null && $task->packed_at === null));
        $this->assertTrue($tasks->every(fn (PackingTask $task): bool => data_get($task->metadata, 'packing_completion') === null));
        $this->assertTrue($tasks->every(fn (PackingTask $task): bool => data_get($task->metadata, 'picking_reset_history.0.previous.status') === 'packed'));

        $archivedWz = WarehouseDocument::withTrashed()->findOrFail($oldWz->id);
        $this->assertSame('cancelled', $archivedWz->status);
        $this->assertNotNull($archivedWz->deleted_at);
        $this->assertStringStartsWith('packing-reset:', (string) $archivedWz->order_fulfillment_key);
        $this->assertSame($oldWzKey, data_get($archivedWz->metadata, 'packing_reset.original_order_fulfillment_key'));
        $this->assertSame(4, StockLedgerEntry::query()->where('warehouse_document_id', $oldWz->id)->count());
        $this->assertSame(0.0, (float) StockLedgerEntry::query()
            ->where('warehouse_document_id', $oldWz->id)
            ->sum('quantity_change'));

        $draft = WarehouseDocument::query()->with('lines')->sole();
        $this->assertNotSame($oldWz->id, $draft->id);
        $this->assertSame('draft', $draft->status);
        $this->assertSame($oldWzKey, $draft->order_fulfillment_key);
        $this->assertCount(2, $draft->lines);

        $activeReservations = StockReservation::query()
            ->where('external_order_id', $order->external_id)
            ->where('status', 'active')
            ->orderBy('product_id')
            ->get();
        $this->assertCount(2, $activeReservations);
        $this->assertTrue($activeReservations->every(fn (StockReservation $reservation): bool => (float) $reservation->quantity === 1.0));
        $this->assertTrue($activeReservations->every(fn (StockReservation $reservation): bool => (int) $reservation->warehouse_id === (int) $fixture['warehouse']->id));

        $this->assertBalance($fixture['warehouse'], $fixture['first_product'], 8, 1, 7);
        $this->assertBalance($fixture['warehouse'], $fixture['second_product'], 2, 1, 1);
        $this->assertSame($oldLabel, $label->only(array_keys($oldLabel)));
        $this->assertSame('cancelled', $printJob->status);
        $this->assertNotSame($oldPrintDeduplicationKey, $printJob->deduplication_key);
        $this->assertSame('pending', data_get($printJob->metadata, 'packing_reset.previous_status'));
        $this->assertNull($printJob->lease_token);
        $this->assertSame(1, ShippingLabel::query()->shipments()->where('external_order_id', $order->id)->count());
        $this->assertSame(0, CustomerMessage::query()->where('external_order_id', $order->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'packing.order_reset_to_picking')->count());
        Http::assertNothingSent();
        Mail::assertNothingQueued();

        $this->get(route('orders.show', $order->fresh()))
            ->assertOk()
            ->assertSee('Kompletacja')
            ->assertSee('Zamówienie jest ponownie w kompletacji')
            ->assertSee('Jej usunięcie oraz utworzenie drugiej przesyłki są zablokowane')
            ->assertDontSee('Usuń etykietę')
            ->assertDontSee('Generuj przesyłkę</button>', false);
    }

    public function test_repacking_reuses_the_preserved_label_and_can_create_a_fresh_print_job_and_wz(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $preview = app(PackedOrderPickingResetService::class)->preview($order);

        $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order))
            ->assertRedirect();

        $tasks = PackingTask::query()->where('external_order_id', $order->id)->orderBy('id')->get();
        app(PackingTaskService::class)->markPickedMany($tasks->pluck('id')->all());
        $tasks->each(fn (PackingTask $task) => app(PackingTaskService::class)->markPacked($task->fresh()));
        $reused = app(ShippingLabelService::class)->generateForOrder($order->fresh());
        $this->assertSame($fixture['label']->id, $reused->id);
        $this->assertSame(1, ShippingLabel::query()->shipments()->where('external_order_id', $order->id)->count());

        $freshPrintJob = app(ShippingLabelPrintQueueService::class)->enqueueForStation(
            $reused,
            [
                'code' => 'PACK-01',
                'name' => 'Pakowanie 1',
                'printer_name' => 'Zebra testowa',
                'segment' => 'all',
            ],
            'packing.order.packed',
        );
        $this->assertNotNull($freshPrintJob);
        $this->assertNotSame($fixture['print_job']->id, $freshPrintJob->id);
        $this->assertSame('pending', $freshPrintJob->status);

        $newWz = WarehouseDocument::query()->with('lines')->sole();
        app(WarehouseDocumentPostingService::class)->post($newWz);
        $this->assertSame('posted', $newWz->fresh()->status);
        $this->assertCount(2, $newWz->lines);
        $this->assertSame(1, ShippingLabel::query()->shipments()->where('external_order_id', $order->id)->count());
        Http::assertNothingSent();
    }

    public function test_direct_generation_is_blocked_after_reset_instead_of_creating_a_second_shipment(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $preview = app(PackedOrderPickingResetService::class)->preview($order);

        $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order))
            ->assertRedirect();

        $this->post(route('orders.label.generate', $order->fresh()), [])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Nie utworzono drugiej przesyłki'));

        $this->assertSame(1, ShippingLabel::query()->shipments()->where('external_order_id', $order->id)->count());
        Http::assertNothingSent();
    }

    public function test_service_layer_blocks_replacement_manual_registration_and_deletion_of_preserved_label(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $preview = app(PackedOrderPickingResetService::class)->preview($order);

        $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order))
            ->assertRedirect();

        foreach ([
            fn () => app(ShippingLabelService::class)->generateForOrder($order->fresh(), forceNew: true),
            fn () => app(ShippingLabelService::class)->registerManualShipment($order->fresh(), 'inpost', 'MANUAL-SECOND'),
            fn () => app(ShippingCancellationService::class)->deleteLabel($fixture['label']->fresh()),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Operacja na zachowanej etykiecie powinna zostać odrzucona.');
            } catch (\RuntimeException $exception) {
                $this->assertTrue(
                    str_contains($exception->getMessage(), 'zachowano etykietę')
                    || str_contains($exception->getMessage(), 'zachowana podczas cofnięcia'),
                    $exception->getMessage(),
                );
            }
        }

        $label = $fixture['label']->fresh();
        $this->assertNotNull($label);
        $this->assertSame('generated', $label->status);
        $this->assertSame(1, ShippingLabel::query()->shipments()->where('external_order_id', $order->id)->count());
        Http::assertNothingSent();
    }

    public function test_preserved_cod_label_is_not_reused_after_order_financials_change(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $preview = app(PackedOrderPickingResetService::class)->preview($order);

        $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order))
            ->assertRedirect();

        $order->update(['total_gross' => 999.99]);

        try {
            app(ShippingLabelService::class)->generateForOrder($order->fresh());
            $this->fail('Zachowana etykieta COD nie może być użyta po zmianie kwoty zamówienia.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('zmieniły się po zachowaniu etykiety', $exception->getMessage());
        }

        $tasks = PackingTask::query()->where('external_order_id', $order->id)->get();
        app(PackingTaskService::class)->markPickedMany($tasks->pluck('id')->all());

        try {
            app(PackingFulfillmentService::class)->completePackedOrder($order->fresh());
            $this->fail('Pakowanie nie może użyć zachowanej etykiety COD po zmianie kwoty.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('zmieniły się po zachowaniu etykiety', $exception->getMessage());
        }

        $this->assertSame(1, ShippingLabel::query()->shipments()->where('external_order_id', $order->id)->count());
        $this->assertSame(2, PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', 'picked')
            ->count());
        Http::assertNothingSent();
    }

    public function test_cod_classifier_requires_confirmation_for_provider_specific_cod_method(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $raw = (array) $order->raw_payload;
        $raw['payment_method'] = 'inpost_cod';
        $raw['payment_method_title'] = 'Płatność przy odbiorze';
        $order->update(['raw_payload' => $raw]);
        $preview = app(PackedOrderPickingResetService::class)->preview($order->fresh());

        $this->assertTrue($preview['plan']['cash_on_delivery']);
        try {
            app(PackedOrderPickingResetService::class)->reset(
                $order->fresh(),
                $preview['version'],
                (string) Str::uuid(),
                $order->external_number,
                'Test klasyfikacji COD.',
                true,
                true,
                false,
                User::query()->firstOrFail(),
            );
            $this->fail('Brak potwierdzenia COD powinien zablokować korektę.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Potwierdź kwotę pobrania', $exception->getMessage());
        }

        $this->assertSame('awaiting_courier', $order->fresh()->fulfillment_status);
        $this->assertSame('posted', $fixture['wz']->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_missing_or_conflicting_cod_evidence_blocks_reset(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $label = $fixture['label'];
        $label->update(['response_payload' => []]);

        $preview = app(PackedOrderPickingResetService::class)->preview($fixture['order']->fresh());
        $this->assertFalse($preview['available']);
        $this->assertStringContainsString('Brak wiarygodnego zapisu kwoty i waluty COD', implode(' ', $preview['reasons']));

        $label->update(['response_payload' => [
            'shipment' => ['cod' => ['amount' => '1000.00', 'currency' => 'PLN']],
            'generation' => ['request' => ['cod_amount' => '1108.50']],
        ]]);
        $preview = app(PackedOrderPickingResetService::class)->preview($fixture['order']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString('są ze sobą sprzeczne', implode(' ', $preview['reasons']));
        $this->assertSame('awaiting_courier', $fixture['order']->fresh()->fulfillment_status);
        $this->assertSame('posted', $fixture['wz']->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_reserved_print_job_is_suspended_before_returning_to_picking(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $fixture['print_job']->update([
            'status' => 'reserved',
            'reserved_by' => 'legacy-bridge',
            'reserved_station' => 'PACK-01',
            'reserved_at' => now(),
        ]);
        $preview = app(PackedOrderPickingResetService::class)->preview($fixture['order']->fresh());

        $this->assertContains($fixture['print_job']->id, $preview['plan']['suspendable_print_job_ids']);
        $this->post(route('orders.reset-to-picking', $fixture['order']), $this->payload($preview, $fixture['order']))
            ->assertRedirect()
            ->assertSessionHas('status');

        $job = $fixture['print_job']->fresh();
        $this->assertSame('cancelled', $job->status);
        $this->assertSame('reserved', data_get($job->metadata, 'packing_reset.previous_status'));
        $this->assertNull($job->reserved_by);
        $this->assertNull($job->reserved_at);
        Http::assertNothingSent();
    }

    public function test_active_printing_blocks_reset_and_preserved_label_cannot_be_requeued_before_repacking(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $fixture['print_job']->update([
            'status' => 'printing',
            'reserved_by' => 'print-bridge',
            'reserved_station' => 'PACK-01',
            'reserved_at' => now(),
            'lease_token' => Str::random(64),
        ]);
        $preview = app(PackedOrderPickingResetService::class)->preview($fixture['order']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString('właśnie drukowana', implode(' ', $preview['reasons']));
        $this->assertSame('posted', $fixture['wz']->fresh()->status);

        $fixture['print_job']->update([
            'status' => 'printed',
            'printed_at' => now(),
            'lease_token' => null,
        ]);
        $preview = app(PackedOrderPickingResetService::class)->preview($fixture['order']->fresh());
        $this->post(route('orders.reset-to-picking', $fixture['order']), $this->payload($preview, $fixture['order']))
            ->assertRedirect()
            ->assertSessionHas('status');

        try {
            app(ShippingLabelPrintQueueService::class)->requeueForStation(
                $fixture['label']->fresh(),
                [
                    'code' => 'PACK-01',
                    'name' => 'Pakowanie 1',
                    'printer_name' => 'Zebra testowa',
                    'segment' => 'all',
                ],
                'packing.manual_reprint',
                (string) Str::uuid(),
            );
            $this->fail('Reprint zachowanej etykiety powinien czekać na ponowne spakowanie.');
        } catch (ConflictHttpException $exception) {
            $this->assertStringContainsString('dopiero po ponownym spakowaniu', $exception->getMessage());
        }

        $this->assertSame(1, PrintJob::query()->where('shipping_label_id', $fixture['label']->id)->count());
        Http::assertNothingSent();
    }

    public function test_unexpected_extra_ledger_movement_blocks_reset(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $line = $fixture['wz']->lines->first();
        StockLedgerEntry::query()->create([
            'warehouse_document_id' => $fixture['wz']->id,
            'warehouse_document_line_id' => $line->id,
            'warehouse_id' => $fixture['warehouse']->id,
            'product_id' => $line->product_id,
            'quantity_change' => -1,
            'direction' => 'out',
            'posted_at' => now(),
            'metadata' => ['source' => 'unexpected-test-entry'],
        ]);

        $preview = app(PackedOrderPickingResetService::class)->preview($fixture['order']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString('zapisy magazynowe', implode(' ', $preview['reasons']));
        $this->assertSame('posted', $fixture['wz']->fresh()->status);
        $this->assertSame('awaiting_courier', $fixture['order']->fresh()->fulfillment_status);
        Http::assertNothingSent();
    }

    public function test_verified_archived_split_child_is_allowed_but_active_artifacts_or_deleted_invoice_block_reset(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $child = ExternalOrder::query()->create([
            'split_parent_order_id' => $order->id,
            'split_root_order_id' => $order->id,
            'sales_channel_id' => $order->sales_channel_id,
            'external_id' => '845095-SPLIT-1',
            'external_number' => '845095/S1',
            'status' => 'split-reverted',
            'currency' => 'PLN',
            'total_gross' => 0,
            'raw_payload' => [
                'sempre_erp_split_reversal' => ['root_order_id' => $order->id],
            ],
        ]);
        $child->delete();
        $childLabel = ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'archived-child-label',
            'status' => 'cancelled',
            'provider' => 'inpost',
            'label_number' => 'ARCHIVED-CHILD',
            'tracking_number' => '520000000000000000008450',
            'disk' => 'local',
            'path' => '',
            'response_payload' => [
                'cancellation' => [
                    'operation_uuid' => 'split-reversal-845095',
                    'remote' => ['status' => 'manual_required'],
                ],
            ],
            'generated_at' => now()->subHour(),
        ]);
        AuditLog::query()->create([
            'action' => 'order.split_reverted',
            'auditable_type' => ExternalOrder::class,
            'auditable_id' => $order->id,
            'after' => [
                'archived_child_order_ids' => [$child->id],
                'reversed_effects' => [
                    'manual_shipping_confirmation' => true,
                    'shipping' => [
                        'manual_required' => [['label_id' => $childLabel->id]],
                    ],
                ],
            ],
            'metadata' => ['split_reversal_uuid' => 'split-reversal-845095'],
        ]);

        $preview = app(PackedOrderPickingResetService::class)->preview($order->fresh());
        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));

        $childLabel->update(['status' => 'generated']);
        $preview = app(PackedOrderPickingResetService::class)->preview($order->fresh());
        $this->assertFalse($preview['available']);
        $this->assertStringContainsString('Historyczna część podziału', implode(' ', $preview['reasons']));

        $childLabel->update(['status' => 'cancelled']);
        $invoice = Invoice::query()->create([
            'number' => 'FV/845095/TEST',
            'type' => 'vat',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => [],
            'buyer_data' => [],
            'gross_total' => 1108.50,
            'issued_at' => now(),
        ]);
        $invoice->delete();
        $preview = app(PackedOrderPickingResetService::class)->preview($order->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString('faktura, proforma albo korekta', implode(' ', $preview['reasons']));
        Http::assertNothingSent();
    }

    public function test_courier_pickup_evidence_blocks_reset_without_any_partial_mutation(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $fixture['label']->update([
            'tracking_status' => 'delivered',
            'picked_up_at' => now(),
        ]);
        $preview = app(PackedOrderPickingResetService::class)->preview($order);

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString('odebrał przesyłkę', implode(' ', $preview['reasons']));

        $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('awaiting_courier', $order->fresh()->fulfillment_status);
        $this->assertSame('posted', $fixture['wz']->fresh()->status);
        $this->assertSame(2, PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', 'packed')
            ->count());
        $this->assertSame(0, StockReservation::query()
            ->where('external_order_id', $order->external_id)
            ->whereIn('status', ['active', 'waiting'])
            ->count());
        Http::assertNothingSent();
    }

    public function test_tracking_response_started_before_reset_cannot_mark_the_preserved_label_as_picked_up(): void
    {
        Mail::fake();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $trackingNumber = (string) $fixture['label']->tracking_number;

        Http::fake(function ($request) use ($order, $trackingNumber) {
            if (! str_ends_with($request->url(), '/v1/tracking/'.$trackingNumber)) {
                return Http::response([], 404);
            }

            $preview = app(PackedOrderPickingResetService::class)->preview($order->fresh());
            app(PackedOrderPickingResetService::class)->reset(
                $order->fresh(),
                $preview['version'],
                (string) Str::uuid(),
                $order->external_number,
                'Towary wróciły na półkę w trakcie sprawdzania przewoźnika.',
                true,
                true,
                true,
                User::query()->firstOrFail(),
            );

            return Http::response([
                'tracking_number' => $trackingNumber,
                'status' => 'collected_from_sender',
                'tracking_details' => [[
                    'status' => 'collected_from_sender',
                    'datetime' => now()->toISOString(),
                ]],
            ], 200);
        });

        $result = app(CourierPickupTrackingService::class)->trackPackedOrders(force: true);

        $this->assertSame(0, $result['picked_up']);
        $this->assertSame(0, $result['orders']);
        $this->assertSame('generated', $fixture['label']->fresh()->status);
        $this->assertSame('picking', $order->fresh()->fulfillment_status);
        $this->assertSame(2, PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', 'open')
            ->count());
        $this->assertSame(0, CustomerMessage::query()->where('external_order_id', $order->id)->count());
        Mail::assertNothingQueued();
    }

    public function test_stale_preview_and_non_administrator_are_rejected(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $preview = app(PackedOrderPickingResetService::class)->preview($order);
        $task = PackingTask::query()->where('external_order_id', $order->id)->firstOrFail();
        $task->update(['metadata' => array_merge((array) $task->metadata, ['changed_after_preview' => true])]);

        $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Stan zamówienia zmienił się'));
        $this->assertSame('posted', $fixture['wz']->fresh()->status);

        $operator = User::query()->create([
            'name' => 'Operator',
            'email' => 'operator-picking-reset@example.test',
            'password' => 'test-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->post(route('orders.reset-to-picking', $order), $this->payload(
                app(PackedOrderPickingResetService::class)->preview($order),
                $order,
            ))
            ->assertForbidden();
        $this->assertSame('posted', $fixture['wz']->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_source_stock_baseline_is_restored_without_exposing_reserved_goods(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder(withSourceBaseline: true);
        $order = $fixture['order'];
        $preview = app(PackedOrderPickingResetService::class)->preview($order);

        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));
        $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertBalance($fixture['warehouse'], $fixture['first_product'], 8, 1, 7);
        $this->assertBalance($fixture['warehouse'], $fixture['second_product'], 2, 1, 1);

        foreach ([$fixture['first_product'], $fixture['second_product']] as $product) {
            $balance = StockBalance::query()
                ->where('warehouse_id', $fixture['warehouse']->id)
                ->where('product_id', $product->id)
                ->firstOrFail();
            $this->assertSame($fixture['order']->sales_channel_id, $balance->source_sales_channel_id);
            $this->assertEqualsWithDelta(1.0, (float) data_get(
                $balance->source_reflected_order_quantities,
                $fixture['order']->external_id,
            ), 0.00001);
        }
        Http::assertNothingSent();
    }

    public function test_failure_after_wz_reversal_rolls_back_every_partial_change(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $otherWarehouse = Warehouse::query()->create([
            'code' => 'OTHER_B2C',
            'name' => 'Inny magazyn B2C',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $otherWarehouse->routes()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 1,
        ]);
        foreach ([$fixture['first_product'], $fixture['second_product']] as $product) {
            StockBalance::query()->create([
                'warehouse_id' => $otherWarehouse->id,
                'product_id' => $product->id,
                'quantity_on_hand' => 20,
                'quantity_reserved' => 0,
                'quantity_available' => 20,
            ]);
        }
        $preview = app(PackedOrderPickingResetService::class)->preview($order->fresh());
        $oldPrintDeduplicationKey = (string) $fixture['print_job']->deduplication_key;

        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));
        $this->post(route('orders.reset-to-picking', $order), $this->payload($preview, $order))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'innego magazynu'));

        $this->assertSame('awaiting_courier', $order->fresh()->fulfillment_status);
        $this->assertNull(data_get($order->fresh()->raw_payload, 'sempre_erp_picking_reset'));
        $this->assertSame('posted', $fixture['wz']->fresh()->status);
        $this->assertNull($fixture['wz']->fresh()->deleted_at);
        $this->assertSame(2, StockLedgerEntry::query()
            ->where('warehouse_document_id', $fixture['wz']->id)
            ->count());
        $this->assertSame(2, PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', 'packed')
            ->count());
        $this->assertSame('pending', $fixture['print_job']->fresh()->status);
        $this->assertSame($oldPrintDeduplicationKey, $fixture['print_job']->fresh()->deduplication_key);
        $this->assertSame(0, StockReservation::query()
            ->where('external_order_id', $order->external_id)
            ->whereIn('status', ['active', 'waiting'])
            ->count());
        $this->assertBalance($fixture['warehouse'], $fixture['first_product'], 7, 0, 7);
        $this->assertBalance($fixture['warehouse'], $fixture['second_product'], 1, 0, 1);
        $this->assertSame(0, AuditLog::query()->where('action', 'packing.order_reset_to_picking')->count());
        Http::assertNothingSent();
    }

    public function test_retry_with_the_same_request_uuid_is_idempotent(): void
    {
        Http::preventStrayRequests();
        $fixture = $this->packedCodOrder();
        $order = $fixture['order'];
        $preview = app(PackedOrderPickingResetService::class)->preview($order);
        $payload = $this->payload($preview, $order);

        $this->post(route('orders.reset-to-picking', $order), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');
        $ledgerCount = StockLedgerEntry::query()
            ->where('warehouse_document_id', $fixture['wz']->id)
            ->count();
        $reservationCount = StockReservation::query()
            ->where('external_order_id', $order->external_id)
            ->count();
        $draftCount = WarehouseDocument::query()->count();

        $this->post(route('orders.reset-to-picking', $order->fresh()), $payload)
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame($ledgerCount, StockLedgerEntry::query()
            ->where('warehouse_document_id', $fixture['wz']->id)
            ->count());
        $this->assertSame($reservationCount, StockReservation::query()
            ->where('external_order_id', $order->external_id)
            ->count());
        $this->assertSame($draftCount, WarehouseDocument::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'packing.order_reset_to_picking')->count());
        Http::assertNothingSent();
    }

    /** @return array<string,mixed> */
    private function packedCodOrder(bool $withSourceBaseline = false): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'PICKING-RESET',
            'name' => 'Reset do kompletacji',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'WC_B2C',
            'name' => 'Magazyn B2C',
            'type' => 'physical',
            'is_active' => true,
        ]);
        $warehouse->routes()->create([
            'sales_channel_id' => $channel->id,
            'push_stock' => true,
            'allocation_strategy' => 'warehouse_balance',
            'stock_buffer' => 0,
            'priority' => 100,
        ]);
        $firstProduct = $this->product($warehouse, 'SEM-00005459', 'Komplet MAREN Mleczna czekolada', 8);
        $secondProduct = $this->product($warehouse, 'SEM-00005440', 'Komplet ARDEN Czarny - S', 2);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '845095',
            'external_number' => '845095',
            'status' => 'processing',
            'fulfillment_status' => 'picking',
            'currency' => 'PLN',
            'total_gross' => 1108.50,
            'raw_payload' => [
                'id' => 845095,
                'number' => '845095',
                'total' => '1108.50',
                'payment_method' => 'cod',
                'payment_method_title' => 'Za pobraniem',
                'shipping_lines' => [['method_title' => 'InPost Kurier Standard Pobranie']],
            ],
            'external_created_at' => now()->subHours(3),
        ]);
        $firstLine = $order->lines()->create([
            'product_id' => $firstProduct->id,
            'external_line_id' => '59624',
            'sku' => $firstProduct->sku,
            'name' => $firstProduct->name,
            'quantity' => 1,
            'unit_gross_price' => 483.17,
        ]);
        $secondLine = $order->lines()->create([
            'product_id' => $secondProduct->id,
            'external_line_id' => '59625',
            'sku' => $secondProduct->sku,
            'name' => $secondProduct->name,
            'quantity' => 1,
            'unit_gross_price' => 397.80,
        ]);

        if ($withSourceBaseline) {
            foreach ([[$firstProduct, 7.0], [$secondProduct, 1.0]] as [$product, $sourceAvailable]) {
                StockBalance::query()
                    ->where('warehouse_id', $warehouse->id)
                    ->where('product_id', $product->id)
                    ->firstOrFail()
                    ->update([
                        'source_sales_channel_id' => $channel->id,
                        'source_available_quantity' => $sourceAvailable,
                        'source_observed_at' => now(),
                        'source_reflected_order_quantities' => [$order->external_id => 1],
                    ]);
            }
        }

        app(StockReservationService::class)->syncForOrder($order->fresh());
        $wz = collect(app(OrderWzDocumentService::class)->ensureDrafts($order->fresh()))->sole();
        app(WarehouseDocumentPostingService::class)->post($wz);
        $wz->refresh();

        foreach ([[$firstLine, $firstProduct], [$secondLine, $secondProduct]] as [$line, $product]) {
            PackingTask::query()->create([
                'sales_channel_id' => $channel->id,
                'external_order_id' => $order->id,
                'external_order_line_id' => $line->id,
                'product_id' => $product->id,
                'external_line_id' => $line->external_line_id,
                'order_number' => $order->external_number,
                'customer_name' => 'Anna Maćkowiak',
                'sku' => $product->sku,
                'product_name' => $product->name,
                'quantity_required' => 1,
                'quantity_picked' => 1,
                'status' => 'packed',
                'courier' => 'InPost Kurier Standard Pobranie',
                'size_label' => $product->id === $secondProduct->id ? 'S' : null,
                'order_date' => now()->subHours(3),
                'picked_at' => now()->subHours(2),
                'packed_at' => now()->subHour(),
                'metadata' => [
                    'packing_completion' => [
                        'wz_document_ids' => [$wz->id],
                        'completed_at' => now()->subHour()->toISOString(),
                    ],
                ],
            ]);
        }

        $label = ShippingLabel::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:order:'.$order->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => '2859722389',
            'tracking_number' => '523000013688150127510323',
            'tracking_status' => 'confirmed',
            'tracking_checked_at' => now(),
            'disk' => 'local',
            'path' => 'shipping-labels/845095.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => hash('sha256', 'label-845095'),
            'response_payload' => [
                'generation' => ['request' => ['cod_amount' => '1108.50']],
            ],
            'generated_at' => now()->subHour(),
        ]);
        $printJob = PrintJob::query()->create([
            'shipping_label_id' => $label->id,
            'deduplication_key' => hash('sha256', implode("\0", [$label->id, 'PACK-01', 'Zebra testowa'])),
            'status' => 'pending',
            'source' => 'packing.order.packed',
            'station_code' => 'PACK-01',
            'printer_name' => 'Zebra testowa',
            'format' => 'pdf',
            'attempts' => 0,
        ]);
        PackingTask::query()->where('external_order_id', $order->id)->get()->each(function (PackingTask $task) use ($label, $printJob): void {
            $metadata = (array) $task->metadata;
            $metadata['packing_completion']['label_id'] = $label->id;
            $metadata['packing_completion']['print_job_id'] = $printJob->id;
            $task->update(['metadata' => $metadata]);
        });
        $order->update(['fulfillment_status' => 'awaiting_courier']);

        return [
            'order' => $order->fresh(),
            'warehouse' => $warehouse,
            'first_product' => $firstProduct,
            'second_product' => $secondProduct,
            'wz' => $wz->fresh(['lines', 'ledgerEntries']),
            'label' => $label->fresh(),
            'print_job' => $printJob->fresh(),
        ];
    }

    private function product(Warehouse $warehouse, string $sku, string $name, float $stock): Product
    {
        $product = Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => $stock,
            'quantity_reserved' => 0,
            'quantity_available' => $stock,
        ]);

        return $product;
    }

    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function payload(array $preview, ExternalOrder $order): array
    {
        return [
            'expected_version' => $preview['version'],
            'request_uuid' => (string) Str::uuid(),
            'typed_order_number' => $order->external_number,
            'reason' => 'Towary wróciły na półkę; ponowna kompletacja całego zamówienia.',
            'confirm_goods_returned' => '1',
            'confirm_preserve_label' => '1',
            'confirm_cod_amount' => '1',
        ];
    }

    private function assertBalance(
        Warehouse $warehouse,
        Product $product,
        float $onHand,
        float $reserved,
        float $available,
    ): void {
        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta($onHand, (float) $balance->quantity_on_hand, 0.00001);
        $this->assertEqualsWithDelta($reserved, (float) $balance->quantity_reserved, 0.00001);
        $this->assertEqualsWithDelta($available, (float) $balance->quantity_available, 0.00001);
    }
}
