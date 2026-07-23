<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Invoices\ReturnCorrectionInvoiceService;
use App\Services\Payments\MbankTransferBasketService;
use App\Services\Payments\PayuRefundService;
use App\Services\Payments\PayuRefundSettingsService;
use App\Services\Returns\ReturnSettingsService;
use App\Services\Returns\ReturnShippingRefundService;
use App\Services\Returns\ReturnStatusPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ReturnShippingRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_oss_payu_return_refunds_configured_delivery_cost(): void
    {
        [$returnCase, $correction] = $this->createCorrection('payu', 2, true);

        $this->assertSame('-210.00', (string) $correction->net_total);
        $this->assertSame('-39.90', (string) $correction->vat_total);
        $this->assertSame('-249.90', (string) $correction->gross_total);
        $this->assertSame('11.9', (string) data_get($correction->metadata, 'shipping_refund.gross_amount'));
        $this->assertTrue((bool) data_get($correction->metadata, 'oss.correction_of_oss_invoice'));

        $shippingLine = $correction->lines->first(
            fn ($line): bool => data_get($line->metadata, 'source') === 'return_shipping_refund',
        );

        $this->assertNotNull($shippingLine);
        $this->assertSame('-10.00', (string) $shippingLine->net_total);
        $this->assertSame('-1.90', (string) $shippingLine->vat_total);
        $this->assertSame('-11.90', (string) $shippingLine->gross_total);
        $this->assertSame('19.00', (string) $shippingLine->vat_rate);

        $this->postJson('/api/store-returns/status', [
            'external_id' => $returnCase->number,
        ], [
            'Authorization' => 'Bearer return-shipping-token',
        ])
            ->assertOk()
            ->assertJsonPath('shipping_refund_amount', 11.9)
            ->assertJsonPath('shipping_refund.gross_amount', 11.9)
            ->assertJsonPath('shipping_refund.net_amount', 10)
            ->assertJsonPath('shipping_refund.tax_amount', 1.9)
            ->assertJsonPath('shipping_refund.vat_rate', 19)
            ->assertJsonPath('shipping_refund.wc_order_item_id', 'shipping-1')
            ->assertJsonPath('currency', 'PLN');

        $returnCase->update([
            'metadata' => array_merge($returnCase->metadata ?? [], [
                'site_url' => 'https://shop.test',
                'return_reference' => 'STORE-RETURN-SHIPPING',
            ]),
        ]);
        Http::fake([
            'https://shop.test/wp-json/lemon-returns/v1/status' => Http::response(['success' => true]),
        ]);

        app(ReturnStatusPushService::class)->push($returnCase->fresh());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://shop.test/wp-json/lemon-returns/v1/status'
            && $request['shipping_refund_amount'] === 11.9
            && data_get($request->data(), 'shipping_refund.net_amount') === 10.0
            && data_get($request->data(), 'shipping_refund.tax_amount') === 1.9
            && data_get($request->data(), 'shipping_refund.wc_order_item_id') === 'shipping-1'
            && $request['currency'] === 'PLN');

        app(PayuRefundSettingsService::class)->update([
            'enabled' => true,
            'auto_refund_enabled' => false,
            'environment' => 'sandbox',
            'client_id' => '300746',
            'client_secret' => 'secret',
            'refund_type' => 'REFUND_PAYMENT_STANDARD',
        ]);

        Http::fake([
            'https://secure.snd.payu.com/pl/standard/user/oauth/authorize' => Http::response([
                'access_token' => 'token-123',
            ]),
            'https://secure.snd.payu.com/api/v2_1/orders/PAYU-SHIPPING/refunds' => Http::response([
                'refund' => [
                    'refundId' => 'REF-SHIPPING',
                    'status' => 'PENDING',
                ],
                'status' => ['statusCode' => 'SUCCESS'],
            ]),
        ]);

        $payment = app(PayuRefundService::class)->refundReturn($returnCase->fresh(), $correction);

        $this->assertSame('249.90', (string) $payment->amount);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://secure.snd.payu.com/api/v2_1/orders/PAYU-SHIPPING/refunds'
            && data_get($request->data(), 'refund.amount') === '24990');
    }

    public function test_full_cash_on_delivery_return_uses_delivery_cost_in_bank_payout(): void
    {
        [$returnCase, $correction] = $this->createCorrection('cod', 2, true);

        $returnCase = $returnCase->fresh()->load(['correctionInvoice', 'lines.externalOrderLine']);

        $this->assertSame('-249.90', (string) $correction->gross_total);
        $this->assertSame(249.90, app(MbankTransferBasketService::class)->amount($returnCase));
    }

    public function test_partial_return_does_not_refund_delivery_cost(): void
    {
        [, $correction] = $this->createCorrection('cod', 1, true);

        $this->assertSame('-119.00', (string) $correction->gross_total);
        $this->assertNull(data_get($correction->metadata, 'shipping_refund'));
        $this->assertFalse($correction->lines->contains(
            fn ($line): bool => data_get($line->metadata, 'source') === 'return_shipping_refund',
        ));
    }

    public function test_free_delivery_is_not_refunded_even_for_full_return(): void
    {
        [, $correction] = $this->createCorrection('payu', 2, false);

        $this->assertSame('-238.00', (string) $correction->gross_total);
        $this->assertNull(data_get($correction->metadata, 'shipping_refund'));
    }

    public function test_foreign_currency_order_converts_configured_pln_amount_using_original_invoice_rate(): void
    {
        [$returnCase, $correction] = $this->createCorrection('payu', 2, true, 'EUR', 4.25);

        $this->assertSame('-240.80', (string) $correction->gross_total);
        $this->assertSame('2.8', (string) data_get($correction->metadata, 'shipping_refund.gross_amount'));
        $this->assertSame('11.9', (string) data_get($correction->metadata, 'shipping_refund.configured_gross_amount'));
        $this->assertSame('PLN', data_get($correction->metadata, 'shipping_refund.configured_currency'));
        $this->assertSame('EUR', data_get($correction->metadata, 'shipping_refund.currency'));
        $this->assertSame('4.25', (string) data_get($correction->metadata, 'shipping_refund.conversion_rate'));

        $this->postJson('/api/store-returns/status', [
            'external_id' => $returnCase->number,
        ], [
            'Authorization' => 'Bearer return-shipping-token',
        ])
            ->assertOk()
            ->assertJsonPath('shipping_refund_amount', 2.8)
            ->assertJsonPath('shipping_refund.currency', 'EUR')
            ->assertJsonPath('currency', 'EUR');
    }

    public function test_snapshotted_shipping_refund_is_not_changed_by_later_configuration_update(): void
    {
        $returnCase = $this->createReturnCase('cod', 2, true);

        app(ReturnShippingRefundService::class)->snapshot($returnCase);
        $this->assertSame(11.9, (float) data_get($returnCase->fresh()->metadata, 'shipping_refund_decision.gross_amount'));

        app(ReturnSettingsService::class)->update([
            'refundable_shipping_cost' => 13.45,
            'refundable_shipping_cost_currency' => 'PLN',
        ]);

        $correction = app(ReturnCorrectionInvoiceService::class)->createForReturn($returnCase->fresh())->load('lines');

        $this->assertSame('-249.90', (string) $correction->gross_total);
        $this->assertSame('11.9', (string) data_get($correction->metadata, 'shipping_refund.gross_amount'));
        $this->assertSame('11.9', (string) data_get($correction->metadata, 'shipping_refund.configured_gross_amount'));
    }

    public function test_foreign_currency_shipping_refund_requires_original_invoice_rate(): void
    {
        $returnCase = $this->createReturnCase('payu', 2, true, 'EUR');

        $this->expectExceptionMessage('faktura pierwotna nie ma prawidłowego kursu waluty');

        app(ReturnShippingRefundService::class)->snapshot($returnCase);
    }

    /**
     * @return array{ReturnCase,Invoice}
     */
    private function createCorrection(
        string $paymentMethod,
        int $returnedQuantity,
        bool $paidShipping,
        string $currency = 'PLN',
        ?float $currencyRate = null,
    ): array {
        $returnCase = $this->createReturnCase($paymentMethod, $returnedQuantity, $paidShipping, $currency, $currencyRate);
        $correction = app(ReturnCorrectionInvoiceService::class)->createForReturn($returnCase);

        return [$returnCase->fresh(), $correction->load('lines')];
    }

    private function createReturnCase(
        string $paymentMethod,
        int $returnedQuantity,
        bool $paidShipping,
        string $currency = 'PLN',
        ?float $currencyRate = null,
    ): ReturnCase {
        app(ReturnSettingsService::class)->update([
            'refundable_shipping_cost' => 11.90,
            'refundable_shipping_cost_currency' => 'PLN',
            'store_api_token' => 'return-shipping-token',
            'store_webhook_secret' => 'return-shipping-webhook',
        ]);

        $channel = SalesChannel::query()->create([
            'code' => 'RET-SHIPPING',
            'name' => 'Zwroty kosztu dostawy',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'code' => 'RET',
            'name' => 'Magazyn zwrotów',
            'type' => 'returns',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'sku' => 'SKU-DELIVERY-REFUND',
            'name' => 'Produkt OSS',
            'unit' => 'szt',
            'vat_rate' => 19,
            'quantity_precision' => 0,
            'is_active' => true,
        ]);
        $rawPayload = $paymentMethod === 'cod'
            ? [
                'payment_method' => 'cod',
                'payment_method_title' => 'Płatność za pobraniem',
            ]
            : [
                'payment_method' => 'payu',
                'payment_method_title' => 'PayU',
                'payu_order_id' => 'PAYU-SHIPPING',
            ];
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'ORDER-SHIPPING',
            'external_number' => 'ORDER-SHIPPING',
            'status' => 'completed',
            'currency' => $currency,
            'total_gross' => $paidShipping ? 252.28 : 238,
            'billing_data' => [
                'first_name' => 'Anna',
                'last_name' => 'Kowalska',
                'email' => 'anna@example.test',
                'country' => 'DE',
            ],
            'raw_payload' => $rawPayload,
        ]);
        $orderLine = $order->lines()->create([
            'product_id' => $product->id,
            'external_line_id' => 'line-oss-1',
            'sku' => $product->sku,
            'name' => $product->name,
            'quantity' => 2,
            'unit_net_price' => 100,
            'unit_gross_price' => 119,
            'vat_rate' => 19,
            'raw_payload' => [
                'total' => '200.00',
                'total_tax' => '38.00',
            ],
        ]);
        $originalInvoice = Invoice::query()->create([
            'number' => 'FV/OSS/1/07/2026',
            'type' => 'vat',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'issue_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
            'payment_due_date' => now()->toDateString(),
            'currency' => $currency,
            'seller_data' => [
                'name' => 'Sempre',
                'tax_id' => '5261040828',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'email' => 'biuro@example.test',
                'phone' => '+48123123123',
                'bank_account' => 'PL00111122223333444455556666',
            ],
            'buyer_data' => [
                'name' => 'Anna Kowalska',
                'address_1' => 'Kliencka 1',
                'country' => 'DE',
            ],
            'net_total' => $paidShipping ? 212 : 200,
            'vat_total' => $paidShipping ? 40.28 : 38,
            'gross_total' => $paidShipping ? 252.28 : 238,
            'payment_method' => $paymentMethod === 'cod' ? 'Pobranie' : 'PayU',
            'issued_at' => now(),
            'metadata' => [
                'oss' => [
                    'country' => 'DE',
                    'vat_rate' => 19,
                ],
                'currency_conversion' => $currencyRate !== null ? [
                    'currency' => $currency,
                    'rate' => $currencyRate,
                ] : null,
            ],
        ]);
        $originalInvoice->lines()->create([
            'product_id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'unit' => 'szt',
            'quantity' => 2,
            'unit_net_price' => 100,
            'net_total' => 200,
            'vat_rate' => 19,
            'vat_total' => 38,
            'gross_total' => 238,
            'metadata' => ['external_line_id' => 'line-oss-1'],
        ]);

        if ($paidShipping) {
            $originalInvoice->lines()->create([
                'product_id' => null,
                'name' => 'Kurier międzynarodowy',
                'sku' => null,
                'unit' => 'usł.',
                'quantity' => 1,
                'unit_net_price' => 12,
                'net_total' => 12,
                'vat_rate' => 19,
                'vat_total' => 2.28,
                'gross_total' => 14.28,
                'metadata' => [
                    'source' => 'woocommerce',
                    'line_type' => 'shipping',
                    'external_line_id' => 'shipping-1',
                ],
            ]);
        }

        $document = WarehouseDocument::query()->create([
            'number' => 'RX/SHIPPING/1',
            'type' => 'RX',
            'status' => 'posted',
            'destination_warehouse_id' => $warehouse->id,
            'document_date' => now(),
            'posted_at' => now(),
        ]);
        $returnCase = ReturnCase::query()->create([
            'number' => 'RET/SHIPPING/1',
            'external_order_id' => $order->id,
            'target_warehouse_id' => $warehouse->id,
            'warehouse_document_id' => $document->id,
            'status' => 'completed',
            'reason' => 'Odstąpienie od umowy',
            'customer_email' => 'anna@example.test',
            'metadata' => [
                'refund_method' => $paymentMethod === 'cod' ? 'bank_transfer' : 'cashback',
                'refund_bank_account' => $paymentMethod === 'cod' ? '11102033520000205312345060' : null,
            ],
        ]);
        $returnCase->lines()->create([
            'product_id' => $product->id,
            'external_order_line_id' => $orderLine->id,
            'warehouse_document_id' => $document->id,
            'quantity_expected' => $returnedQuantity,
            'quantity_accepted' => $returnedQuantity,
            'condition' => 'opened',
            'disposition' => 'restock',
            'target_warehouse_id' => $warehouse->id,
        ]);

        return $returnCase->fresh();
    }
}
