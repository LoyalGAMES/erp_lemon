<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PrintJob;
use App\Services\Printing\ShippingLabelPrintQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrintBridgeController extends Controller
{
    public function next(Request $request, ShippingLabelPrintQueueService $queue): JsonResponse
    {
        $data = $request->validate([
            'station' => ['nullable', 'string', 'max:40'],
            'worker' => ['nullable', 'string', 'max:120'],
        ]);

        $job = $queue->claimNext(
            filled($data['station'] ?? null) ? (string) $data['station'] : null,
            filled($data['worker'] ?? null) ? (string) $data['worker'] : null,
        );

        return response()->json([
            'success' => true,
            'job' => $job instanceof PrintJob ? $queue->apiPayload($job) : null,
        ]);
    }

    public function file(PrintJob $job): StreamedResponse
    {
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
        $data = $request->validate([
            'worker' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

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
        $data = $request->validate([
            'worker' => ['nullable', 'string', 'max:120'],
            'error' => ['required', 'string', 'max:2000'],
        ]);

        $job = $queue->markFailed($job, $data['error'], $data['worker'] ?? null);

        return response()->json([
            'success' => true,
            'job' => $queue->apiPayload($job),
        ]);
    }
}
