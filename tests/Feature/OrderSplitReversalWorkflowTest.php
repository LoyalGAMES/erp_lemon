<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CourierAccount;
use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\PackingTask;
use App\Models\SalesChannel;
use App\Models\ShippingLabel;
use App\Models\WordpressIntegration;
use App\Services\Orders\OrderMutationLock;
use App\Services\Orders\OrderSplitReversalService;
use App\Services\Orders\OrderSplitService;
use App\Services\Packing\PackingTaskService;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class OrderSplitReversalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_delete_migration_cannot_be_rolled_back_while_archived_orders_exist(): void
    {
        [$order] = $this->orderWithTwoLines();
        $order->delete();
        $migration = require database_path('migrations/2026_07_22_000001_add_deleted_at_to_external_orders.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('istnieją zarchiwizowane części zamówień');

        $migration->down();
    }

    public function test_operator_can_reverse_the_entire_nested_family_and_restore_the_exact_original_order(): void
    {
        Mail::fake();
        [$order, $firstLine, $secondLine] = $this->orderWithTwoLines();
        app(PackingTaskService::class)->syncForOrder($order);

        $firstChild = app(OrderSplitService::class)->split(
            $order,
            [$secondLine->id => 1],
            'Druga paczka',
            'manual',
            ['shipping_decision' => [
                'decision' => 'ship_footwear_now',
                'decided_by' => 'Operator',
                'decided_at' => now()->toISOString(),
            ]],
        );
        $nestedLine = $firstChild->lines()->firstOrFail();
        $grandchild = app(OrderSplitService::class)->split(
            $firstChild,
            [$nestedLine->id => 1],
            'Trzecia paczka',
        );

        $partialCreatedMessage = CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->where('trigger', 'order_partial_created')
            ->first();

        if ($partialCreatedMessage instanceof CustomerMessage) {
            $partialCreatedMessage->update([
                'status' => 'sent',
                'sent_at' => now(),
                'failed_at' => null,
                'error_message' => null,
            ]);
        } else {
            CustomerMessage::query()->create([
                'external_order_id' => $order->id,
                'direction' => 'outgoing',
                'type' => 'automated',
                'trigger' => 'order_partial_created',
                'status' => 'sent',
                'recipient_email' => 'customer@example.test',
                'subject' => 'Podział zamówienia',
                'body' => 'Zamówienie zostało podzielone.',
                'sent_at' => now(),
                'metadata' => [],
            ]);
        }

        $familyBefore = ExternalOrder::query()
            ->whereKey($order->id)
            ->orWhere('split_root_order_id', $order->id)
            ->get();

        $this->assertCount(3, $familyBefore);
        $this->assertSame(530.0, round((float) $familyBefore->sum('total_gross'), 2));
        $this->assertSame('ship_footwear_now', data_get($order->fresh()->raw_payload, 'sempre_erp_shipping_decision.decision'));
        $this->assertTrue(CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->where('trigger', 'order_partial_created')
            ->where('status', 'sent')
            ->exists());
        $this->get(route('orders.show', $grandchild))
            ->assertOk()
            ->assertSee('Cofnij rozdzielenie zamówienia');

        $availability = app(OrderSplitReversalService::class)->availability($grandchild);
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $this->delete(route('orders.split.reverse', $grandchild), [
            'family_version' => $availability['version'],
            'note' => 'Klient chce jedną paczkę',
        ])->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('status');

        $restored = $order->fresh('lines');
        $this->assertNotNull($restored);
        $this->assertSame('530.00', (string) $restored->total_gross);
        $this->assertCount(2, $restored->lines);
        $this->assertSame(
            [
                'line-1' => '2.0000',
                'line-2' => '1.0000',
            ],
            $restored->lines->sortBy('external_line_id')->mapWithKeys(
                fn ($line): array => [(string) $line->external_line_id => (string) $line->quantity],
            )->all(),
        );
        $this->assertSame('wait_for_all', data_get($restored->raw_payload, 'sempre_erp_shipping_decision.decision'));
        $this->assertArrayNotHasKey('sempre_erp_split_original', (array) $restored->raw_payload);
        $this->assertArrayNotHasKey('sempre_erp_split_allocations', (array) $restored->raw_payload);
        $this->assertArrayNotHasKey('sempre_erp_split_child_orders', (array) $restored->raw_payload);

        $this->assertSoftDeleted('external_orders', ['id' => $firstChild->id]);
        $this->assertSoftDeleted('external_orders', ['id' => $grandchild->id]);
        $archivedGrandchild = ExternalOrder::withTrashed()->findOrFail($grandchild->id);
        $this->assertSame($firstChild->id, $archivedGrandchild->splitParent()->firstOrFail()->id);
        $this->assertSame($order->id, $archivedGrandchild->splitRoot()->firstOrFail()->id);
        $this->assertSame(1, ExternalOrder::query()->count());
        $this->assertSame(3, ExternalOrder::withTrashed()->count());
        $this->assertSame(2, PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', 'open')
            ->count());
        $this->assertSame(0, PackingTask::query()
            ->whereIn('external_order_id', [$firstChild->id, $grandchild->id])
            ->whereIn('status', ['open', 'picked'])
            ->count());
        $this->assertSame($firstLine->external_line_id, $restored->lines->sortBy('external_line_id')->first()->external_line_id);
        $rollbackMessage = CustomerMessage::query()
            ->where('external_order_id', $order->id)
            ->where('trigger', 'order_packing_rollback')
            ->sole();
        $this->assertNotEmpty(data_get($rollbackMessage->metadata, 'split_reversal_uuid'));
        $this->assertSame(
            data_get($rollbackMessage->metadata, 'split_reversal_uuid'),
            data_get($rollbackMessage->metadata, 'outbox_idempotency_key'),
        );
        $this->assertContains($rollbackMessage->status, ['sent', 'held', 'failed', 'skipped']);
    }

    public function test_repacked_order_after_manually_confirmed_reversal_ignores_the_old_partial_shipment_and_creates_full_cod(): void
    {
        Mail::fake();
        Storage::fake('local');
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/v1/organizations/111/shipments') {
                return Http::response(['items' => [[
                    'id' => 'OLD-PARTIAL',
                    'status' => 'confirmed',
                    'reference' => 'UNDO/1001',
                    'tracking_number' => '520000000000000000000010',
                ]]]);
            }

            if ($request->method() === 'POST' && $path === '/v1/organizations/111/shipments') {
                return Http::response([
                    'id' => 'NEW-FULL-COD',
                    'status' => 'created',
                ], 201);
            }

            if ($request->method() === 'GET' && $path === '/v1/shipments/NEW-FULL-COD') {
                return Http::response([
                    'id' => 'NEW-FULL-COD',
                    'status' => 'confirmed',
                    'tracking_number' => '520000000000000000000011',
                ]);
            }

            if ($request->method() === 'GET' && $path === '/v1/shipments/NEW-FULL-COD/label') {
                return Http::response('^XA full COD ^XZ');
            }

            return Http::response(['message' => 'Unexpected request: '.$request->method().' '.$path], 500);
        });

        [$order, , $secondLine] = $this->orderWithTwoLines();
        $address = [
            'first_name' => 'Jan',
            'last_name' => 'Klient',
            'email' => 'customer@example.test',
            'phone' => '+48111222333',
            'address_1' => 'Magazynowa 5',
            'postcode' => '30-001',
            'city' => 'Kraków',
            'country' => 'PL',
        ];
        $raw = (array) $order->raw_payload;
        $raw['shipping_lines'] = [['method_title' => 'Kurier InPost']];
        $order->update([
            'billing_data' => $address,
            'shipping_data' => $address,
            'raw_payload' => $raw,
        ]);
        $child = app(OrderSplitService::class)->split($order->fresh(), [$secondLine->id => 1]);
        $partialTotal = (float) $order->fresh()->total_gross;
        $account = new CourierAccount([
            'provider' => 'inpost',
            'code' => 'reversal-inpost',
            'name' => 'InPost reversal',
            'organization_id' => '111',
            'default_parcel_template' => 'small',
            'sending_method' => 'dispatch_order',
            'is_default' => true,
            'is_active' => true,
        ]);
        $account->setApiToken('token-inpost');
        $account->save();
        $oldLabel = ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:order:'.$order->id,
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'OLD-PARTIAL',
            'tracking_number' => '520000000000000000000010',
            'disk' => 'local',
            'path' => 'shipping-labels/old-partial.zpl',
            'response_payload' => [
                'shipment' => [
                    'id' => 'OLD-PARTIAL',
                    'status' => 'confirmed',
                    'tracking_number' => '520000000000000000000010',
                ],
                'financial' => [
                    'order_total' => $partialTotal,
                    'requested_cod_amount' => $partialTotal,
                ],
            ],
            'generated_at' => now(),
        ]);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));
        $restored = $reversal->reverse(
            $child->fresh(),
            $availability['version'],
            confirmManualShippingCancellation: true,
        )->fresh();

        $this->assertSame('530.00', (string) $restored->total_gross);
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
        $this->assertSame('cancelled', $oldLabel->fresh()->status);
        $this->assertStringStartsWith('split-reverted:', (string) $oldLabel->fresh()->idempotency_key);
        $this->assertContains(
            'OLD-PARTIAL',
            (array) data_get($restored->raw_payload, 'sempre_erp_split_reversal.cancelled_shipment_identities', []),
        );

        $newLabel = app(ShippingLabelService::class)->generateForOrder($restored, $account);

        $this->assertSame('NEW-FULL-COD', $newLabel->label_number);
        $this->assertFalse((bool) data_get($newLabel->response_payload, 'shipment.reused_existing_shipment'));
        $this->assertEqualsWithDelta(
            530.0,
            (float) data_get($newLabel->response_payload, 'financial.requested_cod_amount'),
            0.001,
        );
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && (string) parse_url($request->url(), PHP_URL_PATH) === '/v1/organizations/111/shipments'
            && (float) data_get($request->data(), 'cod.amount') === 530.0
            && (float) data_get($request->data(), 'insurance.amount') === 530.0);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE'
            && (string) parse_url($request->url(), PHP_URL_PATH) === '/v1/shipments/OLD-PARTIAL');
    }

    public function test_reversal_uploads_an_existing_full_correction_when_the_original_was_uploaded_to_woo(): void
    {
        Mail::fake();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/UNDO-1001' => Http::response([
                'id' => 1001,
                'status' => 'processing',
            ]),
            'https://shop.test/wp-json/lemon-erp/v1/orders/UNDO-1001/invoice' => Http::response([
                'order_id' => 'UNDO-1001',
                'file_url' => 'https://shop.test/invoices/existing-split-correction.pdf',
                'stored_file' => true,
                'note_id' => 8801,
            ]),
        ]);

        [$order, , $secondLine] = $this->orderWithTwoLines();
        WordpressIntegration::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'name' => 'Test Woo split reversal',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'wp_api_username' => 'erp',
            'wp_api_password_encrypted' => Crypt::encryptString('app-password'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
            'invoice_upload_enabled' => true,
        ]);
        $child = app(OrderSplitService::class)->split($order, [$secondLine->id => 1]);

        // Dokumenty muszą jednoznacznie powstać po podziale także w bazach,
        // które zapisują created_at z dokładnością do pełnej sekundy.
        $this->travel(1)->seconds();
        $original = Invoice::query()->create([
            'number' => 'FV/2026/SPLIT-UPLOADED',
            'type' => 'vat',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
            'payment_due_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => [
                'name' => 'Sempre sp. z o.o.',
                'tax_id' => '5261040828',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'buyer_data' => [
                'name' => 'Anna Nowak',
                'tax_id' => '',
                'address_1' => 'Kliencka 2',
                'postcode' => '00-002',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'net_total' => 100,
            'vat_total' => 23,
            'gross_total' => 123,
            'payment_method' => 'PayU',
            'issued_at' => now(),
            'metadata' => [
                'woocommerce_upload' => [
                    'status' => 'success',
                    'uploaded_at' => now()->toISOString(),
                ],
            ],
        ]);
        $originalLine = $original->lines()->create([
            'name' => 'Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 100,
            'net_total' => 100,
            'vat_rate' => 23,
            'vat_total' => 23,
            'gross_total' => 123,
        ]);
        $correction = Invoice::query()->create([
            'number' => 'FK/2026/EXISTING-FULL',
            'type' => 'correction',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
            'payment_due_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => $original->seller_data,
            'buyer_data' => $original->buyer_data,
            'net_total' => -100,
            'vat_total' => -23,
            'gross_total' => -123,
            'payment_method' => 'PayU',
            'issued_at' => now(),
            'metadata' => [
                'corrected_invoice_id' => $original->id,
                'corrected_invoice_number' => $original->number,
                'corrected_invoice_issue_date' => $original->issue_date?->toDateString(),
                'correction_reason' => 'Pełna korekta wystawiona przed cofnięciem podziału',
            ],
        ]);
        $correction->lines()->create([
            'name' => 'Korekta: Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => -1,
            'unit_net_price' => 100,
            'net_total' => -100,
            'vat_rate' => 23,
            'vat_total' => -23,
            'gross_total' => -123,
            'metadata' => ['corrected_invoice_line_id' => $originalLine->id],
        ]);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child);
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $restored = $reversal->reverse($child, $availability['version']);

        $this->assertSame($order->id, $restored->id);
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
        $this->assertSame(1, Invoice::query()->where('type', 'correction')->count());
        $this->assertSame($correction->id, Invoice::query()->where('type', 'correction')->sole()->id);
        $this->assertSame('success', data_get($correction->fresh()->metadata, 'woocommerce_upload.status'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://shop.test/wp-json/lemon-erp/v1/orders/UNDO-1001/invoice'
            && $request['invoice_id'] === $correction->id
            && $request['invoice_type'] === 'correction'
            && $request['invoice_number'] === $correction->number
            && $request['order_id'] === 'UNDO-1001'
            && $request['gross_total'] === '-123.00'
            && str_starts_with((string) base64_decode((string) $request['file_base64'], true), '%PDF-'));
    }

    public function test_reversal_resynchronizes_the_original_woo_status_even_when_local_status_already_matches(): void
    {
        Mail::fake();
        Http::fake([
            'https://shop.test/wp-json/wc/v3/orders/UNDO-1001' => Http::response([
                'id' => 1001,
                'status' => 'processing',
            ]),
        ]);

        [$order, , $secondLine] = $this->orderWithTwoLines();
        WordpressIntegration::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'name' => 'Test przywrócenia statusu Woo',
            'base_url' => 'https://shop.test',
            'consumer_key_encrypted' => Crypt::encryptString('ck_test'),
            'consumer_secret_encrypted' => Crypt::encryptString('cs_test'),
            'order_import_enabled' => true,
            'stock_export_enabled' => true,
        ]);
        $child = app(OrderSplitService::class)->split($order, [$secondLine->id => 1]);
        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child);

        $this->assertSame('processing', $order->fresh()->status);
        $restored = $reversal->reverse($child, $availability['version']);

        $this->assertSame($order->id, $restored->id);
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && $request->url() === 'https://shop.test/wp-json/wc/v3/orders/UNDO-1001'
            && data_get($request->data(), 'status') === 'processing');
    }

    public function test_reversal_cancels_a_label_and_requires_manual_confirmation_when_carrier_cannot_be_verified(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $child = app(OrderSplitService::class)->split($order, [$secondLine->id => 1]);

        $label = ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'shipment-123',
            'disk' => 'local',
            'path' => 'shipping-labels/test.pdf',
            'generated_at' => now(),
        ]);

        $availability = app(OrderSplitReversalService::class)->availability($child);

        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));
        $this->delete(route('orders.split.reverse', $child), [
            'family_version' => $availability['version'],
        ])->assertSessionHas('error');
        $this->assertNotNull($child->fresh());
        $this->assertSame(2, ExternalOrder::query()->count());
        $this->assertSame('cancelled', $label->fresh()->status);

        $retry = app(OrderSplitReversalService::class)->availability($child->fresh());
        $this->assertTrue($retry['available'], implode(' ', $retry['reasons']));
        $this->assertTrue($retry['shipping_confirmation_required']);

        $this->delete(route('orders.split.reverse', $child), [
            'family_version' => $retry['version'],
            'confirm_manual_shipping_cancellation' => true,
        ])->assertRedirect(route('orders.show', $order));

        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
        $this->assertStringStartsWith('split-reverted:', (string) $label->fresh()->idempotency_key);
        $this->assertSame(
            ['shipment-123'],
            data_get($order->fresh()->raw_payload, 'sempre_erp_split_reversal.cancelled_shipment_identities'),
        );
    }

    public function test_locally_cancelled_historical_label_without_remote_proof_requires_confirmation_before_reversal(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $child = app(OrderSplitService::class)->split($order, [$secondLine->id => 1]);

        $label = ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'cancelled',
            'provider' => 'inpost',
            'label_number' => 'shipment-local-only',
            'disk' => 'local',
            'path' => 'shipping-labels/local-only.pdf',
            'response_payload' => [],
            'generated_at' => now(),
        ]);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child);

        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));
        $this->assertTrue($availability['shipping_confirmation_required']);
        $this->assertStringContainsString(
            'anulowana tylko lokalnie',
            implode(' ', $availability['shipping_confirmation_reasons']),
        );

        $this->delete(route('orders.split.reverse', $child), [
            'family_version' => $availability['version'],
        ])->assertSessionHas('error');
        $this->assertNotNull($child->fresh());

        $retry = $reversal->availability($child->fresh());
        $this->delete(route('orders.split.reverse', $child), [
            'family_version' => $retry['version'],
            'confirm_manual_shipping_cancellation' => true,
        ])->assertRedirect(route('orders.show', $order));

        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
        $this->assertStringStartsWith('split-reverted:', (string) $label->fresh()->idempotency_key);
    }

    public function test_locally_cancelled_historical_label_with_remote_proof_needs_no_confirmation(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $child = app(OrderSplitService::class)->split($order, [$secondLine->id => 1]);

        ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'cancelled',
            'provider' => 'inpost',
            'label_number' => 'shipment-remotely-cancelled',
            'disk' => 'local',
            'path' => 'shipping-labels/remotely-cancelled.pdf',
            'response_payload' => [
                'cancellation' => [
                    'remote' => ['status' => 'cancelled'],
                ],
            ],
            'generated_at' => now(),
        ]);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child);

        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));
        $this->assertFalse($availability['shipping_confirmation_required']);

        $restored = $reversal->reverse($child, $availability['version']);

        $this->assertSame($order->id, $restored->id);
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
    }

    public function test_reversal_rejects_a_stale_family_version_without_partial_changes(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $child = app(OrderSplitService::class)->split($order, [$secondLine->id => 1]);
        $staleVersion = app(OrderSplitReversalService::class)->availability($order)['version'];
        $child->update(['total_gross' => 284.00]);

        $this->delete(route('orders.split.reverse', $order), [
            'family_version' => $staleVersion,
        ])->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'zmieniła się'));

        $this->assertSame(2, ExternalOrder::query()->count());
        $this->assertSame('246.47', (string) $order->fresh()->total_gross);
        $this->assertSame('284.00', (string) $child->fresh()->total_gross);
    }

    public function test_reversal_restores_mutable_order_data_captured_before_the_split(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $originalExternalCreatedAt = now()->subDays(12)->startOfSecond();
        $originalExternalUpdatedAt = now()->subDays(10)->startOfSecond();
        $order->update([
            'external_number' => 'UNDO/ORIGINAL',
            'currency' => 'PLN',
            'billing_data' => ['first_name' => 'Anna', 'email' => 'original@example.test'],
            'shipping_data' => ['first_name' => 'Anna', 'city' => 'Warszawa'],
            'external_created_at' => $originalExternalCreatedAt,
            'external_updated_at' => $originalExternalUpdatedAt,
        ]);
        $originalExternalCreatedRaw = $order->fresh()->getRawOriginal('external_created_at');
        $originalExternalUpdatedRaw = $order->fresh()->getRawOriginal('external_updated_at');

        $child = app(OrderSplitService::class)->split($order->fresh(), [$secondLine->id => 1]);

        $order->fresh()->update([
            'external_number' => 'UNDO/CHANGED-AFTER-SPLIT',
            'status' => 'ready-to-ship',
            'fulfillment_status' => 'awaiting_courier',
            'currency' => 'EUR',
            'billing_data' => ['first_name' => 'Changed', 'email' => 'changed@example.test'],
            'shipping_data' => ['first_name' => 'Changed', 'city' => 'Berlin'],
            'external_created_at' => now()->subDay(),
            'external_updated_at' => now(),
        ]);
        $child->update(['currency' => 'EUR']);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());
        $this->assertTrue($availability['available'], implode(' ', $availability['reasons']));

        $restored = $reversal->reverse($child->fresh(), $availability['version'])->fresh();

        $this->assertSame('UNDO/ORIGINAL', $restored->external_number);
        $this->assertSame('processing', $restored->status);
        $this->assertSame('picking', $restored->fulfillment_status);
        $this->assertSame('PLN', $restored->currency);
        $this->assertSame(['first_name' => 'Anna', 'email' => 'original@example.test'], $restored->billing_data);
        $this->assertSame(['first_name' => 'Anna', 'city' => 'Warszawa'], $restored->shipping_data);
        $this->assertSame($originalExternalCreatedRaw, $restored->getRawOriginal('external_created_at'));
        $this->assertSame($originalExternalUpdatedRaw, $restored->getRawOriginal('external_updated_at'));
    }

    public function test_remote_shipment_identity_and_terminal_fulfillment_state_block_family_mutations(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $raw = (array) $order->raw_payload;
        $raw['meta_data'] = [
            ['key' => '_inpost_locker_id', 'value' => '12345'],
        ];
        $order->update(['raw_payload' => $raw]);

        $splitAvailability = app(OrderSplitService::class)->availability($order->fresh());
        $this->assertTrue($splitAvailability['available'], implode(' ', $splitAvailability['reasons']));
        $child = app(OrderSplitService::class)->split($order->fresh(), [$secondLine->id => 1]);
        $this->assertSame('12345', data_get($child->raw_payload, 'meta_data.0.value'));

        $childRaw = (array) $child->raw_payload;
        $childRaw['meta_data'][] = ['key' => '_easypack_parcel_id', 'value' => '987654'];
        $child->update(['raw_payload' => $childRaw]);
        ShippingLabel::query()->create([
            'sales_channel_id' => $child->sales_channel_id,
            'external_order_id' => $child->id,
            'purpose' => 'shipment',
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'DIFFERENT-SHIPMENT-ID',
            'tracking_number' => 'DIFFERENT-TRACKING-ID',
            'disk' => 'local',
            'path' => 'shipping-labels/different.pdf',
            'generated_at' => now(),
        ]);
        $shipmentBlock = app(OrderSplitReversalService::class)->availability($child->fresh());
        $this->assertFalse($shipmentBlock['available']);
        $this->assertStringContainsString('identyfikator przesyłki', implode(' ', $shipmentBlock['reasons']));

        $child->update([
            'raw_payload' => array_replace($childRaw, ['meta_data' => [
                ['key' => '_inpost_locker_id', 'value' => '12345'],
            ]]),
            'fulfillment_status' => 'awaiting_courier',
        ]);
        $fulfillmentBlock = app(OrderSplitReversalService::class)->availability($child->fresh());
        $this->assertTrue($fulfillmentBlock['available'], implode(' ', $fulfillmentBlock['reasons']));
        $this->assertFalse(app(OrderSplitService::class)->availability($order->fresh())['available']);
    }

    public function test_changed_pickup_point_blocks_reversal_but_tracking_and_erp_metadata_do_not(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $raw = (array) $order->raw_payload;
        $raw['meta_data'] = [
            ['key' => '_inpost_target_point', 'value' => 'KRA01A'],
        ];
        $raw['sempre_erp_target_point'] = 'KRA01A';
        $order->update(['raw_payload' => $raw]);
        $child = app(OrderSplitService::class)->split($order->fresh(), [$secondLine->id => 1]);

        $changedRaw = (array) $order->fresh()->raw_payload;
        $changedRaw['meta_data'] = [
            ['key' => '_inpost_target_point', 'value' => 'KRA02A'],
        ];
        $order->update(['raw_payload' => $changedRaw]);

        $reversal = app(OrderSplitReversalService::class);
        $changedPoint = $reversal->availability($child->fresh());

        $this->assertFalse($changedPoint['available']);
        $this->assertStringContainsString(
            'Dane handlowe zamówienia w WooCommerce zmieniły się',
            implode(' ', $changedPoint['reasons']),
        );

        $technicalOnlyRaw = (array) $order->fresh()->raw_payload;
        $technicalOnlyRaw['meta_data'] = [
            ['key' => '_inpost_target_point', 'value' => 'KRA01A'],
            ['key' => '_inpost_tracking_number', 'value' => 'TRACK-TECHNICAL-1'],
            ['key' => 'sempre_erp_internal_marker', 'value' => 'ignored'],
        ];
        $technicalOnlyRaw['sempre_erp_target_point'] = 'KRA02A';
        $order->update(['raw_payload' => $technicalOnlyRaw]);

        $changedErpPoint = $reversal->availability($child->fresh());

        $this->assertFalse($changedErpPoint['available']);

        $technicalOnlyRaw['sempre_erp_target_point'] = 'KRA01A';
        $order->update(['raw_payload' => $technicalOnlyRaw]);
        ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => 'SHIP-TECHNICAL-1',
            'tracking_number' => 'TRACK-TECHNICAL-1',
            'disk' => 'local',
            'path' => 'shipping-labels/technical-meta.pdf',
            'generated_at' => now(),
        ]);

        $technicalOnly = $reversal->availability($child->fresh());

        $this->assertTrue($technicalOnly['available'], implode(' ', $technicalOnly['reasons']));
    }

    public function test_an_incomplete_reversal_blocks_family_mutations_but_the_same_reversal_can_resume(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $child = app(OrderSplitService::class)->split($order, [$secondLine->id => 1]);
        $raw = (array) $order->fresh()->raw_payload;
        $raw['sempre_erp_split_reversal_operation'] = [
            'uuid' => '20000000-0000-4000-8000-000000000001',
            'status' => 'failed',
            'steps' => ['shipping' => ['status' => 'completed']],
        ];
        $order->fresh()->update(['raw_payload' => $raw]);
        $mutationRan = false;

        try {
            app(OrderMutationLock::class)->forOrder($child->fresh(), function () use (&$mutationRan): void {
                $mutationRan = true;
            });
            $this->fail('A normal family mutation must be blocked while split reversal is incomplete.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('niedokończone cofanie podziału', $exception->getMessage());
        }

        $this->assertFalse($mutationRan);

        $reversal = app(OrderSplitReversalService::class);
        $availability = $reversal->availability($child->fresh());
        $restored = $reversal->reverse($child->fresh(), $availability['version']);

        $this->assertSame($order->id, $restored->id);
        $this->assertSoftDeleted('external_orders', ['id' => $child->id]);
    }

    public function test_unknown_line_cannot_create_an_empty_split_order(): void
    {
        [$order] = $this->orderWithTwoLines();

        try {
            app(OrderSplitService::class)->split($order, [999999 => 1]);
            $this->fail('Expected split validation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Żadna wskazana pozycja', $exception->getMessage());
        }

        $this->assertSame(1, ExternalOrder::query()->count());
        $this->assertCount(2, $order->fresh('lines')->lines);
        $this->assertSame('530.00', (string) $order->fresh()->total_gross);
    }

    public function test_failed_local_synchronization_rolls_back_the_child_and_retry_creates_only_one_split(): void
    {
        [$order, , $secondLine] = $this->orderWithTwoLines();
        $failOnce = true;
        PackingTask::creating(function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;

                throw new RuntimeException('Wstrzyknięta awaria synchronizacji zadań.');
            }
        });

        try {
            app(OrderSplitService::class)->split($order, [$secondLine->id => 1]);
            $this->fail('The injected synchronization failure should abort the split.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Wstrzyknięta awaria', $exception->getMessage());
        }

        $restoredAfterFailure = $order->fresh('lines');
        $this->assertSame(1, ExternalOrder::query()->count());
        $this->assertSame('530.00', (string) $restoredAfterFailure->total_gross);
        $this->assertCount(2, $restoredAfterFailure->lines);
        $this->assertArrayNotHasKey('sempre_erp_split_original', (array) $restoredAfterFailure->raw_payload);
        $this->assertSame(0, PackingTask::query()->count());

        $child = app(OrderSplitService::class)->split(
            $restoredAfterFailure,
            [$restoredAfterFailure->lines->firstWhere('external_line_id', $secondLine->external_line_id)->id => 1],
        );

        $this->assertSame(2, ExternalOrder::query()->count());
        $this->assertSame($order->id, $child->split_root_order_id);
        $this->assertSame(1, ExternalOrder::query()->where('split_root_order_id', $order->id)->count());

        PackingTask::flushEventListeners();
    }

    /** @return array{ExternalOrder,mixed,mixed} */
    private function orderWithTwoLines(): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'SPLIT-UNDO',
            'name' => 'Split undo',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'UNDO-1001',
            'external_number' => 'UNDO/1001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 530.00,
            'billing_data' => ['email' => 'customer@example.test'],
            'raw_payload' => [
                'id' => 1001,
                'number' => 'UNDO/1001',
                'total' => '530.00',
                'payment_method' => 'cod',
                'payment_method_title' => 'Płatność za pobraniem',
                'sempre_erp_shipping_decision' => [
                    'decision' => 'wait_for_all',
                    'decided_by' => 'Operator',
                ],
            ],
            'external_created_at' => now(),
        ]);
        $firstLine = $order->lines()->create([
            'external_line_id' => 'line-1',
            'canonical_external_line_id' => 'line-1',
            'sku' => 'SKU-1',
            'name' => 'Pierwszy produkt',
            'quantity' => 2,
            'unit_net_price' => 100,
            'unit_gross_price' => 123,
            'vat_rate' => 23,
            'raw_payload' => [
                'id' => 'line-1',
                'quantity' => 2,
                'total' => '200.00',
                'total_tax' => '46.00',
            ],
        ]);
        $secondLine = $order->lines()->create([
            'external_line_id' => 'line-2',
            'canonical_external_line_id' => 'line-2',
            'sku' => 'SKU-2',
            'name' => 'Drugi produkt',
            'quantity' => 1,
            'unit_net_price' => 230,
            'unit_gross_price' => 283,
            'vat_rate' => 23,
            'raw_payload' => [
                'id' => 'line-2',
                'quantity' => 1,
                'total' => '230.00',
                'total_tax' => '53.00',
            ],
        ]);

        return [$order, $firstLine, $secondLine];
    }
}
