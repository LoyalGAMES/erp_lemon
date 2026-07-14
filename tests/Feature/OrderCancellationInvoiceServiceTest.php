<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceTemplate;
use App\Models\KsefSubmission;
use App\Models\OrderCancellation;
use App\Models\SalesChannel;
use App\Services\Orders\OrderCancellationInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class OrderCancellationInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        InvoiceTemplate::query()->create([
            'code' => 'cancellation-test',
            'name' => 'Test anulowania zamówienia',
            'renderer' => 'blade_pdf',
            'template_body' => '<!DOCTYPE html><html lang="pl"><body>{{ $invoice->number }}</body></html>',
            'settings' => ['source' => 'operator'],
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_it_cancels_proforma_and_draft_idempotently(): void
    {
        [$order, $cancellation] = $this->orderAndCancellation('cancel-non-fiscal');
        $proforma = $this->invoice($order, [
            'number' => 'PRO/2026/000001',
            'type' => 'proforma',
            'status' => 'issued',
        ]);
        $draft = $this->invoice($order, [
            'number' => 'FV/2026/000001',
            'type' => 'vat',
            'status' => 'draft',
        ]);

        $first = app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);
        $proformaCancelledAt = $proforma->fresh()->cancelled_at;
        $draftCancelledAt = $draft->fresh()->cancelled_at;
        $second = app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);

        $this->assertEqualsCanonicalizing([$proforma->id, $draft->id], $first['cancelled']);
        $this->assertEqualsCanonicalizing([$proforma->id, $draft->id], $second['cancelled']);
        $this->assertSame([], $first['corrections']);
        $this->assertSame([], $second['corrections']);
        $this->assertSame('cancelled', $proforma->fresh()->status);
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->assertTrue($proforma->fresh()->cancelled_at?->equalTo($proformaCancelledAt) ?? false);
        $this->assertTrue($draft->fresh()->cancelled_at?->equalTo($draftCancelledAt) ?? false);
        $this->assertSame($cancellation->uuid, data_get($proforma->fresh()->metadata, 'order_cancellation.operation_uuid'));
        $this->assertSame($cancellation->uuid, data_get($draft->fresh()->metadata, 'order_cancellation.operation_uuid'));
        $this->assertSame(
            2,
            AuditLog::query()->where('action', 'invoice.cancelled_with_order')->count(),
        );
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_it_creates_one_full_correction_and_keeps_original_vat_invoice_issued(): void
    {
        [$order, $cancellation] = $this->orderAndCancellation('cancel-issued-vat');
        $original = $this->invoice($order, [
            'number' => 'FV/2026/000010',
            'type' => 'vat',
            'status' => 'issued',
            'net_total' => 100.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
        ]);
        $originalLine = $original->lines()->create([
            'name' => 'Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => 2,
            'unit_net_price' => 50.00,
            'net_total' => 100.00,
            'vat_rate' => 23.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
            'metadata' => ['external_line_id' => '501'],
        ]);

        $first = app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);
        $second = app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);

        $correction = Invoice::query()->where('type', 'correction')->firstOrFail();
        $correctionLine = $correction->lines()->sole();

        $this->assertSame([$correction->id], $first['corrections']);
        $this->assertSame([$correction->id], $second['corrections']);
        $this->assertSame([], $first['cancelled']);
        $this->assertSame([], $second['cancelled']);
        $this->assertSame(1, Invoice::query()->where('type', 'correction')->count());
        $this->assertSame(1, $correction->lines()->count());
        $this->assertSame('-100.00', (string) $correction->net_total);
        $this->assertSame('-23.00', (string) $correction->vat_total);
        $this->assertSame('-123.00', (string) $correction->gross_total);
        $this->assertSame('-2.0000', (string) $correctionLine->quantity);
        $this->assertSame('50.0000', (string) $correctionLine->unit_net_price);
        $this->assertSame('-100.00', (string) $correctionLine->net_total);
        $this->assertSame('-23.00', (string) $correctionLine->vat_total);
        $this->assertSame('-123.00', (string) $correctionLine->gross_total);
        $this->assertSame($originalLine->id, data_get($correctionLine->metadata, 'corrected_invoice_line_id'));
        $this->assertEquals(2.0, data_get($correctionLine->metadata, 'before_correction.quantity'));
        $this->assertEquals(100.0, data_get($correctionLine->metadata, 'before_correction.net_total'));
        $this->assertEquals(23.0, data_get($correctionLine->metadata, 'before_correction.vat_total'));
        $this->assertEquals(123.0, data_get($correctionLine->metadata, 'before_correction.gross_total'));
        $this->assertSame(0, data_get($correctionLine->metadata, 'after_correction.quantity'));
        $this->assertSame(0, data_get($correctionLine->metadata, 'after_correction.net_total'));
        $this->assertSame(0, data_get($correctionLine->metadata, 'after_correction.vat_total'));
        $this->assertSame(0, data_get($correctionLine->metadata, 'after_correction.gross_total'));
        $this->assertSame($cancellation->uuid, data_get($correction->metadata, 'order_cancellation_uuid'));
        $this->assertSame($original->id, data_get($correction->metadata, 'corrected_invoice_id'));
        $this->assertSame('issued', $original->fresh()->status);
        $this->assertNull($original->fresh()->cancelled_at);
        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'order.cancellation_correction_invoice_issued')->count(),
        );
    }

    public function test_it_corrects_only_the_balance_left_after_an_earlier_partial_correction(): void
    {
        [$order, $cancellation] = $this->orderAndCancellation('cancel-after-partial-correction');
        $original = $this->invoice($order, [
            'number' => 'FV/2026/000011',
            'net_total' => 100.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
        ]);
        $originalLine = $original->lines()->create([
            'name' => 'Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => 2,
            'unit_net_price' => 50.00,
            'net_total' => 100.00,
            'vat_rate' => 23.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
        ]);
        $earlier = $this->existingCorrection(
            $order,
            $original,
            $originalLine,
            'FK/2026/000011',
            quantity: -0.4,
            netTotal: -20.00,
            vatTotal: -4.60,
            grossTotal: -24.60,
        );

        $first = app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);
        $second = app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);

        $cancellationCorrection = Invoice::query()
            ->where('type', 'correction')
            ->get()
            ->firstOrFail(fn (Invoice $invoice): bool => data_get($invoice->metadata, 'order_cancellation_uuid') === $cancellation->uuid);
        $line = $cancellationCorrection->lines()->sole();

        $this->assertSame([$cancellationCorrection->id], $first['corrections']);
        $this->assertSame([$cancellationCorrection->id], $second['corrections']);
        $this->assertSame(2, Invoice::query()->where('type', 'correction')->count());
        $this->assertSame('-80.00', (string) $cancellationCorrection->net_total);
        $this->assertSame('-18.40', (string) $cancellationCorrection->vat_total);
        $this->assertSame('-98.40', (string) $cancellationCorrection->gross_total);
        $this->assertSame('-1.6000', (string) $line->quantity);
        $this->assertSame('-80.00', (string) $line->net_total);
        $this->assertSame('-18.40', (string) $line->vat_total);
        $this->assertSame('-98.40', (string) $line->gross_total);
        $this->assertEquals(1.6, data_get($line->metadata, 'before_correction.quantity'));
        $this->assertEquals(80.0, data_get($line->metadata, 'before_correction.net_total'));
        $this->assertEquals(18.4, data_get($line->metadata, 'before_correction.vat_total'));
        $this->assertEquals(98.4, data_get($line->metadata, 'before_correction.gross_total'));
        $this->assertSame([$earlier->id], data_get($cancellationCorrection->metadata, 'reconciled_correction_invoice_ids'));
    }

    public function test_it_does_not_create_another_correction_when_an_earlier_one_fully_zeroed_the_invoice(): void
    {
        [$order, $cancellation] = $this->orderAndCancellation('cancel-after-full-correction');
        $original = $this->invoice($order, [
            'number' => 'FV/2026/000012',
        ]);
        $originalLine = $original->lines()->create([
            'name' => 'Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 100.00,
            'net_total' => 100.00,
            'vat_rate' => 23.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
        ]);
        $earlier = $this->existingCorrection(
            $order,
            $original,
            $originalLine,
            'FK/2026/000012',
            quantity: -1,
            netTotal: -100.00,
            vatTotal: -23.00,
            grossTotal: -123.00,
        );

        $first = app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);
        $second = app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);

        $this->assertSame([], $first['corrections']);
        $this->assertSame([], $second['corrections']);
        $this->assertSame(1, Invoice::query()->where('type', 'correction')->count());
        $this->assertSame($earlier->id, Invoice::query()->where('type', 'correction')->sole()->id);
        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'order.cancellation_correction_invoice_issued')->count(),
        );
    }

    public function test_it_stops_on_a_correction_line_that_is_not_linked_to_the_original_line(): void
    {
        [$order, $cancellation] = $this->orderAndCancellation('cancel-unlinked-correction-line');
        $original = $this->invoice($order, [
            'number' => 'FV/2026/000013',
        ]);
        $originalLine = $original->lines()->create([
            'name' => 'Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 100.00,
            'net_total' => 100.00,
            'vat_rate' => 23.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
        ]);
        $this->existingCorrection(
            $order,
            $original,
            $originalLine,
            'FK/2026/000013',
            quantity: -0.2,
            netTotal: -20.00,
            vatTotal: -4.60,
            grossTotal: -24.60,
            linkLine: false,
        );

        try {
            app(OrderCancellationInvoiceService::class)
                ->reverseForCancellation($order, $cancellation);
            $this->fail('Niespójna korekta powinna zatrzymać anulowanie.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Błąd księgowy faktury', $exception->getMessage());
            $this->assertStringContainsString('metadata.corrected_invoice_line_id', $exception->getMessage());
            $this->assertStringContainsString('nadmiarowej korekty', $exception->getMessage());
        }

        $this->assertSame(1, Invoice::query()->where('type', 'correction')->count());
        $this->assertSame(
            0,
            AuditLog::query()->where('action', 'order.cancellation_correction_invoice_issued')->count(),
        );
    }

    public function test_it_stops_when_an_existing_correction_is_not_linked_to_an_invoice(): void
    {
        [$order, $cancellation] = $this->orderAndCancellation('cancel-unlinked-correction');
        $original = $this->invoice($order, [
            'number' => 'FV/2026/000014',
        ]);
        $originalLine = $original->lines()->create([
            'name' => 'Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 100.00,
            'net_total' => 100.00,
            'vat_rate' => 23.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
        ]);
        $this->existingCorrection(
            $order,
            $original,
            $originalLine,
            'FK/2026/000014',
            quantity: -0.2,
            netTotal: -20.00,
            vatTotal: -4.60,
            grossTotal: -24.60,
            linkInvoice: false,
        );

        try {
            app(OrderCancellationInvoiceService::class)
                ->reverseForCancellation($order, $cancellation);
            $this->fail('Niepowiązana korekta powinna zatrzymać anulowanie.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Błąd księgowy faktury', $exception->getMessage());
            $this->assertStringContainsString('metadata.corrected_invoice_id', $exception->getMessage());
        }

        $this->assertSame(1, Invoice::query()->where('type', 'correction')->count());
    }

    public function test_ksef_context_forces_correction_submission_and_preserves_original_references(): void
    {
        [$order, $cancellation] = $this->orderAndCancellation('cancel-ksef-vat');
        $original = $this->invoice($order, [
            'number' => 'FV/2026/000020',
            'type' => 'vat',
            'status' => 'issued',
            'metadata' => [
                'ksef' => ['send_policy' => 'auto'],
            ],
        ]);
        $original->lines()->create([
            'name' => 'Sukienka',
            'sku' => 'SUK-001',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 100.00,
            'net_total' => 100.00,
            'vat_rate' => 23.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
        ]);
        $acceptedAt = now()->subMinute()->startOfSecond();
        $submission = KsefSubmission::query()->create([
            'invoice_id' => $original->id,
            'environment' => 'production',
            'api_version' => '2.0',
            'status' => 'accepted',
            'reference_number' => 'KSEF-REF-2026-20',
            'ksef_number' => '1234567890-20260714-ABCDEF-20',
            'submitted_at' => $acceptedAt->copy()->subMinute(),
            'accepted_at' => $acceptedAt,
        ]);

        app(OrderCancellationInvoiceService::class)
            ->reverseForCancellation($order, $cancellation);

        $correction = Invoice::query()->where('type', 'correction')->firstOrFail();

        $this->assertSame('send', data_get($correction->metadata, 'ksef.send_policy'));
        $this->assertSame('correction_of_ksef_invoice', data_get($correction->metadata, 'ksef.policy_reason'));
        $this->assertSame($original->id, data_get($correction->metadata, 'ksef.correction_of_invoice_id'));
        $this->assertSame($original->number, data_get($correction->metadata, 'ksef.correction_of_invoice_number'));
        $this->assertSame($submission->id, data_get($correction->metadata, 'ksef.correction_of_submission_id'));
        $this->assertSame($submission->ksef_number, data_get($correction->metadata, 'ksef.correction_of_ksef_number'));
        $this->assertSame($submission->reference_number, data_get($correction->metadata, 'ksef.correction_of_reference_number'));
        $this->assertSame($submission->ksef_number, data_get($correction->metadata, 'corrected_invoice_ksef_number'));
        $this->assertSame($submission->reference_number, data_get($correction->metadata, 'corrected_invoice_ksef_reference_number'));
        $this->assertSame($acceptedAt->toISOString(), data_get($correction->metadata, 'corrected_invoice_ksef_accepted_at'));
        $this->assertSame($submission->id, data_get($correction->metadata, 'corrected_invoice_ksef_submission_id'));
        $this->assertSame('issued', $original->fresh()->status);
        $this->assertNull($original->fresh()->cancelled_at);
    }

    /**
     * @return array{ExternalOrder,OrderCancellation}
     */
    private function orderAndCancellation(string $suffix): array
    {
        $channel = SalesChannel::query()->create([
            'code' => 'CANCEL-'.mb_strtoupper($suffix),
            'name' => 'Kanał anulowania '.$suffix,
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $order = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'WC-'.$suffix,
            'external_number' => 'ZAM-'.$suffix,
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 123.00,
            'billing_data' => ['first_name' => 'Anna', 'last_name' => 'Nowak'],
            'shipping_data' => ['first_name' => 'Anna', 'last_name' => 'Nowak'],
            'raw_payload' => [],
        ]);
        $cancellation = OrderCancellation::query()->create([
            'uuid' => '00000000-0000-4000-8000-'.str_pad((string) crc32($suffix), 12, '0', STR_PAD_LEFT),
            'external_order_id' => $order->id,
            'status' => 'processing',
            'reason' => 'Rezygnacja klientki',
            'refund_status' => 'pending',
            'refund_amount' => 123.00,
            'currency' => 'PLN',
        ]);

        return [$order, $cancellation];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(ExternalOrder $order, array $overrides = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'number' => 'FV/2026/000001',
            'type' => 'vat',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'invoice_template_id' => InvoiceTemplate::query()->where('is_default', true)->value('id'),
            'issue_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
            'payment_due_date' => now()->toDateString(),
            'currency' => 'PLN',
            'seller_data' => [
                'name' => 'Sempre sp. z o.o.',
                'tax_id' => '5250000000',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'buyer_data' => [
                'name' => 'Anna Nowak',
                'address_1' => 'Kliencka 2',
                'postcode' => '00-002',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'net_total' => 100.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
            'payment_method' => 'PayU',
            'issued_at' => now(),
            'metadata' => [],
        ], $overrides));
    }

    private function existingCorrection(
        ExternalOrder $order,
        Invoice $original,
        InvoiceLine $originalLine,
        string $number,
        float $quantity,
        float $netTotal,
        float $vatTotal,
        float $grossTotal,
        bool $linkInvoice = true,
        bool $linkLine = true,
    ): Invoice {
        $correction = $this->invoice($order, [
            'number' => $number,
            'type' => 'correction',
            'status' => 'issued',
            'net_total' => $netTotal,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
            'metadata' => $linkInvoice ? [
                'source' => 'return_case',
                'corrected_invoice_id' => $original->id,
                'corrected_invoice_number' => $original->number,
            ] : ['source' => 'legacy_correction'],
        ]);
        $correction->lines()->create([
            'product_id' => $originalLine->product_id,
            'name' => 'Wcześniejsza korekta: '.$originalLine->name,
            'sku' => $originalLine->sku,
            'unit' => $originalLine->unit,
            'quantity' => $quantity,
            'unit_net_price' => $originalLine->unit_net_price,
            'net_total' => $netTotal,
            'vat_rate' => $originalLine->vat_rate,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
            'metadata' => $linkLine ? [
                'corrected_invoice_line_id' => $originalLine->id,
            ] : ['source' => 'legacy_correction_line'],
        ]);

        return $correction;
    }
}
