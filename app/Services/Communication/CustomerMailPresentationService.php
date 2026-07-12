<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\CustomerMessage;
use Illuminate\Support\Facades\View;

final class CustomerMailPresentationService
{
    public function __construct(
        private readonly MailSettingsService $mailSettings,
    ) {}

    /**
     * @param  array<string, mixed>|null  $layoutOverride
     * @return array<string, mixed>
     */
    public function data(CustomerMessage $message, ?array $layoutOverride = null): array
    {
        $layout = $this->layout($layoutOverride);

        return [
            'customerMessage' => $message,
            'messageSubject' => $message->renderedSubject(),
            'messageBody' => $message->renderedBody(),
            'mailLayout' => $layout,
            'accentForeground' => $this->contrastColor($layout['accent_color']),
        ];
    }

    /** @param array<string, mixed>|null $layoutOverride */
    public function html(CustomerMessage $message, ?array $layoutOverride = null): string
    {
        return View::make('emails.customer-message', $this->data($message, $layoutOverride))->render();
    }

    /** @param array<string, mixed>|null $layoutOverride */
    public function text(CustomerMessage $message, ?array $layoutOverride = null): string
    {
        return View::make('emails.customer-message-text', $this->data($message, $layoutOverride))->render();
    }

    /**
     * @param  array<string, mixed>|null  $override
     * @return array<string, mixed>
     */
    private function layout(?array $override): array
    {
        $layout = $this->mailSettings->data();

        if ($override === null) {
            return $layout;
        }

        foreach ([
            'brand_name' => 120,
            'logo_url' => 1000,
            'accent_color' => 7,
            'header_text' => 160,
            'signature' => 1000,
            'footer_text' => 1000,
            'support_email' => 255,
            'support_phone' => 40,
        ] as $key => $limit) {
            if (! array_key_exists($key, $override)) {
                continue;
            }

            $value = mb_substr(trim((string) $override[$key]), 0, $limit);

            if ($key === 'accent_color' && preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                continue;
            }

            if ($key === 'logo_url' && $value !== '' && $this->httpUrl($value) === null) {
                continue;
            }

            if ($key === 'support_email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $layout[$key] = $value;
        }

        return $layout;
    }

    private function contrastColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));
        $luminance = (($red * 299) + ($green * 587) + ($blue * 114)) / 1000;

        return $luminance > 155 ? '#171717' : '#ffffff';
    }

    private function httpUrl(string $url): ?string
    {
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true)
                ? $url
                : null;
    }
}
