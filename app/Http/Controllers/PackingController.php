<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\PrintJob;
use App\Models\ShippingLabel;
use App\Services\Packing\PackingFulfillmentService;
use App\Services\Packing\PackingProblemService;
use App\Services\Packing\PackingSettingsService;
use App\Services\Packing\PackingTaskService;
use App\Services\Packing\ProductSegmentService;
use App\Services\Printing\ShippingLabelPrintQueueService;
use App\Services\Shipping\CourierPickupTrackingService;
use App\Services\Shipping\ShippingLabelService;
use App\Services\Shipping\ShippingProviderResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PackingController extends Controller
{
    /** @var list<string> */
    private const DOWNLOADABLE_SHIPMENT_LABEL_STATUSES = ['generated', 'picked_up', 'delivered'];

    public function index(
        Request $request,
        PackingTaskService $packing,
        PackingSettingsService $settings,
        ProductSegmentService $segments,
        ShippingProviderResolver $providers,
    ): View {
        $sync = $packing->syncReadyOrders();
        $mode = (string) session('packing_mode', 'hybrid');
        $packingSettings = $settings->data();
        $activeStation = $settings->station((string) session('packing_station', ''));
        $requestedView = (string) $request->query('view', 'home');
        $availableViews = ['home', 'collect', 'pack', 'waiting', 'shipped', 'problems', 'history'];

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
            ->with(['salesChannel', 'order.shipmentLabels.courierAccount', 'product', 'orderLine'])
            ->whereIn('status', ['open', 'picked'])
            ->orderByRaw("case when status = 'picked' then 1 else 0 end")
            ->orderBy('courier')
            ->orderBy('order_date')
            ->orderBy('size_label')
            ->get();

        $problemTasks = PackingTask::query()
            ->with(['salesChannel', 'order.shipmentLabels.courierAccount', 'product', 'orderLine'])
            ->where('status', 'problem')
            ->orderBy('updated_at')
            ->get();

        $recentPickedTasks = PackingTask::query()
            ->with(['salesChannel', 'order.shipmentLabels.courierAccount', 'product', 'orderLine'])
            ->whereIn('status', ['picked', 'packed', 'shipped'])
            ->whereNotNull('picked_at')
            ->latest('picked_at')
            ->limit(20)
            ->get();

        $waitingCourierTasks = PackingTask::query()
            ->with(['salesChannel', 'order.shipmentLabels.courierAccount', 'product', 'orderLine'])
            ->where('status', 'packed')
            ->whereHas('order', fn ($query) => $query
                ->whereDoesntHave('packingTasks', fn ($tasks) => $tasks->whereNotIn('status', ['packed', 'cancelled'])))
            ->orderBy('courier')
            ->orderBy('packed_at')
            ->get();

        $requestedSegment = (string) $request->query('segment', '');
        $activeSegment = in_array($requestedSegment, ['all', ProductSegmentService::SEGMENT_CLOTHING, ProductSegmentService::SEGMENT_FOOTWEAR], true)
            ? $requestedSegment
            : ($activeStation['segment'] ?? 'all');

        $openTasks = $tasks->where('status', 'open')->values();
        $collectOrdersBySegment = [
            'all' => $this->collectOrders($openTasks, $segments),
            ProductSegmentService::SEGMENT_CLOTHING => $this->collectOrders(
                $openTasks->filter(fn (PackingTask $task): bool => $segments->segmentForTask($task) === ProductSegmentService::SEGMENT_CLOTHING)->values(),
                $segments,
            ),
            ProductSegmentService::SEGMENT_FOOTWEAR => $this->collectOrders(
                $openTasks->filter(fn (PackingTask $task): bool => $segments->segmentForTask($task) === ProductSegmentService::SEGMENT_FOOTWEAR)->values(),
                $segments,
            ),
        ];
        $readyOrders = $this->readyOrders($tasks, $segments, $providers);
        $shippedOrdersCount = PackingTask::query()
            ->where('status', 'shipped')
            ->distinct()
            ->count('external_order_id');

        return view('packing.index', [
            'sync' => $sync,
            'tasks' => $tasks,
            'openTasks' => $openTasks,
            'pickedTasks' => $tasks->where('status', 'picked')->values(),
            'collectOrders' => $collectOrdersBySegment[$activeSegment] ?? collect(),
            'collectOrdersCount' => ($collectOrdersBySegment[$activeSegment] ?? collect())->count(),
            'segmentCounts' => [
                'all' => $collectOrdersBySegment['all']->count(),
                ProductSegmentService::SEGMENT_CLOTHING => $collectOrdersBySegment[ProductSegmentService::SEGMENT_CLOTHING]->count(),
                ProductSegmentService::SEGMENT_FOOTWEAR => $collectOrdersBySegment[ProductSegmentService::SEGMENT_FOOTWEAR]->count(),
            ],
            'activeSegment' => $activeSegment,
            'packingStations' => $packingSettings['stations'],
            'activeStation' => $activeStation,
            'courierAccounts' => CourierAccount::query()
                ->where('is_active', true)
                ->where('provider', 'inpost')
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'readyOrders' => $activeSegment === 'all'
                ? $readyOrders
                : $readyOrders
                    ->filter(fn (ExternalOrder $order): bool => in_array($activeSegment, $order->packing_segments ?? [], true))
                    ->values(),
            'problemTasks' => $problemTasks,
            'recentPickedTasks' => $recentPickedTasks,
            'waitingCourierGroups' => $this->waitingCourierGroups($waitingCourierTasks, $providers),
            'shippedOrders' => $packingView === 'shipped'
                ? $this->shippedOrders($providers)
                : collect(),
            'shippedOrdersCount' => $shippedOrdersCount,
            'packingHistoryDate' => $packingHistoryDate->toDateString(),
            'packingHistoryOrders' => $packingView === 'history'
                ? $this->packingHistoryOrders($packingHistoryDate, $providers)
                : collect(),
            'packingMode' => in_array($mode, ['manual', 'hybrid', 'scanner'], true) ? $mode : 'hybrid',
            'packingView' => $packingView,
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

    public function station(Request $request, PackingSettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'station' => ['nullable', 'string', 'max:40'],
        ]);

        $station = $settings->station($data['station'] ?? null);

        if ($station === null) {
            session()->forget('packing_station');

            return back()->with('status', 'Praca bez przypisanego stanowiska pakowania.');
        }

        session(['packing_station' => $station['code']]);

        $printer = $station['printer_name'] !== '' ? " Etykiety: {$station['printer_name']}." : '';

        return back()->with('status', "Pracujesz na: {$station['name']} ({$this->segmentLabel($station['segment'])}).{$printer}");
    }

    private function segmentLabel(string $segment): string
    {
        return match ($segment) {
            ProductSegmentService::SEGMENT_CLOTHING => 'Odzież',
            ProductSegmentService::SEGMENT_FOOTWEAR => 'Obuwie',
            default => 'Wszystkie produkty',
        };
    }

    public function scan(
        Request $request,
        PackingTaskService $packing,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:120'],
        ]);

        try {
            $task = $packing->scan($data['code']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $message = sprintf(
            'Zebrano %s: %s (%s/%s).',
            $task->sku ?: 'produkt',
            $task->product_name,
            number_format((float) $task->quantity_picked, 0, ',', ' '),
            number_format((float) $task->quantity_required, 0, ',', ' '),
        );

        return back()->with('status', $message);
    }

    public function pick(
        Request $request,
        PackingTaskService $packing,
    ): RedirectResponse|JsonResponse {
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer', 'exists:packing_tasks,id'],
        ]);

        try {
            $count = $packing->markPickedMany($data['task_ids']);
        } catch (RuntimeException $exception) {
            return $this->packingActionError($request, $exception->getMessage());
        }

        $message = "Oznaczono {$count} pozycji jako zebrane. Zamówienia trafiły do kolejki pakowania.";

        return $this->packingActionSuccess($request, $message, [
            'action' => 'collect.picked',
            'tasks' => $count,
            'ui' => ['remove_submitted_card' => true, 'destination' => 'pack'],
        ]);
    }

    public function problem(Request $request, PackingProblemService $problems): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer', 'exists:packing_tasks,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'restore_stock' => ['sometimes', 'boolean'],
        ]);

        $reason = trim((string) $data['reason']);

        try {
            $result = $problems->reportTasks($data['task_ids'], $reason, (bool) ($data['restore_stock'] ?? true));
        } catch (RuntimeException $exception) {
            return $this->packingActionError($request, $exception->getMessage());
        }

        $message = "Anulowano {$result['orders']} zamówień i przeniesiono {$result['tasks']} pozycji do listy problemów.";

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia: '.implode(' | ', $result['warnings']);
        }

        return $this->packingActionSuccess($request, $message, [
            'action' => 'collect.problem',
            'tasks' => $result['tasks'],
            'orders' => $result['orders'],
            'warnings' => $result['warnings'],
            'ui' => ['remove_submitted_card' => true, 'destination' => 'problems'],
        ]);
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

    public function packOrder(
        Request $request,
        ExternalOrder $order,
        PackingFulfillmentService $fulfillment,
        PackingSettingsService $settings,
    ): RedirectResponse|JsonResponse {
        try {
            $result = $fulfillment->completePackedOrder(
                $order,
                $settings->station((string) session('packing_station', '')),
            );
        } catch (RuntimeException $exception) {
            return $this->packingActionError($request, $exception->getMessage());
        }

        $message = "Spakowano zamówienie {$order->external_number}: {$result['packed']} pozycji. Zamówienie trafiło do listy oczekujących na kuriera.";

        if ($result['print_job'] !== null) {
            $message .= $this->printJobMessage($result['print_job']);
        }

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia automatyzacji: '.implode(' | ', $result['warnings']);
        }

        return $this->packingActionSuccess($request, $message, [
            'action' => 'packing.completed',
            'order_id' => $order->id,
            'fulfillment_status' => 'awaiting_courier',
            'warnings' => $result['warnings'],
            'ui' => ['remove_submitted_card' => true, 'destination' => 'waiting'],
        ]);
    }

    public function packWithManualShipment(
        Request $request,
        ExternalOrder $order,
        ShippingLabelService $shippingLabels,
        PackingFulfillmentService $fulfillment,
        ShippingProviderResolver $providers,
    ): RedirectResponse|JsonResponse {
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:inpost,gls'],
            'tracking_number' => ['required', 'string', 'regex:/^[0-9A-Za-z-]{8,40}$/'],
        ]);

        try {
            $detectedProvider = $providers->providerForOrder($order);
            if ($detectedProvider !== null && $detectedProvider !== $data['provider']) {
                throw new RuntimeException('Wybrany przewoźnik nie zgadza się z metodą dostawy zamówienia.');
            }
            $activeTasks = $order->packingTasks()->where('status', '!=', 'cancelled')->get();
            if ($activeTasks->isEmpty() || $activeTasks->contains(fn (PackingTask $task): bool => $task->status !== 'picked')) {
                throw new RuntimeException('Najpierw zbierz wszystkie pozycje z tego zamówienia.');
            }
            $label = $shippingLabels->registerManualShipment($order, (string) $data['provider'], (string) $data['tracking_number']);
            $result = $fulfillment->completePackedOrder($order);
        } catch (RuntimeException $exception) {
            return $this->packingActionError($request, $exception->getMessage());
        }

        return $this->packingActionSuccess($request, "Spakowano zamówienie {$order->external_number} z ręcznym numerem ".mb_strtoupper((string) $data['provider'])." {$label->trackingIdentifier()}.", [
            'action' => 'packing.completed_manual_shipment',
            'order_id' => $order->id,
            'warnings' => $result['warnings'],
            'ui' => ['remove_submitted_card' => true, 'destination' => 'waiting'],
        ]);
    }

    public function markOrderShipped(ExternalOrder $order, PackingFulfillmentService $fulfillment): RedirectResponse
    {
        $result = $fulfillment->markOrderPickedUpByCourier($order, [
            'source' => 'manual_order_confirmation',
            'picked_up_at' => now()->toISOString(),
        ]);

        $message = "Zamówienie {$order->external_number} oznaczono jako wysłane.";
        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia: '.implode(' | ', $result['warnings']);
        }

        return back()->with($result['tasks'] > 0 ? 'status' : 'error', $message);
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
            $message .= ' Ostrzeżenia: '.implode(' | ', $result['warnings']);
        }

        return back()->with('status', $message);
    }

    public function courierPickup(Request $request, PackingFulfillmentService $fulfillment): RedirectResponse
    {
        $data = $request->validate([
            'courier' => ['required', 'string', 'max:120'],
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:external_orders,id'],
            'pickup_token' => ['required', 'string', 'size:64'],
        ]);

        if (! hash_equals(
            $this->courierPickupToken($data['courier'], $data['order_ids']),
            $data['pickup_token'],
        )) {
            return back()->with('error', 'Lista paczek zmieniła się albo formularz odbioru jest nieprawidłowy. Odśwież widok i spróbuj ponownie.');
        }

        try {
            $result = $fulfillment->markCourierPickedUp($data['courier'], $data['order_ids'] ?? []);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $message = "Oznaczono odbiór kuriera {$data['courier']}: {$result['orders']} zamówień, {$result['tasks']} pozycji. W ERP zamówienia przeniesiono do wysłanych.";

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia: '.implode(' | ', $result['warnings']);
        }

        return back()->with('status', $message);
    }

    public function checkCourierPickups(CourierPickupTrackingService $tracking): RedirectResponse
    {
        try {
            $result = $tracking->trackPackedOrders(limit: 50, force: true);
        } catch (Throwable $exception) {
            return back()->with('error', 'Nie udało się sprawdzić odbiorów kurierów: '.$exception->getMessage());
        }

        $message = sprintf(
            'Ręcznie sprawdzono odbiory: %d paczek, potwierdzono %d odbiorów, przeniesiono %d zamówień do wysłanych.',
            $result['checked'],
            $result['picked_up'],
            $result['orders'],
        );

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia: '.implode(' | ', $result['warnings']);
        }

        return back()->with('status', $message);
    }

    public function problemOrder(Request $request, ExternalOrder $order, PackingProblemService $problems): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'restore_stock' => ['sometimes', 'boolean'],
        ]);

        $reason = trim((string) $data['reason']);

        try {
            $result = $problems->reportOrder($order, $reason, (bool) ($data['restore_stock'] ?? true));
        } catch (RuntimeException $exception) {
            return $this->packingActionError($request, $exception->getMessage());
        }

        $message = "Anulowano zamówienie {$order->external_number} i przeniesiono {$result['tasks']} pozycji do listy problemów.";

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia: '.implode(' | ', $result['warnings']);
        }

        return $this->packingActionSuccess($request, $message, [
            'action' => 'packing.problem',
            'order_id' => $order->id,
            'tasks' => $result['tasks'],
            'warnings' => $result['warnings'],
            'ui' => ['remove_submitted_card' => true, 'destination' => 'problems'],
        ]);
    }

    public function completeWithLabel(
        Request $request,
        ExternalOrder $order,
        PackingFulfillmentService $fulfillment,
        PackingSettingsService $settings,
        ShippingProviderResolver $providers,
    ): RedirectResponse|JsonResponse {
        if ($providers->providerForOrder($order) === 'gls') {
            return $this->packingActionError($request, 'Dla zamówienia GLS nie można generować etykiety InPost. Podaj ręczny numer GLS albo spakuj bez listu przewozowego.');
        }
        $data = $request->validate([
            'courier_account_id' => [
                'nullable',
                'integer',
                Rule::exists('courier_accounts', 'id')->where(fn ($query) => $query
                    ->where('provider', 'inpost')
                    ->where('is_active', true)),
            ],
            'parcel_template' => ['required', 'string', 'in:small,medium,large'],
        ]);

        $account = filled($data['courier_account_id'] ?? null)
            ? CourierAccount::query()->where('is_active', true)->find((int) $data['courier_account_id'])
            : null;

        try {
            $result = $fulfillment->completePackedOrderWithLabel(
                $order,
                $account,
                $data['parcel_template'],
                $settings->station((string) session('packing_station', '')),
            );
        } catch (RuntimeException $exception) {
            return $this->packingActionError($request, $exception->getMessage());
        }

        $message = $result['already_completed']
            ? "Zamówienie {$order->external_number} było już spakowane. Etykieta pozostaje w kolejce automatycznego wydruku."
            : "Wygenerowano etykietę i spakowano zamówienie {$order->external_number}. Zamówienie oczekuje na kuriera.";

        $message .= $this->printJobMessage($result['print_job']);

        if ($result['warnings'] !== []) {
            $message .= ' Ostrzeżenia automatyzacji: '.implode(' | ', $result['warnings']);
        }

        return $this->packingActionSuccess($request, $message, [
            'action' => 'packing.completed_with_label',
            'order_id' => $order->id,
            'fulfillment_status' => 'awaiting_courier',
            'label' => [
                'id' => $result['label']->id,
                'tracking_number' => $result['label']->trackingIdentifier(),
            ],
            'print_job' => [
                'id' => $result['print_job']->id,
                'status' => $result['print_job']->status,
                'printer_name' => $result['print_job']->printer_name,
            ],
            'warnings' => $result['warnings'],
            'already_completed' => $result['already_completed'],
            'ui' => ['remove_submitted_card' => true, 'destination' => 'waiting'],
        ]);
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

    public function label(
        Request $request,
        ExternalOrder $order,
        ShippingLabelService $shippingLabels,
    ): RedirectResponse {
        $data = $request->validate([
            'courier_account_id' => [
                'nullable',
                'integer',
                Rule::exists('courier_accounts', 'id')->where(fn ($query) => $query
                    ->where('provider', 'inpost')
                    ->where('is_active', true)),
            ],
            'parcel_template' => ['required', 'string', 'in:small,medium,large'],
        ]);

        $account = filled($data['courier_account_id'] ?? null)
            ? CourierAccount::query()->where('is_active', true)->find((int) $data['courier_account_id'])
            : null;

        try {
            $label = $shippingLabels->generateForOrder($order, $account, $data['parcel_template']);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Nie udało się wygenerować etykiety: '.$exception->getMessage());
        }

        $recordedTemplate = data_get($label->response_payload, 'parcel_template');
        $size = match ($recordedTemplate) {
            'small' => 'A',
            'medium' => 'B',
            'large' => 'C',
            default => null,
        };
        $sizeDescription = $size !== null ? " (gabaryt {$size})" : '';
        $message = "Etykieta dla zamówienia {$order->external_number}{$sizeDescription} została pobrana do ERP: {$label->filename()}.";

        if ($account instanceof CourierAccount) {
            $message .= " Konto nadawcze: {$account->name}.";
        }

        return back()->with('status', $message);
    }

    public function downloadLabel(ShippingLabel $label): StreamedResponse
    {
        if (! $this->shipmentLabelCanBeDownloaded($label)) {
            abort(404);
        }

        if (! Storage::disk($label->disk)->exists($label->path)) {
            abort(404);
        }

        return Storage::disk($label->disk)->download($label->path, $label->filename(), [
            'Content-Type' => $label->mime_type ?? 'application/pdf',
        ]);
    }

    public function printLabel(
        Request $request,
        ShippingLabel $label,
        PackingSettingsService $settings,
        ShippingLabelPrintQueueService $printQueue,
    ): RedirectResponse {
        $data = $request->validate([
            'request_token' => ['required', 'string', 'uuid'],
        ]);

        if ($label->purpose !== 'shipment'
            || $label->external_order_id === null
            || $label->status !== 'generated') {
            abort(404);
        }

        $station = $settings->station((string) session('packing_station', ''));
        $printJob = $printQueue->requeueForStation(
            $label,
            $station,
            'packing.waiting.manual',
            $data['request_token'],
        );

        if (! $printJob instanceof PrintJob) {
            return back()->with('error', 'Wybierz stanowisko pakowania z przypisaną drukarką Windows.');
        }

        return back()->with(
            'status',
            'Zlecono wydruk etykiety '.$label->filename().'.'.$this->printJobMessage($printJob),
        );
    }

    private function printJobMessage(PrintJob $printJob): string
    {
        if ($printJob->status === 'printed') {
            return " Etykieta została wydrukowana przez most Windows: {$printJob->printer_name}.";
        }

        if (filled($printJob->last_error)) {
            return " Most Windows nie wydrukował etykiety ({$printJob->printer_name}): {$printJob->last_error}";
        }

        return " Etykieta została dodana do kolejki wydruku: {$printJob->printer_name}.";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function packingActionSuccess(
        Request $request,
        string $message,
        array $payload = [],
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json(array_merge([
                'ok' => true,
                'message' => $message,
                'warnings' => [],
            ], $payload));
        }

        return back()->with('status', $message);
    }

    private function packingActionError(
        Request $request,
        string $message,
        int $status = 409,
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
                'errors' => [],
            ], $status);
        }

        return back()->with('error', $message);
    }

    /**
     * @param  Collection<int, PackingTask>  $openTasks
     * @return Collection<int, array<string, mixed>>
     */
    private function collectOrders(Collection $openTasks, ProductSegmentService $segments): Collection
    {
        return $openTasks
            ->groupBy('external_order_id')
            ->map(function (Collection $tasks) use ($segments): array {
                /** @var Collection<int, PackingTask> $tasks */
                $tasks = $tasks
                    ->sortBy(fn (PackingTask $task): string => implode('|', [
                        $this->taskLocation($task) ?: 'ZZZ',
                        $task->product_name,
                        $task->size_label,
                    ]))
                    ->values();
                /** @var PackingTask $first */
                $first = $tasks->first();
                $order = $first->order;
                $oldest = $tasks->sortBy('order_date')->first()?->order_date;

                return [
                    'order_id' => $first->external_order_id,
                    'order_number' => $order?->external_number ?: $first->order_number ?: '-',
                    'customer_name' => $first->customer_name ?: '-',
                    'courier' => $first->courier ?: 'Nieznany kurier',
                    'order_date' => $oldest,
                    'tasks' => $tasks,
                    'task_ids' => $tasks->pluck('id')->values()->all(),
                    'positions_count' => $tasks->count(),
                    'quantity' => $tasks->sum(fn (PackingTask $task): float => $task->remainingQuantity()),
                    'segments' => $tasks
                        ->map(fn (PackingTask $task): string => $segments->segmentForTask($task))
                        ->unique()
                        ->values(),
                    'sort_key' => implode('|', [
                        optional($oldest)->timestamp ?? 0,
                        $first->courier ?: '',
                        $first->order_number ?: '',
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
     * @param  Collection<int, PackingTask>  $tasks
     * @return Collection<int, ExternalOrder>
     */
    private function readyOrders(Collection $tasks, ProductSegmentService $segments, ShippingProviderResolver $providers): Collection
    {
        return $tasks
            ->groupBy('external_order_id')
            ->filter(fn (Collection $group): bool => $group->where('status', 'open')->isEmpty()
                && $group->where('status', 'picked')->isNotEmpty())
            ->map(function (Collection $group) use ($segments, $providers): ?ExternalOrder {
                /** @var PackingTask|null $first */
                $first = $group->first();
                $order = $first?->order;

                if (! $order instanceof ExternalOrder) {
                    return null;
                }

                $order->setRelation('packingTasks', $group->sortBy('product_name')->values());
                $order->packing_segments = $group
                    ->map(fn (PackingTask $task): string => $segments->segmentForTask($task))
                    ->unique()
                    ->values()
                    ->all();
                $order->detected_shipping_provider = $providers->providerForOrder($order);
                $label = $order->shipmentLabels?->firstWhere('status', 'generated');
                $order->shipment_label_download_allowed = $label instanceof ShippingLabel
                    && $this->shipmentLabelCanBeDownloaded($label, $order);

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
     * @param  Collection<int, PackingTask>  $packedTasks
     * @return Collection<int, array<string, mixed>>
     */
    private function waitingCourierGroups(Collection $packedTasks, ShippingProviderResolver $providers): Collection
    {
        return $packedTasks
            ->groupBy(function (PackingTask $task) use ($providers): string {
                $label = $task->order?->shipmentLabels?->firstWhere('status', 'generated');

                return $label instanceof ShippingLabel
                    ? $providers->courierName($label, $task->courier)
                    : ($task->courier ?: 'Nieznany kurier');
            })
            ->map(function (Collection $group, string $courier) use ($providers): array {
                $orders = $group
                    ->groupBy('external_order_id')
                    ->map(function (Collection $orderTasks) use ($providers): ?array {
                        /** @var PackingTask|null $first */
                        $first = $orderTasks->sortBy('packed_at')->first();
                        $order = $first?->order;

                        if (! $order instanceof ExternalOrder || ! $first instanceof PackingTask) {
                            return null;
                        }

                        $label = $order->shipmentLabels?->firstWhere('status', 'generated');
                        $labelDownloadAllowed = $label instanceof ShippingLabel
                            && $this->shipmentLabelCanBeDownloaded($label, $order);

                        return [
                            'id' => $order->id,
                            'external_number' => $order->external_number,
                            'customer_name' => $first->customer_name ?: '-',
                            'tasks_count' => $orderTasks->count(),
                            'packed_at' => $first->packed_at,
                            'label_id' => $labelDownloadAllowed ? $label->id : null,
                            'label_number' => $label?->trackingIdentifier(),
                            'tracking_url' => $label instanceof ShippingLabel ? $providers->trackingUrl($label) : null,
                            'tracking_status' => $label?->tracking_status,
                            'tracking_checked_at' => $label?->tracking_checked_at,
                            'tracking_error' => $label?->tracking_last_error,
                            'label_error' => collect((array) data_get($first->metadata, 'packing_completion.warnings', []))
                                ->first(fn ($warning): bool => str_starts_with((string) $warning, 'Etykieta:')),
                            'items' => $orderTasks
                                ->sortBy('product_name')
                                ->map(fn (PackingTask $task): array => $this->packingItem($task))
                                ->values()
                                ->all(),
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
                    'pickup_token' => $this->courierPickupToken($courier, $orders->pluck('id')->all()),
                    'oldest_packed_at' => $group->sortBy('packed_at')->first()?->packed_at,
                ];
            })
            ->sortBy('courier')
            ->values();
    }

    /**
     * @param  array<int|string>  $orderIds
     */
    private function courierPickupToken(string $courier, array $orderIds): string
    {
        $ids = collect($orderIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->implode(',');

        return hash_hmac('sha256', trim($courier).'|'.$ids, (string) config('app.key'));
    }

    private function historyDate(string $date): Carbon
    {
        try {
            return Carbon::parse($date)->startOfDay();
        } catch (Throwable) {
            return now()->startOfDay();
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function packingHistoryOrders(Carbon $date, ShippingProviderResolver $providers): Collection
    {
        $tasks = PackingTask::query()
            ->with(['salesChannel', 'order.shipmentLabels.courierAccount', 'product', 'orderLine'])
            ->whereIn('status', ['packed', 'shipped'])
            ->whereDate('packed_at', $date->toDateString())
            ->orderByDesc('packed_at')
            ->get();

        return $this->completedOrders($tasks, $providers);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function shippedOrders(ShippingProviderResolver $providers): Collection
    {
        $tasks = PackingTask::query()
            ->with(['salesChannel', 'order.shipmentLabels.courierAccount', 'product', 'orderLine'])
            ->where('status', 'shipped')
            ->orderByDesc('packed_at')
            ->get();

        return $this->completedOrders($tasks, $providers)
            ->sortByDesc(fn (array $order): int => $order['pickup_at']?->timestamp ?? $order['last_packed_at']?->timestamp ?? 0)
            ->values();
    }

    /**
     * @param  Collection<int, PackingTask>  $tasks
     * @return Collection<int, array<string, mixed>>
     */
    private function completedOrders(Collection $tasks, ShippingProviderResolver $providers): Collection
    {
        return $tasks
            ->groupBy('external_order_id')
            ->map(function (Collection $group) use ($providers): ?array {
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

                $label = $order instanceof ExternalOrder
                    ? $order->shipmentLabels?->first()
                    : null;
                $labelDownloadAllowed = $label instanceof ShippingLabel
                    && $order instanceof ExternalOrder
                    && $this->shipmentLabelCanBeDownloaded($label, $order);

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
                    'label_id' => $labelDownloadAllowed ? $label->id : null,
                    'label_number' => $label?->trackingIdentifier(),
                    'tracking_url' => $label instanceof ShippingLabel ? $providers->trackingUrl($label) : null,
                    'tracking_status' => $label?->tracking_status,
                    'items' => $group
                        ->sortBy('product_name')
                        ->map(fn (PackingTask $task): array => $this->packingItem($task))
                        ->values()
                        ->all(),
                ];
            })
            ->filter()
            ->sortByDesc('last_packed_at')
            ->values();
    }

    private function shipmentLabelCanBeDownloaded(
        ShippingLabel $label,
        ?ExternalOrder $order = null,
    ): bool {
        if ($label->purpose !== 'shipment'
            || $label->external_order_id === null
            || trim((string) $label->path) === ''
            || in_array(data_get($label->response_payload, 'source'), ['manual_tracking_number', 'manual_inpost_tracking_number'], true)
            || ! in_array(mb_strtolower((string) $label->status), self::DOWNLOADABLE_SHIPMENT_LABEL_STATUSES, true)) {
            return false;
        }

        $order ??= $label->order()->first();

        if (! $order instanceof ExternalOrder
            || in_array(mb_strtolower((string) $order->status), [
                'cancellation-pending',
                'cancelled',
                'canceled',
                'refunded',
            ], true)) {
            return false;
        }

        // cancellationOperation() sprawdza zamówienie główne rodziny splitów
        // i celowo ignoruje odrzucone próby anulowania.
        return ! $order->hasCancellationOperation();
    }

    /** @return array{name:string,sku:?string,size_label:?string,quantity:mixed,image_url:?string,thumbnail_url:?string} */
    private function packingItem(PackingTask $task): array
    {
        return [
            'name' => $task->product_name,
            'sku' => $task->sku,
            'size_label' => $task->size_label,
            'quantity' => $task->quantity_required,
            'image_url' => $task->imageUrl(),
            'thumbnail_url' => $task->thumbnailUrl(),
        ];
    }
}
