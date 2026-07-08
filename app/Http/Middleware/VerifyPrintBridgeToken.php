<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPrintBridgeToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = trim((string) config('erp.print_bridge_token', ''));

        if ($configured === '') {
            return response()->json([
                'success' => false,
                'message' => 'Most wydruku nie jest skonfigurowany. Ustaw PRINT_BRIDGE_TOKEN w .env ERP.',
            ], 403);
        }

        $provided = trim((string) ($request->bearerToken() ?? $request->header('X-API-Key', '')));

        if ($provided === '' || ! hash_equals($configured, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Nieprawidłowy token mostu wydruku.',
            ], 401);
        }

        return $next($request);
    }
}
