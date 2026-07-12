<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\PrintJob;
use App\Models\ShippingLabel;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ShippingLabelPrintQueueService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array{code:string,name:string,printer_name:string,segment:string}|null  $station
     */
    public function enqueueForStation(ShippingLabel $label, ?array $station, string $source): ?PrintJob
    {
        $printerName = trim((string) ($station['printer_name'] ?? ''));

        if ($printerName === '') {
            return null;
        }

        $stationCode = filled($station['code'] ?? null) ? trim((string) $station['code']) : null;
        $stationCode = $stationCode !== '' ? $stationCode : null;

        $deduplicationKey = hash('sha256', implode("\0", [$label->id, $stationCode ?? '', $printerName]));

        $existing = PrintJob::query()->where('deduplication_key', $deduplicationKey)->first();
        if (! $existing instanceof PrintJob) {
            $legacy = PrintJob::query()
                ->where('shipping_label_id', $label->id)
                ->where('printer_name', $printerName)
                ->where(function ($query) use ($stationCode): void {
                    if ($stationCode === null) {
                        $query->whereNull('station_code');
                    } else {
                        $query->where('station_code', $stationCode);
                    }
                })
                ->whereNull('deduplication_key')
                ->latest('id')
                ->first();

            if ($legacy instanceof PrintJob) {
                try {
                    $legacy->forceFill(['deduplication_key' => $deduplicationKey])->save();
                    $existing = $legacy;
                } catch (UniqueConstraintViolationException) {
                    // Another request atomically assigned/created the same
                    // logical job; use its canonical row below.
                    $existing = PrintJob::query()->where('deduplication_key', $deduplicationKey)->firstOrFail();
                }
            }
        }

        if (! $existing instanceof PrintJob) {
            $existing = PrintJob::query()->firstOrCreate([
                'deduplication_key' => $deduplicationKey,
            ], [
                'shipping_label_id' => $label->id,
                'status' => 'pending',
                'source' => $source,
                'station_code' => $stationCode,
                'printer_name' => $printerName,
                'format' => $this->formatForLabel($label),
                'metadata' => [
                    'station_name' => $station['name'] ?? null,
                    'label_filename' => $label->filename(),
                ],
            ]);
        }

        if (! $existing->wasRecentlyCreated) {
            $updates = [];

            if ($existing->status === 'printing' && $existing->reserved_by === 'direct-listener') {
                $updates = array_merge($updates, [
                    'status' => 'pending',
                    'reserved_by' => null,
                    'reserved_station' => null,
                    'reserved_at' => null,
                    'lease_token' => null,
                    'next_attempt_at' => null,
                    'attempts' => 0,
                    'failed_at' => null,
                    'last_error' => null,
                ]);
            }

            if ($existing->status === 'failed') {
                $updates = array_merge($updates, [
                    'status' => 'pending', 'source' => $source, 'attempts' => 0,
                    'next_attempt_at' => null, 'reserved_by' => null, 'reserved_station' => null,
                    'reserved_at' => null, 'lease_token' => null, 'failed_at' => null, 'last_error' => null,
                ]);
            }
            if ($updates !== []) {
                $existing->forceFill($updates)->save();
            }

            return $existing->fresh();
        }

        $job = $existing;

        $this->audit->record('print_job.queued', $job, null, [
            'shipping_label_id' => $label->id,
            'printer_name' => $printerName,
            'station_code' => $stationCode,
            'source' => $source,
        ]);

        return $job->fresh();
    }

    public function claimNext(?string $stationCode, ?string $workerName): ?PrintJob
    {
        return DB::transaction(function () use ($stationCode, $workerName): ?PrintJob {
            $existingLease = PrintJob::query()
                ->where('status', 'printing')
                ->where('reserved_by', $workerName)
                ->where('reserved_station', $stationCode)
                ->whereNotNull('lease_token')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($existingLease instanceof PrintJob) {
                return $existingLease->fresh(['shippingLabel.order']);
            }

            $this->releaseStaleReservations();

            $query = PrintJob::query()
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query
                        ->whereNull('next_attempt_at')
                        ->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id')
                ->lockForUpdate();

            if ($stationCode !== null && $stationCode !== '') {
                $query->where('station_code', $stationCode);
            }

            $job = $query->first();

            if (! $job instanceof PrintJob) {
                return null;
            }

            $job->update([
                'status' => 'printing',
                'attempts' => $job->attempts + 1,
                'reserved_by' => $workerName,
                'reserved_station' => $stationCode,
                'reserved_at' => now(),
                'lease_token' => bin2hex(random_bytes(32)),
                'last_error' => null,
            ]);

            return $job->fresh(['shippingLabel.order']);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function markPrinted(PrintJob $job, ?string $workerName = null, array $metadata = []): PrintJob
    {
        if ($job->status === 'printed') {
            return $job->fresh();
        }
        $job->update([
            'status' => 'printed',
            'reserved_by' => $workerName ?: $job->reserved_by,
            'printed_at' => now(),
            'failed_at' => null,
            'last_error' => null,
            'metadata' => array_merge((array) $job->metadata, $metadata),
        ]);

        $this->audit->record('print_job.printed', $job, null, [
            'shipping_label_id' => $job->shipping_label_id,
            'printer_name' => $job->printer_name,
            'worker' => $workerName ?: $job->reserved_by,
        ]);

        return $job->fresh();
    }

    public function markFailed(PrintJob $job, string $error, ?string $workerName = null): PrintJob
    {
        if ($job->status !== 'printing') {
            return $job->fresh();
        }
        $retry = $job->attempts < self::MAX_ATTEMPTS;

        $job->update([
            'status' => $retry ? 'pending' : 'failed',
            'reserved_by' => $workerName ?: $job->reserved_by,
            'reserved_at' => null,
            'next_attempt_at' => $retry ? now()->addSeconds(min(300, max(10, $job->attempts * 30))) : null,
            'failed_at' => $retry ? null : now(),
            'last_error' => mb_substr($error, 0, 2000),
        ]);

        $this->audit->record('print_job.failed', $job, null, [
            'shipping_label_id' => $job->shipping_label_id,
            'printer_name' => $job->printer_name,
            'worker' => $workerName ?: $job->reserved_by,
            'will_retry' => $retry,
            'error' => mb_substr($error, 0, 500),
        ]);

        return $job->fresh();
    }

    public function assertLease(PrintJob $job, string $leaseToken, string $workerName, string $stationCode, array $allowedStatuses = ['printing']): void
    {
        $matches = $job->lease_token !== null
            && hash_equals((string) $job->lease_token, $leaseToken)
            && hash_equals((string) $job->reserved_by, $workerName)
            && hash_equals((string) $job->reserved_station, $stationCode)
            && in_array($job->status, $allowedStatuses, true);
        if (! $matches) {
            throw new ConflictHttpException('Wygasła albo nieprawidłowa dzierżawa zadania wydruku.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function apiPayload(PrintJob $job): array
    {
        $label = $job->shippingLabel;
        $order = $label?->order;

        return [
            'id' => $job->id,
            'status' => $job->status,
            'station_code' => $job->station_code,
            'printer_name' => $job->printer_name,
            'format' => $job->format,
            'attempts' => $job->attempts,
            'lease_token' => $job->status === 'printing' ? $job->lease_token : null,
            'label' => [
                'id' => $label?->id,
                'filename' => $label?->filename(),
                'mime_type' => $label?->mime_type,
                'tracking_number' => $label?->tracking_number,
                'order_number' => $order?->external_number,
            ],
        ];
    }

    private function formatForLabel(ShippingLabel $label): string
    {
        $mime = mb_strtolower((string) $label->mime_type);
        $extension = mb_strtolower(pathinfo($label->path, PATHINFO_EXTENSION));

        if ($extension === 'zpl' || str_contains($mime, 'zpl')) {
            return 'zpl';
        }

        if (in_array($extension, ['png', 'jpg', 'jpeg'], true) || str_starts_with($mime, 'image/')) {
            return 'image';
        }

        return 'pdf';
    }

    private function releaseStaleReservations(): void
    {
        PrintJob::query()
            ->where('status', 'printing')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', now()->subDay())
            ->update([
                'status' => 'pending',
                'reserved_by' => null,
                'reserved_station' => null,
                'reserved_at' => null,
                'lease_token' => null,
                'next_attempt_at' => null,
                'last_error' => 'Most wydruku nie potwierdził zadania w czasie 24 godzin.',
            ]);
    }
}
