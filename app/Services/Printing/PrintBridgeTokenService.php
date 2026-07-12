<?php

declare(strict_types=1);

namespace App\Services\Printing;

use RuntimeException;

final class PrintBridgeTokenService
{
    public function token(): string
    {
        $configured = trim((string) config('erp.print_bridge_token', ''));

        if ($configured !== '') {
            return $configured;
        }

        $appKey = trim((string) config('app.key', ''));

        if ($appKey === '') {
            throw new RuntimeException('Brak APP_KEY potrzebnego do zabezpieczenia mostu wydruku.');
        }

        return hash_hmac('sha256', 'sempre-erp-print-bridge:v1', $appKey);
    }

    public function usesEnvironmentOverride(): bool
    {
        return trim((string) config('erp.print_bridge_token', '')) !== '';
    }
}
