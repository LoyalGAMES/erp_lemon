<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SubmitInvoiceToKsefJob;
use App\Models\AuditLog;
use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\InvoiceTemplate;
use App\Models\KsefSubmission;
use App\Models\OrderCancellation;
use App\Models\SalesChannel;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Orders\OrderCancellationInvoiceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\TestCase;

final class OrderSplitReversalInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        InvoiceTemplate::query()->create([
            'code' => 'split-reversal-test',
            'name' => 'Test cofnięcia podziału',
            'renderer' => 'blade_pdf',
            'template_body' => '<!DOCTYPE html><html lang="pl"><body>{{ $invoice->number }}</body></html>',
            'settings' => ['source' => 'operator'],
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_it_reverses_only_documents_created_since_the_split_and_is_idempotent(): void
    {
        $order = $this->order();
        $cutoff = CarbonImmutable::parse('2026-07-22 10:00:00', 'Europe/Warsaw');
        $operationUuid = '10000000-0000-4000-8000-000000000001';
        $reason = 'Cofnięcie podziału zamówienia';

        $oldDraft = $this->invoice($order, [
            'number' => 'FV/2026/BEFORE-SPLIT',
            'status' => 'draft',
            'metadata' => ['source' => 'before_split'],
        ], $cutoff->subSecond());
        $proforma = $this->invoice($order, [
            'number' => 'PRO/2026/AFTER-SPLIT',
            'type' => 'proforma',
            'status' => 'issued',
            'metadata' => ['source' => 'packing'],
        ], $cutoff);
        $draft = $this->invoice($order, [
            'number' => 'FV/2026/AFTER-SPLIT-DRAFT',
            'status' => 'draft',
        ], $cutoff->addSecond());
        $issued = $this->invoice($order, [
            'number' => 'FV/2026/AFTER-SPLIT-ISSUED',
            'status' => 'issued',
        ], $cutoff->addSeconds(2));
        $issuedLine = $issued->lines()->create([
            'name' => 'Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 100.00,
            'net_total' => 100.00,
            'vat_rate' => 23.00,
            'vat_total' => 23.00,
            'gross_total' => 123.00,
            'metadata' => ['external_line_id' => '501'],
        ]);

        $service = app(OrderCancellationInvoiceService::class);
        $first = $service->reverseForSplitReversal($order, $operationUuid, $reason, $cutoff);
        $proformaCancelledAt = $proforma->fresh()->cancelled_at;
        $draftCancelledAt = $draft->fresh()->cancelled_at;
        $second = $service->reverseForSplitReversal($order, $operationUuid, $reason, $cutoff);

        $correction = Invoice::query()
            ->where('type', 'correction')
            ->where('metadata->split_reversal_uuid', $operationUuid)
            ->sole();
        $correctionLine = $correction->lines()->sole();

        $this->assertEqualsCanonicalizing([$proforma->id, $draft->id], $first['cancelled']);
        $this->assertEqualsCanonicalizing([$proforma->id, $draft->id], $second['cancelled']);
        $this->assertSame([$correction->id], $first['corrections']);
        $this->assertSame([$correction->id], $second['corrections']);
        $this->assertSame('draft', $oldDraft->fresh()->status);
        $this->assertNull($oldDraft->fresh()->cancelled_at);
        $this->assertSame('cancelled', $proforma->fresh()->status);
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->assertTrue($proforma->fresh()->cancelled_at?->equalTo($proformaCancelledAt) ?? false);
        $this->assertTrue($draft->fresh()->cancelled_at?->equalTo($draftCancelledAt) ?? false);
        $this->assertSame('split_reversal', data_get($proforma->fresh()->metadata, 'source'));
        $this->assertSame('packing', data_get($proforma->fresh()->metadata, 'split_reversal.previous_source'));
        $this->assertSame($operationUuid, data_get($proforma->fresh()->metadata, 'split_reversal.operation_uuid'));
        $this->assertSame('split_reversal', data_get($correction->metadata, 'source'));
        $this->assertSame($operationUuid, data_get($correction->metadata, 'split_reversal_uuid'));
        $this->assertSame($reason, data_get($correction->metadata, 'correction_reason'));
        $this->assertSame($issued->id, data_get($correction->metadata, 'corrected_invoice_id'));
        $this->assertSame('-123.00', (string) $correction->gross_total);
        $this->assertSame('split_reversal', data_get($correctionLine->metadata, 'source'));
        $this->assertSame($operationUuid, data_get($correctionLine->metadata, 'split_reversal_uuid'));
        $this->assertSame($issuedLine->id, data_get($correctionLine->metadata, 'corrected_invoice_line_id'));
        $this->assertSame(2, AuditLog::query()->where('action', 'invoice.cancelled_for_split_reversal')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'order.split_reversal_correction_invoice_issued')->count());
        $this->assertSame(0, OrderCancellation::query()->count());
        $this->assertSame(1, Invoice::query()->where('type', 'correction')->count());
    }

    public function test_retry_requeues_the_existing_split_reversal_correction_for_ksef(): void
    {
        app(DocumentAutomationSettingsService::class)->updateRules([
            'invoice.issued' => [
                'invoice.ksef.submit' => '1',
            ],
        ]);

        $order = $this->order();
        $cutoff = CarbonImmutable::parse('2026-07-22 10:00:00', 'Europe/Warsaw');
        $operationUuid = '10000000-0000-4000-8000-000000000055';
        $issued = $this->invoice($order, [
            'number' => 'FV/2026/KSEF-RETRY',
            'status' => 'issued',
            'seller_data' => [
                'name' => 'Sempre sp. z o.o.',
                'tax_id' => '5261040828',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'metadata' => [
                'ksef' => ['send_policy' => 'send'],
            ],
        ], $cutoff->addSecond());
        $issued->lines()->create([
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

        Queue::swap(new class($this->app) extends QueueFake
        {
            public function push($job, $data = '', $queue = null)
            {
                if ($job instanceof SubmitInvoiceToKsefJob) {
                    throw new \RuntimeException('Testowy błąd zapisu zadania do kolejki.');
                }

                return parent::push($job, $data, $queue);
            }
        });

        $service = app(OrderCancellationInvoiceService::class);

        try {
            $service->reverseForSplitReversal(
                $order,
                $operationUuid,
                'Cofnięcie splitu',
                $cutoff,
            );
            $this->fail('Pierwsza próba powinna zakończyć się błędem kolejki.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Testowy błąd zapisu zadania do kolejki.', $exception->getMessage());
        }

        $correctionId = Invoice::query()->where('type', 'correction')->sole()->id;
        $submissionId = KsefSubmission::query()->sole()->id;

        // Faktura i submission są już zapisane. Ponowienie musi użyć tych
        // samych rekordów, mimo że pierwszego zadania nie udało się zapisać.
        Queue::fake();
        $second = $service->reverseForSplitReversal(
            $order,
            $operationUuid,
            'Cofnięcie splitu',
            $cutoff,
        );

        Queue::assertPushed(SubmitInvoiceToKsefJob::class, 1);
        $this->assertSame([$correctionId], $second['corrections']);
        $this->assertSame(1, Invoice::query()->where('type', 'correction')->count());
        $this->assertSame(1, KsefSubmission::query()->count());
        $this->assertSame($submissionId, KsefSubmission::query()->sole()->id);
        $this->assertSame('queued', KsefSubmission::query()->sole()->status);
    }

    public function test_an_invoice_already_fully_corrected_is_marked_as_reversed_for_future_repacking(): void
    {
        $order = $this->order();
        $cutoff = CarbonImmutable::parse('2026-07-22 10:00:00', 'Europe/Warsaw');
        $operationUuid = '10000000-0000-4000-8000-000000000099';
        $issued = $this->invoice($order, [
            'number' => 'FV/2026/ALREADY-CORRECTED',
            'status' => 'issued',
        ], $cutoff->addSecond());
        $issuedLine = $issued->lines()->create([
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
        $firstPriorCorrection = $this->invoice($order, [
            'number' => 'FK/2026/PRIOR-1',
            'type' => 'correction',
            'status' => 'issued',
            'net_total' => -40,
            'vat_total' => -9.20,
            'gross_total' => -49.20,
            'metadata' => ['corrected_invoice_id' => $issued->id],
        ], $cutoff->addSeconds(2));
        $firstPriorCorrection->lines()->create([
            'name' => 'Korekta: Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => -0.4,
            'unit_net_price' => 100,
            'net_total' => -40,
            'vat_rate' => 23,
            'vat_total' => -9.20,
            'gross_total' => -49.20,
            'metadata' => ['corrected_invoice_line_id' => $issuedLine->id],
        ]);
        $secondPriorCorrection = $this->invoice($order, [
            'number' => 'FK/2026/PRIOR-2',
            'type' => 'correction',
            'status' => 'issued',
            'net_total' => -60,
            'vat_total' => -13.80,
            'gross_total' => -73.80,
            'metadata' => ['corrected_invoice_id' => $issued->id],
        ], $cutoff->addSeconds(3));
        $secondPriorCorrection->lines()->create([
            'name' => 'Korekta: Koszula Ava',
            'sku' => 'AVA-001',
            'unit' => 'szt',
            'quantity' => -0.6,
            'unit_net_price' => 100,
            'net_total' => -60,
            'vat_rate' => 23,
            'vat_total' => -13.80,
            'gross_total' => -73.80,
            'metadata' => ['corrected_invoice_line_id' => $issuedLine->id],
        ]);

        $service = app(OrderCancellationInvoiceService::class);
        $first = $service->reverseForSplitReversal($order, $operationUuid, 'Cofnięcie splitu', $cutoff);
        $markedAt = data_get($issued->fresh()->metadata, 'split_reversal.reversed_at');
        $second = $service->reverseForSplitReversal($order, $operationUuid, 'Cofnięcie splitu', $cutoff);

        $expectedCorrectionIds = [$firstPriorCorrection->id, $secondPriorCorrection->id];

        $this->assertSame($expectedCorrectionIds, $first['corrections']);
        $this->assertSame($expectedCorrectionIds, $second['corrections']);
        $this->assertSame(2, Invoice::query()->where('type', 'correction')->count());
        $this->assertTrue(data_get($issued->fresh()->metadata, 'split_reversal.fully_reversed'));
        $this->assertSame($operationUuid, data_get($issued->fresh()->metadata, 'split_reversal.operation_uuid'));
        $this->assertSame($expectedCorrectionIds, data_get(
            $issued->fresh()->metadata,
            'split_reversal.existing_correction_invoice_ids',
        ));
        $this->assertSame($markedAt, data_get($issued->fresh()->metadata, 'split_reversal.reversed_at'));
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'invoice.already_fully_corrected_for_split_reversal')
            ->count());
    }

    private function order(): ExternalOrder
    {
        $channel = SalesChannel::query()->create([
            'code' => 'SPLIT-REV-INVOICE',
            'name' => 'Kanał testu cofnięcia podziału',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        return ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => 'WC-SPLIT-REV-INVOICE',
            'external_number' => 'ZAM-SPLIT-REV-INVOICE',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 123.00,
            'billing_data' => ['first_name' => 'Anna', 'last_name' => 'Nowak'],
            'shipping_data' => ['first_name' => 'Anna', 'last_name' => 'Nowak'],
            'raw_payload' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(
        ExternalOrder $order,
        array $overrides,
        CarbonImmutable $createdAt,
    ): Invoice {
        $invoice = Invoice::query()->create(array_merge([
            'number' => 'FV/2026/SPLIT-REV',
            'type' => 'vat',
            'status' => 'issued',
            'external_order_id' => $order->id,
            'invoice_template_id' => InvoiceTemplate::query()->where('is_default', true)->value('id'),
            'issue_date' => $createdAt->toDateString(),
            'sale_date' => $createdAt->toDateString(),
            'payment_due_date' => $createdAt->toDateString(),
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
            'issued_at' => $createdAt,
            'metadata' => [],
        ], $overrides));

        $invoice->timestamps = false;
        $invoice->created_at = $createdAt;
        $invoice->updated_at = $createdAt;
        $invoice->save();
        $invoice->timestamps = true;

        return $invoice->refresh();
    }
}
