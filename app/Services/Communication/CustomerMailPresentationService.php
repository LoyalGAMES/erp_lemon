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
            'accentLinkColor' => $this->readableAccentColor($layout['accent_color']),
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

    private function readableAccentColor(string $hex): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return '#2f6f4f';
        }

        $accent = $this->hexToRgb($hex);
        $backgrounds = array_map(
            fn (string $background): array => $this->hexToRgb($background),
            ['#ffffff', '#f6f6f3', '#e8efeb'],
        );

        for ($percentage = 100; $percentage >= 0; $percentage--) {
            $candidate = array_map(
                static fn (int $channel): int => (int) round($channel * ($percentage / 100)),
                $accent,
            );

            $readable = true;

            foreach ($backgrounds as $background) {
                if ($this->contrastRatio($candidate, $background) < 4.5) {
                    $readable = false;

                    break;
                }
            }

            if ($readable) {
                return sprintf('#%02x%02x%02x', ...$candidate);
            }
        }

        return '#171717';
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $foreground
     * @param  array{0: int, 1: int, 2: int}  $background
     */
    private function contrastRatio(array $foreground, array $background): float
    {
        $lighter = max($this->relativeLuminance($foreground), $this->relativeLuminance($background));
        $darker = min($this->relativeLuminance($foreground), $this->relativeLuminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    private function relativeLuminance(array $rgb): float
    {
        [$red, $green, $blue] = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return (0.2126 * $red) + (0.7152 * $green) + (0.0722 * $blue);
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
