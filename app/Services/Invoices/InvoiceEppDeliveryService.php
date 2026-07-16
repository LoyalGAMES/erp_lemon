<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Mail\InvoiceEppExportMail;
use App\Services\Communication\MailSettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class InvoiceEppDeliveryService
{
    public function __construct(
        private readonly InvoiceEppDeliverySettingsService $settings,
        private readonly InvoiceEppExportService $exporter,
        private readonly MailSettingsService $mailSettings,
    ) {}

    public function sendIfDue(?Carbon $at = null): string
    {
        $at ??= now();

        return Cache::lock('invoice-epp-scheduled-delivery', 120)->block(1, function () use ($at): string {
            $settings = $this->settings->data();

            if (! $settings['enabled']) {
                return 'disabled';
            }

            $nextSendAt = filled($settings['next_send_at']) ? Carbon::parse($settings['next_send_at']) : null;

            if ($nextSendAt !== null && $at->lt($nextSendAt)) {
                return 'not_due';
            }

            [$periodStart, $periodEnd] = match ($settings['frequency']) {
                InvoiceEppDeliverySettingsService::FREQUENCY_MONTHLY_FIRST => [
                    $at->copy()->subMonthNoOverflow()->startOfMonth(),
                    $at->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay(),
                ],
                InvoiceEppDeliverySettingsService::FREQUENCY_MONTHLY_LAST => [
                    $at->copy()->startOfMonth(),
                    $at->copy()->endOfMonth()->startOfDay(),
                ],
                default => [
                    filled($settings['last_period_end'])
                        ? Carbon::parse($settings['last_period_end'])->addDay()->startOfDay()
                        : $at->copy()->startOfDay()->subDays($settings['interval_days'] - 1),
                    $at->copy()->startOfDay(),
                ],
            };

            try {
                if (! $this->mailSettings->apply()) {
                    throw new RuntimeException((string) ($this->mailSettings->data()['delivery_issue'] ?? 'Konfiguracja poczty nie jest gotowa.'));
                }

                $content = $this->exporter->exportRange($periodStart, $periodEnd->copy()->endOfDay());
                $periodLabel = $periodStart->toDateString().' – '.$periodEnd->toDateString();
                $filename = 'faktury-epp-'.$periodStart->toDateString().'-'.$periodEnd->toDateString().'.epp';

                Mail::to($settings['recipient_emails'])->send(
                    new InvoiceEppExportMail($content, $filename, $periodLabel),
                );

                $this->settings->markSent($at, $periodEnd);

                return 'sent';
            } catch (Throwable $exception) {
                $this->settings->markFailed($exception->getMessage());

                throw $exception;
            }
        });
    }
}
