<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class InvoiceTemplateService
{
    private const DEFAULT_TEMPLATE_SOURCE = 'resources/views/invoices/print.blade.php';
    private const DEFAULT_TEMPLATE_VERSION = '2026-06-14-managed-business-invoice-v8';

    public function defaultTemplate(): InvoiceTemplate
    {
        $template = InvoiceTemplate::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($template instanceof InvoiceTemplate) {
            return $this->refreshManagedDefaultTemplateIfNeeded($template);
        }

        return InvoiceTemplate::query()->updateOrCreate(
            ['code' => 'default_vat'],
            [
                'name' => 'Domyślny szablon faktury VAT',
                'renderer' => 'blade_pdf',
                'template_body' => $this->defaultBody(),
                'settings' => $this->managedDefaultSettings(),
                'is_default' => true,
                'is_active' => true,
            ],
        );
    }

    /**
     * @param array{name?: string, template_body?: string} $payload
     */
    public function updateDefault(array $payload): InvoiceTemplate
    {
        $template = $this->defaultTemplate();
        $body = (string) ($payload['template_body'] ?? $template->template_body);

        $this->assertRenderable($body);

        $template->update([
            'name' => trim((string) ($payload['name'] ?? $template->name)),
            'template_body' => $body,
            'renderer' => 'blade_pdf',
            'settings' => [
                'source' => 'operator',
                'customized_at' => now()->toISOString(),
                'base_template_code' => $template->code,
                'legal_review_required' => true,
            ],
            'is_default' => true,
            'is_active' => true,
        ]);

        InvoiceTemplate::query()
            ->where('id', '!=', $template->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        return $template->refresh();
    }

    public function refreshManagedDefaultTemplate(): InvoiceTemplate
    {
        $template = InvoiceTemplate::query()
            ->where('code', 'default_vat')
            ->first();

        if (! $template instanceof InvoiceTemplate) {
            return $this->defaultTemplate();
        }

        if (! $this->isManagedDefaultTemplate($template)) {
            throw new RuntimeException('Domyślny szablon faktury był edytowany ręcznie, więc nie został nadpisany automatycznie.');
        }

        $template->update([
            'name' => 'Sempre faktura VAT',
            'renderer' => 'blade_pdf',
            'template_body' => $this->defaultBody(),
            'settings' => $this->managedDefaultSettings(),
            'is_default' => true,
            'is_active' => true,
        ]);

        InvoiceTemplate::query()
            ->where('id', '!=', $template->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        return $template->refresh();
    }

    public function assertRenderable(string $body): void
    {
        $this->renderBody($body, $this->sampleInvoice(), $this->defaultTemplate());
    }

    public function renderHtml(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'externalOrder', 'invoiceTemplate']);
        $this->loadCorrectedInvoiceForTemplate($invoice);

        $template = $invoice->invoiceTemplate;

        if ($template instanceof InvoiceTemplate && $template->is_active) {
            $template = $this->refreshManagedDefaultTemplateIfNeeded($template);
            $invoice->setRelation('invoiceTemplate', $template);
        }

        if (! $template instanceof InvoiceTemplate || ! $template->is_active) {
            $template = $this->defaultTemplate();
            $invoice->forceFill(['invoice_template_id' => $template->id])->save();
            $invoice->setRelation('invoiceTemplate', $template);
        }

        return $this->renderBody($template->template_body, $invoice, $template);
    }

    private function loadCorrectedInvoiceForTemplate(Invoice $invoice): void
    {
        if ($invoice->type !== 'correction') {
            return;
        }

        $correctedInvoiceId = data_get($invoice->metadata, 'corrected_invoice_id');
        $query = Invoice::query()->with('lines');

        if (method_exists($query, 'withTrashed')) {
            $query->withTrashed();
        }

        $correctedInvoice = is_numeric($correctedInvoiceId)
            ? $query->find((int) $correctedInvoiceId)
            : $query
                ->where('number', (string) data_get($invoice->metadata, 'corrected_invoice_number', ''))
                ->first();

        if ($correctedInvoice instanceof Invoice) {
            $invoice->setRelation('correctedInvoice', $correctedInvoice);
        }
    }

    private function renderBody(string $body, Invoice $invoice, InvoiceTemplate $template): string
    {
        try {
            return Blade::render($body, [
                'invoice' => $invoice,
                'template' => $template,
                'assets' => $this->templateAssets(),
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Szablon faktury nie może zostać wyrenderowany: ' . $this->safeErrorMessage($exception),
                previous: $exception,
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function templateAssets(): array
    {
        $logoPngPath = public_path('assets/sempre-logotyp.png');
        $logoSvgPath = public_path('assets/sempre-logotyp.svg');

        return [
            'logo_data_uri' => $this->assetDataUri($logoPngPath, 'image/png')
                ?? $this->assetDataUri($logoSvgPath, 'image/svg+xml')
                ?? '',
        ];
    }

    private function assetDataUri(string $path, string $mimeType): ?string
    {
        if (! File::exists($path)) {
            return null;
        }

        return 'data:' . $mimeType . ';base64,' . base64_encode(File::get($path));
    }

    private function sampleInvoice(): Invoice
    {
        $invoice = new Invoice([
            'number' => 'FV/TEST/000001',
            'type' => 'vat',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'sale_date' => now()->toDateString(),
            'payment_due_date' => now()->addDays(7)->toDateString(),
            'currency' => 'PLN',
            'seller_data' => [
                'name' => 'Sempre Love sp. z o.o.',
                'tax_id' => '5261040828',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'bank_account' => 'PL00111122223333444455556666',
            ],
            'buyer_data' => [
                'name' => 'Klient testowy',
                'tax_id' => '1111111111',
                'address_1' => 'Kupująca 2',
                'postcode' => '00-002',
                'city' => 'Łódź',
                'country' => 'PL',
            ],
            'net_total' => 100,
            'vat_total' => 23,
            'gross_total' => 123,
            'metadata' => [],
        ]);

        $invoice->setRelation('lines', new Collection([
            new InvoiceLine([
                'name' => 'Produkt testowy',
                'sku' => 'SKU-TEST',
                'unit' => 'szt',
                'quantity' => 1,
                'unit_net_price' => 100,
                'net_total' => 100,
                'vat_rate' => 23,
                'vat_total' => 23,
                'gross_total' => 123,
            ]),
        ]));

        return $invoice;
    }

    private function safeErrorMessage(Throwable $exception): string
    {
        return mb_substr(trim($exception->getMessage()), 0, 260, 'UTF-8') ?: $exception::class;
    }

    private function defaultBody(): string
    {
        $path = resource_path('views/invoices/print.blade.php');

        return File::exists($path)
            ? File::get($path)
            : '<!DOCTYPE html><html lang="pl"><body><h1>Faktura {{ $invoice->number }}</h1></body></html>';
    }

    private function refreshManagedDefaultTemplateIfNeeded(InvoiceTemplate $template): InvoiceTemplate
    {
        if (! $this->isManagedDefaultTemplate($template)) {
            return $template;
        }

        if (($template->settings['source_version'] ?? null) === self::DEFAULT_TEMPLATE_VERSION) {
            return $template;
        }

        return $this->refreshManagedDefaultTemplate();
    }

    private function isManagedDefaultTemplate(InvoiceTemplate $template): bool
    {
        $settings = $template->settings ?? [];

        if (($settings['source'] ?? null) === 'operator') {
            return false;
        }

        return $template->code === 'default_vat'
            && in_array($template->name, ['Domyślny szablon faktury VAT', 'Sempre faktura VAT'], true)
            && (
                ($settings['source'] ?? null) === self::DEFAULT_TEMPLATE_SOURCE
                || ! array_key_exists('source', $settings)
            );
    }

    /**
     * @return array<string, mixed>
     */
    private function managedDefaultSettings(): array
    {
        return [
            'source' => self::DEFAULT_TEMPLATE_SOURCE,
            'source_version' => self::DEFAULT_TEMPLATE_VERSION,
            'legal_review_required' => true,
        ];
    }
}
