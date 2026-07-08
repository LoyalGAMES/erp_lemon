<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Returns\ReturnSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyStoreReturnsToken
{
    public function __construct(
        private readonly ReturnSettingsService $settings,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) ($this->settings->data()['store_api_token'] ?? '');

        if ($configured === '') {
            return response()->json([
                'success' => false,
                'message' => 'API zwrotów nie jest skonfigurowane. Ustaw token w ustawieniach zwrotów ERP.',
            ], 403);
        }

        $provided = trim((string) ($request->bearerToken() ?? $request->header('X-API-Key', '')));

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Nieprawidłowy token API.',
            ], 401);
        }

        return $next($request);
    }
}
