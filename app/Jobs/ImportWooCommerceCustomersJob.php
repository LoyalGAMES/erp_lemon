<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CustomerExternalAccount;
use App\Models\IntegrationSyncLog;
use App\Models\WordpressIntegration;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\WooCommerce\WooCommerceCustomerSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportWooCommerceCustomersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    private const NOTIFICATION_OVERLAP_MINUTES = 30;

    public function __construct(
        private readonly int $integrationId,
        private readonly int $syncLogId,
    ) {}

    public function uniqueId(): string
    {
        return 'woocommerce-customers-log:'.$this->syncLogId;
    }

    public function handle(
        WooCommerceCustomerSyncService $importer,
        CustomerCommunicationService $communication,
    ): void {
        $integration = WordpressIntegration::query()->findOrFail($this->integrationId);
        $log = IntegrationSyncLog::query()->findOrFail($this->syncLogId);
        $previousSuccessfulImport = IntegrationSyncLog::query()
            ->where('wordpress_integration_id', $integration->id)
            ->where('operation', 'import_customers')
            ->where('status', 'success')
            ->whereNotNull('finished_at')
            ->whereKeyNot($log->id)
            ->latest('finished_at')
            ->first();
        $previousSuccessfulAt = $previousSuccessfulImport?->finished_at !== null
            ? CarbonImmutable::instance($previousSuccessfulImport->finished_at)
            : null;
        $notificationBaselineAt = $this->notificationBaselineAt($integration);
        $isBaselineImport = $previousSuccessfulAt === null || $notificationBaselineAt === null;

        if ($notificationBaselineAt === null) {
            // Capture the boundary before the potentially long paginated import.
            // An account created while the first scan is running must remain
            // eligible for the next pass instead of falling into a time gap.
            $notificationBaselineAt = $this->ensureNotificationBaselineAt(
                $integration,
                CarbonImmutable::instance(now()),
            );
        }

        $notificationCutoff = $isBaselineImport
            ? null
            : $this->laterDate(
                $notificationBaselineAt,
                $previousSuccessfulAt->subMinutes(self::NOTIFICATION_OVERLAP_MINUTES),
            );

        $log->update([
            'status' => 'running',
            'attempts' => $this->attempts(),
            'started_at' => now(),
            'finished_at' => null,
            'error_message' => null,
        ]);

        $stats = $importer->importCustomers($integration);
        $stats['created_customer_ids_count'] = count((array) ($stats['created_customer_ids'] ?? []));
        $stats['created_external_account_ids_count'] = count((array) ($stats['created_external_account_ids'] ?? []));
        unset($stats['created_customer_ids'], $stats['created_external_account_ids']);

        $stats = array_merge($stats, $this->sendAccountCreatedNotifications(
            $integration,
            $communication,
            $notificationCutoff,
            $previousSuccessfulAt,
            $notificationBaselineAt,
            $isBaselineImport,
        ));

        $integration->update(['last_successful_sync_at' => now()]);
        $log->update([
            'status' => 'success',
            'response_payload' => $stats,
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $exception): void
    {
        IntegrationSyncLog::query()
            ->whereKey($this->syncLogId)
            ->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);
    }

    /**
     * The first successful customer import establishes the historical baseline.
     * Only later imports may notify accounts created since the previous success.
     *
     * @return array{
     *     notification_baseline:bool,
     *     notification_cutoff:?string,
     *     notification_previous_success_at:?string,
     *     notification_baseline_at:?string,
     *     notification_overlap_minutes:int,
     *     notifications_eligible:int,
     *     notifications_created:int,
     *     notifications_sent:int,
     *     notifications_held:int,
     *     notifications_failed:int,
     *     notifications_skipped:int,
     *     notification_errors:list<string>
     * }
     */
    private function sendAccountCreatedNotifications(
        WordpressIntegration $integration,
        CustomerCommunicationService $communication,
        ?CarbonImmutable $cutoff,
        ?CarbonImmutable $previousSuccessfulAt,
        ?CarbonImmutable $baselineAt,
        bool $isBaselineImport,
    ): array {
        $stats = [
            'notification_baseline' => $isBaselineImport,
            'notification_cutoff' => $cutoff?->toIso8601String(),
            'notification_previous_success_at' => $previousSuccessfulAt?->toIso8601String(),
            'notification_baseline_at' => $baselineAt?->toIso8601String(),
            'notification_overlap_minutes' => self::NOTIFICATION_OVERLAP_MINUTES,
            'notifications_eligible' => 0,
            'notifications_created' => 0,
            'notifications_sent' => 0,
            'notifications_held' => 0,
            'notifications_failed' => 0,
            'notifications_skipped' => 0,
            'notification_errors' => [],
        ];

        if ($isBaselineImport || $cutoff === null) {
            return $stats;
        }

        $eligible = CustomerExternalAccount::query()
            ->where('wordpress_integration_id', $integration->id)
            ->where('is_registered', true)
            ->whereNotNull('account_created_at')
            ->where('account_created_at', '>=', $cutoff->utc());
        $stats['notifications_eligible'] = (clone $eligible)->count();
        $candidates = (clone $eligible)
            ->whereDoesntHave('customer.messages', fn ($messages) => $messages->where('trigger', 'customer_account_created'));
        $candidateCount = (clone $candidates)->count();
        $stats['notifications_skipped'] = max(0, $stats['notifications_eligible'] - $candidateCount);
        $accountUrl = rtrim($integration->base_url, '/').'/moje-konto/';

        $candidates
            ->with('customer')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($communication, $accountUrl, &$stats): void {
                foreach ($accounts as $account) {
                    try {
                        $message = $communication->sendCustomerAccountCreated(
                            $account->customer,
                            accountUrl: $accountUrl,
                        );

                        if ($message === null) {
                            $stats['notifications_skipped']++;

                            continue;
                        }

                        $stats['notifications_created']++;

                        match ($message->status) {
                            'sent' => $stats['notifications_sent']++,
                            'held', 'pending' => $stats['notifications_held']++,
                            'failed' => $stats['notifications_failed']++,
                            default => $stats['notifications_skipped']++,
                        };
                    } catch (Throwable $exception) {
                        $stats['notifications_failed']++;
                        $stats['notification_errors'][] = mb_substr($exception->getMessage(), 0, 500);
                    }
                }
            });

        return $stats;
    }

    private function notificationBaselineAt(WordpressIntegration $integration): ?CarbonImmutable
    {
        $value = data_get($integration->settings, 'customer_import.notification_baseline_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function ensureNotificationBaselineAt(
        WordpressIntegration $integration,
        CarbonImmutable $candidate,
    ): CarbonImmutable {
        return DB::transaction(function () use ($integration, $candidate): CarbonImmutable {
            $locked = WordpressIntegration::query()
                ->lockForUpdate()
                ->findOrFail($integration->id);
            $existing = $this->notificationBaselineAt($locked);

            if ($existing !== null) {
                return $existing;
            }

            $settings = (array) $locked->settings;
            data_set($settings, 'customer_import.notification_baseline_at', $candidate->toIso8601String());
            $locked->update(['settings' => $settings]);

            return $candidate;
        }, 3);
    }

    private function laterDate(CarbonImmutable $first, CarbonImmutable $second): CarbonImmutable
    {
        return $first->greaterThan($second) ? $first : $second;
    }
}
