<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Invoices\ReturnCorrectionInvoiceService;
use App\Services\Returns\ReturnNumberService;
use App\Services\Returns\ReturnReceivingService;
use App\Services\Returns\ReturnSettingsService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ReturnController extends Controller
{
    public function index(ReturnSettingsService $settings): View
    {
        $returnSettings = $settings->data();

        return view('returns.index', [
            'returns' => ReturnCase::query()
                ->with([
                    'lines.product',
                    'lines.targetWarehouse',
                    'lines.warehouseDocument',
                    'targetWarehouse',
                    'warehouseDocument',
                    'externalOrder',
                    'correctionInvoice',
                ])
                ->latest()
                ->get(),
            'orders' => ExternalOrder::query()
                ->with(['lines.product'])
                ->latest()
                ->limit(25)
                ->get(),
            'products' => Product::query()->where('is_active', true)->orderBy('sku')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'returnSettings' => $returnSettings,
            'module' => 'returns',
        ]);
    }

    public function lookupOrder(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([
                'orders' => [],
                'exact' => null,
            ]);
        }

        $needle = mb_strtolower($term);
        $orders = ExternalOrder::query()
            ->with(['lines.product'])
            ->where(function ($query) use ($needle): void {
                $query
                    ->whereRaw("LOWER(COALESCE(external_number, '')) LIKE ?", ["%{$needle}%"])
                    ->orWhereRaw("LOWER(COALESCE(external_id, '')) LIKE ?", ["%{$needle}%"])
                    ->orWhereRaw("LOWER(COALESCE(CAST(billing_data AS CHAR), '')) LIKE ?", ["%{$needle}%"])
                    ->orWhereRaw("LOWER(COALESCE(CAST(shipping_data AS CHAR), '')) LIKE ?", ["%{$needle}%"])
                    ->orWhereRaw("LOWER(COALESCE(CAST(raw_payload AS CHAR), '')) LIKE ?", ["%{$needle}%"])
                    ->orWhereHas('lines', function ($lineQuery) use ($needle): void {
                        $lineQuery
                            ->whereRaw("LOWER(COALESCE(sku, '')) LIKE ?", ["%{$needle}%"])
                            ->orWhereRaw("LOWER(COALESCE(name, '')) LIKE ?", ["%{$needle}%"])
                            ->orWhereRaw("LOWER(COALESCE(CAST(raw_payload AS CHAR), '')) LIKE ?", ["%{$needle}%"]);
                    });
            })
            ->latest()
            ->limit(20)
            ->get();

        $exactOrder = $orders->first(fn (ExternalOrder $order): bool => $this->matchesOrderExactly($order, $term));

        if (! $exactOrder instanceof ExternalOrder) {
            $exactOrder = ExternalOrder::query()
                ->with(['lines.product'])
                ->where('external_number', $term)
                ->orWhere('external_id', $term)
                ->first();

            if ($exactOrder instanceof ExternalOrder && ! $orders->contains('id', $exactOrder->id)) {
                $orders->prepend($exactOrder);
            }
        }

        return response()->json([
            'orders' => $orders
                ->map(fn (ExternalOrder $order): array => $this->serializeReturnOrder($order))
                ->values(),
            'exact' => $exactOrder instanceof ExternalOrder ? $this->serializeReturnOrder($exactOrder) : null,
        ]);
    }

    public function store(
        Request $request,
        ReturnNumberService $numbers,
        ReturnSettingsService $settings,
        DocumentAutomationSettingsService $automationSettings,
        ReturnReceivingService $receivingService,
        WarehouseDocumentPostingService $postingService,
    ): RedirectResponse {
        $returnSettings = $settings->data();
        $conditionCodes = collect($returnSettings['conditions'] ?? [])->pluck('code')->filter()->implode(',');
        $dispositionCodes = collect($returnSettings['dispositions'] ?? [])->pluck('code')->filter()->implode(',');
        $conditionRule = $conditionCodes !== '' ? ["in:{$conditionCodes}"] : [];
        $dispositionRule = $dispositionCodes !== '' ? ["in:{$dispositionCodes}"] : [];

        $validator = Validator::make($request->all(), [
            'external_order_id' => ['nullable', 'integer', 'exists:external_orders,id'],
            'external_order_number' => ['nullable', 'string', 'max:80'],
            'target_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'reason' => ['nullable', 'string', 'max:255'],
            'condition' => array_merge(['nullable', 'string', 'max:40'], $conditionRule),
            'disposition' => array_merge(['nullable', 'string', 'max:40'], $dispositionRule),
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['nullable', 'array'],
            'lines.*.external_order_line_id' => ['nullable', 'integer', 'exists:external_order_lines,id'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0.0001'],
            'lines.*.condition' => array_merge(['nullable', 'string', 'max:40'], $conditionRule),
            'lines.*.disposition' => array_merge(['nullable', 'string', 'max:40'], $dispositionRule),
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($returnSettings): void {
            $data = $validator->getData();
            $lines = $this->returnLinesFromInput($validator->getData());
            $hasOrderLookup = filled($data['external_order_id'] ?? null)
                || filled($data['external_order_number'] ?? null);

            if ($lines === [] && ! $hasOrderLookup) {
                $validator->errors()->add('lines', 'Zwrot musi mieć co najmniej jedną pozycję produktu.');
            }

            if ($lines === [] && $hasOrderLookup) {
                $validator->errors()->add('lines', 'Wybierz co najmniej jeden towar z listy pozycji zamówienia.');
            }

            if (filled($data['external_order_number'] ?? null) && $this->resolveOrderFromInput($data) === null) {
                $validator->errors()->add('external_order_number', 'Nie znaleziono zamówienia o podanym numerze.');
            }

            foreach ((array) ($validator->getData()['lines'] ?? []) as $index => $line) {
                if (! is_array($line)) {
                    continue;
                }

                $hasAnyValue = filled($line['product_id'] ?? null)
                    || filled($line['quantity'] ?? null)
                    || filled($line['notes'] ?? null);

                if (! $hasAnyValue) {
                    continue;
                }

                if (! filled($line['product_id'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.product_id", 'Wybierz produkt dla pozycji zwrotu.');
                }

                if (! filled($line['quantity'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.quantity", 'Podaj ilość dla pozycji zwrotu.');
                }
            }

            $order = $this->resolveOrderFromInput($data);

            if ($order instanceof ExternalOrder) {
                $this->validateOrderLineSelection($validator, $order, $data);
                $this->validateReturnableQuantities($validator, $order, $data, $returnSettings);
            }
        });

        $validated = $validator->validate();
        $order = $this->resolveOrderFromInput($validated);
        $returnLines = $this->returnLinesFromInput($validated, $returnSettings);

        if ($returnLines === []) {
            return back()->withInput()->with('error', 'Zamówienie nie ma pozycji możliwych do przyjęcia jako zwrot.');
        }

        $returnCase = DB::transaction(function () use ($validated, $returnLines, $numbers, $order): ReturnCase {
            $returnCase = ReturnCase::query()->create([
                'number' => $numbers->next(),
                'external_order_id' => $order?->id,
                'target_warehouse_id' => $validated['target_warehouse_id'],
                'status' => 'opened',
                'reason' => $validated['reason'] ?? null,
                'customer_email' => $validated['customer_email']
                    ?? (is_array($order?->billing_data) ? ($order->billing_data['email'] ?? null) : null),
                'notes' => $validated['notes'] ?? null,
                'metadata' => [
                    'source' => 'manual_panel',
                    'external_order_number' => $order?->external_number,
                ],
            ]);

            foreach ($returnLines as $line) {
                $orderLine = $order instanceof ExternalOrder
                    ? $this->resolveOrderLineForReturn($order, $line)
                    : null;

                $returnCase->lines()->create([
                    'product_id' => $line['product_id'],
                    'external_order_line_id' => $orderLine?->id,
                    'quantity_expected' => $line['quantity'],
                    'quantity_accepted' => $line['quantity'],
                    'condition' => $line['condition'],
                    'disposition' => $line['disposition'],
                    'target_warehouse_id' => (int) $validated['target_warehouse_id'],
                    'notes' => $line['notes'] ?? null,
                    'metadata' => [
                        'created_from' => 'return_form',
                        'external_order_line_id' => $orderLine?->external_line_id,
                    ],
                ]);
            }

            return $returnCase;
        });

        $warnings = [];
        $createRx = $automationSettings->actionEnabled('return.created', 'return.rx.create');
        $postRx = $automationSettings->actionEnabled('return.created', 'return.rx.post');

        if ($createRx || $postRx) {
            try {
                $documents = $receivingService->createReceivingDocuments($returnCase);

                if ($postRx) {
                    foreach ($documents as $document) {
                        $postingService->post($document);
                    }
                }
            } catch (Throwable $exception) {
                $warnings[] = 'Automatyzacja RX: '.$exception->getMessage();
            }
        }

        $response = back()->with('status', "Zwrot {$returnCase->number} został utworzony.");

        if ($warnings !== []) {
            $response->with('error', implode(' ', $warnings));
        }

        return $response;
    }

    public function edit(ReturnCase $returnCase, ReturnSettingsService $settings): View|RedirectResponse
    {
        $returnCase->load([
            'lines.product',
            'lines.externalOrderLine.product',
            'lines.targetWarehouse',
            'lines.warehouseDocument',
            'targetWarehouse',
            'externalOrder.lines.product',
        ]);

        if ($this->hasReturnDocuments($returnCase)) {
            return redirect()
                ->route('returns.index')
                ->with('error', 'Zwrotu z utworzonym dokumentem RX nie można edytować. Zmień dokument magazynowy albo utwórz kolejny zwrot.');
        }

        return view('returns.edit', [
            'returnCase' => $returnCase,
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'returnSettings' => $settings->data(),
            'module' => 'returns',
        ]);
    }

    public function update(
        Request $request,
        ReturnCase $returnCase,
        ReturnSettingsService $settings,
    ): RedirectResponse {
        $returnCase->load(['lines.warehouseDocument', 'externalOrder.lines.product']);

        if ($this->hasReturnDocuments($returnCase)) {
            return redirect()
                ->route('returns.index')
                ->with('error', 'Zwrotu z utworzonym dokumentem RX nie można edytować.');
        }

        $returnSettings = $settings->data();
        $conditionCodes = collect($returnSettings['conditions'] ?? [])->pluck('code')->filter()->implode(',');
        $dispositionCodes = collect($returnSettings['dispositions'] ?? [])->pluck('code')->filter()->implode(',');
        $conditionRule = $conditionCodes !== '' ? ["in:{$conditionCodes}"] : [];
        $dispositionRule = $dispositionCodes !== '' ? ["in:{$dispositionCodes}"] : [];

        $validator = Validator::make($request->all(), [
            'target_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.external_order_line_id' => ['nullable', 'integer', 'exists:external_order_lines,id'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.condition' => array_merge(['required', 'string', 'max:40'], $conditionRule),
            'lines.*.disposition' => array_merge(['required', 'string', 'max:40'], $dispositionRule),
            'lines.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validator->after(function ($validator) use ($returnCase, $returnSettings): void {
            $order = $returnCase->externalOrder;

            if ($order instanceof ExternalOrder) {
                $this->validateReturnableQuantities(
                    $validator,
                    $order,
                    $validator->getData(),
                    $returnSettings,
                    $returnCase->id,
                );
            }
        });

        $validated = $validator->validate();
        $order = $returnCase->externalOrder;
        $returnLines = $this->returnLinesFromInput($validated, $returnSettings);

        DB::transaction(function () use ($returnCase, $validated, $returnLines, $order): void {
            $returnCase->update([
                'target_warehouse_id' => $validated['target_warehouse_id'],
                'reason' => $validated['reason'] ?? null,
                'customer_email' => $validated['customer_email'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            $returnCase->lines()->delete();

            foreach ($returnLines as $line) {
                $orderLine = $order instanceof ExternalOrder
                    ? $this->resolveOrderLineForReturn($order, $line)
                    : null;

                $returnCase->lines()->create([
                    'product_id' => $line['product_id'],
                    'external_order_line_id' => $orderLine?->id,
                    'quantity_expected' => $line['quantity'],
                    'quantity_accepted' => $line['quantity'],
                    'condition' => $line['condition'],
                    'disposition' => $line['disposition'],
                    'target_warehouse_id' => (int) $validated['target_warehouse_id'],
                    'notes' => $line['notes'] ?? null,
                    'metadata' => [
                        'updated_from' => 'return_edit_form',
                        'external_order_line_id' => $orderLine?->external_line_id,
                    ],
                ]);
            }
        });

        return redirect()
            ->route('returns.index')
            ->with('status', "Zwrot {$returnCase->number} został zaktualizowany.");
    }

    public function createDocument(ReturnCase $returnCase, ReturnReceivingService $receivingService): RedirectResponse
    {
        try {
            $documents = $receivingService->createReceivingDocuments($returnCase);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $numbers = $documents->pluck('number')->implode(', ');
        $message = $documents->count() === 1
            ? "Utworzono szkic {$numbers} dla zwrotu {$returnCase->number}. Zaksięguj dokument w module Dokumenty."
            : "Utworzono szkice RX {$numbers} dla zwrotu {$returnCase->number} według mapowania dyspozycji. Zaksięguj dokumenty w module Dokumenty.";

        return back()->with('status', $message);
    }

    public function createCorrection(
        ReturnCase $returnCase,
        ReturnCorrectionInvoiceService $corrections,
        InvoiceWooCommerceUploadService $uploader,
    ): RedirectResponse {
        try {
            $invoice = $corrections->createForReturn($returnCase);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        try {
            $uploader->upload($invoice);
        } catch (RuntimeException $exception) {
            return back()->with(
                'error',
                "Wystawiono fakturę korygującą {$invoice->number}, ale nie dodano jej do zamówienia WooCommerce: {$exception->getMessage()} Po poprawieniu integracji kliknij Wyślij do WooCommerce przy tej korekcie.",
            );
        }

        return back()->with('status', "Wystawiono fakturę korygującą {$invoice->number} dla zwrotu {$returnCase->number} i dodano ją do zamówienia WooCommerce.");
    }

    public function destroy(ReturnCase $returnCase): RedirectResponse
    {
        $returnCase->load(['lines.warehouseDocument.lines', 'warehouseDocument.lines', 'correctionInvoice']);

        if ($returnCase->correctionInvoice !== null) {
            return back()->with('error', 'Nie można usunąć zwrotu z wystawioną fakturą korygującą.');
        }

        $documents = $this->returnDocuments($returnCase);
        $blockingDocument = $documents->first(fn (WarehouseDocument $document): bool => $document->status !== 'draft');

        if ($blockingDocument instanceof WarehouseDocument) {
            return back()->with('error', "Nie można usunąć zwrotu z dokumentem RX {$blockingDocument->number} w statusie {$blockingDocument->status}. Dokument został już objęty historią magazynową.");
        }

        $number = $returnCase->number;

        DB::transaction(function () use ($returnCase, $documents): void {
            foreach ($documents as $document) {
                $document->lines()->delete();
                $document->forceDelete();
            }

            $returnCase->lines()->delete();
            $returnCase->delete();
        });

        return redirect()
            ->route('returns.index')
            ->with('status', "Zwrot {$number} został usunięty.");
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{external_order_line_id:?int,product_id:int,quantity:float,condition:string,disposition:string,notes:?string}>
     */
    private function returnLinesFromInput(array $input, ?array $settings = null): array
    {
        $defaultCondition = $settings['default_condition'] ?? 'unchecked';
        $defaultDisposition = $settings['default_disposition'] ?? 'restock';

        $lines = collect($input['lines'] ?? [])
            ->filter(fn ($line): bool => is_array($line))
            ->filter(fn (array $line): bool => filled($line['product_id'] ?? null) && filled($line['quantity'] ?? null))
            ->map(fn (array $line): array => [
                'external_order_line_id' => filled($line['external_order_line_id'] ?? null)
                    ? (int) $line['external_order_line_id']
                    : null,
                'product_id' => (int) $line['product_id'],
                'quantity' => (float) $line['quantity'],
                'condition' => trim((string) ($line['condition'] ?? $defaultCondition)) ?: $defaultCondition,
                'disposition' => trim((string) ($line['disposition'] ?? $defaultDisposition)) ?: $defaultDisposition,
                'notes' => filled($line['notes'] ?? null) ? trim((string) $line['notes']) : null,
            ])
            ->values()
            ->all();

        if ($lines !== []) {
            return $lines;
        }

        if (! filled($input['product_id'] ?? null) || ! filled($input['quantity'] ?? null)) {
            return [];
        }

        return [[
            'external_order_line_id' => null,
            'product_id' => (int) $input['product_id'],
            'quantity' => (float) $input['quantity'],
            'condition' => trim((string) ($input['condition'] ?? $defaultCondition)) ?: $defaultCondition,
            'disposition' => trim((string) ($input['disposition'] ?? $defaultDisposition)) ?: $defaultDisposition,
            'notes' => filled($input['notes'] ?? null) ? trim((string) $input['notes']) : null,
        ]];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveOrderFromInput(array $input): ?ExternalOrder
    {
        if (filled($input['external_order_id'] ?? null)) {
            return ExternalOrder::query()->find((int) $input['external_order_id']);
        }

        $number = trim((string) ($input['external_order_number'] ?? ''));

        if ($number === '') {
            return null;
        }

        return ExternalOrder::query()
            ->where('external_number', $number)
            ->orWhere('external_id', $number)
            ->first();
    }

    private function matchesOrderExactly(ExternalOrder $order, string $term): bool
    {
        $normalizedTerm = mb_strtolower($term);
        $email = mb_strtolower((string) data_get($order->billing_data, 'email', ''));
        $phone = preg_replace('/\D+/', '', (string) (data_get($order->billing_data, 'phone') ?: data_get($order->shipping_data, 'phone', ''))) ?? '';
        $normalizedPhoneTerm = preg_replace('/\D+/', '', $term) ?? '';

        return mb_strtolower((string) $order->external_number) === $normalizedTerm
            || mb_strtolower((string) $order->external_id) === $normalizedTerm
            || ($email !== '' && $email === $normalizedTerm)
            || ($phone !== '' && $phone === $normalizedPhoneTerm);
    }

    /**
     * @return array{id:int,number:string,external_id:string,status:string,email:string,phone:string,customer:string,has_returns:bool,return_count:int,lines:list<array{id:int,product_id:int,sku:?string,name:string,quantity:float,returned_quantity:float,remaining_quantity:float,returnable:bool}>}
     */
    private function serializeReturnOrder(ExternalOrder $order, ?int $excludeReturnCaseId = null): array
    {
        $order->loadMissing('lines.product');
        $returned = $this->returnedQuantitiesForOrder($order, $excludeReturnCaseId);
        $returnCount = ReturnCase::query()
            ->where('external_order_id', $order->id)
            ->when($excludeReturnCaseId !== null, fn ($query) => $query->whereKeyNot($excludeReturnCaseId))
            ->count();

        return [
            'id' => $order->id,
            'number' => (string) $order->external_number,
            'external_id' => (string) $order->external_id,
            'status' => (string) $order->status,
            'email' => (string) data_get($order->billing_data, 'email', ''),
            'phone' => (string) (data_get($order->billing_data, 'phone') ?: data_get($order->shipping_data, 'phone', '')),
            'customer' => trim(implode(' ', array_filter([
                data_get($order->billing_data, 'first_name'),
                data_get($order->billing_data, 'last_name'),
                data_get($order->billing_data, 'company'),
            ]))),
            'has_returns' => $returnCount > 0,
            'return_count' => $returnCount,
            'lines' => $order->lines
                ->filter(fn (ExternalOrderLine $line): bool => $line->product_id !== null && (float) $line->quantity > 0)
                ->map(function (ExternalOrderLine $line) use ($returned): array {
                    $orderedQuantity = (float) $line->quantity;
                    $returnedQuantity = (float) ($returned['line:'.$line->id] ?? $returned['product:'.$line->product_id] ?? 0);
                    $remainingQuantity = max(0, $orderedQuantity - $returnedQuantity);

                    return [
                        'id' => (int) $line->id,
                        'product_id' => (int) $line->product_id,
                        'sku' => $line->sku,
                        'name' => $line->name,
                        'quantity' => $orderedQuantity,
                        'returned_quantity' => $returnedQuantity,
                        'remaining_quantity' => $remainingQuantity,
                        'returnable' => $remainingQuantity > 0,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{external_order_line_id:?int,product_id:int}  $line
     */
    private function resolveOrderLineForReturn(ExternalOrder $order, array $line): ?ExternalOrderLine
    {
        $order->loadMissing('lines');

        if (filled($line['external_order_line_id'] ?? null)) {
            $orderLine = $order->lines->firstWhere('id', (int) $line['external_order_line_id']);

            if ($orderLine instanceof ExternalOrderLine) {
                return $orderLine;
            }
        }

        return $order->lines
            ->where('product_id', $line['product_id'])
            ->sortByDesc('id')
            ->first();
    }

    /**
     * @return array<string, float>
     */
    private function returnedQuantitiesForOrder(ExternalOrder $order, ?int $excludeReturnCaseId = null): array
    {
        $rows = ReturnCaseLine::query()
            ->selectRaw('external_order_line_id, product_id, SUM(quantity_accepted) as quantity')
            ->whereHas('returnCase', function ($query) use ($order, $excludeReturnCaseId): void {
                $query->where('external_order_id', $order->id);

                if ($excludeReturnCaseId !== null) {
                    $query->whereKeyNot($excludeReturnCaseId);
                }
            })
            ->groupBy('external_order_line_id', 'product_id')
            ->get();

        $quantities = [];

        foreach ($rows as $row) {
            if ($row->external_order_line_id !== null) {
                $quantities['line:'.$row->external_order_line_id] = (float) $row->quantity;

                continue;
            }

            if ($row->product_id !== null) {
                $key = 'product:'.$row->product_id;
                $quantities[$key] = ($quantities[$key] ?? 0) + (float) $row->quantity;
            }
        }

        return $quantities;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateOrderLineSelection(
        \Illuminate\Contracts\Validation\Validator $validator,
        ExternalOrder $order,
        array $input,
    ): void {
        $order->loadMissing('lines');
        $submittedRows = collect((array) ($input['lines'] ?? []))
            ->filter(fn (mixed $line): bool => is_array($line))
            ->filter(fn (array $line): bool => filled($line['external_order_line_id'] ?? null)
                || filled($line['product_id'] ?? null)
                || filled($line['quantity'] ?? null)
                || filled($line['notes'] ?? null));

        if ($submittedRows->isEmpty()) {
            if (filled($input['product_id'] ?? null) || filled($input['quantity'] ?? null)) {
                $validator->errors()->add('lines', 'Wybierz towar z listy pozycji zamówienia.');
            }

            return;
        }

        foreach ($submittedRows as $index => $line) {
            if (! filled($line['external_order_line_id'] ?? null)) {
                $validator->errors()->add("lines.{$index}.external_order_line_id", 'Wybierz towar z listy pozycji zamówienia.');

                continue;
            }

            $orderLine = $order->lines->firstWhere('id', (int) $line['external_order_line_id']);

            if (! $orderLine instanceof ExternalOrderLine) {
                $validator->errors()->add("lines.{$index}.external_order_line_id", 'Wybrana pozycja nie należy do tego zamówienia.');

                continue;
            }

            if (filled($line['product_id'] ?? null) && (int) $line['product_id'] !== (int) $orderLine->product_id) {
                $validator->errors()->add("lines.{$index}.product_id", 'Produkt nie zgadza się z wybraną pozycją zamówienia.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function validateReturnableQuantities(
        \Illuminate\Contracts\Validation\Validator $validator,
        ExternalOrder $order,
        array $input,
        array $settings,
        ?int $excludeReturnCaseId = null,
    ): void {
        $order->loadMissing('lines');
        $returnLines = $this->returnLinesFromInput($input, $settings);
        $returned = $this->returnedQuantitiesForOrder($order, $excludeReturnCaseId);

        foreach ($returnLines as $index => $line) {
            $orderLine = $this->resolveOrderLineForReturn($order, $line);

            if (! $orderLine instanceof ExternalOrderLine) {
                $validator->errors()->add("lines.{$index}.product_id", 'Ten produkt nie występuje w wybranym zamówieniu.');

                continue;
            }

            $returnedQuantity = (float) ($returned['line:'.$orderLine->id] ?? $returned['product:'.$orderLine->product_id] ?? 0);
            $remainingQuantity = max(0, (float) $orderLine->quantity - $returnedQuantity);

            if ($line['quantity'] > $remainingQuantity) {
                $validator->errors()->add(
                    "lines.{$index}.quantity",
                    "Można przyjąć maksymalnie {$this->formatQuantityForMessage($remainingQuantity)} szt. dla pozycji {$orderLine->sku}.",
                );
            }
        }
    }

    private function hasReturnDocuments(ReturnCase $returnCase): bool
    {
        return $returnCase->warehouse_document_id !== null
            || $returnCase->lines->contains(fn (ReturnCaseLine $line): bool => $line->warehouse_document_id !== null);
    }

    /**
     * @return Collection<int, WarehouseDocument>
     */
    private function returnDocuments(ReturnCase $returnCase): Collection
    {
        return collect([$returnCase->warehouseDocument])
            ->merge($returnCase->lines->map(fn (ReturnCaseLine $line) => $line->warehouseDocument))
            ->filter(fn ($document): bool => $document instanceof WarehouseDocument)
            ->unique('id')
            ->values();
    }

    private function formatQuantityForMessage(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, ',', ' '), '0'), ',');
    }
}
