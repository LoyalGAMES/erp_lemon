<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use App\Models\IntegrationSyncLog;
use App\Models\WordpressIntegration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class VerifyWooCommerceGlobalSizeOrderSyncCommand extends Command
{
    protected $signature = 'erp:verify-woocommerce-global-size-order-sync
        {--since= : ISO-8601 lower bound for a fresh successful sync}
        {--trigger= : Exact deployment trigger required in the successful sync log}';

    protected $description = 'Verify that every active WooCommerce integration completed the global size-order repair.';

    public function handle(): int
    {
        $since = $this->since();
        $trigger = trim((string) $this->option('trigger'));
        $activeIntegrationIds = WordpressIntegration::query()
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $successfulIntegrationIds = IntegrationSyncLog::query()
            ->where('operation', 'sync_woocommerce_global_size_order')
            ->where('status', 'success')
            ->where('finished_at', '>=', $since->format('Y-m-d H:i:s'))
            ->whereIn('wordpress_integration_id', $activeIntegrationIds)
            ->get(['wordpress_integration_id', 'request_payload', 'response_payload'])
            ->filter(fn (IntegrationSyncLog $log): bool => data_get(
                $log->response_payload,
                'status',
            ) === 'synchronized'
                && ($trigger === '' || data_get($log->request_payload, 'trigger') === $trigger))
            ->pluck('wordpress_integration_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $missingIntegrationIds = $activeIntegrationIds
            ->diff($successfulIntegrationIds)
            ->values();
        // An exact deployment trigger is produced by the guarded synchronous
        // command and queues no work. Its fresh success supersedes any older
        // async fingerprint because every queued job reads the current ERP
        // dictionary when it eventually runs under the shared catalog lock.
        $pending = $trigger !== ''
            ? collect()
            : DB::table('jobs')
                ->select(['id', 'queue', 'payload'])
                ->get()
                ->filter(fn (object $job): bool => $this->isTargetPayload($job->payload))
                ->values();
        $failed = $trigger !== ''
            ? collect()
            : DB::table('failed_jobs')
                ->where('failed_at', '>=', $since->format('Y-m-d H:i:s'))
                ->select(['id', 'queue', 'payload'])
                ->get()
                ->filter(fn (object $job): bool => $this->isTargetPayload($job->payload))
                ->values();

        $this->info(sprintf(
            'Woo size-order postcondition: active=%d, fresh_success=%d, missing=%s, pending=%d, failed=%d, since=%s, trigger=%s.',
            $activeIntegrationIds->count(),
            $successfulIntegrationIds->count(),
            $missingIntegrationIds->isEmpty() ? '-' : $missingIntegrationIds->implode(','),
            $pending->count(),
            $failed->count(),
            $since->toIso8601String(),
            $trigger !== '' ? $trigger : '-',
        ));

        if ($missingIntegrationIds->isNotEmpty() || $pending->isNotEmpty() || $failed->isNotEmpty()) {
            $pending->each(fn (object $job) => $this->warn(sprintf(
                'Pending size-order job: id=%d queue=%s.',
                (int) $job->id,
                (string) $job->queue,
            )));
            $failed->each(fn (object $job) => $this->error(sprintf(
                'Failed size-order job: id=%d queue=%s.',
                (int) $job->id,
                (string) $job->queue,
            )));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function since(): CarbonImmutable
    {
        $value = trim((string) $this->option('since'));

        return ($value === '' ? CarbonImmutable::now() : CarbonImmutable::parse($value))
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    private function isTargetPayload(mixed $payload): bool
    {
        $decoded = json_decode((string) $payload, true);

        return is_array($decoded)
            && ($decoded['displayName'] ?? null) === SyncWooCommerceGlobalSizeOrderJob::class;
    }
}
