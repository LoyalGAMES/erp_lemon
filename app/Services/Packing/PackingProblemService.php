<?php

declare(strict_types=1);

namespace App\Services\Packing;

use App\Models\CustomerMessage;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Services\Audit\AuditLogService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\StockReservationService;
use App\Services\WooCommerce\WooCommerceOrderStatusService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

final class PackingProblemService
{
    private const ORDER_LOCK_SECONDS = 180;

    public function __construct(
        private readonly PackingTaskService $packingTasks,
        private readonly WooCommerceOrderStatusService $orderStatuses,
        private readonly StockReservationService $reservations,
        private readonly CustomerCommunicationService $communication,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<int|string>  $taskIds
     * @return array{tasks:int,orders:int,warnings:list<string>}
     */
    public function reportTasks(array $taskIds, string $reason): array
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
            $result = $this->reportOrder($order, $reason);
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
    public function reportOrder(ExternalOrder $order, string $reason): array
    {
        try {
            return Cache::lock('packing-fulfillment-order-'.$order->id, self::ORDER_LOCK_SECONDS)
                ->block(10, fn (): array => $this->reportOrderWhileLocked($order, $reason));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'To zamówienie jest właśnie aktualizowane. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * @return array{tasks:int,orders:int,warnings:list<string>}
     */
    private function reportOrderWhileLocked(ExternalOrder $order, string $reason): array
    {
        $order = ExternalOrder::query()->findOrFail($order->id);
        $taskStatuses = PackingTask::query()
            ->where('external_order_id', $order->id)
            ->whereIn('status', ['open', 'picked', 'problem'])
            ->pluck('status');

        if ($taskStatuses->isEmpty()) {
            throw new RuntimeException('To zamówienie nie ma aktywnych pozycji kompletacji ani pakowania.');
        }

        if ($order->status !== 'cancelled') {
            try {
                $this->orderStatuses->markCancelledForPackingProblem($order);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'Nie udało się anulować zamówienia w WooCommerce: '.$exception->getMessage(),
                    previous: $exception,
                );
            }
            $order->refresh();
        }

        if ($taskStatuses->contains(fn (string $status): bool => in_array($status, ['open', 'picked'], true))) {
            $this->packingTasks->markOrderProblem($order, $reason);
        }

        $warnings = [];
        try {
            $this->reservations->syncForOrder($order);
        } catch (Throwable $exception) {
            $warnings[] = 'Rezerwacje magazynowe: '.$exception->getMessage();
        }

        $message = $this->communication->sendOrderStatus($order, 'order_cancelled_problem', [
            'problem_note' => $reason,
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
            'warnings' => $warnings,
        ]);

        return [
            'tasks' => $taskCount,
            'orders' => 1,
            'warnings' => $warnings,
        ];
    }
}
