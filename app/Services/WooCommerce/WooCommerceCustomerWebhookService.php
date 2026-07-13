<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\CustomerMessage;
use App\Models\WordpressIntegration;
use App\Services\Communication\CustomerCommunicationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class WooCommerceCustomerWebhookService
{
    private const BASELINE_GRACE_SECONDS = 5;

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly WooCommerceCustomerSyncService $customers,
        private readonly CustomerCommunicationService $communication,
    ) {}

    /**
     * Fetch the canonical customer from WooCommerce instead of trusting PII
     * delivered in the webhook request.
     *
     * @return array{
     *     ignored:bool,
     *     ignore_reason:?string,
     *     customer_id:?int,
     *     external_account_id:?int,
     *     external_customer_id:string,
     *     account_created_locally:bool,
     *     notification_eligible:bool,
     *     notification_created:bool,
     *     notification_status:?string
     * }
     */
    public function process(
        WordpressIntegration $integration,
        string $event,
        string $externalCustomerId,
        CarbonImmutable $occurredAt,
    ): array {
        $profile = $this->client->customer($integration, (int) $externalCustomerId);
        $canonicalCustomerId = (string) (int) ($profile['id'] ?? 0);

        if ($canonicalCustomerId === '0' || $canonicalCustomerId !== $externalCustomerId) {
            throw new RuntimeException('WooCommerce zwrócił profil innego klienta niż wskazany w webhooku.');
        }

        $role = mb_strtolower(trim((string) ($profile['role'] ?? '')));

        if ($role !== '' && $role !== 'customer') {
            return [
                'ignored' => true,
                'ignore_reason' => 'unsupported_role',
                'customer_id' => null,
                'external_account_id' => null,
                'external_customer_id' => $externalCustomerId,
                'account_created_locally' => false,
                'notification_eligible' => false,
                'notification_created' => false,
                'notification_status' => null,
            ];
        }

        $sync = $this->customers->syncRegisteredCustomer($integration, $profile);

        if ($sync === null) {
            throw new RuntimeException('Nie udało się zsynchronizować profilu klienta WooCommerce.');
        }

        $account = $sync['account'];
        $account->loadMissing('customer');
        $notificationEligible = $this->notificationEligible(
            $integration,
            $event,
            $occurredAt,
            $account->account_created_at !== null
                ? CarbonImmutable::instance($account->account_created_at)
                : null,
        );
        $message = null;

        if ($notificationEligible) {
            $message = $this->communication->sendCustomerAccountCreated(
                $account->customer,
                accountUrl: rtrim($integration->base_url, '/').'/moje-konto/',
            );
        }

        $integration->forceFill(['last_successful_sync_at' => now()])->save();

        return [
            'ignored' => false,
            'ignore_reason' => null,
            'customer_id' => (int) $account->customer_id,
            'external_account_id' => (int) $account->id,
            'external_customer_id' => $externalCustomerId,
            'account_created_locally' => (bool) $sync['account_created'],
            'notification_eligible' => $notificationEligible,
            'notification_created' => $message instanceof CustomerMessage,
            'notification_status' => $message?->status,
        ];
    }

    public function ensureNotificationBaseline(WordpressIntegration $integration): CarbonImmutable
    {
        return DB::transaction(function () use ($integration): CarbonImmutable {
            $locked = WordpressIntegration::query()
                ->lockForUpdate()
                ->findOrFail($integration->id);
            $existing = $this->notificationBaseline($locked);

            if ($existing !== null) {
                return $existing;
            }

            $baseline = CarbonImmutable::instance(now())->startOfSecond();
            $settings = (array) $locked->settings;
            data_set($settings, 'customer_import.notification_baseline_at', $baseline->toIso8601String());
            $locked->forceFill(['settings' => $settings])->save();

            return $baseline;
        }, 3);
    }

    private function notificationEligible(
        WordpressIntegration $integration,
        string $event,
        CarbonImmutable $occurredAt,
        ?CarbonImmutable $accountCreatedAt,
    ): bool {
        if ($event !== 'customer.created' || $accountCreatedAt === null) {
            return false;
        }

        $baseline = $this->notificationBaseline($integration);

        if ($baseline === null) {
            // Until the integration has an explicit baseline, importing a
            // historic/replayed event must never start an email campaign.
            return false;
        }

        $oldestEligible = $baseline->subSeconds(self::BASELINE_GRACE_SECONDS);

        return ! $occurredAt->isBefore($oldestEligible)
            && ! $accountCreatedAt->isBefore($oldestEligible);
    }

    private function notificationBaseline(WordpressIntegration $integration): ?CarbonImmutable
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
}
