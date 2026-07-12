<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Printing\PrintBridgeTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPrintBridgeToken
{
    public function __construct(
        private readonly PrintBridgeTokenService $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configured = $this->tokens->token();

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
