<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSyncLog;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceCustomerWebhookService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

final class WooCommerceCustomerWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WordpressIntegration $integration,
        WooCommerceCustomerWebhookService $webhooks,
    ): JsonResponse {
        $validator = Validator::make($request->json()->all(), [
            'event' => ['required', 'string', Rule::in(['customer.created', 'customer.updated'])],
            'event_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'occurred_at' => ['required', 'date'],
            'store_url' => ['required', 'url:http,https', 'max:255'],
            'customer_id' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook klienta ma nieprawidłowy format.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $payload = $validator->validated();

        if (! $this->headersMatchPayload($request, $payload)
            || ! $this->sameStore((string) $integration->base_url, (string) $payload['store_url'])
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook nie pasuje do wskazanej integracji WooCommerce.',
            ], 422);
        }

        $eventId = (string) $payload['event_id'];
        $processedKey = $this->cacheKey($integration, $eventId, 'processed');

        if (Cache::get($processedKey) === true) {
            return response()->json([
                'success' => true,
                'duplicate' => true,
                'event_id' => $eventId,
            ]);
        }

        $lock = Cache::lock($this->cacheKey($integration, $eventId, 'lock'), 120);

        if (! $lock->get()) {
            return response()->json([
                'success' => true,
                'processing' => true,
                'event_id' => $eventId,
            ], 202);
        }

        $log = null;

        try {
            if (Cache::get($processedKey) === true) {
                return response()->json([
                    'success' => true,
                    'duplicate' => true,
                    'event_id' => $eventId,
                ]);
            }

            $log = IntegrationSyncLog::query()->create([
                'sales_channel_id' => $integration->sales_channel_id,
                'wordpress_integration_id' => $integration->id,
                'direction' => 'in',
                'operation' => 'customer_webhook',
                'status' => 'running',
                'external_resource' => 'customer',
                'external_id' => $eventId,
                'request_payload' => [
                    'event' => $payload['event'],
                    'event_id' => $eventId,
                    'occurred_at' => $payload['occurred_at'],
                    'customer_id' => (string) $payload['customer_id'],
                ],
                'attempts' => 1,
                'started_at' => now(),
            ]);
            $result = $webhooks->process(
                $integration,
                (string) $payload['event'],
                (string) $payload['customer_id'],
                CarbonImmutable::parse((string) $payload['occurred_at']),
            );

            $log->update([
                'status' => 'success',
                'response_payload' => $result,
                'finished_at' => now(),
            ]);
            Cache::put($processedKey, true, now()->addDays(7));

            return response()->json([
                'success' => true,
                'duplicate' => false,
                'event_id' => $eventId,
                'result' => $result,
            ]);
        } catch (Throwable $exception) {
            $log?->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);

            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'Synchronizacja klienta nie powiodła się i webhook zostanie ponowiony.',
                'event_id' => $eventId,
            ], 503);
        } finally {
            $lock->release();
        }
    }

    /** @param array<string, mixed> $payload */
    private function headersMatchPayload(Request $request, array $payload): bool
    {
        return trim((string) $request->header('X-Lemon-Webhook-Version', '')) === '1'
            && hash_equals((string) $payload['event'], trim((string) $request->header('X-Lemon-Webhook-Event', '')))
            && hash_equals((string) $payload['event_id'], trim((string) $request->header('X-Lemon-Webhook-Id', '')));
    }

    private function sameStore(string $configured, string $provided): bool
    {
        return $this->canonicalStoreUrl($configured) === $this->canonicalStoreUrl($provided);
    }

    private function canonicalStoreUrl(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || blank($parts['scheme'] ?? null) || blank($parts['host'] ?? null)) {
            return '';
        }

        $scheme = mb_strtolower((string) $parts['scheme']);
        $host = mb_strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $includePort = $port !== null
            && ! ($scheme === 'https' && $port === 443)
            && ! ($scheme === 'http' && $port === 80);
        $path = '/'.trim((string) ($parts['path'] ?? ''), '/');

        return $scheme.'://'.$host.($includePort ? ':'.$port : '').rtrim($path, '/');
    }

    private function cacheKey(WordpressIntegration $integration, string $eventId, string $suffix): string
    {
        return 'woocommerce-customer-webhook:'.$integration->id.':'.hash('sha256', $eventId).':'.$suffix;
    }
}
