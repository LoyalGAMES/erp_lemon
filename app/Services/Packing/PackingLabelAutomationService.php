<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\ShippingLabel;
use App\Services\Printing\ShippingLabelPrintQueueService;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PackingLabelAutomationService
{
    private const GENERATION_LOCK_SECONDS = 900;

    public function __construct(
        private readonly ShippingLabelService $shippingLabels,
        private readonly ShippingLabelPrintQueueService $printQueue,
    ) {}

    /**
     * @return array{status:string,label:?ShippingLabel,message:?string}
     */
    public function ensureForOrder(ExternalOrder $order): array
    {
        $lock = Cache::lock('packing-label-order-'.$order->id, self::GENERATION_LOCK_SECONDS);
        $result = $lock->get(fn (): array => $this->ensureWhileLocked($order));

        if (! is_array($result)) {
            return [
                'status' => 'busy',
                'label' => null,
                'message' => 'Generowanie etykiety dla tego zamówienia już trwa.',
            ];
        }

        return $result;
    }

    /**
     * Reconcylia etykiety dla zamówień, które przeszły kompletację, np. gdy
     * krótkotrwała awaria API uniemożliwiła utworzenie etykiety od razu.
     *
     * @return array{checked:int,generated:int,existing:int,failed:int,warnings:list<string>}
     */
    public function generateReadyOrders(int $limit = 25): array
    {
        $orders = ExternalOrder::query()
            ->whereHas('packingTasks', fn ($query) => $query->whereIn('status', ['picked', 'packed']))
            ->whereDoesntHave('packingTasks', fn ($query) => $query->whereIn('status', ['open', 'problem', 'shipped']))
            ->whereDoesntHave('shipmentLabels', fn ($query) => $query->where('status', 'generated'))
            ->where(function ($query): void {
                $query->whereNull('label_generation_next_at')
                    ->orWhere('label_generation_next_at', '<=', now());
            })
            ->where(fn ($query) => $query->whereNull('fulfillment_status')->orWhere('fulfillment_status', '!=', 'shipped'))
            ->orderByRaw('case when label_generation_next_at is null then 0 else 1 end')
            ->orderBy('label_generation_next_at')
            ->oldest('id')
            ->limit(max(1, $limit))
            ->get();

        $summary = [
            'checked' => 0,
            'generated' => 0,
            'existing' => 0,
            'failed' => 0,
            'warnings' => [],
        ];

        foreach ($orders as $order) {
            $summary['checked']++;
            $result = $this->ensureForOrder($order);

            if ($result['status'] === 'generated') {
                $summary['generated']++;
            } elseif ($result['status'] === 'existing') {
                $summary['existing']++;
            } elseif (in_array($result['status'], ['failed', 'busy'], true)) {
                $summary['failed']++;
                if (filled($result['message'])) {
                    $summary['warnings'][] = "Zamówienie {$order->external_number}: {$result['message']}";
                }
            }
        }

        return $summary;
    }

    /**
     * @return array{status:string,label:?ShippingLabel,message:?string}
     */
    private function ensureWhileLocked(ExternalOrder $order): array
    {
        $tasks = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->get();

        if ($tasks->isEmpty()
            || $tasks->contains(fn (PackingTask $task): bool => in_array($task->status, ['open', 'problem', 'shipped'], true))
            || ! $tasks->contains(fn (PackingTask $task): bool => in_array($task->status, ['picked', 'packed'], true))) {
            return ['status' => 'ineligible', 'label' => null, 'message' => null];
        }

        $order->update([
            'fulfillment_status' => $tasks->every(fn (PackingTask $task): bool => $task->status === 'packed')
                ? 'awaiting_courier'
                : 'ready_to_pack',
        ]);

        $existing = ShippingLabel::query()
            ->shipments()
            ->where('external_order_id', $order->id)
            ->where('status', 'generated')
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if ($existing instanceof ShippingLabel) {
            $this->recordOrderResult($order, true);
            $this->recordResult($tasks, 'existing', $existing);

            return ['status' => 'existing', 'label' => $existing, 'message' => null];
        }

        try {
            $label = $this->shippingLabels->generateForOrder($order);
            $message = $this->enqueueDelayedPrint($order, $label);
            $this->recordOrderResult($order, true);
            $this->recordResult($tasks, 'generated', $label, $message);

            return ['status' => 'generated', 'label' => $label, 'message' => $message];
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $this->recordOrderResult($order, false, $message);
            $this->recordResult($tasks, 'failed', null, $message);

            return ['status' => 'failed', 'label' => null, 'message' => $message];
        }
    }

    /**
     * @param  iterable<PackingTask>  $tasks
     */
    private function recordResult(iterable $tasks, string $status, ?ShippingLabel $label, ?string $message = null): void
    {
        foreach ($tasks as $task) {
            DB::transaction(function () use ($task, $status, $label, $message): void {
                $freshTask = PackingTask::query()->lockForUpdate()->find($task->id);

                if (! $freshTask instanceof PackingTask) {
                    return;
                }

                $metadata = (array) $freshTask->metadata;
                $metadata['label_automation'] = [
                    'status' => $status,
                    'label_id' => $label?->id,
                    'message' => $message,
                    'checked_at' => now()->toISOString(),
                ];

                $freshTask->update(['metadata' => $metadata]);
            });
        }
    }

    /**
     * Gdy etykieta nie była dostępna w chwili kliknięcia „Spakuj”, późniejsza
     * próba zachowuje intencję wydruku na wybranym wtedy stanowisku.
     */
    private function enqueueDelayedPrint(ExternalOrder $order, ShippingLabel $label): ?string
    {
        $tasks = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', '!=', 'cancelled')
            ->get();

        if ($tasks->isEmpty() || ! $tasks->every(fn (PackingTask $task): bool => $task->status === 'packed')) {
            return null;
        }

        $station = data_get($tasks->first()?->metadata, 'packing_completion.print_station');

        if (! is_array($station) || blank($station['printer_name'] ?? null)) {
            return null;
        }

        try {
            $job = $this->printQueue->enqueueForStation($label, $station, 'packing.label.retry');

            return $job === null ? null : 'Etykieta po ponowieniu została dodana do kolejki wydruku.';
        } catch (Throwable $exception) {
            return 'Etykieta powstała, ale nie udało się dodać wydruku do kolejki: '.$exception->getMessage();
        }
    }

    private function recordOrderResult(ExternalOrder $order, bool $success, ?string $message = null): void
    {
        if ($success) {
            $order->update([
                'label_generation_attempts' => 0,
                'label_generation_next_at' => null,
                'label_generation_last_error' => null,
            ]);

            return;
        }

        $attempts = max(0, (int) $order->label_generation_attempts) + 1;
        $delay = min(360, 5 * (2 ** min(6, max(0, $attempts - 1))));
        $order->update([
            'label_generation_attempts' => $attempts,
            'label_generation_next_at' => now()->addMinutes($delay),
            'label_generation_last_error' => $message,
        ]);
    }
}
