<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\WordpressIntegration;
use App\Services\Communication\CustomerEmailWorkflowSettingsService;
use RuntimeException;
use Throwable;

final class WooCommerceOrderStatusService
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly CustomerEmailWorkflowSettingsService $emailWorkflow,
    ) {
    }

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
    private function updateStatus(ExternalOrder $order, string $settingsKey, string $defaultStatus, string $operation): array
    {
        $order = ExternalOrder::query()->findOrFail($order->id);
        $integration = WordpressIntegration::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->first();

        if (! $integration instanceof WordpressIntegration) {
            throw new RuntimeException('Brak aktywnej integracji WooCommerce dla kanału tego zamówienia.');
        }

        $status = trim((string) data_get($integration->orderStatusSettings(), $settingsKey, $defaultStatus));
        $status = $status !== '' ? $status : $defaultStatus;
        $startedAt = now();

        if (! $this->emailWorkflow->isEnabled($operation)) {
            $this->syncLog($integration, $order, $operation, 'skipped', $startedAt, [
                'status' => $status,
                'workflow_disabled' => true,
                'message' => 'Zmiana statusu WooCommerce wyłączona w workflow maili.',
            ]);

            return [
                'status' => null,
                'skipped' => true,
                'target_status' => $status,
            ];
        }

        try {
            $response = $this->client->updateOrderStatus($integration, (string) $order->external_id, $status);

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
     * @param array<string, mixed>|null $responsePayload
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
