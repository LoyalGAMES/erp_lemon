<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CourierAccount;
use App\Models\CustomerPayment;
use App\Models\EmailTemplate;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\InternalNote;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Models\ShippingLabel;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Invoices\ReturnCorrectionInvoiceService;
use App\Services\Orders\OrderCancellationGuard;
use App\Services\Orders\OrderMutationLock;
use App\Services\Payments\MbankTransferBasketService;
use App\Services\Payments\PayuRefundService;
use App\Services\Returns\ReturnNumberService;
use App\Services\Returns\ReturnOrderContextService;
use App\Services\Returns\ReturnProcessStatusService;
use App\Services\Returns\ReturnReceivingService;
use App\Services\Returns\ReturnSettingsService;
use App\Services\Returns\ReturnShippingRefundService;
use App\Services\Returns\ReturnStatusPushService;
use App\Services\Returns\StoreReturnIntakeService;
use App\Services\Shipping\ShippingLabelService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ReturnController extends Controller
{
    public function index(
        Request $request,
        ReturnSettingsService $settings,
        MbankTransferBasketService $mbankBasket,
    ): View {
        $returnSettings = $settings->data();
        $tab = $request->query('tab') === 'pending' ? 'pending' : 'all';
        $search = trim((string) $request->query('q', ''));
        $phoneNeedles = $this->phoneSearchNeedles($search);

        $returnsQuery = ReturnCase::query()
            ->with([
                'lines.product',
                'lines.externalOrderLine',
                'lines.targetWarehouse',
                'lines.warehouseDocument',
                'targetWarehouse',
                'externalOrder',
                'correctionInvoice',
            ])
            ->when($tab === 'pending', fn ($query) => $query->where('status', StoreReturnIntakeService::STATUS_PENDING))
            ->when($search !== '', function ($query) use ($search, $phoneNeedles): void {
                $needle = mb_strtolower($search);
                $query->where(function ($query) use ($needle, $phoneNeedles): void {
                    $query
                        ->whereRaw("LOWER(COALESCE(number, '')) LIKE ?", ["%{$needle}%"])
                        ->orWhereRaw("LOWER(COALESCE(customer_email, '')) LIKE ?", ["%{$needle}%"])
                        ->orWhereRaw("LOWER(COALESCE(reason, '')) LIKE ?", ["%{$needle}%"])
                        ->orWhereRaw("LOWER(COALESCE(CAST(metadata AS CHAR), '')) LIKE ?", ["%{$needle}%"])
                        ->orWhereHas('externalOrder', function ($orderQuery) use ($needle, $phoneNeedles): void {
                            $orderQuery->where(function ($orderMatch) use ($needle, $phoneNeedles): void {
                                $orderMatch
                                    ->whereRaw("LOWER(COALESCE(external_number, '')) LIKE ?", ["%{$needle}%"])
                                    ->orWhereRaw("LOWER(COALESCE(external_id, '')) LIKE ?", ["%{$needle}%"]);

                                foreach ($phoneNeedles as $phoneNeedle) {
                                    $orderMatch
                                        ->orWhereRaw($this->normalizedPhoneTextSql('billing_data').' LIKE ?', ["%{$phoneNeedle}%"])
                                        ->orWhereRaw($this->normalizedPhoneTextSql('shipping_data').' LIKE ?', ["%{$phoneNeedle}%"]);
                                }
                            });
                        })
                        ->orWhereHas('lines', function ($lineQuery) use ($needle): void {
                            $lineQuery->whereRaw("LOWER(COALESCE(CAST(metadata AS CHAR), '')) LIKE ?", ["%{$needle}%"]);
                        });

                    foreach ($phoneNeedles as $phoneNeedle) {
                        $query->orWhereRaw($this->normalizedPhoneTextSql('metadata').' LIKE ?', ["%{$phoneNeedle}%"]);
                    }
                });
            });

        return view('returns.index', [
            'returns' => $returnsQuery->latest()->get(),
            'pendingCount' => ReturnCase::query()
                ->where('status', StoreReturnIntakeService::STATUS_PENDING)
                ->count(),
            'activeTab' => $tab,
            'searchTerm' => $search,
            'orders' => ExternalOrder::query()
                ->with(['lines.product'])
                ->orderByRaw('COALESCE(external_created_at, created_at) DESC')
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
            'products' => Product::query()->where('is_active', true)->orderBy('sku')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'returnSettings' => $returnSettings,
            'storeReturnsApiConfigured' => filled($returnSettings['store_api_token'] ?? null),
            'mbankPayoutCount' => $mbankBasket->eligibleReturns()->count(),
            'module' => 'returns',
        ]);
    }

    public function show(
        ReturnCase $returnCase,
        ReturnSettingsService $settings,
        MbankTransferBasketService $mbankBasket,
        ReturnProcessStatusService $processStatuses,
        ReturnOrderContextService $orderContext,
    ): View {
        $returnCase->load([
            'lines.product',
            'lines.externalOrderLine.product',
            'lines.targetWarehouse',
            'lines.warehouseDocument.lines.product',
            'lines.warehouseDocument.destinationWarehouse',
            'targetWarehouse',
            'warehouseDocument.lines.product',
            'warehouseDocument.destinationWarehouse',
            'externalOrder.lines.product',
            'externalOrder.invoices.files',
            'externalOrder.customerMessages',
            'externalOrder.customerPayments',
            'externalOrder.internalNotes',
            'correctionInvoice.files',
            'shippingLabels.courierAccount',
            'customerMessages',
            'internalNotes',
            'customerPayments',
        ]);

        $mbankPayoutEligible = $mbankBasket->eligibleReturns()->contains('id', $returnCase->id);
        $mbankPayoutAmount = $mbankBasket->amount($returnCase);
        $returnSettings = $settings->data();
        $orderSnapshot = $orderContext->build(
            $returnCase,
            (int) ($returnSettings['return_window_days'] ?? 14),
        );

        return view('returns.show', [
            'title' => 'Karta zwrotu '.$returnCase->number,
            'subtitle' => 'Pełna obsługa zwrotu: produkty, historia, dokumenty, wypłaty, notatki, komunikacja i etykiety.',
            'module' => 'returns',
            'returnCase' => $returnCase,
            'returnSettings' => $returnSettings,
            'orderItems' => $orderSnapshot['items'],
            'returnDeadline' => $orderSnapshot['deadline'],
            'courierAccounts' => CourierAccount::query()
                ->where('provider', 'inpost')
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'emailTemplates' => EmailTemplate::query()
                ->where('is_active', true)
                ->whereIn('context', ['return', 'both'])
                ->orderBy('name')
                ->get(),
            'mbankPayoutEligible' => $mbankPayoutEligible,
            'mbankPayoutAmount' => $mbankPayoutAmount,
            'mbankPayoutRecipient' => $mbankBasket->recipientName($returnCase),
            'mbankPayoutAccount' => $mbankBasket->recipientAccount($returnCase),
            'returnProcess' => $processStatuses->summary($returnCase, $mbankPayoutEligible, $mbankPayoutAmount),
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
        $phoneNeedles = $this->phoneSearchNeedles($term);
        $orders = ExternalOrder::query()
            ->with(['lines.product'])
            ->where(function ($query) use ($needle, $phoneNeedles): void {
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

                foreach ($phoneNeedles as $phoneNeedle) {
                    $query
                        ->orWhereRaw($this->normalizedPhoneTextSql('billing_data').' LIKE ?', ["%{$phoneNeedle}%"])
                        ->orWhereRaw($this->normalizedPhoneTextSql('shipping_data').' LIKE ?', ["%{$phoneNeedle}%"]);
                }
            })
            ->orderByRaw('COALESCE(external_created_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $exactOrder = $orders->first(fn (ExternalOrder $order): bool => $this->matchesOrderExactly($order, $term));

        if (! $exactOrder instanceof ExternalOrder) {
            $exactOrder = ExternalOrder::query()
                ->with(['lines.product'])
                ->where(fn ($query) => $query
                    ->where('external_number', $term)
                    ->orWhere('external_id', $term))
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
        CustomerCommunicationService $communication,
        OrderMutationLock $orderLock,
        OrderCancellationGuard $cancellationGuard,
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
            'refund_recipient_name' => ['nullable', 'string', 'max:143'],
            'refund_bank_account' => ['nullable', 'string', 'max:34'],
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

        try {
            $returnCase = $this->withOrderFamilyLock(
                $order,
                $orderLock,
                function () use ($validated, $returnLines, $numbers, $order, $cancellationGuard): ReturnCase {
                    if ($order instanceof ExternalOrder) {
                        $cancellationGuard->assertReturnAllowed($order);
                    }

                    return DB::transaction(function () use ($validated, $returnLines, $numbers, $order): ReturnCase {
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
                                'refund_recipient_name' => trim((string) ($validated['refund_recipient_name'] ?? '')),
                                'refund_bank_account' => trim((string) ($validated['refund_bank_account'] ?? '')),
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
                },
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $communication->sendReturnStatus($returnCase, 'return_waiting_for_package');

        $warnings = [];
        $createRx = $automationSettings->actionEnabled('return.created', 'return.rx.create');
        $postRx = $automationSettings->actionEnabled('return.created', 'return.rx.post');

        if ($createRx || $postRx) {
            try {
                $documents = $receivingService->createReceivingDocuments($returnCase);

                if ($postRx) {
                    $completedWithoutDocument = $receivingService->receiveWithoutStockMovement($returnCase);

                    foreach ($documents as $document) {
                        $postingService->post($document);
                    }

                    if ($completedWithoutDocument) {
                        $postingService->handleReturnReceiptCompletion($returnCase);
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
                ->route('returns.show', $returnCase)
                ->with('error', 'Zwrotu z rozpoczętym przyjęciem nie można edytować. Zmień dokument magazynowy albo utwórz kolejny zwrot.');
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
                ->route('returns.show', $returnCase)
                ->with('error', 'Zwrotu z rozpoczętym przyjęciem nie można edytować.');
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
            'refund_recipient_name' => ['nullable', 'string', 'max:143'],
            'refund_bank_account' => ['nullable', 'string', 'max:34'],
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
                'metadata' => array_merge($returnCase->metadata ?? [], [
                    'refund_recipient_name' => trim((string) ($validated['refund_recipient_name'] ?? '')),
                    'refund_bank_account' => trim((string) ($validated['refund_bank_account'] ?? '')),
                ]),
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
            ->route('returns.show', $returnCase)
            ->with('status', "Zwrot {$returnCase->number} został zaktualizowany.");
    }

    public function createDocument(
        ReturnCase $returnCase,
        ReturnReceivingService $receivingService,
        WarehouseDocumentPostingService $postingService,
        OrderMutationLock $orderLock,
        OrderCancellationGuard $cancellationGuard,
    ): RedirectResponse {
        try {
            $documents = $this->withReturnCaseFamilyLock(
                $returnCase,
                $orderLock,
                function () use ($returnCase, $receivingService, $postingService, $cancellationGuard): Collection {
                    $cancellationGuard->assertReturnAllowedForCase($returnCase);

                    $documents = $receivingService->createReceivingDocuments($returnCase);
                    $completedWithoutDocument = $receivingService->receiveWithoutStockMovement($returnCase);

                    foreach ($documents as $document) {
                        if ($document->status === 'draft') {
                            $postingService->post($document);
                        }
                    }

                    if ($completedWithoutDocument) {
                        $postingService->handleReturnReceiptCompletion($returnCase);
                    }

                    return $documents->map(fn (WarehouseDocument $document): WarehouseDocument => $document->refresh());
                },
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $freshReturn = $returnCase->fresh() ?? $returnCase;
        $numbers = $documents->pluck('number')->implode(', ');
        $noRestockQuantity = (float) data_get($freshReturn->metadata, 'inventory_receipt.no_restock_quantity', 0);
        $message = match (true) {
            $documents->isEmpty() => "Przyjęto zwrot {$returnCase->number} bez przywracania towaru na stan. Dokument RX nie był wymagany.",
            $noRestockQuantity > 0 => "Utworzono i zaksięgowano RX {$numbers} dla zwrotu {$returnCase->number}. Pozycje z dyspozycją „Nie przywracaj na stan” nie zmieniły stanu magazynowego.",
            $documents->count() === 1 => "Utworzono i zaksięgowano {$numbers} dla zwrotu {$returnCase->number}. Towar został przyjęty na stan.",
            default => "Utworzono i zaksięgowano RX {$numbers} dla zwrotu {$returnCase->number}. Towary zostały przyjęte na właściwe magazyny.",
        };

        return back()->with('status', $message);
    }

    public function createCorrection(
        ReturnCase $returnCase,
        ReturnCorrectionInvoiceService $corrections,
        InvoiceWooCommerceUploadService $uploader,
        CustomerCommunicationService $communication,
        PayuRefundService $payuRefunds,
        OrderMutationLock $orderLock,
        OrderCancellationGuard $cancellationGuard,
    ): RedirectResponse {
        try {
            $invoice = $this->withReturnCaseFamilyLock(
                $returnCase,
                $orderLock,
                function () use ($returnCase, $corrections, $cancellationGuard) {
                    $cancellationGuard->assertReturnAllowedForCase($returnCase);

                    return $corrections->createForReturn($returnCase);
                },
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $freshReturn = $returnCase->fresh() ?? $returnCase;

        try {
            $uploader->upload($invoice);
        } catch (RuntimeException $exception) {
            $communication->sendReturnSettlement($freshReturn, null, $invoice->number);

            return back()->with(
                'error',
                "Wystawiono fakturę korygującą {$invoice->number}, ale nie dodano jej do zamówienia WooCommerce: {$exception->getMessage()} Po poprawieniu integracji kliknij Wyślij do WooCommerce przy tej korekcie.",
            );
        }

        try {
            $payuPayment = $this->withReturnCaseFamilyLock(
                $freshReturn,
                $orderLock,
                function () use ($freshReturn, $invoice, $payuRefunds, $cancellationGuard): ?CustomerPayment {
                    $cancellationGuard->assertReturnAllowedForCase($freshReturn);

                    return $payuRefunds->attemptAutomaticRefund($freshReturn, $invoice);
                },
            );
        } catch (RuntimeException $exception) {
            $this->appendAutomationWarning($freshReturn, 'payu_refund', $exception->getMessage());
            $communication->sendReturnSettlement($freshReturn, null, $invoice->number);

            return back()->with(
                'error',
                "Wystawiono fakturę korygującą {$invoice->number}, ale automatyczny refund PayU nie przeszedł: {$exception->getMessage()}",
            );
        }

        $communication->sendReturnSettlement(
            $freshReturn,
            isset($payuPayment) && $payuPayment instanceof CustomerPayment ? $payuPayment : null,
            $invoice->number,
        );

        if (isset($payuPayment) && $payuPayment instanceof CustomerPayment) {
            return back()->with('status', "Wystawiono fakturę korygującą {$invoice->number} i wysłano refund PayU dla zwrotu {$returnCase->number}.");
        }

        return back()->with('status', "Wystawiono fakturę korygującą {$invoice->number} dla zwrotu {$returnCase->number} i dodano ją do zamówienia WooCommerce.");
    }

    public function approve(
        ReturnCase $returnCase,
        ReturnStatusPushService $pusher,
        CustomerCommunicationService $communication,
        OrderMutationLock $orderLock,
        OrderCancellationGuard $cancellationGuard,
        ReturnShippingRefundService $shippingRefunds,
    ): RedirectResponse {
        try {
            return $this->withReturnCaseFamilyLock(
                $returnCase,
                $orderLock,
                function () use ($returnCase, $pusher, $communication, $cancellationGuard, $shippingRefunds): RedirectResponse {
                    $cancellationGuard->assertReturnAllowedForCase($returnCase);

                    $freshReturn = $returnCase->fresh() ?? $returnCase;

                    if ($freshReturn->status !== StoreReturnIntakeService::STATUS_PENDING) {
                        return back()->with('error', "Zwrot {$freshReturn->number} nie oczekuje na zatwierdzenie.");
                    }

                    $shippingRefunds->snapshot($freshReturn);
                    $freshReturn->update(['status' => StoreReturnIntakeService::STATUS_COMPLETED]);
                    $communication->sendReturnStatus($freshReturn->fresh() ?? $freshReturn, 'return_approved');

                    return $this->pushStatusToStore(
                        $freshReturn,
                        $pusher,
                        "Zwrot {$freshReturn->number} został zatwierdzony.",
                    );
                },
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function reject(
        ReturnCase $returnCase,
        ReturnStatusPushService $pusher,
        CustomerCommunicationService $communication,
    ): RedirectResponse {
        if ($returnCase->status !== StoreReturnIntakeService::STATUS_PENDING) {
            return back()->with('error', "Zwrot {$returnCase->number} nie oczekuje na obsługę.");
        }

        $returnCase->update(['status' => StoreReturnIntakeService::STATUS_REJECTED]);
        $communication->sendReturnStatus($returnCase->fresh() ?? $returnCase, 'return_rejected');

        return $this->pushStatusToStore(
            $returnCase,
            $pusher,
            "Zwrot {$returnCase->number} został odrzucony.",
        );
    }

    private function pushStatusToStore(
        ReturnCase $returnCase,
        ReturnStatusPushService $pusher,
        string $successMessage,
    ): RedirectResponse {
        if (! $pusher->canPush($returnCase)) {
            return back()->with(
                'status',
                $successMessage.' Sklep pobierze nowy status przy najbliższej synchronizacji.',
            );
        }

        try {
            $pusher->push($returnCase);
        } catch (RuntimeException $exception) {
            return back()->with('error', $successMessage." Nie udało się powiadomić sklepu: {$exception->getMessage()} Wtyczka pobierze status automatycznie w ciągu 15 minut.");
        }

        return back()->with('status', $successMessage.' Sklep został powiadomiony i utworzy zwrot w zamówieniu.');
    }

    public function createShippingLabel(
        Request $request,
        ReturnCase $returnCase,
        ShippingLabelService $shippingLabels,
        CustomerCommunicationService $communication,
    ): RedirectResponse {
        $data = $request->validate([
            'courier_account_id' => ['required', 'integer', 'exists:courier_accounts,id'],
            'purpose' => ['nullable', 'string', 'in:return,exchange'],
        ]);
        $purpose = $data['purpose'] ?? 'return';

        $account = CourierAccount::query()
            ->where('is_active', true)
            ->find((int) $data['courier_account_id']);

        if (! $account instanceof CourierAccount) {
            return back()->with('error', 'Wybrane konto kurierskie jest nieaktywne.');
        }

        if ($purpose === 'return' && $returnCase->shippingLabels()->where('status', 'generated')->where('purpose', 'return')->exists()) {
            return back()->with('error', "Zwrot {$returnCase->number} ma już wygenerowaną etykietę zwrotną.");
        }

        try {
            $label = $purpose === 'exchange'
                ? $shippingLabels->generateExchangeLabel($returnCase, $account)
                : $shippingLabels->generateReturnLabel($returnCase, $account);
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Nie udało się wygenerować przesyłki: '.$exception->getMessage());
        }

        $kind = $purpose === 'exchange' ? 'wymiany do klienta' : 'zwrotna';

        if ($purpose === 'return') {
            $communication->sendReturnStatus($returnCase->fresh() ?? $returnCase, 'return_label_ready', [
                'tracking_number' => $label->trackingIdentifier(),
                'attachment_shipping_label_ids' => [$label->id],
            ]);
        } else {
            $communication->sendReturnStatus($returnCase->fresh() ?? $returnCase, 'exchange_label_ready', [
                'tracking_number' => $label->trackingIdentifier(),
            ]);
        }

        return back()->with('status', "Etykieta {$kind} dla {$returnCase->number} została wygenerowana ({$account->name}): {$label->filename()}.");
    }

    public function sendMessage(
        Request $request,
        ReturnCase $returnCase,
        CustomerCommunicationService $communication,
    ): RedirectResponse {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $communication->sendManualForReturn($returnCase, $validated['subject'], $validated['body']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Wiadomość do klienta zwrotu {$returnCase->number} została wysłana.");
    }

    public function storeNote(Request $request, ReturnCase $returnCase): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:3000'],
        ]);

        InternalNote::query()->create([
            'return_case_id' => $returnCase->id,
            'external_order_id' => $returnCase->external_order_id,
            'user_id' => Auth::id(),
            'author_name' => Auth::user()?->name ?: (string) $request->server('PHP_AUTH_USER', 'ERP'),
            'body' => $validated['body'],
            'metadata' => ['source' => 'return_view'],
        ]);

        return back()->with('status', 'Notatka wewnętrzna została dodana do zwrotu.');
    }

    public function storePayment(
        Request $request,
        ReturnCase $returnCase,
        CustomerCommunicationService $communication,
    ): RedirectResponse {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'method' => ['required', 'string', 'in:blik,bank_transfer,cash,card,payu,other'],
            'reference' => ['nullable', 'string', 'max:160'],
            'payment_url' => ['nullable', 'url', 'max:1000'],
            'description' => ['nullable', 'string', 'max:1000'],
            'booked_at' => ['nullable', 'date'],
            'send_payment_request' => ['nullable', 'boolean'],
        ]);

        $isPaymentRequest = $request->boolean('send_payment_request');
        $payment = CustomerPayment::query()->create([
            'external_order_id' => $returnCase->external_order_id,
            'return_case_id' => $returnCase->id,
            'direction' => 'incoming',
            'method' => $validated['method'],
            'status' => $isPaymentRequest ? 'pending' : 'booked',
            'amount' => round((float) $validated['amount'], 2),
            'currency' => mb_strtoupper($validated['currency'] ?? $returnCase->externalOrder?->currency ?? 'PLN'),
            'reference' => $validated['reference'] ?? null,
            'description' => $validated['description'] ?? null,
            'booked_at' => $isPaymentRequest ? null : ($validated['booked_at'] ?? now()),
            'metadata' => [
                'source' => 'return_view',
                'booked_by' => Auth::user()?->name ?: (string) $request->server('PHP_AUTH_USER', 'ERP'),
                'payment_url' => trim((string) ($validated['payment_url'] ?? '')) ?: null,
                'send_payment_request' => $isPaymentRequest,
            ],
        ]);

        if ($isPaymentRequest) {
            $communication->sendReturnStatus($returnCase, 'exchange_payment_requested', [
                'amount' => number_format((float) $payment->amount, 2, ',', ' '),
                'currency' => $payment->currency,
                'payment_url' => trim((string) ($validated['payment_url'] ?? '')),
                'payment_reference' => $payment->reference,
                'payment_description' => $payment->description,
                'customer_payment_id' => $payment->id,
            ]);

            return back()->with('status', 'Zapisano oczekiwaną dopłatę i wysłano klientowi prośbę o płatność.');
        }

        $communication->sendReturnStatus($returnCase, 'exchange_payment_received', [
            'amount' => number_format((float) $payment->amount, 2, ',', ' '),
            'currency' => $payment->currency,
            'payment_reference' => $payment->reference,
        ]);

        return back()->with('status', 'Wpłata klienta została zaksięgowana w saldzie zwrotu/wymiany.');
    }

    public function refundWithPayu(
        ReturnCase $returnCase,
        PayuRefundService $payuRefunds,
        CustomerCommunicationService $communication,
        OrderMutationLock $orderLock,
        OrderCancellationGuard $cancellationGuard,
    ): RedirectResponse {
        try {
            $payment = $this->withReturnCaseFamilyLock(
                $returnCase,
                $orderLock,
                function () use ($returnCase, $payuRefunds, $cancellationGuard): CustomerPayment {
                    $cancellationGuard->assertReturnAllowedForCase($returnCase);

                    return $payuRefunds->refundReturn($returnCase);
                },
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', 'Nie udało się wysłać refundu PayU: '.$exception->getMessage());
        }

        $returnCase->loadMissing('correctionInvoice');
        $communication->sendReturnSettlement(
            $returnCase->fresh() ?? $returnCase,
            $payment,
            $returnCase->correctionInvoice?->number,
        );

        return back()->with('status', "Refund PayU dla zwrotu {$returnCase->number} został wysłany. Status: {$payment->status}.");
    }

    public function mbankPayouts(MbankTransferBasketService $mbankBasket): View
    {
        $returns = $mbankBasket->eligibleReturns();

        return view('returns.mbank-payouts', [
            'title' => 'Koszyk przelewów mBank',
            'subtitle' => 'Zatwierdzone zwroty pobraniowe gotowe do wypłaty przelewem.',
            'module' => 'returns',
            'returns' => $returns,
            'totalAmount' => $returns->sum(fn (ReturnCase $returnCase): float => $mbankBasket->amount($returnCase)),
            'mbankBasket' => $mbankBasket,
        ]);
    }

    public function downloadMbankPayouts(MbankTransferBasketService $mbankBasket): Response
    {
        $returns = $mbankBasket->eligibleReturns();

        if ($returns->isEmpty()) {
            return back()->with('error', 'Brak zwrotów pobraniowych gotowych do exportu mBank.');
        }

        $csv = $mbankBasket->csv($returns);

        return response($csv, 200, [
            'Content-Type' => 'text/plain; charset=Windows-1250',
            'Content-Disposition' => 'attachment; filename="mbank-koszyk-zwrotow-'.now()->format('Ymd-His').'.txt"',
        ]);
    }

    public function downloadLabel(ShippingLabel $label): StreamedResponse
    {
        abort_if($label->return_case_id === null, 404);

        if (! Storage::disk($label->disk)->exists($label->path)) {
            abort(404);
        }

        return Storage::disk($label->disk)->download($label->path, $label->filename(), [
            'Content-Type' => $label->mime_type ?? 'application/pdf',
        ]);
    }

    public function destroy(ReturnCase $returnCase): RedirectResponse
    {
        $returnCase->load(['lines.warehouseDocument.lines', 'warehouseDocument.lines', 'correctionInvoice']);

        if ($returnCase->correctionInvoice !== null) {
            return back()->with('error', 'Nie można usunąć zwrotu z wystawioną fakturą korygującą.');
        }

        $documents = $this->returnDocuments($returnCase);
        $firstDocument = $documents->first();

        if ($firstDocument instanceof WarehouseDocument) {
            return back()->with('error', "Nie można usunąć zwrotu z utworzonym dokumentem RX {$firstDocument->number}. Usuń albo anuluj dokument w module Dokumenty.");
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

    private function withOrderFamilyLock(
        ?ExternalOrder $order,
        OrderMutationLock $orderLock,
        callable $operation,
    ): mixed {
        if (! $order instanceof ExternalOrder) {
            return $operation();
        }

        return $orderLock->forOrderFamily($order, $operation);
    }

    private function withReturnCaseFamilyLock(
        ReturnCase $returnCase,
        OrderMutationLock $orderLock,
        callable $operation,
    ): mixed {
        $returnCase->loadMissing('externalOrder');

        return $this->withOrderFamilyLock($returnCase->externalOrder, $orderLock, $operation);
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
        $phoneMatches = $phone !== '' && collect($this->phoneSearchNeedles($term))->contains(
            fn (string $needle): bool => str_ends_with($phone, $needle) || str_ends_with($needle, $phone),
        );

        return mb_strtolower((string) $order->external_number) === $normalizedTerm
            || mb_strtolower((string) $order->external_id) === $normalizedTerm
            || ($email !== '' && $email === $normalizedTerm)
            || $phoneMatches;
    }

    /** @return list<string> */
    private function phoneSearchNeedles(string $term): array
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        if (strlen($digits) < 7) {
            return [];
        }

        return array_values(array_unique(array_filter([
            $digits,
            strlen($digits) > 9 ? substr($digits, -9) : null,
        ])));
    }

    private function normalizedPhoneTextSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(CAST({$column} AS CHAR), ''), ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";
    }

    /**
     * @return array{id:int,number:string,external_id:string,status:string,order_date:?string,email:string,phone:string,customer:string,has_returns:bool,return_count:int,lines:list<array{id:int,product_id:int,sku:?string,name:string,quantity:float,returned_quantity:float,remaining_quantity:float,returnable:bool}>}
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
            'order_date' => ($order->external_created_at ?? $order->created_at)?->format('Y-m-d H:i'),
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
            || $returnCase->lines->contains(fn (ReturnCaseLine $line): bool => $line->warehouse_document_id !== null
                || filled(data_get($line->metadata, 'inventory_receipt.prepared_at')));
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

    private function appendAutomationWarning(ReturnCase $returnCase, string $type, string $message): void
    {
        $warnings = (array) data_get($returnCase->metadata, 'automation_warnings', []);
        $warnings[] = [
            'type' => $type,
            'message' => $message,
            'created_at' => now()->toISOString(),
        ];

        $returnCase->update([
            'metadata' => array_merge($returnCase->metadata ?? [], [
                'automation_warnings' => array_slice($warnings, -10),
            ]),
        ]);
    }

    private function formatQuantityForMessage(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, ',', ' '), '0'), ',');
    }
}
