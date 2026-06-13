<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceFile;
use App\Services\Audit\AuditLogService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\InvoiceTemplateService;
use App\Services\Invoices\InvoiceValidationService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InvoiceController extends Controller
{
    public function index(
        InvoiceTemplateService $templates,
        InvoiceSettingsService $settings,
        InvoiceValidationService $validation,
    ): View
    {
        $invoices = Invoice::query()
            ->with(['lines', 'files', 'ksefSubmissions', 'invoiceTemplate', 'externalOrder'])
            ->latest('issue_date')
            ->get();

        $validationStates = $invoices->mapWithKeys(fn (Invoice $invoice): array => [
            $invoice->id => $validation->validate($invoice),
        ]);
        $sellerStatus = $settings->sellerConfigurationStatus();
        $sellerRepairableCount = $sellerStatus['is_ready']
            ? $invoices
                ->filter(fn (Invoice $invoice): bool => $this->hasSellerValidationErrors($validationStates->get($invoice->id, []))
                    && ! $this->isKsefAccepted($invoice))
                ->count()
            : 0;
        $woocommercePendingCount = $invoices
            ->filter(fn (Invoice $invoice): bool => $this->needsWooCommerceUpload($invoice)
                && ! (bool) data_get($validationStates->get($invoice->id, []), 'is_blocking', true))
            ->count();

        return view('invoices.index', [
            'title' => 'Faktury',
            'subtitle' => 'Faktury sprzedaży, pliki wydruku oraz edytowalny szablon dokumentu.',
            'module' => 'invoices',
            'invoices' => $invoices,
            'validation' => $validationStates,
            'validationSummary' => [
                'ready' => $validationStates
                    ->filter(fn (array $state): bool => ! $state['is_blocking'] && $state['warnings'] === [])
                    ->count(),
                'blocking' => $validationStates
                    ->filter(fn (array $state): bool => $state['is_blocking'])
                    ->count(),
                'warnings' => $validationStates
                    ->filter(fn (array $state): bool => ! $state['is_blocking'] && $state['warnings'] !== [])
                    ->count(),
                'messages' => $validationStates
                    ->sum(fn (array $state): int => count($state['errors']) + count($state['warnings'])),
            ],
            'template' => $templates->defaultTemplate(),
            'seller' => $settings->sellerData(),
            'sellerStatus' => $sellerStatus,
            'sellerRepairableCount' => $sellerRepairableCount,
            'woocommercePendingCount' => $woocommercePendingCount,
            'numbering' => $settings->numberingData(),
        ]);
    }

    public function updateTemplate(Request $request, InvoiceTemplateService $templates): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'template_body' => ['required', 'string', 'min:20'],
        ]);

        try {
            $template = $templates->updateDefault($validated);
        } catch (RuntimeException $exception) {
            return back()
                ->withInput()
                ->with('error', 'Nie zapisano szablonu faktury. ' . $exception->getMessage());
        }

        return back()->with('status', "Szablon faktury {$template->name} został zapisany.");
    }

    public function updateSeller(Request $request, InvoiceSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'tax_id' => ['required', 'string', 'max:32'],
            'address_1' => ['required', 'string', 'max:255'],
            'address_2' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:120'],
            'country' => ['required', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'bank_account' => ['nullable', 'string', 'max:64'],
        ]);

        $sellerStatus = $settings->sellerConfigurationStatus($validated);

        if (! $sellerStatus['is_ready']) {
            return back()
                ->withInput()
                ->with('error', 'Nie zapisano danych sprzedawcy. ' . implode(' ', $sellerStatus['errors']));
        }

        $settings->updateSellerData($validated);

        $message = 'Dane sprzedawcy do faktur zostały zapisane.';

        if ($sellerStatus['warnings'] !== []) {
            $message .= ' Ostrzeżenia: ' . implode(' ', $sellerStatus['warnings']);
        }

        return back()->with('status', $message);
    }

    public function updateSettings(Request $request, InvoiceSettingsService $settings): RedirectResponse
    {
        $validated = $request->validate([
            'sales_prefix' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\/-]+$/'],
            'correction_prefix' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\/-]+$/'],
            'pattern' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/{}-]+$/'],
            'padding' => ['required', 'integer', 'min:3', 'max:9'],
            'payment_due_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        $settings->updateNumberingData($validated);

        return back()->with('status', 'Ustawienia numeracji i płatności faktur zostały zapisane.');
    }

    public function edit(Invoice $invoice, InvoiceValidationService $validation): View
    {
        $invoice->load(['lines', 'ksefSubmissions']);

        return view('invoices.edit', [
            'title' => 'Edycja faktury',
            'subtitle' => 'Popraw dane dokumentu przed ponownym wygenerowaniem plików, wysyłką do WooCommerce lub KSeF.',
            'module' => 'invoices',
            'invoice' => $invoice,
            'validationState' => $validation->validate($invoice),
            'isKsefAccepted' => $this->isKsefAccepted($invoice),
        ]);
    }

    public function updateData(
        Request $request,
        Invoice $invoice,
        OrderInvoiceService $invoices,
        AuditLogService $audit,
    ): RedirectResponse {
        $invoice->load(['ksefSubmissions']);

        if ($this->isKsefAccepted($invoice)) {
            return back()->with('error', 'Faktura przyjęta przez KSeF nie może być edytowana. Wystaw fakturę korygującą.');
        }

        $validated = $request->validate([
            'issue_date' => ['required', 'date'],
            'sale_date' => ['required', 'date'],
            'payment_due_date' => ['nullable', 'date'],
            'currency' => ['required', 'string', 'size:3'],
            'payment_method' => ['nullable', 'string', 'max:120'],
            'seller.name' => ['required', 'string', 'max:255'],
            'seller.tax_id' => ['required', 'string', 'max:32'],
            'seller.address_1' => ['required', 'string', 'max:255'],
            'seller.address_2' => ['nullable', 'string', 'max:255'],
            'seller.postcode' => ['nullable', 'string', 'max:32'],
            'seller.city' => ['nullable', 'string', 'max:120'],
            'seller.country' => ['required', 'string', 'size:2'],
            'seller.email' => ['nullable', 'email', 'max:255'],
            'seller.phone' => ['nullable', 'string', 'max:64'],
            'seller.bank_account' => ['nullable', 'string', 'max:64'],
            'buyer.name' => ['required', 'string', 'max:255'],
            'buyer.tax_id' => ['nullable', 'string', 'max:32'],
            'buyer.address_1' => ['required', 'string', 'max:255'],
            'buyer.address_2' => ['nullable', 'string', 'max:255'],
            'buyer.postcode' => ['nullable', 'string', 'max:32'],
            'buyer.city' => ['nullable', 'string', 'max:120'],
            'buyer.country' => ['required', 'string', 'size:2'],
            'buyer.email' => ['nullable', 'email', 'max:255'],
            'buyer.phone' => ['nullable', 'string', 'max:64'],
        ]);

        $before = $invoice->only([
            'issue_date',
            'sale_date',
            'payment_due_date',
            'currency',
            'payment_method',
            'seller_data',
            'buyer_data',
            'metadata',
        ]);

        $metadata = $invoice->metadata ?? [];
        $metadata['last_data_edit_at'] = now()->toISOString();
        $metadata['legal_review_required'] = true;

        if (data_get($metadata, 'woocommerce_upload.status') === 'success') {
            data_set($metadata, 'woocommerce_upload.status', 'stale');
            data_set($metadata, 'woocommerce_upload.requires_resend', true);
            data_set($metadata, 'woocommerce_upload.stale_since', now()->toISOString());
        }

        $invoice->update([
            'issue_date' => $validated['issue_date'],
            'sale_date' => $validated['sale_date'],
            'payment_due_date' => $validated['payment_due_date'] ?? null,
            'currency' => strtoupper($validated['currency']),
            'payment_method' => filled($validated['payment_method'] ?? null) ? $validated['payment_method'] : null,
            'seller_data' => $this->cleanPartyData($validated['seller']),
            'buyer_data' => $this->cleanPartyData($validated['buyer']),
            'metadata' => $metadata,
        ]);

        $invoice = $invoices->regenerateFiles($invoice);

        $audit->record('invoice.data_updated', $invoice, $before, $invoice->only([
            'issue_date',
            'sale_date',
            'payment_due_date',
            'currency',
            'payment_method',
            'seller_data',
            'buyer_data',
            'metadata',
        ]));

        return redirect()
            ->route('invoices.edit', $invoice)
            ->with('status', "Dane faktury {$invoice->number} zostały zapisane i pliki zostały wygenerowane ponownie.");
    }

    public function preview(Invoice $invoice, OrderInvoiceService $invoices): Response
    {
        try {
            $pdf = $invoices->previewPdf($invoice);
        } catch (RuntimeException $exception) {
            return response(
                '<!DOCTYPE html><html lang="pl"><meta charset="utf-8"><body><h1>Błąd szablonu faktury</h1><p>'
                . e($exception->getMessage())
                . '</p></body></html>',
                422,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $filename = str_replace(['/', '\\'], '-', $invoice->number) . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function applySellerSettings(
        Invoice $invoice,
        InvoiceSettingsService $settings,
        OrderInvoiceService $invoices,
        AuditLogService $audit,
    ): RedirectResponse {
        $invoice->load(['ksefSubmissions']);

        if ($this->isKsefAccepted($invoice)) {
            return back()->with('error', 'Faktura przyjęta przez KSeF nie może być zmieniona. Wystaw fakturę korygującą.');
        }

        $sellerStatus = $settings->sellerConfigurationStatus();

        if (! $sellerStatus['is_ready']) {
            return back()->with('error', 'Najpierw uzupełnij dane sprzedawcy w ustawieniach faktur. ' . implode(' ', $sellerStatus['errors']));
        }

        try {
            $invoice = $this->applySellerSettingsToInvoice($invoice, $settings->sellerData(), $invoices, $audit);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Dane sprzedawcy zapisano, ale nie wygenerowano plików faktury. ' . $exception->getMessage());
        }

        return back()->with('status', "Uzupełniono dane sprzedawcy i wygenerowano ponownie pliki faktury {$invoice->number}.");
    }

    public function applySellerSettingsBatch(
        InvoiceSettingsService $settings,
        InvoiceValidationService $validation,
        OrderInvoiceService $invoices,
        AuditLogService $audit,
    ): RedirectResponse {
        $sellerStatus = $settings->sellerConfigurationStatus();

        if (! $sellerStatus['is_ready']) {
            return back()->with('error', 'Najpierw uzupełnij dane sprzedawcy w ustawieniach faktur. ' . implode(' ', $sellerStatus['errors']));
        }

        $sellerData = $settings->sellerData();
        $updated = 0;
        $skipped = 0;
        $failed = [];

        Invoice::query()
            ->with(['lines', 'files', 'externalOrder', 'invoiceTemplate', 'ksefSubmissions'])
            ->orderBy('id')
            ->get()
            ->each(function (Invoice $invoice) use ($validation, $invoices, $audit, $sellerData, &$updated, &$skipped, &$failed): void {
                if ($this->isKsefAccepted($invoice) || ! $this->hasSellerValidationErrors($validation->validate($invoice))) {
                    $skipped++;

                    return;
                }

                try {
                    $this->applySellerSettingsToInvoice($invoice, $sellerData, $invoices, $audit);
                    $updated++;
                } catch (RuntimeException $exception) {
                    $failed[] = $invoice->number . ': ' . $exception->getMessage();
                }
            });

        if ($failed !== []) {
            return back()->with(
                'error',
                'Uzupełniono dane sprzedawcy na części faktur. Poprawione: '
                . $updated
                . ', pominięte: '
                . $skipped
                . '. Błędy: '
                . implode(' ', $failed),
            );
        }

        if ($updated === 0) {
            return back()->with('status', 'Nie znaleziono faktur wymagających uzupełnienia danych sprzedawcy.');
        }

        return back()->with('status', 'Uzupełniono dane sprzedawcy i wygenerowano ponownie pliki faktur. Poprawione: ' . $updated . ', pominięte: ' . $skipped . '.');
    }

    public function regenerate(Invoice $invoice, OrderInvoiceService $invoices): RedirectResponse
    {
        try {
            $invoice = $invoices->regenerateFiles($invoice);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Nie wygenerowano plików faktury. ' . $exception->getMessage());
        }

        return back()->with('status', "Pliki faktury {$invoice->number} zostały wygenerowane ponownie z aktualnego szablonu.");
    }

    public function uploadToWooCommerce(
        Invoice $invoice,
        InvoiceWooCommerceUploadService $uploader,
    ): RedirectResponse {
        try {
            $uploader->upload($invoice);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Upload faktury do WooCommerce nie powiódł się: ' . $exception->getMessage());
        }

        return back()->with('status', "Faktura {$invoice->number} została wysłana do zamówienia WooCommerce.");
    }

    public function uploadPendingToWooCommerce(
        InvoiceWooCommerceUploadService $uploader,
        InvoiceValidationService $validation,
    ): RedirectResponse {
        $uploaded = 0;
        $skipped = 0;
        $failed = [];

        Invoice::query()
            ->with(['lines', 'files', 'externalOrder.salesChannel', 'invoiceTemplate', 'ksefSubmissions'])
            ->orderBy('id')
            ->get()
            ->each(function (Invoice $invoice) use ($uploader, $validation, &$uploaded, &$skipped, &$failed): void {
                if (! $this->needsWooCommerceUpload($invoice)) {
                    $skipped++;

                    return;
                }

                $validationState = $validation->validate($invoice);

                if ($validationState['is_blocking']) {
                    $skipped++;

                    return;
                }

                try {
                    $uploader->upload($invoice);
                    $uploaded++;
                } catch (RuntimeException $exception) {
                    $failed[] = $invoice->number . ': ' . $exception->getMessage();
                }
            });

        if ($failed !== []) {
            return back()->with(
                'error',
                'Wysłano część faktur do WooCommerce. Wysłane: '
                . $uploaded
                . ', pominięte: '
                . $skipped
                . '. Błędy: '
                . implode(' ', $failed),
            );
        }

        if ($uploaded === 0) {
            return back()->with('status', 'Nie znaleziono faktur oczekujących na wysyłkę do WooCommerce.');
        }

        return back()->with('status', 'Wysłano zaległe faktury do WooCommerce. Wysłane: ' . $uploaded . ', pominięte: ' . $skipped . '.');
    }

    public function downloadFile(Invoice $invoice, InvoiceFile $file): BinaryFileResponse
    {
        $absolutePath = storage_path('app/' . $file->path);

        if ($file->invoice_id !== $invoice->id || $file->disk !== 'local' || ! File::exists($absolutePath)) {
            abort(404);
        }

        $filename = str_replace(['/', '\\'], '-', $invoice->number) . '.' . ($file->type === 'pdf' ? 'pdf' : 'html');

        return response()->download($absolutePath, $filename, [
            'Content-Type' => $file->mime_type ?? 'application/octet-stream',
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function cleanPartyData(array $data): array
    {
        return [
            'name' => trim((string) ($data['name'] ?? '')),
            'tax_id' => trim((string) ($data['tax_id'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'address_1' => trim((string) ($data['address_1'] ?? '')),
            'address_2' => trim((string) ($data['address_2'] ?? '')),
            'postcode' => trim((string) ($data['postcode'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'country' => strtoupper(trim((string) ($data['country'] ?? 'PL'))) ?: 'PL',
            'bank_account' => trim((string) ($data['bank_account'] ?? '')),
        ];
    }

    private function isKsefAccepted(Invoice $invoice): bool
    {
        return filled($invoice->ksef_number)
            || $invoice->ksefSubmissions->contains(fn ($submission): bool => $submission->status === 'accepted');
    }

    /**
     * @param array{errors?: list<string>} $validationState
     */
    private function hasSellerValidationErrors(array $validationState): bool
    {
        foreach (($validationState['errors'] ?? []) as $message) {
            if (str_contains($message, 'sprzedawcy')) {
                return true;
            }
        }

        return false;
    }

    private function needsWooCommerceUpload(Invoice $invoice): bool
    {
        if (! filled($invoice->external_order_id)) {
            return false;
        }

        $status = data_get($invoice->metadata, 'woocommerce_upload.status');

        return data_get($invoice->metadata, 'woocommerce_upload.requires_resend') === true
            || $status !== 'success';
    }

    /**
     * @param array<string, string> $sellerData
     */
    private function applySellerSettingsToInvoice(
        Invoice $invoice,
        array $sellerData,
        OrderInvoiceService $invoices,
        AuditLogService $audit,
    ): Invoice {
        $before = $invoice->only(['seller_data', 'metadata']);
        $metadata = $invoice->metadata ?? [];
        $metadata['seller_settings_applied_at'] = now()->toISOString();
        $metadata['legal_review_required'] = true;

        if (data_get($metadata, 'woocommerce_upload.status') === 'success') {
            data_set($metadata, 'woocommerce_upload.status', 'stale');
            data_set($metadata, 'woocommerce_upload.requires_resend', true);
            data_set($metadata, 'woocommerce_upload.stale_since', now()->toISOString());
        }

        $invoice->update([
            'seller_data' => $sellerData,
            'metadata' => $metadata,
        ]);

        $invoice = $invoices->regenerateFiles($invoice);

        $audit->record('invoice.seller_settings_applied', $invoice, $before, $invoice->only(['seller_data', 'metadata']));

        return $invoice;
    }
}
