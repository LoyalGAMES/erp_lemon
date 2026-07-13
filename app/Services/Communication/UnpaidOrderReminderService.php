<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\AppSetting;
use App\Models\ExternalOrder;
use App\Services\Payments\PaymentMethodClassifier;
use Carbon\CarbonImmutable;
use Throwable;

final class UnpaidOrderReminderService
{
    private const ACTIVATION_KEY = 'unpaid_order_reminder_activation';

    public function __construct(
        private readonly CustomerCommunicationService $communication,
        private readonly CustomerEmailWorkflowSettingsService $workflow,
        private readonly PaymentMethodClassifier $paymentMethods,
    ) {}

    /**
     * @return array{scanned:int,eligible:int,created:int,sent:int,held:int,failed:int,skipped:int}
     */
    public function dispatchDue(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $stats = [
            'scanned' => 0,
            'eligible' => 0,
            'created' => 0,
            'sent' => 0,
            'held' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        $activatedAt = $this->activatedAt();

        $orders = ExternalOrder::query()
            ->whereIn('status', ['pending', 'on-hold'])
            ->where('created_at', '>=', $activatedAt)
            ->where(function ($query) use ($activatedAt): void {
                $query->whereNull('external_created_at')
                    ->orWhere('external_created_at', '>=', $activatedAt);
            })
            ->oldest('id')
            ->limit(min(5000, $limit * 20))
            ->get();

        foreach ($orders as $order) {
            if ($stats['created'] >= $limit) {
                break;
            }

            $stats['scanned']++;
            $category = $this->paymentMethods->category($order);

            if ($category === PaymentMethodClassifier::CASH_ON_DELIVERY
                || $this->isPaid($order)
                || $this->paymentReminderAlreadyHandled($order)
                || ! $this->isDue($order, $category)) {
                $stats['skipped']++;

                continue;
            }

            $stats['eligible']++;
            $message = $this->communication->sendOrderStatus($order, 'order_on_hold', [
                'scheduled_unpaid_reminder' => true,
                'reminder_delay_minutes' => $this->workflow->unpaidReminderDelayMinutes($category),
            ]);

            if ($message === null) {
                $stats['skipped']++;

                continue;
            }

            $stats['created']++;

            if (array_key_exists($message->status, $stats)) {
                $stats[$message->status]++;
            }
        }

        return $stats;
    }

    public function isPaid(ExternalOrder $order): bool
    {
        foreach (['date_paid', 'date_paid_gmt', 'payment_details.date_paid'] as $path) {
            if (filled(data_get($order->raw_payload, $path))) {
                return true;
            }
        }

        $currency = mb_strtoupper(trim((string) ($order->currency ?: 'PLN')));
        $booked = (float) $order->customerPayments()
            ->where('direction', 'incoming')
            ->whereIn('status', ['booked', 'paid', 'settled'])
            ->where('currency', $currency)
            ->sum('amount');

        return $booked + 0.005 >= (float) $order->total_gross;
    }

    private function isDue(ExternalOrder $order, string $category): bool
    {
        $createdAt = $order->created_at?->toImmutable();

        if ($createdAt === null) {
            return false;
        }

        return $createdAt
            ->addMinutes($this->workflow->unpaidReminderDelayMinutes($category))
            ->lessThanOrEqualTo(now());
    }

    private function paymentReminderAlreadyHandled(ExternalOrder $order): bool
    {
        return $order->customerMessages()
            ->whereIn('trigger', ['order_on_hold', 'order_payment_failed', 'manual_payment_reminder'])
            ->whereIn('status', ['held', 'pending', 'sent', 'failed'])
            ->exists();
    }

    private function activatedAt(): CarbonImmutable
    {
        $setting = AppSetting::query()->firstOrCreate(
            ['key' => self::ACTIVATION_KEY],
            ['value' => ['activated_at' => now()->subMinutes(10)->toIso8601String()]],
        );
        $value = data_get($setting->value, 'activated_at');

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            $activatedAt = CarbonImmutable::now();
            $setting->update(['value' => ['activated_at' => $activatedAt->toIso8601String()]]);

            return $activatedAt;
        }
    }
}
