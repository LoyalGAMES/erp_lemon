<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Audit\AuditLogService;
use App\Services\Gs1\Gs1GtinService;
use App\Services\Gs1\Gs1SettingsService;
use App\Services\Inventory\WarehouseDocumentNumberService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Products\ProductIdentifierService;
use App\Services\WooCommerce\ProductDataExportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class ProductController extends Controller
{
    private const PRODUCT_LIST_PER_PAGE = 30;

    public function index(Request $request): View
    {
        $filters = $this->productFilters($request);

        return view('products.index', [
            'productRows' => $this->productListRows($filters),
            'productsCount' => Product::query()->count(),
            'filters' => $filters,
            'channelOptions' => $this->productListChannelOptions(),
            'warehouseOptions' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'categoryOptions' => $this->productListCategoryOptions(),
            'catalogOptions' => collect(['Domyślny']),
            'parameterOptions' => $this->productListParameterOptions(),
            'productLookupUrl' => route('products.lookup'),
            'module' => 'products',
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $query = $this->nullableString($request->query('q'));

        if ($query === null || mb_strlen($query) < 2) {
            return response()->json([]);
        }

        $like = '%'.$query.'%';

        return response()->json(
            Product::query()
                ->select(['sku', 'name', 'ean'])
                ->where(function (Builder $product) use ($like): void {
                    $product
                        ->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('ean', 'like', $like);
                })
                ->orderBy('sku')
                ->limit(20)
                ->get()
                ->map(fn (Product $product): array => [
                    'sku' => $product->sku,
                    'label' => $product->sku.' | '.$product->name,
                ])
                ->values(),
        );
    }

    public function show(Product $product, Gs1SettingsService $gs1Settings): View
    {
        $product->load([
            'stockBalances.warehouse',
            'channelMappings.salesChannel',
            'childRelations.childProduct',
            'variantChildren.stockBalances.warehouse',
            'variantChildren.channelMappings.salesChannel',
        ]);
        $mappedSalesChannelIds = $product->channelMappings
            ->pluck('sales_channel_id')
            ->filter()
            ->all();
        $externalProductIds = $product->channelMappings
            ->pluck('external_product_id')
            ->filter()
            ->unique()
            ->values();
        $externalVariants = $externalProductIds->isEmpty()
            ? collect()
            : Product::query()
                ->with(['stockBalances.warehouse', 'channelMappings.salesChannel'])
                ->whereKeyNot($product->id)
                ->whereHas('channelMappings', function ($query) use ($externalProductIds): void {
                    $query->whereIn('external_product_id', $externalProductIds)
                        ->whereNotNull('external_variation_id');
                })
                ->orderBy('name')
                ->get();
        $relatedVariants = $product->variantChildren
            ->concat($externalVariants)
            ->unique('id')
            ->sortBy(fn (Product $variant): string => mb_strtolower($variant->name.' '.$variant->sku))
            ->values();
        $variantRelationByChildId = $product->childRelations
            ->where('relation_type', 'variant')
            ->keyBy('child_product_id');

        return view('products.show', [
            'product' => $product,
            'relatedVariants' => $relatedVariants,
            'variantRelationByChildId' => $variantRelationByChildId,
            'ledgerEntries' => StockLedgerEntry::query()
                ->with(['warehouse', 'document'])
                ->where('product_id', $product->id)
                ->latest('posted_at')
                ->limit(50)
                ->get(),
            'availableWooCommerceCreateIntegrations' => WordpressIntegration::query()
                ->with('salesChannel')
                ->whereNotIn('sales_channel_id', $mappedSalesChannelIds)
                ->whereHas('salesChannel', fn ($query) => $query->where('is_active', true))
                ->orderBy('name')
                ->get(),
            'categoryOptions' => $this->categoryOptions(),
            'catalogOptions' => $this->catalogOptions(),
            'parameterOptions' => $this->parameterOptions(),
            'productLookupOptions' => $this->productLookupOptions($product),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'gs1Settings' => $gs1Settings->publicConfiguration(),
            'module' => 'products',
            'title' => $product->name,
            'subtitle' => $this->productSubtitle($product),
        ]);
    }

    public function edit(Product $product, Gs1SettingsService $gs1Settings): View
    {
        $product->load([
            'stockBalances.warehouse',
            'channelMappings.salesChannel',
            'childRelations.childProduct',
            'variantChildren.stockBalances.warehouse',
        ]);

        return view('products.edit', [
            'product' => $product,
            'categoryOptions' => $this->categoryOptions(),
            'catalogOptions' => $this->catalogOptions(),
            'parameterOptions' => $this->parameterOptions(),
            'productLookupOptions' => $this->productLookupOptions($product),
            'gs1Settings' => $gs1Settings->publicConfiguration(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'module' => 'products',
            'title' => 'Edycja produktu',
            'subtitle' => $product->name.' | '.$this->productSubtitle($product),
        ]);
    }

    public function store(Request $request, ProductIdentifierService $identifiers): RedirectResponse
    {
        $validated = $request->validate($this->productValidationRules());
        $requestedSku = $this->nullableString($validated['sku'] ?? null);

        $product = Product::query()->create([
            'sku' => $requestedSku ?? $identifiers->temporarySku(),
            'name' => $validated['name'],
            'ean' => $this->nullableString($validated['ean'] ?? null),
            'unit' => $validated['unit'],
            'vat_rate' => $validated['vat_rate'],
            'weight_kg' => $this->nullableFloat($validated['weight_kg'] ?? null),
            'quantity_precision' => 0,
            'is_active' => $request->boolean('is_active', true),
            'attributes' => [
                'master' => [
                    'source' => 'erp',
                    'content' => [
                        'pl' => [
                            'name' => $validated['name'],
                        ],
                    ],
                ],
            ],
        ]);

        $attributes = (array) $product->attributes;
        $attributes['master'] = $this->masterDataFromRequest(
            $validated,
            $request,
            $this->storeUploadedMedia($product, $request),
        );
        $product->forceFill(['attributes' => $attributes])->save();
        $identifiers->ensureSku($product, $requestedSku === null);
        $eanResult = $identifiers->ensureEan($product);
        $this->syncVariantRelations($product, (array) $request->input('variant_skus', []), [], $validated['variant_attribute'] ?? null);

        $redirect = redirect()
            ->route('products.show', $product)
            ->with('status', 'Produkt został dodany jako dane główne ERP.');

        return $eanResult['error'] !== null
            ? $redirect->with('warning', $eanResult['error'])
            : $redirect;
    }

    public function update(Product $product, Request $request, AuditLogService $audit, ProductIdentifierService $identifiers): RedirectResponse
    {
        $validated = $request->validate($this->productValidationRules($product));

        $before = [
            'product' => $product->only(['sku', 'name', 'ean', 'unit', 'vat_rate', 'weight_kg', 'is_active']),
            'attributes' => $product->attributes,
        ];

        $attributes = (array) $product->attributes;
        $currentMaster = data_get($attributes, 'master', []);
        $media = $request->has('existing_media') || $request->hasFile('new_media')
            ? array_merge(
                $this->normalizeExistingMedia((array) $request->input('existing_media', [])),
                $this->storeUploadedMedia($product, $request),
            )
            : (array) data_get($currentMaster, 'media', []);

        $attributes['master'] = $this->masterDataFromRequest($validated, $request, $media, (array) $currentMaster);

        $product->fill([
            'sku' => $this->nullableString($validated['sku'] ?? null) ?? $product->sku,
            'name' => $validated['name'],
            'ean' => $this->nullableString($validated['ean'] ?? null),
            'unit' => $validated['unit'],
            'vat_rate' => $validated['vat_rate'],
            'weight_kg' => $this->nullableFloat($validated['weight_kg'] ?? null),
            'quantity_precision' => 0,
            'is_active' => $request->boolean('is_active'),
            'attributes' => $attributes,
        ]);
        $product->save();
        $identifiers->ensureSku($product, $this->nullableString($validated['sku'] ?? null) === null);
        $eanResult = $identifiers->ensureEan($product);
        $this->syncVariantRelations(
            $product,
            (array) $request->input('variant_skus', []),
            (array) $request->input('variant_remove', []),
            $validated['variant_attribute'] ?? null,
        );

        $audit->record('product.master_data_updated', $product, $before, [
            'product' => $product->only(['sku', 'name', 'ean', 'unit', 'vat_rate', 'weight_kg', 'is_active']),
            'attributes' => $product->attributes,
        ]);
        $this->queueWooCommerceDataExport($product);

        $redirect = redirect()
            ->route('products.show', $product)
            ->with('status', 'Dane produktu zostały zapisane jako dane główne ERP. Zmapowane kanały WooCommerce zostaną zsynchronizowane w tle.');

        return $eanResult['error'] !== null
            ? $redirect->with('warning', $eanResult['error'])
            : $redirect;
    }

    public function duplicate(
        Product $product,
        AuditLogService $audit,
        ProductIdentifierService $identifiers,
    ): RedirectResponse {
        $product->load([
            'childRelations' => fn ($query) => $query
                ->where('relation_type', 'variant')
                ->with('childProduct'),
        ]);
        $copy = $product->replicate(['sku', 'created_at', 'updated_at']);
        $copy->name = $this->copyName($product->name);
        $copy->sku = $identifiers->temporarySku();
        $copy->ean = null;
        $copy->attributes = $this->copyAttributes((array) $product->attributes, $copy->name, $product->id);
        $copy->is_active = false;
        $copy->save();
        $identifiers->ensureSku($copy, true);

        $copiedVariants = [];

        foreach ($product->childRelations as $sourceRelation) {
            $sourceVariant = $sourceRelation->childProduct;

            if (! $sourceVariant instanceof Product) {
                continue;
            }

            $variantCopy = $sourceVariant->replicate(['sku', 'created_at', 'updated_at']);
            $variantCopy->name = $this->copyName($sourceVariant->name);
            $variantCopy->sku = $identifiers->temporarySku();
            $variantCopy->ean = null;
            $variantCopy->attributes = $this->copyAttributes(
                (array) $sourceVariant->attributes,
                $variantCopy->name,
                $sourceVariant->id,
            );
            $variantCopy->is_active = false;
            $variantCopy->save();

            ProductRelation::query()->create([
                'parent_product_id' => $copy->id,
                'child_product_id' => $variantCopy->id,
                'relation_type' => 'variant',
                'sort_order' => $sourceRelation->sort_order,
                'metadata' => array_merge((array) $sourceRelation->metadata, [
                    'copied_from_relation_id' => $sourceRelation->id,
                    'copied_at' => now()->toISOString(),
                ]),
            ]);

            $identifiers->ensureSku($variantCopy, true);
            $copiedVariants[] = $variantCopy;
        }

        $identifierResults = collect([$copy, ...$copiedVariants])
            ->map(fn (Product $copiedProduct): array => $identifiers->ensureEan($copiedProduct));

        $audit->record('product.duplicated', $copy, null, [
            'source_product_id' => $product->id,
            'source_sku' => $product->sku,
            'copy_sku' => $copy->sku,
            'copied_variants' => collect($copiedVariants)->map(fn (Product $variant): array => [
                'product_id' => $variant->id,
                'sku' => $variant->sku,
            ])->values()->all(),
        ]);

        $redirect = redirect()
            ->route('products.edit', $copy)
            ->with('status', count($copiedVariants) > 0
                ? "Utworzono kopię produktu {$product->sku} razem z ".count($copiedVariants).' wariantami. Nadano nowe SKU i usunięto zdjęcia oraz stare identyfikatory WooCommerce.'
                : "Utworzono kopię produktu {$product->sku}. Nadano nowe SKU i usunięto zdjęcia oraz stare identyfikatory WooCommerce.");

        $eanError = $identifierResults->pluck('error')->filter()->first();

        return is_string($eanError) ? $redirect->with('warning', $eanError) : $redirect;
    }

    public function storeRelation(Product $product, Request $request, AuditLogService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'relation_type' => ['required', 'string', 'in:variant'],
            'child_sku' => ['required', 'string', 'exists:products,sku'],
            'variant_attribute' => ['nullable', 'string', 'max:255'],
        ]);
        $child = Product::query()->where('sku', $validated['child_sku'])->firstOrFail();

        if ((int) $child->id === (int) $product->id) {
            return back()->withInput()->with('error', 'Produkt nie może być swoim własnym wariantem.');
        }

        $relation = ProductRelation::query()->updateOrCreate(
            [
                'parent_product_id' => $product->id,
                'child_product_id' => $child->id,
                'relation_type' => 'variant',
            ],
            [
                'sort_order' => (int) (ProductRelation::query()
                    ->where('parent_product_id', $product->id)
                    ->where('relation_type', 'variant')
                    ->max('sort_order') ?? 0) + 10,
                'metadata' => [
                    'created_from' => 'product_card',
                    'variant_attribute' => $this->nullableString($validated['variant_attribute'] ?? null)
                        ?? $this->nullableString(data_get($product->masterData(), 'variant_attribute'))
                        ?? 'Rozmiar',
                ],
            ],
        );

        $this->markAsVariableParent($product, (string) data_get($relation->metadata, 'variant_attribute', 'Rozmiar'));
        $this->markAsVariantChild($child);

        $audit->record('product.variant_attached', $product, null, [
            'child_product_id' => $child->id,
            'child_sku' => $child->sku,
            'relation_id' => $relation->id,
        ]);
        $this->queueWooCommerceDataExport($product);

        return back()->with('status', "Dodano {$child->sku} jako wariant produktu {$product->sku}.");
    }

    public function destroyRelation(Product $product, ProductRelation $relation, AuditLogService $audit): RedirectResponse
    {
        if ((int) $relation->parent_product_id !== (int) $product->id) {
            abort(404);
        }

        $childSku = $relation->childProduct?->sku;
        $relation->delete();

        $audit->record('product.variant_detached', $product, [
            'child_product_id' => $relation->child_product_id,
            'child_sku' => $childSku,
        ]);
        $this->queueWooCommerceDataExport($product);

        return back()->with('status', 'Wariant został odłączony od produktu.');
    }

    public function adjustStock(
        Product $product,
        Request $request,
        WarehouseDocumentNumberService $numbers,
        WarehouseDocumentPostingService $posting,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'new_quantity' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:500'],
            'redirect_url' => ['nullable', 'string', 'max:1000'],
        ]);

        $warehouse = Warehouse::query()
            ->whereKey((int) $validated['warehouse_id'])
            ->where('is_active', true)
            ->firstOrFail();
        $balance = StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
        $current = round((float) ($balance?->quantity_on_hand ?? 0), 4);
        $target = round((float) $validated['new_quantity'], 4);
        $delta = round($target - $current, 4);

        if (abs($delta) < 0.0001) {
            return $this->redirectAfterStockAdjustment($request, $product)
                ->with('status', "Stan SKU {$product->sku} w magazynie {$warehouse->code} już wynosi {$this->formatQuantity($target)}.");
        }

        $document = DB::transaction(function () use ($product, $warehouse, $validated, $numbers, $current, $target, $delta): WarehouseDocument {
            $document = WarehouseDocument::query()->create([
                'number' => $numbers->next('KOR'),
                'type' => 'KOR',
                'status' => 'draft',
                'destination_warehouse_id' => $warehouse->id,
                'document_date' => now(),
                'external_reference' => $product->sku,
                'notes' => $this->nullableString($validated['notes'] ?? null)
                    ?? "Ręczna korekta stanu SKU {$product->sku} z karty produktu.",
                'metadata' => [
                    'source' => 'product_stock_adjustment',
                    'product_id' => $product->id,
                    'product_sku' => $product->sku,
                    'warehouse_id' => $warehouse->id,
                    'warehouse_code' => $warehouse->code,
                    'previous_quantity_on_hand' => $current,
                    'target_quantity_on_hand' => $target,
                    'delta_quantity' => $delta,
                ],
            ]);

            $document->lines()->create([
                'product_id' => $product->id,
                'quantity' => $delta,
                'notes' => "Stan {$current} -> {$target}",
                'metadata' => [
                    'source' => 'product_stock_adjustment',
                    'previous_quantity_on_hand' => $current,
                    'target_quantity_on_hand' => $target,
                ],
            ]);

            return $document;
        });

        try {
            $posting->post($document);
        } catch (RuntimeException $exception) {
            $audit->record('product.stock_adjust_failed', $product, null, null, [
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'document_id' => $document->id,
                'current_quantity' => $current,
                'target_quantity' => $target,
                'delta_quantity' => $delta,
                'error' => $exception->getMessage(),
            ]);

            return $this->redirectAfterStockAdjustment($request, $product)
                ->with('error', 'Nie zaksięgowano korekty stanu: '.$exception->getMessage());
        }

        $audit->record('product.stock_adjusted', $product, [
            'warehouse_id' => $warehouse->id,
            'quantity_on_hand' => $current,
        ], [
            'warehouse_id' => $warehouse->id,
            'quantity_on_hand' => $target,
            'delta_quantity' => $delta,
            'document_id' => $document->id,
            'document_number' => $document->number,
        ]);

        return $this->redirectAfterStockAdjustment($request, $product)
            ->with('status', "Zmieniono stan SKU {$product->sku} w magazynie {$warehouse->code} z {$this->formatQuantity($current)} na {$this->formatQuantity($target)} dokumentem {$document->number}.");
    }

    private function redirectAfterStockAdjustment(Request $request, Product $product): RedirectResponse
    {
        $redirectUrl = $this->safeStockAdjustmentRedirectUrl($request);

        if ($redirectUrl !== null) {
            return redirect()->to($redirectUrl);
        }

        return redirect()->route('products.show', $product);
    }

    private function queueWooCommerceDataExport(Product $product): void
    {
        if (! ProductChannelMapping::query()->where('product_id', $product->id)->exists()) {
            return;
        }

        ExportWooCommerceProductDataJob::dispatch($product->id)->afterCommit();
    }

    private function safeStockAdjustmentRedirectUrl(Request $request): ?string
    {
        $url = trim((string) $request->input('redirect_url', ''));

        if ($url === '') {
            return null;
        }

        if (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')) {
            return $url;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host === $request->getHost() ? $url : null;
    }

    public function exportToWooCommerce(
        Product $product,
        ProductDataExportService $exportService,
        AuditLogService $audit,
    ): RedirectResponse {
        try {
            $result = $exportService->export($product);
        } catch (\Throwable $exception) {
            $audit->record('product.woocommerce_export_failed', $product, null, null, [
                'error' => $exception->getMessage(),
            ]);

            return back()->with('error', $exception->getMessage());
        }

        $product->refresh();
        $audit->record('product.woocommerce_exported', $product, null, [
            'exported' => $result['exported'],
            'results' => $result['results'],
        ]);

        return back()->with('status', "Dane produktu wysłane do WooCommerce: {$result['exported']} kanałów.");
    }

    public function createInWooCommerce(
        Product $product,
        WordpressIntegration $integration,
        ProductDataExportService $exportService,
        AuditLogService $audit,
    ): RedirectResponse {
        try {
            $result = $exportService->create($product, $integration);
        } catch (\Throwable $exception) {
            $audit->record('product.woocommerce_create_failed', $product, null, null, [
                'wordpress_integration_id' => $integration->id,
                'sales_channel_id' => $integration->sales_channel_id,
                'error' => $exception->getMessage(),
            ]);

            return back()->with('error', $exception->getMessage());
        }

        $product->refresh();
        $audit->record('product.woocommerce_created', $product, null, [
            'wordpress_integration_id' => $integration->id,
            'sales_channel_id' => $integration->sales_channel_id,
            'external_product_id' => $result['mapping']->external_product_id,
            'response' => [
                'id' => $result['response']['id'] ?? null,
                'sku' => $result['response']['sku'] ?? null,
                'name' => $result['response']['name'] ?? null,
                'permalink' => $result['response']['permalink'] ?? null,
            ],
        ]);

        $channel = $integration->salesChannel?->code ?? $integration->name;
        $variantCount = count($result['variant_mappings'] ?? []);

        return back()->with(
            'status',
            $variantCount > 0
                ? "Produkt utworzony w WooCommerce dla kanału {$channel} razem z {$variantCount} wariantami."
                : "Produkt utworzony w WooCommerce dla kanału {$channel}.",
        );
    }

    public function generateGs1Ean(
        Request $request,
        Product $product,
        Gs1GtinService $gtinService,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'gpc_code' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'gpc_label' => ['nullable', 'string', 'max:180'],
        ]);

        try {
            $result = $gtinService->generateForProduct(
                $product,
                $validated['gpc_code'] ?? null,
                $validated['gpc_label'] ?? null,
            );
        } catch (\Throwable $exception) {
            $audit->record('product.gs1_ean_failed', $product, null, null, [
                'error' => $exception->getMessage(),
                'gpc_code' => $validated['gpc_code'] ?? null,
            ]);

            return back()->with('error', $exception->getMessage());
        }

        $product->refresh();
        $audit->record('product.gs1_ean_generated', $product, null, [
            'ean' => $result['gtin'],
            'registered_in_gs1' => $result['registered'],
            'gs1_result' => $result['response']['result'] ?? null,
            'gpc_code' => $validated['gpc_code'] ?? null,
        ]);

        return back()->with(
            'status',
            $result['registered']
                ? "EAN {$result['gtin']} został wygenerowany i zapisany w MojeGS1."
                : "EAN {$result['gtin']} został wygenerowany lokalnie z puli GS1.",
        );
    }

    public function destroy(Product $product): RedirectResponse
    {
        $hasStock = StockBalance::query()
            ->where('product_id', $product->id)
            ->where(function ($query): void {
                $query->where('quantity_on_hand', '!=', 0)
                    ->orWhere('quantity_reserved', '!=', 0);
            })
            ->exists();

        if ($hasStock) {
            return back()->with('error', 'Nie można usunąć produktu ze stanem magazynowym.');
        }

        $product->delete();

        return back()->with('status', 'Produkt został usunięty.');
    }

    /**
     * @return array<string, mixed>
     */
    private function productValidationRules(?Product $product = null): array
    {
        $skuRule = Rule::unique('products', 'sku');

        if ($product !== null) {
            $skuRule->ignore($product->id);
        }

        return [
            'sku' => ['nullable', 'string', 'max:255', $skuRule],
            'name' => ['required', 'string', 'max:255'],
            'ean' => ['nullable', 'string', 'max:255'],
            'unit' => ['required', 'string', 'max:16'],
            'vat_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'catalog' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:product_categories,id'],
            'producer' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'asin' => ['nullable', 'string', 'max:255'],
            'publication_status' => ['nullable', 'string', 'in:publish,draft,pending,private'],
            'publication_date' => ['nullable', 'date'],
            'catalog_visibility' => ['nullable', 'string', 'in:visible,catalog,search,hidden'],
            'product_type' => ['nullable', 'string', 'in:simple,variable,variation'],
            'variant_attribute' => ['nullable', 'string', 'max:255'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price_pln' => ['nullable', 'numeric', 'min:0'],
            'retail_price_pln' => ['nullable', 'numeric', 'min:0'],
            'sale_price_pln' => ['nullable', 'numeric', 'min:0'],
            'sale_price_starts_at' => ['nullable', 'date'],
            'sale_price_ends_at' => ['nullable', 'date'],
            'price_eur' => ['nullable', 'numeric', 'min:0'],
            'price_gbp' => ['nullable', 'numeric', 'min:0'],
            'price_usd' => ['nullable', 'numeric', 'min:0'],
            'purchase_price_pln' => ['nullable', 'numeric', 'min:0'],
            'extra_cost_pln' => ['nullable', 'numeric', 'min:0'],
            'warehouse_location' => ['nullable', 'string', 'max:255'],
            'manage_stock' => ['nullable', 'boolean'],
            'backorders' => ['nullable', 'string', 'in:no,notify,yes'],
            'low_stock_amount' => ['nullable', 'numeric', 'min:0'],
            'sold_individually' => ['nullable', 'boolean'],
            'custom_label_pl' => ['nullable', 'string', 'max:120'],
            'custom_label_en' => ['nullable', 'string', 'max:120'],
            'custom_label_bg_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'custom_label_text_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description_pl' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'short_description_pl' => ['nullable', 'string'],
            'short_description_en' => ['nullable', 'string'],
            'additional_description_pl' => ['nullable', 'string'],
            'related_upsell_skus' => ['nullable', 'string', 'max:4000'],
            'related_cross_sell_skus' => ['nullable', 'string', 'max:4000'],
            'parameters' => ['nullable', 'array'],
            'parameters.name' => ['nullable', 'array'],
            'parameters.name.*' => ['nullable', 'string', 'max:255'],
            'parameters.value' => ['nullable', 'array'],
            'parameters.value.*' => ['nullable', 'string', 'max:2000'],
            'parameters.variation' => ['nullable', 'array'],
            'parameters.variation.*' => ['nullable', 'boolean'],
            'variant_skus' => ['nullable', 'array'],
            'variant_skus.*' => ['nullable', 'string', 'exists:products,sku'],
            'variant_remove' => ['nullable', 'array'],
            'variant_remove.*' => ['nullable', 'boolean'],
            'existing_media' => ['nullable', 'array'],
            'existing_media.*.src' => ['nullable', 'string', 'max:2000'],
            'existing_media.*.alt' => ['nullable', 'string', 'max:255'],
            'existing_media.*.remove' => ['nullable', 'boolean'],
            'new_media' => ['nullable', 'array'],
            'new_media.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
            'new_media_alt' => ['nullable', 'string', 'max:255'],
            'suppliers' => ['nullable', 'array'],
            'suppliers.name' => ['nullable', 'array'],
            'suppliers.name.*' => ['nullable', 'string', 'max:255'],
            'suppliers.product_code' => ['nullable', 'array'],
            'suppliers.product_code.*' => ['nullable', 'string', 'max:255'],
            'suppliers.purchase_price_pln' => ['nullable', 'array'],
            'suppliers.purchase_price_pln.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  list<array{src:string,alt:?string,name:?string}>  $media
     * @return array<string, mixed>
     */
    private function masterDataFromRequest(array $validated, Request $request, array $media, array $existingMaster = []): array
    {
        $categoryIds = collect((array) ($validated['category_ids'] ?? []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $selectedCategories = $categoryIds->isEmpty()
            ? collect()
            : ProductCategory::query()->whereIn('id', $categoryIds)->get()->sortBy(fn (ProductCategory $category): int => $categoryIds->search($category->id));
        $categoryNames = $selectedCategories
            ->map(fn (ProductCategory $category): string => $category->path ?: $category->name)
            ->values()
            ->all();
        $legacyCategory = $this->nullableString($validated['category'] ?? null)
            ?? ($categoryNames[0] ?? null);

        return [
            'source' => 'erp',
            'catalog' => $this->nullableString($validated['catalog'] ?? null) ?? 'Domyślny',
            'category' => $legacyCategory,
            'category_ids' => $categoryIds->all(),
            'categories' => $categoryNames !== [] ? $categoryNames : array_values(array_filter((array) data_get($existingMaster, 'categories', []))),
            'producer' => $this->nullableString($validated['producer'] ?? null) ?? 'SEMPRE',
            'tags' => $this->tagList($validated['tags'] ?? ''),
            'asin' => $this->nullableString($validated['asin'] ?? null),
            'publication_status' => $this->nullableString($validated['publication_status'] ?? null) ?? 'publish',
            'publication_date' => $this->nullableDateTimeString($validated['publication_date'] ?? null),
            'catalog_visibility' => $this->nullableString($validated['catalog_visibility'] ?? null) ?? 'visible',
            'product_type' => $this->nullableString($validated['product_type'] ?? null) ?? 'simple',
            'variant_attribute' => $this->nullableString($validated['variant_attribute'] ?? null),
            'developed' => $request->boolean('developed'),
            'dimensions' => [
                'height_cm' => $this->nullableFloat($validated['height_cm'] ?? null),
                'width_cm' => $this->nullableFloat($validated['width_cm'] ?? null),
                'length_cm' => $this->nullableFloat($validated['length_cm'] ?? null),
            ],
            'prices' => $this->priceData($validated),
            'stock' => [
                'location' => $this->nullableString($validated['warehouse_location'] ?? null),
            ],
            'inventory' => [
                'manage_stock' => $request->has('manage_stock')
                    ? $request->boolean('manage_stock')
                    : (bool) data_get($existingMaster, 'inventory.manage_stock', true),
                'backorders' => $this->nullableString($validated['backorders'] ?? null)
                    ?? (string) data_get($existingMaster, 'inventory.backorders', 'no'),
                'low_stock_amount' => array_key_exists('low_stock_amount', $validated)
                    ? $this->nullableFloat($validated['low_stock_amount'])
                    : data_get($existingMaster, 'inventory.low_stock_amount'),
                'sold_individually' => $request->has('sold_individually')
                    ? $request->boolean('sold_individually')
                    : (bool) data_get($existingMaster, 'inventory.sold_individually', false),
            ],
            'custom_label' => [
                'pl' => $this->nullableString($validated['custom_label_pl'] ?? data_get($existingMaster, 'custom_label.pl')),
                'en' => $this->nullableString($validated['custom_label_en'] ?? data_get($existingMaster, 'custom_label.en')),
                'bg_color' => $this->nullableString($validated['custom_label_bg_color'] ?? data_get($existingMaster, 'custom_label.bg_color')),
                'text_color' => $this->nullableString($validated['custom_label_text_color'] ?? data_get($existingMaster, 'custom_label.text_color')),
            ],
            'content' => [
                'pl' => [
                    'name' => $validated['name'],
                    'description' => $this->nullableString($validated['description_pl'] ?? null),
                    'additional_description' => $this->nullableString($validated['short_description_pl'] ?? null)
                        ?? $this->nullableString($validated['additional_description_pl'] ?? null),
                ],
                'en' => [
                    'name' => $this->nullableString($validated['name_en'] ?? null),
                    'description' => $this->nullableString($validated['description_en'] ?? null),
                    'additional_description' => $this->nullableString($validated['short_description_en'] ?? null),
                ],
            ],
            'related_products' => [
                'upsell_skus' => $this->skuList($validated['related_upsell_skus'] ?? ''),
                'cross_sell_skus' => $this->skuList($validated['related_cross_sell_skus'] ?? ''),
            ],
            'parameters' => $this->normalizeParameters((array) $request->input('parameters', [])),
            'media' => $media,
            'suppliers' => $this->normalizeSuppliers((array) $request->input('suppliers', [])),
            'gs1' => (array) data_get($existingMaster, 'gs1', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, float|null>
     */
    private function priceData(array $validated): array
    {
        $retailPrice = $this->nullableFloat($validated['retail_price_pln'] ?? null);

        return [
            'wholesale_price_pln' => $this->nullableFloat($validated['wholesale_price_pln'] ?? null),
            'retail_price_pln' => $retailPrice,
            'sale_price_pln' => $this->nullableFloat($validated['sale_price_pln'] ?? null),
            'sale_price_starts_at' => $this->nullableDateString($validated['sale_price_starts_at'] ?? null),
            'sale_price_ends_at' => $this->nullableDateString($validated['sale_price_ends_at'] ?? null),
            'price_eur' => $this->convertedPrice($retailPrice, 'EUR'),
            'price_gbp' => $this->convertedPrice($retailPrice, 'GBP'),
            'price_usd' => $this->convertedPrice($retailPrice, 'USD'),
            'purchase_price_pln' => $this->nullableFloat($validated['purchase_price_pln'] ?? null),
            'extra_cost_pln' => $this->nullableFloat($validated['extra_cost_pln'] ?? null),
        ];
    }

    private function convertedPrice(?float $plnGrossPrice, string $currency): ?float
    {
        if ($plnGrossPrice === null) {
            return null;
        }

        $rates = [
            'EUR' => 4.55,
            'GBP' => 5.25,
            'USD' => 4.15,
        ];

        $rate = $rates[$currency] ?? null;

        return $rate !== null ? round($plnGrossPrice / $rate, 2) : null;
    }

    private function markAsVariableParent(Product $product, string $variantAttribute): void
    {
        $attributes = (array) $product->attributes;
        $master = (array) data_get($attributes, 'master', []);
        $master['source'] = 'erp';
        $master['product_type'] = 'variable';
        $master['variant_attribute'] = $variantAttribute;
        data_set($attributes, 'master', $master);
        $product->forceFill(['attributes' => $attributes])->save();
    }

    private function markAsVariantChild(Product $product): void
    {
        $attributes = (array) $product->attributes;
        $master = (array) data_get($attributes, 'master', []);
        $master['source'] = 'erp';
        $master['product_type'] = 'variation';
        data_set($attributes, 'master', $master);
        $product->forceFill(['attributes' => $attributes])->save();
    }

    /**
     * @param  array<int|string, mixed>  $submittedSkus
     * @param  array<int|string, mixed>  $removeFlags
     */
    private function syncVariantRelations(Product $product, array $submittedSkus, array $removeFlags, mixed $variantAttribute): void
    {
        if ($submittedSkus === [] && $removeFlags === []) {
            return;
        }

        $variantAttribute = $this->nullableString($variantAttribute)
            ?? $this->nullableString(data_get($product->masterData(), 'variant_attribute'))
            ?? 'Rozmiar';
        $currentRelations = ProductRelation::query()
            ->with('childProduct')
            ->where('parent_product_id', $product->id)
            ->where('relation_type', 'variant')
            ->get();
        $currentRelationBySku = $currentRelations
            ->filter(fn (ProductRelation $relation): bool => $relation->childProduct !== null)
            ->keyBy(fn (ProductRelation $relation): string => mb_strtolower((string) $relation->childProduct->sku));
        $skusToAttach = [];

        foreach ($submittedSkus as $index => $sku) {
            $sku = $this->nullableString($sku);

            if ($sku === null || mb_strtolower($sku) === mb_strtolower($product->sku)) {
                continue;
            }

            if (filter_var($removeFlags[$index] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $currentRelationBySku->get(mb_strtolower($sku))?->delete();

                continue;
            }

            $skusToAttach[] = $sku;
        }

        $children = Product::query()
            ->whereIn('sku', collect($skusToAttach)->unique()->values()->all())
            ->get()
            ->keyBy('sku');
        $nextSortOrder = (int) (ProductRelation::query()
            ->where('parent_product_id', $product->id)
            ->where('relation_type', 'variant')
            ->max('sort_order') ?? 0);

        foreach (collect($skusToAttach)->unique() as $sku) {
            $child = $children->get($sku);

            if (! $child instanceof Product || (int) $child->id === (int) $product->id) {
                continue;
            }

            $existing = $currentRelationBySku->get(mb_strtolower($sku));
            $nextSortOrder += $existing instanceof ProductRelation ? 0 : 10;

            ProductRelation::query()->updateOrCreate(
                [
                    'parent_product_id' => $product->id,
                    'child_product_id' => $child->id,
                    'relation_type' => 'variant',
                ],
                [
                    'sort_order' => $existing?->sort_order ?? $nextSortOrder,
                    'metadata' => [
                        'created_from' => 'product_editor',
                        'variant_attribute' => $variantAttribute,
                    ],
                ],
            );

            $this->markAsVariantChild($child);
        }

        if ($skusToAttach !== [] || ProductRelation::query()->where('parent_product_id', $product->id)->where('relation_type', 'variant')->exists()) {
            $this->markAsVariableParent($product, $variantAttribute);
        }
    }

    /**
     * Loads only the current page of product families. The old implementation
     * hydrated every SKU, every balance and every channel mapping before slicing
     * the collection in PHP.
     *
     * @param  array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string}  $filters
     * @return LengthAwarePaginator<int, array{product:Product,variants:Collection<int, Product>}>
     */
    private function productListRows(array $filters): LengthAwarePaginator
    {
        $products = Product::query()
            ->select($this->productListColumns())
            ->whereDoesntHave('parentRelations', fn (Builder $relations) => $relations->where('relation_type', 'variant'))
            ->with([
                'stockBalances' => fn ($balances) => $balances->select([
                    'id',
                    'product_id',
                    'warehouse_id',
                    'quantity_on_hand',
                    'quantity_reserved',
                    'quantity_available',
                ]),
                'channelMappings' => fn ($mappings) => $mappings->select([
                    'id',
                    'product_id',
                    'sales_channel_id',
                    'external_product_id',
                    'external_variation_id',
                ]),
                'channelMappings.salesChannel:id,code,name',
                'variantChildren' => fn ($children) => $children->select($this->productListColumns(qualified: true)),
                'variantChildren.stockBalances' => fn ($balances) => $balances->select([
                    'id',
                    'product_id',
                    'warehouse_id',
                    'quantity_on_hand',
                    'quantity_reserved',
                    'quantity_available',
                ]),
                'variantChildren.channelMappings' => fn ($mappings) => $mappings->select([
                    'id',
                    'product_id',
                    'sales_channel_id',
                    'external_product_id',
                    'external_variation_id',
                ]),
                'variantChildren.channelMappings.salesChannel:id,code,name',
            ]);

        $this->applyProductListFilters($products, $filters);
        $this->applyProductListOrder($products);

        $paginator = $products
            ->paginate(self::PRODUCT_LIST_PER_PAGE)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(fn (Product $product): array => [
                    'product' => $product,
                    'variants' => $product->variantChildren->values(),
                ]),
        );

        return $paginator;
    }

    /**
     * @return list<string>
     */
    private function productListColumns(bool $qualified = false): array
    {
        $prefix = $qualified ? 'products.' : '';

        return collect([
            'id',
            'sku',
            'name',
            'ean',
            'unit',
            'quantity_precision',
            'vat_rate',
            'attributes',
            'is_active',
            'created_at',
        ])->map(fn (string $column): string => $prefix.$column)->all();
    }

    /**
     * @param  array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string}  $filters
     */
    private function applyProductListFilters(Builder $products, array $filters): void
    {
        if ($this->hasProductListModelFilters($filters)) {
            $products->where(function (Builder $family) use ($filters): void {
                $family
                    ->where(fn (Builder $product) => $this->applyProductListModelFilters($product, $filters))
                    ->orWhereHas('variantChildren', fn (Builder $variant) => $this->applyProductListModelFilters($variant, $filters));
            });
        }

        $this->applyProductListStockFilter($products, $filters['stock']);

        if ($filters['type'] === 'with_variants') {
            $products->whereHas('variantChildren');
        }

        if ($filters['type'] === 'without_variants') {
            $products->whereDoesntHave('variantChildren');
        }
    }

    /**
     * @param  array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string}  $filters
     */
    private function hasProductListModelFilters(array $filters): bool
    {
        return $filters['q'] !== ''
            || $filters['channel'] !== ''
            || $filters['warehouse'] !== ''
            || $filters['category'] !== ''
            || $filters['status'] !== '';
    }

    /**
     * @param  array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string}  $filters
     */
    private function applyProductListModelFilters(Builder $products, array $filters): void
    {
        if ($filters['q'] !== '') {
            $like = '%'.$filters['q'].'%';

            $products->where(function (Builder $search) use ($like): void {
                $search
                    ->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('ean', 'like', $like)
                    ->orWhere('attributes', 'like', $like);
            });
        }

        if ($filters['channel'] !== '') {
            $products->whereHas('channelMappings.salesChannel', fn (Builder $channel) => $channel->where('code', $filters['channel']));
        }

        if ($filters['warehouse'] !== '') {
            $products->whereHas('stockBalances', function (Builder $balance) use ($filters): void {
                $balance
                    ->where('warehouse_id', (int) $filters['warehouse'])
                    ->where(function (Builder $quantities): void {
                        $quantities
                            ->where('quantity_on_hand', '!=', 0)
                            ->orWhere('quantity_reserved', '!=', 0)
                            ->orWhere('quantity_available', '!=', 0);
                    });
            });
        }

        if ($filters['category'] !== '') {
            $products->where('attributes', 'like', '%'.$filters['category'].'%');
        }

        if ($filters['status'] === 'active') {
            $products->where('is_active', true);
        }

        if ($filters['status'] === 'inactive') {
            $products->where('is_active', false);
        }

        if (in_array($filters['status'], ['publish', 'draft'], true)) {
            $products->where(function (Builder $status) use ($filters): void {
                $status
                    ->where('attributes->woocommerce_status', $filters['status'])
                    ->orWhere('attributes->master->publication_status', $filters['status']);
            });
        }
    }

    private function applyProductListStockFilter(Builder $products, string $stock): void
    {
        $hasBalance = function (Builder $query, string $column, string $operator, int|float $value): void {
            $query->where(function (Builder $family) use ($column, $operator, $value): void {
                $family
                    ->whereHas('stockBalances', fn (Builder $balance) => $balance->where($column, $operator, $value))
                    ->orWhereHas('variantChildren.stockBalances', fn (Builder $balance) => $balance->where($column, $operator, $value));
            });
        };

        if ($stock === 'available') {
            $hasBalance($products, 'quantity_available', '>', 0);
        }

        if ($stock === 'reserved') {
            $hasBalance($products, 'quantity_reserved', '>', 0);
        }

        if ($stock === 'out_of_stock') {
            $products
                ->whereDoesntHave('stockBalances', fn (Builder $balance) => $balance->where('quantity_on_hand', '>', 0))
                ->whereDoesntHave('variantChildren.stockBalances', fn (Builder $balance) => $balance->where('quantity_on_hand', '>', 0));
        }

        if ($stock === 'no_stock') {
            $hasNonZeroBalance = function (Builder $balance): void {
                $balance->where(function (Builder $quantities): void {
                    $quantities
                        ->where('quantity_on_hand', '!=', 0)
                        ->orWhere('quantity_reserved', '!=', 0)
                        ->orWhere('quantity_available', '!=', 0);
                });
            };

            $products
                ->whereDoesntHave('stockBalances', $hasNonZeroBalance)
                ->whereDoesntHave('variantChildren.stockBalances', $hasNonZeroBalance);
        }
    }

    private function applyProductListOrder(Builder $products): void
    {
        $freshnessColumn = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "coalesce(json_unquote(json_extract(`attributes`, '$.master.publication_date')), `created_at`)",
            'sqlite' => "coalesce(json_extract(\"attributes\", '$.master.publication_date'), \"created_at\")",
            'pgsql' => "coalesce(\"attributes\"->'master'->>'publication_date', \"created_at\"::text)",
            default => 'created_at',
        };

        $products
            ->orderByRaw($freshnessColumn.' desc')
            ->orderByDesc('id');
    }

    /**
     * @return Collection<int, string>
     */
    private function productListChannelOptions(): Collection
    {
        return SalesChannel::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->pluck('code');
    }

    /**
     * @return Collection<int, array{id:?int,name:string,path:string,sales_channel:?string,gs1_gpc_code:?string}>
     */
    private function productListCategoryOptions(): Collection
    {
        return ProductCategory::query()
            ->with('salesChannel:id,code')
            ->orderBy('path')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => $category->path ?: $category->name,
                'sales_channel' => $category->salesChannel?->code,
                'gs1_gpc_code' => $category->gs1_gpc_code,
            ]);
    }

    /**
     * @return Collection<int, array{name:string,values:list<string>,is_variant:bool,is_required:bool,input_type:string}>
     */
    private function productListParameterOptions(): Collection
    {
        return ProductParameterDefinition::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductParameterDefinition $definition): array => [
                'name' => $definition->name,
                'values' => (array) $definition->values,
                'is_variant' => (bool) $definition->is_variant,
                'is_required' => (bool) $definition->is_required,
                'input_type' => $definition->input_type,
            ]);
    }

    /**
     * @return array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string}
     */
    private function productFilters(Request $request): array
    {
        $warehouseId = (string) ($request->query('warehouse') ?? '');

        return [
            'q' => $this->nullableString($request->query('q')) ?? '',
            'channel' => $this->nullableString($request->query('channel')) ?? '',
            'warehouse' => ctype_digit($warehouseId) && Warehouse::query()->whereKey((int) $warehouseId)->exists()
                ? $warehouseId
                : '',
            'stock' => in_array($request->query('stock'), ['available', 'reserved', 'out_of_stock', 'no_stock'], true)
                ? (string) $request->query('stock')
                : '',
            'type' => in_array($request->query('type'), ['with_variants', 'without_variants'], true)
                ? (string) $request->query('type')
                : '',
            'category' => $this->nullableString($request->query('category')) ?? '',
            'status' => in_array($request->query('status'), ['active', 'inactive', 'publish', 'draft'], true)
                ? (string) $request->query('status')
                : '',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace(',', '.', trim((string) $value));

        return $value === '' ? null : (float) $value;
    }

    private function nullableDateString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        return $value === null ? null : mb_substr($value, 0, 10);
    }

    private function nullableDateTimeString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return mb_substr(str_replace(' ', 'T', $value), 0, 16);
        }
    }

    private function formatQuantity(float $value): string
    {
        if (abs($value - round($value)) < 0.00001) {
            return number_format($value, 0, ',', ' ');
        }

        return rtrim(rtrim(number_format($value, 4, ',', ' '), '0'), ',');
    }

    /**
     * @return list<string>
     */
    private function skuList(mixed $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', (string) ($value ?? '')) ?: [])
            ->map(fn (string $sku): string => trim($sku))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function tagList(mixed $value): array
    {
        return collect(explode(',', (string) ($value ?? '')))
            ->map(fn (string $tag): string => trim($tag))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{name:string,value:string}>
     */
    private function normalizeParameters(array $input): array
    {
        $names = (array) ($input['name'] ?? []);
        $values = (array) ($input['value'] ?? []);
        $variations = (array) ($input['variation'] ?? []);
        $rows = [];

        foreach ($names as $index => $name) {
            $name = $this->nullableString($name);
            $value = $this->nullableString($values[$index] ?? null);

            if ($name === null && $value === null) {
                continue;
            }

            $rows[] = [
                'name' => $name ?? '',
                'value' => $value ?? '',
                'variation' => filter_var($variations[$index] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, array{name:string,path:string,sales_channel:?string}>
     */
    private function categoryOptions(): Collection
    {
        $storedCategories = ProductCategory::query()
            ->with('salesChannel')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => $category->path ?: $category->name,
                'sales_channel' => $category->salesChannel?->code,
                'gs1_gpc_code' => $category->gs1_gpc_code,
            ]);

        $productCategories = Product::query()
            ->whereNotNull('attributes')
            ->get(['attributes'])
            ->flatMap(function (Product $product): array {
                $attributes = (array) $product->attributes;
                $categories = (array) data_get($attributes, 'woocommerce_categories', []);
                $masterCategory = $this->nullableString(data_get($attributes, 'master.category'));

                if ($masterCategory !== null) {
                    $categories[] = $masterCategory;
                }

                return collect($categories)
                    ->map(fn ($category): ?array => $this->nullableString($category) !== null
                        ? [
                            'id' => null,
                            'name' => $this->nullableString($category),
                            'path' => $this->nullableString($category),
                            'sales_channel' => null,
                            'gs1_gpc_code' => null,
                        ]
                        : null)
                    ->filter()
                    ->values()
                    ->all();
            });

        return $storedCategories
            ->concat($productCategories)
            ->filter(fn (array $category): bool => $category['name'] !== null && $category['name'] !== '')
            ->unique(fn (array $category): string => mb_strtolower((string) $category['path']))
            ->sortBy('path')
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function catalogOptions(): Collection
    {
        return Product::query()
            ->whereNotNull('attributes')
            ->get(['attributes'])
            ->map(fn (Product $product): ?string => $this->nullableString(data_get((array) $product->attributes, 'master.catalog')))
            ->filter()
            ->push('Domyślny')
            ->unique(fn (string $catalog): string => mb_strtolower($catalog))
            ->sort()
            ->values();
    }

    /**
     * @return Collection<int, array{name:string,values:list<string>,is_variant:bool,is_required:bool,input_type:string}>
     */
    private function parameterOptions(): Collection
    {
        $defined = ProductParameterDefinition::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductParameterDefinition $definition): array => [
                'name' => $definition->name,
                'values' => (array) $definition->values,
                'is_variant' => (bool) $definition->is_variant,
                'is_required' => (bool) $definition->is_required,
                'input_type' => $definition->input_type,
            ]);

        $discovered = Product::query()
            ->whereNotNull('attributes')
            ->get(['attributes'])
            ->flatMap(function (Product $product): array {
                return collect((array) data_get((array) $product->attributes, 'master.parameters', []))
                    ->filter(fn ($row): bool => is_array($row))
                    ->map(fn (array $row): array => [
                        'name' => $this->nullableString($row['name'] ?? null),
                        'value' => $this->nullableString($row['value'] ?? null),
                        'variation' => (bool) ($row['variation'] ?? false),
                    ])
                    ->filter(fn (array $row): bool => $row['name'] !== null)
                    ->values()
                    ->all();
            })
            ->groupBy(fn (array $row): string => mb_strtolower((string) $row['name']))
            ->map(fn (Collection $rows): array => [
                'name' => (string) $rows->first()['name'],
                'values' => $rows->pluck('value')->filter()->unique()->sort()->values()->all(),
                'is_variant' => $rows->contains(fn (array $row): bool => (bool) $row['variation']),
                'is_required' => false,
                'input_type' => 'text',
            ])
            ->values();

        return $defined
            ->concat($discovered)
            ->unique(fn (array $row): string => mb_strtolower($row['name']))
            ->sortBy('name')
            ->values();
    }

    /**
     * @return Collection<int, array{sku:string,name:string,ean:?string,category:?string,label:string}>
     */
    private function productLookupOptions(?Product $excludedProduct = null): Collection
    {
        return Product::query()
            ->when($excludedProduct !== null, fn ($query) => $query->whereKeyNot($excludedProduct->id))
            ->orderBy('name')
            ->orderBy('sku')
            ->get(['id', 'sku', 'name', 'ean', 'attributes'])
            ->map(function (Product $product): array {
                $category = $this->nullableString(data_get((array) $product->attributes, 'master.category'));
                $label = $product->sku.' | '.$product->name;

                if ($category !== null) {
                    $label .= ' | '.$category;
                }

                return [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'ean' => $this->nullableString($product->ean),
                    'category' => $category,
                    'label' => $label,
                ];
            })
            ->values();
    }

    private function copyName(string $name): string
    {
        $suffix = ' (kopia)';

        return str_ends_with($name, $suffix) ? $name : $name.$suffix;
    }

    private function productSubtitle(Product $product): string
    {
        $displaySku = $product->displaySku();

        if ($displaySku !== null) {
            return 'SKU: '.$displaySku;
        }

        $externalId = $product->externalDisplayId();

        return $externalId !== null ? 'ID Woo: '.$externalId : 'SKU wewnętrzne: '.$product->sku;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function copyAttributes(array $attributes, string $copyName, int $sourceProductId): array
    {
        foreach (array_keys($attributes) as $key) {
            if (str_starts_with((string) $key, 'woocommerce_')) {
                unset($attributes[$key]);
            }
        }

        data_set($attributes, 'master.content.pl.name', $copyName);
        data_set($attributes, 'master.source', 'erp');
        data_set($attributes, 'master.copy.created_from_product_id', $sourceProductId);
        data_set($attributes, 'master.copy.created_at', now()->toISOString());
        data_set($attributes, 'master.media', []);

        return $attributes;
    }

    /**
     * @param  array<int|string, mixed>  $rows
     * @return list<array{src:string,alt:?string,name:?string}>
     */
    private function normalizeExistingMedia(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row): bool => is_array($row))
            ->filter(fn (array $row): bool => ! filter_var($row['remove'] ?? false, FILTER_VALIDATE_BOOLEAN))
            ->map(function (array $row): ?array {
                $src = $this->nullableString($row['src'] ?? null);

                if ($src === null) {
                    return null;
                }

                return [
                    'src' => $src,
                    'alt' => $this->nullableString($row['alt'] ?? null),
                    'name' => $this->nullableString($row['name'] ?? null),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return list<array{src:string,alt:?string,name:string}>
     */
    private function storeUploadedMedia(Product $product, Request $request): array
    {
        $files = $request->file('new_media', []);

        if (! is_array($files) || $files === []) {
            return [];
        }

        $relativeDirectory = $this->productMediaDirectory($product);
        $absoluteDirectory = public_path($relativeDirectory);
        File::ensureDirectoryExists($absoluteDirectory, 0755, true);
        $alt = $this->nullableString($request->input('new_media_alt'));
        $rows = [];

        foreach ($files as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $originalName = $file->getClientOriginalName();
            $baseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'produkt';
            $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg');
            $filename = now()->format('YmdHis').'-'.Str::random(8).'-'.$baseName.'.'.$extension;

            $file->move($absoluteDirectory, $filename);

            $rows[] = [
                'src' => '/'.trim($relativeDirectory.'/'.$filename, '/'),
                'alt' => $alt,
                'name' => $originalName,
            ];
        }

        return $rows;
    }

    private function productMediaDirectory(Product $product): string
    {
        $base = app()->environment('testing') ? 'uploads/testing-products' : 'uploads/products';

        return $base.'/'.$product->id;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{name:string,product_code:?string,purchase_price_pln:?float}>
     */
    private function normalizeSuppliers(array $input): array
    {
        $names = (array) ($input['name'] ?? []);
        $codes = (array) ($input['product_code'] ?? []);
        $prices = (array) ($input['purchase_price_pln'] ?? []);
        $rows = [];

        foreach ($names as $index => $name) {
            $name = $this->nullableString($name);
            $code = $this->nullableString($codes[$index] ?? null);
            $price = $this->nullableFloat($prices[$index] ?? null);

            if ($name === null && $code === null && $price === null) {
                continue;
            }

            $rows[] = [
                'name' => $name ?? '',
                'product_code' => $code,
                'purchase_price_pln' => $price,
            ];
        }

        return $rows;
    }
}
