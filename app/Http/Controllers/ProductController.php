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
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use App\Services\Audit\AuditLogService;
use App\Services\Gs1\Gs1GtinService;
use App\Services\Gs1\Gs1SettingsService;
use App\Services\Inventory\WarehouseDocumentNumberService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Products\ProductEditFieldSettingsService;
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
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class ProductController extends Controller
{
    private const PRODUCT_LIST_PER_PAGE = 30;

    public function index(Request $request): View
    {
        $isFavorites = $request->routeIs('products.favorites');
        $filters = $this->productFilters($request, $isFavorites);

        return view('products.index', [
            'productRows' => $this->productListRows($filters),
            'productsCount' => Product::query()->where('is_translation', false)->count(),
            'filters' => $filters,
            'isFavorites' => $isFavorites,
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
                ->where('is_translation', false)
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

    public function show(Product $product): RedirectResponse
    {
        return redirect()->route('products.edit', $product);
    }

    public function edit(
        Product $product,
        Gs1SettingsService $gs1Settings,
        ProductEditFieldSettingsService $productEditFields,
    ): View
    {
        $product->load([
            'stockBalances.warehouse',
            'channelMappings.salesChannel',
            'childRelations.childProduct',
            'variantChildren.stockBalances.warehouse',
        ]);
        $mappedSalesChannelIds = $product->channelMappings
            ->pluck('sales_channel_id')
            ->filter()
            ->all();

        return view('products.edit', [
            'product' => $product,
            'categoryOptions' => $this->categoryOptions(),
            'catalogOptions' => $this->catalogOptions(),
            'parameterOptions' => $this->parameterOptions(),
            'productLookupOptions' => $this->productLookupOptions($product),
            'visibleProductEditFields' => $productEditFields->visibleFields(),
            'gs1Settings' => $gs1Settings->publicConfiguration(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('code')->get(),
            'availableWooCommerceCreateIntegrations' => WordpressIntegration::query()
                ->with('salesChannel')
                ->whereNotIn('sales_channel_id', $mappedSalesChannelIds)
                ->whereHas('salesChannel', fn ($query) => $query->where('is_active', true))
                ->orderBy('name')
                ->get(),
            'module' => 'products',
            'title' => 'Edycja produktu',
            'subtitle' => $product->name.' | '.$this->productSubtitle($product),
        ]);
    }

    public function store(Request $request, ProductIdentifierService $identifiers): RedirectResponse
    {
        $validated = $request->validate($this->productValidationRules());
        $this->validateProductTypeSelection($request, $validated);
        $requestedSku = $this->nullableString($validated['sku'] ?? null);
        $uploadedMedia = [];

        try {
            [$product, $eanResult, $generatedVariants] = DB::transaction(function () use ($validated, $request, $requestedSku, $identifiers, &$uploadedMedia): array {
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
                $uploadedMedia = $this->storeUploadedMedia($product, $request);
                $attributes['master'] = $this->masterDataFromRequest(
                    $validated,
                    $request,
                    $uploadedMedia,
                );
                $product->forceFill(['attributes' => $attributes])->save();
                $identifiers->ensureSku($product, $requestedSku === null);
                $eanResult = $identifiers->ensureEan($product);
                $this->syncVariantRelations($product, (array) $request->input('variant_skus', []), [], $validated['variant_attribute'] ?? null);
                $generatedVariants = $this->createGeneratedVariants(
                    $product,
                    $request,
                    $validated['variant_attribute'] ?? null,
                    $identifiers,
                );

                return [$product, $eanResult, $generatedVariants];
            });
        } catch (\Throwable $exception) {
            $this->deleteStoredMediaFiles($uploadedMedia);

            throw $exception;
        }

        $redirect = redirect()
            ->route('products.edit', $product)
            ->with('status', $generatedVariants['created'] > 0
                ? "Produkt został dodany razem z {$generatedVariants['created']} wariantami. Każdy wariant otrzymał własne SKU i EAN."
                : 'Produkt został dodany jako dane główne ERP.');

        $identifierError = $eanResult['error'] ?? $generatedVariants['error'];

        return $identifierError !== null
            ? $redirect->with('warning', $identifierError)
            : $redirect;
    }

    public function update(
        Product $product,
        Request $request,
        AuditLogService $audit,
        ProductIdentifierService $identifiers,
        ProductEditFieldSettingsService $productEditFields,
    ): RedirectResponse {
        $this->preserveHiddenProductFields($request, $product, $productEditFields->visibleFields());
        $validated = $request->validate($this->productValidationRules($product));
        $this->validateProductTypeSelection($request, $validated);

        $before = [
            'product' => $product->only(['sku', 'name', 'ean', 'unit', 'vat_rate', 'weight_kg', 'is_active']),
            'attributes' => $product->attributes,
        ];

        $uploadedMedia = [];

        try {
            [$eanResult, $generatedVariants] = DB::transaction(function () use ($product, $request, $validated, $identifiers, $audit, $before, &$uploadedMedia): array {
                $attributes = (array) $product->attributes;
                $currentMaster = data_get($attributes, 'master', []);
                $uploadedMedia = $this->storeUploadedMedia($product, $request);
                $media = $request->has('existing_media') || $request->hasFile('new_media')
                ? array_merge(
                    $this->normalizeExistingMedia((array) $request->input('existing_media', [])),
                    $uploadedMedia,
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
                $generatedVariants = $this->createGeneratedVariants(
                    $product,
                    $request,
                    $validated['variant_attribute'] ?? null,
                    $identifiers,
                );
                $product->load('variantChildren');

                foreach ($product->variantChildren as $variant) {
                    $variantEanResult = $identifiers->ensureEan($variant);
                    $eanResult['error'] ??= $variantEanResult['error'];
                }

                $audit->record('product.master_data_updated', $product, $before, [
                    'product' => $product->only(['sku', 'name', 'ean', 'unit', 'vat_rate', 'weight_kg', 'is_active']),
                    'attributes' => $product->attributes,
                ]);
                $this->queueWooCommerceDataExport($product);

                return [$eanResult, $generatedVariants];
            });
        } catch (\Throwable $exception) {
            $this->deleteStoredMediaFiles($uploadedMedia);

            throw $exception;
        }

        $redirect = redirect()
            ->route('products.edit', $product)
            ->with('status', $generatedVariants['created'] > 0
                ? "Dane produktu zostały zapisane i utworzono {$generatedVariants['created']} brakujących wariantów. Zmapowane kanały WooCommerce zostaną zsynchronizowane w tle."
                : 'Dane produktu zostały zapisane jako dane główne ERP. Zmapowane kanały WooCommerce zostaną zsynchronizowane w tle.');

        $identifierError = $eanResult['error'] ?? $generatedVariants['error'];

        return $identifierError !== null
            ? $redirect->with('warning', $identifierError)
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
        [$copy, $copiedVariants] = DB::transaction(function () use ($product, $identifiers, $audit): array {
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
                $variantCopy->is_active = $sourceVariant->is_active;
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

            $audit->record('product.duplicated', $copy, null, [
                'source_product_id' => $product->id,
                'source_sku' => $product->sku,
                'copy_sku' => $copy->sku,
                'copied_variants' => collect($copiedVariants)->map(fn (Product $variant): array => [
                    'product_id' => $variant->id,
                    'sku' => $variant->sku,
                ])->values()->all(),
            ]);

            return [$copy, $copiedVariants];
        });

        $redirect = redirect()
            ->route('products.edit', $copy)
            ->with('status', count($copiedVariants) > 0
                ? "Utworzono kopię produktu {$product->sku} razem z ".count($copiedVariants).' wariantami. Nadano nowe SKU i usunięto zdjęcia oraz stare identyfikatory WooCommerce.'
                : "Utworzono kopię produktu {$product->sku}. Nadano nowe SKU i usunięto zdjęcia oraz stare identyfikatory WooCommerce.");

        return $redirect->with('warning', 'EAN produktu i wariantów zostanie nadany przy pierwszym zapisie po wybraniu właściwych kategorii.');
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

    public function toggleFavorite(Product $product): RedirectResponse
    {
        $product->forceFill(['is_favorite' => ! $product->is_favorite])->save();

        return back()->with('status', $product->is_favorite
            ? "Produkt {$product->name} dodano do ulubionych."
            : "Produkt {$product->name} usunięto z ulubionych.");
    }

    public function destroyRelation(Product $product, ProductRelation $relation, AuditLogService $audit): RedirectResponse
    {
        if ((int) $relation->parent_product_id !== (int) $product->id) {
            abort(404);
        }

        $child = $relation->childProduct;
        $childSku = $child?->sku;
        $this->markVariantForWooRemoval($product, $child);
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

        return redirect()->route('products.edit', $product);
    }

    /**
     * Hidden fields are presentation choices, not data deletion requests. Keep
     * their current values when the product form is saved without rendering them.
     *
     * @param  array<string, bool>  $visibleFields
     */
    private function preserveHiddenProductFields(Request $request, Product $product, array $visibleFields): void
    {
        $master = $product->masterData();
        $preserved = [];
        $preserve = function (string $field, string $input, mixed $value) use ($request, $visibleFields, &$preserved): void {
            if (($visibleFields[$field] ?? true) === false && ! $request->has($input)) {
                $preserved[$input] = $value;
            }
        };

        $preserve('name', 'name', $product->name);
        $preserve('catalog', 'catalog', data_get($master, 'catalog'));
        $preserve('categories', 'category_ids', (array) data_get($master, 'category_ids', []));
        $preserve('tags', 'tags', implode(', ', (array) data_get($master, 'tags', [])));
        $preserve('sku', 'sku', $product->sku);
        $preserve('ean', 'ean', $product->ean);
        $preserve('asin', 'asin', data_get($master, 'asin'));
        $preserve('weight', 'weight_kg', $product->weight_kg);
        $preserve('height', 'height_cm', data_get($master, 'dimensions.height_cm'));
        $preserve('width', 'width_cm', data_get($master, 'dimensions.width_cm'));
        $preserve('length', 'length_cm', data_get($master, 'dimensions.length_cm'));
        $preserve('unit', 'unit', $product->unit);
        $preserve('is_active', 'is_active', $product->is_active ? 1 : 0);
        $preserve('publication_status', 'publication_status', data_get($master, 'publication_status', 'publish'));
        $preserve('publication_date', 'publication_date', data_get($master, 'publication_date'));
        $preserve('catalog_visibility', 'catalog_visibility', data_get($master, 'catalog_visibility', 'visible'));
        $preserve('product_type', 'product_type', data_get($master, 'product_type', 'simple'));
        $preserve('variant_attribute', 'variant_attribute', data_get($master, 'variant_attribute'));
        $preserve('developed', 'developed', data_get($master, 'developed', false) ? 1 : 0);
        $preserve('wholesale_price', 'wholesale_price_pln', data_get($master, 'prices.wholesale_price_pln'));
        $preserve('retail_price', 'retail_price_pln', data_get($master, 'prices.retail_price_pln'));
        $preserve('sale_price', 'sale_price_pln', data_get($master, 'prices.sale_price_pln'));
        $preserve('sale_price_starts_at', 'sale_price_starts_at', data_get($master, 'prices.sale_price_starts_at'));
        $preserve('sale_price_ends_at', 'sale_price_ends_at', data_get($master, 'prices.sale_price_ends_at'));
        $preserve('vat_rate', 'vat_rate', $product->vat_rate);
        $preserve('warehouse_location', 'warehouse_location', data_get($master, 'stock.location'));
        $preserve('purchase_price', 'purchase_price_pln', data_get($master, 'prices.purchase_price_pln'));
        $preserve('extra_cost', 'extra_cost_pln', data_get($master, 'prices.extra_cost_pln'));
        $preserve('manage_stock', 'manage_stock', data_get($master, 'inventory.manage_stock', true) ? 1 : 0);
        $preserve('backorders', 'backorders', data_get($master, 'inventory.backorders', 'no'));
        $preserve('low_stock_amount', 'low_stock_amount', data_get($master, 'inventory.low_stock_amount'));
        $preserve('sold_individually', 'sold_individually', data_get($master, 'inventory.sold_individually', false) ? 1 : 0);
        $preserve('name_en', 'name_en', data_get($master, 'content.en.name'));

        $preserve('custom_label_pl', 'custom_label_pl', data_get($master, 'custom_label.pl'));
        $preserve('custom_label_en', 'custom_label_en', data_get($master, 'custom_label.en'));
        $preserve('custom_label_bg_color', 'custom_label_bg_color', data_get($master, 'custom_label.bg_color'));
        $preserve('custom_label_text_color', 'custom_label_text_color', data_get($master, 'custom_label.text_color'));

        $preserve('description_pl', 'description_pl', data_get($master, 'content.pl.description'));
        $preserve('description_en', 'description_en', data_get($master, 'content.en.description'));
        $preserve('short_description_pl', 'short_description_pl', data_get($master, 'content.pl.additional_description'));
        $preserve('short_description_en', 'short_description_en', data_get($master, 'content.en.additional_description'));
        $preserve('related_upsell_products', 'related_upsell_skus', implode("\n", (array) data_get($master, 'related_products.upsell_skus', [])));
        $preserve('related_cross_sell_products', 'related_cross_sell_skus', implode("\n", (array) data_get($master, 'related_products.cross_sell_skus', [])));

        if (($visibleFields['parameters'] ?? true) === false && ! $request->has('parameters')) {
            $parameters = collect((array) data_get($master, 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter))
                ->values();
            $preserved['parameters'] = [
                'name' => $parameters->pluck('name')->all(),
                'value' => $parameters->pluck('value')->all(),
                'variation' => $parameters->map(fn (array $parameter): int => (int) (bool) ($parameter['variation'] ?? false))->all(),
            ];
        }

        if ($preserved !== []) {
            $request->merge($preserved);
        }
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

        if (($result['resumed'] ?? false) === true) {
            return back()->with(
                'status',
                "Wznowiono i dokończono synchronizację produktu w WooCommerce dla kanału {$channel}.",
            );
        }

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
        $eanRule = Rule::unique('products', 'ean');

        if ($product !== null) {
            $skuRule->ignore($product->id);
            $eanRule->ignore($product->id);
        }

        return [
            'sku' => ['nullable', 'string', 'max:255', $skuRule],
            'name' => ['required', 'string', 'max:255'],
            'ean' => [
                'nullable',
                'string',
                'regex:/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/',
                $eanRule,
                function (string $attribute, mixed $value, \Closure $fail) use ($product): void {
                    if ($product !== null && (string) $product->ean === (string) $value) {
                        return;
                    }

                    if (! $this->hasValidGtinCheckDigit((string) $value)) {
                        $fail('Pole EAN musi zawierać poprawny numer GTIN z prawidłową cyfrą kontrolną.');
                    }
                },
            ],
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
            'variant_attribute' => ['nullable', 'string', 'max:255', 'required_with:new_variant_values,new_variant_values_custom'],
            'new_variant_values' => ['nullable', 'array', 'max:100'],
            'new_variant_values.*' => ['nullable', 'string', 'max:120'],
            'new_variant_values_custom' => ['nullable', 'string', 'max:4000'],
            'height_cm' => ['nullable', 'numeric', 'min:0'],
            'width_cm' => ['nullable', 'numeric', 'min:0'],
            'length_cm' => ['nullable', 'numeric', 'min:0'],
            'wholesale_price_pln' => ['nullable', 'numeric', 'min:0'],
            'retail_price_pln' => ['nullable', 'numeric', 'min:0'],
            'sale_price_pln' => ['nullable', 'numeric', 'min:0', 'lte:retail_price_pln'],
            'sale_price_starts_at' => ['nullable', 'date'],
            'sale_price_ends_at' => ['nullable', 'date', 'after_or_equal:sale_price_starts_at'],
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
            'categories' => $categoryNames,
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
            'prices' => $this->priceData($validated, (array) data_get($existingMaster, 'prices', [])),
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
            'suppliers' => $request->has('suppliers')
                ? $this->normalizeSuppliers((array) $request->input('suppliers', []))
                : array_values((array) data_get($existingMaster, 'suppliers', [])),
            'gs1' => (array) data_get($existingMaster, 'gs1', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, float|null>
     */
    private function priceData(array $validated, array $existingPrices = []): array
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
            'purchase_price_pln' => array_key_exists('purchase_price_pln', $validated)
                ? $this->nullableFloat($validated['purchase_price_pln'])
                : $this->nullableFloat($existingPrices['purchase_price_pln'] ?? null),
            'extra_cost_pln' => array_key_exists('extra_cost_pln', $validated)
                ? $this->nullableFloat($validated['extra_cost_pln'])
                : $this->nullableFloat($existingPrices['extra_cost_pln'] ?? null),
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
     * @return array{created:int,error:?string}
     */
    private function createGeneratedVariants(
        Product $parent,
        Request $request,
        mixed $variantAttribute,
        ProductIdentifierService $identifiers,
    ): array {
        $options = collect((array) $request->input('new_variant_values', []))
            ->merge(preg_split('/[\r\n,;]+/', (string) $request->input('new_variant_values_custom', '')) ?: [])
            ->map(fn (mixed $option): string => mb_substr(trim((string) $option), 0, 120))
            ->filter()
            ->unique(fn (string $option): string => mb_strtolower($option))
            ->take(100)
            ->values();

        if ($options->isEmpty()) {
            return ['created' => 0, 'error' => null];
        }

        $variantAttribute = $this->nullableString($variantAttribute)
            ?? $this->nullableString(data_get($parent->masterData(), 'variant_attribute'));

        if ($variantAttribute === null) {
            return ['created' => 0, 'error' => 'Nie utworzono wariantów: wybierz atrybut wariantowy.'];
        }

        $parent->loadMissing('variantChildren');
        $existingOptions = $parent->variantChildren
            ->map(fn (Product $variant): ?string => $this->variantOptionValue($variant, $variantAttribute))
            ->filter()
            ->map(fn (string $option): string => mb_strtolower($option))
            ->flip();
        $nextSortOrder = (int) (ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->max('sort_order') ?? 0);
        $created = 0;
        $eanErrors = [];

        foreach ($options as $option) {
            if ($existingOptions->has(mb_strtolower($option))) {
                continue;
            }

            $variantName = mb_substr($parent->name.' - '.$option, 0, 255);
            $variant = Product::query()->create([
                'sku' => $identifiers->temporarySku(),
                'name' => $variantName,
                'ean' => null,
                'unit' => $parent->unit,
                'vat_rate' => $parent->vat_rate,
                'weight_kg' => $parent->weight_kg,
                'quantity_precision' => $parent->quantity_precision,
                'is_active' => true,
                'attributes' => [
                    'master' => $this->generatedVariantMasterData($parent, $variantAttribute, $option),
                ],
            ]);
            $nextSortOrder += 10;

            ProductRelation::query()->create([
                'parent_product_id' => $parent->id,
                'child_product_id' => $variant->id,
                'relation_type' => 'variant',
                'sort_order' => $nextSortOrder,
                'metadata' => [
                    'created_from' => 'attribute_value_generator',
                    'variant_attribute' => $variantAttribute,
                    'variant_option' => $option,
                ],
            ]);

            $identifiers->ensureSku($variant, true);
            $eanResult = $identifiers->ensureEan($variant);

            if ($eanResult['error'] !== null) {
                $eanErrors[] = $eanResult['error'];
            }

            $existingOptions->put(mb_strtolower($option), true);
            $created++;
        }

        if ($created > 0) {
            $this->markAsVariableParent($parent, $variantAttribute);
            $this->syncVariantParameterDefinition($variantAttribute, $options->all());
        }

        return [
            'created' => $created,
            'error' => collect($eanErrors)->first(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generatedVariantMasterData(Product $parent, string $variantAttribute, string $option): array
    {
        $master = $parent->masterData();
        $master['source'] = 'erp';
        $master['product_type'] = 'variation';
        $master['variant_attribute'] = $variantAttribute;
        $master['media'] = [];
        unset($master['copy']);

        foreach (['pl', 'en'] as $language) {
            $parentName = $this->nullableString(data_get($master, "content.{$language}.name"));

            if ($parentName !== null) {
                data_set($master, "content.{$language}.name", mb_substr($parentName.' - '.$option, 0, 255));
            }
        }

        $parameters = collect((array) data_get($master, 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter))
            ->reject(fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? ''))) === mb_strtolower($variantAttribute))
            ->values()
            ->push([
                'name' => $variantAttribute,
                'value' => $option,
                'variation' => true,
            ])
            ->all();
        $master['parameters'] = $parameters;

        return $master;
    }

    private function variantOptionValue(Product $variant, string $variantAttribute): ?string
    {
        foreach ((array) data_get($variant->masterData(), 'parameters', []) as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            if (mb_strtolower(trim((string) ($parameter['name'] ?? ''))) === mb_strtolower($variantAttribute)) {
                return $this->nullableString($parameter['value'] ?? null);
            }
        }

        foreach ($variant->wooVariationAttributes() as $attribute) {
            if (mb_strtolower(trim((string) ($attribute['name'] ?? ''))) === mb_strtolower($variantAttribute)) {
                return $this->nullableString($attribute['option'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $options
     */
    private function syncVariantParameterDefinition(string $variantAttribute, array $options): void
    {
        $definition = ProductParameterDefinition::query()->firstOrNew(['name' => $variantAttribute]);
        $definition->fill([
            'slug' => $definition->slug ?: Str::slug($variantAttribute),
            'input_type' => 'select',
            'values' => collect((array) $definition->values)
                ->merge($options)
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->unique(fn (string $value): string => mb_strtolower($value))
                ->values()
                ->all(),
            'is_variant' => true,
            'is_required' => $definition->is_required ?? false,
            'sort_order' => $definition->sort_order ?: 100,
            'metadata' => array_merge((array) $definition->metadata, [
                'source' => $definition->exists ? data_get($definition->metadata, 'source', 'erp') : 'erp_variant_generator',
                'updated_from_product_at' => now()->toISOString(),
            ]),
        ])->save();
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
                $relation = $currentRelationBySku->get(mb_strtolower($sku));

                if ($relation instanceof ProductRelation) {
                    $this->markVariantForWooRemoval($product, $relation->childProduct);
                    $relation->delete();
                }

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
            ->where('is_translation', false)
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
                'variantChildren' => fn ($children) => $children
                    ->select($this->productListColumns(qualified: true))
                    ->where('products.is_translation', false),
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
            'is_favorite',
            'is_translation',
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

        if ($filters['favorites']) {
            $products->where(function (Builder $family): void {
                $family
                    ->where('is_favorite', true)
                    ->orWhereHas('variantChildren', fn (Builder $variants) => $variants->where('is_favorite', true));
            });
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
        return $this->primaryProductCategories()
            ->sortBy(fn (ProductCategory $category): string => mb_strtolower($category->path ?: $category->name))
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
    private function productFilters(Request $request, bool $favorites = false): array
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
            'favorites' => $favorites,
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
        $storedCategories = $this->primaryProductCategories()
            ->sortBy(fn (ProductCategory $category): string => mb_strtolower($category->path ?: $category->name))
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
     * Polish is the canonical PIM category. English values live on that same
     * record in metadata, so stale EN-only import rows are never offered as a
     * second category in product forms or filters.
     *
     * @return Collection<int, ProductCategory>
     */
    private function primaryProductCategories(): Collection
    {
        return ProductCategory::query()
            ->with('salesChannel:id,code,name')
            ->get()
            ->reject(function (ProductCategory $category): bool {
                $woocommerceIds = (array) data_get($category->metadata, 'woocommerce_ids', []);

                return filled($woocommerceIds['en'] ?? null)
                    && blank($woocommerceIds['pl'] ?? null);
            })
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
            ->where('is_translation', false)
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

        foreach (['pl', 'en'] as $language) {
            $name = $this->nullableString(data_get($attributes, "master.content.{$language}.name"));

            if ($name !== null) {
                data_set($attributes, "master.content.{$language}.name", $this->copyName($name));
            }
        }

        data_set($attributes, 'master.content.pl.name', $copyName);
        data_set($attributes, 'master.source', 'erp');
        data_set($attributes, 'master.copy.created_from_product_id', $sourceProductId);
        data_set($attributes, 'master.copy.created_at', now()->toISOString());
        data_set($attributes, 'master.media', []);

        return $attributes;
    }

    private function markVariantForWooRemoval(Product $parent, ?Product $variant): void
    {
        if (! $variant instanceof Product) {
            return;
        }

        $parentMappings = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->get()
            ->keyBy('sales_channel_id');

        foreach ($variant->channelMappings()->get() as $mapping) {
            $parentMapping = $parentMappings->get($mapping->sales_channel_id);

            if (! $parentMapping instanceof ProductChannelMapping
                || (string) $parentMapping->external_product_id !== (string) $mapping->external_product_id) {
                continue;
            }

            $metadata = (array) $mapping->metadata;
            $metadata['pending_variant_removal'] = [
                'parent_product_id' => $parent->id,
                'requested_at' => now()->toISOString(),
            ];
            $mapping->forceFill([
                'stock_sync_enabled' => false,
                'metadata' => $metadata,
            ])->save();
        }
    }

    private function hasValidGtinCheckDigit(string $value): bool
    {
        if (preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/', $value) !== 1) {
            return false;
        }

        $sum = 0;
        $position = 0;

        for ($index = strlen($value) - 2; $index >= 0; $index--, $position++) {
            $sum += (int) $value[$index] * ($position % 2 === 0 ? 3 : 1);
        }

        return (int) $value[strlen($value) - 1] === (10 - ($sum % 10)) % 10;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function validateProductTypeSelection(Request $request, array $validated): void
    {
        if (($validated['product_type'] ?? 'simple') !== 'simple') {
            return;
        }

        $hasGeneratedVariants = collect((array) $request->input('new_variant_values', []))
            ->filter(fn (mixed $value): bool => filled($value))
            ->isNotEmpty()
            || filled($request->input('new_variant_values_custom'));
        $remainingAttachedVariants = collect((array) $request->input('variant_skus', []))
            ->filter(function (mixed $sku, int|string $index) use ($request): bool {
                return filled($sku) && ! filter_var(
                    data_get((array) $request->input('variant_remove', []), $index, false),
                    FILTER_VALIDATE_BOOLEAN,
                );
            })
            ->isNotEmpty();

        if ($hasGeneratedVariants || $remainingAttachedVariants) {
            throw ValidationException::withMessages([
                'product_type' => 'Produkt prosty nie może mieć wariantów. Usuń wszystkie warianty albo wybierz typ „Produkt wariantowy”.',
            ]);
        }
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

    /**
     * @param  list<array{src:string,alt:?string,name:string}>  $media
     */
    private function deleteStoredMediaFiles(array $media): void
    {
        foreach ($media as $row) {
            $src = ltrim((string) ($row['src'] ?? ''), '/');

            if ($src === '' || (! str_starts_with($src, 'uploads/products/') && ! str_starts_with($src, 'uploads/testing-products/'))) {
                continue;
            }

            File::delete(public_path($src));
        }
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
