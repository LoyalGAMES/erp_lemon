<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\InvoiceEppExportMail;
use App\Models\AppSetting;
use App\Models\Invoice;
use App\Models\InvoiceFile;
use App\Models\InvoiceTemplate;
use App\Services\Invoices\InvoiceEppDeliveryService;
use App\Services\Invoices\InvoiceEppDeliverySettingsService;
use App\Services\Invoices\InvoiceNumberService;
use App\Services\Invoices\InvoiceSettingsService;
use App\Services\Invoices\InvoiceTemplateService;
use App\Services\Invoices\InvoiceValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
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
            'b2c_sales_prefix' => 'FV/DET',
            'b2b_sales_prefix' => 'FV/FIRMA',
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
            ->assertSee('FV/DET')
            ->assertSee('FV/FIRMA')
            ->assertSee('FK/ERP')
            ->assertSee('PRO/ERP')
            ->assertSee('FV/OSS')
            ->assertSee('FVK/OSS')
            ->assertSee('{PREFIX}/{YYYY}/{SEQ}')
            ->assertSee('{PREFIX}/{SEQ}/{MM}/{YYYY}')
            ->assertSee('14')
            ->assertSee('Domyślnie nie wysyłaj do KSeF');

        $invoice = $this->createInvoice();
        $invoice->update(['number' => 'FV/DET/'.now()->format('Y').'/0009']);

        $numbers = app(InvoiceNumberService::class);

        $this->assertSame('FV/DET/'.now()->format('Y').'/0010', $numbers->next());
        $this->assertSame('FV/FIRMA/'.now()->format('Y').'/0001', $numbers->next('B2B'));
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

    public function test_operator_can_export_monthly_invoices_to_epp_file(): void
    {
        $invoice = $this->createInvoice('FV/FIRMA/2026/000010');
        $invoice->update([
            'issue_date' => '2026-06-15',
            'sale_date' => '2026-06-14',
            'payment_due_date' => '2026-06-22',
            'payment_method' => 'Przelew',
            'metadata' => ['external_order_number' => 'WC-10042'],
        ]);

        $outsideMonth = $this->createInvoice('FV/FIRMA/2026/000011');
        $outsideMonth->update(['issue_date' => '2026-07-01']);

        $proforma = $this->createInvoice('PRO/2026/000001');
        $proforma->update(['type' => 'proforma', 'issue_date' => '2026-06-20']);

        $response = $this->get(route('invoices.epp.export', ['month' => '2026-06']));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream');

        $content = iconv('WINDOWS-1250', 'UTF-8//IGNORE', (string) $response->getContent());

        $this->assertIsString($content);

        $this->assertStringContainsString('[INFO]', $content);
        $infoLines = preg_split('/\r\n|\r|\n/', $content);
        $this->assertIsArray($infoLines);
        $this->assertCount(24, str_getcsv($infoLines[1], ',', '"', ''));
        $this->assertStringStartsWith('"1.11",0,1250,"Sempre ERP"', $infoLines[1]);
        $this->assertStringContainsString('"Sempre Love sp. z o.o."', $infoLines[1]);
        $this->assertStringContainsString('20260601000000', $infoLines[1]);
        $this->assertStringContainsString('20260630000000', $infoLines[1]);
        $document = str_getcsv($infoLines[3], ',', '"', '');
        $this->assertCount(62, $document);
        $this->assertSame('FS', $document[0]);
        $this->assertSame('1', $document[1]);
        $this->assertSame('FS FV/FIRMA/2026/000010', $document[6]);
        $this->assertSame('WC-10042', $document[9]);
        $this->assertSame('NIP1111111111', $document[11]);
        $this->assertSame('20260615000000', $document[21]);
        $this->assertSame('20260614000000', $document[22]);
        $this->assertSame('20260622000000', $document[34]);
        $this->assertSame('PLN', $document[46]);
        $this->assertSame('1.0000', $document[47]);
        $this->assertSame('0', $document[54]);
        $this->assertSame('Polska', $document[59]);
        $this->assertSame('PL', $document[60]);
        $this->assertSame('1', $document[61]);
        $this->assertCount(18, str_getcsv($infoLines[5], ',', '"', ''));
        $contractorsHeader = array_search('"KONTRAHENCI"', $infoLines, true);
        $this->assertIsInt($contractorsHeader);
        $contractor = str_getcsv($infoLines[$contractorsHeader + 2], ',', '"', '');
        $this->assertCount(31, $contractor);
        $this->assertSame('Klient testowy', $contractor[2]);
        $this->assertSame('Warszawa', $contractor[4]);
        $this->assertSame('Polska', $contractor[27]);
        $this->assertSame('PL', $contractor[28]);
        $this->assertSame('1', $contractor[29]);
        $this->assertSame('PL', $contractor[30]);
        $this->assertStringContainsString('[NAGLOWEK]', $content);
        $this->assertStringContainsString('[ZAWARTOSC]', $content);
        $this->assertStringContainsString('"FS FV/FIRMA/2026/000010"', $content);
        $this->assertStringContainsString('"KONTRAHENCI"', $content);
        $this->assertStringContainsString('"DATYZAKONCZENIA"', $content);
        $this->assertStringContainsString('"DOKUMENTYZNACZNIKIJPKVAT"', $content);
        $this->assertStringContainsString('"Klient testowy"', $content);
        $this->assertStringContainsString('"SPRZEDAZ_FIRMY"', $content);
        $this->assertStringNotContainsString('FV/FIRMA/2026/000011', $content);
        $this->assertStringNotContainsString('PRO/2026/000001', $content);
    }

    public function test_epp_export_contains_oss_country_and_foreign_vat_rate(): void
    {
        $invoice = $this->createInvoice('FV/OSS/1/06/2026');
        $invoice->update([
            'buyer_data' => [
                'name' => 'Max Mustermann',
                'address_1' => 'Hauptstrasse 1',
                'postcode' => '10115',
                'city' => 'Berlin',
                'country' => 'DE',
            ],
            'metadata' => [
                'oss' => [
                    'buyer_country' => 'DE',
                    'vat_rate' => 19.0,
                ],
            ],
            'net_total' => 100,
            'vat_total' => 19,
            'gross_total' => 119,
            'currency' => 'EUR',
        ]);
        $invoice->update(['metadata' => array_merge($invoice->metadata ?? [], [
            'currency_conversion' => ['currency' => 'EUR', 'rate' => 4.25],
        ])]);
        $invoice->lines()->firstOrFail()->update([
            'vat_rate' => 19,
            'vat_total' => 19,
            'gross_total' => 119,
        ]);

        $response = $this->get(route('invoices.epp.export', ['month' => '2026-06']));
        $content = iconv('WINDOWS-1250', 'UTF-8//IGNORE', (string) $response->getContent());

        $this->assertIsString($content);
        $this->assertStringContainsString('"SPRZEDAZ_OSS_DE"', $content);
        $this->assertStringContainsString('"INFORMACJEWSTO"', $content);
        $this->assertStringContainsString('"SPECYFIKACJATOWAROWAWSTO"', $content);
        $this->assertStringContainsString('"STAWKIVATZAGRANICZNE"', $content);
        $this->assertStringContainsString('"Niemcy"', $content);
        $this->assertStringContainsString('"DE"', $content);
        $this->assertStringContainsString('"19%"', $content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $this->assertIsArray($lines);
        $document = str_getcsv($lines[3], ',', '"', '');
        $this->assertCount(62, $document);
        $this->assertSame('EUR', $document[46]);
        $this->assertSame('4.2500', $document[47]);
        $this->assertSame('23', $document[54]);
        $this->assertSame('Niemcy', $document[59]);
        $this->assertSame('DE', $document[60]);
        $this->assertSame('1', $document[61]);

        $jpkHeader = array_search('"DOKUMENTYZNACZNIKIJPKVAT"', $lines, true);
        $this->assertIsInt($jpkHeader);
        $jpk = str_getcsv($lines[$jpkHeader + 2], ',', '"', '');
        $this->assertCount(31, $jpk);
        $this->assertSame('1', $jpk[29]);

        $specificationHeader = array_search('"SPECYFIKACJATOWAROWAWSTO"', $lines, true);
        $this->assertIsInt($specificationHeader);
        $this->assertSame(
            ['FS FV/OSS/1/06/2026', 'Produkt fakturowany', '1.0000', 'szt'],
            str_getcsv($lines[$specificationHeader + 2], ',', '"', ''),
        );
    }

    public function test_epp_export_maps_correction_reference_and_reason(): void
    {
        $original = $this->createInvoice('FV/FIRMA/2026/000020');
        $correction = $this->createInvoice('FK/FIRMA/2026/000001');
        $correction->update([
            'type' => 'correction',
            'issue_date' => '2026-06-20',
            'metadata' => [
                'corrected_invoice_number' => $original->number,
                'corrected_invoice_issue_date' => '2026-06-01',
                'correction_reason' => 'Zwrot towaru',
            ],
        ]);

        $response = $this->get(route('invoices.epp.export', ['month' => '2026-06']));
        $content = iconv('WINDOWS-1250', 'UTF-8//IGNORE', (string) $response->getContent());
        $this->assertIsString($content);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $this->assertIsArray($lines);

        $document = collect($lines)
            ->map(fn (string $line): array => str_getcsv($line, ',', '"', ''))
            ->first(fn (array $row): bool => ($row[6] ?? null) === 'KFS FK/FIRMA/2026/000001');
        $this->assertIsArray($document);
        $this->assertCount(62, $document);
        $this->assertSame('FS FV/FIRMA/2026/000020', $document[7]);
        $this->assertSame('20260601000000', $document[8]);

        $reasonHeader = array_search('"PRZYCZYNYKOREKT"', $lines, true);
        $this->assertIsInt($reasonHeader);
        $this->assertSame(
            ['KFS FK/FIRMA/2026/000001', '1', 'Zwrot towaru'],
            str_getcsv($lines[$reasonHeader + 2], ',', '"', ''),
        );
        $this->assertStringContainsString('"DATYUJECIAKOREKT"', $content);
    }

    public function test_epp_export_rejects_foreign_currency_invoice_without_exchange_rate(): void
    {
        $invoice = $this->createInvoice('FV/EUR/2026/000001');
        $invoice->update(['currency' => 'EUR']);

        $this->get(route('invoices.epp.export', ['month' => '2026-06']))
            ->assertRedirect()
            ->assertSessionHasErrors('month');
    }

    public function test_operator_can_configure_daily_and_interval_epp_delivery(): void
    {
        $this->travelTo('2026-06-15 18:00:00');

        $this->put(route('invoices.epp-delivery-settings.update'), [
            'enabled' => '1',
            'recipient_emails' => "ksiegowosc@example.test\nbiuro@example.test; KSIEGOWOSC@example.test",
            'frequency' => 'interval',
            'interval_days' => 7,
            'send_time' => '19:00',
        ])->assertRedirect()->assertSessionHas('status');

        $settings = app(InvoiceEppDeliverySettingsService::class)->data();
        $this->assertTrue($settings['enabled']);
        $this->assertSame('ksiegowosc@example.test', $settings['recipient_email']);
        $this->assertSame(['ksiegowosc@example.test', 'biuro@example.test'], $settings['recipient_emails']);
        $this->assertSame('interval', $settings['frequency']);
        $this->assertSame(7, $settings['interval_days']);
        $this->assertSame('19:00', $settings['send_time']);
        $this->assertSame('2026-06-15T19:00:00+02:00', $settings['next_send_at']);

        $this->put(route('invoices.epp-delivery-settings.update'), [
            'enabled' => '1',
            'recipient_emails' => "poprawny@example.test\nnie-email",
            'frequency' => 'daily',
            'send_time' => '20:00',
        ])->assertSessionHasErrors('recipient_emails_list.1');
    }

    public function test_due_epp_schedule_sends_one_attachment_for_unsent_period(): void
    {
        Mail::fake();
        $this->travelTo('2026-06-15 18:00:00');

        $invoice = $this->createInvoice('FV/FIRMA/2026/000030');
        $invoice->update(['issue_date' => '2026-06-15']);
        $oldInvoice = $this->createInvoice('FV/FIRMA/2026/000029');
        $oldInvoice->update(['issue_date' => '2026-06-07']);

        app(InvoiceEppDeliverySettingsService::class)->update([
            'enabled' => true,
            'recipient_emails' => ['ksiegowosc@example.test', 'biuro@example.test'],
            'frequency' => 'interval',
            'interval_days' => 7,
            'send_time' => '19:00',
        ]);

        AppSetting::query()->create([
            'key' => 'mail_settings',
            'value' => [
                'delivery_enabled' => true,
                'delivery_method' => 'smtp',
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
                'from_address' => 'erp@example.test',
                'from_name' => 'Sempre ERP',
            ],
        ]);

        $delivery = app(InvoiceEppDeliveryService::class);
        $this->assertSame('not_due', $delivery->sendIfDue(now()));

        $this->travelTo('2026-06-15 19:00:00');
        $this->assertSame('sent', $delivery->sendIfDue(now()));
        $this->assertSame('not_due', $delivery->sendIfDue(now()));

        $sentMail = null;
        Mail::assertSent(InvoiceEppExportMail::class, function (InvoiceEppExportMail $mail) use (&$sentMail): bool {
            $sentMail = $mail;

            return true;
        });
        Mail::assertSentCount(1);
        $this->assertInstanceOf(InvoiceEppExportMail::class, $sentMail);
        $sentMail->build();
        $attachment = $sentMail->rawAttachments[0] ?? [];
        $decoded = iconv('WINDOWS-1250', 'UTF-8//IGNORE', (string) ($attachment['data'] ?? ''));
        $this->assertTrue($sentMail->hasTo('ksiegowosc@example.test'));
        $this->assertTrue($sentMail->hasTo('biuro@example.test'));
        $this->assertSame('faktury-epp-2026-06-09-2026-06-15.epp', $attachment['name'] ?? null);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('FV/FIRMA/2026/000030', $decoded);
        $this->assertStringNotContainsString('FV/FIRMA/2026/000029', $decoded);

        $settings = app(InvoiceEppDeliverySettingsService::class)->data();
        $this->assertSame('2026-06-15', $settings['last_period_end']);
        $this->assertSame('2026-06-22T19:00:00+02:00', $settings['next_send_at']);
        $this->assertNull($settings['last_error']);
    }

    public function test_monthly_epp_schedules_use_first_or_last_day_of_month(): void
    {
        $this->travelTo('2026-06-15 12:00:00');
        $settingsService = app(InvoiceEppDeliverySettingsService::class);

        $firstDay = $settingsService->update([
            'enabled' => true,
            'recipient_emails' => ['ksiegowosc@example.test'],
            'frequency' => 'monthly_first',
            'send_time' => '19:00',
        ]);
        $this->assertSame('2026-07-01T19:00:00+02:00', $firstDay['next_send_at']);

        $lastDay = $settingsService->update([
            'enabled' => true,
            'recipient_emails' => ['ksiegowosc@example.test'],
            'frequency' => 'monthly_last',
            'send_time' => '19:00',
        ]);
        $this->assertSame('2026-06-30T19:00:00+02:00', $lastDay['next_send_at']);

        $settingsService->markSent(Carbon::parse('2027-01-31 19:00:00'), Carbon::parse('2027-01-31'));
        $this->assertSame('2027-02-28T19:00:00+01:00', $settingsService->data()['next_send_at']);
    }

    public function test_first_day_monthly_delivery_attaches_previous_full_month(): void
    {
        Mail::fake();
        $this->travelTo('2026-06-30 18:00:00');

        $juneInvoice = $this->createInvoice('FV/FIRMA/2026/000040');
        $juneInvoice->update(['issue_date' => '2026-06-30']);
        $julyInvoice = $this->createInvoice('FV/FIRMA/2026/000041');
        $julyInvoice->update(['issue_date' => '2026-07-01']);

        app(InvoiceEppDeliverySettingsService::class)->update([
            'enabled' => true,
            'recipient_emails' => ['ksiegowosc@example.test'],
            'frequency' => 'monthly_first',
            'send_time' => '19:00',
        ]);
        AppSetting::query()->create([
            'key' => 'mail_settings',
            'value' => [
                'delivery_enabled' => true,
                'delivery_method' => 'smtp',
                'host' => 'smtp.example.test',
                'port' => 587,
                'encryption' => 'tls',
                'from_address' => 'erp@example.test',
                'from_name' => 'Sempre ERP',
            ],
        ]);

        $this->travelTo('2026-07-01 19:00:00');
        $this->assertSame('sent', app(InvoiceEppDeliveryService::class)->sendIfDue(now()));

        $sentMail = null;
        Mail::assertSent(InvoiceEppExportMail::class, function (InvoiceEppExportMail $mail) use (&$sentMail): bool {
            $sentMail = $mail;

            return true;
        });
        $this->assertInstanceOf(InvoiceEppExportMail::class, $sentMail);
        $sentMail->build();
        $attachment = $sentMail->rawAttachments[0] ?? [];
        $decoded = iconv('WINDOWS-1250', 'UTF-8//IGNORE', (string) ($attachment['data'] ?? ''));
        $this->assertSame('faktury-epp-2026-06-01-2026-06-30.epp', $attachment['name'] ?? null);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('FV/FIRMA/2026/000040', $decoded);
        $this->assertStringNotContainsString('FV/FIRMA/2026/000041', $decoded);
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
