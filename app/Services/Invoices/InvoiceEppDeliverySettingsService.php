<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\AppSetting;
use Illuminate\Support\Carbon;

final class InvoiceEppDeliverySettingsService
{
    public const KEY = 'invoice_epp_delivery';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_INTERVAL = 'interval';

    public const FREQUENCY_MONTHLY_FIRST = 'monthly_first';

    public const FREQUENCY_MONTHLY_LAST = 'monthly_last';

    /**
     * @return array{enabled:bool,recipient_email:string,recipient_emails:list<string>,frequency:string,interval_days:int,send_time:string,last_sent_at:?string,next_send_at:?string,last_period_end:?string,last_error:?string}
     */
    public function data(): array
    {
        $stored = AppSetting::query()->where('key', self::KEY)->value('value');
        $data = array_merge($this->defaults(), is_array($stored) ? $stored : []);
        $frequency = in_array($data['frequency'], $this->frequencies(), true)
            ? $data['frequency']
            : self::FREQUENCY_DAILY;
        $recipientEmails = $this->recipientEmails($data);

        return [
            'enabled' => (bool) $data['enabled'],
            'recipient_email' => $recipientEmails[0] ?? '',
            'recipient_emails' => $recipientEmails,
            'frequency' => $frequency,
            'interval_days' => $frequency === self::FREQUENCY_INTERVAL
                ? max(2, min(365, (int) $data['interval_days']))
                : 1,
            'send_time' => preg_match('/^\d{2}:\d{2}$/', (string) $data['send_time']) === 1 ? (string) $data['send_time'] : '19:00',
            'last_sent_at' => filled($data['last_sent_at']) ? (string) $data['last_sent_at'] : null,
            'next_send_at' => filled($data['next_send_at']) ? (string) $data['next_send_at'] : null,
            'last_period_end' => filled($data['last_period_end']) ? (string) $data['last_period_end'] : null,
            'last_error' => filled($data['last_error']) ? (string) $data['last_error'] : null,
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): array
    {
        $current = $this->data();
        $frequency = in_array($data['frequency'] ?? null, $this->frequencies(), true)
            ? (string) $data['frequency']
            : self::FREQUENCY_DAILY;
        $recipientEmails = $this->recipientEmails($data);
        $payload = array_merge($current, [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'recipient_email' => $recipientEmails[0] ?? '',
            'recipient_emails' => $recipientEmails,
            'frequency' => $frequency,
            'interval_days' => $frequency === self::FREQUENCY_INTERVAL
                ? max(2, min(365, (int) ($data['interval_days'] ?? 7)))
                : 1,
            'send_time' => (string) ($data['send_time'] ?? '19:00'),
            'last_error' => null,
        ]);

        $scheduleChanged = $current['frequency'] !== $payload['frequency']
            || $current['interval_days'] !== $payload['interval_days']
            || $current['send_time'] !== $payload['send_time']
            || ! $current['enabled'];

        if ($payload['enabled'] && ($scheduleChanged || $current['next_send_at'] === null)) {
            $payload['next_send_at'] = $this->nextOccurrence(
                now(),
                $payload['send_time'],
                $payload['frequency'],
            )->toIso8601String();
        }

        if (! $payload['enabled']) {
            $payload['next_send_at'] = null;
        }

        $this->store($payload);

        return $this->data();
    }

    public function markSent(Carbon $sentAt, Carbon $periodEnd): void
    {
        $data = $this->data();
        $next = (match ($data['frequency']) {
            self::FREQUENCY_MONTHLY_FIRST => $sentAt->copy()->addMonthNoOverflow()->startOfMonth(),
            self::FREQUENCY_MONTHLY_LAST => $sentAt->copy()->addMonthNoOverflow()->endOfMonth(),
            default => $sentAt->copy()->addDays($data['interval_days']),
        })->setTimeFromTimeString($data['send_time'])->second(0);

        $this->store(array_merge($data, [
            'last_sent_at' => $sentAt->toIso8601String(),
            'next_send_at' => $next->toIso8601String(),
            'last_period_end' => $periodEnd->toDateString(),
            'last_error' => null,
        ]));
    }

    public function markFailed(string $message): void
    {
        $this->store(array_merge($this->data(), [
            'last_error' => mb_substr($message, 0, 500),
        ]));
    }

    private function nextOccurrence(Carbon $now, string $time, string $frequency): Carbon
    {
        $candidate = (match ($frequency) {
            self::FREQUENCY_MONTHLY_FIRST => $now->copy()->startOfMonth(),
            self::FREQUENCY_MONTHLY_LAST => $now->copy()->endOfMonth(),
            default => $now->copy(),
        })->setTimeFromTimeString($time)->second(0);

        if ($candidate->gt($now)) {
            return $candidate;
        }

        return match ($frequency) {
            self::FREQUENCY_MONTHLY_FIRST => $candidate->addMonthNoOverflow()->startOfMonth()->setTimeFromTimeString($time),
            self::FREQUENCY_MONTHLY_LAST => $candidate->addMonthNoOverflow()->endOfMonth()->setTimeFromTimeString($time),
            default => $candidate->addDay(),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function recipientEmails(array $data): array
    {
        $storedEmails = is_array($data['recipient_emails'] ?? null) ? $data['recipient_emails'] : [];
        $emails = $storedEmails !== [] ? $storedEmails : [$data['recipient_email'] ?? ''];

        return collect($emails)
            ->map(fn (mixed $email): string => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $payload */
    private function store(array $payload): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::KEY], ['value' => $payload]);
    }

    private function defaults(): array
    {
        return [
            'enabled' => false,
            'recipient_email' => '',
            'recipient_emails' => [],
            'frequency' => 'daily',
            'interval_days' => 7,
            'send_time' => '19:00',
            'last_sent_at' => null,
            'next_send_at' => null,
            'last_period_end' => null,
            'last_error' => null,
        ];
    }

    /** @return list<string> */
    private function frequencies(): array
    {
        return [
            self::FREQUENCY_DAILY,
            self::FREQUENCY_INTERVAL,
            self::FREQUENCY_MONTHLY_FIRST,
            self::FREQUENCY_MONTHLY_LAST,
        ];
    }
}
