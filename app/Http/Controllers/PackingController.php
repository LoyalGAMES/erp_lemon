<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\ShippingLabel;
use App\Services\Packing\PackingFulfillmentService;
use App\Services\Packing\PackingTaskService;
use App\Services\Shipping\ShippingLabelService;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PackingController extends Controller
{
    public function index(Request $request, PackingTaskService $packing): View
    {
        $sync = $packing->syncReadyOrders();
        $mode = (string) session('packing_mode', 'hybrid');
        $requestedView = (string) $request->query('view', 'home');
        $availableViews = ['home', 'collect', 'pack', 'history'];

        if (in_array($requestedView, $availableViews, true)) {
            session(['packing_view' => $requestedView]);
        }

        $packingView = in_array($requestedView, $availableViews, true)
            ? $requestedView
            : 'home';

        if (! in_array($packingView, $availableViews, true)) {
            $packingView = 'home';
        }

        $packingHistoryDate = $this->historyDate((string) $request->query('date', now()->toDateString()));

        $tasks = PackingTask::query()
            ->with(['salesChannel', 'order.shippingLabels', 'product'])
            ->whereIn('status', ['open', 'picked'])
            ->orderByRaw("case when status = 'picked' then 1 else 0 end")
            ->orderBy('courier')
            ->orderBy('order_date')
            ->orderBy('size_label')
            ->get();

        $problemTasks = PackingTask::query()
            ->with(['salesChannel', 'order.shippingLabels', 'product'])
            ->where('status', 'problem')
            ->orderBy('updated_at')
            ->get();

        $recentPickedTasks = PackingTask::query()
            ->with(['salesChannel', 'order.shippingLabels', 'product'])
            ->whereIn('status', ['picked', 'packed', 'shipped'])
            ->whereNotNull('picked_at')
            ->latest('picked_at')
            ->limit(20)
            ->get();

        $waitingCourierTasks = PackingTask::query()
            ->with(['salesChannel', 'order.shippingLabels', 'product'])
            ->where('status', 'packed')
            ->orderBy('courier')
            ->orderBy('packed_at')
            ->get();

        return view('packing.index', [
            'sync' => $sync,
            'tasks' => $tasks,
            'openTasks' => $tasks->where('status', 'open')->values(),
            'pickedTasks' => $tasks->where('status', 'picked')->values(),
            'pickGroups' => $this->pickGroups($tasks->where('status', 'open')->values()),
            'readyOrders' => $this->readyOrders($tasks),
            'problemTasks' => $problemTasks,
            'recentPickedTasks' => $recentPickedTasks,
            'waitingCourierGroups' => $this->waitingCourierGroups($waitingCourierTasks),
            'packingHistoryDate' => $packingHistoryDate->toDateString(),
            'packingHistoryOrders' => $packingView === 'history'
                ? $this->packingHistoryOrders($packingHistoryDate)
                : collect(),
            'packingMode' => in_array($mode, ['manual', 'hybrid', 'scanner'], true) ? $mode : 'hybrid',
            'packingView' => $packingView,
            'packedToday' => PackingTask::query()
                ->whereIn('status', ['packed', 'shipped'])
                ->whereDate('packed_at', now()->toDateString())
                ->count(),
        ]);
    }

    public function mode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', 'in:manual,hybrid,scanner'],
        ]);

        session(['packing_mode' => $data['mode']]);

        return back()->with('status', match ($data['mode']) {
            'manual' => 'Tryb pakowania ustawiony na pracę bez skanera.',
            'scanner' => 'Tryb pakowania ustawiony na skaner.',
            default => 'Tryb pakowania ustawiony na hybrydowy.',
        });
    }

    public function scan(Request $request, PackingTaskService $packing): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
        ]);

        try {
            $task = $packing->scan($data['code']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', sprintf(
            'Zebrano %s: %s (%s/%s).',
            $task->sku ?: 'produkt',
            $task->product_name,
            number_format((float) $task->quantity_picked, 0, ',', ' '),
            number_format((float) $task->quantity_required, 0, ',', ' '),
        ));
    }

    public function pick(Request $request, PackingTaskService $packing): RedirectResponse
    {
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer', 'exists:packing_tasks,id'],
        ]);

        try {
            $count = $packing->markPickedMany($data['task_ids']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Oznaczono {$count} pozycji jako zebrane. Zamówienia trafiły do kolejki pakowania.");
    }

    public function problem(Request $request, PackingTaskService $packing): RedirectResponse
    {
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer', 'exists:packing_tasks,id'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $reason = trim((string) ($data['reason'] ?? '')) ?: 'Do wyjaśnienia';

        try {
            $count = $packing->markProblemMany($data['task_ids'], $reason);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Oznaczono {$count} pozycji jako problem: {$reason}.");
    }

    public function pack(PackingTask $task, PackingTaskService $packing): RedirectResponse
    {
        try {
            $packing->markPacked($task);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Oznaczono pozycję {$task->sku} jako spakowaną.");
    }

    public function packOrder(ExternalOrder $order, PackingFulfillmentService $fulfillment): RedirectResponse
    {
        try {
            $result = $fulfillment->completePackedOrder($order);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = "Spakowano zamówienie {$order->external_number}: {$result['packed']} pozycji. Zamówienie trafiło do listy oczekujących na kuriera.";

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia automatyzacji: ' . implode(' | ', $result['warnings']);
        }

        return back()->with('status', $message);
    }

    public function unpackOrder(Request $request, ExternalOrder $order, PackingFulfillmentService $fulfillment): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $result = $fulfillment->undoPackedOrder($order, $data['reason'] ?? null);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = "Cofnięto pakowanie zamówienia {$order->external_number}: {$result['tasks']} pozycji wróciło do kolejki pakowania. WZ, faktura i etykieta pozostają w historii.";

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia: ' . implode(' | ', $result['warnings']);
        }

        return back()->with('status', $message);
    }

    public function courierPickup(Request $request, PackingFulfillmentService $fulfillment): RedirectResponse
    {
        $data = $request->validate([
            'courier' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $fulfillment->markCourierPickedUp($data['courier']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = "Oznaczono odbiór kuriera {$data['courier']}: {$result['orders']} zamówień, {$result['tasks']} pozycji. Status w WooCommerce zmieniono na wysłano.";

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia: ' . implode(' | ', $result['warnings']);
        }

        return back()->with('status', $message);
    }

    public function problemOrder(Request $request, ExternalOrder $order, PackingTaskService $packing): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $reason = trim((string) ($data['reason'] ?? '')) ?: 'Problem z zamówieniem';

        try {
            $count = $packing->markOrderProblem($order, $reason);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Przeniesiono zamówienie {$order->external_number} do wyjaśnienia: {$count} pozycji.");
    }

    public function reopen(PackingTask $task, PackingTaskService $packing): RedirectResponse
    {
        try {
            $packing->reopenProblem($task);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Przywrócono pozycję {$task->sku} do kolejki.");
    }

    public function label(ExternalOrder $order, ShippingLabelService $shippingLabels): RedirectResponse
    {
        try {
            $label = $shippingLabels->generateForOrder($order);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Nie udało się wygenerować etykiety: ' . $exception->getMessage());
        }

        return back()->with('status', "Etykieta dla zamówienia {$order->external_number} została pobrana do ERP: {$label->filename()}.");
    }

    public function downloadLabel(ShippingLabel $label): StreamedResponse
    {
        if (! Storage::disk($label->disk)->exists($label->path)) {
            abort(404);
        }

        return Storage::disk($label->disk)->download($label->path, $label->filename(), [
            'Content-Type' => $label->mime_type ?? 'application/pdf',
        ]);
    }

    /**
     * @param Collection<int, PackingTask> $openTasks
     * @return Collection<int, array<string, mixed>>
     */
    private function pickGroups(Collection $openTasks): Collection
    {
        return $openTasks
            ->groupBy(fn (PackingTask $task): string => implode('|', [
                $task->courier ?: '-',
                $task->size_label ?: '-',
                $task->sku ?: 'no-sku',
                (string) ($task->product_id ?: 0),
                $task->product_name,
            ]))
            ->map(function (Collection $group): array {
                /** @var PackingTask $first */
                $first = $group->first();
                $oldest = $group->sortBy('order_date')->first()?->order_date;
                $location = $this->taskLocation($first);

                return [
                    'product_name' => $first->product_name,
                    'sku' => $first->sku,
                    'courier' => $first->courier ?: 'Nieznany kurier',
                    'size_label' => $first->size_label ?: '-',
                    'location' => $location,
                    'quantity' => $group->sum(fn (PackingTask $task): float => $task->remainingQuantity()),
                    'orders_count' => $group->pluck('external_order_id')->unique()->count(),
                    'order_numbers' => $group->pluck('order_number')->filter()->unique()->take(6)->implode(', '),
                    'oldest_order_at' => $oldest,
                    'image_url' => $first->product?->imageUrl(),
                    'task_ids' => $group->pluck('id')->values()->all(),
                    'sort_key' => implode('|', [
                        $location ?: 'ZZZ',
                        $first->courier ?: '',
                        optional($oldest)->timestamp ?? 0,
                        $first->size_label ?: '',
                        $first->sku ?: '',
                        $first->product_name,
                    ]),
                ];
            })
            ->sortBy('sort_key')
            ->values();
    }

    private function taskLocation(PackingTask $task): string
    {
        foreach ([
            'warehouse_location',
            'product.attributes.master.stock.location',
            'product.attributes.warehouse_location',
        ] as $path) {
            $value = trim((string) data_get($task, $path, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '-';
    }

    /**
     * @param Collection<int, PackingTask> $tasks
     * @return Collection<int, ExternalOrder>
     */
    private function readyOrders(Collection $tasks): Collection
    {
        return $tasks
            ->groupBy('external_order_id')
            ->filter(fn (Collection $group): bool => $group->where('status', 'open')->isEmpty()
                && $group->where('status', 'picked')->isNotEmpty())
            ->map(function (Collection $group): ?ExternalOrder {
                /** @var PackingTask|null $first */
                $first = $group->first();
                $order = $first?->order;

                if (! $order instanceof ExternalOrder) {
                    return null;
                }

                $order->setRelation('packingTasks', $group->sortBy('product_name')->values());

                return $order;
            })
            ->filter()
            ->sortBy(fn (ExternalOrder $order): string => implode('|', [
                $order->packingTasks->first()?->courier ?: '',
                optional($order->external_created_at)->timestamp ?? 0,
                $order->external_number,
            ]))
            ->values();
    }

    /**
     * @param Collection<int, PackingTask> $packedTasks
     * @return Collection<int, array<string, mixed>>
     */
    private function waitingCourierGroups(Collection $packedTasks): Collection
    {
        return $packedTasks
            ->groupBy(fn (PackingTask $task): string => $task->courier ?: 'Nieznany kurier')
            ->map(function (Collection $group, string $courier): array {
                $orders = $group
                    ->groupBy('external_order_id')
                    ->map(function (Collection $orderTasks): ?array {
                        /** @var PackingTask|null $first */
                        $first = $orderTasks->sortBy('packed_at')->first();
                        $order = $first?->order;

                        if (! $order instanceof ExternalOrder || ! $first instanceof PackingTask) {
                            return null;
                        }

                        return [
                            'id' => $order->id,
                            'external_number' => $order->external_number,
                            'customer_name' => $first->customer_name ?: '-',
                            'tasks_count' => $orderTasks->count(),
                            'packed_at' => $first->packed_at,
                        ];
                    })
                    ->filter()
                    ->sortBy('packed_at')
                    ->values();

                return [
                    'courier' => $courier,
                    'orders_count' => $orders->count(),
                    'tasks_count' => $group->count(),
                    'order_numbers' => $orders->pluck('external_number')->filter()->values()->all(),
                    'orders' => $orders->all(),
                    'oldest_packed_at' => $group->sortBy('packed_at')->first()?->packed_at,
                ];
            })
            ->sortBy('courier')
            ->values();
    }

    private function historyDate(string $date): Carbon
    {
        try {
            return Carbon::parse($date)->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function packingHistoryOrders(Carbon $date): Collection
    {
        return PackingTask::query()
            ->with(['salesChannel', 'order.shippingLabels', 'product'])
            ->whereIn('status', ['packed', 'shipped'])
            ->whereDate('packed_at', $date->toDateString())
            ->orderByDesc('packed_at')
            ->get()
            ->groupBy('external_order_id')
            ->map(function (Collection $group): ?array {
                /** @var PackingTask|null $first */
                $first = $group->sortByDesc('packed_at')->first();
                $order = $first?->order;

                if (! $first instanceof PackingTask) {
                    return null;
                }

                $pickupAt = $group
                    ->map(fn (PackingTask $task): ?string => data_get($task->metadata, 'courier_pickup.picked_up_at'))
                    ->filter()
                    ->sort()
                    ->last();

                return [
                    'order_id' => $order instanceof ExternalOrder ? $order->id : null,
                    'order_number' => $order instanceof ExternalOrder ? $order->external_number : $first->order_number,
                    'customer_name' => $first->customer_name ?: '-',
                    'sales_channel' => $first->salesChannel?->code ?? '-',
                    'courier' => $first->courier ?: 'Nieznany kurier',
                    'status' => $group->every(fn (PackingTask $task): bool => $task->status === 'shipped') ? 'shipped' : 'packed',
                    'tasks_count' => $group->count(),
                    'packed_at' => $group->sortBy('packed_at')->first()?->packed_at,
                    'last_packed_at' => $first->packed_at,
                    'pickup_at' => $pickupAt ? Carbon::parse($pickupAt) : null,
                    'items' => $group
                        ->sortBy('product_name')
                        ->map(fn (PackingTask $task): array => [
                            'name' => $task->product_name,
                            'sku' => $task->sku,
                            'size_label' => $task->size_label,
                            'quantity' => $task->quantity_required,
                            'image_url' => $task->product?->imageUrl(),
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->sortByDesc('last_packed_at')
            ->values();
    }
}
