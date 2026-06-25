<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\KsefSubmission;
use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Services\Automation\InvoiceKsefAutomationService;
use App\Services\Audit\AuditLogService;
use App\Services\Ksef\KsefEligibilityService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReturnCorrectionInvoiceService
{
    public function __construct(
        private readonly InvoiceNumberService $numbers,
        private readonly InvoiceTemplateService $templates,
        private readonly OrderInvoiceService $files,
        private readonly InvoiceSettingsService $settings,
        private readonly AuditLogService $audit,
        private readonly InvoiceKsefAutomationService $ksefAutomation,
    ) {
    }

    public function createForReturn(ReturnCase $returnCase): Invoice
    {
        $createdInvoiceId = null;

        $invoice = DB::transaction(function () use ($returnCase, &$createdInvoiceId): Invoice {
            $returnCase = ReturnCase::query()
                ->with([
                    'lines.externalOrderLine',
                    'lines.warehouseDocument',
                    'externalOrder.invoices.ksefSubmissions',
                    'externalOrder.invoices.lines',
                    'warehouseDocument',
                    'correctionInvoice.lines',
                ])
                ->lockForUpdate()
                ->findOrFail($returnCase->id);

            if ($returnCase->correctionInvoice instanceof Invoice) {
                return $this->files->ensureFiles($returnCase->correctionInvoice->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']));
            }

            $returnDocuments = $this->returnDocuments($returnCase);

            if ($returnDocuments->isEmpty() || ! $returnDocuments->every(fn ($document): bool => $document->status === 'posted')) {
                throw new RuntimeException('Najpierw zaksięguj wszystkie RX dla tego zwrotu.');
            }

            if ($returnCase->externalOrder === null) {
                throw new RuntimeException('Faktura korygująca wymaga zwrotu powiązanego z zamówieniem.');
            }

            $originalInvoice = $returnCase->externalOrder->invoices
                ->where('type', 'vat')
                ->where('status', 'issued')
                ->sortByDesc('id')
                ->first();

            if (! $originalInvoice instanceof Invoice) {
                throw new RuntimeException('Nie znaleziono pierwotnej faktury sprzedaży do tego zamówienia.');
            }

            $sellerStatus = $this->settings->sellerConfigurationStatus($originalInvoice->seller_data ?? []);

            if (! $sellerStatus['is_ready']) {
                throw new RuntimeException(
                    'Faktura pierwotna ma niekompletne dane sprzedawcy. Uzupełnij dane sprzedawcy na fakturze pierwotnej przed wystawieniem korekty. '
                    . implode(' ', $sellerStatus['errors']),
                );
            }

            $correctionLines = $this->correctionLines($returnCase, $originalInvoice);

            if ($correctionLines === []) {
                throw new RuntimeException('Zwrot nie ma pozycji, które można skorygować na fakturze.');
            }

            $template = $this->templates->defaultTemplate();
            $netTotal = round(collect($correctionLines)->sum('net_total'), 2);
            $vatTotal = round(collect($correctionLines)->sum('vat_total'), 2);
            $grossTotal = round(collect($correctionLines)->sum('gross_total'), 2);
            $metadata = $this->metadataForCorrection($returnCase, $originalInvoice, $returnDocuments);

            $invoice = Invoice::query()->create([
                'number' => $this->numbers->next('FK'),
                'type' => 'correction',
                'status' => 'issued',
                'external_order_id' => $returnCase->external_order_id,
                'invoice_template_id' => $template->id,
                'issue_date' => now()->toDateString(),
                'sale_date' => $originalInvoice->sale_date?->toDateString(),
                'payment_due_date' => $this->settings->paymentDueDate(),
                'currency' => $originalInvoice->currency,
                'seller_data' => $originalInvoice->seller_data,
                'buyer_data' => $originalInvoice->buyer_data,
                'net_total' => $netTotal,
                'vat_total' => $vatTotal,
                'gross_total' => $grossTotal,
                'payment_method' => $originalInvoice->payment_method,
                'issued_at' => now(),
                'metadata' => $metadata,
            ]);

            foreach ($correctionLines as $line) {
                $invoice->lines()->create($line);
            }

            $returnCase->update([
                'correction_invoice_id' => $invoice->id,
                'status' => 'corrected',
                'metadata' => array_merge($returnCase->metadata ?? [], [
                    'correction_invoice_id' => $invoice->id,
                    'correction_invoice_number' => $invoice->number,
                    'corrected_at' => now()->toISOString(),
                ]),
            ]);

            $this->audit->record('return.correction_invoice_issued', $invoice, null, [
                'invoice_number' => $invoice->number,
                'return_case_number' => $returnCase->number,
                'gross_total' => (string) $invoice->gross_total,
            ], [
                'return_case_id' => $returnCase->id,
                'corrected_invoice_id' => $originalInvoice->id,
            ]);

            $createdInvoiceId = (int) $invoice->id;

            return $this->files->ensureFiles($invoice->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']));
        });

        if ($createdInvoiceId !== null) {
            $this->ksefAutomation->queueAfterInvoiceIssued($invoice);
        }

        return $invoice->refresh()->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']);
    }

    private function returnDocuments(ReturnCase $returnCase): \Illuminate\Support\Collection
    {
        return $returnCase->lines
            ->map(fn (ReturnCaseLine $line) => $line->warehouseDocument)
            ->filter()
            ->push($returnCase->warehouseDocument)
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataForCorrection(ReturnCase $returnCase, Invoice $originalInvoice, \Illuminate\Support\Collection $returnDocuments): array
    {
        $metadata = [
            'source' => 'return_case',
            'return_case_id' => $returnCase->id,
            'return_case_number' => $returnCase->number,
            'correction_reason' => $returnCase->reason ?: 'Zwrot towaru',
            'corrected_invoice_id' => $originalInvoice->id,
            'corrected_invoice_number' => $originalInvoice->number,
            'corrected_invoice_issue_date' => $originalInvoice->issue_date?->toDateString(),
            'warehouse_document_id' => $returnCase->warehouse_document_id,
            'warehouse_document_number' => $returnCase->warehouseDocument?->number,
            'warehouse_document_ids' => $returnDocuments->pluck('id')->values()->all(),
            'warehouse_document_numbers' => $returnDocuments->pluck('number')->values()->all(),
            'legal_review_required' => true,
        ];

        return $this->withKsefCorrectionContext($metadata, $originalInvoice);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function withKsefCorrectionContext(array $metadata, Invoice $originalInvoice): array
    {
        $context = $this->originalKsefContext($originalInvoice);

        if (! $context['should_send_correction']) {
            return $metadata;
        }

        if (filled($context['number'])) {
            $metadata['corrected_invoice_ksef_number'] = $context['number'];
        }

        if (filled($context['reference_number'])) {
            $metadata['corrected_invoice_ksef_reference_number'] = $context['reference_number'];
        }

        if (filled($context['accepted_at'])) {
            $metadata['corrected_invoice_ksef_accepted_at'] = $context['accepted_at'];
        }

        if ($context['submission_id'] !== null) {
            $metadata['corrected_invoice_ksef_submission_id'] = $context['submission_id'];
        }

        $metadata['ksef'] = $this->withoutBlankValues([
            'send_policy' => KsefEligibilityService::POLICY_SEND,
            'policy_reason' => 'correction_of_ksef_invoice',
            'correction_policy_set_at' => now()->toISOString(),
            'correction_of_invoice_id' => $originalInvoice->id,
            'correction_of_invoice_number' => $originalInvoice->number,
            'correction_of_ksef_number' => $context['number'],
            'correction_of_reference_number' => $context['reference_number'],
            'correction_of_submission_id' => $context['submission_id'],
        ]);

        return $metadata;
    }

    /**
     * @return array{should_send_correction: bool, number: string|null, reference_number: string|null, accepted_at: string|null, submission_id: int|null}
     */
    private function originalKsefContext(Invoice $originalInvoice): array
    {
        $originalInvoice->loadMissing('ksefSubmissions');

        $submission = $originalInvoice->ksefSubmissions
            ->sortByDesc('id')
            ->first(fn (KsefSubmission $submission): bool => in_array($submission->status, [
                'accepted',
                'submitted',
                'running',
                'queued',
                'missing_configuration',
            ], true));

        $number = $this->firstFilled(
            $originalInvoice->ksef_number,
            data_get($originalInvoice->metadata, 'ksef.number'),
            $submission?->ksef_number,
        );
        $referenceNumber = $this->firstFilled(
            data_get($originalInvoice->metadata, 'ksef.reference_number'),
            $submission?->reference_number,
        );
        $acceptedAt = $this->firstFilled(
            data_get($originalInvoice->metadata, 'ksef.accepted_at'),
            $submission?->accepted_at?->toISOString(),
        );
        $policy = KsefEligibilityService::POLICY_AUTO;
        $metadataPolicy = data_get($originalInvoice->metadata, 'ksef.send_policy');

        if (is_string($metadataPolicy) && trim($metadataPolicy) !== '') {
            $policy = strtolower(trim($metadataPolicy));
        }

        return [
            'should_send_correction' => filled($number)
                || $submission instanceof KsefSubmission
                || $policy === KsefEligibilityService::POLICY_SEND,
            'number' => $number,
            'reference_number' => $referenceNumber,
            'accepted_at' => $acceptedAt,
            'submission_id' => $submission instanceof KsefSubmission ? (int) $submission->id : null,
        ];
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withoutBlankValues(array $values): array
    {
        return collect($values)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function correctionLines(ReturnCase $returnCase, Invoice $originalInvoice): array
    {
        return $returnCase->lines
            ->filter(fn (ReturnCaseLine $line): bool => (float) $line->quantity_accepted > 0)
            ->map(function (ReturnCaseLine $returnLine) use ($originalInvoice): ?array {
                $invoiceLine = $this->matchingInvoiceLine($originalInvoice, $returnLine);

                if (! $invoiceLine instanceof InvoiceLine) {
                    return null;
                }

                $quantity = -1 * min((float) $returnLine->quantity_accepted, abs((float) $invoiceLine->quantity));
                $unitNet = (float) $invoiceLine->unit_net_price;
                $vatRate = (float) $invoiceLine->vat_rate;
                $netTotal = round($quantity * $unitNet, 2);
                $vatTotal = round($netTotal * ($vatRate / 100), 2);
                $grossTotal = round($netTotal + $vatTotal, 2);
                $beforeQuantity = (float) $invoiceLine->quantity;
                $beforeNetTotal = (float) $invoiceLine->net_total;
                $beforeVatTotal = (float) $invoiceLine->vat_total;
                $beforeGrossTotal = (float) $invoiceLine->gross_total;

                return [
                    'product_id' => $invoiceLine->product_id,
                    'name' => 'Korekta zwrotu: ' . $invoiceLine->name,
                    'sku' => $invoiceLine->sku,
                    'unit' => $invoiceLine->unit,
                    'quantity' => $quantity,
                    'unit_net_price' => $unitNet,
                    'net_total' => $netTotal,
                    'vat_rate' => $vatRate,
                    'vat_total' => $vatTotal,
                    'gross_total' => $grossTotal,
                    'metadata' => [
                        'source' => 'return_case_line',
                        'return_case_line_id' => $returnLine->id,
                        'external_order_line_id' => $returnLine->externalOrderLine?->external_line_id,
                        'corrected_invoice_line_id' => $invoiceLine->id,
                        'before_correction' => [
                            'name' => $invoiceLine->name,
                            'sku' => $invoiceLine->sku,
                            'unit' => $invoiceLine->unit,
                            'quantity' => $beforeQuantity,
                            'unit_net_price' => $unitNet,
                            'net_total' => $beforeNetTotal,
                            'vat_rate' => $vatRate,
                            'vat_total' => $beforeVatTotal,
                            'gross_total' => $beforeGrossTotal,
                        ],
                        'after_correction' => [
                            'name' => $invoiceLine->name,
                            'sku' => $invoiceLine->sku,
                            'unit' => $invoiceLine->unit,
                            'quantity' => round($beforeQuantity + $quantity, 4),
                            'unit_net_price' => $unitNet,
                            'net_total' => round($beforeNetTotal + $netTotal, 2),
                            'vat_rate' => $vatRate,
                            'vat_total' => round($beforeVatTotal + $vatTotal, 2),
                            'gross_total' => round($beforeGrossTotal + $grossTotal, 2),
                        ],
                    ],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function matchingInvoiceLine(Invoice $invoice, ReturnCaseLine $returnLine): ?InvoiceLine
    {
        $externalLineId = $returnLine->externalOrderLine?->external_line_id;

        if (filled($externalLineId)) {
            $byExternalLine = $invoice->lines->first(
                fn (InvoiceLine $line): bool => (string) data_get($line->metadata, 'external_line_id') === (string) $externalLineId,
            );

            if ($byExternalLine instanceof InvoiceLine) {
                return $byExternalLine;
            }
        }

        return $invoice->lines->first(
            fn (InvoiceLine $line): bool => $returnLine->product_id !== null && $line->product_id === $returnLine->product_id,
        );
    }
}
