<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourierAccount;
use App\Models\EmailTemplate;
use App\Models\Warehouse;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Communication\EmailTemplateRenderer;
use App\Services\Communication\MailSettingsService;
use App\Services\Inventory\WarehouseDocumentSettingsService;
use App\Services\Payments\MbankTransferBasketSettingsService;
use App\Services\Payments\PayuRefundSettingsService;
use App\Services\Packing\PackingSettingsService;
use App\Services\Returns\ReturnSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SettingsController extends Controller
{
    public function __invoke(): View
    {
        return view('settings.index', [
            'title' => 'Ustawienia',
            'subtitle' => 'Wybierz obszar konfiguracji. Szczegółowe formularze są rozdzielone na osobne strony modułów.',
            'module' => 'settings',
        ]);
    }

    public function documents(
        WarehouseDocumentSettingsService $documentSettings,
        DocumentAutomationSettingsService $automationSettings,
    ): View {
        return view('settings.documents', [
            'title' => 'Ustawienia dokumentów',
            'subtitle' => 'Numeracja dokumentów magazynowych, lokalizacje i automatyczny obieg akcji.',
            'module' => 'settings',
            'documentNumbering' => $documentSettings->numberingData(),
            'documentNumberExample' => $documentSettings->exampleNumber(),
            'warehouseLocations' => $documentSettings->locations(),
            'documentAutomation' => $automationSettings->data(),
            'documentAutomationRules' => $automationSettings->rules(),
        ]);
    }

    public function shipping(): View
    {
        return view('settings.shipping', [
            'title' => 'Ustawienia wysyłek',
            'subtitle' => 'Konta kurierskie do etykiet: InPost (ShipX) i BLPaczka. Każde konto ma własny token API.',
            'module' => 'settings',
            'accounts' => CourierAccount::query()
                ->orderBy('provider')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function mail(MailSettingsService $mailSettings, EmailTemplateRenderer $templateRenderer): View
    {
        return view('settings.mail', [
            'title' => 'Ustawienia maili',
            'subtitle' => 'Konfiguracja SMTP oraz szablony ręcznej komunikacji z klientami.',
            'module' => 'settings',
            'mailSettings' => $mailSettings->data(),
            'mailDeliverability' => $mailSettings->deliverabilityReport(),
            'templateVariables' => $templateRenderer->variables(),
            'emailTemplates' => EmailTemplate::query()
                ->orderBy('context')
                ->orderBy('name')
                ->get(),
            'runtimeMailer' => config('mail.default'),
        ]);
    }

    public function payments(
        PayuRefundSettingsService $payuSettings,
        MbankTransferBasketSettingsService $mbankSettings,
    ): View {
        return view('settings.payments', [
            'title' => 'Ustawienia płatności',
            'subtitle' => 'PayU refundy i eksport koszyka przelewów mBank dla zwrotów pobraniowych.',
            'module' => 'settings',
            'payuSettings' => $payuSettings->data(),
            'mbankSettings' => $mbankSettings->data(),
        ]);
    }

    public function packing(PackingSettingsService $packingSettings): View
    {
        return view('settings.packing', [
            'title' => 'Ustawienia pakowania',
            'subtitle' => 'Stanowiska pakowania, drukarki etykiet Zebra i podział asortymentu do kompletacji.',
            'module' => 'settings',
            'packingSettings' => $packingSettings->data(),
            'printListenerApp' => $this->windowsPrintListenerAppData(),
        ]);
    }

    public function downloadWindowsPrintListener(): BinaryFileResponse
    {
        $path = $this->windowsPrintListenerPath();

        if (! is_file($path)) {
            abort(404, 'Aplikacja Windows do wydruku nie jest dostępna na serwerze ERP.');
        }

        return response()->download($path, 'lemon-print-listener.exe', [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updatePacking(Request $request, PackingSettingsService $packingSettings): RedirectResponse
    {
        $data = $request->validate([
            'stations' => ['required', 'array', 'min:1', 'max:6'],
            'stations.*.code' => ['nullable', 'string', 'max:40'],
            'stations.*.name' => ['nullable', 'string', 'max:80'],
            'stations.*.printer_name' => ['nullable', 'string', 'max:120'],
            'stations.*.listener_url' => ['nullable', 'url', 'max:180'],
            'stations.*.segment' => ['nullable', 'string', 'in:all,clothing,footwear'],
            'footwear_keywords' => ['nullable', 'string', 'max:2000'],
        ]);

        $packingSettings->update([
            'stations' => $data['stations'],
            'footwear_keywords' => $data['footwear_keywords'] ?? null,
        ]);

        return back()->with('status', 'Ustawienia stanowisk pakowania i drukarek zostały zapisane.');
    }

    public function packingListenerPrinters(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listener_url' => ['required', 'url', 'max:180'],
        ]);

        $listenerUrl = rtrim((string) $data['listener_url'], '/');

        try {
            $response = Http::timeout(6)
                ->acceptJson()
                ->get($listenerUrl.'/printers');
        } catch (Throwable $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Nie udało się połączyć z aplikacją Windows: '.$exception->getMessage(),
            ], 422);
        }

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Aplikacja Windows zwróciła HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'printers' => collect((array) $response->json('printers', []))
                ->map(fn (array $printer): array => [
                    'name' => trim((string) ($printer['name'] ?? '')),
                    'driver' => trim((string) ($printer['driver'] ?? '')),
                    'port' => trim((string) ($printer['port'] ?? '')),
                    'default' => (bool) ($printer['default'] ?? false),
                ])
                ->filter(fn (array $printer): bool => $printer['name'] !== '')
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array{available:bool,download_url:string,filename:string,size_mb:string|null,updated_at:string|null}
     */
    private function windowsPrintListenerAppData(): array
    {
        $path = $this->windowsPrintListenerPath();
        $mtime = is_file($path) ? filemtime($path) : false;
        $size = is_file($path) ? filesize($path) : false;

        return [
            'available' => is_file($path),
            'download_url' => route('settings.packing.windows-listener.download', [
                'v' => $mtime ?: now()->timestamp,
            ]),
            'filename' => 'lemon-print-listener.exe',
            'size_mb' => $size !== false ? number_format($size / 1048576, 1, ',', ' ') . ' MB' : null,
            'updated_at' => $mtime !== false ? date('Y-m-d H:i', $mtime) : null,
        ];
    }

    private function windowsPrintListenerPath(): string
    {
        return base_path('tools/windows-print-listener/dist/lemon-print-listener.exe');
    }

    public function updatePayments(
        Request $request,
        PayuRefundSettingsService $payuSettings,
        MbankTransferBasketSettingsService $mbankSettings,
    ): RedirectResponse {
        $payuEnabled = $request->boolean('payu_enabled');
        $validated = $request->validate([
            'payu_enabled' => ['nullable', 'boolean'],
            'payu_auto_refund_enabled' => ['nullable', 'boolean'],
            'payu_environment' => ['required', 'string', 'in:sandbox,production'],
            'payu_client_id' => [$payuEnabled ? 'required' : 'nullable', 'string', 'max:120'],
            'payu_pos_id' => ['nullable', 'string', 'max:120'],
            'payu_client_secret' => ['nullable', 'string', 'max:2000'],
            'payu_clear_client_secret' => ['nullable', 'boolean'],
            'payu_refund_type' => ['required', 'string', 'in:REFUND_PAYMENT_STANDARD,FAST'],
            'mbank_source_account' => ['nullable', 'string', 'max:34'],
            'mbank_source_bank_code' => ['required', 'string', 'max:8'],
            'mbank_source_name' => ['required', 'string', 'max:143'],
            'mbank_encoding' => ['required', 'string', 'in:UTF-8,Windows-1250,CP852'],
        ]);

        $payuSettings->update([
            'enabled' => $payuEnabled,
            'auto_refund_enabled' => $request->boolean('payu_auto_refund_enabled'),
            'environment' => $validated['payu_environment'],
            'client_id' => $validated['payu_client_id'] ?? '',
            'pos_id' => $validated['payu_pos_id'] ?? '',
            'client_secret' => $validated['payu_client_secret'] ?? '',
            'clear_client_secret' => $request->boolean('payu_clear_client_secret'),
            'refund_type' => $validated['payu_refund_type'],
        ]);

        $mbankSettings->update([
            'source_account' => $validated['mbank_source_account'] ?? '',
            'source_bank_code' => $validated['mbank_source_bank_code'],
            'source_name' => $validated['mbank_source_name'],
            'encoding' => $validated['mbank_encoding'],
        ]);

        return back()->with('status', 'Ustawienia płatności i zwrotów zostały zapisane.');
    }

    public function updateMail(Request $request, MailSettingsService $mailSettings): RedirectResponse
    {
        $enabled = $request->boolean('enabled');
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'host' => [$enabled ? 'required' : 'nullable', 'string', 'max:255'],
            'port' => [$enabled ? 'required' : 'nullable', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'string', 'in:none,tls,ssl'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:2000'],
            'clear_password' => ['nullable', 'boolean'],
            'from_address' => [$enabled ? 'required' : 'nullable', 'email', 'max:255'],
            'from_name' => [$enabled ? 'required' : 'nullable', 'string', 'max:120'],
            'ehlo_domain' => ['nullable', 'string', 'max:255'],
            'timeout' => ['required', 'integer', 'min:3', 'max:120'],
            'brand_name' => ['nullable', 'string', 'max:120'],
            'logo_url' => ['nullable', 'url', 'max:1000'],
            'accent_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'header_text' => ['nullable', 'string', 'max:160'],
            'signature' => ['nullable', 'string', 'max:1000'],
            'footer_text' => ['nullable', 'string', 'max:1000'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:40'],
        ]);

        $validated['enabled'] = $enabled;
        $validated['clear_password'] = $request->boolean('clear_password');
        $mailSettings->update($validated);

        return back()->with('status', 'Ustawienia poczty zostały zapisane.');
    }

    public function testMail(Request $request, MailSettingsService $mailSettings): RedirectResponse
    {
        $validated = $request->validate([
            'recipient' => ['required', 'email', 'max:255'],
        ]);

        $settings = $mailSettings->data();

        if (! $settings['enabled']) {
            return back()->with('error', 'Najpierw włącz konfigurację poczty i zapisz ustawienia.');
        }

        try {
            $mailSettings->apply();
            Mail::raw(
                "To jest test konfiguracji maili z Sempre ERP.\n\nJeśli widzisz tę wiadomość, SMTP działa poprawnie.",
                function (Message $message) use ($validated): void {
                    $message
                        ->to($validated['recipient'])
                        ->subject('Test konfiguracji maili Sempre ERP');
                },
            );
        } catch (\Throwable $exception) {
            return back()->with('error', 'Nie udało się wysłać maila testowego: '.$exception->getMessage());
        }

        return back()->with('status', 'Mail testowy został wysłany na '.$validated['recipient'].'.');
    }

    public function storeEmailTemplate(Request $request): RedirectResponse
    {
        $validated = $this->emailTemplateData($request);
        $codeBase = Str::slug($validated['name']) ?: 'szablon';
        $code = $codeBase;
        $suffix = 2;

        while (EmailTemplate::query()->where('code', $code)->exists()) {
            $code = $codeBase.'-'.$suffix;
            $suffix++;
        }

        EmailTemplate::query()->create(array_merge($validated, [
            'code' => $code,
            'is_active' => $request->boolean('is_active', true),
        ]));

        return back()->with('status', 'Szablon maila został dodany.');
    }

    public function updateEmailTemplate(Request $request, EmailTemplate $template): RedirectResponse
    {
        $validated = $this->emailTemplateData($request);
        $template->update(array_merge($validated, [
            'is_active' => $request->boolean('is_active'),
        ]));

        return back()->with('status', 'Szablon maila został zapisany.');
    }

    public function destroyEmailTemplate(EmailTemplate $template): RedirectResponse
    {
        $name = $template->name;
        $template->delete();

        return back()->with('status', "Szablon {$name} został usunięty.");
    }

    public function storeCourierAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:inpost,blpaczka'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'organization_id' => ['required', 'string', 'max:255'],
            'api_token' => ['required', 'string', 'max:2000'],
            'default_parcel_template' => ['required', 'string', 'in:small,medium,large'],
            'sending_method' => ['required', 'string', 'in:dispatch_order,parcel_locker,pok,branch'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($validated): void {
            $account = new CourierAccount([
                'provider' => $validated['provider'],
                'code' => mb_strtolower($validated['code']),
                'name' => $validated['name'],
                'organization_id' => $validated['organization_id'],
                'default_parcel_template' => $validated['default_parcel_template'],
                'sending_method' => $validated['sending_method'],
                'is_default' => (bool) ($validated['is_default'] ?? false),
                'is_active' => true,
            ]);
            $account->setApiToken($validated['api_token']);
            $account->save();

            if ($account->is_default) {
                $this->demoteOtherDefaults($account);
            }
        });

        return back()->with('status', "Konto kurierskie {$validated['name']} zostało dodane.");
    }

    public function updateCourierAccount(Request $request, CourierAccount $account): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'organization_id' => ['required', 'string', 'max:40'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'default_parcel_template' => ['required', 'string', 'in:small,medium,large'],
            'sending_method' => ['required', 'string', 'in:dispatch_order,parcel_locker,pok,branch'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'return_name' => ['nullable', 'string', 'max:120'],
            'return_phone' => ['nullable', 'string', 'max:32'],
            'return_email' => ['nullable', 'email', 'max:255'],
            'return_target_point' => ['nullable', 'string', 'max:20'],
            'return_street' => ['nullable', 'string', 'max:160'],
            'return_building_number' => ['nullable', 'string', 'max:20'],
            'return_post_code' => ['nullable', 'string', 'max:12'],
            'return_city' => ['nullable', 'string', 'max:80'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_street' => ['nullable', 'string', 'max:160'],
            'sender_house_no' => ['nullable', 'string', 'max:20'],
            'sender_locum_no' => ['nullable', 'string', 'max:20'],
            'sender_postal' => ['nullable', 'string', 'max:12'],
            'sender_city' => ['nullable', 'string', 'max:80'],
            'sender_phone' => ['nullable', 'string', 'max:32'],
            'sender_email' => ['nullable', 'email', 'max:255'],
            'parcel_weight' => ['nullable', 'numeric', 'min:0.1', 'max:1000'],
            'parcel_side_x' => ['nullable', 'integer', 'min:1', 'max:500'],
            'parcel_side_y' => ['nullable', 'integer', 'min:1', 'max:500'],
            'parcel_side_z' => ['nullable', 'integer', 'min:1', 'max:500'],
            'payment' => ['nullable', 'string', 'in:bank,pay_later'],
        ]);

        DB::transaction(function () use ($account, $validated): void {
            $account->fill([
                'name' => $validated['name'],
                'organization_id' => $validated['organization_id'],
                'default_parcel_template' => $validated['default_parcel_template'],
                'sending_method' => $validated['sending_method'],
                'is_default' => (bool) ($validated['is_default'] ?? false),
                'is_active' => (bool) ($validated['is_active'] ?? false),
                'metadata' => array_merge((array) $account->metadata, [
                    'return' => [
                        'name' => trim((string) ($validated['return_name'] ?? '')),
                        'phone' => trim((string) ($validated['return_phone'] ?? '')),
                        'email' => trim((string) ($validated['return_email'] ?? '')),
                        'target_point' => strtoupper(trim((string) ($validated['return_target_point'] ?? ''))),
                        'street' => trim((string) ($validated['return_street'] ?? '')),
                        'building_number' => trim((string) ($validated['return_building_number'] ?? '')),
                        'post_code' => trim((string) ($validated['return_post_code'] ?? '')),
                        'city' => trim((string) ($validated['return_city'] ?? '')),
                        'country_code' => 'PL',
                    ],
                    'sender' => [
                        'name' => trim((string) ($validated['sender_name'] ?? '')),
                        'street' => trim((string) ($validated['sender_street'] ?? '')),
                        'house_no' => trim((string) ($validated['sender_house_no'] ?? '')),
                        'locum_no' => trim((string) ($validated['sender_locum_no'] ?? '')),
                        'postal' => trim((string) ($validated['sender_postal'] ?? '')),
                        'city' => trim((string) ($validated['sender_city'] ?? '')),
                        'phone' => trim((string) ($validated['sender_phone'] ?? '')),
                        'email' => trim((string) ($validated['sender_email'] ?? '')),
                    ],
                    'parcel' => [
                        'weight' => (float) ($validated['parcel_weight'] ?? 0),
                        'side_x' => (int) ($validated['parcel_side_x'] ?? 0),
                        'side_y' => (int) ($validated['parcel_side_y'] ?? 0),
                        'side_z' => (int) ($validated['parcel_side_z'] ?? 0),
                    ],
                    'payment' => (string) ($validated['payment'] ?? 'bank'),
                ]),
            ]);

            if (filled($validated['api_token'] ?? null)) {
                $account->setApiToken($validated['api_token']);
            }

            $account->save();

            if ($account->is_default) {
                $this->demoteOtherDefaults($account);
            }
        });

        return back()->with('status', "Konto kurierskie {$account->name} zostało zaktualizowane.");
    }

    public function destroyCourierAccount(CourierAccount $account): RedirectResponse
    {
        $name = $account->name;
        $account->delete();

        return back()->with('status', "Konto kurierskie {$name} zostało usunięte. Wystawione etykiety pozostają w historii.");
    }

    private function demoteOtherDefaults(CourierAccount $account): void
    {
        CourierAccount::query()
            ->where('provider', $account->provider)
            ->whereKeyNot($account->id)
            ->update(['is_default' => false]);
    }

    /**
     * @return array{name:string,context:string,subject:string,body:string}
     */
    private function emailTemplateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'context' => ['required', 'string', 'in:order,return,both'],
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    public function returns(ReturnSettingsService $returnSettings): View
    {
        return view('settings.returns', [
            'title' => 'Ustawienia zwrotów',
            'subtitle' => 'Domyślne ustawienia przyjęć zwrotów, numeracji i docelowego magazynu.',
            'module' => 'settings',
            'returnSettings' => $returnSettings->data(),
            'returnNumberExample' => $returnSettings->exampleNumber(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function updateDocuments(
        Request $request,
        WarehouseDocumentSettingsService $documentSettings,
    ): RedirectResponse {
        $validated = $request->validate([
            'pattern' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/{}-]+$/'],
            'padding' => ['required', 'integer', 'min:3', 'max:9'],
        ]);

        $documentSettings->updateNumberingData($validated);

        return back()->with('status', 'Ustawienia numeracji dokumentów magazynowych zostały zapisane.');
    }

    public function updateLocations(
        Request $request,
        WarehouseDocumentSettingsService $documentSettings,
    ): RedirectResponse {
        $validated = $request->validate([
            'locations_text' => ['nullable', 'string', 'max:5000'],
        ]);

        $locations = preg_split('/[\r\n,;]+/', (string) ($validated['locations_text'] ?? '')) ?: [];
        $documentSettings->updateLocations($locations);

        return back()->with('status', 'Lokalizacje magazynowe zostały zapisane.');
    }

    public function updateDocumentAutomation(
        Request $request,
        DocumentAutomationSettingsService $automationSettings,
    ): RedirectResponse {
        $automationSettings->updateRules((array) $request->input('automation', []));

        return back()->with('status', 'Ustawienia automatycznego obiegu dokumentów zostały zapisane.');
    }

    public function updateReturns(
        Request $request,
        ReturnSettingsService $returnSettings,
    ): RedirectResponse {
        $validated = $request->validate([
            'numbering_pattern' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\/{}-]+$/'],
            'numbering_prefix' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_\/-]+$/'],
            'numbering_padding' => ['required', 'integer', 'min:3', 'max:9'],
            'default_target_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'default_condition' => ['required', 'string', 'max:40'],
            'default_disposition' => ['required', 'string', 'max:40'],
            'return_reasons' => ['nullable', 'array'],
            'return_reasons.*' => ['nullable', 'string', 'max:120'],
            'conditions' => ['required', 'array', 'min:1'],
            'conditions.*.code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'conditions.*.label' => ['nullable', 'string', 'max:80'],
            'dispositions' => ['required', 'array', 'min:1'],
            'dispositions.*.code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'dispositions.*.label' => ['nullable', 'string', 'max:80'],
            'dispositions.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'store_api_token' => ['nullable', 'string', 'max:120'],
            'store_webhook_secret' => ['nullable', 'string', 'max:120'],
        ]);

        $returnSettings->update($validated);

        return back()->with('status', 'Ustawienia zwrotów zostały zapisane.');
    }
}
