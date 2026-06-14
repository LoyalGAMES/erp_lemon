<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\KsefSubmission;
use App\Services\Invoices\InvoiceTemplateService;
use App\Services\Ksef\KsefSettingsService;
use App\Services\Ksef\KsefSubmissionService;
use App\Services\Ksef\KsefXmlBuilder;
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
        $this->assertStringContainsString('<Podmiot2>', (string) $submission->xml_payload);
        $this->assertStringContainsString('<JST>2</JST>', (string) $submission->xml_payload);
        $this->assertStringContainsString('<GV>2</GV>', (string) $submission->xml_payload);
        $this->assertSame(KsefSettingsService::TEST_PUBLIC_KEY_ID, $submission->request_metadata['public_key_id']);
        $this->assertSame(KsefSettingsService::TEST_PUBLIC_KEY_SHA256, $submission->request_metadata['public_key_sha256']);

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Brak konfiguracji')
            ->assertSee('FV/2026/000001');

        $this->get(route('ksef.submissions.xml', $submission))
            ->assertOk()
            ->assertSee('<Faktura xmlns="http://crd.gov.pl/wzor/2025/06/25/13775/">', false);
    }

    public function test_ksef_index_shows_full_native_api_error(): void
    {
        $baseUrl = 'https://api-test.ksef.local/v2';
        $longError = 'Nieprawidłowe wyzwanie autoryzacyjne. Pełna treść błędu powinna być widoczna w panelu KSeF bez ucinania końcówki komunikatu.';

        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => 'test-token',
            'services.ksef.gateway_url' => '',
            'services.ksef.base_url' => $baseUrl,
            'services.ksef.environment' => 'test',
            'services.ksef.auth_status_delay_ms' => 0,
        ]);

        $invoice = $this->createInvoice();

        Http::fake([
            $baseUrl.'/security/public-key-certificates' => Http::response($this->publicKeyCertificates()),
            $baseUrl.'/auth/challenge' => Http::response([
                'challenge' => 'CHALLENGE-1',
                'timestampMs' => 1752236636015,
            ]),
            $baseUrl.'/auth/ksef-token' => Http::response([
                'title' => 'Bad Request',
                'detail' => $longError,
            ], 400),
        ]);

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame('failed', $submission->status);
        $this->assertStringContainsString($longError, (string) $submission->last_error);

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee($longError)
            ->assertDontSee('ucinania końcówki...');
    }

    public function test_ksef_cleanup_url_replaces_legacy_gateway_error(): void
    {
        config([
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        $invoice = $this->createInvoice();
        $legacy = $invoice->ksefSubmissions()->create([
            'environment' => 'test',
            'api_version' => '2.6.0',
            'status' => 'requires_configuration',
            'last_error' => KsefSubmissionService::LEGACY_GATEWAY_ERROR,
        ]);
        $other = $invoice->ksefSubmissions()->create([
            'environment' => 'test',
            'api_version' => '2.6.0',
            'status' => 'failed',
            'last_error' => 'Inny błąd KSeF',
        ]);

        $this->get(route('ksef.index', ['cleanup_legacy_errors' => 1]))
            ->assertRedirect(route('ksef.index'))
            ->assertSessionHas('status', 'Wyczyszczono stare komunikaty KSeF: 1.');

        $legacy->refresh();
        $other->refresh();

        $this->assertSame('failed', $legacy->status);
        $this->assertSame(KsefSubmissionService::LEGACY_GATEWAY_CLEANUP_ERROR, $legacy->last_error);
        $this->assertSame('Inny błąd KSeF', $other->last_error);
    }

    public function test_ksef_diagnostics_url_reports_native_client_and_legacy_errors(): void
    {
        config([
            'queue.default' => 'database',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        $invoice = $this->createInvoice();
        $invoice->ksefSubmissions()->create([
            'environment' => 'test',
            'api_version' => '2.6.0',
            'status' => 'requires_configuration',
            'last_error' => KsefSubmissionService::LEGACY_GATEWAY_ERROR,
            'request_metadata' => [
                'delivery_mode' => 'native',
            ],
        ]);

        $this->get(route('ksef.index', ['diagnostics' => 1]))
            ->assertOk()
            ->assertJsonPath('code_marker', 'native-ksef-2.0-online-session')
            ->assertJsonPath('native_client_active', true)
            ->assertJsonPath('manual_ksef_submit_mode', 'sync-web')
            ->assertJsonPath('queue_connection', 'database')
            ->assertJsonPath('legacy_gateway_error_count', 1)
            ->assertJsonPath('latest_submissions.0.last_error_is_legacy_gateway_error', true)
            ->assertJsonPath('latest_submissions.0.request_delivery_mode', 'native');
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
            ->assertSessionHas('status', 'Faktura FV/2026/000001 została przyjęta przez KSeF.');

        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame('accepted', $submission->status);
        $this->assertSame('20260601-SEMPRE-REF', $submission->reference_number);
        $this->assertSame('20260601-SEMPRE-KSEF-0001', $submission->ksef_number);
        $invoice->refresh();
        $this->assertSame('20260601-SEMPRE-KSEF-0001', $invoice->ksef_number);
        $this->assertStringStartsWith('https://qr-test.ksef.mf.gov.pl/invoice/5261040828/01-06-2026/', data_get($invoice->metadata, 'ksef.qr_url'));
        $this->assertNotEmpty(data_get($invoice->metadata, 'ksef.invoice_hash_sha256_base64url'));
        $this->assertStringContainsString('Sprawdź fakturę w KSeF', app(InvoiceTemplateService::class)->renderHtml($invoice));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://ksef-gateway.test/submit'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && str_contains((string) $request['invoice_xml'], '<P_2>FV/2026/000001</P_2>')
            && $request['public_key_id'] === KsefSettingsService::TEST_PUBLIC_KEY_ID
            && $request['public_key_sha256'] === KsefSettingsService::TEST_PUBLIC_KEY_SHA256
            && $request['invoice_size'] > 0);
    }

    public function test_native_ksef_api_flow_encrypts_xml_and_sends_online_session_invoice(): void
    {
        $baseUrl = 'https://api-test.ksef.local/v2';
        $tokenPublicKeyId = $this->publicKeyId('token');
        $sessionPublicKeyId = $this->publicKeyId('session');

        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => 'native-ksef-token',
            'services.ksef.gateway_url' => '',
            'services.ksef.status_url' => '',
            'services.ksef.base_url' => $baseUrl,
            'services.ksef.environment' => 'test',
            'services.ksef.auth_status_delay_ms' => 0,
        ]);

        Http::fake([
            $baseUrl.'/security/public-key-certificates' => Http::response($this->publicKeyCertificates($tokenPublicKeyId, $sessionPublicKeyId)),
            $baseUrl.'/auth/challenge' => Http::response([
                'challenge' => 'AUTH-CHALLENGE',
                'timestampMs' => 1752236636015,
            ]),
            $baseUrl.'/auth/ksef-token' => Http::response([
                'referenceNumber' => 'AUTH-REF',
                'authenticationToken' => [
                    'token' => 'AUTH-TOKEN',
                    'validUntil' => '2026-06-14T12:00:00+00:00',
                ],
            ], 202),
            $baseUrl.'/auth/AUTH-REF' => Http::response([
                'status' => [
                    'code' => 200,
                    'description' => 'Uwierzytelnianie zakończone sukcesem',
                ],
            ]),
            $baseUrl.'/auth/token/redeem' => Http::response([
                'accessToken' => [
                    'token' => 'ACCESS-TOKEN',
                    'validUntil' => '2026-06-14T12:15:00+00:00',
                ],
                'refreshToken' => [
                    'token' => 'REFRESH-TOKEN',
                    'validUntil' => '2026-06-21T12:15:00+00:00',
                ],
            ]),
            $baseUrl.'/sessions/online' => Http::response([
                'referenceNumber' => 'SESSION-REF',
                'validUntil' => '2026-06-14T23:59:00+00:00',
            ], 202),
            $baseUrl.'/sessions/online/SESSION-REF/invoices' => Http::response([
                'referenceNumber' => 'INVOICE-REF',
            ], 202),
            $baseUrl.'/sessions/online/SESSION-REF/close' => Http::response(null, 204),
        ]);

        $invoice = $this->createInvoice();

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status', 'Faktura FV/2026/000001 została wysłana do weryfikacji KSeF. Odśwież status po zakończeniu przetwarzania.');

        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame('submitted', $submission->status);
        $this->assertSame('INVOICE-REF', $submission->reference_number);
        $this->assertSame('native', $submission->response_metadata['mode']);
        $this->assertSame('SESSION-REF', $submission->response_metadata['sessionReferenceNumber']);
        $this->assertSame($sessionPublicKeyId, $submission->response_metadata['symmetricKeyPublicKeyId']);

        Http::assertSent(fn ($request): bool => $request->url() === $baseUrl.'/auth/ksef-token'
            && $request['challenge'] === 'AUTH-CHALLENGE'
            && $request['contextIdentifier']['type'] === 'Nip'
            && $request['contextIdentifier']['value'] === '5261040828'
            && $request['publicKeyId'] === $tokenPublicKeyId
            && is_string($request['encryptedToken'])
            && ! str_contains($request['encryptedToken'], 'native-ksef-token'));

        Http::assertSent(fn ($request): bool => $request->url() === $baseUrl.'/sessions/online'
            && $request->hasHeader('Authorization', 'Bearer ACCESS-TOKEN')
            && $request['formCode']['systemCode'] === KsefXmlBuilder::FORM_SYSTEM_CODE
            && $request['formCode']['schemaVersion'] === KsefXmlBuilder::SCHEMA_VERSION
            && $request['formCode']['value'] === 'FA'
            && $request['encryption']['publicKeyId'] === $sessionPublicKeyId
            && is_string($request['encryption']['encryptedSymmetricKey'])
            && is_string($request['encryption']['initializationVector']));

        Http::assertSent(fn ($request): bool => $request->url() === $baseUrl.'/sessions/online/SESSION-REF/invoices'
            && $request->hasHeader('Authorization', 'Bearer ACCESS-TOKEN')
            && $request['invoiceHash'] === $submission->request_metadata['xml_sha256_base64']
            && $request['invoiceSize'] === $submission->request_metadata['xml_size']
            && $request['encryptedInvoiceHash'] !== $request['invoiceHash']
            && $request['encryptedInvoiceSize'] > 0
            && $request['offlineMode'] === false
            && is_string($request['encryptedInvoiceContent'])
            && ! str_contains($request['encryptedInvoiceContent'], '<P_2>FV/2026/000001</P_2>'));

        Http::assertSent(fn ($request): bool => $request->url() === $baseUrl.'/sessions/online/SESSION-REF/close'
            && $request->hasHeader('Authorization', 'Bearer ACCESS-TOKEN'));
    }

    public function test_native_ksef_status_refresh_accepts_invoice_and_updates_invoice_number(): void
    {
        $baseUrl = 'https://api-test.ksef.local/v2';
        $tokenPublicKeyId = $this->publicKeyId('token');
        $sessionPublicKeyId = $this->publicKeyId('session');

        config([
            'services.ksef.access_token' => 'native-ksef-token',
            'services.ksef.gateway_url' => '',
            'services.ksef.status_url' => '',
            'services.ksef.base_url' => $baseUrl,
            'services.ksef.environment' => 'test',
            'services.ksef.auth_status_delay_ms' => 0,
        ]);

        Http::fake([
            $baseUrl.'/security/public-key-certificates' => Http::response($this->publicKeyCertificates($tokenPublicKeyId, $sessionPublicKeyId)),
            $baseUrl.'/auth/challenge' => Http::response([
                'challenge' => 'AUTH-CHALLENGE',
                'timestampMs' => 1752236636015,
            ]),
            $baseUrl.'/auth/ksef-token' => Http::response([
                'referenceNumber' => 'AUTH-REF',
                'authenticationToken' => [
                    'token' => 'AUTH-TOKEN',
                    'validUntil' => '2026-06-14T12:00:00+00:00',
                ],
            ], 202),
            $baseUrl.'/auth/AUTH-REF' => Http::response([
                'status' => [
                    'code' => 200,
                    'description' => 'Uwierzytelnianie zakończone sukcesem',
                ],
            ]),
            $baseUrl.'/auth/token/redeem' => Http::response([
                'accessToken' => [
                    'token' => 'ACCESS-TOKEN',
                    'validUntil' => '2026-06-14T12:15:00+00:00',
                ],
                'refreshToken' => [
                    'token' => 'REFRESH-TOKEN',
                    'validUntil' => '2026-06-21T12:15:00+00:00',
                ],
            ]),
            $baseUrl.'/sessions/SESSION-REF/invoices/INVOICE-REF' => Http::response([
                'ordinalNumber' => 1,
                'referenceNumber' => 'INVOICE-REF',
                'ksefNumber' => '5261040828-20260614-000000000001-11',
                'invoiceHash' => 'HASH=',
                'invoicingDate' => '2026-06-14T12:20:00+00:00',
                'status' => [
                    'code' => 200,
                    'description' => 'Sukces',
                ],
            ]),
        ]);

        $invoice = $this->createInvoice();
        $xml = app(KsefXmlBuilder::class)->build($invoice);
        $submission = $invoice->ksefSubmissions()->create([
            'environment' => 'test',
            'api_version' => '2.6.0',
            'status' => 'submitted',
            'reference_number' => 'INVOICE-REF',
            'xml_payload' => $xml,
            'request_metadata' => [
                'xml_sha256_base64' => base64_encode(hash('sha256', $xml, true)),
                'xml_size' => strlen($xml),
            ],
            'response_metadata' => [
                'mode' => 'native',
                'sessionReferenceNumber' => 'SESSION-REF',
            ],
            'submitted_at' => now(),
        ]);

        $this->post(route('ksef.submissions.refresh', $submission))
            ->assertRedirect()
            ->assertSessionHas('status', 'Status zgłoszenia KSeF został odświeżony.');

        $submission->refresh();

        $this->assertSame('accepted', $submission->status);
        $this->assertSame('5261040828-20260614-000000000001-11', $submission->ksef_number);
        $this->assertSame('5261040828-20260614-000000000001-11', $invoice->refresh()->ksef_number);
        $this->assertSame('SESSION-REF', data_get($submission->response_metadata, 'last_status_check.sessionReferenceNumber'));

        Http::assertSent(fn ($request): bool => $request->url() === $baseUrl.'/sessions/SESSION-REF/invoices/INVOICE-REF'
            && $request->hasHeader('Authorization', 'Bearer ACCESS-TOKEN'));
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

    public function test_b2c_invoice_is_skipped_by_default_for_ksef_submission(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        $invoice = $this->createInvoice();
        $buyer = $invoice->buyer_data;
        $buyer['tax_id'] = '';
        $invoice->update(['buyer_data' => $buyer]);

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'nie jest przeznaczona do wysyłki do KSeF')
                && str_contains($message, 'B2C'));

        $this->assertSame(0, KsefSubmission::query()->count());

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('B2C / pomiń')
            ->assertSee('Zmień KSeF');
    }

    public function test_global_invoice_ksef_policy_can_force_b2c_submission(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        AppSetting::query()->updateOrCreate(
            ['key' => 'invoice_ksef_settings'],
            ['value' => ['default_send_policy' => 'send']],
        );

        $invoice = $this->createInvoice();
        $buyer = $invoice->buyer_data;
        $buyer['tax_id'] = '';
        $invoice->update(['buyer_data' => $buyer]);

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $submission = KsefSubmission::query()->firstOrFail();

        $this->assertSame($invoice->id, $submission->invoice_id);
        $this->assertSame('missing_configuration', $submission->status);

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Wysyłać')
            ->assertSee('Domyślna konfiguracja faktur wymusza dobrowolną wysyłkę');
    }

    public function test_operator_can_force_b2c_invoice_to_ksef(): void
    {
        config([
            'queue.default' => 'sync',
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => 'test',
        ]);

        $invoice = $this->createInvoice();
        $buyer = $invoice->buyer_data;
        $buyer['tax_id'] = '';
        $invoice->update(['buyer_data' => $buyer]);

        $this->put(route('ksef.invoices.policy.update', $invoice), [
            'ksef_policy' => 'send',
        ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame('send', data_get($invoice->refresh()->metadata, 'ksef.send_policy'));

        $this->post(route('ksef.invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, KsefSubmission::query()->count());
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

        $this->assertSame('failed', $submission->status);
        $this->assertStringContainsString('HTTP 503', (string) $submission->last_error);

        $this->get(route('ksef.index'))
            ->assertOk()
            ->assertSee('Ponów')
            ->assertSee('Bramka KSeF zwróciła HTTP 503');

        $this->post(route('ksef.submissions.retry', $submission))
            ->assertRedirect()
            ->assertSessionHas('status', 'Faktura FV/2026/000001 została przyjęta przez KSeF.');

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
        $this->assertSame('failed', $audit->before['status']);
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

    public function test_operator_can_use_default_test_public_key_profile_for_ksef(): void
    {
        config([
            'services.ksef.access_token' => '',
            'services.ksef.gateway_url' => '',
            'services.ksef.environment' => '',
            'services.ksef.api_version' => '',
        ]);

        $this->put(route('integrations.ksef.configuration.update'), [
            'environment' => 'test',
            'api_version' => '2.6.0',
            'base_url' => '',
            'gateway_url' => '',
            'status_url' => '',
            'public_key_id' => '',
            'public_key_sha256' => '',
        ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Konfiguracja KSeF została zapisana.');

        $setting = AppSetting::query()->where('key', 'ksef_configuration')->firstOrFail();

        $this->assertSame('test', $setting->value['environment']);
        $this->assertSame(KsefSettingsService::TEST_PUBLIC_KEY_ID, $setting->value['public_key_id']);
        $this->assertSame(KsefSettingsService::TEST_PUBLIC_KEY_SHA256, $setting->value['public_key_sha256']);

        $this->get(route('integrations.index'))
            ->assertOk()
            ->assertSee(KsefSettingsService::TEST_PUBLIC_KEY_ID)
            ->assertSee(KsefSettingsService::TEST_PUBLIC_KEY_SHA256);

        $this->put(route('integrations.ksef.configuration.update'), [
            'environment' => 'production',
            'api_version' => '2.6.0',
            'public_key_id' => KsefSettingsService::TEST_PUBLIC_KEY_ID,
            'public_key_sha256' => KsefSettingsService::TEST_PUBLIC_KEY_SHA256,
        ])->assertRedirect();

        $setting->refresh();

        $this->assertSame('production', $setting->value['environment']);
        $this->assertSame('', $setting->value['public_key_id']);
        $this->assertSame('', $setting->value['public_key_sha256']);
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function publicKeyCertificates(?string $tokenPublicKeyId = null, ?string $sessionPublicKeyId = null): array
    {
        $certificate = $this->certificateBase64();

        return [
            [
                'certificate' => $certificate,
                'certificateId' => $this->publicKeyId('token-certificate'),
                'publicKeyId' => $tokenPublicKeyId ?? $this->publicKeyId('token'),
                'validFrom' => '2026-01-01T00:00:00+00:00',
                'validTo' => '2028-01-01T00:00:00+00:00',
                'usage' => ['KsefTokenEncryption'],
            ],
            [
                'certificate' => $certificate,
                'certificateId' => $this->publicKeyId('session-certificate'),
                'publicKeyId' => $sessionPublicKeyId ?? $this->publicKeyId('session'),
                'validFrom' => '2026-01-01T00:00:00+00:00',
                'validTo' => '2028-01-01T00:00:00+00:00',
                'usage' => ['SymmetricKeyEncryption'],
            ],
        ];
    }

    private function publicKeyId(string $seed): string
    {
        return base64_encode(hash('sha256', $seed, true));
    }

    private function certificateBase64(): string
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(['commonName' => 'Ministerstwo Finansow'], $privateKey);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 365);

        openssl_x509_export($certificate, $pem);

        return preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $pem) ?? '';
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
                'tax_id' => '5261040828',
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
