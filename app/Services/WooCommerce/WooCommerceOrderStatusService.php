<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\WordpressIntegration;
use RuntimeException;
use Throwable;

final class WooCommerceOrderStatusService
{
    public function __construct(
        private readonly WooCommerceClient $client,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function markReadyForShipment(ExternalOrder $order): array
    {
        return $this->updateStatus($order, 'ready_to_ship', 'ready-to-ship', 'order_ready_for_shipment');
    }

    /**
     * @return array<string, mixed>
     */
    public function markShipped(ExternalOrder $order): array
    {
        return $this->updateStatus($order, 'shipped', 'completed', 'order_shipped');
    }

    /**
     * @return array<string, mixed>
     */
    public function markPackingRollback(ExternalOrder $order): array
    {
        return $this->updateStatus($order, 'packing_rollback', 'processing', 'order_packing_rollback');
    }

    /**
     * @return array<string, mixed>
     */
    public function markCancelledForPackingProblem(ExternalOrder $order): array
    {
        return $this->updateStatus(
            $order,
            settingsKey: null,
            defaultStatus: 'cancelled',
            operation: 'order_cancelled_from_packing_problem',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function updateManually(ExternalOrder $order, string $status): array
    {
        return $this->updateStatus(
            $order,
            settingsKey: null,
            defaultStatus: $status,
            operation: 'order_status_manual_update',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function updateStatus(
        ExternalOrder $order,
        ?string $settingsKey,
        string $defaultStatus,
        string $operation,
    ): array {
        $order = ExternalOrder::query()->findOrFail($order->id);
        $status = $settingsKey !== null
            ? null
            : trim($defaultStatus);

        if ($order->hasCancellationOperation()
            && $status !== 'cancelled') {
            throw new RuntimeException('Nie można zmienić statusu zamówienia podczas trwającej lub zakończonej anulacji.');
        }

        if (in_array($order->status, ['cancelled', 'refunded'], true)
            && ! in_array($status, ['cancelled', 'refunded'], true)) {
            throw new RuntimeException('Anulowanego albo zwróconego zamówienia nie można ponownie otworzyć zwykłą zmianą statusu.');
        }

        $integration = WordpressIntegration::query()
            ->when(
                $order->wordpress_integration_id !== null,
                fn ($query) => $query->whereKey($order->wordpress_integration_id),
                fn ($query) => $query->where('sales_channel_id', $order->sales_channel_id),
            )
            ->where('sales_channel_id', $order->sales_channel_id)
            ->first();

        if (! $integration instanceof WordpressIntegration) {
            throw new RuntimeException('Brak aktywnej integracji WooCommerce dla kanału tego zamówienia.');
        }

        $status = $settingsKey !== null
            ? trim((string) data_get($integration->orderStatusSettings(), $settingsKey, $defaultStatus))
            : trim($defaultStatus);
        $status = $status !== '' ? $status : $defaultStatus;
        $startedAt = now();

        try {
            $response = $this->client->updateOrderStatus($integration, (string) $order->external_id, $status);
            $responseStatus = mb_strtolower(trim((string) ($response['status'] ?? '')));

            if ($status === 'cancelled' && $responseStatus !== 'cancelled') {
                throw new RuntimeException(
                    'WooCommerce nie potwierdził anulowania zamówienia. '
                    .'Odpowiedź zawiera status: '.($responseStatus !== '' ? $responseStatus : 'brak statusu').'.',
                );
            }

            $raw = (array) $order->raw_payload;
            $raw['sempre_erp_status_sync'] = [
                'operation' => $operation,
                'status' => $status,
                'synced_at' => now()->toISOString(),
            ];

            $order->update([
                'status' => $status,
                'raw_payload' => $raw,
                'external_updated_at' => now(),
            ]);

            $this->syncLog($integration, $order, $operation, 'success', $startedAt, [
                'status' => $status,
                'response_status' => $response['status'] ?? null,
            ]);

            return [
                'status' => $status,
                'response' => $response,
            ];
        } catch (Throwable $exception) {
            $this->syncLog($integration, $order, $operation, 'failed', $startedAt, error: $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function syncLog(
        WordpressIntegration $integration,
        ExternalOrder $order,
        string $operation,
        string $status,
        mixed $startedAt,
        ?array $responsePayload = null,
        ?string $error = null,
    ): void {
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => $operation,
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
