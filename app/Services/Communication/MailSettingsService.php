<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\AppSetting;
use App\Models\GoogleMailConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

final class MailSettingsService
{
    public const DELIVERY_SMTP = 'smtp';

    public const DELIVERY_GOOGLE_WORKSPACE = 'google_workspace';

    private const KEY = 'mail_settings';

    /**
     * @return array{
     *     enabled:bool,
     *     delivery_method:string,
     *     delivery_ready:bool,
     *     delivery_issue:?string,
     *     host:string,
     *     port:int,
     *     encryption:string,
     *     username:string,
     *     from_address:string,
     *     from_name:string,
     *     reply_to_address:string,
     *     ehlo_domain:string,
     *     timeout:int,
     *     password_configured:bool,
     *     brand_name:string,
     *     logo_url:string,
     *     accent_color:string,
     *     header_text:string,
     *     signature:string,
     *     footer_text:string,
     *     support_email:string,
     *     support_phone:string,
     *     google_oauth_configured:bool,
     *     google_client_secret_configured:bool,
     *     google_connected:bool,
     *     google_reauthorization_required:bool,
     *     google_account_email:string,
     *     google_client_id:string,
     *     google_redirect_uri:string
     * }
     */
    public function data(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');

        $stored = is_array($stored) ? $stored : [];
        $data = array_merge($this->defaults(), $stored);
        $encryption = in_array($data['encryption'] ?? null, ['none', 'tls', 'ssl'], true)
            ? (string) $data['encryption']
            : 'tls';
        $fromAddress = trim((string) ($data['from_address'] ?? config('mail.from.address', '')));
        $fromName = trim((string) ($data['from_name'] ?? config('mail.from.name', config('app.name', 'Sempre ERP'))));
        $supportEmail = trim((string) ($data['support_email'] ?? $fromAddress));
        $replyToAddress = array_key_exists('reply_to_address', $stored)
            ? trim((string) $stored['reply_to_address'])
            : $supportEmail;
        $deliveryMethod = in_array($data['delivery_method'] ?? null, [
            self::DELIVERY_SMTP,
            self::DELIVERY_GOOGLE_WORKSPACE,
        ], true)
            ? (string) $data['delivery_method']
            : self::DELIVERY_SMTP;
        $enabled = array_key_exists('delivery_enabled', $stored)
            ? (bool) $stored['delivery_enabled']
            : (bool) ($data['enabled'] ?? false);
        $googleConnection = GoogleMailConnection::query()
            ->where('purpose', GoogleMailConnection::PURPOSE_TRANSACTIONAL_MAIL)
            ->first();
        $storedGoogleClientId = trim((string) ($googleConnection?->client_id ?? ''));
        $googleClientId = $storedGoogleClientId !== ''
            ? $storedGoogleClientId
            : trim((string) config('services.google_workspace.client_id', ''));
        $googleClientSecretConfigured = $googleConnection?->clientSecret() !== null
            || filled(config('services.google_workspace.client_secret'));
        $googleOauthConfigured = $googleClientId !== '' && $googleClientSecretConfigured;
        $googleConnected = $googleOauthConfigured
            && $googleConnection instanceof GoogleMailConnection
            && $googleConnection->isUsable();
        $host = trim((string) ($data['host'] ?? ''));
        $deliveryIssue = $this->deliveryIssue(
            $enabled,
            $deliveryMethod,
            $fromAddress,
            $host,
            $googleOauthConfigured,
            $googleConnected,
        );

        return [
            'enabled' => $enabled,
            'delivery_method' => $deliveryMethod,
            'delivery_ready' => $deliveryIssue === null,
            'delivery_issue' => $deliveryIssue,
            'host' => $host,
            'port' => max(1, min(65535, (int) ($data['port'] ?? 587))),
            'encryption' => $encryption,
            'username' => trim((string) ($data['username'] ?? '')),
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'reply_to_address' => $replyToAddress,
            'ehlo_domain' => trim((string) ($data['ehlo_domain'] ?? '')),
            'timeout' => max(3, min(120, (int) ($data['timeout'] ?? 15))),
            'password_configured' => filled($data['password_encrypted'] ?? null),
            'brand_name' => trim((string) ($data['brand_name'] ?? $fromName)),
            'logo_url' => trim((string) ($data['logo_url'] ?? '')),
            'accent_color' => $this->color((string) ($data['accent_color'] ?? '#2f6f4f')),
            'header_text' => trim((string) ($data['header_text'] ?? 'Informacja o zamówieniu')),
            'signature' => trim((string) ($data['signature'] ?? "Pozdrawiamy,\nZespół Sempre")),
            'footer_text' => trim((string) ($data['footer_text'] ?? 'Ta wiadomość została wysłana automatycznie przez system obsługi zamówień.')),
            'support_email' => $supportEmail,
            'support_phone' => trim((string) ($data['support_phone'] ?? '')),
            'google_oauth_configured' => $googleOauthConfigured,
            'google_client_secret_configured' => $googleClientSecretConfigured,
            'google_connected' => $googleConnected,
            'google_reauthorization_required' => $googleConnection?->reauthorization_required_at !== null,
            'google_account_email' => (string) ($googleConnection?->email ?? ''),
            'google_client_id' => $googleClientId,
            'google_redirect_uri' => route('settings.mail.google.callback'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');
        $stored = is_array($stored) ? $stored : [];

        $password = trim((string) ($data['password'] ?? ''));
        $passwordEncrypted = $stored['password_encrypted'] ?? null;

        if ($password !== '') {
            $passwordEncrypted = Crypt::encryptString($password);
        } elseif ((bool) ($data['clear_password'] ?? false)) {
            $passwordEncrypted = null;
        }

        $encryption = in_array($data['encryption'] ?? null, ['none', 'tls', 'ssl'], true)
            ? (string) $data['encryption']
            : 'tls';

        $fromAddress = trim((string) ($data['from_address'] ?? ''));
        $fromName = trim((string) ($data['from_name'] ?? ''));
        $brandName = array_key_exists('brand_name', $data)
            ? trim((string) $data['brand_name'])
            : $fromName;
        $headerText = array_key_exists('header_text', $data)
            ? trim((string) $data['header_text'])
            : 'Informacja o zamówieniu';
        $signature = array_key_exists('signature', $data)
            ? trim((string) $data['signature'])
            : "Pozdrawiamy,\nZespół Sempre";
        $footerText = array_key_exists('footer_text', $data)
            ? trim((string) $data['footer_text'])
            : 'Ta wiadomość została wysłana automatycznie przez system obsługi zamówień.';
        $supportEmail = array_key_exists('support_email', $data)
            ? trim((string) $data['support_email'])
            : $fromAddress;
        $replyToAddress = array_key_exists('reply_to_address', $data)
            ? trim((string) $data['reply_to_address'])
            : (array_key_exists('reply_to_address', $stored)
                ? trim((string) $stored['reply_to_address'])
                : $supportEmail);
        $deliveryMethod = in_array($data['delivery_method'] ?? ($stored['delivery_method'] ?? null), [
            self::DELIVERY_SMTP,
            self::DELIVERY_GOOGLE_WORKSPACE,
        ], true)
            ? (string) ($data['delivery_method'] ?? $stored['delivery_method'])
            : self::DELIVERY_SMTP;
        $deliveryEnabled = (bool) ($data['enabled'] ?? false);

        $payload = [
            // Older releases only understand "enabled" and always use SMTP.
            // Keep it off for Gmail API so a code rollback cannot silently send through SMTP.
            'enabled' => $deliveryMethod === self::DELIVERY_SMTP && $deliveryEnabled,
            'delivery_enabled' => $deliveryEnabled,
            'delivery_method' => $deliveryMethod,
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($data['port'] ?? 587))),
            'encryption' => $encryption,
            'username' => trim((string) ($data['username'] ?? '')),
            'password_encrypted' => $passwordEncrypted,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'reply_to_address' => mb_substr($replyToAddress, 0, 255),
            'ehlo_domain' => trim((string) ($data['ehlo_domain'] ?? '')),
            'timeout' => max(3, min(120, (int) ($data['timeout'] ?? 15))),
            'brand_name' => mb_substr($brandName, 0, 120),
            'logo_url' => mb_substr(trim((string) ($data['logo_url'] ?? '')), 0, 1000),
            'accent_color' => $this->color((string) ($data['accent_color'] ?? '#2f6f4f')),
            'header_text' => mb_substr($headerText, 0, 160),
            'signature' => mb_substr($signature, 0, 1000),
            'footer_text' => mb_substr($footerText, 0, 1000),
            'support_email' => mb_substr($supportEmail, 0, 255),
            'support_phone' => mb_substr(trim((string) ($data['support_phone'] ?? '')), 0, 40),
        ];

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $payload],
        );

        return $payload;
    }

    public function apply(): bool
    {
        $settings = $this->data();

        if (! $settings['enabled'] || ! $settings['delivery_ready']) {
            return false;
        }

        if ($settings['delivery_method'] === self::DELIVERY_GOOGLE_WORKSPACE) {
            config([
                'mail.default' => 'google_workspace',
                'mail.mailers.google_workspace.transport' => 'gmail_api',
                'mail.from.address' => $settings['from_address'],
                'mail.from.name' => $settings['from_name'],
            ]);

            Mail::purge('google_workspace');

            return true;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => $this->scheme($settings['encryption']),
            'mail.mailers.smtp.url' => null,
            'mail.mailers.smtp.host' => $settings['host'],
            'mail.mailers.smtp.port' => $settings['port'],
            'mail.mailers.smtp.username' => $settings['username'] !== '' ? $settings['username'] : null,
            'mail.mailers.smtp.password' => $this->password(),
            'mail.mailers.smtp.timeout' => $settings['timeout'],
            'mail.mailers.smtp.local_domain' => $settings['ehlo_domain'] !== ''
                ? $settings['ehlo_domain']
                : parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST),
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'],
        ]);

        Mail::purge('smtp');

        return true;
    }

    /**
     * @return array{
     *     from_domain:?string,
     *     username_domain:?string,
     *     ehlo_domain:string,
     *     checks:array<int, array{status:string,title:string,description:string}>
     * }
     */
    public function deliverabilityReport(): array
    {
        $settings = $this->data();
        $fromDomain = $this->emailDomain($settings['from_address']);
        $googleDomain = $this->emailDomain($settings['google_account_email']);
        $usernameDomain = $this->emailDomain($settings['username']);
        $ehloDomain = $settings['ehlo_domain'] !== ''
            ? $settings['ehlo_domain']
            : (string) parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST);
        $checks = [];

        if ($settings['delivery_method'] === self::DELIVERY_GOOGLE_WORKSPACE) {
            if (! $settings['enabled']) {
                $checks[] = [
                    'status' => 'warn',
                    'title' => 'Wysyłka poczty jest wyłączona',
                    'description' => 'Maile transakcyjne pozostaną w kolejce do czasu włączenia wysyłki.',
                ];
            }

            if (! $settings['google_oauth_configured']) {
                $checks[] = [
                    'status' => 'warn',
                    'title' => 'Brak klienta Google OAuth',
                    'description' => 'Uzupełnij identyfikator klienta i sekret klienta Google OAuth w panelu.',
                ];
            } elseif (! $settings['google_connected']) {
                $checks[] = [
                    'status' => 'warn',
                    'title' => 'Konto Google Workspace nie jest połączone',
                    'description' => $settings['google_reauthorization_required']
                        ? 'Dostęp wygasł lub został cofnięty. Połącz konto ponownie.'
                        : 'Zapisz ustawienia i użyj przycisku „Połącz konto Google”.',
                ];
            } else {
                $checks[] = [
                    'status' => 'ok',
                    'title' => 'Gmail API',
                    'description' => 'Połączone konto: '.$settings['google_account_email'].'.',
                ];
            }

            if ($fromDomain !== null && $googleDomain !== null && $fromDomain !== $googleDomain) {
                $checks[] = [
                    'status' => 'warn',
                    'title' => 'Nadawca jest w innej domenie',
                    'description' => 'Adres nadawcy musi być kontem Google lub skonfigurowanym aliasem „Wyślij jako”.',
                ];
            } elseif ($settings['google_connected']
                && mb_strtolower($settings['from_address']) !== mb_strtolower($settings['google_account_email'])) {
                $checks[] = [
                    'status' => 'info',
                    'title' => 'Wysyłka z aliasu',
                    'description' => 'Sprawdź w Gmailu, czy '.$settings['from_address'].' jest aktywnym aliasem „Wyślij jako”.',
                ];
            }

            $checks[] = [
                'status' => 'info',
                'title' => 'Minimalny dostęp',
                'description' => 'Integracja prosi wyłącznie o zakres gmail.send oraz identyfikację połączonego konta.',
            ];
            $checks[] = [
                'status' => 'info',
                'title' => 'DKIM i SPF',
                'description' => 'Google Workspace podpisuje wiadomości zgodnie z konfiguracją domeny w konsoli administratora.',
            ];

            return [
                'from_domain' => $fromDomain,
                'username_domain' => $googleDomain,
                'ehlo_domain' => '',
                'checks' => $checks,
            ];
        }

        if (! $settings['enabled']) {
            $checks[] = [
                'status' => 'warn',
                'title' => 'Wysyłka SMTP jest wyłączona',
                'description' => 'Maile transakcyjne nie będą wysyłane przez zapisane konto SMTP.',
            ];
        }

        if ($fromDomain === null) {
            $checks[] = [
                'status' => 'warn',
                'title' => 'Brak poprawnej domeny nadawcy',
                'description' => 'Ustaw adres nadawcy w domenie sklepu, np. powiadomienia@twojadomena.pl.',
            ];
        } else {
            $checks[] = [
                'status' => 'info',
                'title' => 'DNS domeny '.$fromDomain,
                'description' => 'W DNS tej domeny muszą być poprawne rekordy SPF, DKIM i DMARC dla serwera SMTP, z którego wysyłasz.',
            ];
        }

        if ($fromDomain !== null && $usernameDomain !== null && $fromDomain !== $usernameDomain) {
            $checks[] = [
                'status' => 'warn',
                'title' => 'Login SMTP jest w innej domenie',
                'description' => "Login SMTP używa domeny {$usernameDomain}, a nadawca {$fromDomain}. Jeśli dostawca SMTP nie podpisuje domeny nadawcy DKIM/SPF, Gmail może wrzucać wiadomości do spamu.",
            ];
        }

        if ($ehloDomain === '' || in_array($ehloDomain, ['localhost', '127.0.0.1'], true)) {
            $checks[] = [
                'status' => 'warn',
                'title' => 'Domena EHLO nie wygląda produkcyjnie',
                'description' => 'Ustaw EHLO na domenę hosta lub subdomenę powiązaną z ERP, np. erp.twojadomena.pl.',
            ];
        } else {
            $checks[] = [
                'status' => 'ok',
                'title' => 'EHLO',
                'description' => 'Serwer przedstawi się jako '.$ehloDomain.'.',
            ];
        }

        $checks[] = [
            'status' => 'info',
            'title' => 'DKIM',
            'description' => 'DKIM musi być włączony u dostawcy SMTP. Aplikacja nie podpisuje DKIM lokalnie.',
        ];
        $checks[] = [
            'status' => 'info',
            'title' => 'DMARC',
            'description' => 'Dodaj DMARC dla domeny nadawcy, minimum p=none na start, potem zaostrzaj politykę po testach.',
        ];

        return [
            'from_domain' => $fromDomain,
            'username_domain' => $usernameDomain,
            'ehlo_domain' => $ehloDomain,
            'checks' => $checks,
        ];
    }

    private function password(): ?string
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');

        $encrypted = is_array($stored) ? ($stored['password_encrypted'] ?? null) : null;

        if (! filled($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    private function scheme(string $encryption): string
    {
        return $encryption === 'ssl' ? 'smtps' : 'smtp';
    }

    private function color(string $color): string
    {
        $color = trim($color);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#2f6f4f';
    }

    private function emailDomain(string $email): ?string
    {
        $email = trim($email);

        if (! str_contains($email, '@')) {
            return null;
        }

        $domain = mb_strtolower(trim((string) substr(strrchr($email, '@') ?: '', 1)));

        return $domain !== '' ? $domain : null;
    }

    private function deliveryIssue(
        bool $enabled,
        string $deliveryMethod,
        string $fromAddress,
        string $host,
        bool $googleOauthConfigured,
        bool $googleConnected,
    ): ?string {
        if (! $enabled) {
            return $deliveryMethod === self::DELIVERY_GOOGLE_WORKSPACE
                ? 'Wysyłka przez Gmail API jest wyłączona.'
                : 'SMTP jest wyłączone.';
        }

        if ($fromAddress === '') {
            return 'Uzupełnij adres nadawcy.';
        }

        if ($deliveryMethod === self::DELIVERY_GOOGLE_WORKSPACE) {
            if (! $googleOauthConfigured) {
                return 'Uzupełnij identyfikator klienta i sekret klienta Google OAuth.';
            }

            if (! $googleConnected) {
                return 'Konto Google Workspace nie jest połączone lub wymaga ponownej autoryzacji.';
            }

            return null;
        }

        return $host !== '' ? null : 'Uzupełnij host SMTP.';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'enabled' => false,
            'delivery_enabled' => false,
            'delivery_method' => self::DELIVERY_SMTP,
            'host' => (string) config('mail.mailers.smtp.host', ''),
            'port' => (int) config('mail.mailers.smtp.port', 587),
            'encryption' => (int) config('mail.mailers.smtp.port', 587) === 465 ? 'ssl' : 'tls',
            'username' => (string) config('mail.mailers.smtp.username', ''),
            'password_encrypted' => null,
            'from_address' => (string) config('mail.from.address', ''),
            'from_name' => (string) config('mail.from.name', config('app.name', 'Sempre ERP')),
            'reply_to_address' => '',
            'ehlo_domain' => (string) config('mail.mailers.smtp.local_domain', ''),
            'timeout' => 15,
            'brand_name' => (string) config('mail.from.name', config('app.name', 'Sempre ERP')),
            'logo_url' => '',
            'accent_color' => '#2f6f4f',
            'header_text' => 'Informacja o zamówieniu',
            'signature' => "Pozdrawiamy,\nZespół Sempre",
            'footer_text' => 'Ta wiadomość została wysłana automatycznie przez system obsługi zamówień.',
            'support_email' => (string) config('mail.from.address', ''),
            'support_phone' => '',
        ];
    }
}
