<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendReturnWaitingForPackageMailJob;
use App\Models\AppSetting;
use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Services\Returns\ReturnSettingsService;
use App\Support\OperationalStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StoreReturnsApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-store-token';

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        app(ReturnSettingsService::class)->update([
            'numbering_pattern' => '{PREFIX}/{YYYY}/{SEQ}',
            'numbering_prefix' => 'RET',
            'numbering_padding' => 6,
            'default_condition' => 'unchecked',
            'default_disposition' => 'restock',
            'conditions' => [['code' => 'unchecked', 'label' => 'Niezweryfikowany']],
            'dispositions' => [['code' => 'restock', 'label' => 'Przywróć na stan', 'warehouse_id' => null]],
            'store_api_token' => self::TOKEN,
            'store_webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    public function test_api_rejects_missing_and_invalid_tokens(): void
    {
        $this->postJson('/api/store-returns/status', [])
            ->assertStatus(401);

        $this->postJson('/api/store-returns/status', [], ['Authorization' => 'Bearer wrong-token'])
            ->assertStatus(401);
    }

    public function test_returns_navigation_and_page_warn_when_intake_api_is_not_configured(): void
    {
        AppSetting::query()->where('key', 'return_settings')->delete();

        $status = app(OperationalStatus::class)->navigation()['store_returns'];

        $this->assertSame('red', $status['tone']);
        $this->assertSame('API nieaktywne', $status['label']);

        $this->get(route('returns.index'))
            ->assertOk()
            ->assertSee('API formularza zwrotów jest nieaktywne')
            ->assertSee('nie uruchamiają maila do klienta');
    }

    public function test_order_lookup_requires_matching_contact_and_returns_woo_ids(): void
    {
        $order = $this->createOrder();

        $this->postJson('/api/store-returns/lookup-order', [
            'order_reference' => '12345',
            'contact' => 'obcy@example.test',
        ], $this->authHeaders())->assertStatus(404);

        $response = $this->postJson('/api/store-returns/lookup-order', [
            'order_reference' => '12345',
            'contact' => 'klient@example.test',
        ], $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('order.source', 'erp')
            ->assertJsonPath('order.order_id', (string) $order->external_id)
            ->assertJsonPath('order.items.0.id', '771')
            ->assertJsonPath('order.items.0.quantity', 2);
    }

    public function test_store_return_creates_pending_case_and_is_idempotent(): void
    {
        Queue::fake();
        $order = $this->createOrder();

        $payload = [
            'return_reference' => 'LLR-20260708-TEST01',
            'local_return_id' => 123,
            'order_reference' => '12345',
            'order_number' => '12345',
            'return_method' => 'wygodne_zwroty',
            'customer_contact' => 'klient@example.test',
            'customer_email' => 'klient@example.test',
            'customer_note' => 'Proszę o szybki zwrot',
            'site_url' => 'https://sklep.example.test',
            'items' => [
                ['id' => '771', 'name' => 'Produkt testowy', 'sku' => 'SKU-1', 'quantity' => 1, 'reason' => 'wrong_size'],
            ],
        ];

        $response = $this->postJson('/api/store-returns', $payload, $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'pending_package');

        $returnCase = ReturnCase::query()->with('lines')->firstOrFail();

        $this->assertSame('pending', $returnCase->status);
        $this->assertSame($order->id, $returnCase->external_order_id);
        $this->assertSame('store_form', data_get($returnCase->metadata, 'source'));
        $this->assertSame('LLR-20260708-TEST01', data_get($returnCase->metadata, 'return_reference'));
        $this->assertSame('https://sklep.example.test', data_get($returnCase->metadata, 'site_url'));
        $this->assertCount(1, $returnCase->lines);
        $this->assertSame($order->lines->first()->id, $returnCase->lines->first()->external_order_line_id);
        $this->assertSame($response->json('external_id'), $returnCase->number);

        Queue::assertPushed(
            SendReturnWaitingForPackageMailJob::class,
            fn (SendReturnWaitingForPackageMailJob $job): bool => $job->uniqueId() === (string) $returnCase->id,
        );

        $this->postJson('/api/store-returns', $payload, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('external_id', $returnCase->number);

        $this->assertSame(1, ReturnCase::query()->count());
        Queue::assertPushed(SendReturnWaitingForPackageMailJob::class, 1);
    }

    public function test_lookup_exposes_cashback_for_automatic_payment(): void
    {
        $order = $this->createOrder();
        $order->update(['raw_payload' => [
            'payment_method' => 'stripe',
            'payment_method_title' => 'Karta Stripe',
        ]]);

        $this->postJson('/api/store-returns/lookup-order', [
            'order_reference' => '12345',
            'contact' => 'klient@example.test',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('order.payment_method', 'Karta Stripe')
            ->assertJsonPath('order.refund_method', 'cashback');
    }

    public function test_cod_return_requires_bank_account_and_enters_mbank_metadata(): void
    {
        $order = $this->createOrder();
        $order->update(['raw_payload' => [
            'payment_method' => 'cod',
            'payment_method_title' => 'Płatność za pobraniem',
        ]]);
        $payload = [
            'return_reference' => 'LLR-COD-ACCOUNT',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'refund_method' => 'cashback',
            'items' => [['id' => '771', 'quantity' => 1]],
        ];

        $this->postJson('/api/store-returns', $payload, $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson('/api/store-returns', $payload + [
            'refund_bank_account' => 'PL11 1020 3352 0000 2053 1234 5060',
            'refund_recipient_name' => 'Jan Testowy',
        ], $this->authHeaders())->assertOk();

        $returnCase = ReturnCase::query()->firstOrFail();
        $this->assertSame('bank_transfer', data_get($returnCase->metadata, 'refund_method'));
        $this->assertSame('11102033520000205312345060', data_get($returnCase->metadata, 'refund_bank_account'));
        $this->assertSame('Jan Testowy', data_get($returnCase->metadata, 'refund_recipient_name'));
    }

    public function test_store_return_rejects_quantity_above_remaining_returnable(): void
    {
        $this->createOrder();

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-20260708-TEST02',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'site_url' => 'https://sklep.example.test',
            'items' => [
                ['id' => '771', 'quantity' => 99],
            ],
        ], $this->authHeaders())
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(0, ReturnCase::query()->count());
    }

    public function test_partial_return_leaves_only_the_remaining_quantity_and_blocks_a_duplicate(): void
    {
        $this->createOrder();

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-PARTIAL-1',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => '771', 'quantity' => 1],
            ],
        ], $this->authHeaders())->assertOk();

        $this->postJson('/api/store-returns/lookup-order', [
            'order_reference' => '12345',
            'contact' => 'klient@example.test',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('order.items.0.id', '771')
            ->assertJsonPath('order.items.0.quantity', 1)
            ->assertJsonPath('order.accounted_return_references.0', 'LLR-PARTIAL-1');

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-PARTIAL-2',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => '771', 'quantity' => 1],
            ],
        ], $this->authHeaders())->assertOk();

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-PARTIAL-DUPLICATE',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => '771', 'quantity' => 1],
            ],
        ], $this->authHeaders())->assertStatus(422);

        $this->assertSame(2, ReturnCase::query()->count());
    }

    public function test_returning_one_product_keeps_other_order_products_available(): void
    {
        $order = $this->createOrder();
        $secondProduct = Product::query()->create([
            'sku' => 'SKU-2',
            'name' => 'Drugi produkt',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $order->lines()->create([
            'product_id' => $secondProduct->id,
            'external_line_id' => '772',
            'canonical_external_line_id' => '772',
            'sku' => 'SKU-2',
            'name' => 'Drugi produkt',
            'quantity' => 3,
            'unit_gross_price' => 49.99,
        ]);

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-FIRST-PRODUCT',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => '771', 'quantity' => 2],
            ],
        ], $this->authHeaders())->assertOk();

        $response = $this->postJson('/api/store-returns/lookup-order', [
            'order_reference' => '12345',
            'contact' => 'klient@example.test',
        ], $this->authHeaders())->assertOk();

        $this->assertSame([['id' => '772', 'quantity' => 3]], collect($response->json('order.items'))
            ->map(fn (array $item): array => ['id' => $item['id'], 'quantity' => $item['quantity']])
            ->values()
            ->all());
    }

    public function test_rejected_and_cancelled_returns_release_reserved_quantity(): void
    {
        $this->createOrder();

        foreach (['rejected', 'cancelled'] as $index => $status) {
            $response = $this->postJson('/api/store-returns', [
                'return_reference' => 'LLR-RELEASE-'.$index,
                'order_reference' => '12345',
                'customer_contact' => 'klient@example.test',
                'items' => [
                    ['id' => '771', 'quantity' => 2],
                ],
            ], $this->authHeaders())->assertOk();

            ReturnCase::query()
                ->where('number', $response->json('external_id'))
                ->firstOrFail()
                ->update(['status' => $status]);

            $this->postJson('/api/store-returns/lookup-order', [
                'order_reference' => '12345',
                'contact' => 'klient@example.test',
            ], $this->authHeaders())
                ->assertOk()
                ->assertJsonPath('order.items.0.quantity', 2);
        }
    }

    public function test_split_order_lookup_uses_root_order_and_original_woo_line_id(): void
    {
        $order = $this->createOrder();
        $sourceLine = $order->lines->firstOrFail();
        $splitOrder = ExternalOrder::query()->create([
            'split_parent_order_id' => $order->id,
            'split_root_order_id' => $order->id,
            'sales_channel_id' => $order->sales_channel_id,
            'external_id' => '9001-SPLIT-1',
            'external_number' => '12345/S1',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 199.99,
            'billing_data' => $order->billing_data,
            'raw_payload' => [
                'sempre_erp_split' => [
                    'parent_order_id' => $order->id,
                    'root_order_id' => $order->id,
                ],
            ],
        ]);
        $splitLine = $splitOrder->lines()->create([
            'product_id' => $sourceLine->product_id,
            'external_line_id' => '771-S1',
            'canonical_external_line_id' => '771',
            'sku' => $sourceLine->sku,
            'name' => $sourceLine->name,
            'quantity' => 2,
            'unit_gross_price' => $sourceLine->unit_gross_price,
            'raw_payload' => [
                'sempre_erp_split' => [
                    'source_order_line_id' => $sourceLine->id,
                    'source_external_line_id' => '771',
                    'root_external_line_id' => '771',
                ],
            ],
        ]);
        $sourceLine->delete();

        foreach (['12345', '12345/S1'] as $reference) {
            $this->postJson('/api/store-returns/lookup-order', [
                'order_reference' => $reference,
                'contact' => 'klient@example.test',
            ], $this->authHeaders())
                ->assertOk()
                ->assertJsonPath('order.order_id', '9001')
                ->assertJsonPath('order.order_reference', '12345')
                ->assertJsonPath('order.items.0.id', '771')
                ->assertJsonPath('order.items.0.wc_order_item_id', '771')
                ->assertJsonPath('order.items.0.quantity', 2);
        }

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-SPLIT',
            'order_reference' => '12345/S1',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => '771', 'quantity' => 2],
            ],
        ], $this->authHeaders())->assertOk();

        $returnCase = ReturnCase::query()->with('lines')->firstOrFail();

        $this->assertSame($order->id, $returnCase->external_order_id);
        $this->assertSame($splitLine->id, $returnCase->lines->first()->external_order_line_id);
        $this->assertSame('771', $returnCase->lines->first()->canonical_external_line_id);
    }

    public function test_partial_split_allocates_successive_returns_across_physical_lines(): void
    {
        $order = $this->createOrder();
        $rootLine = $order->lines->firstOrFail();
        $rootLine->update([
            'quantity' => 1,
            'canonical_external_line_id' => '771',
        ]);
        $splitOrder = ExternalOrder::query()->create([
            'split_parent_order_id' => $order->id,
            'split_root_order_id' => $order->id,
            'sales_channel_id' => $order->sales_channel_id,
            'external_id' => '9001-SPLIT-1',
            'external_number' => '12345/S1',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 99.99,
            'billing_data' => $order->billing_data,
        ]);
        $splitLine = $splitOrder->lines()->create([
            'product_id' => $rootLine->product_id,
            'external_line_id' => '771-S1',
            'canonical_external_line_id' => '771',
            'sku' => $rootLine->sku,
            'name' => $rootLine->name,
            'quantity' => 1,
            'unit_gross_price' => $rootLine->unit_gross_price,
        ]);

        foreach ([1, 2] as $index) {
            $this->postJson('/api/store-returns', [
                'return_reference' => 'LLR-SPLIT-PART-'.$index,
                'order_reference' => '12345',
                'customer_contact' => 'klient@example.test',
                'items' => [
                    ['id' => '771', 'quantity' => 1],
                ],
            ], $this->authHeaders())->assertOk();
        }

        $lineIds = ReturnCase::query()
            ->orderBy('id')
            ->with('lines')
            ->get()
            ->map(fn (ReturnCase $case): ?int => $case->lines->first()?->external_order_line_id)
            ->all();

        $this->assertSame([$rootLine->id, $splitLine->id], $lineIds);
    }

    public function test_unknown_and_duplicated_item_ids_are_rejected_without_creating_a_case(): void
    {
        $this->createOrder();

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-UNKNOWN-ITEM',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => 'not-an-order-line', 'sku' => 'SKU-1', 'quantity' => 1],
            ],
        ], $this->authHeaders())->assertStatus(422);

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-DUPLICATED-ITEM',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => '771', 'quantity' => 1],
                ['id' => '771', 'quantity' => 1],
            ],
        ], $this->authHeaders())->assertStatus(422);

        $this->assertSame(0, ReturnCase::query()->count());
    }

    public function test_two_order_lines_with_the_same_product_and_sku_remain_independent(): void
    {
        $order = $this->createOrder();
        $firstLine = $order->lines->firstOrFail();
        $firstLine->update(['quantity' => 1]);
        $order->lines()->create([
            'product_id' => $firstLine->product_id,
            'external_line_id' => '772',
            'canonical_external_line_id' => '772',
            'sku' => $firstLine->sku,
            'name' => $firstLine->name,
            'quantity' => 1,
            'unit_gross_price' => $firstLine->unit_gross_price,
        ]);

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-SAME-SKU',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => '771', 'sku' => 'SKU-1', 'quantity' => 1],
            ],
        ], $this->authHeaders())->assertOk();

        $response = $this->postJson('/api/store-returns/lookup-order', [
            'order_reference' => '12345',
            'contact' => 'klient@example.test',
        ], $this->authHeaders())->assertOk();

        $this->assertSame(['772'], collect($response->json('order.items'))->pluck('id')->all());
        $this->assertSame(1, $response->json('order.items.0.quantity'));
    }

    public function test_existing_return_stays_reserved_after_source_line_is_moved_to_a_split(): void
    {
        $order = $this->createOrder();
        $sourceLine = $order->lines->firstOrFail();

        $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-BEFORE-SPLIT',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'items' => [
                ['id' => '771', 'quantity' => 1],
            ],
        ], $this->authHeaders())->assertOk();

        $splitOrder = ExternalOrder::query()->create([
            'split_parent_order_id' => $order->id,
            'split_root_order_id' => $order->id,
            'sales_channel_id' => $order->sales_channel_id,
            'external_id' => '9001-SPLIT-1',
            'external_number' => '12345/S1',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 199.99,
            'billing_data' => $order->billing_data,
        ]);
        $splitOrder->lines()->create([
            'product_id' => $sourceLine->product_id,
            'external_line_id' => '771-S1',
            'canonical_external_line_id' => '771',
            'sku' => $sourceLine->sku,
            'name' => $sourceLine->name,
            'quantity' => 2,
            'unit_gross_price' => $sourceLine->unit_gross_price,
        ]);
        $sourceLine->delete();

        $this->postJson('/api/store-returns/lookup-order', [
            'order_reference' => '12345',
            'contact' => 'klient@example.test',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('order.items.0.id', '771')
            ->assertJsonPath('order.items.0.quantity', 1);
    }

    public function test_status_endpoint_reports_current_state(): void
    {
        $this->createOrder();
        $returnCase = $this->createPendingReturn();

        $this->postJson('/api/store-returns/status', [
            'return_reference' => 'LLR-20260708-TEST03',
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'pending_package')
            ->assertJsonPath('external_id', $returnCase->number);

        $returnCase->update(['status' => 'completed']);

        $this->postJson('/api/store-returns/status', [
            'external_id' => $returnCase->number,
        ], $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'Zwrot zrealizowany');
    }

    public function test_pending_tab_lists_and_search_finds_store_returns(): void
    {
        $this->createOrder();
        $returnCase = $this->createPendingReturn();

        $this->get(route('returns.index', ['tab' => 'pending']))
            ->assertOk()
            ->assertSee($returnCase->number)
            ->assertSee('Otwórz kartę')
            ->assertDontSee('Zatwierdź');

        $this->get(route('returns.show', $returnCase))
            ->assertOk()
            ->assertSee('Zatwierdź');

        $this->get(route('returns.index', ['q' => 'LLR-20260708-TEST03']))
            ->assertOk()
            ->assertSee($returnCase->number);

        $this->get(route('returns.index', ['q' => 'nie-istnieje-xyz']))
            ->assertOk()
            ->assertDontSee($returnCase->number);
    }

    public function test_approve_pushes_completed_status_webhook_to_store(): void
    {
        Http::fake([
            'sklep.example.test/*' => Http::response(['success' => true], 200),
        ]);

        $this->createOrder();
        $returnCase = $this->createPendingReturn();

        $this->post(route('returns.approve', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('completed', $returnCase->fresh()->status);

        Http::assertSent(function ($request) use ($returnCase): bool {
            return $request->url() === 'https://sklep.example.test/wp-json/lemon-returns/v1/status'
                && $request->hasHeader('X-Lemon-Returns-Token', self::WEBHOOK_SECRET)
                && $request['return_reference'] === 'LLR-20260708-TEST03'
                && $request['external_id'] === $returnCase->number
                && $request['status'] === 'Zwrot zrealizowany';
        });
    }

    public function test_reject_pushes_rejected_status_and_approve_requires_pending(): void
    {
        Http::fake([
            'sklep.example.test/*' => Http::response(['success' => true], 200),
        ]);

        $this->createOrder();
        $returnCase = $this->createPendingReturn();

        $this->post(route('returns.reject', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('rejected', $returnCase->fresh()->status);

        Http::assertSent(fn ($request): bool => $request['status'] === 'rejected');

        $this->post(route('returns.approve', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_approve_survives_unreachable_store(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->createOrder();
        $returnCase = $this->createPendingReturn();

        $this->post(route('returns.approve', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('completed', $returnCase->fresh()->status);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.self::TOKEN];
    }

    private function createOrder(): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'name' => 'Sklep testowy',
            'code' => 'shop',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $product = Product::query()->create([
            'sku' => 'SKU-1',
            'name' => 'Produkt testowy',
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);

        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001',
            'external_number' => '12345',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 199.99,
            'billing_data' => [
                'email' => 'klient@example.test',
                'phone' => '+48 123 123 123',
                'first_name' => 'Jan',
                'last_name' => 'Testowy',
            ],
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => '771',
            'sku' => 'SKU-1',
            'name' => 'Produkt testowy',
            'quantity' => 2,
            'unit_gross_price' => 99.99,
        ]);

        return $order->load('lines');
    }

    private function createPendingReturn(): ReturnCase
    {
        $response = $this->postJson('/api/store-returns', [
            'return_reference' => 'LLR-20260708-TEST03',
            'order_reference' => '12345',
            'customer_contact' => 'klient@example.test',
            'customer_email' => 'klient@example.test',
            'return_method' => 'own_shipping',
            'site_url' => 'https://sklep.example.test',
            'items' => [
                ['id' => '771', 'name' => 'Produkt testowy', 'sku' => 'SKU-1', 'quantity' => 1, 'reason' => 'wrong_size'],
            ],
        ], $this->authHeaders());

        $response->assertOk();

        return ReturnCase::query()->where('number', $response->json('external_id'))->firstOrFail();
    }
}
