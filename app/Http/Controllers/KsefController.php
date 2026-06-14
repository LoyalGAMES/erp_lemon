<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\KsefSubmission;
use App\Services\Audit\AuditLogService;
use App\Services\Invoices\InvoiceValidationService;
use App\Services\Ksef\KsefClient;
use App\Services\Ksef\KsefEligibilityService;
use App\Services\Ksef\KsefSubmissionService;
use App\Services\Ksef\KsefXmlBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

class KsefController extends Controller
{
    public function index(
        Request $request,
        KsefClient $client,
        KsefSubmissionService $submissions,
        InvoiceValidationService $validation,
        KsefXmlBuilder $xmlBuilder,
        KsefEligibilityService $eligibility,
    ): View|RedirectResponse|JsonResponse {
        if ($request->boolean('cleanup_legacy_errors')) {
            $updated = $submissions->cleanupLegacyGatewayConfigurationErrors();

            return redirect()
                ->route('ksef.index')
                ->with('status', "Wyczyszczono stare komunikaty KSeF: {$updated}.");
        }

        if ($request->boolean('diagnostics')) {
            return response()->json([
                'code_marker' => 'native-ksef-2.0-online-session',
                'native_client_active' => method_exists($client, 'sendNative'),
                'manual_ksef_submit_mode' => 'sync-web',
                'queue_connection' => (string) config('queue.default'),
                'server_time' => now()->toISOString(),
                'configuration' => $client->configurationStatus(),
                'legacy_gateway_error_count' => KsefSubmission::query()
                    ->where('last_error', KsefSubmissionService::LEGACY_GATEWAY_ERROR)
                    ->count(),
                'latest_submissions' => KsefSubmission::query()
                    ->latest()
                    ->limit(10)
                    ->get()
                    ->map(fn (KsefSubmission $submission): array => [
                        'id' => $submission->id,
                        'invoice_id' => $submission->invoice_id,
                        'status' => $submission->status,
                        'reference_number' => $submission->reference_number,
                        'ksef_number' => $submission->ksef_number,
                        'last_error' => $submission->last_error,
                        'last_error_is_legacy_gateway_error' => $submission->last_error === KsefSubmissionService::LEGACY_GATEWAY_ERROR,
                        'request_delivery_mode' => data_get($submission->request_metadata, 'delivery_mode'),
                        'response_mode' => data_get($submission->response_metadata, 'mode'),
                        'updated_at' => $submission->updated_at?->toISOString(),
                    ]),
                'next_steps' => [
                    'Jeśli native_client_active=false albo code_marker nie istnieje, serwer nie działa na nowym kodzie.',
                    'Jeśli queue_connection nie jest sync, po deployu uruchom php artisan queue:restart.',
                    'Jeśli legacy_gateway_error_count > 0, odpal /ksef?cleanup_legacy_errors=1.',
                ],
            ]);
        }

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
            'eligibility' => $invoices->mapWithKeys(fn (Invoice $invoice): array => [
                $invoice->id => $eligibility->state($invoice),
            ]),
            'submissions' => KsefSubmission::query()
                ->with('invoice')
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    public function updatePolicy(
        Request $request,
        Invoice $invoice,
        KsefEligibilityService $eligibility,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'ksef_policy' => ['required', 'string', 'in:auto,send,skip'],
        ]);

        $before = ['metadata' => $invoice->metadata];
        $metadata = $eligibility->metadataWithPolicy($invoice->metadata ?? [], $validated['ksef_policy']);

        $invoice->update(['metadata' => $metadata]);

        $audit->record('invoice.ksef_policy_updated', $invoice, $before, ['metadata' => $invoice->metadata]);

        return back()->with('status', "Zmieniono kwalifikację KSeF faktury {$invoice->number}.");
    }

    public function submit(Invoice $invoice, KsefSubmissionService $submissions): RedirectResponse
    {
        try {
            $submission = $submissions->prepare($invoice);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $submission = ! in_array($submission->status, ['submitted', 'accepted'], true)
            ? $submissions->submit($submission)
            : $submission;

        return back()->with('status', $this->submitStatusMessage($invoice->number, $submission));
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

        $submission = $submissions->submit($submission);

        return back()->with('status', $this->submitStatusMessage($submission->invoice?->number ?? 'faktury', $submission));
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
            'Content-Disposition' => 'attachment; filename="'.$number.'-ksef.xml"',
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
            $state['errors'][] = 'KSeF FA(3): brak mapowania dla stawek VAT '.implode(', ', array_map(
                fn (float $rate): string => rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',').'%',
                $unsupportedRates,
            )).'.';
            $state['is_blocking'] = true;
        }

        return $state;
    }

    private function submitStatusMessage(string $invoiceNumber, KsefSubmission $submission): string
    {
        return match ($submission->status) {
            'accepted' => "Faktura {$invoiceNumber} została przyjęta przez KSeF.",
            'submitted' => "Faktura {$invoiceNumber} została wysłana do KSeF. Odśwież status po zakończeniu przetwarzania.",
            default => "Próba wysyłki faktury {$invoiceNumber} do KSeF zakończyła się statusem: {$submission->status}. Sprawdź historię KSeF.",
        };
    }
}
