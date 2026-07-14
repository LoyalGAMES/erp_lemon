<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\KsefSubmission;
use App\Models\OrderCancellation;
use App\Services\Audit\AuditLogService;
use App\Services\Automation\InvoiceKsefAutomationService;
use App\Services\Invoices\InvoiceNumberService;
use App\Services\Invoices\InvoiceTemplateService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\Ksef\KsefEligibilityService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class OrderCancellationInvoiceService
{
    public function __construct(
        private readonly InvoiceNumberService $numbers,
        private readonly InvoiceTemplateService $templates,
        private readonly OrderInvoiceService $invoiceFiles,
        private readonly InvoiceKsefAutomationService $ksefAutomation,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * An issued fiscal invoice is never deleted or silently voided. It is
     * reversed with a full correction; drafts and proformas can be cancelled.
     *
     * @return array{cancelled:list<int>,corrections:list<int>}
     */
    public function reverseForCancellation(
        ExternalOrder $order,
        OrderCancellation $cancellation,
    ): array {
        $cancelled = [];
        $corrections = [];

        $invoices = Invoice::query()
            ->where('external_order_id', $order->id)
            ->oldest('id')
            ->get();

        foreach ($invoices as $invoice) {
            if ($invoice->type === 'correction') {
                continue;
            }

            if ($invoice->status === 'cancelled') {
                $cancelled[] = (int) $invoice->id;

                continue;
            }

            if ($invoice->type === 'proforma' || $invoice->status === 'draft') {
                $this->cancelNonFiscalDocument($invoice, $cancellation);
                $cancelled[] = (int) $invoice->id;

                continue;
            }

            $correction = $this->fullCorrection($invoice, $cancellation);

            if ($correction instanceof Invoice) {
                $corrections[] = (int) $correction->id;
            }
        }

        return [
            'cancelled' => array_values(array_unique($cancelled)),
            'corrections' => array_values(array_unique($corrections)),
        ];
    }

    private function cancelNonFiscalDocument(Invoice $invoice, OrderCancellation $cancellation): void
    {
        DB::transaction(function () use ($invoice, $cancellation): void {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($locked->status === 'cancelled') {
                return;
            }

            $before = [
                'status' => $locked->status,
                'cancelled_at' => $locked->cancelled_at?->toISOString(),
            ];
            $metadata = (array) $locked->metadata;
            $metadata['order_cancellation'] = [
                'operation_uuid' => $cancellation->uuid,
                'reason' => $cancellation->reason,
                'cancelled_at' => now()->toISOString(),
            ];

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'metadata' => $metadata,
            ]);

            $this->audit->record('invoice.cancelled_with_order', $locked, $before, [
                'status' => $locked->status,
                'cancelled_at' => $locked->cancelled_at?->toISOString(),
            ], [
                'order_cancellation_id' => $cancellation->id,
                'order_cancellation_uuid' => $cancellation->uuid,
            ]);
        }, 3);
    }

    private function fullCorrection(Invoice $invoice, OrderCancellation $cancellation): ?Invoice
    {
        $createdInvoiceId = null;

        $correction = DB::transaction(function () use ($invoice, $cancellation, &$createdInvoiceId): ?Invoice {
            $original = Invoice::query()
                ->with(['lines', 'ksefSubmissions'])
                ->lockForUpdate()
                ->findOrFail($invoice->id);

            $allCorrections = Invoice::query()
                ->with('lines')
                ->where('external_order_id', $original->external_order_id)
                ->where('type', 'correction')
                ->oldest('id')
                ->lockForUpdate()
                ->get();
            $existing = $allCorrections->first(
                fn (Invoice $candidate): bool => (string) data_get($candidate->metadata, 'order_cancellation_uuid') === (string) $cancellation->uuid
                    && (int) data_get($candidate->metadata, 'corrected_invoice_id') === (int) $original->id
            );

            if ($existing instanceof Invoice) {
                return $this->invoiceFiles->ensureFiles(
                    $existing->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']),
                );
            }

            $reconciliation = $this->remainingBalances(
                $original,
                $allCorrections->filter(fn (Invoice $candidate): bool => $candidate->status !== 'cancelled')->values(),
            );

            if ($reconciliation['lines'] === []) {
                return null;
            }

            $template = $this->templates->defaultTemplate();
            $metadata = $this->correctionMetadata($original, $cancellation);
            $metadata['reconciled_correction_invoice_ids'] = $reconciliation['correction_invoice_ids'];
            $netTotal = -$this->minorUnitsTotal($reconciliation['lines'], 'net_total') / 100;
            $vatTotal = -$this->minorUnitsTotal($reconciliation['lines'], 'vat_total') / 100;
            $grossTotal = -$this->minorUnitsTotal($reconciliation['lines'], 'gross_total') / 100;
            $correction = Invoice::query()->create([
                'number' => $this->numbers->next($this->correctionNumberType($original)),
                'type' => 'correction',
                'status' => 'issued',
                'external_order_id' => $original->external_order_id,
                'invoice_template_id' => $template->id,
                'issue_date' => now()->toDateString(),
                'sale_date' => $original->sale_date?->toDateString(),
                'payment_due_date' => now()->toDateString(),
                'currency' => $original->currency,
                'seller_data' => $original->seller_data,
                'buyer_data' => $original->buyer_data,
                'net_total' => $netTotal,
                'vat_total' => $vatTotal,
                'gross_total' => $grossTotal,
                'payment_method' => $original->payment_method,
                'issued_at' => now(),
                'metadata' => $metadata,
            ]);

            foreach ($reconciliation['lines'] as $lineBalance) {
                $correction->lines()->create($this->correctionLine($lineBalance, $cancellation));
            }

            $this->audit->record('order.cancellation_correction_invoice_issued', $correction, null, [
                'invoice_number' => $correction->number,
                'gross_total' => (string) $correction->gross_total,
            ], [
                'order_cancellation_id' => $cancellation->id,
                'order_cancellation_uuid' => $cancellation->uuid,
                'corrected_invoice_id' => $original->id,
                'corrected_invoice_number' => $original->number,
            ]);

            $createdInvoiceId = (int) $correction->id;

            return $this->invoiceFiles->ensureFiles(
                $correction->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']),
            );
        }, 3);

        if ($createdInvoiceId !== null) {
            /** @var Invoice $correction */
            $this->ksefAutomation->queueAfterInvoiceIssued($correction);
        }

        if (! $correction instanceof Invoice) {
            return null;
        }

        return $correction->refresh()->load(['lines', 'files', 'externalOrder', 'invoiceTemplate']);
    }

    /**
     * Reconcile the original with every active correction. All monetary values
     * are counted in minor units and quantities in 1/10000 units so that a
     * rounding artefact can never create an extra correction.
     *
     * @param  EloquentCollection<int, Invoice>  $corrections
     * @return array{
     *     lines:list<array{line:InvoiceLine,quantity:int,net_total:int,vat_total:int,gross_total:int}>,
     *     correction_invoice_ids:list<int>
     * }
     */
    private function remainingBalances(Invoice $original, EloquentCollection $corrections): array
    {
        if ($original->lines->isEmpty()) {
            $this->accountingError($original, 'faktura pierwotna nie ma pozycji.');
        }

        $this->assertTotalsMatchLines($original);

        $originalIds = Invoice::query()
            ->where('external_order_id', $original->external_order_id)
            ->where('type', '!=', 'correction')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $lineBalances = $original->lines
            ->mapWithKeys(fn (InvoiceLine $line): array => [
                (int) $line->id => [
                    'line' => $line,
                    'quantity' => $this->quantityUnits($line->quantity),
                    'net_total' => $this->minorUnits($line->net_total),
                    'vat_total' => $this->minorUnits($line->vat_total),
                    'gross_total' => $this->minorUnits($line->gross_total),
                ],
            ])
            ->all();
        $appliedCorrectionIds = [];

        foreach ($corrections as $correction) {
            $linkedInvoiceId = data_get($correction->metadata, 'corrected_invoice_id');

            if (! is_numeric($linkedInvoiceId) || (int) $linkedInvoiceId <= 0) {
                $this->accountingError(
                    $original,
                    "korekta {$correction->number} nie ma poprawnego metadata.corrected_invoice_id.",
                );
            }

            $linkedInvoiceId = (int) $linkedInvoiceId;

            if (! in_array($linkedInvoiceId, $originalIds, true)) {
                $this->accountingError(
                    $original,
                    "korekta {$correction->number} wskazuje nieistniejącą lub obcą fakturę #{$linkedInvoiceId}.",
                );
            }

            if ($linkedInvoiceId !== (int) $original->id) {
                continue;
            }

            if ($correction->currency !== $original->currency) {
                $this->accountingError(
                    $original,
                    "korekta {$correction->number} ma inną walutę ({$correction->currency}).",
                );
            }

            $this->assertTotalsMatchLines($correction);

            foreach ($correction->lines as $correctionLine) {
                $linkedLineId = data_get($correctionLine->metadata, 'corrected_invoice_line_id');

                if (! is_numeric($linkedLineId) || ! isset($lineBalances[(int) $linkedLineId])) {
                    $this->accountingError(
                        $original,
                        "pozycja #{$correctionLine->id} korekty {$correction->number} nie ma poprawnego metadata.corrected_invoice_line_id.",
                    );
                }

                $linkedLineId = (int) $linkedLineId;
                $lineBalances[$linkedLineId]['quantity'] += $this->quantityUnits($correctionLine->quantity);
                $lineBalances[$linkedLineId]['net_total'] += $this->minorUnits($correctionLine->net_total);
                $lineBalances[$linkedLineId]['vat_total'] += $this->minorUnits($correctionLine->vat_total);
                $lineBalances[$linkedLineId]['gross_total'] += $this->minorUnits($correctionLine->gross_total);
            }

            $appliedCorrectionIds[] = (int) $correction->id;
        }

        foreach ($lineBalances as $balance) {
            $this->assertBalanceDidNotCrossZero($original, $balance);
        }

        $remaining = array_values(array_filter(
            $lineBalances,
            fn (array $balance): bool => $balance['quantity'] !== 0
                || $balance['net_total'] !== 0
                || $balance['vat_total'] !== 0
                || $balance['gross_total'] !== 0,
        ));

        return [
            'lines' => $remaining,
            'correction_invoice_ids' => $appliedCorrectionIds,
        ];
    }

    private function assertTotalsMatchLines(Invoice $invoice): void
    {
        if ($invoice->lines->isEmpty()) {
            $this->accountingError($invoice, "dokument {$invoice->number} nie ma pozycji.");
        }

        foreach (['net_total', 'vat_total', 'gross_total'] as $field) {
            $header = $this->minorUnits($invoice->{$field});
            $lines = $invoice->lines->sum(fn (InvoiceLine $line): int => $this->minorUnits($line->{$field}));

            if ($header !== $lines) {
                $this->accountingError(
                    $invoice,
                    "suma {$field} dokumentu {$invoice->number} ({$header}) nie zgadza się z jego pozycjami ({$lines}) w groszach.",
                );
            }
        }
    }

    /**
     * @param  array{line:InvoiceLine,quantity:int,net_total:int,vat_total:int,gross_total:int}  $balance
     */
    private function assertBalanceDidNotCrossZero(Invoice $original, array $balance): void
    {
        $line = $balance['line'];
        $originalValues = [
            'quantity' => $this->quantityUnits($line->quantity),
            'net_total' => $this->minorUnits($line->net_total),
            'vat_total' => $this->minorUnits($line->vat_total),
            'gross_total' => $this->minorUnits($line->gross_total),
        ];

        foreach ($originalValues as $field => $value) {
            $remaining = $balance[$field];
            $crossedZero = ($value > 0 && $remaining < 0)
                || ($value < 0 && $remaining > 0)
                || ($value === 0 && $remaining !== 0);

            if ($crossedZero) {
                $this->accountingError(
                    $original,
                    "aktywne korekty przekraczają saldo pozycji #{$line->id} w polu {$field}.",
                );
            }
        }

        if ($balance['gross_total'] !== $balance['net_total'] + $balance['vat_total']) {
            $this->accountingError(
                $original,
                "saldo pozycji #{$line->id} ma niespójne wartości netto, VAT i brutto.",
            );
        }
    }

    private function minorUnits(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function quantityUnits(mixed $value): int
    {
        return (int) round((float) $value * 10_000);
    }

    /**
     * @param  list<array{line:InvoiceLine,quantity:int,net_total:int,vat_total:int,gross_total:int}>  $balances
     */
    private function minorUnitsTotal(array $balances, string $field): int
    {
        return array_sum(array_column($balances, $field));
    }

    private function accountingError(Invoice $invoice, string $detail): never
    {
        throw new RuntimeException("Błąd księgowy faktury {$invoice->number}: {$detail} Anulowanie zatrzymano, aby nie wystawić nadmiarowej korekty.");
    }

    /**
     * @param  array{line:InvoiceLine,quantity:int,net_total:int,vat_total:int,gross_total:int}  $balance
     * @return array<string, mixed>
     */
    private function correctionLine(array $balance, OrderCancellation $cancellation): array
    {
        $line = $balance['line'];
        $before = [
            'name' => $line->name,
            'sku' => $line->sku,
            'unit' => $line->unit,
            'quantity' => $balance['quantity'] / 10_000,
            'unit_net_price' => (float) $line->unit_net_price,
            'net_total' => $balance['net_total'] / 100,
            'vat_rate' => (float) $line->vat_rate,
            'vat_total' => $balance['vat_total'] / 100,
            'gross_total' => $balance['gross_total'] / 100,
        ];

        return [
            'product_id' => $line->product_id,
            'name' => 'Korekta anulowania: '.$line->name,
            'sku' => $line->sku,
            'unit' => $line->unit,
            'quantity' => -$balance['quantity'] / 10_000,
            'unit_net_price' => (float) $line->unit_net_price,
            'net_total' => -$balance['net_total'] / 100,
            'vat_rate' => (float) $line->vat_rate,
            'vat_total' => -$balance['vat_total'] / 100,
            'gross_total' => -$balance['gross_total'] / 100,
            'metadata' => [
                'source' => 'order_cancellation',
                'order_cancellation_uuid' => $cancellation->uuid,
                'corrected_invoice_line_id' => $line->id,
                'before_correction' => $before,
                'after_correction' => array_merge($before, [
                    'quantity' => 0,
                    'net_total' => 0,
                    'vat_total' => 0,
                    'gross_total' => 0,
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function correctionMetadata(Invoice $original, OrderCancellation $cancellation): array
    {
        $metadata = [
            'source' => 'order_cancellation',
            'order_cancellation_id' => $cancellation->id,
            'order_cancellation_uuid' => $cancellation->uuid,
            'correction_reason' => $cancellation->reason,
            'corrected_invoice_id' => $original->id,
            'corrected_invoice_number' => $original->number,
            'corrected_invoice_issue_date' => $original->issue_date?->toDateString(),
            'legal_review_required' => true,
        ];

        $oss = data_get($original->metadata, 'oss');

        if (is_array($oss)) {
            $metadata['oss'] = array_merge($oss, [
                'correction_of_oss_invoice' => true,
                'corrected_invoice_number' => $original->number,
            ]);
        }

        return $this->withKsefContext($metadata, $original);
    }

    private function correctionNumberType(Invoice $original): string
    {
        return is_array(data_get($original->metadata, 'oss')) ? 'CORRECTION_OSS' : 'FK';
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function withKsefContext(array $metadata, Invoice $original): array
    {
        $submission = $original->ksefSubmissions
            ->sortByDesc('id')
            ->first(fn (KsefSubmission $candidate): bool => in_array($candidate->status, [
                'accepted', 'submitted', 'running', 'queued', 'missing_configuration',
            ], true));
        $number = $this->firstFilled(
            $original->ksef_number,
            data_get($original->metadata, 'ksef.number'),
            $submission?->ksef_number,
        );
        $reference = $this->firstFilled(
            data_get($original->metadata, 'ksef.reference_number'),
            $submission?->reference_number,
        );
        $acceptedAt = $this->firstFilled(
            data_get($original->metadata, 'ksef.accepted_at'),
            $submission?->accepted_at?->toISOString(),
        );
        $policy = mb_strtolower(trim((string) data_get(
            $original->metadata,
            'ksef.send_policy',
            KsefEligibilityService::POLICY_AUTO,
        )));

        if (! filled($number) && ! $submission instanceof KsefSubmission && $policy !== KsefEligibilityService::POLICY_SEND) {
            return $metadata;
        }

        $metadata['corrected_invoice_ksef_number'] = $number;
        $metadata['corrected_invoice_ksef_reference_number'] = $reference;
        $metadata['corrected_invoice_ksef_accepted_at'] = $acceptedAt;
        $metadata['corrected_invoice_ksef_submission_id'] = $submission?->id;
        $metadata['ksef'] = array_filter([
            'send_policy' => KsefEligibilityService::POLICY_SEND,
            'policy_reason' => 'correction_of_ksef_invoice',
            'correction_policy_set_at' => now()->toISOString(),
            'correction_of_invoice_id' => $original->id,
            'correction_of_invoice_number' => $original->number,
            'correction_of_ksef_number' => $number,
            'correction_of_reference_number' => $reference,
            'correction_of_submission_id' => $submission?->id,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return $metadata;
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
}
