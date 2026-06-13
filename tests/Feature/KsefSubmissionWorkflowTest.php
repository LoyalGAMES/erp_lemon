<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\KsefSubmission;
use App\Services\Ksef\KsefSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KsefSubmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_can_prepare_fa3_xml_and_stop_without_ksef_credentials(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        $invoice = $this->createInvoice();

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame($invoice->id, $submission->invoice_id);
        $this->assertSame('missing_configuration', $submission->status);
        $this->assertSame('test', $submission->environment);
        $this->assertSame('2.6.0', $submission->api_version);
        $this->assertStringContainsString('Brak tokena dostępu KSeF', (string) $submission->last_error);
        $this->assertStringContainsString('<KodFormularza kodSystemowy="FA (3)" wersjaSchemy="1-0E">FA</KodFormularza>', (string) $submission->xml_payload);
        $this->assertStringContainsString('<P_2>FV/2026/000001</P_2>', (string) $submission->xml_payload);
        $this->assertStringContainsString('<P_7>Produkt KSeF</P_7>', (string) $submission->xml_payload);

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Brak konfiguracji')
            ->assertSee('FV/2026/000001');

        $this->get(route('ksef.submissions.xml', $submission))
            ->assertOk()
            ->assertSee('<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">', false);
    }

    public function test_configured_ksef_gateway_accepts_submission_and_updates_invoice_number(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => 'test-token',
            'services.ksef.gateway_url' => 'https://ksef-gateway.test/submit',
            'services.ksef.environment' => 'test',
        ]);

        Http::fake([
            'https://ksef-gateway.test/submit' => Http::response([
                'referenceNumber' => '20260601-SEMPRE-REF',
                'ksefNumber' => '20260601-SEMPRE-KSEF-0001',
            ]),
        ]);

        $invoice = $this->createInvoice();

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame('accepted', $submission->status);
        $this->assertSame('20260601-SEMPRE-REF', $submission->reference_number);
        $this->assertSame('20260601-SEMPRE-KSEF-0001', $submission->ksef_number);
        $this->assertSame('20260601-SEMPRE-KSEF-0001', $invoice->refresh()->ksef_number);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ksef-gateway.test/submit'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && str_contains((string) $request['invoice_xml'], '<P_2>FV/2026/000001</P_2>')
            && $request['invoice_size'] > 0);
    }

    public function test_prepare_reuses_active_ksef_submission_for_invoice(): void
    {
        config([
            'services.ksef.access_token' => 'test-token',
            'services.ksef.gateway_url' => 'https://ksef-gateway.test/submit',
            'services.ksef.environment' => 'test',
        ]);

        $invoice = $this->createInvoice();
        $service = app(KsefSubmissionService::class);

        $first = $service->prepare($invoice);
        $second = $service->prepare($invoice);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, KsefSubmission::query()->count());
        $this->assertSame('queued', $second->status);
    }

    public function test_failed_ksef_submission_can_be_retried_from_history(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => 'test-token',
            'services.ksef.gateway_url' => 'https://ksef-gateway.test/submit',
            'services.ksef.environment' => 'test',
        ]);

        Http::fake([
            'https://ksef-gateway.test/submit' => Http::sequence()
                ->push(['message' => 'temporary unavailable'], 503)
                ->push([
                    'referenceNumber' => 'RETRY-REF',
                    'ksefNumber' => 'RETRY-KSEF-001',
                ]),
        ]);

        $invoice = $this->createInvoice();

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame('requires_configuration', $submission->status);
        $this->assertStringContainsString('HTTP 503', (string) $submission->last_error);

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Ponów')
            ->assertSee('Bramka KSeF zwróciła HTTP 503');

        $this->post(route('ksef.submissions.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('status', 'Zgłoszenie KSeF zostało ponownie dodane do kolejki.');

        $submission->refresh();

        $this->assertSame('accepted', $submission->status);
        $this->assertSame('RETRY-REF', $submission->reference_number);
        $this->assertSame('RETRY-KSEF-001', $submission->ksef_number);
        $this->assertSame('RETRY-KSEF-001', $invoice->refresh()->ksef_number);
        $this->assertNull($submission->last_error);
        $this->assertSame(1, (int) data_get($submission->request_metadata, 'retry_count'));
        $this->assertNotEmpty(data_get($submission->request_metadata, 'last_retry_at'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ksef.submission_retried',
            'auditable_type' => KsefSubmission::class,
            'auditable_id' => $submission->id,
        ]);

        $audit = AuditLog::query()->where('action', 'ksef.submission_retried')->firstOrFail();
        $this->assertSame('requires_configuration', $audit->before['status']);
        $this->assertSame('queued', $audit->after['status']);
        $this->assertSame(1, $audit->after['retry_count']);

        $this->post(route('ksef.submissions.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('error', 'Tego zgłoszenia KSeF nie można ponowić.');
    }

    public function test_operator_can_store_ksef_configuration_and_use_it_for_submission(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => '',
            'services.ksef.api_version' => '',
        ]);

        $this->put(route('integrations.ksef.configuration.update'), [
            'environment' => 'demo',
            'api_version' => '2.6.0',
            'base_url' => 'https://api-demo.ksef.test/v2',
            'gateway_url' => 'https://ksef-gateway.test/submit',
            'access_token' => 'stored-secret-token',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Konfiguracja KSeF została zapisana.');

        $setting = AppSetting::query()->where('key', 'ksef_configuration')->firstOrFail();
        $this->assertSame('demo', $setting->value['environment']);
        $this->assertNotSame('stored-secret-token', $setting->value['access_token_encrypted']);

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('Integracja KSeF')
            ->assertSee('Gotowe do wysyłki')
            ->assertSee('Przejdź do faktur KSeF')
            ->assertDontSee('stored-secret-token');

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Konfiguracja w Integracjach')
            ->assertDontSee('Zapisz konfigurację KSeF')
            ->assertDontSee('stored-secret-token');

        Http::fake([
            'https://ksef-gateway.test/submit' => Http::response([
                'referenceNumber' => 'DEMO-REF',
                'ksefNumber' => 'DEMO-KSEF-001',
            ]),
        ]);

        $invoice = $this->createInvoice();

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame('accepted', $submission->status);
        $this->assertSame('demo', $submission->environment);
        $this->assertSame('DEMO-KSEF-001', $invoice->refresh()->ksef_number);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ksef-gateway.test/submit'
            && $request->hasHeader('Authorization', 'Bearer stored-secret-token'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ksef.configuration_updated',
        ]);
    }

    public function test_operator_can_clear_stored_ksef_token(): void
    {
        config([
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
        ]);

        $this->put(route('integrations.ksef.configuration.update'), [
            'environment' => 'test',
            'api_version' => '2.6.0',
            'access_token' => 'temporary-token',
        ])->assertRedirect();

        $this->put(route('integrations.ksef.configuration.update'), [
            'environment' => 'test',
            'api_version' => '2.6.0',
            'clear_access_token' => '1',
        ])->assertRedirect();

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Brakuje tokena KSeF')
            ->assertDontSee('temporary-token');
    }

    public function test_invalid_invoice_is_blocked_before_ksef_submission(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => 'test-token',
            'services.ksef.gateway_url' => 'https://ksef-gateway.test/submit',
        ]);

        $invoice = $this->createInvoice();
        $invoice->update([
            'seller_data' => [
                'name' => 'Sempre Love sp. z o.o.',
                'tax_id' => '',
                'address_1' => '',
                'country' => 'PL',
            ],
        ]);

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, KsefSubmission::query()->count());

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Do poprawy')
            ->assertSee('Popraw fakturę');
    }

    public function test_ksef_xml_splits_summary_by_vat_rate_buckets(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        $invoice = $this->createInvoice();
        $invoice->lines()->create([
            'name' => 'Produkt 8%',
            'sku' => 'SKU-8',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 50,
            'net_total' => 50,
            'vat_rate' => 8,
            'vat_total' => 4,
            'gross_total' => 54,
        ]);
        $invoice->lines()->create([
            'name' => 'Produkt 5%',
            'sku' => 'SKU-5',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 40,
            'net_total' => 40,
            'vat_rate' => 5,
            'vat_total' => 2,
            'gross_total' => 42,
        ]);
        $invoice->lines()->create([
            'name' => 'Produkt 0%',
            'sku' => 'SKU-0',
            'unit' => 'szt',
            'quantity' => 1,
            'unit_net_price' => 25,
            'net_total' => 25,
            'vat_rate' => 0,
            'vat_total' => 0,
            'gross_total' => 25,
        ]);
        $invoice->update([
            'net_total' => 215,
            'vat_total' => 29,
            'gross_total' => 244,
        ]);

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $xml = (string) KsefSubmission::query()->firstOrFail()->xml_payload;

        $this->assertStringContainsString('<P_13_1>100.00</P_13_1>', $xml);
        $this->assertStringContainsString('<P_14_1>23.00</P_14_1>', $xml);
        $this->assertStringContainsString('<P_13_2>50.00</P_13_2>', $xml);
        $this->assertStringContainsString('<P_14_2>4.00</P_14_2>', $xml);
        $this->assertStringContainsString('<P_13_3>40.00</P_13_3>', $xml);
        $this->assertStringContainsString('<P_14_3>2.00</P_14_3>', $xml);
        $this->assertStringContainsString('<P_13_6_1>25.00</P_13_6_1>', $xml);
        $this->assertStringContainsString('<P_15>244.00</P_15>', $xml);
    }

    public function test_ksef_submission_blocks_unmapped_vat_rate(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => 'test-token',
            'services.ksef.gateway_url' => 'https://ksef-gateway.test/submit',
        ]);

        $invoice = $this->createInvoice();
        $invoice->lines()->firstOrFail()->update([
            'vat_rate' => 3,
            'vat_total' => 3,
            'gross_total' => 103,
        ]);
        $invoice->update([
            'vat_total' => 3,
            'gross_total' => 103,
        ]);

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, KsefSubmission::query()->count());

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Do poprawy')
            ->assertSee('brak mapowania dla stawek VAT');
    }

    private function createInvoice(): Invoice
    {
        $invoice = Invoice::query()->create([
            'number' => 'FV/2026/000001',
            'type' => 'vat',
            'status' => 'issued',
            'issue_date' => '2026-06-01',
            'sale_date' => '2026-06-01',
            'payment_due_date' => '2026-06-01',
            'currency' => 'PLN',
            'seller_data' => [
                'name' => 'Sempre Love sp. z o.o.',
                'tax_id' => '5261040828',
                'address_1' => 'Testowa 1',
                'postcode' => '00-001',
                'city' => 'Warszawa',
                'country' => 'PL',
            ],
            'buyer_data' => [
                'name' => 'Jan Kowalski',
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
            'name' => 'Produkt KSeF',
            'sku' => 'SKU-KSEF',
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
