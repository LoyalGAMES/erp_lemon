<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\WordpressIntegration;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWooCommerceCustomerWebhookSignature
{
    private const MAX_CLOCK_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $integration = $request->route('integration');

        if (! $integration instanceof WordpressIntegration) {
            return $this->unauthorized();
        }

        $timestamp = trim((string) $request->header('X-Lemon-Webhook-Timestamp', ''));
        $providedSignature = trim((string) $request->header('X-Lemon-Webhook-Signature', ''));

        if (! preg_match('/^[0-9]{10}$/', $timestamp)
            || abs(now()->getTimestamp() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS
            || $providedSignature === ''
        ) {
            return $this->unauthorized();
        }

        try {
            $secret = Crypt::decryptString((string) $integration->consumer_secret_encrypted);
        } catch (DecryptException) {
            return $this->unauthorized();
        }

        if ($secret === '') {
            return $this->unauthorized();
        }

        $expectedSignature = base64_encode(hash_hmac(
            'sha256',
            $timestamp.'.'.$request->getContent(),
            $secret,
            true,
        ));

        if (! hash_equals($expectedSignature, $providedSignature)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Nieprawidłowy albo wygasły podpis webhooka WooCommerce.',
        ], 401);
    }
}
