<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureValidCustomerAccountClaimSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasValidSignature()) {
            $response = response()->view('customer-account-claims.error', [
                'message' => 'Ten link jest nieprawidłowy albo wygasł. Poproś obsługę sklepu o ponowne wysłanie zaproszenia.',
                'storeUrl' => null,
            ], 403);

            return $this->preventCaching($response);
        }

        return $this->preventCaching($next($request));
    }

    private function preventCaching(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
