<?php

declare(strict_types=1);

namespace App\Services\Printing;

use App\Models\PrintJob;
use App\Models\ShippingLabel;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ShippingLabelPrintQueueService
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array{code:string,name:string,printer_name:string,listener_url?:string,segment:string}|null  $station
     */
    public function enqueueForStation(ShippingLabel $label, ?array $station, string $source): ?PrintJob
    {
        $printerName = trim((string) ($station['printer_name'] ?? ''));
        $listenerUrl = $this->normalizeListenerUrl((string) ($station['listener_url'] ?? ''));

        if ($printerName === '') {
            return null;
        }

        $stationCode = filled($station['code'] ?? null) ? (string) $station['code'] : null;

        $existing = PrintJob::query()
            ->where('shipping_label_id', $label->id)
            ->where('printer_name', $printerName)
            ->where(function ($query) use ($stationCode): void {
                if ($stationCode === null) {
                    $query->whereNull('station_code');
                } else {
                    $query->where('station_code', $stationCode);
                }
            })
            ->whereIn('status', ['pending', 'printing', 'printed'])
            ->latest('id')
            ->first();

        if ($existing instanceof PrintJob) {
            if ($existing->status !== 'printed') {
                return $this->sendToListenerIfConfigured($existing, $label, $listenerUrl);
            }

            return $existing;
        }

        $failed = PrintJob::query()
            ->where('shipping_label_id', $label->id)
            ->where('printer_name', $printerName)
            ->where('status', 'failed')
            ->latest('id')
            ->first();

        if ($failed instanceof PrintJob) {
            $failed->update([
                'status' => 'pending',
                'source' => $source,
                'station_code' => $stationCode,
                'listener_url' => $listenerUrl !== '' ? $listenerUrl : null,
                'attempts' => 0,
                'next_attempt_at' => null,
                'reserved_by' => null,
                'reserved_at' => null,
                'failed_at' => null,
                'last_error' => null,
            ]);

            return $failed->fresh();
        }

        $job = PrintJob::query()->create([
            'shipping_label_id' => $label->id,
            'status' => 'pending',
            'source' => $source,
            'station_code' => $stationCode,
            'printer_name' => $printerName,
            'listener_url' => $listenerUrl !== '' ? $listenerUrl : null,
            'format' => $this->formatForLabel($label),
            'metadata' => [
                'station_name' => $station['name'] ?? null,
                'label_filename' => $label->filename(),
            ],
        ]);

        $this->audit->record('print_job.queued', $job, null, [
            'shipping_label_id' => $label->id,
            'printer_name' => $printerName,
            'station_code' => $stationCode,
            'source' => $source,
        ]);

        return $this->sendToListenerIfConfigured($job, $label, $listenerUrl);
    }

    public function claimNext(?string $stationCode, ?string $workerName): ?PrintJob
    {
        return DB::transaction(function () use ($stationCode, $workerName): ?PrintJob {
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
                'reserved_at' => now(),
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
            'listener_url' => $job->listener_url,
            'format' => $job->format,
            'attempts' => $job->attempts,
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

    private function sendToListenerIfConfigured(PrintJob $job, ShippingLabel $label, string $listenerUrl): PrintJob
    {
        if ($listenerUrl === '') {
            return $job;
        }

        $job->update([
            'status' => 'printing',
            'attempts' => $job->attempts + 1,
            'reserved_by' => 'direct-listener',
            'reserved_at' => now(),
            'listener_url' => $listenerUrl,
            'last_error' => null,
        ]);

        try {
            if (! Storage::disk($label->disk)->exists($label->path)) {
                throw new RuntimeException('Plik etykiety nie istnieje w storage.');
            }

            $label->loadMissing('order');
            $response = Http::timeout(12)
                ->acceptJson()
                ->post($listenerUrl.'/print', [
                    'printer_name' => $job->printer_name,
                    'format' => $job->format,
                    'filename' => $label->filename(),
                    'mime_type' => $label->mime_type ?? 'application/pdf',
                    'tracking_number' => $label->tracking_number,
                    'order_number' => $label->order?->external_number,
                    'content_base64' => base64_encode(Storage::disk($label->disk)->get($label->path)),
                ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Aplikacja Windows zwróciła HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
                );
            }

            return $this->markPrinted($job->fresh(), 'direct-listener', [
                'listener_url' => $listenerUrl,
                'listener_response' => $response->json() ?? null,
            ]);
        } catch (Throwable $exception) {
            return $this->markFailed($job->fresh(), $exception->getMessage(), 'direct-listener');
        }
    }

    private function normalizeListenerUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if ($url === '') {
            return '';
        }

        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    private function releaseStaleReservations(): void
    {
        PrintJob::query()
            ->where('status', 'printing')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', now()->subMinutes(10))
            ->update([
                'status' => 'pending',
                'reserved_by' => null,
                'reserved_at' => null,
                'next_attempt_at' => null,
                'last_error' => 'Most wydruku nie potwierdził zadania w czasie 10 minut.',
            ]);
    }
}
