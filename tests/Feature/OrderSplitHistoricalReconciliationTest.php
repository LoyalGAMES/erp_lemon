<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureErpRole;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Orders\HistoricalSplitReconciliationService;
use App\Services\Orders\HistoricalSplitSnapshot;
use App\Services\Orders\OrderSplitReversalService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderSplitHistoricalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_adopt_and_reverse_the_verified_845095_history_without_reversing_pre_split_work(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $this->actingAs(User::query()->where('role', User::ROLE_ADMINISTRATOR)->firstOrFail());
        $fixture = $this->legacy845095Family();
        $root = $fixture['root'];
        $child = $fixture['child'];
        $preservedWz = $fixture['preserved_wz'];
        $duplicateWz = $fixture['duplicate_wz'];
        $preservedLabel = $fixture['preserved_label'];
        $childLabel = $fixture['child_label'];
        $rootTasks = $fixture['root_tasks'];
        $childTask = $fixture['child_task'];
        $duplicatedBalance = $fixture['duplicated_balance'];
        $otherBalance = $fixture['other_balance'];

        $preservedWzFingerprint = HistoricalSplitSnapshot::warehouseDocumentFingerprint(
            $preservedWz->load(['lines', 'ledgerEntries']),
        );
        $preservedLabelFingerprint = HistoricalSplitSnapshot::shippingLabelFingerprint($preservedLabel);
        $taskBaselines = collect($rootTasks)->mapWithKeys(fn (PackingTask $task): array => [
            $task->id => [
                'status' => $task->status,
                'quantity_picked' => (string) $task->quantity_picked,
                'picked_at' => $task->picked_at?->toISOString(),
                'packed_at' => $task->packed_at?->toISOString(),
            ],
        ]);

        $raw = (array) $child->raw_payload;
        $calculatedWooTotal = collect((array) $raw['line_items'])
            ->sum(fn (array $line): float => (float) $line['total'] + (float) $line['total_tax'])
            + (float) $raw['shipping_total']
            + (float) $raw['shipping_tax'];
        $this->assertSame(1108.50, round($calculatedWooTotal, 2));
        $this->assertSame('1108.50', (string) $raw['total']);
        $this->assertSame('377.56', (string) $raw['discount_total']);
        $this->assertSame('86.84', (string) $raw['discount_tax']);
        $this->assertSame('24.90', (string) $raw['shipping_total']);
        $this->assertSame('0.00', (string) $raw['shipping_tax']);
        $this->assertSame('202.63', (string) $raw['total_tax']);
        $this->assertSame(
            collect($raw['line_items'])->mapWithKeys(fn (array $line): array => [
                (string) $line['sku'] => (float) $line['quantity'],
            ])->sortKeys()->all(),
            $preservedWz->lines->mapWithKeys(fn ($line): array => [
                (string) $line->product->sku => (float) $line->quantity,
            ])->sortKeys()->all(),
        );

        $reconciliation = app(HistoricalSplitReconciliationService::class);
        $preview = $reconciliation->preview($child);

        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));
        $this->assertSame('1108.50', $preview['plan']['restored_total_gross']);
        $this->assertEqualsCanonicalizing(
            collect($rootTasks)->pluck('id')->all(),
            $preview['plan']['preserve_task_ids'],
        );
        $this->assertSame([$preservedWz->id], $preview['plan']['preserve_wz_ids']);
        $this->assertSame([$preservedLabel->id], $preview['plan']['preserve_label_ids']);
        $this->assertSame([$childTask->id], $preview['plan']['reverse_task_ids']);
        $this->assertSame([$duplicateWz->id], $preview['plan']['reverse_wz_ids']);
        $this->assertSame([$childLabel->id], $preview['plan']['reverse_label_ids']);
        $this->assertSame([$child->id], $preview['plan']['archive_child_order_ids']);
        $this->assertSame('1', $preview['plan']['balance_changes'][0]['change']);
        $this->assertSame('0.0000', $preview['plan']['balance_changes'][0]['quantity_on_hand_before']);
        $this->assertSame('1', $preview['plan']['balance_changes'][0]['quantity_on_hand_after']);

        $adoptionResponse = $this->from(route('orders.show', $root))->post(
            route('orders.split.historical-reconciliation', $child),
            $this->adoptionPayload($preview, $root),
        );
        $this->assertNull(session('error'), (string) session('error'));
        $adoptionResponse->assertRedirect(route('orders.show', $root))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $snapshot = data_get($root->fresh()->raw_payload, 'sempre_erp_split_original');
        $this->assertIsArray($snapshot);
        $this->assertTrue(HistoricalSplitSnapshot::isVerified($snapshot));
        $this->assertSame('1108.50', $snapshot['total_gross']);
        $this->assertSame('1108.50', data_get($snapshot, 'raw_payload.total'));
        $this->assertSame(
            User::ROLE_ADMINISTRATOR,
            data_get($snapshot, 'legacy_adoption.adopted_by_role'),
        );

        $availability = app(OrderSplitReversalService::class)->availability($child->fresh());
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));
        $this->assertFalse($availability['shipping_confirmation_required']);

        $reversalResponse = $this->from(route('orders.show', $root))->delete(route('orders.split.reverse', $child), [
            'family_version' => $availability['version'],
            'note' => 'Uzgodnione cofnięcie historycznego podziału zamówienia 845095.',
        ]);
        $this->assertNull(session('error'), (string) session('error'));
        $reversalResponse->assertRedirect(route('orders.show', $root))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $restored = $root->fresh('lines');
        $this->assertSame('1108.50', (string) $restored->total_gross);
        $this->assertSame('processing', $restored->status);
        $this->assertSame('awaiting_courier', $restored->fulfillment_status);
        $this->assertCount(2, $restored->lines);
        $this->assertSame('1108.50', (string) data_get($restored->raw_payload, 'total'));
        $this->assertSame('377.56', (string) data_get($restored->raw_payload, 'discount_total'));
        $this->assertSame('202.63', (string) data_get($restored->raw_payload, 'total_tax'));
        $this->assertSame('24.90', (string) data_get($restored->raw_payload, 'shipping_total'));
        $this->assertSame('0.00', (string) data_get($restored->raw_payload, 'shipping_tax'));
        $this->assertSame(
            '523000013688150127510323',
            data_get($restored->raw_payload, 'inpost_shipment_id'),
        );
        $this->assertArrayNotHasKey('inpost_tracking_number', (array) $restored->raw_payload);
        $restoredShipmentMeta = collect((array) data_get($restored->raw_payload, 'meta_data'))
            ->pluck('value')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
        $this->assertContains('523000013688150127510323', $restoredShipmentMeta);
        $this->assertNotContains('523000013688150127519999', $restoredShipmentMeta);
        $this->assertSame(
            ['523000013688150127519999'],
            data_get($restored->raw_payload, 'sempre_erp_split_reversal.cancelled_shipment_identities'),
        );
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);

        foreach ($rootTasks as $task) {
            $task->refresh();
            $baseline = $taskBaselines->get($task->id);
            $this->assertSame($root->id, $task->external_order_id);
            $this->assertSame($baseline['status'], $task->status);
            $this->assertSame($baseline['quantity_picked'], (string) $task->quantity_picked);
            $this->assertSame($baseline['picked_at'], $task->picked_at?->toISOString());
            $this->assertSame($baseline['packed_at'], $task->packed_at?->toISOString());
            $this->assertNotNull($task->external_order_line_id);
            $this->assertSame(
                (string) $task->external_line_id,
                (string) $task->orderLine?->canonical_external_line_id,
            );
            $this->assertTrue((bool) data_get($task->metadata, 'split_reversal.historical_baseline_preserved'));
        }

        $childTask->refresh();
        $this->assertSame('cancelled', $childTask->status);
        $this->assertSame('0.0000', (string) $childTask->quantity_picked);
        $this->assertNull($childTask->picked_at);
        $this->assertNull($childTask->packed_at);

        $preservedWz->refresh()->load(['lines', 'ledgerEntries']);
        $this->assertSame('posted', $preservedWz->status);
        $this->assertNull($preservedWz->deleted_at);
        $this->assertSame(
            $preservedWzFingerprint,
            HistoricalSplitSnapshot::warehouseDocumentFingerprint($preservedWz),
        );
        $this->assertSame(-2.0, (float) $preservedWz->ledgerEntries->sum('quantity_change'));

        $archivedDuplicateWz = WarehouseDocument::withTrashed()
            ->with('ledgerEntries')
            ->findOrFail($duplicateWz->id);
        $this->assertSame('cancelled', $archivedDuplicateWz->status);
        $this->assertNotNull($archivedDuplicateWz->deleted_at);
        $this->assertStringStartsWith('split-reverted:', (string) $archivedDuplicateWz->order_fulfillment_key);
        $this->assertCount(2, $archivedDuplicateWz->ledgerEntries);
        $this->assertSame(0.0, (float) $archivedDuplicateWz->ledgerEntries->sum('quantity_change'));

        $preservedLabel->refresh();
        $this->assertSame('generated', $preservedLabel->status);
        $this->assertSame(
            $preservedLabelFingerprint,
            HistoricalSplitSnapshot::shippingLabelFingerprint($preservedLabel),
        );
        $childLabel->refresh();
        $this->assertSame('cancelled', $childLabel->status);
        $this->assertStringStartsWith('split-reverted:', (string) $childLabel->idempotency_key);
        $this->assertNotNull(data_get($childLabel->response_payload, 'split_reversal.operation_uuid'));

        $this->assertSame('1.0000', (string) $duplicatedBalance->fresh()->quantity_on_hand);
        $this->assertSame('7.0000', (string) $otherBalance->fresh()->quantity_on_hand);
        $this->assertSame(1.0, (float) $duplicatedBalance->fresh()->quantity_on_hand);
        $this->assertSame(0, CustomerMessage::query()->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_non_administrator_cannot_adopt_a_historical_baseline(): void
    {
        $fixture = $this->legacy845095Family();
        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']);
        $operator = User::query()->create([
            'name' => 'Operator testowy',
            'email' => 'operator-historyczny@example.test',
            'password' => 'test-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->post(
                route('orders.split.historical-reconciliation', $fixture['child']),
                $this->adoptionPayload($preview, $fixture['root']),
            )->assertForbidden();

        $this->assertNull(data_get(
            $fixture['root']->fresh()->raw_payload,
            'sempre_erp_split_original',
        ));
        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('posted', $fixture['duplicate_wz']->fresh()->status);
        $this->assertSame('0.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);
    }

    public function test_non_administrator_cannot_execute_an_adopted_historical_reversal(): void
    {
        $fixture = $this->legacy845095Family();
        $administrator = auth()->user();
        $this->assertInstanceOf(User::class, $administrator);
        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']);
        app(HistoricalSplitReconciliationService::class)->adopt(
            $fixture['child'],
            $administrator,
            $preview['version'],
            $preview['plan_digest'],
            (string) Str::uuid(),
            $fixture['root']->external_number,
            'Administrator zatwierdził historyczny plan przed testem uprawnień.',
            [
                'carrier_not_handed_over' => true,
                'package_matches_preserved_wz' => true,
                'duplicate_items_returned' => true,
                'financial_total_verified' => true,
            ],
        );
        $operator = User::query()->create([
            'name' => 'Operator bez prawa cofnięcia',
            'email' => 'operator-history-reversal@example.test',
            'password' => 'test-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);
        $availability = app(OrderSplitReversalService::class)->availability($fixture['child']->fresh());

        $this->actingAs($operator)
            ->delete(route('orders.split.reverse', $fixture['child']), [
                'family_version' => $availability['version'],
                'note' => 'Operator nie może wykonać tego cofnięcia.',
            ])->assertForbidden();

        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('posted', $fixture['duplicate_wz']->fresh()->status);
        $this->assertSame('0.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);
    }

    public function test_adoption_rejects_a_stale_or_tampered_preview_without_partial_reversal(): void
    {
        $this->actingAs(User::query()->where('role', User::ROLE_ADMINISTRATOR)->firstOrFail());
        $fixture = $this->legacy845095Family();
        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']);
        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));

        $fixture['child_task']->update([
            'status' => 'open',
            'quantity_picked' => 0,
            'picked_at' => null,
        ]);
        $payload = $this->adoptionPayload($preview, $fixture['root']);
        $payload['plan_digest'] = str_repeat('0', 64);

        $this->from(route('orders.show', $fixture['root']))
            ->post(route('orders.split.historical-reconciliation', $fixture['child']), $payload)
            ->assertRedirect(route('orders.show', $fixture['root']))
            ->assertSessionHas('error', fn (string $message): bool => str_contains(
                $message,
                'Dane rodziny zmieniły się',
            ));

        $this->assertNull(data_get(
            $fixture['root']->fresh()->raw_payload,
            'sempre_erp_split_original',
        ));
        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('posted', $fixture['duplicate_wz']->fresh()->status);
        $this->assertNull($fixture['duplicate_wz']->fresh()->deleted_at);
        $this->assertSame('0.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);
        $this->assertSame('cancelled', $fixture['child_label']->fresh()->status);
    }

    public function test_preview_rejects_a_source_total_and_tax_that_do_not_reconcile_to_woo_lines(): void
    {
        $fixture = $this->legacy845095Family();

        foreach ([$fixture['root'], $fixture['child']] as $order) {
            $raw = (array) $order->raw_payload;
            $raw['total'] = '1109.50';
            $raw['total_tax'] = '203.63';
            $order->update(['raw_payload' => $raw]);
        }

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString(
            'nie zgadza się z sumą pozycji, podatku, wysyłki i opłat',
            implode(' ', $preview['reasons']),
        );
        $this->assertStringContainsString(
            'Łączny podatek',
            implode(' ', $preview['reasons']),
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
    }

    public function test_preview_rejects_a_preserved_wz_that_does_not_cover_the_original_order_exactly(): void
    {
        $fixture = $this->legacy845095Family();
        $document = $fixture['preserved_wz']->fresh(['lines', 'ledgerEntries']);
        $line = $document->lines->firstOrFail();
        $entry = $document->ledgerEntries
            ->firstWhere('warehouse_document_line_id', $line->id);
        $this->assertInstanceOf(StockLedgerEntry::class, $entry);
        $line->update(['quantity' => 2]);
        $entry->update(['quantity_change' => -2]);

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString(
            'Zachowywany dokument WZ nie pokrywa dokładnie produktów i ilości pierwotnego zamówienia',
            implode(' ', $preview['reasons']),
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
    }

    public function test_preview_rejects_a_preserved_posted_wz_without_a_posted_timestamp(): void
    {
        $fixture = $this->legacy845095Family();
        $rootPayloadBefore = $fixture['root']->fresh()->raw_payload;
        $fixture['preserved_wz']->update(['posted_at' => null]);

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString(
            'Zaksięgowany dokument '.$fixture['preserved_wz']->number.' sprzed podziału nie ma wiarygodnego czasu księgowania',
            implode(' ', $preview['reasons']),
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
        $this->assertSame($rootPayloadBefore, $fixture['root']->fresh()->raw_payload);
        $this->assertSame('posted', $fixture['preserved_wz']->fresh()->status);
        $this->assertNull($fixture['preserved_wz']->fresh()->posted_at);
        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('0.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);
    }

    public function test_preview_rejects_a_post_split_cancelled_wz_with_an_incomplete_ledger_pair(): void
    {
        $fixture = $this->legacy845095Family();
        $rootPayloadBefore = $fixture['root']->fresh()->raw_payload;
        $fixture['duplicate_wz']->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
        $ledgerCountBefore = $fixture['duplicate_wz']->ledgerEntries()->count();

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString(
            'Anulowany dokument '.$fixture['duplicate_wz']->number.' nie ma kompletnej, wzajemnie znoszącej się pary ruchów magazynowych',
            implode(' ', $preview['reasons']),
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
        $this->assertSame($rootPayloadBefore, $fixture['root']->fresh()->raw_payload);
        $this->assertSame('cancelled', $fixture['duplicate_wz']->fresh()->status);
        $this->assertSame($ledgerCountBefore, $fixture['duplicate_wz']->ledgerEntries()->count());
        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('0.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);
    }

    public function test_preview_rejects_an_extra_wz_ledger_entry_with_the_line_and_warehouse_but_another_product(): void
    {
        $fixture = $this->legacy845095Family();
        $rootPayloadBefore = $fixture['root']->fresh()->raw_payload;
        $document = $fixture['duplicate_wz']->fresh(['lines', 'ledgerEntries']);
        $line = $document->lines->firstOrFail();
        $otherProductId = $fixture['preserved_wz']->lines
            ->first(fn ($candidate): bool => (int) $candidate->product_id !== (int) $line->product_id)
            ?->product_id;
        $this->assertNotNull($otherProductId);
        StockLedgerEntry::query()->create([
            'warehouse_document_id' => $document->id,
            'warehouse_document_line_id' => $line->id,
            'warehouse_id' => $document->source_warehouse_id,
            'product_id' => $otherProductId,
            'quantity_change' => -1,
            'direction' => 'out',
            'posted_at' => $document->posted_at,
            'metadata' => [
                'document_number' => $document->number,
                'document_type' => 'WZ',
            ],
        ]);
        $ledgerCountBefore = $document->ledgerEntries()->count();

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString(
            'Dokument '.$document->number.' zawiera dodatkowy ruch, którego nie można jednoznacznie odwrócić',
            implode(' ', $preview['reasons']),
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
        $this->assertSame($rootPayloadBefore, $fixture['root']->fresh()->raw_payload);
        $this->assertSame('posted', $document->fresh()->status);
        $this->assertSame($ledgerCountBefore, $document->ledgerEntries()->count());
        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('0.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);
    }

    public function test_reversal_can_be_retried_after_its_active_child_label_was_already_cancelled_by_the_same_operation(): void
    {
        Http::preventStrayRequests();
        $administrator = User::query()->where('role', User::ROLE_ADMINISTRATOR)->firstOrFail();
        $this->actingAs($administrator);
        $fixture = $this->legacy845095Family();
        $childLabel = $fixture['child_label'];
        $childLabel->update([
            'status' => 'generated',
            'response_payload' => [
                'generation' => ['request' => ['cod_amount' => '397.80']],
                'shipment' => ['status' => 'cancelled'],
            ],
        ]);
        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());
        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));

        app(HistoricalSplitReconciliationService::class)->adopt(
            $fixture['child']->fresh(),
            $administrator,
            $preview['version'],
            $preview['plan_digest'],
            (string) Str::uuid(),
            $fixture['root']->external_number,
            'Test bezpiecznego wznowienia po anulowaniu etykiety części.',
            [
                'carrier_not_handed_over' => true,
                'package_matches_preserved_wz' => true,
                'duplicate_items_returned' => true,
                'financial_total_verified' => true,
            ],
        );

        $failLocalFinalizationOnce = true;
        WarehouseDocument::updated(function (WarehouseDocument $document) use (
            &$failLocalFinalizationOnce,
            $fixture,
        ): void {
            if ($failLocalFinalizationOnce
                && (int) $document->id === (int) $fixture['duplicate_wz']->id
                && (string) $document->status === 'cancelled') {
                $failLocalFinalizationOnce = false;

                throw new \RuntimeException('Wymuszony błąd lokalnej finalizacji po anulowaniu etykiety.');
            }
        });

        $reversal = app(OrderSplitReversalService::class);
        $firstAttempt = $reversal->availability($fixture['child']->fresh());
        $this->assertTrue($firstAttempt['available'], implode(' ', $firstAttempt['reasons']));

        try {
            $reversal->reverse(
                $fixture['child']->fresh(),
                $firstAttempt['version'],
                actor: $administrator,
            );
            $this->fail('Pierwsza próba powinna zatrzymać się po anulowaniu etykiety.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Wymuszony błąd lokalnej finalizacji', $exception->getMessage());
        }

        $childLabel->refresh();
        $this->assertSame('cancelled', $childLabel->status);
        $operationUuid = (string) data_get(
            $fixture['root']->fresh()->raw_payload,
            'sempre_erp_split_reversal_operation.uuid',
        );
        $this->assertNotSame('', $operationUuid);
        $this->assertSame(
            $operationUuid,
            data_get($childLabel->response_payload, 'cancellation.operation_uuid'),
        );
        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('posted', $fixture['duplicate_wz']->fresh()->status);
        $this->assertSame('0.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);

        $retry = $reversal->availability($fixture['child']->fresh());
        $this->assertTrue($retry['available'], implode(' ', $retry['reasons']));
        $this->assertFalse($retry['shipping_confirmation_required']);
        $restored = $reversal->reverse(
            $fixture['child']->fresh(),
            $retry['version'],
            'Wznowienie tej samej operacji po bezpiecznym anulowaniu etykiety.',
            actor: $administrator,
        );

        $this->assertSame($fixture['root']->id, $restored->id);
        $this->assertSoftDeleted('external_orders', ['id' => $fixture['child']->id]);
        $this->assertStringStartsWith('split-reverted:'.$operationUuid.':', (string) $childLabel->fresh()->idempotency_key);
        $this->assertSame('1.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);
        Http::assertNothingSent();
    }

    public function test_v5_reversal_restores_the_pre_packing_state_without_pre_split_tasks_or_preserved_artifacts(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $administrator = User::query()->where('role', User::ROLE_ADMINISTRATOR)->firstOrFail();
        $fixture = $this->legacy845095Family();

        foreach ($fixture['root_tasks'] as $task) {
            $task->delete();
        }

        $fixture['preserved_label']->delete();
        $preservedWz = $fixture['preserved_wz']->fresh(['lines', 'ledgerEntries']);
        $preservedWz->ledgerEntries()->delete();
        $preservedWz->lines()->delete();
        $preservedWz->forceDelete();
        $this->removeShipmentIdentities($fixture['root']);
        $this->removeShipmentIdentities($fixture['child']);
        $fixture['root']->update(['fulfillment_status' => null]);

        $reconciliation = app(HistoricalSplitReconciliationService::class);
        $preview = $reconciliation->preview($fixture['child']->fresh());

        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));
        $this->assertSame([], $preview['plan']['preserve_task_ids']);
        $this->assertSame([], $preview['plan']['preserve_wz_ids']);
        $this->assertSame([], $preview['plan']['preserve_label_ids']);
        $this->assertNull($preview['plan']['restored_fulfillment_status']);
        $this->assertSame([$fixture['child_task']->id], $preview['plan']['reverse_task_ids']);
        $this->assertSame([$fixture['duplicate_wz']->id], $preview['plan']['reverse_wz_ids']);
        $this->assertSame([$fixture['child_label']->id], $preview['plan']['reverse_label_ids']);

        $reconciliation->adopt(
            $fixture['child']->fresh(),
            $administrator,
            $preview['version'],
            $preview['plan_digest'],
            (string) Str::uuid(),
            $fixture['root']->external_number,
            'Zweryfikowano historyczny stan sprzed rozpoczęcia pakowania.',
            $this->reconciliationConfirmations(),
        );
        $availability = app(OrderSplitReversalService::class)->availability($fixture['child']->fresh());
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $restored = app(OrderSplitReversalService::class)->reverse(
            $fixture['child']->fresh(),
            $availability['version'],
            'Przywrócenie stanu sprzed rozpoczęcia pakowania.',
            actor: $administrator,
        )->fresh('lines');

        $this->assertNull($restored->fulfillment_status);
        $this->assertSame('1108.50', (string) $restored->total_gross);
        $this->assertCount(2, $restored->lines);
        $this->assertSame(0, PackingTask::query()->where('external_order_id', $restored->id)->count());
        $this->assertSame('cancelled', $fixture['child_task']->fresh()->status);
        $this->assertSoftDeleted('external_orders', ['id' => $fixture['child']->id]);
        $this->assertSame('cancelled', WarehouseDocument::withTrashed()->findOrFail($fixture['duplicate_wz']->id)->status);
        $this->assertSame('1.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);

        $rootReservations = StockReservation::query()
            ->where('sales_channel_id', $restored->sales_channel_id)
            ->where('external_order_id', $restored->external_id)
            ->whereIn('status', ['active', 'waiting'])
            ->get();
        $this->assertCount(2, $rootReservations);
        $this->assertSame(2.0, (float) $rootReservations->sum('quantity'));
        $this->assertTrue($rootReservations->every(fn (StockReservation $reservation): bool => $reservation->status === 'waiting'));
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_preview_infers_awaiting_courier_from_the_exact_preserved_wz_and_label_when_pre_split_tasks_are_missing(): void
    {
        $fixture = $this->legacy845095Family();

        foreach ($fixture['root_tasks'] as $task) {
            $task->delete();
        }

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());

        $this->assertTrue($preview['available'], implode(' ', $preview['reasons']));
        $this->assertSame([], $preview['plan']['preserve_task_ids']);
        $this->assertSame([$fixture['preserved_wz']->id], $preview['plan']['preserve_wz_ids']);
        $this->assertSame([$fixture['preserved_label']->id], $preview['plan']['preserve_label_ids']);
        $this->assertSame('awaiting_courier', $preview['plan']['restored_fulfillment_status']);
    }

    public function test_preview_fails_closed_when_tasks_are_missing_and_only_one_preserved_packing_artifact_exists(): void
    {
        $fixture = $this->legacy845095Family();

        foreach ($fixture['root_tasks'] as $task) {
            $task->delete();
        }

        $fixture['preserved_label']->delete();
        $this->removeShipmentIdentities($fixture['root']);
        $this->removeShipmentIdentities($fixture['child']);

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString(
            'Brak zadań pakowania sprzed podziału, a zachowane artefakty nie pozwalają jednoznacznie odtworzyć etapu pakowania',
            implode(' ', $preview['reasons']),
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
    }

    public function test_preview_rejects_tasks_labels_and_documents_created_in_the_exact_split_second(): void
    {
        $fixture = $this->legacy845095Family();
        $splitAt = CarbonImmutable::instance($fixture['child']->created_at)->startOfSecond();
        $this->forceTimestamps($fixture['root_tasks'][0], $splitAt);
        $this->forceTimestamps($fixture['preserved_label'], $splitAt);
        $this->forceTimestamps($fixture['preserved_wz'], $splitAt);

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());
        $reasons = implode(' ', $preview['reasons']);

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString('Zadanie pakowania powstało dokładnie w sekundzie podziału', $reasons);
        $this->assertStringContainsString(
            'Etykieta #'.$fixture['preserved_label']->id.' powstała dokładnie w sekundzie podziału',
            $reasons,
        );
        $this->assertStringContainsString(
            'Dokument '.$fixture['preserved_wz']->number.' powstał dokładnie w sekundzie podziału',
            $reasons,
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
    }

    public function test_preview_rejects_artifacts_created_before_the_split_but_finalized_after_it(): void
    {
        $fixture = $this->legacy845095Family();
        $splitAt = CarbonImmutable::instance($fixture['child']->created_at)->startOfSecond();
        $fixture['preserved_label']->update(['generated_at' => $splitAt->addSecond()]);
        $this->forceTimestamps($fixture['preserved_label'], $splitAt->subSecond());
        $fixture['preserved_wz']->update(['posted_at' => $splitAt->addSecond()]);
        $this->forceTimestamps($fixture['preserved_wz'], $splitAt->subSecond());

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());
        $reasons = implode(' ', $preview['reasons']);

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString(
            'Etykieta #'.$fixture['preserved_label']->id.' została utworzona przed podziałem, ale wygenerowana po nim',
            $reasons,
        );
        $this->assertStringContainsString(
            'Dokument '.$fixture['preserved_wz']->number.' utworzono przed podziałem, ale zaksięgowano po nim',
            $reasons,
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
    }

    public function test_preview_rejects_a_preserved_cod_label_with_an_amount_different_from_the_source_total(): void
    {
        $fixture = $this->legacy845095Family();
        $payload = (array) $fixture['preserved_label']->response_payload;
        data_set($payload, 'generation.request.cod_amount', '397.80');
        $fixture['preserved_label']->update(['response_payload' => $payload]);

        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']->fresh());

        $this->assertFalse($preview['available']);
        $this->assertStringContainsString(
            'Kwota COD zapisana przy etykiecie sprzed podziału nie zgadza się z pierwotną kwotą zamówienia',
            implode(' ', $preview['reasons']),
        );
        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
    }

    public function test_services_reject_a_non_administrator_even_when_called_directly(): void
    {
        $fixture = $this->legacy845095Family();
        $operator = User::query()->create([
            'name' => 'Operator bez bezpośredniego dostępu',
            'email' => 'operator-direct-history@example.test',
            'password' => 'test-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);
        $administrator = User::query()->where('role', User::ROLE_ADMINISTRATOR)->firstOrFail();
        $reconciliation = app(HistoricalSplitReconciliationService::class);
        $preview = $reconciliation->preview($fixture['child']);

        try {
            $reconciliation->adopt(
                $fixture['child'],
                $operator,
                $preview['version'],
                $preview['plan_digest'],
                (string) Str::uuid(),
                $fixture['root']->external_number,
                'Operator próbuje bezpośrednio zatwierdzić historyczny plan.',
                $this->reconciliationConfirmations(),
            );
            $this->fail('Serwis uzgodnienia powinien odrzucić operatora.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Tylko administrator', $exception->getMessage());
        }

        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
        $reconciliation->adopt(
            $fixture['child']->fresh(),
            $administrator,
            $preview['version'],
            $preview['plan_digest'],
            (string) Str::uuid(),
            $fixture['root']->external_number,
            'Administrator zatwierdza plan przed kontrolą serwisu cofnięcia.',
            $this->reconciliationConfirmations(),
        );
        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($fixture['child']->fresh());

        try {
            $reversal->reverse(
                $fixture['child']->fresh(),
                $availability['version'],
                actor: $operator,
            );
            $this->fail('Serwis cofnięcia powinien odrzucić operatora.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Tylko administrator', $exception->getMessage());
        }

        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('posted', $fixture['duplicate_wz']->fresh()->status);
        $this->assertSame('0.0000', (string) $fixture['duplicated_balance']->fresh()->quantity_on_hand);
    }

    public function test_historical_reconciliation_controller_rejects_an_operator_even_without_the_settings_middleware(): void
    {
        $fixture = $this->legacy845095Family();
        $preview = app(HistoricalSplitReconciliationService::class)->preview($fixture['child']);
        $operator = User::query()->create([
            'name' => 'Operator kontroli kontrolera',
            'email' => 'operator-controller-history@example.test',
            'password' => 'test-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);

        $this->withoutMiddleware(EnsureErpRole::class)
            ->actingAs($operator)
            ->post(
                route('orders.split.historical-reconciliation', $fixture['child']),
                $this->adoptionPayload($preview, $fixture['root']),
            )->assertForbidden();

        $this->assertNull(data_get($fixture['root']->fresh()->raw_payload, 'sempre_erp_split_original'));
        $this->assertNotNull($fixture['child']->fresh());
        $this->assertSame('posted', $fixture['duplicate_wz']->fresh()->status);
    }

    /**
     * @return array{
     *     root:ExternalOrder,
     *     child:ExternalOrder,
     *     root_tasks:list<PackingTask>,
     *     child_task:PackingTask,
     *     preserved_wz:WarehouseDocument,
     *     duplicate_wz:WarehouseDocument,
     *     preserved_label:ShippingLabel,
     *     child_label:ShippingLabel,
     *     duplicated_balance:StockBalance,
     *     other_balance:StockBalance
     * }
     */
    private function legacy845095Family(): array
    {
        $splitAt = CarbonImmutable::parse('2026-07-18 12:45:21');
        $channel = SalesChannel::query()->create([
            'code' => 'WOO-HISTORY-845095',
            'name' => 'WooCommerce 845095',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'WH-HISTORY-845095',
            'name' => 'Magazyn historyczny 845095',
            'type' => 'physical',
            'allow_negative_stock' => false,
            'is_active' => true,
        ]);
        $otherProduct = $this->product('5459', 'Buty 5459');
        $duplicatedProduct = $this->product('5440', 'Buty 5440');
        $otherBalance = $this->balance($warehouse, $otherProduct, 7);
        $duplicatedBalance = $this->balance($warehouse, $duplicatedProduct, 0);
        $line5459 = [
            'id' => 59624,
            'sku' => '5459',
            'name' => 'Buty 5459',
            'quantity' => 1,
            'subtotal' => '690.24',
            'subtotal_tax' => '158.76',
            'total' => '483.17',
            'total_tax' => '111.13',
        ];
        $line5440 = [
            'id' => 59625,
            'sku' => '5440',
            'name' => 'Buty 5440',
            'quantity' => 1,
            'subtotal' => '568.29',
            'subtotal_tax' => '130.71',
            'total' => '397.80',
            'total_tax' => '91.50',
        ];
        $commercePayload = [
            'id' => 845095,
            'number' => '845095',
            'status' => 'processing',
            'currency' => 'PLN',
            'prices_include_tax' => false,
            'discount_total' => '377.56',
            'discount_tax' => '86.84',
            'shipping_total' => '24.90',
            'shipping_tax' => '0.00',
            'cart_tax' => '202.63',
            'total_tax' => '202.63',
            'total' => '1108.50',
            'payment_method' => 'cod',
            'payment_method_title' => 'Płatność przy odbiorze',
            'billing' => [
                'first_name' => 'Anna',
                'last_name' => 'Testowa',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'email' => 'anna.845095@example.test',
            ],
            'shipping' => [
                'first_name' => 'Anna',
                'last_name' => 'Testowa',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'line_items' => [$line5459, $line5440],
            'shipping_lines' => [[
                'id' => 701,
                'method_title' => 'Kurier InPost',
                'method_id' => 'flat_rate',
                'total' => '24.90',
                'total_tax' => '0.00',
            ]],
            'meta_data' => [
                [
                    'key' => '_inpost_shipment_id',
                    'value' => '523000013688150127510323',
                ],
            ],
        ];
        $root = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '845095',
            'external_number' => '845095',
            'status' => 'processing',
            'fulfillment_status' => 'awaiting_courier',
            'currency' => 'PLN',
            'total_gross' => 483.17,
            'billing_data' => $commercePayload['billing'],
            'shipping_data' => $commercePayload['shipping'],
            'raw_payload' => $commercePayload + [
                'sempre_erp_split_child_orders' => ['845095-SPLIT-1'],
                'sempre_erp_split_allocations' => [[
                    'child_external_id' => '845095-SPLIT-1',
                    'source_external_line_id' => '59625',
                    'split_quantity' => 1,
                ]],
            ],
            'external_created_at' => $splitAt->subDays(3),
            'external_updated_at' => $splitAt->subDay(),
        ]);
        $this->forceTimestamps($root, $splitAt->subDays(3));
        $rootLine = $root->lines()->create([
            'product_id' => $otherProduct->id,
            'external_line_id' => '59624',
            'canonical_external_line_id' => '59624',
            'sku' => '5459',
            'name' => 'Buty 5459',
            'quantity' => 1,
            'unit_net_price' => 690.24,
            'unit_gross_price' => 483.17,
            'vat_rate' => 23,
            'raw_payload' => $line5459,
        ]);

        $childPayload = array_merge($commercePayload, [
            'inpost_shipment_id' => '523000013688150127510323',
            'inpost_tracking_number' => '523000013688150127519999',
            'meta_data' => [
                [
                    'key' => '_inpost_shipment_id',
                    'value' => '523000013688150127510323',
                ],
                [
                    'key' => '_inpost_tracking_number',
                    'value' => '523000013688150127519999',
                ],
            ],
            'sempre_erp_split' => [
                'parent_order_id' => $root->id,
                'parent_external_id' => $root->external_id,
                'root_order_id' => $root->id,
                'root_external_id' => $root->external_id,
                'created_at' => $splitAt->toISOString(),
            ],
        ]);
        $child = ExternalOrder::query()->create([
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
            'sales_channel_id' => $channel->id,
            'external_id' => '845095-SPLIT-1',
            'external_number' => '845095/S1',
            'status' => 'processing',
            'fulfillment_status' => 'ready_to_pack',
            'currency' => 'PLN',
            'total_gross' => 397.80,
            'billing_data' => $commercePayload['billing'],
            'shipping_data' => $commercePayload['shipping'],
            'raw_payload' => $childPayload,
            'external_created_at' => $root->external_created_at,
            'external_updated_at' => $splitAt,
        ]);
        $this->forceTimestamps($child, $splitAt);
        $childLine = $child->lines()->create([
            'product_id' => $duplicatedProduct->id,
            'external_line_id' => '59625-S1',
            'canonical_external_line_id' => '59625',
            'sku' => '5440',
            'name' => 'Buty 5440',
            'quantity' => 1,
            'unit_net_price' => 568.29,
            'unit_gross_price' => 397.80,
            'vat_rate' => 23,
            'raw_payload' => $line5440 + [
                'sempre_erp_split' => [
                    'source_external_line_id' => '59625',
                    'root_external_line_id' => '59625',
                    'source_quantity' => 1,
                    'split_quantity' => 1,
                ],
            ],
        ]);

        $firstRootTask = $this->packingTask(
            $root,
            $rootLine->id,
            $otherProduct,
            '59624',
            'packed',
            $splitAt->subHours(8),
            $splitAt->subHours(7),
            $splitAt->subHours(6),
        );
        $secondRootTask = $this->packingTask(
            $root,
            null,
            $duplicatedProduct,
            '59625',
            'packed',
            $splitAt->subHours(8),
            $splitAt->subHours(7),
            $splitAt->subHours(6),
        );
        $childTask = $this->packingTask(
            $child,
            $childLine->id,
            $duplicatedProduct,
            '59625-S1',
            'picked',
            $splitAt->addHour(),
            $splitAt->addHours(2),
            null,
        );

        $preservedWz = $this->postedWz(
            $root,
            $warehouse,
            'WZ/000093/07/2026',
            'history-845095-root-wz',
            [[$otherProduct, 1.0], [$duplicatedProduct, 1.0]],
            $splitAt->subHours(5),
            $splitAt->subHours(4),
        );
        $duplicateWz = $this->postedWz(
            $child,
            $warehouse,
            'WZ/000140/07/2026',
            'history-845095-child-wz',
            [[$duplicatedProduct, 1.0]],
            $splitAt->addHours(3),
            $splitAt->addHours(4),
        );
        $preservedLabel = ShippingLabel::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $root->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:history-845095-root',
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => '523000013688150127510323',
            'tracking_number' => '523000013688150127510323',
            'disk' => 'local',
            'path' => 'shipping-labels/history-845095-root.pdf',
            'mime_type' => 'application/pdf',
            'response_payload' => [
                'generation' => ['request' => ['cod_amount' => '1108.50']],
            ],
            'generated_at' => $splitAt->subHours(3),
        ]);
        $this->forceTimestamps($preservedLabel, $splitAt->subHours(3));
        $childLabel = ShippingLabel::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:history-845095-child',
            'status' => 'cancelled',
            'provider' => 'inpost',
            'label_number' => '523000013688150127519999',
            'tracking_number' => '523000013688150127519999',
            'disk' => 'local',
            'path' => 'shipping-labels/history-845095-child.pdf',
            'mime_type' => 'application/pdf',
            'response_payload' => [
                'generation' => ['request' => ['cod_amount' => '397.80']],
                'cancellation' => ['remote' => ['status' => 'cancelled']],
            ],
            'generated_at' => $splitAt->addHours(5),
        ]);
        $this->forceTimestamps($childLabel, $splitAt->addHours(5));

        return [
            'root' => $root->fresh(),
            'child' => $child->fresh(),
            'root_tasks' => [$firstRootTask->fresh(), $secondRootTask->fresh()],
            'child_task' => $childTask->fresh(),
            'preserved_wz' => $preservedWz->fresh(['lines.product', 'ledgerEntries']),
            'duplicate_wz' => $duplicateWz->fresh(['lines.product', 'ledgerEntries']),
            'preserved_label' => $preservedLabel->fresh(),
            'child_label' => $childLabel->fresh(),
            'duplicated_balance' => $duplicatedBalance->fresh(),
            'other_balance' => $otherBalance->fresh(),
        ];
    }

    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function adoptionPayload(array $preview, ExternalOrder $root): array
    {
        return [
            'family_version' => $preview['version'],
            'plan_digest' => $preview['plan_digest'],
            'reconciliation_request_uuid' => (string) Str::uuid(),
            'typed_order_number' => $root->external_number,
            'reason' => 'Ręcznie zweryfikowano stan rodziny historycznego podziału 845095.',
            'confirm_carrier_not_handed_over' => '1',
            'confirm_package_matches_preserved_wz' => '1',
            'confirm_duplicate_items_returned' => '1',
            'confirm_financial_total_verified' => '1',
        ];
    }

    private function product(string $sku, string $name): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => $name,
            'unit' => 'szt',
            'quantity_precision' => 0,
            'vat_rate' => 23,
            'is_active' => true,
        ]);
    }

    private function balance(Warehouse $warehouse, Product $product, float $quantity): StockBalance
    {
        return StockBalance::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity_on_hand' => $quantity,
            'quantity_reserved' => 0,
            'quantity_available' => $quantity,
        ]);
    }

    private function packingTask(
        ExternalOrder $order,
        ?int $lineId,
        Product $product,
        string $externalLineId,
        string $status,
        CarbonInterface $createdAt,
        ?CarbonInterface $pickedAt,
        ?CarbonInterface $packedAt,
    ): PackingTask {
        $task = PackingTask::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'external_order_line_id' => $lineId,
            'product_id' => $product->id,
            'external_line_id' => $externalLineId,
            'order_number' => $order->external_number,
            'customer_name' => 'Anna Testowa',
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity_required' => 1,
            'quantity_picked' => 1,
            'status' => $status,
            'courier' => 'InPost',
            'order_date' => $order->external_created_at,
            'picked_at' => $pickedAt,
            'packed_at' => $packedAt,
            'metadata' => $status === 'packed'
                ? ['packing_completion' => ['completed_at' => $packedAt?->toISOString()]]
                : ['packing_started_at' => $pickedAt?->toISOString()],
        ]);
        $this->forceTimestamps($task, $createdAt);

        return $task;
    }

    /** @param list<array{0:Product,1:float}> $items */
    private function postedWz(
        ExternalOrder $order,
        Warehouse $warehouse,
        string $number,
        string $fulfillmentKey,
        array $items,
        CarbonInterface $createdAt,
        CarbonInterface $postedAt,
    ): WarehouseDocument {
        $document = WarehouseDocument::query()->create([
            'number' => $number,
            'type' => 'WZ',
            'status' => 'posted',
            'source_warehouse_id' => $warehouse->id,
            'document_date' => $createdAt,
            'posted_at' => $postedAt,
            'external_reference' => $order->external_number,
            'order_fulfillment_key' => $fulfillmentKey,
            'metadata' => [
                'sales_channel_id' => $order->sales_channel_id,
                'external_order_id' => $order->external_id,
                'external_order_number' => $order->external_number,
                'source' => 'packing',
            ],
        ]);
        $this->forceTimestamps($document, $createdAt);

        foreach ($items as [$product, $quantity]) {
            $line = $document->lines()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'metadata' => ['sku' => $product->sku],
            ]);
            $this->forceTimestamps($line, $createdAt);
            $entry = StockLedgerEntry::query()->create([
                'warehouse_document_id' => $document->id,
                'warehouse_document_line_id' => $line->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity_change' => -$quantity,
                'direction' => 'out',
                'posted_at' => $postedAt,
                'metadata' => [
                    'document_number' => $number,
                    'document_type' => 'WZ',
                ],
            ]);
            $this->forceTimestamps($entry, $postedAt);
        }

        return $document;
    }

    private function forceTimestamps(Model $model, CarbonInterface $createdAt): void
    {
        $model->timestamps = false;
        $model->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
        $model->timestamps = true;
    }

    /** @return array<string,bool> */
    private function reconciliationConfirmations(): array
    {
        return [
            'carrier_not_handed_over' => true,
            'package_matches_preserved_wz' => true,
            'duplicate_items_returned' => true,
            'financial_total_verified' => true,
        ];
    }

    private function removeShipmentIdentities(ExternalOrder $order): void
    {
        $raw = (array) $order->raw_payload;
        unset(
            $raw['inpost_shipment_id'],
            $raw['inpost_tracking_number'],
            $raw['_inpost_shipment_id'],
            $raw['_inpost_tracking_number'],
        );
        $raw['meta_data'] = collect((array) ($raw['meta_data'] ?? []))
            ->reject(function (mixed $meta): bool {
                if (! is_array($meta)) {
                    return false;
                }

                $key = mb_strtolower((string) ($meta['key'] ?? ''));

                return str_contains($key, 'tracking')
                    || (str_contains($key, 'inpost') && ! str_contains($key, 'point'));
            })->values()->all();
        $order->update(['raw_payload' => $raw]);
    }
}
