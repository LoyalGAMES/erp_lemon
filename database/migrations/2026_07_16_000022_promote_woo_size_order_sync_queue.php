<?php

use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wordpress_integrations')
            || ! Schema::hasTable('sales_channels')
            || ! Schema::hasTable('product_parameter_definitions')
            || ! Schema::hasTable('jobs')
        ) {
            return;
        }

        // Release 000021 queued this narrowly scoped repair behind full
        // catalog exports. Move only an unreserved, exactly identified job;
        // active workers are never reassigned underneath a running attempt.
        DB::table('jobs')
            ->whereNull('reserved_at')
            ->where('queue', 'woocommerce-critical')
            ->select(['id', 'payload'])
            ->get()
            ->each(function (object $queuedJob): void {
                $payload = json_decode((string) $queuedJob->payload, true);

                if (! is_array($payload)
                    || ($payload['displayName'] ?? null) !== SyncWooCommerceGlobalSizeOrderJob::class
                ) {
                    return;
                }

                DB::table('jobs')
                    ->where('id', $queuedJob->id)
                    ->where('queue', 'woocommerce-critical')
                    ->whereNull('reserved_at')
                    ->update([
                        'queue' => SyncWooCommerceGlobalSizeOrderJob::QUEUE,
                        'available_at' => now()->timestamp,
                    ]);
            });

        // If the previous job already completed or failed, queue a fresh
        // attempt. ShouldBeUnique suppresses a duplicate when it was moved.
        SyncWooCommerceGlobalSizeOrderJob::dispatchForActiveIntegrations(
            'dedicated_size_order_queue_2026_07_16_000022',
        );
    }

    public function down(): void
    {
        // Deliberate no-op. Sending this maintenance task back behind long
        // product exports would reintroduce the production stall.
    }
};
