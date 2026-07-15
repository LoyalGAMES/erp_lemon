<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IntegrationSyncLog;
use App\Models\ProductParameterDefinition;
use App\Models\WordpressIntegration;
use App\Services\WooCommerce\WooCommerceGlobalSizeOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SyncWooCommerceGlobalSizeOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const QUEUE = 'woocommerce-critical';

    public int $tries = 70;

    public int $maxExceptions = 3;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $integrationId,
        public readonly string $trigger = 'manual',
        public readonly string $dictionaryFingerprint = '',
    ) {}

    public function uniqueId(): string
    {
        return 'woocommerce-global-size-order:'
            .$this->integrationId.':'
            .($this->dictionaryFingerprint !== '' ? $this->dictionaryFingerprint : 'current');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [ImportWooCommerceProductsJob::catalogLock($this->integrationId)];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 120, 300, 900];
    }

    public function handle(WooCommerceGlobalSizeOrderSyncService $sync): void
    {
        $integration = WordpressIntegration::query()
            ->with('salesChannel')
            ->find($this->integrationId);

        if (! $integration instanceof WordpressIntegration
            || ! $integration->salesChannel?->is_active
            || $integration->salesChannel?->type !== 'woocommerce'
        ) {
            return;
        }

        $result = $sync->sync($integration);
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'success',
            'external_resource' => 'product_attribute',
            'external_id' => isset($result['attribute_id']) ? (string) $result['attribute_id'] : null,
            'request_payload' => [
                'trigger' => $this->trigger,
                'existing_terms_only' => true,
            ],
            'response_payload' => $result,
            'attempts' => max(1, $this->attempts()),
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $integration = WordpressIntegration::query()->find($this->integrationId);

        if (! $integration instanceof WordpressIntegration) {
            return;
        }

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'sync_woocommerce_global_size_order',
            'status' => 'failed',
            'external_resource' => 'product_attribute',
            'request_payload' => [
                'trigger' => $this->trigger,
                'existing_terms_only' => true,
            ],
            'error_message' => $exception->getMessage(),
            'attempts' => max(1, $this->attempts()),
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public static function dispatchForActiveIntegrations(string $trigger): int
    {
        $fingerprint = sha1((string) json_encode(
            ProductParameterDefinition::query()
                ->orderBy('id')
                ->get(['id', 'name', 'name_en', 'slug', 'values', 'values_en', 'is_variant'])
                ->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $integrations = WordpressIntegration::query()
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->orderBy('id')
            ->get();

        foreach ($integrations as $integration) {
            self::dispatch((int) $integration->id, $trigger, $fingerprint)
                ->onConnection('database')
                ->onQueue(self::QUEUE)
                ->afterCommit();
        }

        return $integrations->count();
    }
}
