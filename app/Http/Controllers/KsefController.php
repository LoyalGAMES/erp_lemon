<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\SubmitInvoiceToKsefJob;
use App\Models\Invoice;
use App\Models\KsefSubmission;
use App\Services\Audit\AuditLogService;
use App\Services\Invoices\InvoiceValidationService;
use App\Services\Ksef\KsefClient;
use App\Services\Ksef\KsefSubmissionService;
use App\Services\Ksef\KsefXmlBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

class KsefController extends Controller
{
    public function index(
        KsefClient $client,
        InvoiceValidationService $validation,
        KsefXmlBuilder $xmlBuilder,
    ): View
    {
        $invoices = Invoice::query()
            ->with(['lines', 'ksefSubmissions', 'externalOrder'])
            ->latest('issue_date')
            ->get();

        return view('ksef.index', [
            'title' => 'KSeF',
            'subtitle' => 'Przygotowanie XML FA(3), kolejka wysyłki i statusy integracji z KSeF 2.0.',
            'module' => 'ksef',
            'configuration' => $client->configurationStatus(),
            'invoices' => $invoices,
            'validation' => $invoices->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->id => $this->ksefValidationState($invoice, $validation, $xmlBuilder),
            ]),
            'submissions' => KsefSubmission::query()
                ->with('invoice')
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    public function submit(Invoice $invoice, KsefSubmissionService $submissions): RedirectResponse
    {
        try {
            $submission = $submissions->prepare($invoice);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if (! in_array($submission->status, ['submitted', 'accepted'], true)) {
            SubmitInvoiceToKsefJob::dispatch($submission->id);
        }

        return back()->with('status', "Faktura {$invoice->number} została dodana do kolejki KSeF.");
    }

    public function retry(
        KsefSubmission $submission,
        KsefSubmissionService $submissions,
        AuditLogService $audit,
    ): RedirectResponse {
        $before = [
            'status' => $submission->status,
            'last_error' => $submission->last_error,
            'retry_count' => (int) data_get($submission->request_metadata, 'retry_count', 0),
        ];

        try {
            $submission = $submissions->retry($submission);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record(
            'ksef.submission_retried',
            $submission,
            $before,
            [
                'status' => $submission->status,
                'retry_count' => (int) data_get($submission->request_metadata, 'retry_count', 0),
            ],
            [
                'invoice_id' => $submission->invoice_id,
            ],
        );

        SubmitInvoiceToKsefJob::dispatch($submission->id);

        return back()->with('status', 'Zgłoszenie KSeF zostało ponownie dodane do kolejki.');
    }

    public function refresh(
        KsefSubmission $submission,
        KsefSubmissionService $submissions,
        AuditLogService $audit,
    ): RedirectResponse {
        $before = [
            'status' => $submission->status,
            'reference_number' => $submission->reference_number,
            'ksef_number' => $submission->ksef_number,
            'last_error' => $submission->last_error,
        ];

        try {
            $submission = $submissions->refreshStatus($submission);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record(
            'ksef.submission_status_checked',
            $submission,
            $before,
            [
                'status' => $submission->status,
                'reference_number' => $submission->reference_number,
                'ksef_number' => $submission->ksef_number,
            ],
            [
                'invoice_id' => $submission->invoice_id,
            ],
        );

        return back()->with('status', 'Status zgłoszenia KSeF został odświeżony.');
    }

    public function xml(KsefSubmission $submission): Response
    {
        abort_if($submission->xml_payload === null || $submission->xml_payload === '', 404);

        $number = str_replace(['/', '\\'], '-', $submission->invoice?->number ?? 'faktura');

        return response($submission->xml_payload, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $number . '-ksef.xml"',
        ]);
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>, is_blocking: bool}
     */
    private function ksefValidationState(
        Invoice $invoice,
        InvoiceValidationService $validation,
        KsefXmlBuilder $xmlBuilder,
    ): array {
        $state = $validation->validate($invoice);
        $unsupportedRates = $xmlBuilder->unsupportedVatRates($invoice);

        if ($unsupportedRates !== []) {
            $state['errors'][] = 'KSeF FA(3): brak mapowania dla stawek VAT ' . implode(', ', array_map(
                fn (float $rate): string => rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',') . '%',
                $unsupportedRates,
            )) . '.';
            $state['is_blocking'] = true;
        }

        return $state;
    }
}
