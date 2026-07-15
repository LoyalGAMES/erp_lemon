<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\OrderCancellation;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Payments\PayuRefundSettingsService;
use App\Services\Returns\ReturnSettingsService;
use App\Services\Returns\StoreReturnIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class ReturnCancellationInterlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_and_store_return_intake_are_blocked_for_a_cancelled_split_family(): void
    {
        Queue::fake();
        [$root, $child, $product, $warehouse] = $this->splitOrderFamily();
        $this->cancellation($root, 'completed');

        $this->post(route('returns.store'), [
            'external_order_id' => $child->id,
            'target_warehouse_id' => $warehouse->id,
            'lines' => [[
                'external_order_line_id' => $child->lines->firstOrFail()->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'condition' => 'unchecked',
                'disposition' => 'restock',
            ]],
        ])->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'proces anulowania'));

        $this->assertSame(0, ReturnCase::query()->count());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('proces anulowania');

        app(StoreReturnIntakeService::class)->createFromStorePayload($this->storePayload($child));
    }

    public function test_rejected_cancellation_allows_store_return_intake(): void
    {
        Queue::fake();
        [$root, $child, , $warehouse] = $this->splitOrderFamily('REJECTED');
        $this->cancellation($root, 'rejected');
        $this->configureReturnSettings($warehouse);

        $returnCase = app(StoreReturnIntakeService::class)
            ->createFromStorePayload($this->storePayload($child, 'RETURN-REJECTED-CANCEL'));

        $this->assertSame($root->id, $returnCase->external_order_id);
        $this->assertSame(StoreReturnIntakeService::STATUS_PENDING, $returnCase->status);
    }

    public function test_pending_return_cannot_be_approved_when_cancellation_exists(): void
    {
        Http::fake();
        [$root, $child, , $warehouse] = $this->splitOrderFamily('APPROVE');
        $returnCase = ReturnCase::query()->create([
            'number' => 'RET/APPROVE/1',
            'external_order_id' => $child->id,
            'target_warehouse_id' => $warehouse->id,
            'status' => StoreReturnIntakeService::STATUS_PENDING,
            'metadata' => [
                'site_url' => 'https://shop.example.test',
                'return_reference' => 'RETURN-APPROVE-1',
            ],
        ]);
        $this->cancellation($root, 'attention_required');

        $this->post(route('returns.approve', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'proces anulowania'));

        $this->assertSame(StoreReturnIntakeService::STATUS_PENDING, $returnCase->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_payu_refund_is_blocked_before_any_remote_request_or_payment_claim(): void
    {
        Http::fake();
        [$root, $child, , $warehouse] = $this->splitOrderFamily('PAYU', [
            'payment_method' => 'payu',
            'payment_method_title' => 'PayU',
            'payu_order_id' => 'PAYU-ORDER-1',
        ]);
        $returnCase = ReturnCase::query()->create([
            'number' => 'RET/PAYU/1',
            'external_order_id' => $child->id,
            'target_warehouse_id' => $warehouse->id,
            'status' => 'corrected',
        ]);
        $invoice = $this->correctionInvoice($child);
        $returnCase->update(['correction_invoice_id' => $invoice->id]);
        $this->cancellation($root, 'completed');
        app(PayuRefundSettingsService::class)->update([
            'enabled' => true,
            'auto_refund_enabled' => false,
            'environment' => 'sandbox',
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'refund_type' => 'REFUND_PAYMENT_STANDARD',
        ]);

        $this->post(route('returns.payu-refund', $returnCase))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'proces anulowania'));

        $this->assertSame(0, CustomerPayment::query()->count());
        Http::assertNothingSent();
    }

    public function test_posting_return_receipt_is_blocked_before_stock_or_status_changes(): void
    {
        [$root, $child, $product, $warehouse] = $this->splitOrderFamily('RX');
        $returnCase = ReturnCase::query()->create([
            'number' => 'RET/RX/1',
            'external_order_id' => $child->id,
            'target_warehouse_id' => $warehouse->id,
            'status' => 'document_created',
        ]);
        $document = WarehouseDocument::query()->create([
            'number' => 'RX/LOCK/1',
            'type' => 'RX',
            'status' => 'draft',
            'destination_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'metadata' => ['return_case_id' => $returnCase->id],
        ]);
        $document->lines()->create([
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $returnCase->update(['warehouse_document_id' => $document->id]);
        $returnCase->lines()->create([
            'product_id' => $product->id,
            'external_order_line_id' => $child->lines->firstOrFail()->id,
            'warehouse_document_id' => $document->id,
            'quantity_expected' => 1,
            'quantity_accepted' => 1,
            'condition' => 'unchecked',
            'disposition' => 'restock',
            'target_warehouse_id' => $warehouse->id,
        ]);
        $this->cancellation($root, 'completed');

        $this->post(route('documents.post', $document))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'proces anulowania'));

        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame('document_created', $returnCase->fresh()->status);
        $this->assertSame(0, StockLedgerEntry::query()->count());
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     * @return array{ExternalOrder,ExternalOrder,Product,Warehouse}
     */
    private function splitOrderFamily(string $suffix = 'ACTIVE', array $rawPayload = []): array
    {
        $rawPayload += [
            'payment_method' => 'stripe',
            'payment_method_title' => 'Karta Stripe',
        ];

        $channel = SalesChannel::query()->create([
            'code' => 'RETURN-CANCEL-'.$suffix,
            'name' => 'Return cancellation '.$suffix,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'RET-'.$suffix,
            'name' => 'Magazyn zwrotów '.$suffix,
            'type' => 'returns',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'RETURN-'.$suffix,
            'name' => 'Produkt zwracany '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $root = $this->order($channel, '9000-'.$suffix, $rawPayload);
        $child = $this->order($channel, '9000-'.$suffix.'-S1', $rawPayload, [
            'split_parent_order_id' => $root->id,
            'split_root_order_id' => $root->id,
        ]);
        $child->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-'.$suffix,
            'canonical_external_line_id' => 'line-'.$suffix,
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 2,
            'unit_gross_price' => 100,
        ]);

        $this->configureReturnSettings($warehouse);

        return [$root, $child->fresh('lines'), $product, $warehouse];
    }

    /** @param array<string, mixed> $rawPayload @param array<string, mixed> $attributes */
    private function order(
        SalesChannel $channel,
        string $number,
        array $rawPayload = [],
        array $attributes = [],
    ): ExternalOrder {
        return ExternalOrder::query()->create(array_merge([
            'sales_channel_id' => $channel->id,
            'external_id' => $number,
            'external_number' => $number,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 200,
            'billing_data' => ['email' => 'client@example.test'],
            'raw_payload' => $rawPayload,
            'external_created_at' => now()->subDay(),
        ], $attributes));
    }

    private function cancellation(ExternalOrder $root, string $status): OrderCancellation
    {
        return OrderCancellation::query()->create([
            'uuid' => fake()->uuid(),
            'external_order_id' => $root->id,
            'status' => $status,
            'reason' => 'Anulowanie w teście blokady zwrotu',
            'refund_status' => $status === 'completed' ? 'confirmed' : 'pending',
            'currency' => 'PLN',
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function storePayload(
        ExternalOrder $order,
        string $returnReference = 'RETURN-ACTIVE-CANCEL',
    ): array {
        return [
            'return_reference' => $returnReference,
            'order_reference' => $order->external_number,
            'customer_contact' => 'client@example.test',
            'customer_email' => 'client@example.test',
            'items' => [[
                'id' => $order->lines->firstOrFail()->external_line_id,
                'quantity' => 1,
                'reason' => 'wrong_size',
            ]],
        ];
    }

    private function configureReturnSettings(Warehouse $warehouse): void
    {
        app(ReturnSettingsService::class)->update([
            'numbering_pattern' => '{PREFIX}/{YYYY}/{SEQ}',
            'numbering_prefix' => 'RET',
            'numbering_padding' => 6,
            'default_target_warehouse_id' => $warehouse->id,
            'default_condition' => 'unchecked',
            'default_disposition' => 'restock',
            'conditions' => [['code' => 'unchecked', 'label' => 'Niezweryfikowany']],
            'dispositions' => [['code' => 'restock', 'label' => 'Przywróć na stan', 'warehouse_id' => $warehouse->id]],
        ]);
    }

    private function correctionInvoice(ExternalOrder $order): Invoice
    {
        return Invoice::query()->create([
            'number' => 'FK/RETURN-CANCEL/1',
            'type' => 'correction',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => ['name' => 'Sempre'],
            'buyer_data' => ['name' => 'Klient'],
            'net_total' => -81.30,
            'vat_total' => -18.70,
            'gross_total' => -100,
            'payment_method' => 'PayU',
            'issued_at' => now(),
        ]);
    }
}
