<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceFile;
use App\Models\InvoiceTemplate;
use App\Services\Invoices\InvoiceNumberService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\InvoiceTemplateService;
use App\Services\Invoices\InvoiceValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InvoiceTemplateWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_edit_invoice_template_preview_and_regenerate_files(): void
    {
        $invoice = $this->createInvoice();

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Edytuj szablon faktury')
            ->assertSee('Ustawienia faktur')
            ->assertSee('Dane sprzedawcy')
            ->assertSee($invoice->number);

        $this->put(route('invoices.seller.update'), [
            'name' => 'Sempre Love sp. z o.o.',
            'tax_id' => '5261040828',
            'address_1' => 'Testowa 1',
            'postcode' => '00-001',
            'city' => 'Warszawa',
            'country' => 'PL',
            'email' => 'biuro@example.test',
        ])->assertRedirect()->assertSessionHas('status');

        $body = <<<'BLADE'
<!DOCTYPE html>
<html lang="pl">
<body>
    <h1>Niestandardowy szablon Sempre</h1>
    <p>Faktura: {{ $invoice->number }}</p>
    <p>Nabywca: {{ $invoice->buyer_data['name'] ?? '-' }}</p>
</body>
</html>
BLADE;

        $this->put(route('invoices.template.update'), [
            'name' => 'Sempre custom',
            'template_body' => $body,
        ])->assertRedirect()->assertSessionHas('status');

        $template = InvoiceTemplate::query()->firstOrFail();
        $this->assertSame('Sempre custom', $template->name);
        $this->assertTrue($template->is_default);

        $preview = $this->get(route('invoices.preview', $invoice))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="FV-2026-000010.pdf"');

        $this->assertStringStartsWith('%PDF-', $preview->getContent());

        $this->post(route('invoices.regenerate', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $invoice->refresh();
        $this->assertSame($template->id, $invoice->invoice_template_id);
        $this->assertSame(2, InvoiceFile::query()->where('invoice_id', $invoice->id)->count());

        $htmlFile = InvoiceFile::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'html')
            ->firstOrFail();

        $this->assertSame($template->id, $htmlFile->metadata['template_id']);
        $this->assertStringContainsString('Niestandardowy szablon Sempre', File::get(storage_path('app/'.$htmlFile->path)));

        $pdfFile = InvoiceFile::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'pdf')
            ->firstOrFail();

        $this->get(route('invoices.files.download', [$invoice, $htmlFile]))
            ->assertOk()
            ->assertDownload('FV-2026-000010.html');

        $this->get(route('invoices.files.download', [$invoice, $pdfFile]))
            ->assertOk()
            ->assertDownload('FV-2026-000010.pdf');

        $this->get('/modul/invoices')->assertRedirect('/invoices');
    }

    public function test_invalid_invoice_template_is_not_saved(): void
    {
        $this->createInvoice();

        $default = app(InvoiceTemplateService::class)->defaultTemplate();
        $originalBody = $default->template_body;

        $invalidBody = <<<'BLADE'
<!DOCTYPE html>
<html lang="pl">
<body>
    {{ $invoice->lines->first()->methodThatDoesNotExistForValidation() }}
</body>
</html>
BLADE;

        $this->put(route('invoices.template.update'), [
            'name' => 'Popsuty szablon',
            'template_body' => $invalidBody,
        ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Nie zapisano szablonu faktury')
                && str_contains($message, 'Szablon faktury nie może zostać wyrenderowany'));

        $default->refresh();

        $this->assertNotSame('Popsuty szablon', $default->name);
        $this->assertSame($originalBody, $default->template_body);
    }

    public function test_attached_managed_invoice_template_refreshes_from_current_source(): void
    {
        $oldTemplateBody = <<<'BLADE'
<!DOCTYPE html>
<html lang="pl">
<body>STARY SZABLON {{ $invoice->number }}</body>
</html>
BLADE;

        $template = InvoiceTemplate::query()->updateOrCreate(['code' => 'default_vat'], [
            'name' => 'Sempre faktura VAT',
            'renderer' => 'blade_pdf',
            'template_body' => $oldTemplateBody,
            'settings' => [
                'source' => 'resources/views/invoices/print.blade.php',
                'source_version' => '2026-06-03-managed-branded-invoice-v3',
                'legal_review_required' => true,
            ],
            'is_default' => true,
            'is_active' => true,
        ]);

        $invoice = $this->createInvoice();
        $invoice->update(['invoice_template_id' => $template->id]);

        $html = app(InvoiceTemplateService::class)->renderHtml($invoice->fresh());

        $template->refresh();

        $this->assertStringNotContainsString('STARY SZABLON', $html);
        $this->assertStringContainsString('width: 198px', $html);
        $this->assertNotSame('2026-06-03-managed-branded-invoice-v3', $template->settings['source_version']);
    }

    public function test_invalid_stored_invoice_template_does_not_delete_existing_files(): void
    {
        $invoice = $this->createInvoice();

        $this->post(route('invoices.regenerate', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $invoice->refresh()->load(['files', 'invoiceTemplate']);
        $this->assertSame(2, $invoice->files->count());
        $paths = $invoice->files->pluck('path')->all();

        $template = $invoice->invoiceTemplate;
        $this->assertInstanceOf(InvoiceTemplate::class, $template);
        $template->update([
            'template_body' => <<<'BLADE'
<!DOCTYPE html>
<html lang="pl">
<body>
    {{ $invoice->lines->first()->methodThatDoesNotExistForRegeneration() }}
</body>
</html>
BLADE,
        ]);

        $this->get(route('invoices.preview', $invoice))
            ->assertStatus(422)
            ->assertSee('Błąd szablonu faktury')
            ->assertSee('Szablon faktury nie może zostać wyrenderowany');

        $this->post(route('invoices.regenerate', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Nie wygenerowano plików faktury')
                && str_contains($message, 'Szablon faktury nie może zostać wyrenderowany'));

        $this->assertSame(2, InvoiceFile::query()->where('invoice_id', $invoice->id)->count());

        foreach ($paths as $path) {
            $this->assertFileExists(storage_path('app/'.$path));
        }
    }

    public function test_operator_can_configure_invoice_numbering_series(): void
    {
        $this->put(route('invoices.settings.update'), [
            'sales_prefix' => 'FV/ERP',
            'correction_prefix' => 'FK/ERP',
            'proforma_prefix' => 'PRO/ERP',
            'oss_sales_prefix' => 'FV/OSS',
            'oss_correction_prefix' => 'FVK/OSS',
            'oss_pattern' => '{PREFIX}/{SEQ}/{MM}/{YYYY}',
            'oss_padding' => 1,
            'pattern' => '{PREFIX}/{YYYY}/{SEQ}',
            'padding' => 4,
            'payment_due_days' => 14,
            'default_ksef_policy' => 'skip',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('skip', app(InvoiceSettingsService::class)->ksefData()['default_send_policy']);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('FV/ERP')
            ->assertSee('FK/ERP')
            ->assertSee('PRO/ERP')
            ->assertSee('FV/OSS')
            ->assertSee('FVK/OSS')
            ->assertSee('{PREFIX}/{YYYY}/{SEQ}')
            ->assertSee('{PREFIX}/{SEQ}/{MM}/{YYYY}')
            ->assertSee('14')
            ->assertSee('Domyślnie nie wysyłaj do KSeF');

        $invoice = $this->createInvoice();
        $invoice->update(['number' => 'FV/ERP/'.now()->format('Y').'/0009']);

        $numbers = app(InvoiceNumberService::class);

        $this->assertSame('FV/ERP/'.now()->format('Y').'/0010', $numbers->next());
        $this->assertSame('FK/ERP/'.now()->format('Y').'/0001', $numbers->next('FK'));
        $this->assertSame('FV/OSS/1/'.now()->format('m/Y'), $numbers->next('OSS'));
        $this->assertSame('FVK/OSS/1/'.now()->format('m/Y'), $numbers->next('CORRECTION_OSS'));

        $this->put(route('invoices.settings.update'), [
            'sales_prefix' => 'FV/ERP',
            'correction_prefix' => 'FK/ERP',
            'proforma_prefix' => 'PRO/ERP',
            'oss_sales_prefix' => 'FV/OSS',
            'oss_correction_prefix' => 'FVK/OSS',
            'oss_pattern' => '{PREFIX}/{SEQ}/{MM}/{YYYY}',
            'oss_padding' => 1,
            'pattern' => '{PREFIX}/{MM}/{YYYY}/{SEQ}',
            'padding' => 3,
            'payment_due_days' => 14,
            'default_ksef_policy' => 'auto',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('FV/ERP/'.now()->format('m/Y').'/001', $numbers->next());
    }

    public function test_generated_invoice_pdf_uses_unicode_renderer_for_polish_text(): void
    {
        $invoice = $this->createInvoice();
        $invoice->update([
            'seller_data' => [
                'name' => 'Zażółć sp. z o.o.',
                'tax_id' => '5261040828',
                'address_1' => 'Łąkowa 1',
                'postcode' => '00-001',
                'city' => 'Łódź',
                'country' => 'PL',
            ],
            'buyer_data' => [
                'name' => 'Gęślą Jaźń',
                'tax_id' => '1111111111',
                'address_1' => 'Śliwkowa 2',
                'postcode' => '00-002',
                'city' => 'Żyrardów',
                'country' => 'PL',
            ],
        ]);
        $invoice->lines()->firstOrFail()->update([
            'name' => 'Koszula ŁÓDŹ śliwkowa',
        ]);

        $this->post(route('invoices.regenerate', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $htmlFile = InvoiceFile::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'html')
            ->firstOrFail();
        $pdfFile = InvoiceFile::query()
            ->where('invoice_id', $invoice->id)
            ->where('type', 'pdf')
            ->firstOrFail();

        $this->assertStringContainsString('Koszula ŁÓDŹ śliwkowa', File::get(storage_path('app/'.$htmlFile->path)));
        $this->assertStringStartsWith('%PDF-', File::get(storage_path('app/'.$pdfFile->path)));
        $this->assertSame('dompdf_html_pdf', $pdfFile->metadata['renderer']);
        $this->assertTrue($pdfFile->metadata['unicode_text']);
        $this->assertTrue($pdfFile->metadata['html_layout']);
    }

    public function test_correction_template_builds_before_and_after_rows_from_corrected_invoice(): void
    {
        $original = $this->createInvoice('FV/2026/000020');
        $originalLine = $original->lines()->firstOrFail();

        $correction = Invoice::query()->create([
            'number' => 'FK/2026/000001',
            'type' => 'correction',
            'status' => 'issued',
            'issue_date' => '2026-06-10',
            'sale_date' => '2026-06-01',
            'payment_due_date' => '2026-06-17',
            'currency' => 'PLN',
            'seller_data' => $original->seller_data,
            'buyer_data' => $original->buyer_data,
            'net_total' => -100,
            'vat_total' => -23,
            'gross_total' => -123,
            'issued_at' => now(),
            'metadata' => [
                'corrected_invoice_id' => $original->id,
                'corrected_invoice_number' => $original->number,
                'corrected_invoice_issue_date' => $original->issue_date?->toDateString(),
                'correction_reason' => 'Zwrot towaru',
            ],
        ]);

        $correction->lines()->create([
            'name' => 'Korekta zwrotu: Produkt fakturowany',
            'sku' => 'SKU-FV',
            'unit' => 'szt',
            'quantity' => -1,
            'unit_net_price' => 100,
            'net_total' => -100,
            'vat_rate' => 23,
            'vat_total' => -23,
            'gross_total' => -123,
            'metadata' => [
                'corrected_invoice_line_id' => $originalLine->id,
            ],
        ]);

        $correction->loadMissing('correctedInvoice.lines');

        $this->assertTrue($correction->correctedInvoice->is($original));

        $html = app(InvoiceTemplateService::class)->renderHtml($correction);

        $this->assertStringContainsString('Pozycje przed korektą', $html);
        $this->assertStringContainsString('Pozycje po korekcie', $html);
        $this->assertStringContainsString('FV/2026/000020', $html);
        $this->assertStringContainsString('1 szt', $html);
        $this->assertStringContainsString('0 szt', $html);
        $this->assertStringNotContainsString('Pozycje korekty', $html);

        $numberOnlyCorrection = Invoice::query()->create([
            'number' => 'FK/2026/000002',
            'type' => 'correction',
            'status' => 'issued',
            'issue_date' => '2026-06-10',
            'sale_date' => '2026-06-01',
            'payment_due_date' => '2026-06-17',
            'currency' => 'PLN',
            'seller_data' => $original->seller_data,
            'buyer_data' => $original->buyer_data,
            'net_total' => -100,
            'vat_total' => -23,
            'gross_total' => -123,
            'issued_at' => now(),
            'metadata' => [
                'corrected_invoice_number' => $original->number,
                'corrected_invoice_issue_date' => $original->issue_date?->toDateString(),
                'correction_reason' => 'Zwrot towaru',
            ],
        ]);

        $numberOnlyCorrection->lines()->create([
            'name' => 'Korekta zwrotu: Produkt fakturowany',
            'sku' => 'SKU-FV',
            'unit' => 'szt',
            'quantity' => -1,
            'unit_net_price' => 100,
            'net_total' => -100,
            'vat_rate' => 23,
            'vat_total' => -23,
            'gross_total' => -123,
        ]);

        $numberOnlyHtml = app(InvoiceTemplateService::class)->renderHtml($numberOnlyCorrection);

        $this->assertStringContainsString('Pozycje przed korektą', $numberOnlyHtml);
        $this->assertStringContainsString('Pozycje po korekcie', $numberOnlyHtml);
        $this->assertStringContainsString('0 szt', $numberOnlyHtml);
    }

    public function test_operator_can_fix_invoice_data_and_regenerate_files(): void
    {
        $invoice = $this->createInvoice();
        $line = $invoice->lines()->firstOrFail();
        $invoice->update([
            'metadata' => [
                'woocommerce_upload' => [
                    'status' => 'success',
                    'requires_resend' => false,
                    'uploaded_at' => now()->subDay()->toISOString(),
                ],
            ],
        ]);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('Edycja faktury')
            ->assertSee('Walidacja faktury: OK');

        $this->put(route('invoices.data.update', $invoice), [
            'issue_date' => '2026-06-02',
            'sale_date' => '2026-06-01',
            'payment_due_date' => '2026-06-16',
            'currency' => 'PLN',
            'payment_method' => 'Przelew',
            'ksef_policy' => 'skip',
            'seller' => [
                'name' => 'Sempre Love sp. z o.o.',
                'tax_id' => '5261040828',
                'address_1' => 'Łąkowa 1',
                'address_2' => '',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
                'email' => 'biuro@example.test',
                'phone' => '+48123123123',
                'bank_account' => 'PL00111122223333444455556666',
            ],
            'buyer' => [
                'name' => 'Poprawiony klient',
                'tax_id' => '1111111111',
                'address_1' => 'Kupująca 22',
                'address_2' => '',
                'postcode' => '00-002',
                'city' => 'Łódź',
                'country' => 'PL',
                'email' => 'klient@example.test',
                'phone' => '+48555111222',
            ],
            'lines' => [
                $line->id => [
                    'id' => $line->id,
                    'name' => 'Poprawiony produkt fakturowany',
                    'sku' => 'SKU-FV-EDIT',
                    'unit' => 'szt',
                    'quantity' => '2',
                    'unit_net_price' => '60',
                    'net_total' => '120',
                    'vat_rate' => '23',
                    'vat_total' => '27.60',
                    'gross_total' => '147.60',
                ],
            ],
        ])
            ->assertRedirect(route('invoices.edit', $invoice))
            ->assertSessionHas('status');

        $invoice->refresh();
        $line->refresh();

        $this->assertSame('2026-06-02', $invoice->issue_date->toDateString());
        $this->assertSame('Poprawiony klient', $invoice->buyer_data['name']);
        $this->assertSame('Łódź', $invoice->buyer_data['city']);
        $this->assertSame('120.00', (string) $invoice->net_total);
        $this->assertSame('147.60', (string) $invoice->gross_total);
        $this->assertSame('Poprawiony produkt fakturowany', $line->name);
        $this->assertSame('2.0000', (string) $line->quantity);
        $this->assertSame('147.60', (string) $line->gross_total);
        $this->assertSame('skip', data_get($invoice->metadata, 'ksef.send_policy'));
        $this->assertNotEmpty(data_get($invoice->metadata, 'manual_line_edit_at'));
        $this->assertSame('stale', data_get($invoice->metadata, 'woocommerce_upload.status'));
        $this->assertTrue(data_get($invoice->metadata, 'woocommerce_upload.requires_resend'));
        $this->assertSame(2, InvoiceFile::query()->where('invoice_id', $invoice->id)->count());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'invoice.data_updated',
            'auditable_id' => $invoice->id,
        ]);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Do ponownej wysyłki')
            ->assertSee('Edytuj dane');
    }

    public function test_invoice_index_summarizes_validation_states_and_shows_visible_messages(): void
    {
        $this->createInvoice('FV/2026/000010');

        $blockingInvoice = $this->createInvoice('FV/2026/000011');
        $blockingInvoice->update([
            'seller_data' => [
                'name' => '',
                'tax_id' => '',
                'address_1' => '',
                'country' => '',
            ],
        ]);

        $warningInvoice = $this->createInvoice('FV/2026/000012');
        $warningInvoice->update([
            'buyer_data' => [
                'name' => 'Klient testowy',
                'tax_id' => '123',
                'address_1' => 'Kupująca 2',
                'postcode' => '00-002',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
        ]);

        $this->get(route('invoices.index'))
            ->assertOk()
            ->assertSee('Gotowe do wysyłki')
            ->assertSee('Do poprawy')
            ->assertSee('Z ostrzeżeniami')
            ->assertSee('Komunikaty walidacji')
            ->assertSee('Komunikaty (4)')
            ->assertSee('Komunikaty (1)')
            ->assertSee('Brakuje NIP sprzedawcy.')
            ->assertSee('NIP nabywcy wygląda nietypowo. Sprawdź przed wysyłką do KSeF.');
    }

    public function test_polish_nip_checksum_is_validated_for_invoice_parties(): void
    {
        $invoice = $this->createInvoice();
        $invoice->update([
            'seller_data' => [
                'name' => 'Sempre Love sp. z o.o.',
                'tax_id' => '1234567890',
                'address_1' => 'Testowa 1',
                'country' => 'PL',
            ],
            'buyer_data' => [
                'name' => 'Klient testowy',
                'tax_id' => '1234567890',
                'address_1' => 'Kupująca 2',
                'postcode' => '00-002',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
        ]);

        $result = app(InvoiceValidationService::class)->validate($invoice);

        $this->assertTrue($result['is_blocking']);
        $this->assertContains('NIP sprzedawcy ma niepoprawny format.', $result['errors']);
        $this->assertContains('NIP nabywcy wygląda nietypowo. Sprawdź przed wysyłką do KSeF.', $result['warnings']);
    }

    public function test_ksef_accepted_invoice_data_cannot_be_edited(): void
    {
        $invoice = $this->createInvoice();
        $invoice->update(['ksef_number' => 'KSEF-ACCEPTED-1']);

        $this->get(route('invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('nie może być edytowana');

        $this->put(route('invoices.data.update', $invoice), [
            'issue_date' => '2026-06-02',
            'sale_date' => '2026-06-01',
            'payment_due_date' => '2026-06-16',
            'currency' => 'PLN',
            'seller' => [
                'name' => 'Inna spółka',
                'tax_id' => '5261040828',
                'address_1' => 'Testowa 1',
                'country' => 'PL',
            ],
            'buyer' => [
                'name' => 'Klient testowy',
                'address_1' => 'Kupująca 2',
                'country' => 'PL',
            ],
        ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame('Sempre Love sp. z o.o.', $invoice->refresh()->seller_data['name']);
    }

    private function createInvoice(string $number = 'FV/2026/000010'): Invoice
    {
        $invoice = Invoice::query()->create([
            'number' => $number,
            'type' => 'vat',
            'status' => 'issued',
            'issue_date' => '2026-06-01',
            'sale_date' => '2026-06-01',
            'payment_due_date' => '2026-06-08',
            'currency' => 'PLN',
            'seller_data' => [
                'name' => 'Sempre Love sp. z o.o.',
                'tax_id' => '5261040828',
                'address_1' => 'Testowa 1',
                'country' => 'PL',
            ],
            'buyer_data' => [
                'name' => 'Klient testowy',
                'tax_id' => '1111111111',
                'address_1' => 'Kupująca 2',
                'postcode' => '00-002',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'net_total' => 100,
            'vat_total' => 23,
            'gross_total' => 123,
            'issued_at' => now(),
        ]);

        $invoice->lines()->create([
            'name' => 'Produkt fakturowany',
            'sku' => 'SKU-FV',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 100,
            'net_total' => 100,
            'vat_rate' => 23,
            'vat_total' => 23,
            'gross_total' => 123,
        ]);

        return $invoice;
    }
}
