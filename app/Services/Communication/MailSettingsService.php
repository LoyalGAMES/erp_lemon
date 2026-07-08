<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\AppSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

final class MailSettingsService
{
    private const KEY = 'mail_settings';

    /**
     * @return array{
     *     enabled:bool,
     *     host:string,
     *     port:int,
     *     encryption:string,
     *     username:string,
     *     from_address:string,
     *     from_name:string,
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
     *     support_phone:string
     * }
     */
    public function data(): array
    {
        $stored = AppSetting::query()
            ->where('key', self::KEY)
            ->value('value');

        $data = array_merge($this->defaults(), is_array($stored) ? $stored : []);
        $encryption = in_array($data['encryption'] ?? null, ['none', 'tls', 'ssl'], true)
            ? (string) $data['encryption']
            : 'tls';
        $fromAddress = trim((string) ($data['from_address'] ?? config('mail.from.address', '')));
        $fromName = trim((string) ($data['from_name'] ?? config('mail.from.name', config('app.name', 'Sempre ERP'))));

        return [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($data['port'] ?? 587))),
            'encryption' => $encryption,
            'username' => trim((string) ($data['username'] ?? '')),
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'ehlo_domain' => trim((string) ($data['ehlo_domain'] ?? '')),
            'timeout' => max(3, min(120, (int) ($data['timeout'] ?? 15))),
            'password_configured' => filled($data['password_encrypted'] ?? null),
            'brand_name' => trim((string) ($data['brand_name'] ?? $fromName)),
            'logo_url' => trim((string) ($data['logo_url'] ?? '')),
            'accent_color' => $this->color((string) ($data['accent_color'] ?? '#2f6f4f')),
            'header_text' => trim((string) ($data['header_text'] ?? 'Informacja o zamówieniu')),
            'signature' => trim((string) ($data['signature'] ?? "Pozdrawiamy,\nZespół Sempre")),
            'footer_text' => trim((string) ($data['footer_text'] ?? 'Ta wiadomość została wysłana automatycznie przez system obsługi zamówień.')),
            'support_email' => trim((string) ($data['support_email'] ?? $fromAddress)),
            'support_phone' => trim((string) ($data['support_phone'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $data
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

        $payload = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'host' => trim((string) ($data['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($data['port'] ?? 587))),
            'encryption' => $encryption,
            'username' => trim((string) ($data['username'] ?? '')),
            'password_encrypted' => $passwordEncrypted,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
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

        if (! $settings['enabled']) {
            return false;
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

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'enabled' => false,
            'host' => (string) config('mail.mailers.smtp.host', ''),
            'port' => (int) config('mail.mailers.smtp.port', 587),
            'encryption' => (int) config('mail.mailers.smtp.port', 587) === 465 ? 'ssl' : 'tls',
            'username' => (string) config('mail.mailers.smtp.username', ''),
            'password_encrypted' => null,
            'from_address' => (string) config('mail.from.address', ''),
            'from_name' => (string) config('mail.from.name', config('app.name', 'Sempre ERP')),
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
