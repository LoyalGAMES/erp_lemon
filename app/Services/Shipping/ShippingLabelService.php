<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\ShippingLabel;
use App\Models\WordpressIntegration;
use App\Services\Audit\AuditLogService;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ShippingLabelService
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly AuditLogService $audit,
    ) {
    }

    public function generateForOrder(ExternalOrder $order): ShippingLabel
    {
        $order = ExternalOrder::query()
            ->with('salesChannel')
            ->findOrFail($order->id);

        $integration = $this->integrationForOrder($order);
        $startedAt = now();

        try {
            $labelData = $this->client->generateShippingLabel(
                $integration,
                (string) $order->external_id,
                (string) $order->external_number,
            );

            $contents = (string) $labelData['contents'];
            $mimeType = (string) ($labelData['mime_type'] ?? 'application/pdf');
            $filename = $this->filename($order, $labelData, $mimeType);
            $path = 'shipping-labels/' . now()->format('Y/m') . '/' . $filename;

            Storage::disk('local')->put($path, $contents);

            $label = ShippingLabel::query()->create([
                'sales_channel_id' => $order->sales_channel_id,
                'external_order_id' => $order->id,
                'wordpress_integration_id' => $integration->id,
                'status' => 'generated',
                'provider' => $this->stringFromPayload($labelData, ['provider', 'carrier', 'shipping_provider']),
                'label_number' => $this->stringFromPayload($labelData, ['label_number', 'label_id', 'id']),
                'tracking_number' => $this->stringFromPayload($labelData, ['tracking_number', 'tracking', 'tracking_code']),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $mimeType,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'source_url' => (string) ($labelData['source_url'] ?? ''),
                'response_payload' => $labelData['response_payload'] ?? null,
                'generated_at' => now(),
            ]);

            $this->syncLog($integration, $order, 'success', $startedAt, responsePayload: [
                'label_id' => $label->id,
                'path' => $label->path,
                'source_url' => $label->source_url,
                'tracking_number' => $label->tracking_number,
            ]);

            $this->audit->record('shipping_label.generated', $label, null, [
                'order_number' => $order->external_number,
                'label_id' => $label->id,
                'tracking_number' => $label->tracking_number,
            ], [
                'sales_channel' => $order->salesChannel?->code,
                'integration_id' => $integration->id,
            ]);

            return $label;
        } catch (Throwable $exception) {
            $this->syncLog($integration, $order, 'failed', $startedAt, error: $exception->getMessage());
            $this->audit->record('shipping_label.failed', $order, null, null, [
                'sales_channel' => $order->salesChannel?->code,
                'integration_id' => $integration->id,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    private function integrationForOrder(ExternalOrder $order): WordpressIntegration
    {
        $integration = WordpressIntegration::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->get()
            ->first(fn (WordpressIntegration $candidate): bool => $candidate->shippingLabelsEnabled());

        if (! $integration instanceof WordpressIntegration) {
            throw new RuntimeException('Brak aktywnej konfiguracji etykiet kurierskich dla kanału tego zamówienia.');
        }

        return $integration;
    }

    /**
     * @param array<string, mixed> $labelData
     */
    private function filename(ExternalOrder $order, array $labelData, string $mimeType): string
    {
        $raw = (string) ($labelData['filename'] ?? '');
        $extension = $this->extension($mimeType, $raw);
        $base = $raw !== ''
            ? pathinfo($raw, PATHINFO_FILENAME)
            : 'etykieta-' . ($order->external_number ?: $order->external_id ?: $order->id);

        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'etykieta';

        return trim($safeBase, '-_.') . '-' . now()->format('YmdHis') . '.' . $extension;
    }

    private function extension(string $mimeType, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'zpl'], true)) {
            return $extension;
        }

        return match (strtolower($mimeType)) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'application/zpl', 'text/plain' => 'zpl',
            default => 'pdf',
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function stringFromPayload(array $payload, array $keys): ?string
    {
        $response = (array) ($payload['response_payload'] ?? []);

        foreach ($keys as $key) {
            $value = $payload[$key] ?? data_get($response, $key);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $responsePayload
     */
    private function syncLog(
        WordpressIntegration $integration,
        ExternalOrder $order,
        string $status,
        mixed $startedAt,
        ?array $responsePayload = null,
        ?string $error = null,
    ): void {
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'generate_shipping_label',
            'status' => $status,
            'external_resource' => 'order',
            'external_id' => (string) $order->external_id,
            'request_payload' => [
                'order_id' => $order->external_id,
                'order_number' => $order->external_number,
            ],
            'response_payload' => $responsePayload,
            'error_message' => $error,
            'attempts' => 1,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
