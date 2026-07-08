<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Services\Returns\ReturnSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        $this->postJson('/api/store-returns', $payload, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('external_id', $returnCase->number);

        $this->assertSame(1, ReturnCase::query()->count());
    }

    public function test_store_return_caps_quantity_at_remaining_returnable(): void
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
        ], $this->authHeaders())->assertOk();

        $this->assertSame(2.0, (float) ReturnCase::query()->firstOrFail()->lines()->first()->quantity_expected);
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
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

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
