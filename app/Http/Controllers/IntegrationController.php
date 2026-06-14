<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IntegrationSyncLog;
use App\Models\SalesChannel;
use App\Models\WordpressIntegration;
use App\Services\Audit\AuditLogService;
use App\Services\Gs1\Gs1Client;
use App\Services\Gs1\Gs1SettingsService;
use App\Services\Integrations\WooCommerceImportQueueService;
use App\Services\Ksef\KsefClient;
use App\Services\Ksef\KsefSettingsService;
use App\Services\WooCommerce\WooCommerceClient;
use App\Services\Wordpress\LemonErpWooCommercePluginPackageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class IntegrationController extends Controller
{
    public function index(
        KsefClient $ksefClient,
        KsefSettingsService $ksefSettings,
        Gs1SettingsService $gs1Settings,
        LemonErpWooCommercePluginPackageService $woocommercePlugin,
    ): View {
        return view('integrations.index', [
            'integrations' => WordpressIntegration::query()
                ->with('salesChannel')
                ->latest()
                ->get(),
            'logs' => IntegrationSyncLog::query()
                ->with(['salesChannel', 'wordpressIntegration'])
                ->latest()
                ->limit(20)
                ->get(),
            'ksefConfiguration' => $ksefClient->configurationStatus(),
            'ksefSettings' => $ksefSettings->publicConfiguration(),
            'gs1Settings' => $gs1Settings->publicConfiguration(),
            'woocommercePluginVersion' => $woocommercePlugin->version(),
            'module' => 'integrations',
        ]);
    }

    public function store(Request $request, AuditLogService $audit): RedirectResponse
    {
        $request->merge([
            'channel_code' => Str::upper(Str::slug((string) $request->input('channel_code'), '_')),
        ]);

        $validated = $request->validate([
            'channel_code' => ['required', 'string', 'max:40', Rule::unique('sales_channels', 'code')],
            'channel_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:255'],
            'consumer_key' => ['required', 'string', 'max:255'],
            'consumer_secret' => ['required', 'string', 'max:255'],
            'wp_api_username' => ['nullable', 'string', 'max:255'],
            'wp_api_application_password' => ['nullable', 'string', 'max:255'],
            'order_import_enabled' => ['nullable', 'boolean'],
            'stock_export_enabled' => ['nullable', 'boolean'],
            'invoice_upload_enabled' => ['nullable', 'boolean'],
            'invoice_delivery_mode' => ['nullable', Rule::in(['lemon_plugin', 'media_library'])],
        ]);

        $channel = SalesChannel::query()->firstOrCreate(
            ['code' => $validated['channel_code']],
            [
                'name' => $validated['channel_name'],
                'type' => 'woocommerce',
                'is_active' => true,
            ],
        );

        $integration = WordpressIntegration::query()->create([
            'sales_channel_id' => $channel->id,
            'name' => $validated['name'],
            'base_url' => rtrim($validated['base_url'], '/'),
            'consumer_key_encrypted' => Crypt::encryptString($validated['consumer_key']),
            'consumer_secret_encrypted' => Crypt::encryptString($validated['consumer_secret']),
            'wp_api_username' => $validated['wp_api_username'] ?? null,
            'wp_api_password_encrypted' => filled($validated['wp_api_application_password'] ?? null)
                ? Crypt::encryptString($validated['wp_api_application_password'])
                : null,
            'order_import_enabled' => $request->boolean('order_import_enabled'),
            'stock_export_enabled' => $request->boolean('stock_export_enabled'),
            'invoice_upload_enabled' => $request->boolean('invoice_upload_enabled'),
            'settings' => [
                'created_from' => 'erp_panel',
                'invoice_delivery' => [
                    'mode' => $validated['invoice_delivery_mode'] ?? 'lemon_plugin',
                ],
                'order_statuses' => [
                    'ready_to_ship' => 'ready-to-ship',
                    'shipped' => 'completed',
                    'packing_rollback' => 'processing',
                ],
            ],
        ]);

        $audit->record('integration.created', $integration, null, [
            'sales_channel_code' => $channel->code,
            'sales_channel_name' => $channel->name,
            'name' => $integration->name,
            'base_url' => $integration->base_url,
            'order_import_enabled' => $integration->order_import_enabled,
            'stock_export_enabled' => $integration->stock_export_enabled,
            'invoice_upload_enabled' => $integration->invoice_upload_enabled,
        ]);

        return redirect()
            ->to(route('integrations.index').'#woocommerce-plugin')
            ->with('status', 'Integracja WooCommerce została dodana. Pobierz wtyczkę Lemon ERP i wgraj ją w panelu WordPress, a potem użyj testu połączenia.');
    }

    public function downloadWooCommercePlugin(LemonErpWooCommercePluginPackageService $packages): BinaryFileResponse
    {
        try {
            $package = $packages->build();
        } catch (RuntimeException $exception) {
            abort(500, $exception->getMessage());
        }

        return response()->download($package['path'], $package['filename'], [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function update(
        Request $request,
        WordpressIntegration $integration,
        AuditLogService $audit,
    ): RedirectResponse {
        $integration->load('salesChannel');
        $request->merge([
            'channel_code' => Str::upper(Str::slug((string) $request->input('channel_code'), '_')),
        ]);

        $validated = $request->validate([
            'channel_code' => [
                'required',
                'string',
                'max:40',
                Rule::unique('sales_channels', 'code')->ignore($integration->sales_channel_id),
            ],
            'channel_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'base_url' => ['required', 'url', 'max:255'],
            'consumer_key' => ['nullable', 'string', 'max:255'],
            'consumer_secret' => ['nullable', 'string', 'max:255'],
            'order_import_enabled' => ['nullable', 'boolean'],
            'stock_export_enabled' => ['nullable', 'boolean'],
            'invoice_upload_enabled' => ['nullable', 'boolean'],
            'invoice_delivery_mode' => ['nullable', Rule::in(['lemon_plugin', 'media_library'])],
        ]);

        $channelCode = $validated['channel_code'];
        $before = [
            'sales_channel_code' => $integration->salesChannel?->code,
            'sales_channel_name' => $integration->salesChannel?->name,
            'name' => $integration->name,
            'base_url' => $integration->base_url,
            'order_import_enabled' => $integration->order_import_enabled,
            'stock_export_enabled' => $integration->stock_export_enabled,
            'invoice_upload_enabled' => $integration->invoice_upload_enabled,
            'invoice_delivery' => $integration->invoiceDeliverySettings(),
            'consumer_key' => $integration->maskedConsumerKey(),
        ];

        $integration->salesChannel?->update([
            'code' => $channelCode,
            'name' => $validated['channel_name'],
        ]);

        $updates = [
            'name' => $validated['name'],
            'base_url' => rtrim($validated['base_url'], '/'),
            'order_import_enabled' => $request->boolean('order_import_enabled'),
            'stock_export_enabled' => $request->boolean('stock_export_enabled'),
            'invoice_upload_enabled' => $request->boolean('invoice_upload_enabled'),
        ];
        $settings = $integration->settings ?? [];
        $settings['invoice_delivery'] = [
            'mode' => $validated['invoice_delivery_mode'] ?? $integration->invoiceDeliverySettings()['mode'],
        ];
        $updates['settings'] = $settings;

        if (filled($validated['consumer_key'] ?? null)) {
            $updates['consumer_key_encrypted'] = Crypt::encryptString($validated['consumer_key']);
        }

        if (filled($validated['consumer_secret'] ?? null)) {
            $updates['consumer_secret_encrypted'] = Crypt::encryptString($validated['consumer_secret']);
        }

        $integration->update($updates);
        $integration->refresh()->load('salesChannel');

        $audit->record(
            'integration.updated',
            $integration,
            $before,
            [
                'sales_channel_code' => $integration->salesChannel?->code,
                'sales_channel_name' => $integration->salesChannel?->name,
                'name' => $integration->name,
                'base_url' => $integration->base_url,
                'order_import_enabled' => $integration->order_import_enabled,
                'stock_export_enabled' => $integration->stock_export_enabled,
                'invoice_upload_enabled' => $integration->invoice_upload_enabled,
                'invoice_delivery' => $integration->invoiceDeliverySettings(),
                'consumer_key' => filled($validated['consumer_key'] ?? null)
                    ? $integration->maskedConsumerKey()
                    : 'unchanged',
            ],
            ['sensitive_values' => 'redacted'],
        );

        return back()->with('status', 'Konfiguracja integracji WooCommerce została zapisana.');
    }

    public function test(WordpressIntegration $integration, WooCommerceClient $client): RedirectResponse
    {
        try {
            $result = $client->test($integration);

            $integration->update(['last_successful_sync_at' => now()]);
            $this->log($integration, 'in', 'test_connection', 'success', responsePayload: $result);

            return back()->with('status', "Połączenie działa. WooCommerce: {$result['wc_version']}.");
        } catch (Throwable $exception) {
            $this->log($integration, 'in', 'test_connection', 'failed', error: $exception->getMessage());

            return back()->with('error', 'Test połączenia nie powiódł się: '.$exception->getMessage());
        }
    }

    public function importProducts(
        WordpressIntegration $integration,
        WooCommerceImportQueueService $imports,
    ): RedirectResponse {
        $log = $imports->queueImport($integration, 'import_products');

        if (! $log->wasRecentlyCreated) {
            return back()->with('status', 'Import produktów dla tej integracji jest już w kolejce albo w toku.');
        }

        return back()->with('status', 'Import produktów został dodany do kolejki. Status będzie widoczny w logach synchronizacji.');
    }

    public function importOrders(
        WordpressIntegration $integration,
        WooCommerceImportQueueService $imports,
    ): RedirectResponse {
        $log = $imports->queueImport($integration, 'import_orders');

        if (! $log->wasRecentlyCreated) {
            return back()->with('status', 'Import zamówień dla tej integracji jest już w kolejce albo w toku.');
        }

        return back()->with('status', 'Import zamówień został dodany do kolejki. Status będzie widoczny w logach synchronizacji.');
    }

    public function retryLog(
        IntegrationSyncLog $log,
        WooCommerceImportQueueService $imports,
        AuditLogService $audit,
    ): RedirectResponse {
        if ($log->status !== 'failed') {
            return back()->with('error', 'Ponowić można tylko nieudany import.');
        }

        if (! in_array($log->operation, ['import_products', 'import_orders'], true)) {
            return back()->with('error', 'Ten typ operacji nie obsługuje ręcznego ponowienia.');
        }

        $integration = $log->wordpressIntegration;

        if (! $integration instanceof WordpressIntegration) {
            return back()->with('error', 'Nie można ponowić importu, bo integracja została usunięta.');
        }

        $retryLog = $imports->queueImport($integration, $log->operation, $log);

        if (! $retryLog->wasRecentlyCreated) {
            return back()->with('status', 'Taki import jest już w kolejce albo w toku. Nie dodano duplikatu.');
        }

        $audit->record(
            'integration_sync.retry_requested',
            $retryLog,
            [
                'source_log_id' => $log->id,
                'source_status' => $log->status,
                'source_error' => $log->error_message,
            ],
            [
                'retry_log_id' => $retryLog->id,
                'operation' => $retryLog->operation,
                'status' => $retryLog->status,
            ],
            [
                'sales_channel_id' => $integration->sales_channel_id,
                'wordpress_integration_id' => $integration->id,
            ],
        );

        return back()->with('status', 'Import został ponownie dodany do kolejki.');
    }

    public function updateWordpressCredentials(Request $request, WordpressIntegration $integration): RedirectResponse
    {
        $validated = $request->validate([
            'wp_api_username' => ['required', 'string', 'max:255'],
            'wp_api_application_password' => ['required', 'string', 'max:255'],
        ]);

        $integration->update([
            'wp_api_username' => $validated['wp_api_username'],
            'wp_api_password_encrypted' => Crypt::encryptString($validated['wp_api_application_password']),
        ]);

        return back()->with('status', 'Dane WordPress REST do uploadu faktur zostały zapisane.');
    }

    public function updateShippingLabelSettings(
        Request $request,
        WordpressIntegration $integration,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'shipping_label_enabled' => ['nullable', 'boolean'],
            'shipping_label_endpoint' => ['nullable', 'string', 'max:255', 'required_if:shipping_label_enabled,1'],
            'shipping_label_method' => ['required', 'in:GET,POST,PUT'],
            'shipping_label_auth' => ['required', 'in:woocommerce,wordpress,none'],
            'shipping_label_url_key' => ['nullable', 'string', 'max:120'],
            'shipping_label_base64_key' => ['nullable', 'string', 'max:120'],
            'shipping_label_filename_key' => ['nullable', 'string', 'max:120'],
        ]);

        $before = $integration->shippingLabelSettings();
        $settings = $integration->settings ?? [];
        $settings['shipping_labels'] = [
            'enabled' => $request->boolean('shipping_label_enabled'),
            'endpoint' => filled($validated['shipping_label_endpoint'] ?? null)
                ? trim((string) $validated['shipping_label_endpoint'])
                : null,
            'method' => $validated['shipping_label_method'],
            'auth' => $validated['shipping_label_auth'],
            'url_key' => filled($validated['shipping_label_url_key'] ?? null)
                ? trim((string) $validated['shipping_label_url_key'])
                : null,
            'base64_key' => filled($validated['shipping_label_base64_key'] ?? null)
                ? trim((string) $validated['shipping_label_base64_key'])
                : null,
            'filename_key' => filled($validated['shipping_label_filename_key'] ?? null)
                ? trim((string) $validated['shipping_label_filename_key'])
                : null,
        ];

        $integration->update(['settings' => $settings]);

        $audit->record('integration.shipping_labels_updated', $integration, $before, $integration->shippingLabelSettings(), [
            'sales_channel' => $integration->salesChannel?->code,
        ]);

        return back()->with('status', 'Konfiguracja etykiet kurierskich została zapisana.');
    }

    public function updateKsefConfiguration(
        Request $request,
        KsefSettingsService $settings,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'environment' => ['required', Rule::in(['test', 'demo', 'production'])],
            'api_version' => ['required', 'string', 'max:30'],
            'base_url' => ['nullable', 'url', 'max:500'],
            'gateway_url' => ['nullable', 'url', 'max:500'],
            'status_url' => ['nullable', 'url', 'max:500'],
            'access_token' => ['nullable', 'string', 'max:2000'],
            'clear_access_token' => ['nullable', 'boolean'],
        ]);

        $change = $settings->update($validated);

        $audit->record(
            'ksef.configuration_updated',
            null,
            $change['before'],
            $change['after'],
            ['sensitive_values' => 'redacted'],
        );

        return back()->with('status', 'Konfiguracja KSeF została zapisana.');
    }

    public function updateGs1Configuration(
        Request $request,
        Gs1SettingsService $settings,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:500'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'clear_password' => ['nullable', 'boolean'],
            'company_prefix' => ['required', 'string', 'regex:/^\d{6,11}$/'],
            'next_item_reference' => ['required', 'integer', 'min:0'],
            'default_gpc_code' => ['nullable', 'string', 'regex:/^\d+$/', 'max:20'],
            'gpc_options' => ['nullable', 'string', 'max:100000'],
            'target_market' => ['required', 'string', 'size:2'],
            'register_products' => ['nullable', 'boolean'],
        ]);

        $change = $settings->update($validated);

        $audit->record(
            'gs1.configuration_updated',
            null,
            $change['before'],
            $change['after'],
            ['sensitive_values' => 'redacted'],
        );

        return back()->with('status', 'Konfiguracja GS1 została zapisana.');
    }

    public function testGs1Connection(Gs1Client $client, AuditLogService $audit): RedirectResponse
    {
        try {
            $client->testConnection();
        } catch (Throwable $exception) {
            $audit->record('gs1.connection_failed', null, null, null, [
                'error' => $exception->getMessage(),
            ]);

            return back()->with('error', $exception->getMessage());
        }

        $audit->record('gs1.connection_succeeded');

        return back()->with('status', 'Połączenie GS1 działa. Dane API są poprawne.');
    }

    public function updateOrderStatusSettings(
        Request $request,
        WordpressIntegration $integration,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'ready_to_ship_status' => ['required', 'string', 'max:80'],
            'shipped_status' => ['required', 'string', 'max:80'],
            'packing_rollback_status' => ['nullable', 'string', 'max:80'],
        ]);

        $before = $integration->orderStatusSettings();
        $settings = $integration->settings ?? [];
        $settings['order_statuses'] = [
            'ready_to_ship' => trim((string) $validated['ready_to_ship_status']),
            'shipped' => trim((string) $validated['shipped_status']),
            'packing_rollback' => trim((string) ($validated['packing_rollback_status'] ?? 'processing')) ?: 'processing',
        ];

        $integration->update(['settings' => $settings]);

        $audit->record('integration.order_statuses_updated', $integration, $before, $integration->orderStatusSettings(), [
            'sales_channel' => $integration->salesChannel?->code,
        ]);

        return back()->with('status', 'Statusy WooCommerce dla pakowania zostały zapisane.');
    }

    public function destroy(WordpressIntegration $integration, AuditLogService $audit): RedirectResponse
    {
        $activeImport = IntegrationSyncLog::query()
            ->where('wordpress_integration_id', $integration->id)
            ->whereIn('status', ['queued', 'running'])
            ->exists();

        if ($activeImport) {
            return back()->with('error', 'Nie można usunąć integracji, gdy import jest w kolejce albo w toku.');
        }

        $before = [
            'id' => $integration->id,
            'sales_channel_id' => $integration->sales_channel_id,
            'name' => $integration->name,
            'base_url' => $integration->base_url,
            'order_import_enabled' => $integration->order_import_enabled,
            'stock_export_enabled' => $integration->stock_export_enabled,
            'invoice_upload_enabled' => $integration->invoice_upload_enabled,
        ];

        $integration->update([
            'order_import_enabled' => false,
            'stock_export_enabled' => false,
            'invoice_upload_enabled' => false,
        ]);

        $integration->delete();

        $audit->record(
            'integration.deleted',
            $integration,
            $before,
            [
                'deleted_at' => $integration->deleted_at?->toDateTimeString(),
                'order_import_enabled' => false,
                'stock_export_enabled' => false,
                'invoice_upload_enabled' => false,
            ],
            [
                'sales_channel_id' => $integration->sales_channel_id,
                'sales_channel_code' => $integration->salesChannel?->code,
            ],
        );

        return back()->with('status', 'Integracja została usunięta.');
    }

    private function log(
        WordpressIntegration $integration,
        string $direction,
        string $operation,
        string $status,
        ?array $requestPayload = null,
        ?array $responsePayload = null,
        ?string $error = null,
    ): IntegrationSyncLog {
        return IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => $direction,
            'operation' => $operation,
            'status' => $status,
            'request_payload' => $requestPayload,
            'response_payload' => $responsePayload,
            'error_message' => $error,
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => in_array($status, ['queued', 'running'], true) ? null : now(),
        ]);
    }
}
