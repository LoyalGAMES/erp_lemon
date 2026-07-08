<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\IntegrationSyncLog;
use App\Models\ReturnCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Wypycha status zwrotu do wtyczki lemon-woo-returns
 * (POST {site_url}/wp-json/lemon-returns/v1/status), żeby sklep od razu
 * utworzył natywny refund WooCommerce zamiast czekać na cron.
 */
final class ReturnStatusPushService
{
    public function __construct(
        private readonly ReturnSettingsService $settings,
        private readonly StoreReturnIntakeService $intake,
    ) {
    }

    public function canPush(ReturnCase $returnCase): bool
    {
        return filled(data_get($returnCase->metadata, 'site_url'))
            && filled(data_get($returnCase->metadata, 'return_reference'))
            && (string) ($this->settings->data()['store_webhook_secret'] ?? '') !== '';
    }

    /**
     * @throws RuntimeException gdy sklep nie przyjął statusu
     */
    public function push(ReturnCase $returnCase): void
    {
        $siteUrl = rtrim((string) data_get($returnCase->metadata, 'site_url'), '/');
        $secret = (string) ($this->settings->data()['store_webhook_secret'] ?? '');

        if ($siteUrl === '' || $secret === '') {
            throw new RuntimeException('Brak adresu sklepu albo sekretu webhooka w konfiguracji.');
        }

        $payload = [
            'return_reference' => (string) data_get($returnCase->metadata, 'return_reference'),
            'external_id' => $returnCase->number,
            'status' => $this->intake->statusForStore($returnCase),
        ];

        $log = IntegrationSyncLog::query()->create([
            'direction' => 'outbound',
            'operation' => 'return_status_push',
            'status' => 'pending',
            'external_resource' => 'return_case',
            'external_id' => $returnCase->number,
            'request_payload' => $payload,
            'attempts' => 1,
            'started_at' => now(),
        ]);

        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Lemon-Returns-Token' => $secret])
                ->acceptJson()
                ->post($siteUrl.'/wp-json/lemon-returns/v1/status', $payload);
        } catch (Throwable $exception) {
            $log->update([
                'status' => 'error',
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);

            throw new RuntimeException('Nie udało się połączyć ze sklepem: '.$exception->getMessage());
        }

        if ($response->failed()) {
            $log->update([
                'status' => 'error',
                'response_payload' => $response->json() ?? ['body' => mb_substr($response->body(), 0, 1000)],
                'error_message' => 'HTTP '.$response->status(),
                'finished_at' => now(),
            ]);

            throw new RuntimeException("Sklep odrzucił aktualizację statusu (HTTP {$response->status()}).");
        }

        $log->update([
            'status' => 'success',
            'response_payload' => $response->json(),
            'finished_at' => now(),
        ]);
    }
}
