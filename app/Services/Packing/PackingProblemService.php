<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Services\Audit\AuditLogService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Orders\OrderCancellationService;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

final class PackingProblemService
{
    public function __construct(
        private readonly PackingTaskService $packingTasks,
        private readonly OrderCancellationService $cancellations,
        private readonly CustomerCommunicationService $communication,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<int|string>  $taskIds
     * @return array{tasks:int,orders:int,warnings:list<string>}
     */
    public function reportTasks(array $taskIds, string $reason, bool $restoreStock = true): array
    {
        $ids = collect($taskIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $orders = ExternalOrder::query()
            ->whereHas('packingTasks', fn ($query) => $query
                ->whereIn('id', $ids)
                ->whereIn('status', ['open', 'picked', 'problem']))
            ->get();

        if ($orders->isEmpty()) {
            throw new RuntimeException('Nie znaleziono aktywnego zamówienia do oznaczenia jako problem.');
        }

        $tasks = 0;
        $warnings = [];

        foreach ($orders as $order) {
            $result = $this->reportOrder($order, $reason, $restoreStock);
            $tasks += $result['tasks'];
            $warnings = array_merge($warnings, $result['warnings']);
        }

        return [
            'tasks' => $tasks,
            'orders' => $orders->count(),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{tasks:int,orders:int,warnings:list<string>}
     */
    public function reportOrder(ExternalOrder $order, string $reason, bool $restoreStock = true): array
    {
        $order = ExternalOrder::query()->findOrFail($order->id);
        $taskStatuses = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->whereIn('status', ['open', 'picked', 'problem'])
            ->pluck('status');

        if ($taskStatuses->isEmpty()) {
            throw new RuntimeException('To zamówienie nie ma aktywnych pozycji kompletacji ani pakowania.');
        }

        try {
            $cancellationResult = $this->cancellations->cancelForPackingProblem(
                $order,
                $reason,
                Auth::id(),
                $restoreStock,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Nie udało się bezpiecznie anulować zamówienia: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        $order->refresh();
        $warnings = array_values(array_unique((array) ($cancellationResult['warnings'] ?? [])));
        $stockRestored = (bool) data_get(
            $cancellationResult['cancellation']->metadata,
            'context.restore_stock',
            true,
        );

        if ($order->status !== 'cancelled') {
            $details = $warnings === []
                ? 'Wymagane jest ręczne cofnięcie wysyłki przed zwrotem środków.'
                : implode(' | ', $warnings);

            throw new RuntimeException(
                'Anulowanie zostało bezpiecznie wstrzymane. '.$details,
            );
        }

        $message = $this->communication->sendOrderStatus($order, 'order_cancelled_problem', [
            'problem_note' => $reason,
            'cancellation_uuid' => $cancellationResult['cancellation']->uuid,
            'refund_status' => $cancellationResult['cancellation']->refund_status,
            'stock_restored' => $stockRestored,
        ]);

        if (! $message instanceof CustomerMessage) {
            $message = CustomerMessage::query()
                ->where('external_order_id', $order->id)
                ->where('trigger', 'order_cancelled_problem')
                ->latest('id')
                ->first();
        }

        if ($message instanceof CustomerMessage && in_array($message->status, ['failed', 'skipped'], true)) {
            $warnings[] = "E-mail do klienta ma status: {$message->status}.";
        }

        $this->packingTasks->annotateOrderProblem($order, [
            'reason' => $reason,
            'reported_at' => now()->toISOString(),
            'cancelled_at' => now()->toISOString(),
            'woo_status' => $order->status,
            'customer_message_id' => $message?->id,
            'customer_message_status' => $message?->status,
            'warnings' => $warnings,
            'order_cancellation_uuid' => $cancellationResult['cancellation']->uuid,
            'refund_status' => $cancellationResult['cancellation']->refund_status,
            'stock_restored' => $stockRestored,
        ]);

        $taskCount = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->where('status', 'problem')
            ->count();

        $this->audit->record('packing.order_problem_cancelled', $order, null, [
            'reason' => $reason,
            'tasks' => $taskCount,
            'customer_message_id' => $message?->id,
            'customer_message_status' => $message?->status,
            'order_cancellation_id' => $cancellationResult['cancellation']->id,
            'order_cancellation_uuid' => $cancellationResult['cancellation']->uuid,
            'refund_status' => $cancellationResult['cancellation']->refund_status,
            'stock_restored' => $stockRestored,
            'warnings' => $warnings,
        ]);

        return [
            'tasks' => $taskCount,
            'orders' => 1,
            'warnings' => $warnings,
        ];
    }
}
