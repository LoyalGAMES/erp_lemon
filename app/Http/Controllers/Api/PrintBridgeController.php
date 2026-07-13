<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintJob;
use App\Services\Packing\PackingSettingsService;
use App\Services\Printing\PrintBridgeClientRegistry;
use App\Services\Printing\ShippingLabelPrintQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrintBridgeController extends Controller
{
    public function status(
        Request $request,
        PrintBridgeClientRegistry $clients,
        PackingSettingsService $packingSettings,
    ): JsonResponse {
        $request->merge(['station' => $this->normalizeStationCode($request->input('station'))]);
        $data = $request->validate([
            'station' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'worker' => ['required', 'string', 'max:120'],
            'version' => ['nullable', 'string', 'max:40'],
            'printers' => ['present', 'array', 'max:200'],
            'printers.*' => ['array:name,driver,port,default'],
            'printers.*.name' => ['required', 'string', 'max:120'],
            'printers.*.driver' => ['nullable', 'string', 'max:200'],
            'printers.*.port' => ['nullable', 'string', 'max:200'],
            'printers.*.default' => ['required', 'boolean'],
            'printer_error' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->assertConfiguredStation((string) $data['station'], $packingSettings);

        $client = $clients->report(
            (string) $data['station'],
            (string) $data['worker'],
            isset($data['version']) ? (string) $data['version'] : null,
            (array) $data['printers'],
            isset($data['printer_error']) ? (string) $data['printer_error'] : null,
        );

        return response()->json([
            'success' => true,
            'received_at' => $client->last_seen_at?->copy()->utc()->toIso8601String(),
        ]);
    }

    public function next(
        Request $request,
        ShippingLabelPrintQueueService $queue,
        PackingSettingsService $packingSettings,
    ): JsonResponse {
        $request->merge(['station' => $this->normalizeStationCode($request->input('station'))]);
        $data = $request->validate([
            'station' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'worker' => ['required', 'string', 'max:120'],
        ]);
        $this->assertConfiguredStation((string) $data['station'], $packingSettings);

        $job = $queue->claimNext(
            filled($data['station'] ?? null) ? (string) $data['station'] : null,
            filled($data['worker'] ?? null) ? (string) $data['worker'] : null,
        );

        return response()->json([
            'success' => true,
            'job' => $job instanceof PrintJob ? $queue->apiPayload($job) : null,
        ]);
    }

    public function file(Request $request, PrintJob $job, ShippingLabelPrintQueueService $queue): StreamedResponse
    {
        $queue->assertLease(
            $job,
            (string) $request->header('X-Print-Lease', ''),
            (string) $request->header('X-Print-Worker', ''),
            $this->normalizeStationCode($request->header('X-Print-Station', '')),
        );
        $label = $job->shippingLabel;

        if ($label === null || ! Storage::disk($label->disk)->exists($label->path)) {
            abort(404);
        }

        return Storage::disk($label->disk)->download($label->path, $label->filename(), [
            'Content-Type' => $label->mime_type ?? 'application/pdf',
        ]);
    }

    public function printed(Request $request, PrintJob $job, ShippingLabelPrintQueueService $queue): JsonResponse
    {
        $request->merge(['station' => $this->normalizeStationCode($request->input('station'))]);
        $data = $request->validate([
            'worker' => ['required', 'string', 'max:120'],
            'station' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'lease_token' => ['required', 'string', 'size:64'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $queue->assertLease($job, $data['lease_token'], (string) ($data['worker'] ?? ''), $data['station'], ['printing', 'printed']);
        $job = $queue->markPrinted($job, $data['worker'] ?? null, [
            'bridge_message' => $data['message'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'job' => $queue->apiPayload($job),
        ]);
    }

    public function failed(Request $request, PrintJob $job, ShippingLabelPrintQueueService $queue): JsonResponse
    {
        $request->merge(['station' => $this->normalizeStationCode($request->input('station'))]);
        $data = $request->validate([
            'worker' => ['required', 'string', 'max:120'],
            'station' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'lease_token' => ['required', 'string', 'size:64'],
            'error' => ['required', 'string', 'max:2000'],
        ]);

        $queue->assertLease($job, $data['lease_token'], (string) ($data['worker'] ?? ''), $data['station'], ['printing', 'pending', 'failed']);
        $job = $queue->markFailed($job, $data['error'], $data['worker'] ?? null);

        return response()->json([
            'success' => true,
            'job' => $queue->apiPayload($job),
        ]);
    }

    private function normalizeStationCode(mixed $value): string
    {
        $code = mb_strtolower(trim((string) $value));

        return preg_replace('/[^a-z0-9_-]+/', '-', $code) ?? '';
    }

    private function assertConfiguredStation(string $stationCode, PackingSettingsService $packingSettings): void
    {
        if ($packingSettings->station($stationCode) !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'station' => 'Kod stanowiska nie jest skonfigurowany w ERP.',
        ]);
    }
}
