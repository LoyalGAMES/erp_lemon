<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourierAccount;
use App\Models\EmailTemplate;
use App\Models\Warehouse;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Communication\CustomerEmailWorkflowSettingsService;
use App\Services\Communication\EmailTemplateRenderer;
use App\Services\Communication\MailSettingsService;
use App\Services\Inventory\WarehouseDocumentSettingsService;
use App\Services\Packing\PackingSettingsService;
use App\Services\Payments\MbankTransferBasketSettingsService;
use App\Services\Payments\PayuRefundSettingsService;
use App\Services\Printing\PrintBridgeTokenService;
use App\Services\Products\ProductEditFieldSettingsService;
use App\Services\Returns\ReturnSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function mail(
        MailSettingsService $mailSettings,
        EmailTemplateRenderer $templateRenderer,
        CustomerEmailWorkflowSettingsService $mailWorkflow,
    ): View {
        return view('settings.mail', [
            'title' => 'Ustawienia maili',
            'subtitle' => 'Konfiguracja SMTP oraz szablony ręcznej komunikacji z klientami.',
            'module' => 'settings',
            'mailSettings' => $mailSettings->data(),
            'mailDeliverability' => $mailSettings->deliverabilityReport(),
            'mailWorkflow' => $mailWorkflow->data(),
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

    public function packing(
        PackingSettingsService $packingSettings,
        PrintBridgeTokenService $printBridgeTokens,
    ): View {
        return view('settings.packing', [
            'title' => 'Ustawienia pakowania',
            'subtitle' => 'Stanowiska pakowania, drukarki etykiet Zebra i podział asortymentu do kompletacji.',
            'module' => 'settings',
            'packingSettings' => $packingSettings->data(),
            'printListenerApp' => $this->windowsPrintListenerAppData(),
            'printBridge' => [
                'erp_url' => url('/'),
                'token' => $printBridgeTokens->token(),
                'environment_override' => $printBridgeTokens->usesEnvironmentOverride(),
            ],
        ]);
    }

    public function products(ProductEditFieldSettingsService $productEditFields): View
    {
        return view('settings.products', [
            'title' => 'Edycja produktów',
            'subtitle' => 'Wybierz pola widoczne podczas edycji pojedynczego towaru.',
            'module' => 'settings',
            'productEditFieldDefinitions' => $productEditFields->definitions(),
            'visibleProductEditFields' => $productEditFields->visibleFields(),
        ]);
    }

    public function updateProducts(Request $request, ProductEditFieldSettingsService $productEditFields): RedirectResponse
    {
        $validated = $request->validate([
            'visible_fields' => ['nullable', 'array'],
            'visible_fields.*' => ['string', 'max:100'],
        ]);

        $productEditFields->update((array) ($validated['visible_fields'] ?? []));

        return back()->with('status', 'Widoczność pól edycji produktu została zapisana.');
    }

    public function downloadWindowsPrintListener(): BinaryFileResponse
    {
        $release = $this->windowsPrintListenerRelease();

        if ($release === null) {
            abort(404, 'Podpisany instalator Windows nie jest dostępny na serwerze ERP.');
        }

        return response()->download($release['path'], $release['filename'], [
            'Content-Type' => 'application/vnd.microsoft.portable-executable',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Checksum-Sha256' => $release['sha256'],
        ]);
    }

    public function updatePacking(Request $request, PackingSettingsService $packingSettings): RedirectResponse
    {
        $data = $request->validate([
            'stations' => ['required', 'array', 'min:1', 'max:6'],
            'stations.*' => ['array'],
            'stations.*.code' => ['nullable', 'string', 'max:40'],
            'stations.*.name' => ['nullable', 'string', 'max:80'],
            'stations.*.printer_name' => ['nullable', 'string', 'max:120'],
            'stations.*.segment' => ['nullable', 'string', 'in:all,clothing,footwear'],
            'footwear_keywords' => ['nullable', 'string', 'max:2000'],
        ]);

        $stations = array_map(static fn (array $station): array => [
            'code' => $station['code'] ?? null,
            'name' => $station['name'] ?? null,
            'printer_name' => $station['printer_name'] ?? null,
            'segment' => $station['segment'] ?? null,
        ], $data['stations']);

        $packingSettings->update([
            'stations' => $stations,
            'footwear_keywords' => $data['footwear_keywords'] ?? null,
        ]);

        return back()->with('status', 'Ustawienia stanowisk pakowania i drukarek zostały zapisane.');
    }

    /**
     * @return array{available:bool,download_url:string,filename:string,size_mb:string|null,updated_at:string|null}
     */
    private function windowsPrintListenerAppData(): array
    {
        $release = $this->windowsPrintListenerRelease();
        $mtime = $release !== null ? filemtime($release['path']) : false;

        return [
            'available' => $release !== null,
            'download_url' => route('settings.packing.windows-listener.download', [
                'v' => $mtime ?: now()->timestamp,
            ]),
            'filename' => 'SempreERP-PrintListener-Setup.exe',
            'size_mb' => $release !== null ? number_format($release['size'] / 1048576, 1, ',', ' ').' MB' : null,
            'updated_at' => $mtime !== false ? date('Y-m-d H:i', $mtime) : null,
        ];
    }

    /**
     * Only expose the Setup.exe emitted by the signed release workflow. The
     * manifest gate prevents an unsigned validation build or the legacy raw
     * executable from becoming a production download by accident.
     *
     * @return array{path:string,filename:string,size:int,sha256:string}|null
     */
    private function windowsPrintListenerRelease(): ?array
    {
        $releaseDirectory = $this->windowsPrintListenerReleaseDirectory(
            base_path('tools/windows-print-listener/dist'),
        );
        if ($releaseDirectory === null) {
            return null;
        }

        $path = $releaseDirectory.'/SempreERP-PrintListener-Setup.exe';
        $manifestPath = $releaseDirectory.'/RELEASE-MANIFEST.json';

        if (! is_file($path) || ! is_readable($path) || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return null;
        }

        $rawManifest = file_get_contents($manifestPath);
        if ($rawManifest === false) {
            return null;
        }

        $rawManifest = preg_replace('/^\xEF\xBB\xBF/', '', $rawManifest) ?? $rawManifest;
        $manifest = json_decode($rawManifest, true);

        if (! is_array($manifest)
            || ($manifest['product'] ?? null) !== 'Sempre ERP Print Listener'
            || ($manifest['target'] ?? null) !== 'windows/amd64'
            || ($manifest['signed'] ?? null) !== true
            || ! is_array($manifest['artifacts'] ?? null)) {
            return null;
        }

        $installer = collect($manifest['artifacts'])
            ->first(fn (mixed $artifact): bool => is_array($artifact)
                && ($artifact['name'] ?? null) === 'SempreERP-PrintListener-Setup.exe');

        if (! is_array($installer)) {
            return null;
        }

        $expectedHash = mb_strtolower(trim((string) ($installer['sha256'] ?? '')));
        $size = filesize($path);
        $actualHash = hash_file('sha256', $path);

        if (preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1
            || $size === false
            || (int) ($installer['size'] ?? -1) !== $size
            || $actualHash === false
            || ! hash_equals($expectedHash, $actualHash)
            || ! $this->hasEmbeddedAuthenticodeSignature($path)) {
            return null;
        }

        return [
            'path' => $path,
            'filename' => 'SempreERP-PrintListener-Setup.exe',
            'size' => $size,
            'sha256' => $actualHash,
        ];
    }

    /**
     * Resolve only the complete versioned release selected by the atomically
     * replaced CURRENT pointer. A partial upload is never considered.
     */
    private function windowsPrintListenerReleaseDirectory(string $distPath): ?string
    {
        $pointerPath = $distPath.'/CURRENT';
        $pointerSize = is_file($pointerPath) ? filesize($pointerPath) : false;
        if ($pointerSize === false || $pointerSize < 1 || $pointerSize > 256) {
            return null;
        }

        $releaseId = trim((string) file_get_contents($pointerPath));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $releaseId) !== 1) {
            return null;
        }

        $releasesRoot = realpath($distPath.'/releases');
        if ($releasesRoot === false || ! is_dir($releasesRoot)) {
            return null;
        }

        $releaseDirectory = realpath($releasesRoot.DIRECTORY_SEPARATOR.$releaseId);
        $rootPrefix = rtrim($releasesRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($releaseDirectory === false
            || ! is_dir($releaseDirectory)
            || ! str_starts_with($releaseDirectory.DIRECTORY_SEPARATOR, $rootPrefix)) {
            return null;
        }

        return $releaseDirectory;
    }

    /**
     * Confirm that the PE contains a WIN_CERTIFICATE Authenticode table. Trust,
     * publisher and timestamp are verified by SignTool in the release workflow;
     * this server-side check prevents a plain/renamed EXE from being served.
     */
    private function hasEmbeddedAuthenticodeSignature(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        try {
            $dosHeader = fread($handle, 64);
            if ($dosHeader === false || strlen($dosHeader) !== 64 || substr($dosHeader, 0, 2) !== 'MZ') {
                return false;
            }

            $peOffset = unpack('Voffset', substr($dosHeader, 60, 4))['offset'] ?? null;
            if (! is_int($peOffset) || $peOffset < 64 || fseek($handle, $peOffset) !== 0) {
                return false;
            }

            $peAndFileHeader = fread($handle, 24);
            if ($peAndFileHeader === false
                || strlen($peAndFileHeader) !== 24
                || substr($peAndFileHeader, 0, 4) !== "PE\0\0") {
                return false;
            }

            $optionalHeaderSize = unpack('vsize', substr($peAndFileHeader, 20, 2))['size'] ?? 0;
            if (! is_int($optionalHeaderSize) || $optionalHeaderSize < 136) {
                return false;
            }

            $optionalHeader = fread($handle, $optionalHeaderSize);
            if ($optionalHeader === false || strlen($optionalHeader) !== $optionalHeaderSize) {
                return false;
            }

            $magic = unpack('vmagic', substr($optionalHeader, 0, 2))['magic'] ?? 0;
            $dataDirectoryOffset = match ($magic) {
                0x10B => 96,
                0x20B => 112,
                default => 0,
            };
            $securityDirectoryOffset = $dataDirectoryOffset + (4 * 8);
            if ($dataDirectoryOffset === 0 || $securityDirectoryOffset + 8 > $optionalHeaderSize) {
                return false;
            }

            $securityDirectory = unpack(
                'Vfile_offset/Vsize',
                substr($optionalHeader, $securityDirectoryOffset, 8),
            );
            $certificateOffset = (int) ($securityDirectory['file_offset'] ?? 0);
            $certificateSize = (int) ($securityDirectory['size'] ?? 0);
            $fileSize = filesize($path);

            if ($fileSize === false
                || $certificateOffset <= 0
                || $certificateSize < 8
                || $certificateOffset + $certificateSize > $fileSize
                || fseek($handle, $certificateOffset) !== 0) {
                return false;
            }

            $certificateHeader = fread($handle, 8);
            if ($certificateHeader === false || strlen($certificateHeader) !== 8) {
                return false;
            }
            $certificate = unpack('Vlength/vrevision/vtype', $certificateHeader);
            $length = (int) ($certificate['length'] ?? 0);

            return $length >= 8
                && $length <= $certificateSize
                && $certificateOffset + $length <= $fileSize
                && (int) ($certificate['revision'] ?? 0) === 0x0200
                && (int) ($certificate['type'] ?? 0) === 0x0002;
        } finally {
            fclose($handle);
        }
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

    public function updateMailWorkflow(Request $request, CustomerEmailWorkflowSettingsService $mailWorkflow): RedirectResponse
    {
        $validated = $request->validate([
            'workflow' => ['nullable', 'array'],
            'workflow.*.enabled' => ['nullable', 'boolean'],
            'workflow.*.stage' => ['nullable', 'string', 'max:160'],
            'workflow.*.subject' => ['nullable', 'string', 'max:160'],
            'workflow.*.body' => ['nullable', 'string', 'max:5000'],
        ]);

        $mailWorkflow->update((array) ($validated['workflow'] ?? []));

        return back()->with('status', 'Workflow maili do klientów został zapisany.');
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
            'returnSettings' => $returnSettings->publicData(),
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
            'clear_store_api_token' => ['nullable', 'boolean'],
            'clear_store_webhook_secret' => ['nullable', 'boolean'],
        ]);

        $returnSettings->update($validated);

        return back()->with('status', 'Ustawienia zwrotów zostały zapisane.');
    }
}
