<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\ExportWooCommerceProductDataJob;
use App\Jobs\ImportWooCommerceProductsJob;
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
use App\Services\Inventory\StockSyncQueueService;
use App\Services\Inventory\WarehouseDocumentNumberService;
use App\Services\Inventory\WarehouseDocumentPostingService;
use App\Services\Products\LegacySizeVariantAxisResolver;
use App\Services\Products\ProductDescriptionSanitizer;
use App\Services\Products\ProductEditFieldSettingsService;
use App\Services\Products\ProductIdentifierService;
use App\Services\Products\ProductImportIssueService;
use App\Services\Products\ProductStorefrontVisibilityService;
use App\Services\Products\ProductVariantAxisNameResolver;
use App\Services\Products\ProductVariantInheritanceService;
use App\Services\Products\ProductVariantOptionNormalizer;
use App\Services\WooCommerce\ProductDataExportService;
use App\Services\WooCommerce\WooCommerceProductCreationRecoveryService;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

    /** @var list<string> */
    private const BULK_EDIT_FIELDS = [
        'category_ids',
        'retail_price_pln',
        'sale_price_pln',
        'is_active',
        'catalog_visibility',
        'publication_date',
        'publication_status',
        'sale_price_starts_at',
        'sale_price_ends_at',
        'backorders',
        'custom_label_pl',
        'custom_label_en',
        'custom_label_bg_color',
        'custom_label_text_color',
        'lemon_shipping_days',
        'lemon_shipping_text',
        'lemon_preorder',
    ];

    public function index(Request $request, ProductImportIssueService $importIssues): View
    {
        $isFavorites = $request->routeIs('products.favorites');
        $filters = $this->productFilters($request, $isFavorites);
        $importIssue = $importIssues->resolve($request->query('import_issue'));

        return view('products.index', [
            'productRows' => $this->productListRows($filters, $importIssue),
            'productsCount' => Product::query()->where('is_translation', false)->count(),
            'filters' => $filters,
            'importIssue' => $importIssue,
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
        ProductVariantInheritanceService $variantInheritance,
    ): View {
        $product->load([
            'stockBalances.warehouse',
            'channelMappings.salesChannel',
            'childRelations.childProduct',
            'variantParents.channelMappings.salesChannel',
            'variantChildren.stockBalances.warehouse',
        ]);
        $catalogVisibilityUsesParent = $this->catalogVisibilityUsesParent($product);
        $catalogVisibilityParent = $catalogVisibilityUsesParent
            ? $this->catalogVisibilityParent($product)
            : null;
        $effectiveMaster = $catalogVisibilityParent instanceof Product
            && $variantInheritance->inheritsFromParent($product, $catalogVisibilityParent)
                ? $variantInheritance->masterData($catalogVisibilityParent, $product)
                : $product->masterData();
        $mappedSalesChannelIds = $product->channelMappings
            ->pluck('sales_channel_id')
            ->filter()
            ->all();

        return view('products.edit', [
            'product' => $product,
            'categoryOptions' => $this->categoryOptions(),
            'catalogOptions' => $this->catalogOptions(),
            'parameterOptions' => $this->parameterOptions($product),
            'productLookupOptions' => $this->productLookupOptions($product),
            'visibleProductEditFields' => $productEditFields->visibleFields(),
            'catalogVisibilityUsesParent' => $catalogVisibilityUsesParent,
            'catalogVisibilityParent' => $catalogVisibilityParent,
            'effectiveMaster' => $effectiveMaster,
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

    public function store(
        Request $request,
        ProductIdentifierService $identifiers,
        ProductDescriptionSanitizer $descriptionSanitizer,
    ): RedirectResponse {
        $validated = $request->validate($this->productValidationRules());
        $validated = $this->sanitizeProductDescriptions($validated, $descriptionSanitizer);
        $validated = $this->normalizeVariantAxisInput($validated, $request);
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
                    [],
                    $request->boolean('is_active', true),
                );
                $product->forceFill(['attributes' => $attributes])->save();
                $identifiers->ensureSku($product, $requestedSku === null);
                $eanResult = $identifiers->ensureEan($product);
                $this->syncVariantRelations(
                    $product,
                    (array) $request->input('variant_skus', []),
                    [],
                    (array) $request->input('variant_sort_order', []),
                    $validated['variant_attribute'] ?? null,
                );
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
        ProductDescriptionSanitizer $descriptionSanitizer,
        ProductVariantInheritanceService $variantInheritance,
    ): RedirectResponse {
        $catalogVisibilityWasSubmitted = $request->has('catalog_visibility');
        $catalogVisibilityUsesParent = $this->catalogVisibilityUsesParent($product);
        $catalogVisibilityParent = $catalogVisibilityUsesParent
            ? $this->catalogVisibilityParent($product)
            : null;
        $inheritanceParent = $catalogVisibilityParent instanceof Product
            && $variantInheritance->inheritsFromParent($product, $catalogVisibilityParent)
                ? $catalogVisibilityParent
                : null;

        if ($inheritanceParent instanceof Product) {
            $effectiveAttributes = (array) $product->attributes;
            $effectiveAttributes['master'] = $variantInheritance->masterData($inheritanceParent, $product);
            $product->setAttribute('attributes', $effectiveAttributes);
        }

        $this->preserveHiddenProductFields($request, $product, $productEditFields->visibleFields());

        if ($catalogVisibilityUsesParent) {
            $request->merge([
                'catalog_visibility' => $catalogVisibilityParent instanceof Product && $catalogVisibilityWasSubmitted
                    ? $request->input('catalog_visibility')
                    : data_get(
                        ($catalogVisibilityParent ?? $product)->masterData(),
                        'catalog_visibility',
                        'visible',
                    ),
            ]);
        }

        $validated = $request->validate($this->productValidationRules($product));
        $validated = $this->sanitizeProductDescriptions($validated, $descriptionSanitizer);
        $validated = $this->normalizeVariantAxisInput($validated, $request, $product);
        $this->validateProductTypeSelection($request, $validated);

        $before = [
            'product' => $product->only(['sku', 'name', 'ean', 'unit', 'vat_rate', 'weight_kg', 'is_active']),
            'attributes' => $product->attributes,
        ];

        $uploadedMedia = [];
        try {
            [$eanResult, $generatedVariants] = DB::transaction(function () use ($product, $request, $validated, $identifiers, $audit, $before, $catalogVisibilityParent, $inheritanceParent, $variantInheritance, &$uploadedMedia): array {
                $attributes = (array) $product->attributes;
                $currentMaster = (array) data_get($attributes, 'master', []);
                $uploadedMedia = $inheritanceParent instanceof Product
                    ? []
                    : $this->storeUploadedMedia($product, $request);
                $media = ! ($inheritanceParent instanceof Product)
                    && ($request->has('existing_media') || $request->hasFile('new_media'))
                ? array_merge(
                    $this->normalizeExistingMedia((array) $request->input('existing_media', [])),
                    $uploadedMedia,
                )
                : (array) (is_array($currentMaster['media'] ?? null)
                    ? $currentMaster['media']
                    : $product->mediaImages());

                $attributes['master'] = $this->masterDataFromRequest(
                    $validated,
                    $request,
                    $media,
                    $currentMaster,
                    $request->boolean('is_active'),
                );

                if ($inheritanceParent instanceof Product) {
                    $attributes['master'] = $variantInheritance->inheritedMasterData(
                        $inheritanceParent,
                        $attributes['master'],
                    );
                }

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
                    (array) $request->input('variant_sort_order', []),
                    $validated['variant_attribute'] ?? null,
                );
                $generatedVariants = $this->createGeneratedVariants(
                    $product,
                    $request,
                    $validated['variant_attribute'] ?? null,
                    $identifiers,
                );
                if ($inheritanceParent instanceof Product) {
                    $variantInheritance->synchronizeVariant($inheritanceParent, $product);
                } else {
                    $variantInheritance->synchronizeFamily($product);
                }
                $product->load('variantChildren');

                foreach ($product->variantChildren as $variant) {
                    $variantEanResult = $identifiers->ensureEan($variant);
                    $eanResult['error'] ??= $variantEanResult['error'];
                }

                $audit->record('product.master_data_updated', $product, $before, [
                    'product' => $product->only(['sku', 'name', 'ean', 'unit', 'vat_rate', 'weight_kg', 'is_active']),
                    'attributes' => $product->attributes,
                ]);

                if ($catalogVisibilityParent instanceof Product) {
                    $visibility = (string) ($validated['catalog_visibility'] ?? 'visible');
                    $previousVisibility = (string) data_get(
                        $catalogVisibilityParent->masterData(),
                        'catalog_visibility',
                        'visible',
                    );

                    if ($visibility !== $previousVisibility) {
                        $parentAttributes = (array) $catalogVisibilityParent->attributes;
                        data_set($parentAttributes, 'master.source', 'erp');
                        data_set($parentAttributes, 'master.catalog_visibility', $visibility);
                        $catalogVisibilityParent->forceFill(['attributes' => $parentAttributes])->save();

                        if ($inheritanceParent instanceof Product) {
                            $variantInheritance->synchronizeVariant($inheritanceParent, $product);
                        }

                        $audit->record('product.catalog_visibility_updated_from_variant', $catalogVisibilityParent, [
                            'catalog_visibility' => $previousVisibility,
                        ], [
                            'catalog_visibility' => $visibility,
                            'variant_product_id' => $product->id,
                            'variant_sku' => $product->sku,
                        ]);
                    }
                }

                return [$eanResult, $generatedVariants];
            });
        } catch (\Throwable $exception) {
            $this->deleteStoredMediaFiles($uploadedMedia);

            throw $exception;
        }

        $parentExportQueued = $catalogVisibilityParent instanceof Product
            && $this->queueWooCommerceDataExport($catalogVisibilityParent);

        if (! $parentExportQueued) {
            $this->queueWooCommerceDataExport($product);
        }

        $redirect = redirect()
            ->route('products.edit', $product)
            ->with('status', $generatedVariants['created'] > 0
                ? "Dane produktu zostały zapisane i utworzono {$generatedVariants['created']} brakujących wariantów. Synchronizacja zmapowanych kanałów WooCommerce została uruchomiona od razu."
                : 'Dane produktu zostały zapisane jako dane główne ERP. Synchronizacja zmapowanych kanałów WooCommerce została uruchomiona od razu.');

        $identifierError = $eanResult['error'] ?? $generatedVariants['error'];

        return $identifierError !== null
            ? $redirect->with('warning', $identifierError)
            : $redirect;
    }

    public function bulkUpdate(
        Request $request,
        AuditLogService $audit,
        ProductImportIssueService $importIssues,
        ProductVariantInheritanceService $variantInheritance,
    ): RedirectResponse {
        $allowedFields = implode(',', self::BULK_EDIT_FIELDS);
        $validated = $request->validateWithBag('bulk', [
            'selection_mode' => ['required', 'string', 'in:selected,all_filtered'],
            'product_ids' => ['nullable', 'array', 'max:5000'],
            'product_ids.*' => ['integer', 'distinct'],
            'excluded_ids' => ['nullable', 'array', 'max:5000'],
            'excluded_ids.*' => ['integer', 'distinct'],
            'filters' => ['nullable', 'array'],
            'filters.q' => ['nullable', 'string', 'max:255'],
            'filters.channel' => ['nullable', 'string', 'max:100'],
            'filters.warehouse' => ['nullable', 'integer'],
            'filters.stock' => ['nullable', 'string', 'in:available,reserved,out_of_stock,no_stock'],
            'filters.type' => ['nullable', 'string', 'in:with_variants,without_variants'],
            'filters.category' => ['nullable', 'string', 'max:255'],
            'filters.status' => ['nullable', 'string', 'in:active,inactive,publish,draft'],
            'filters.favorites' => ['nullable', 'boolean'],
            'filters.import_issue' => ['nullable', 'integer'],
            'apply' => ['required', "array:{$allowedFields}", 'min:1'],
            'apply.*' => ['accepted'],
            'changes' => ['nullable', 'array'],
            'changes.category_ids' => ['nullable', 'array'],
            'changes.category_ids.*' => ['integer', 'distinct', 'exists:product_categories,id'],
            'changes.retail_price_pln' => ['nullable', 'numeric', 'min:0'],
            'changes.sale_price_pln' => ['nullable', 'numeric', 'min:0'],
            'changes.is_active' => ['nullable', 'boolean'],
            'changes.catalog_visibility' => ['nullable', 'string', 'in:visible,catalog,search,hidden'],
            'changes.publication_date' => ['nullable', 'date'],
            'changes.publication_status' => ['nullable', 'string', 'in:publish,draft,pending,private'],
            'changes.sale_price_starts_at' => ['nullable', 'date'],
            'changes.sale_price_ends_at' => ['nullable', 'date'],
            'changes.backorders' => ['nullable', 'string', 'in:no,notify,yes'],
            'changes.custom_label_pl' => ['nullable', 'string', 'max:120'],
            'changes.custom_label_en' => ['nullable', 'string', 'max:120'],
            'changes.custom_label_bg_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'changes.custom_label_text_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'changes.lemon_shipping_days' => ['nullable', 'integer', 'min:0'],
            'changes.lemon_shipping_text' => ['nullable', 'string', 'max:1000'],
            'changes.lemon_preorder' => ['nullable', 'boolean'],
        ]);
        $apply = collect(array_keys((array) ($validated['apply'] ?? [])))
            ->intersect(self::BULK_EDIT_FIELDS)
            ->values()
            ->all();
        $changes = (array) ($validated['changes'] ?? []);
        $requiredValues = [
            'is_active',
            'catalog_visibility',
            'publication_status',
            'backorders',
            'custom_label_bg_color',
            'custom_label_text_color',
            'lemon_preorder',
        ];

        foreach ($requiredValues as $field) {
            if (in_array($field, $apply, true)
                && (! array_key_exists($field, $changes) || $changes[$field] === null || $changes[$field] === '')
            ) {
                throw ValidationException::withMessages([
                    "changes.{$field}" => 'Wybierz wartość dla zaznaczonego pola.',
                ])->errorBag('bulk');
            }
        }

        $selection = $this->bulkProductSelectionQuery($validated, $importIssues);
        $productIds = $selection
            ->pluck('products.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            throw ValidationException::withMessages([
                'selection_mode' => 'Zaznacz co najmniej jeden produkt do edycji.',
            ])->errorBag('bulk');
        }

        $categoryData = $this->bulkCategoryData($apply, $changes);
        $selectionMode = (string) $validated['selection_mode'];
        $updatedProductIds = DB::transaction(function () use ($productIds, $apply, $changes, $categoryData, $selectionMode, $audit, $variantInheritance): array {
            $updated = [];

            Product::query()
                ->whereIn('id', $productIds->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(function (Product $product) use (&$updated, $apply, $changes, $categoryData, $selectionMode, $audit, $variantInheritance): void {
                    $master = $product->masterData();
                    $this->assertBulkProductChangeIsValid($product, $master, $apply, $changes);
                    $before = [
                        'is_active' => (bool) $product->is_active,
                        'master' => $master,
                    ];
                    $master = $this->bulkUpdatedMasterData($master, $apply, $changes, $categoryData);

                    if (in_array('is_active', $apply, true)) {
                        $product->is_active = filter_var($changes['is_active'], FILTER_VALIDATE_BOOLEAN);
                    }

                    $attributes = (array) $product->attributes;
                    $attributes['master'] = $master;
                    $product->forceFill(['attributes' => $attributes])->save();
                    $variantInheritance->synchronizeFamily($product);
                    $audit->record('product.bulk_updated', $product, $before, [
                        'is_active' => (bool) $product->is_active,
                        'master' => $master,
                    ], [
                        'applied_fields' => $apply,
                        'selection_mode' => $selectionMode,
                    ]);
                    $updated[] = (int) $product->id;
                });

            return $updated;
        });

        $queued = collect($updatedProductIds)
            ->filter(fn (int $productId): bool => $this->queueWooCommerceDataExport(
                Product::query()->findOrFail($productId),
            ))
            ->count();
        $count = count($updatedProductIds);
        $productLabel = $this->polishProductCountLabel($count);
        $mappedProductLabel = $queued === 1 ? 'zmapowanego produktu' : 'zmapowanych produktów';

        return back()->with(
            'status',
            "Zmieniono grupowo {$count} {$productLabel}. Synchronizację WooCommerce uruchomiono dla {$queued} {$mappedProductLabel}.",
        );
    }

    public function duplicate(
        Product $product,
        AuditLogService $audit,
        ProductIdentifierService $identifiers,
        ProductVariantInheritanceService $variantInheritance,
        ProductVariantOptionNormalizer $variantOptions,
        LegacySizeVariantAxisResolver $legacySizeAxis,
    ): RedirectResponse {
        $product->load([
            'childRelations' => fn ($query) => $query
                ->where('relation_type', 'variant')
                ->with('childProduct'),
        ]);
        [$copy, $copiedVariants] = DB::transaction(function () use ($product, $identifiers, $audit, $variantInheritance, $variantOptions, $legacySizeAxis): array {
            $copy = $product->replicate([
                'sku',
                'storefront_hidden_at',
                'storefront_restore_visibility',
                'stock_verification_required_at',
                'created_at',
                'updated_at',
            ]);
            $copy->name = $this->copyName($product->name);
            $copy->sku = $identifiers->temporarySku();
            $copy->ean = null;
            $copy->attributes = $this->copyAttributes((array) $product->attributes, $copy->name, $product->id);
            $copy->is_active = false;
            $copy->save();
            $identifiers->ensureSku($copy, true);

            $copiedVariants = [];
            $variantAttribute = $this->variantAttributeForCopy(
                $product,
                $copy,
                $variantOptions,
                $legacySizeAxis,
            );

            if ($product->childRelations->isNotEmpty()) {
                $copyAttributes = (array) $copy->attributes;
                data_set($copyAttributes, 'master.product_type', 'variable');
                data_set($copyAttributes, 'master.variant_attribute', $variantAttribute);

                if ($variantAttribute === ProductVariantAxisNameResolver::SIZE) {
                    $sourceVariantAttribute = $this->nullableString(data_get(
                        $product->masterData(),
                        'variant_attribute',
                    ));
                    $parameters = $this->canonicalizeCopiedSizeParameters(
                        (array) data_get($copyAttributes, 'master.parameters', []),
                        $sourceVariantAttribute,
                        $sourceVariantAttribute === null
                            ? []
                            : $this->copyVariantAxisEvidence($product, $sourceVariantAttribute),
                    );
                    data_set($copyAttributes, 'master.parameters', $parameters);
                }

                $copy->forceFill(['attributes' => $copyAttributes])->save();
            }

            foreach ($product->childRelations as $sourceRelation) {
                $sourceVariant = $sourceRelation->childProduct;

                if (! $sourceVariant instanceof Product) {
                    continue;
                }

                $optionParameter = $this->variantOptionParameter($sourceVariant, $variantAttribute);
                $option = $this->nullableString($optionParameter['value'] ?? null)
                    ?? $this->nullableString(data_get($sourceRelation->metadata, 'variant_option'))
                    ?? $sourceVariant->name;
                $option = $legacySizeAxis->canonicalSizeOption(
                    $product,
                    $variantAttribute,
                    $option,
                ) ?? $option;
                $option = $variantOptions->normalize($variantAttribute, $option);
                $optionParameter ??= [
                    'name' => $variantAttribute,
                    'value' => $option,
                    'variation' => true,
                ];
                $optionParameter['name'] = $variantAttribute;
                $optionParameter['value'] = $option;
                $optionParameter['variation'] = true;
                $variantCopy = Product::query()->create([
                    'sku' => $identifiers->temporarySku(),
                    'name' => mb_substr($copy->name.' - '.$option, 0, 255),
                    'ean' => null,
                    'unit' => $copy->unit,
                    'vat_rate' => $copy->vat_rate,
                    'weight_kg' => $copy->weight_kg,
                    'quantity_precision' => $copy->quantity_precision,
                    'is_active' => $sourceVariant->is_active,
                    'attributes' => [
                        'master' => $variantInheritance->newVariantMasterData(
                            $copy,
                            $variantAttribute,
                            $optionParameter,
                            [
                                'created_from_product_id' => $sourceVariant->id,
                                'created_at' => now()->toISOString(),
                            ],
                        ),
                    ],
                ]);

                ProductRelation::query()->create([
                    'parent_product_id' => $copy->id,
                    'child_product_id' => $variantCopy->id,
                    'relation_type' => 'variant',
                    'sort_order' => $sourceRelation->sort_order,
                    'metadata' => array_merge((array) $sourceRelation->metadata, [
                        'copied_from_relation_id' => $sourceRelation->id,
                        'copied_at' => now()->toISOString(),
                        'variant_attribute' => $variantAttribute,
                        'variant_option' => $option,
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
        if ($this->catalogVisibilityUsesParent($product)) {
            return back()->withInput()->with(
                'error',
                'Nie można dodać wariantu do wariantu. Relacje i kolejność ustaw na produkcie głównym.',
            );
        }

        $validated = $request->validate([
            'relation_type' => ['required', 'string', 'in:variant'],
            'child_sku' => ['required', 'string', 'exists:products,sku'],
            'variant_attribute' => ['nullable', 'string', 'max:255'],
        ]);
        $child = Product::query()->where('sku', $validated['child_sku'])->firstOrFail();

        if ((int) $child->id === (int) $product->id) {
            return back()->withInput()->with('error', 'Produkt nie może być swoim własnym wariantem.');
        }

        $variantAttribute = $this->nullableString($validated['variant_attribute'] ?? null)
            ?? $this->nullableString(data_get($product->masterData(), 'variant_attribute'))
            ?? ProductVariantAxisNameResolver::SIZE;
        $protectedLegacyAxis = $this->legacyVariantAxisProtectedUntilWooRepair($product);
        $variantAttribute = $protectedLegacyAxis
            ?? app(ProductVariantAxisNameResolver::class)->resolve(
                $variantAttribute,
                $this->variantAxisEvidence($request, $variantAttribute, $product)
                    ->merge($this->productVariantAxisValues($child, $variantAttribute)),
                $this->knownSizeOptions(),
            );
        $childSourceAttribute = $this->nullableString(data_get($child->masterData(), 'variant_attribute'))
            ?? collect((array) data_get($child->masterData(), 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter)
                    && (bool) ($parameter['variation'] ?? false))
                ->map(fn (array $parameter): ?string => $this->nullableString($parameter['name'] ?? null))
                ->filter()
                ->first()
            ?? $this->nullableString($validated['variant_attribute'] ?? null)
            ?? $variantAttribute;

        if ($protectedLegacyAxis !== null
            && ! $this->variantAxisNamesMatch($childSourceAttribute, $protectedLegacyAxis)
        ) {
            return back()->withInput()->with(
                'error',
                'Najpierw wykonaj naprawę osi wariantów WooCommerce. Do istniejącej rodziny nie można teraz dołączyć wariantu z inną osią.',
            );
        }

        if (! $this->variantChildAxisIsCompatible(
            $product,
            $child,
            $childSourceAttribute,
            $variantAttribute,
        )) {
            return back()->withInput()->with(
                'error',
                'Nie można dołączyć wariantu: jego oś i wartości nie odpowiadają osi wariantów produktu głównego.',
            );
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
                    'variant_attribute' => $variantAttribute,
                ],
            ],
        );

        $this->markAsVariableParent($product, $variantAttribute);
        if ($protectedLegacyAxis === null) {
            $this->canonicalizeUnmappedVariantChild(
                $child,
                $childSourceAttribute,
                $variantAttribute,
            );
        }
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

    public function hideFromStorefront(
        Product $product,
        ProductStorefrontVisibilityService $storefront,
    ): RedirectResponse {
        try {
            $result = $storefront->hide($product);
        } catch (\Throwable $exception) {
            return back()->with('error', 'Nie udało się ukryć produktu: '.$exception->getMessage());
        }

        /** @var Product $root */
        $root = $result['root'];

        return back()->with(
            'status',
            $result['changed']
                ? "Produkt {$root->name} został ukryty w sklepie. Jego strona bezpośrednia pozostaje opublikowana, a stan w WooCommerce jest zablokowany na 0."
                : "Produkt {$root->name} jest już ukryty w sklepie.",
        );
    }

    public function revealOnStorefront(
        Product $product,
        ProductStorefrontVisibilityService $storefront,
    ): RedirectResponse {
        try {
            $result = $storefront->reveal($product);
        } catch (\Throwable $exception) {
            return back()->with('error', 'Nie udało się odkryć produktu: '.$exception->getMessage());
        }

        /** @var Product $root */
        $root = $result['root'];

        return back()->with(
            'status',
            $result['changed']
                ? "Produkt {$root->name} został odkryty. Stan w WooCommerce pozostaje równy 0 do ręcznego potwierdzenia."
                : "Produkt {$root->name} jest już widoczny w sklepie.",
        );
    }

    public function verifyStorefrontStock(
        Product $product,
        ProductStorefrontVisibilityService $storefront,
    ): RedirectResponse {
        try {
            $result = $storefront->verifyStock($product);
        } catch (\Throwable $exception) {
            return back()->with('error', 'Nie udało się potwierdzić stanu produktu: '.$exception->getMessage());
        }

        /** @var Product $root */
        $root = $result['root'];

        return back()->with(
            'status',
            $result['changed']
                ? "Stan produktu {$root->name} został ręcznie potwierdzony i przekazany do synchronizacji z WooCommerce."
                : "Stan produktu {$root->name} był już potwierdzony.",
        );
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
        StockSyncQueueService $stockSyncQueue,
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
        $target = round((float) $validated['new_quantity'], 4);
        $current = null;
        $delta = null;
        $document = null;

        try {
            $result = DB::transaction(function () use (
                $product,
                $warehouse,
                $validated,
                $numbers,
                $posting,
                $target,
                &$current,
                &$delta,
                &$document,
            ): array {
                $now = now();

                DB::table('stock_balances')->insertOrIgnore([
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $product->id,
                    'quantity_on_hand' => 0,
                    'quantity_reserved' => 0,
                    'quantity_available' => 0,
                    'recalculated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $balance = StockBalance::query()
                    ->where('product_id', $product->id)
                    ->where('warehouse_id', $warehouse->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $current = round((float) $balance->quantity_on_hand, 4);
                $delta = round($target - $current, 4);

                if (abs($delta) < 0.0001) {
                    return ['balance' => $balance, 'document' => null];
                }

                $document = WarehouseDocument::query()->create([
                    'number' => $numbers->next('KOR'),
                    'type' => 'KOR',
                    'status' => 'draft',
                    'destination_warehouse_id' => $warehouse->id,
                    'document_date' => $now,
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

                $posting->post($document);

                return ['balance' => $balance->refresh(), 'document' => $document->refresh()];
            }, 3);
        } catch (RuntimeException $exception) {
            $audit->record('product.stock_adjust_failed', $product, null, null, [
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'document_id' => $document?->id,
                'current_quantity' => $current,
                'target_quantity' => $target,
                'delta_quantity' => $delta,
                'error' => $exception->getMessage(),
            ]);

            return $this->redirectAfterStockAdjustment($request, $product)
                ->with('error', 'Nie zaksięgowano korekty stanu: '.$exception->getMessage());
        }

        /** @var StockBalance $balance */
        $balance = $result['balance'];
        /** @var WarehouseDocument|null $document */
        $document = $result['document'];

        if (! $document instanceof WarehouseDocument) {
            $queued = $stockSyncQueue->queueForTriggers([
                [
                    'warehouse_id' => (int) $warehouse->id,
                    'product_id' => (int) $product->id,
                ],
            ], 'manual_stock_sync_requested');

            $audit->record('product.stock_sync_requested', $product, null, null, [
                'warehouse_id' => $warehouse->id,
                'warehouse_code' => $warehouse->code,
                'quantity_on_hand' => $current,
                'quantity_reserved' => (float) ($balance?->quantity_reserved ?? 0),
                'quantity_available' => (float) ($balance?->quantity_available ?? 0),
                'queued_channels' => $queued,
            ]);

            $syncMessage = $queued > 0
                ? ' Aktualny stan dostępny dodano ponownie do synchronizacji z WooCommerce.'
                : ' Nie znaleziono aktywnej trasy eksportu stanu do WooCommerce.';

            return $this->redirectAfterStockAdjustment($request, $product)
                ->with('status', "Stan ogółem SKU {$product->sku} w magazynie {$warehouse->code} już wynosi {$this->formatQuantity($target)}.{$syncMessage}");
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
            ->with('status', "Zmieniono stan ogółem SKU {$product->sku} w magazynie {$warehouse->code} z {$this->formatQuantity($current)} na {$this->formatQuantity($target)} dokumentem {$document->number}. Stan dostępny do synchronizacji z WooCommerce jest liczony po odjęciu rezerwacji.");
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
        $preserve('lemon_shipping_days', 'lemon_shipping_days', data_get($master, 'shipping.days'));
        $preserve('lemon_shipping_text', 'lemon_shipping_text', data_get($master, 'shipping.text'));
        $preserve('lemon_preorder', 'lemon_preorder', data_get($master, 'shipping.preorder', false) ? 1 : 0);

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

    private function queueWooCommerceDataExport(Product $product): bool
    {
        $syncToken = (string) Str::uuid();
        $mappingCount = DB::transaction(function () use ($product, $syncToken): int {
            $mappings = ProductChannelMapping::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->get();

            foreach ($mappings as $mapping) {
                $metadata = (array) $mapping->metadata;
                data_set($metadata, 'product_data_export.pending_token', $syncToken);
                data_set($metadata, 'product_data_export.requested_at', now()->toISOString());
                $mapping->forceFill(['metadata' => $metadata])->save();
            }

            return $mappings->count();
        });

        if ($mappingCount === 0) {
            return false;
        }

        ExportWooCommerceProductDataJob::dispatch($product->id, $syncToken)
            ->onConnection('database');
        ExportWooCommerceProductDataJob::dispatchAfterResponse($product->id, $syncToken);

        return true;
    }

    private function catalogVisibilityUsesParent(Product $product): bool
    {
        $product->loadMissing(['channelMappings', 'variantParents']);

        return data_get($product->masterData(), 'product_type') === 'variation'
            || $product->variantParents->isNotEmpty()
            || $product->channelMappings->contains(
                fn (ProductChannelMapping $mapping): bool => filled($mapping->external_variation_id),
            );
    }

    private function catalogVisibilityParent(Product $product): ?Product
    {
        $product->loadMissing(['channelMappings', 'variantParents']);
        $parents = $product->variantParents->keyBy('id');

        foreach ($product->channelMappings as $mapping) {
            if (! filled($mapping->external_variation_id)) {
                continue;
            }

            $parentMapping = ProductChannelMapping::query()
                ->with('product')
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->where('external_product_id', $mapping->external_product_id)
                ->whereNull('external_variation_id')
                ->where('product_id', '!=', $product->id)
                ->first();

            if ($parentMapping?->product instanceof Product) {
                $parents->put($parentMapping->product->id, $parentMapping->product);
            }
        }

        return $parents->count() === 1 ? $parents->first() : null;
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
        ProductStorefrontVisibilityService $storefront,
        WooOwnedVariantAxisRepairService $axisRepair,
    ): RedirectResponse {
        if ($axisRepair->blocksFullExport($product)) {
            return back()->with(
                'error',
                'Ta historyczna rodzina ma zablokowany pełny eksport do czasu bezpiecznej naprawy osi Rozmiar w ERP i WooCommerce.',
            );
        }

        $familyRootId = $axisRepair->familyRootId((int) $product->id);
        $locks = $this->acquireWooCommerceMutationLocks(
            $familyRootId,
            ImportWooCommerceProductsJob::catalogIntegrationIdsForProduct($familyRootId),
        );

        if ($locks === null) {
            return back()->with('status', 'Synchronizacja tego produktu z WooCommerce już trwa. Nowsze dane zostaną wysłane po jej zakończeniu.');
        }

        try {
            $product->refresh();

            if ($axisRepair->blocksFullExport($product)) {
                return back()->with(
                    'error',
                    'Ta historyczna rodzina została właśnie skierowana do bezpiecznej naprawy osi Rozmiar. Pełny eksport pozostaje wstrzymany.',
                );
            }

            $result = $exportService->export($product);
            $storefront->completeSuccessfulManualExport($product);
        } catch (\Throwable $exception) {
            $audit->record('product.woocommerce_export_failed', $product, null, null, [
                'error' => $exception->getMessage(),
            ]);

            return back()->with('error', $exception->getMessage());
        } finally {
            $this->releaseWooCommerceMutationLocks($locks);
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
        WooCommerceProductCreationRecoveryService $creationRecovery,
        WooOwnedVariantAxisRepairService $axisRepair,
    ): RedirectResponse {
        $locks = $this->acquireWooCommerceMutationLocks(
            $axisRepair->familyRootId((int) $product->id),
            collect([(int) $integration->id]),
        );

        if ($locks === null) {
            return back()->with('status', 'Tworzenie lub synchronizacja tego produktu w WooCommerce już trwa.');
        }

        try {
            $result = $exportService->create($product, $integration);
        } catch (\Throwable $exception) {
            $failureAudit = $audit->record('product.woocommerce_create_failed', $product, null, null, [
                'wordpress_integration_id' => $integration->id,
                'sales_channel_id' => $integration->sales_channel_id,
                'error' => $exception->getMessage(),
            ]);
            $creationRecovery->markPendingForFailure(
                $product,
                $integration,
                $failureAudit,
                $exception,
            );

            return back()->with('error', $exception->getMessage());
        } finally {
            $this->releaseWooCommerceMutationLocks($locks);
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

    /**
     * Acquire the same ordered family → integration locks as queued catalog
     * jobs so a manual button cannot overlap an import or axis repair.
     *
     * @param  iterable<mixed>  $integrationIds
     * @return list<Lock>|null
     */
    private function acquireWooCommerceMutationLocks(
        int $familyRootId,
        iterable $integrationIds,
    ): ?array {
        $locks = [
            Cache::lock(
                ExportWooCommerceProductDataJob::lockKey($familyRootId),
                ExportWooCommerceProductDataJob::LOCK_SECONDS,
            ),
        ];

        collect($integrationIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->each(function (int $integrationId) use (&$locks): void {
                $locks[] = Cache::lock(
                    ImportWooCommerceProductsJob::catalogLockKey($integrationId),
                    ImportWooCommerceProductsJob::CATALOG_LOCK_SECONDS,
                );
            });

        $acquired = [];

        foreach ($locks as $lock) {
            if ($lock->get()) {
                $acquired[] = $lock;

                continue;
            }

            $this->releaseWooCommerceMutationLocks($acquired);

            return null;
        }

        return $acquired;
    }

    /** @param list<Lock> $locks */
    private function releaseWooCommerceMutationLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            $lock->release();
        }
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
            'lemon_shipping_days' => ['nullable', 'integer', 'min:0'],
            'lemon_shipping_text' => ['nullable', 'string', 'max:1000'],
            'lemon_preorder' => ['nullable', 'boolean'],
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
            'variant_sort_order' => ['nullable', 'array'],
            'variant_sort_order.*' => ['nullable', 'integer', 'min:1', 'max:65535'],
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
    private function masterDataFromRequest(
        array $validated,
        Request $request,
        array $media,
        array $existingMaster = [],
        ?bool $isActive = null,
    ): array {
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
        $publicationStatus = $this->nullableString($validated['publication_status'] ?? null)
            ?? (string) data_get($existingMaster, 'publication_status', 'publish');
        $publicationDate = $request->exists('publication_date')
            ? $this->nullableDateTimeString($validated['publication_date'] ?? null)
            : $this->nullableDateTimeString(data_get($existingMaster, 'publication_date'));

        if ($publicationDate === null && $publicationStatus === 'publish' && ($isActive ?? false)) {
            $publicationDate = now()->format('Y-m-d\TH:i');
        }

        return [
            'source' => 'erp',
            'catalog' => $this->nullableString($validated['catalog'] ?? null) ?? 'Domyślny',
            'category' => $legacyCategory,
            'category_ids' => $categoryIds->all(),
            'categories' => $categoryNames,
            'producer' => $this->nullableString($validated['producer'] ?? null) ?? 'SEMPRE',
            'tags' => $this->tagList($validated['tags'] ?? ''),
            'asin' => $this->nullableString($validated['asin'] ?? null),
            'publication_status' => $publicationStatus,
            'publication_date' => $publicationDate,
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
            'shipping' => [
                'days' => array_key_exists('lemon_shipping_days', $validated)
                    ? ($this->nullableString($validated['lemon_shipping_days']) === null
                        ? null
                        : (int) $validated['lemon_shipping_days'])
                    : data_get($existingMaster, 'shipping.days'),
                'text' => array_key_exists('lemon_shipping_text', $validated)
                    ? $this->nullableString($validated['lemon_shipping_text'])
                    : $this->nullableString(data_get($existingMaster, 'shipping.text')),
                'preorder' => $request->has('lemon_preorder')
                    ? $request->boolean('lemon_preorder')
                    : (bool) data_get($existingMaster, 'shipping.preorder', false),
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
            'parameters' => $this->normalizeParameters(
                (array) $request->input('parameters', []),
                (array) data_get($existingMaster, 'parameters', []),
            ),
            'media' => $media,
            'media_updated_at' => $request->has('existing_media') || $request->hasFile('new_media')
                ? now()->toISOString()
                : data_get($existingMaster, 'media_updated_at'),
            'suppliers' => $request->has('suppliers')
                ? $this->normalizeSuppliers((array) $request->input('suppliers', []))
                : array_values((array) data_get($existingMaster, 'suppliers', [])),
            'gs1' => (array) data_get($existingMaster, 'gs1', []),
            'copy' => (array) data_get($existingMaster, 'copy', []),
            'inheritance' => (array) data_get($existingMaster, 'inheritance', []),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function sanitizeProductDescriptions(
        array $validated,
        ProductDescriptionSanitizer $sanitizer,
    ): array {
        foreach ([
            'description_pl',
            'description_en',
            'short_description_pl',
            'short_description_en',
            'additional_description_pl',
        ] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = $sanitizer->sanitize(
                    is_string($validated[$field]) ? $validated[$field] : null,
                );
            }
        }

        return $validated;
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
        $variantAttribute = $this->legacyVariantAxisProtectedUntilWooRepair($product)
            ?? $variantAttribute;
        $attributes = (array) $product->attributes;
        $master = (array) data_get($attributes, 'master', []);
        $master['source'] = 'erp';
        $master['product_type'] = 'variable';
        $master['variant_attribute'] = $variantAttribute;
        data_set($attributes, 'master', $master);
        $product->forceFill(['attributes' => $attributes])->save();
    }

    /**
     * A regular editor save cannot perform the local half of a remote axis
     * migration first. Keep the current local legacy declaration until the
     * dedicated Woo repair has synchronized parent and children remotely.
     */
    private function legacyVariantAxisProtectedUntilWooRepair(Product $product): ?string
    {
        $variantAttribute = $this->nullableString(data_get(
            $product->masterData(),
            'variant_attribute',
        ));

        if ($variantAttribute === null
            || (! app(LegacySizeVariantAxisResolver::class)->isLegacyGeneric($variantAttribute)
                && ! app(ProductVariantAxisNameResolver::class)
                    ->isLegacyPluralSizeAlias($variantAttribute))
        ) {
            return null;
        }

        $state = (array) data_get(
            $product->masterData(),
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );

        if (WooOwnedVariantAxisRepairService::isSynchronizedRevision(
            $state['revision'] ?? null,
        )) {
            return null;
        }

        $product->loadMissing('channelMappings.salesChannel');
        $hasActiveWooMapping = $product->channelMappings->contains(
            fn (ProductChannelMapping $mapping): bool => filled($mapping->external_product_id)
                && $mapping->salesChannel?->type === 'woocommerce'
                && (bool) $mapping->salesChannel?->is_active,
        );

        return $hasActiveWooMapping ? $variantAttribute : null;
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

    private function variantChildAxisIsCompatible(
        Product $parent,
        Product $child,
        string $childSourceAttribute,
        string $parentAttribute,
    ): bool {
        $resolver = app(ProductVariantAxisNameResolver::class);
        $knownSizeOptions = $this->knownSizeOptions();
        $resolvedParentAttribute = $resolver->resolve(
            $parentAttribute,
            $this->storedVariantFamilyAxisEvidence($parent, $parentAttribute),
            $knownSizeOptions,
        );
        $resolvedChildAttribute = $resolver->resolve(
            $childSourceAttribute,
            $this->productVariantAxisValues($child, $childSourceAttribute),
            $knownSizeOptions,
        );

        return $this->variantAxisNamesMatch(
            $resolvedChildAttribute,
            $resolvedParentAttribute,
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function storedVariantFamilyAxisEvidence(
        Product $product,
        string $variantAttribute,
    ): Collection {
        $values = $this->productVariantAxisValues($product, $variantAttribute);
        $product->loadMissing('variantChildren');

        foreach ($product->variantChildren as $variant) {
            $values = $values->merge($this->productVariantAxisValues(
                $variant,
                $variantAttribute,
            ));
            $metadataAttribute = trim((string) data_get(
                $variant->pivot?->metadata,
                'variant_attribute',
                '',
            ));

            if ($this->variantAxisNamesMatch($metadataAttribute, $variantAttribute)) {
                $values->push(data_get($variant->pivot?->metadata, 'variant_option'));
            }
        }

        return app(ProductVariantAxisNameResolver::class)->optionTokens($values);
    }

    private function canonicalizeUnmappedVariantChild(
        Product $child,
        string $sourceAttribute,
        string $resolvedAttribute,
    ): void {
        if ($sourceAttribute === $resolvedAttribute) {
            return;
        }

        $resolver = app(ProductVariantAxisNameResolver::class);
        $childResolvedAttribute = $resolver->resolve(
            $sourceAttribute,
            $this->productVariantAxisValues($child, $sourceAttribute),
            $this->knownSizeOptions(),
        );

        if (! $this->variantAxisNamesMatch($childResolvedAttribute, $resolvedAttribute)) {
            return;
        }

        $child->loadMissing('channelMappings.salesChannel');

        // Only an active Woo mapping participates in the remote-first guard.
        // Marketplace/BaseLinker identities do not own the Woo variant axis
        // and must not keep legacy aliases alive in the ERP copy.
        if ($child->channelMappings->contains(
            fn (ProductChannelMapping $mapping): bool => filled($mapping->external_product_id)
                && $mapping->salesChannel?->type === 'woocommerce'
                && (bool) $mapping->salesChannel?->is_active,
        )) {
            return;
        }

        $attributes = (array) $child->attributes;
        $master = (array) data_get($attributes, 'master', []);
        $master['variant_attribute'] = $resolvedAttribute;
        $master['parameters'] = collect((array) ($master['parameters'] ?? []))
            ->map(function (mixed $parameter) use ($sourceAttribute, $resolvedAttribute): mixed {
                if (! is_array($parameter) || ! $this->variantAxisNamesMatch(
                    (string) ($parameter['name'] ?? ''),
                    $sourceAttribute,
                )) {
                    return $parameter;
                }

                $parameter['name'] = $resolvedAttribute;
                $parameter['value'] = app(ProductVariantOptionNormalizer::class)->normalize(
                    $resolvedAttribute,
                    $parameter['value'] ?? '',
                );

                return $parameter;
            })
            ->values()
            ->all();
        $attributes['master'] = $master;
        $child->forceFill(['attributes' => $attributes])->save();
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
        if ($this->catalogVisibilityUsesParent($parent)) {
            return [
                'created' => 0,
                'error' => $request->filled('new_variant_values') || $request->filled('new_variant_values_custom')
                    ? 'Nie utworzono wariantów: wariant nie może zawierać kolejnych wariantów.'
                    : null,
            ];
        }

        $variantAttribute = $this->nullableString($variantAttribute)
            ?? $this->nullableString(data_get($parent->masterData(), 'variant_attribute'));

        if ($variantAttribute === null) {
            return ['created' => 0, 'error' => 'Nie utworzono wariantów: wybierz atrybut wariantowy.'];
        }

        $variantOptions = app(ProductVariantOptionNormalizer::class);
        $options = collect((array) $request->input('new_variant_values', []))
            ->merge(preg_split('/[\r\n,;]+/', (string) $request->input('new_variant_values_custom', '')) ?: [])
            ->map(fn (mixed $option): string => mb_substr(trim((string) $option), 0, 120))
            ->map(fn (string $option): string => $variantOptions->normalize($variantAttribute, $option))
            ->filter()
            ->unique(fn (string $option): string => $variantOptions->identity($variantAttribute, $option))
            ->take(100)
            ->values();

        if ($options->isEmpty()) {
            return ['created' => 0, 'error' => null];
        }

        $parent->loadMissing('variantChildren');
        $existingOptions = $parent->variantChildren
            ->map(fn (Product $variant): ?string => $this->variantOptionValue($variant, $variantAttribute))
            ->filter()
            ->map(fn (string $option): string => $variantOptions->identity($variantAttribute, $option))
            ->flip();
        $nextSortOrder = (int) (ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->max('sort_order') ?? 0);
        $created = 0;
        $eanErrors = [];

        foreach ($options as $option) {
            $optionIdentity = $variantOptions->identity($variantAttribute, $option);

            if ($existingOptions->has($optionIdentity)) {
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

            $existingOptions->put($optionIdentity, true);
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
        return app(ProductVariantInheritanceService::class)->newVariantMasterData(
            $parent,
            $variantAttribute,
            [
                'name' => $variantAttribute,
                'value' => $option,
                'variation' => true,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function variantOptionParameter(Product $variant, string $variantAttribute): ?array
    {
        $parameters = collect((array) data_get($variant->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter));

        $matching = $parameters->filter(
            fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? ''))) === mb_strtolower($variantAttribute),
        )->values();
        $matchingVariant = $matching->first(fn (array $parameter): bool => (bool) ($parameter['variation'] ?? false)
            && ! $this->isAggregateVariantOption($parameter['value'] ?? null));

        if (is_array($matchingVariant)) {
            return $matchingVariant;
        }

        $variantParameters = $parameters
            ->filter(fn (array $parameter): bool => (bool) ($parameter['variation'] ?? false))
            ->values();

        $matchingSingleOption = $matching->first(fn (array $parameter): bool => ! $this->isAggregateVariantOption(
            $parameter['value'] ?? null,
        ));

        if (is_array($matchingSingleOption)) {
            return $matchingSingleOption;
        }

        if (app(ProductVariantOptionNormalizer::class)->isSizeAttribute($variantAttribute)) {
            $sizeParameters = $variantParameters
                ->filter(fn (array $parameter): bool => app(ProductVariantOptionNormalizer::class)->isSizeAttribute(
                    (string) ($parameter['name'] ?? ''),
                ))
                ->values();

            if ($sizeParameters->count() === 1) {
                return $sizeParameters->first();
            }
        }

        if ($variantParameters->count() === 1) {
            return $variantParameters->first();
        }

        return null;
    }

    private function isAggregateVariantOption(mixed $value): bool
    {
        return preg_match('/[,;|]/u', trim((string) ($value ?? ''))) === 1;
    }

    private function variantAttributeForCopy(
        Product $source,
        Product $copy,
        ProductVariantOptionNormalizer $variantOptions,
        LegacySizeVariantAxisResolver $legacySizeAxis,
    ): string {
        $variants = $source->childRelations
            ->map(fn (ProductRelation $relation): ?Product => $relation->childProduct)
            ->filter(fn (?Product $variant): bool => $variant instanceof Product)
            ->values();
        $recoveredSizeAxis = $legacySizeAxis->recover($source, $variants);

        if ($recoveredSizeAxis !== null) {
            return $this->resolvedVariantAttributeForCopy($source, $recoveredSizeAxis);
        }

        $explicit = $this->nullableString(data_get($copy->masterData(), 'variant_attribute'));

        if ($explicit !== null) {
            return $this->resolvedVariantAttributeForCopy($source, $explicit);
        }

        $relationCandidate = $this->singleVariantAttributeCandidate(
            $source->childRelations
                ->map(fn (ProductRelation $relation): string => trim((string) data_get(
                    $relation->metadata,
                    'variant_attribute',
                    '',
                )))
                ->map(fn (string $candidate): string => $this->resolvedVariantAttributeForCopy(
                    $source,
                    $candidate,
                )),
            $variantOptions,
        );

        if ($relationCandidate !== null) {
            return $relationCandidate;
        }

        $commonCandidates = $variants
            ->flatMap(function (Product $variant): array {
                return collect((array) data_get($variant->masterData(), 'parameters', []))
                    ->filter(fn (mixed $parameter): bool => is_array($parameter)
                        && (bool) ($parameter['variation'] ?? false))
                    ->map(fn (array $parameter): string => trim((string) ($parameter['name'] ?? '')))
                    ->filter()
                    ->unique(fn (string $candidate): string => mb_strtolower($candidate))
                    ->map(fn (string $candidate): array => [
                        'name' => $candidate,
                        'product_id' => (int) $variant->id,
                    ])
                    ->values()
                    ->all();
            })
            ->groupBy(fn (array $candidate): string => mb_strtolower($candidate['name']))
            ->filter(fn (Collection $candidates): bool => $candidates
                ->pluck('product_id')
                ->unique()
                ->count() === $variants->count())
            ->map(fn (Collection $candidates): string => (string) $candidates->first()['name'])
            ->map(fn (string $candidate): string => $this->resolvedVariantAttributeForCopy(
                $source,
                $candidate,
            ))
            ->values();
        $commonCandidate = $this->singleVariantAttributeCandidate($commonCandidates, $variantOptions);

        if ($commonCandidate !== null) {
            return $commonCandidate;
        }

        $parentCandidate = $this->singleVariantAttributeCandidate(
            collect((array) data_get($source->masterData(), 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter)
                    && (bool) ($parameter['variation'] ?? false))
                ->map(fn (array $parameter): string => trim((string) ($parameter['name'] ?? '')))
                ->map(fn (string $candidate): string => $this->resolvedVariantAttributeForCopy(
                    $source,
                    $candidate,
                )),
            $variantOptions,
        );

        return $parentCandidate ?? ProductVariantAxisNameResolver::SIZE;
    }

    private function resolvedVariantAttributeForCopy(Product $source, string $candidate): string
    {
        return app(ProductVariantAxisNameResolver::class)->resolve(
            $candidate,
            $this->copyVariantAxisEvidence($source, $candidate),
            $this->knownSizeOptions(),
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function copyVariantAxisEvidence(Product $source, string $candidate): Collection
    {
        $values = $this->productVariantAxisValues($source, $candidate);

        foreach ($source->childRelations as $relation) {
            $variant = $relation->childProduct;

            if ($variant instanceof Product) {
                $values = $values->merge($this->productVariantAxisValues($variant, $candidate));
            }

            $relationAttribute = trim((string) data_get(
                $relation->metadata,
                'variant_attribute',
                '',
            ));

            if ($this->variantAxisNamesMatch($relationAttribute, $candidate)) {
                $values->push(data_get($relation->metadata, 'variant_option'));
            }
        }

        return app(ProductVariantAxisNameResolver::class)->optionTokens($values);
    }

    /**
     * @param  Collection<int, string>  $candidates
     */
    private function singleVariantAttributeCandidate(
        Collection $candidates,
        ProductVariantOptionNormalizer $variantOptions,
    ): ?string {
        $candidates = $candidates
            ->filter()
            ->unique(fn (string $candidate): string => mb_strtolower(trim($candidate)))
            ->values();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $sizeCandidates = $candidates
            ->filter(fn (string $candidate): bool => $variantOptions->isSizeAttribute($candidate))
            ->values();

        return $sizeCandidates->count() === 1 ? $sizeCandidates->first() : null;
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
        $variantAttribute = app(ProductVariantAxisNameResolver::class)->resolve(
            $variantAttribute,
            $options,
            $this->knownSizeOptions(),
        );
        $options = collect($options)
            ->map(fn (mixed $option): string => app(ProductVariantOptionNormalizer::class)
                ->normalize($variantAttribute, $option))
            ->all();
        $definition = ProductParameterDefinition::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($variantAttribute)])
            ->first()
            ?? ProductParameterDefinition::query()->make(['name' => $variantAttribute]);
        $definition->fill([
            'name' => $variantAttribute,
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
     * @param  array<int|string, mixed>  $submittedSortOrders
     */
    private function syncVariantRelations(
        Product $product,
        array $submittedSkus,
        array $removeFlags,
        array $submittedSortOrders,
        mixed $variantAttribute,
    ): void {
        if ($this->catalogVisibilityUsesParent($product)) {
            return;
        }

        if ($submittedSkus === [] && $removeFlags === []) {
            return;
        }

        $variantAttribute = $this->nullableString($variantAttribute)
            ?? $this->nullableString(data_get($product->masterData(), 'variant_attribute'))
            ?? 'Rozmiar';
        $protectedLegacyAxis = $this->legacyVariantAxisProtectedUntilWooRepair($product);
        $variantAttribute = $protectedLegacyAxis ?? $variantAttribute;
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

            $skusToAttach[$index] = $sku;
        }

        $children = Product::query()
            ->whereIn('sku', collect($skusToAttach)->unique()->values()->all())
            ->get()
            ->keyBy('sku');
        $nextSortOrder = (int) (ProductRelation::query()
            ->where('parent_product_id', $product->id)
            ->where('relation_type', 'variant')
            ->max('sort_order') ?? 0);

        foreach (collect($skusToAttach)->unique() as $index => $sku) {
            $child = $children->get($sku);

            if (! $child instanceof Product || (int) $child->id === (int) $product->id) {
                continue;
            }

            $existing = $currentRelationBySku->get(mb_strtolower($sku));
            $childSourceAttribute = $this->nullableString(data_get($child->masterData(), 'variant_attribute'))
                ?? collect((array) data_get($child->masterData(), 'parameters', []))
                    ->filter(fn (mixed $parameter): bool => is_array($parameter)
                        && (bool) ($parameter['variation'] ?? false))
                    ->map(fn (array $parameter): ?string => $this->nullableString($parameter['name'] ?? null))
                    ->filter()
                    ->first()
                ?? $variantAttribute;

            if ($protectedLegacyAxis !== null
                && ! $this->variantAxisNamesMatch($childSourceAttribute, $protectedLegacyAxis)
            ) {
                throw ValidationException::withMessages([
                    'variant_skus' => 'Najpierw wykonaj naprawę osi wariantów WooCommerce. Do istniejącej rodziny nie można teraz dołączyć wariantu z inną osią.',
                ]);
            }

            if (! $this->variantChildAxisIsCompatible(
                $product,
                $child,
                $childSourceAttribute,
                $variantAttribute,
            )) {
                throw ValidationException::withMessages([
                    'variant_skus' => 'Nie można dołączyć wariantu: jego oś i wartości nie odpowiadają osi wariantów produktu głównego.',
                ]);
            }

            $nextSortOrder = $existing instanceof ProductRelation
                ? $nextSortOrder
                : min(65535, $nextSortOrder + 10);
            $submittedSortOrder = $submittedSortOrders[$index] ?? null;
            $sortOrder = $submittedSortOrder === null || $submittedSortOrder === ''
                ? ($existing?->sort_order ?? $nextSortOrder)
                : max(1, min(65535, (int) $submittedSortOrder));
            $nextSortOrder = max($nextSortOrder, (int) $sortOrder);

            ProductRelation::query()->updateOrCreate(
                [
                    'parent_product_id' => $product->id,
                    'child_product_id' => $child->id,
                    'relation_type' => 'variant',
                ],
                [
                    'sort_order' => $sortOrder,
                    'metadata' => array_merge((array) $existing?->metadata, [
                        'created_from' => 'product_editor',
                        'variant_attribute' => $variantAttribute,
                    ]),
                ],
            );

            if ($protectedLegacyAxis === null) {
                $this->canonicalizeUnmappedVariantChild(
                    $child,
                    $childSourceAttribute,
                    $variantAttribute,
                );
            }
            $this->markAsVariantChild($child);
        }

        if ($skusToAttach !== [] || ProductRelation::query()->where('parent_product_id', $product->id)->where('relation_type', 'variant')->exists()) {
            $this->markAsVariableParent($product, $variantAttribute);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function bulkProductSelectionQuery(
        array $validated,
        ProductImportIssueService $importIssues,
    ): Builder {
        $products = $this->productFamilyQuery();

        if (($validated['selection_mode'] ?? null) === 'selected') {
            return $products->whereIn('products.id', collect((array) ($validated['product_ids'] ?? []))
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->all());
        }

        $filterInput = (array) ($validated['filters'] ?? []);
        $filters = $this->normalizedProductFilters(
            $filterInput,
            filter_var($filterInput['favorites'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
        $importIssue = $importIssues->resolve($filterInput['import_issue'] ?? null);
        $this->applyProductListFilters($products, $filters, $importIssue);
        $excludedIds = collect((array) ($validated['excluded_ids'] ?? []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->all();

        return $excludedIds === [] ? $products : $products->whereNotIn('products.id', $excludedIds);
    }

    /**
     * @param  list<string>  $apply
     * @param  array<string, mixed>  $changes
     * @return array{ids:list<int>,names:list<string>,legacy:?string}
     */
    private function bulkCategoryData(array $apply, array $changes): array
    {
        if (! in_array('category_ids', $apply, true)) {
            return ['ids' => [], 'names' => [], 'legacy' => null];
        }

        $ids = collect((array) ($changes['category_ids'] ?? []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $categories = $ids->isEmpty()
            ? collect()
            : ProductCategory::query()
                ->whereIn('id', $ids->all())
                ->get()
                ->sortBy(fn (ProductCategory $category): int|false => $ids->search($category->id));
        $names = $categories
            ->map(fn (ProductCategory $category): string => $category->path ?: $category->name)
            ->values()
            ->all();

        return [
            'ids' => $ids->all(),
            'names' => $names,
            'legacy' => $names[0] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $master
     * @param  list<string>  $apply
     * @param  array<string, mixed>  $changes
     */
    private function assertBulkProductChangeIsValid(
        Product $product,
        array $master,
        array $apply,
        array $changes,
    ): void {
        $retailPrice = in_array('retail_price_pln', $apply, true)
            ? $this->nullableFloat($changes['retail_price_pln'] ?? null)
            : $this->nullableFloat(data_get($master, 'prices.retail_price_pln'));
        $salePrice = in_array('sale_price_pln', $apply, true)
            ? $this->nullableFloat($changes['sale_price_pln'] ?? null)
            : $this->nullableFloat(data_get($master, 'prices.sale_price_pln'));

        if ($salePrice !== null && ($retailPrice === null || $salePrice > $retailPrice)) {
            throw ValidationException::withMessages([
                'changes.sale_price_pln' => "Produkt {$product->sku}: cena promocyjna nie może być wyższa od ceny regularnej.",
            ])->errorBag('bulk');
        }

        $startsAt = in_array('sale_price_starts_at', $apply, true)
            ? $this->nullableDateString($changes['sale_price_starts_at'] ?? null)
            : $this->nullableDateString(data_get($master, 'prices.sale_price_starts_at'));
        $endsAt = in_array('sale_price_ends_at', $apply, true)
            ? $this->nullableDateString($changes['sale_price_ends_at'] ?? null)
            : $this->nullableDateString(data_get($master, 'prices.sale_price_ends_at'));

        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            throw ValidationException::withMessages([
                'changes.sale_price_ends_at' => "Produkt {$product->sku}: data końca promocji nie może być wcześniejsza od daty rozpoczęcia.",
            ])->errorBag('bulk');
        }
    }

    /**
     * @param  array<string, mixed>  $master
     * @param  list<string>  $apply
     * @param  array<string, mixed>  $changes
     * @param  array{ids:list<int>,names:list<string>,legacy:?string}  $categoryData
     * @return array<string, mixed>
     */
    private function bulkUpdatedMasterData(
        array $master,
        array $apply,
        array $changes,
        array $categoryData,
    ): array {
        $master['source'] = 'erp';

        if (in_array('category_ids', $apply, true)) {
            $master['category_ids'] = $categoryData['ids'];
            $master['categories'] = $categoryData['names'];
            $master['category'] = $categoryData['legacy'];
        }

        $prices = (array) data_get($master, 'prices', []);

        if (in_array('retail_price_pln', $apply, true)) {
            $retailPrice = $this->nullableFloat($changes['retail_price_pln'] ?? null);
            $prices['retail_price_pln'] = $retailPrice;
            $prices['price_eur'] = $this->convertedPrice($retailPrice, 'EUR');
            $prices['price_gbp'] = $this->convertedPrice($retailPrice, 'GBP');
            $prices['price_usd'] = $this->convertedPrice($retailPrice, 'USD');
        }

        if (in_array('sale_price_pln', $apply, true)) {
            $prices['sale_price_pln'] = $this->nullableFloat($changes['sale_price_pln'] ?? null);
        }

        if (in_array('sale_price_starts_at', $apply, true)) {
            $prices['sale_price_starts_at'] = $this->nullableDateString($changes['sale_price_starts_at'] ?? null);
        }

        if (in_array('sale_price_ends_at', $apply, true)) {
            $prices['sale_price_ends_at'] = $this->nullableDateString($changes['sale_price_ends_at'] ?? null);
        }

        $master['prices'] = $prices;

        if (in_array('catalog_visibility', $apply, true)) {
            $master['catalog_visibility'] = (string) $changes['catalog_visibility'];
        }

        if (in_array('publication_date', $apply, true)) {
            $master['publication_date'] = $this->nullableDateTimeString($changes['publication_date'] ?? null);
        }

        if (in_array('publication_status', $apply, true)) {
            $master['publication_status'] = (string) $changes['publication_status'];
        }

        if (in_array('backorders', $apply, true)) {
            data_set($master, 'inventory.backorders', (string) $changes['backorders']);
        }

        foreach ([
            'custom_label_pl' => 'custom_label.pl',
            'custom_label_en' => 'custom_label.en',
            'custom_label_bg_color' => 'custom_label.bg_color',
            'custom_label_text_color' => 'custom_label.text_color',
            'lemon_shipping_text' => 'shipping.text',
        ] as $field => $path) {
            if (in_array($field, $apply, true)) {
                data_set($master, $path, $this->nullableString($changes[$field] ?? null));
            }
        }

        if (in_array('lemon_shipping_days', $apply, true)) {
            data_set(
                $master,
                'shipping.days',
                $this->nullableString($changes['lemon_shipping_days'] ?? null) === null
                    ? null
                    : (int) $changes['lemon_shipping_days'],
            );
        }

        if (in_array('lemon_preorder', $apply, true)) {
            data_set(
                $master,
                'shipping.preorder',
                filter_var($changes['lemon_preorder'], FILTER_VALIDATE_BOOLEAN),
            );
        }

        return $master;
    }

    private function productFamilyQuery(): Builder
    {
        return Product::query()
            ->where('is_translation', false)
            ->whereDoesntHave('parentRelations', fn (Builder $relations) => $relations->where('relation_type', 'variant'));
    }

    private function polishProductCountLabel(int $count): string
    {
        if ($count === 1) {
            return 'produkt';
        }

        $lastTwoDigits = $count % 100;
        $lastDigit = $count % 10;

        return $lastDigit >= 2 && $lastDigit <= 4 && ($lastTwoDigits < 12 || $lastTwoDigits > 14)
            ? 'produkty'
            : 'produktów';
    }

    /**
     * Loads only the current page of product families. The old implementation
     * hydrated every SKU, every balance and every channel mapping before slicing
     * the collection in PHP.
     *
     * @param  array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string}  $filters
     * @return LengthAwarePaginator<int, array{product:Product,variants:Collection<int, Product>,family_variants:Collection<int, Product>,is_import_issue:bool}>
     */
    private function productListRows(array $filters, ?array $importIssue = null): LengthAwarePaginator
    {
        $products = $this->productFamilyQuery()
            ->select($this->productListColumns())
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

        $this->applyProductListFilters($products, $filters, $importIssue);
        $this->applyProductListOrder($products);

        $paginator = $products
            ->paginate(self::PRODUCT_LIST_PER_PAGE)
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()
                ->map(function (Product $product) use ($importIssue): array {
                    $familyVariants = $product->variantChildren->values();
                    $variants = $familyVariants;

                    if ($importIssue !== null) {
                        $variants = $variants
                            ->filter(fn (Product $variant): bool => $this->matchesProductImportIssue($variant, $importIssue, 'variation'))
                            ->values();
                    }

                    return [
                        'product' => $product,
                        'variants' => $variants,
                        'family_variants' => $familyVariants,
                        'is_import_issue' => $importIssue !== null
                            && $this->matchesProductImportIssue($product, $importIssue, 'product'),
                    ];
                }),
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
            'storefront_hidden_at',
            'storefront_restore_visibility',
            'stock_verification_required_at',
            'created_at',
        ])->map(fn (string $column): string => $prefix.$column)->all();
    }

    /**
     * @param  array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string}  $filters
     */
    private function applyProductListFilters(Builder $products, array $filters, ?array $importIssue = null): void
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

        if ($importIssue !== null) {
            $this->applyProductImportIssueFilter($products, $importIssue);
        }
    }

    /**
     * Limits the product family query to the exact ERP records reported by an
     * import log. IDs are preferred because the duplicated SKU is shared by
     * more than one WooCommerce entity. The SKU is only a fallback for older
     * payloads which did not persist an ERP product ID.
     *
     * @param  array<string, mixed>  $importIssue
     */
    private function applyProductImportIssueFilter(Builder $products, array $importIssue): void
    {
        $targetIds = (array) data_get($importIssue, 'targets.ids', []);
        $productSkus = (array) data_get($importIssue, 'targets.product_skus', []);
        $variationSkus = (array) data_get($importIssue, 'targets.variation_skus', []);

        $products->where(function (Builder $family) use ($targetIds, $productSkus, $variationSkus): void {
            $hasParentTargets = $targetIds !== [] || $productSkus !== [];
            $hasVariantTargets = $targetIds !== [] || $variationSkus !== [];

            if ($hasParentTargets) {
                $family->where(function (Builder $product) use ($targetIds, $productSkus): void {
                    $this->applyProductImportIssueModelFilter($product, $targetIds, $productSkus);
                });
            }

            if ($hasVariantTargets) {
                $method = $hasParentTargets ? 'orWhereHas' : 'whereHas';
                $family->{$method}('variantChildren', function (Builder $variant) use ($targetIds, $variationSkus): void {
                    $this->applyProductImportIssueModelFilter($variant, $targetIds, $variationSkus);
                });
            }

            if (! $hasParentTargets && ! $hasVariantTargets) {
                $family->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @param  list<int>  $targetIds
     * @param  list<string>  $targetSkus
     */
    private function applyProductImportIssueModelFilter(Builder $products, array $targetIds, array $targetSkus): void
    {
        $qualifiedId = $products->qualifyColumn('id');
        $qualifiedSku = $products->qualifyColumn('sku');

        $products->where(function (Builder $match) use ($qualifiedId, $qualifiedSku, $targetIds, $targetSkus): void {
            if ($targetIds !== []) {
                $match->whereIn($qualifiedId, $targetIds);
            }

            if ($targetSkus !== []) {
                $method = $targetIds !== [] ? 'orWhereIn' : 'whereIn';
                $match->{$method}(DB::raw('LOWER('.$qualifiedSku.')'), $targetSkus);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $importIssue
     */
    private function matchesProductImportIssue(Product $product, array $importIssue, string $entityKind): bool
    {
        if (in_array((int) $product->id, (array) data_get($importIssue, 'targets.ids', []), true)) {
            return true;
        }

        $skuKey = $entityKind === 'variation' ? 'variation_skus' : 'product_skus';

        return in_array(
            mb_strtolower(trim((string) $product->sku)),
            (array) data_get($importIssue, 'targets.'.$skuKey, []),
            true,
        );
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
     * @return Collection<int, array{name:string,values:list<string>,is_variant:bool,is_required:bool,input_type:string,aliases:list<string>,canonicalized_aliases:list<string>}>
     */
    private function productListParameterOptions(): Collection
    {
        $options = ProductParameterDefinition::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductParameterDefinition $definition): array => [
                'name' => $definition->name,
                '_definition_slug' => $definition->slug,
                'values' => (array) $definition->values,
                'is_variant' => (bool) $definition->is_variant,
                'is_required' => (bool) $definition->is_required,
                'input_type' => $definition->input_type,
            ]);

        return $this->canonicalizeVariantAxisParameterOptions($options);
    }

    /**
     * @return array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string}
     */
    private function productFilters(Request $request, bool $favorites = false): array
    {
        return $this->normalizedProductFilters((array) $request->query(), $favorites);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{q:string,channel:string,warehouse:string,stock:string,type:string,category:string,status:string,favorites:bool}
     */
    private function normalizedProductFilters(array $input, bool $favorites = false): array
    {
        $warehouseId = (string) ($input['warehouse'] ?? '');

        return [
            'q' => $this->nullableString($input['q'] ?? null) ?? '',
            'channel' => $this->nullableString($input['channel'] ?? null) ?? '',
            'warehouse' => ctype_digit($warehouseId) && Warehouse::query()->whereKey((int) $warehouseId)->exists()
                ? $warehouseId
                : '',
            'stock' => in_array($input['stock'] ?? null, ['available', 'reserved', 'out_of_stock', 'no_stock'], true)
                ? (string) $input['stock']
                : '',
            'type' => in_array($input['type'] ?? null, ['with_variants', 'without_variants'], true)
                ? (string) $input['type']
                : '',
            'category' => $this->nullableString($input['category'] ?? null) ?? '',
            'status' => in_array($input['status'] ?? null, ['active', 'inactive', 'publish', 'draft'], true)
                ? (string) $input['status']
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
     * Canonicalize only the axis selected in an ERP size-family operation.
     * Generic names stay untouched unless the submitted/current option values
     * prove that they represent sizes.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeVariantAxisInput(
        array $validated,
        Request $request,
        ?Product $product = null,
    ): array {
        $sourceAttribute = $this->nullableString($validated['variant_attribute'] ?? null);

        if ($product instanceof Product
            && ($protectedLegacyAxis = $this->legacyVariantAxisProtectedUntilWooRepair($product)) !== null
        ) {
            $submittedAttribute = $sourceAttribute ?? $protectedLegacyAxis;
            $validated['variant_attribute'] = $protectedLegacyAxis;
            $request->merge(['variant_attribute' => $protectedLegacyAxis]);
            $parameters = (array) $request->input('parameters', []);
            $names = (array) ($parameters['name'] ?? []);
            $variations = (array) ($parameters['variation'] ?? []);

            foreach ($names as $index => $name) {
                if ($this->variantAxisNamesMatch((string) $name, $protectedLegacyAxis)) {
                    $names[$index] = $protectedLegacyAxis;

                    continue;
                }

                // The editor may render the selected legacy size axis as its
                // canonical label. Rewrite that selected variation row only;
                // an informational Rozmiar row next to `wariant` must survive
                // unchanged until the remote-first repair merges the axes.
                if ($this->variantAxisNamesMatch((string) $name, $submittedAttribute)
                    && filter_var(
                        $variations[$index] ?? false,
                        FILTER_VALIDATE_BOOLEAN,
                    )
                ) {
                    $names[$index] = $protectedLegacyAxis;
                }
            }

            if ($names !== []) {
                $parameters['name'] = $names;
                $request->merge(['parameters' => $parameters]);
            }

            return $validated;
        }

        if ($sourceAttribute === null) {
            return $validated;
        }

        $resolvedAttribute = app(ProductVariantAxisNameResolver::class)->resolve(
            $sourceAttribute,
            $this->variantAxisEvidence($request, $sourceAttribute, $product),
            $this->knownSizeOptions(),
        );

        if ($resolvedAttribute === $sourceAttribute
            && $resolvedAttribute !== ProductVariantAxisNameResolver::SIZE
        ) {
            return $validated;
        }

        $validated['variant_attribute'] = $resolvedAttribute;
        $request->merge(['variant_attribute' => $resolvedAttribute]);
        $parameters = (array) $request->input('parameters', []);
        $names = (array) ($parameters['name'] ?? []);
        $values = (array) ($parameters['value'] ?? []);
        $knownSizeOptions = $this->knownSizeOptions();

        foreach ($names as $index => $name) {
            $name = trim((string) $name);

            if ($resolvedAttribute === ProductVariantAxisNameResolver::SIZE
                && app(ProductVariantAxisNameResolver::class)->resolve(
                    $name,
                    $this->submittedParameterAxisEvidence(
                        $name,
                        $values[$index] ?? null,
                        $product,
                    ),
                    $knownSizeOptions,
                ) === ProductVariantAxisNameResolver::SIZE
            ) {
                $names[$index] = ProductVariantAxisNameResolver::SIZE;

                continue;
            }

            if ($resolvedAttribute !== $sourceAttribute
                && $this->variantAxisNamesMatch($name, $sourceAttribute)
            ) {
                $names[$index] = $resolvedAttribute;
            }
        }

        if ($names !== []) {
            $parameters['name'] = $names;
            $request->merge(['parameters' => $parameters]);
        }

        return $validated;
    }

    /**
     * @return Collection<int, string>
     */
    private function submittedParameterAxisEvidence(
        string $attribute,
        mixed $submittedValue,
        ?Product $product,
    ): Collection {
        $values = collect([$submittedValue]);

        if ($product instanceof Product) {
            $values = $values->merge($this->productVariantAxisValues($product, $attribute));
            $product->loadMissing('variantChildren');

            foreach ($product->variantChildren as $variant) {
                $values = $values->merge($this->productVariantAxisValues($variant, $attribute));
                $metadataAttribute = trim((string) data_get(
                    $variant->pivot?->metadata,
                    'variant_attribute',
                    '',
                ));

                if ($this->variantAxisNamesMatch($metadataAttribute, $attribute)) {
                    $values->push(data_get($variant->pivot?->metadata, 'variant_option'));
                }
            }
        }

        return app(ProductVariantAxisNameResolver::class)->optionTokens($values);
    }

    /**
     * @return Collection<int, string>
     */
    private function variantAxisEvidence(
        Request $request,
        string $variantAttribute,
        ?Product $product = null,
    ): Collection {
        $values = collect((array) $request->input('new_variant_values', []))
            ->push((string) $request->input('new_variant_values_custom', ''));
        $parameters = (array) $request->input('parameters', []);
        $names = (array) ($parameters['name'] ?? []);
        $parameterValues = (array) ($parameters['value'] ?? []);

        foreach ($names as $index => $name) {
            if ($this->variantAxisNamesMatch((string) $name, $variantAttribute)) {
                $values->push($parameterValues[$index] ?? null);
            }
        }

        if ($product instanceof Product) {
            $values = $values->merge($this->productVariantAxisValues($product, $variantAttribute));
            $product->loadMissing('variantChildren');

            foreach ($product->variantChildren as $variant) {
                $values = $values->merge($this->productVariantAxisValues($variant, $variantAttribute));
                $metadataAttribute = trim((string) data_get($variant->pivot?->metadata, 'variant_attribute', ''));

                if ($this->variantAxisNamesMatch($metadataAttribute, $variantAttribute)) {
                    $values->push(data_get($variant->pivot?->metadata, 'variant_option'));
                }
            }
        }

        return app(ProductVariantAxisNameResolver::class)->optionTokens($values);
    }

    /**
     * @return Collection<int, string>
     */
    private function productVariantAxisValues(Product $product, string $variantAttribute): Collection
    {
        $values = collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && $this->variantAxisNamesMatch(
                    (string) ($parameter['name'] ?? ''),
                    $variantAttribute,
                ))
            ->pluck('value');

        foreach ($product->wooVariationAttributes() as $attribute) {
            if ($this->variantAxisNamesMatch(
                (string) ($attribute['name'] ?? ''),
                $variantAttribute,
            )) {
                $values->push($attribute['option'] ?? null);
            }
        }

        return app(ProductVariantAxisNameResolver::class)->optionTokens($values);
    }

    /**
     * @return Collection<int, string>
     */
    private function knownSizeOptions(): Collection
    {
        $resolver = app(ProductVariantAxisNameResolver::class);

        return ProductParameterDefinition::query()
            ->get(['name', 'name_en', 'slug', 'values', 'values_en'])
            ->filter(fn (ProductParameterDefinition $definition): bool => collect([
                $definition->name,
                $definition->name_en,
                $definition->slug,
            ])->contains(fn (mixed $name): bool => $resolver
                ->isDirectSizeAlias((string) $name)))
            ->flatMap(fn (ProductParameterDefinition $definition): array => [
                ...(array) $definition->values,
                ...(array) $definition->values_en,
            ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values();
    }

    private function variantAxisNamesMatch(string $candidate, string $selected): bool
    {
        if (mb_strtolower(trim($candidate)) === mb_strtolower(trim($selected))) {
            return true;
        }

        $resolver = app(ProductVariantAxisNameResolver::class);

        return $resolver->isDirectSizeAlias($candidate)
            && $resolver->isDirectSizeAlias($selected);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $existing
     * @return list<array<string, mixed>>
     */
    private function normalizeParameters(array $input, array $existing = []): array
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

            $preserved = is_array($existing[$index] ?? null) ? $existing[$index] : [];
            $rows[] = array_merge($preserved, [
                'name' => $name ?? '',
                'value' => $value ?? '',
                'variation' => filter_var($variations[$index] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $sizeRows = collect($rows)
            ->keys()
            ->filter(fn (int $index): bool => mb_strtolower(trim((string) ($rows[$index]['name'] ?? '')))
                === mb_strtolower(ProductVariantAxisNameResolver::SIZE))
            ->values();

        if ($sizeRows->count() > 1) {
            $firstIndex = $sizeRows->first();
            $mergedValues = app(ProductVariantAxisNameResolver::class)->optionTokens(
                $sizeRows->map(fn (int $index): mixed => $rows[$index]['value'] ?? null),
            );
            $rows[$firstIndex]['value'] = $mergedValues->implode(' | ');
            $rows[$firstIndex]['variation'] = $sizeRows->contains(
                fn (int $index): bool => (bool) ($rows[$index]['variation'] ?? false),
            );
            $rows = collect($rows)
                ->reject(fn (array $row, int $index): bool => $index !== $firstIndex
                    && $sizeRows->contains($index))
                ->values()
                ->all();
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
     * @return Collection<int, array{name:string,values:list<string>,is_variant:bool,is_required:bool,input_type:string,aliases:list<string>,canonicalized_aliases:list<string>}>
     */
    private function parameterOptions(?Product $contextProduct = null): Collection
    {
        $defined = ProductParameterDefinition::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductParameterDefinition $definition): array => [
                'name' => $definition->name,
                '_definition_slug' => $definition->slug,
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
                        '_definition_slug' => null,
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
                '_definition_slug' => null,
                'values' => $rows->pluck('value')->filter()->unique()->sort()->values()->all(),
                'is_variant' => $rows->contains(fn (array $row): bool => (bool) $row['variation']),
                'is_required' => false,
                'input_type' => 'text',
            ])
            ->values();

        $options = $defined
            ->concat($discovered)
            ->unique(fn (array $row): string => mb_strtolower($row['name']))
            ->sortBy('name')
            ->values();

        return $this->canonicalizeVariantAxisParameterOptions($options, $contextProduct);
    }

    /**
     * Fold size-only legacy dictionary rows into the canonical ERP option.
     * A context product may declare a legacy generic axis even when the global
     * dictionary also contains non-size BLVariant values; in that case only an
     * alias is added to the canonical size row and the color row is preserved.
     *
     * @param  Collection<int, array<string, mixed>>  $options
     * @return Collection<int, array{name:string,values:list<string>,is_variant:bool,is_required:bool,input_type:string,aliases:list<string>,canonicalized_aliases:list<string>}>
     */
    private function canonicalizeVariantAxisParameterOptions(
        Collection $options,
        ?Product $contextProduct = null,
    ): Collection {
        $resolver = app(ProductVariantAxisNameResolver::class);
        $variantOptions = app(ProductVariantOptionNormalizer::class);
        $knownSizeOptions = $this->knownSizeOptions()
            ->merge($options
                ->filter(fn (array $row): bool => $resolver->isDirectSizeAlias((string) ($row['name'] ?? '')))
                ->flatMap(fn (array $row): array => (array) ($row['values'] ?? [])))
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values();

        $options = $options
            ->map(function (array $row, int $sourceIndex) use ($resolver, $variantOptions, $knownSizeOptions): array {
                $originalName = trim((string) ($row['name'] ?? ''));
                $resolvedName = $resolver->resolve(
                    $originalName,
                    (array) ($row['values'] ?? []),
                    $knownSizeOptions,
                );
                $row['name'] = $resolvedName;
                $row['values'] = collect((array) ($row['values'] ?? []))
                    ->map(fn (mixed $value): string => $variantOptions->normalize($resolvedName, $value))
                    ->filter()
                    ->values()
                    ->all();
                $row['aliases'] = collect((array) ($row['aliases'] ?? []))
                    ->push($originalName)
                    ->filter()
                    ->unique(fn (string $name): string => mb_strtolower($name))
                    ->values()
                    ->all();
                $row['canonicalized_aliases'] = collect((array) ($row['canonicalized_aliases'] ?? []))
                    ->when(
                        mb_strtolower($originalName) !== mb_strtolower($resolvedName),
                        fn (Collection $aliases): Collection => $aliases->push($originalName),
                    )
                    ->filter()
                    ->unique(fn (string $name): string => mb_strtolower($name))
                    ->values()
                    ->all();
                $definitionSlug = Str::slug(trim((string) ($row['_definition_slug'] ?? '')));
                $definitionSlug = str_starts_with($definitionSlug, 'pa-')
                    ? substr($definitionSlug, 3)
                    : $definitionSlug;
                $isExactCanonicalDefinition = mb_strtolower($originalName)
                        === mb_strtolower(ProductVariantAxisNameResolver::SIZE)
                    && $definitionSlug === 'rozmiar';
                $row['_variant_axis_source_priority'] = match (true) {
                    $isExactCanonicalDefinition => 0,
                    mb_strtolower($originalName) === mb_strtolower(ProductVariantAxisNameResolver::SIZE) => 1,
                    $resolver->isDirectSizeAlias($originalName)
                        && ! $resolver->isLegacyPluralSizeAlias($originalName) => 10,
                    $resolver->isLegacyPluralSizeAlias($originalName) => 20,
                    $resolver->isGenericSizeAlias($originalName) => 30,
                    default => 40,
                };
                $row['_variant_axis_source_index'] = $sourceIndex;

                return $row;
            })
            ->groupBy(fn (array $row): string => mb_strtolower((string) $row['name']))
            ->map(function (Collection $rows): array {
                $rows = $rows
                    ->sort(function (array $left, array $right): int {
                        $priority = ((int) ($left['_variant_axis_source_priority'] ?? 40))
                            <=> ((int) ($right['_variant_axis_source_priority'] ?? 40));

                        return $priority !== 0
                            ? $priority
                            : ((int) ($left['_variant_axis_source_index'] ?? 0))
                                <=> ((int) ($right['_variant_axis_source_index'] ?? 0));
                    })
                    ->values();
                $row = $rows->first();
                $row['values'] = $rows
                    ->flatMap(fn (array $candidate): array => (array) ($candidate['values'] ?? []))
                    ->filter()
                    ->unique(fn (string $value): string => mb_strtolower($value))
                    ->values()
                    ->all();
                $row['aliases'] = $rows
                    ->flatMap(fn (array $candidate): array => (array) ($candidate['aliases'] ?? []))
                    ->filter()
                    ->unique(fn (string $name): string => mb_strtolower($name))
                    ->values()
                    ->all();
                $row['canonicalized_aliases'] = $rows
                    ->flatMap(fn (array $candidate): array => (array) ($candidate['canonicalized_aliases'] ?? []))
                    ->filter()
                    ->unique(fn (string $name): string => mb_strtolower($name))
                    ->values()
                    ->all();
                $row['is_variant'] = $rows->contains(
                    fn (array $candidate): bool => (bool) ($candidate['is_variant'] ?? false),
                );
                $row['is_required'] = $rows->contains(
                    fn (array $candidate): bool => (bool) ($candidate['is_required'] ?? false),
                );
                $row['input_type'] = $rows->contains(
                    fn (array $candidate): bool => ($candidate['input_type'] ?? null) === 'select',
                ) ? 'select' : (string) ($row['input_type'] ?? 'text');
                unset(
                    $row['_definition_slug'],
                    $row['_variant_axis_source_priority'],
                    $row['_variant_axis_source_index'],
                );

                return $row;
            })
            ->values();

        if (! $contextProduct instanceof Product) {
            return $options->sortBy('name')->values();
        }

        $sourceAttribute = trim((string) data_get($contextProduct->masterData(), 'variant_attribute', ''));

        if ($sourceAttribute === '') {
            return $options->sortBy('name')->values();
        }

        $contextValues = $this->productVariantAxisValues($contextProduct, $sourceAttribute);
        $contextProduct->loadMissing('variantChildren');

        foreach ($contextProduct->variantChildren as $variant) {
            $contextValues = $contextValues->merge(
                $this->productVariantAxisValues($variant, $sourceAttribute),
            );
        }

        $resolvedAttribute = $resolver->resolve(
            $sourceAttribute,
            $contextValues,
            $knownSizeOptions,
        );

        if (mb_strtolower($resolvedAttribute) === mb_strtolower($sourceAttribute)) {
            return $options->sortBy('name')->values();
        }

        $canonicalIndex = $options->search(
            fn (array $row): bool => mb_strtolower((string) ($row['name'] ?? ''))
                === mb_strtolower($resolvedAttribute),
        );
        $canonicalRow = $canonicalIndex === false ? [
            'name' => $resolvedAttribute,
            'values' => [],
            'is_variant' => true,
            'is_required' => false,
            'input_type' => 'select',
            'aliases' => [$resolvedAttribute],
            'canonicalized_aliases' => [],
        ] : $options->get($canonicalIndex);
        $canonicalRow['values'] = collect((array) ($canonicalRow['values'] ?? []))
            ->merge($contextValues->map(
                fn (string $value): string => $variantOptions->normalize($resolvedAttribute, $value),
            ))
            ->filter()
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all();
        $canonicalRow['aliases'] = collect((array) ($canonicalRow['aliases'] ?? []))
            ->push($sourceAttribute)
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();
        $canonicalRow['canonicalized_aliases'] = collect((array) ($canonicalRow['canonicalized_aliases'] ?? []))
            ->push($sourceAttribute)
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values()
            ->all();

        if ($canonicalIndex === false) {
            $options->push($canonicalRow);
        } else {
            $options->put($canonicalIndex, $canonicalRow);
        }

        return $options->sortBy('name')->values();
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

        $sourceVariantAttribute = $this->nullableString(data_get(
            $attributes,
            'master.variant_attribute',
        ));

        if ($sourceVariantAttribute !== null) {
            $parameters = collect((array) data_get($attributes, 'master.parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter));
            $evidence = $parameters
                ->filter(fn (array $parameter): bool => $this->variantAxisNamesMatch(
                    (string) ($parameter['name'] ?? ''),
                    $sourceVariantAttribute,
                ))
                ->pluck('value');
            $resolvedVariantAttribute = app(ProductVariantAxisNameResolver::class)->resolve(
                $sourceVariantAttribute,
                $evidence,
                $this->knownSizeOptions(),
            );

            if ($resolvedVariantAttribute === ProductVariantAxisNameResolver::SIZE) {
                data_set(
                    $attributes,
                    'master.variant_attribute',
                    ProductVariantAxisNameResolver::SIZE,
                );
                data_set(
                    $attributes,
                    'master.parameters',
                    $this->canonicalizeCopiedSizeParameters(
                        $parameters->values()->all(),
                        $sourceVariantAttribute,
                        $evidence,
                    ),
                );
            }
        }

        foreach (['pl', 'en'] as $language) {
            $name = $this->nullableString(data_get($attributes, "master.content.{$language}.name"));

            if ($name !== null) {
                data_set($attributes, "master.content.{$language}.name", $this->copyName($name));
            }
        }

        if (! is_array(data_get($attributes, 'master.content.en'))) {
            data_set($attributes, 'master.content.en', []);
        }

        data_set($attributes, 'master.content.pl.name', $copyName);
        data_set($attributes, 'master.source', 'erp');
        data_set($attributes, 'master.copy.created_from_product_id', $sourceProductId);
        data_set($attributes, 'master.copy.created_at', now()->toISOString());
        data_set($attributes, 'master.publication_date', now()->format('Y-m-d\TH:i'));
        data_set($attributes, 'master.media', []);
        data_forget($attributes, 'master.inheritance');

        return $attributes;
    }

    /**
     * Fold direct aliases and independently proven generic size rows into one
     * canonical parameter without consuming a generic colour row.
     *
     * @param  list<mixed>  $parameters
     * @param  iterable<mixed>  $selectedEvidence
     * @return list<array<string, mixed>>
     */
    private function canonicalizeCopiedSizeParameters(
        array $parameters,
        ?string $selectedSourceAttribute = null,
        iterable $selectedEvidence = [],
    ): array {
        $resolver = app(ProductVariantAxisNameResolver::class);
        $variantOptions = app(ProductVariantOptionNormalizer::class);
        $knownSizeOptions = $this->knownSizeOptions();
        $selectedEvidence = $resolver->optionTokens($selectedEvidence);
        $rows = collect($parameters)
            ->filter(fn (mixed $parameter): bool => is_array($parameter))
            ->values()
            ->map(function (array $parameter, int $index) use (
                $resolver,
                $variantOptions,
                $knownSizeOptions,
                $selectedSourceAttribute,
                $selectedEvidence,
            ): array {
                $originalName = trim((string) ($parameter['name'] ?? ''));
                $evidence = $resolver->optionTokens([$parameter['value'] ?? null]);

                if ($selectedSourceAttribute !== null
                    && $this->variantAxisNamesMatch($originalName, $selectedSourceAttribute)
                ) {
                    $evidence = $evidence->merge($selectedEvidence);
                }

                $isSize = $resolver->resolve(
                    $originalName,
                    $evidence,
                    $knownSizeOptions,
                ) === ProductVariantAxisNameResolver::SIZE;

                if ($isSize) {
                    $parameter['name'] = ProductVariantAxisNameResolver::SIZE;
                    $parameter['name_en'] = 'Size';
                    $parameter['value'] = $resolver->optionTokens([$parameter['value'] ?? null])
                        ->map(fn (string $value): string => $variantOptions->normalize(
                            ProductVariantAxisNameResolver::SIZE,
                            $value,
                        ))
                        ->filter()
                        ->implode(' | ');
                }

                return [
                    'index' => $index,
                    'row' => $parameter,
                    'is_size' => $isSize,
                    'priority' => match (true) {
                        mb_strtolower($originalName) === mb_strtolower(ProductVariantAxisNameResolver::SIZE) => 0,
                        $resolver->isDirectSizeAlias($originalName)
                            && ! $resolver->isLegacyPluralSizeAlias($originalName) => 10,
                        $resolver->isLegacyPluralSizeAlias($originalName) => 20,
                        $resolver->isGenericSizeAlias($originalName) => 30,
                        default => 40,
                    },
                ];
            });
        $sizeRows = $rows->filter(fn (array $row): bool => $row['is_size'])->values();

        if ($sizeRows->isEmpty()) {
            return $rows->pluck('row')->all();
        }

        $orderedSizeRows = $sizeRows
            ->sort(function (array $left, array $right): int {
                $priority = $left['priority'] <=> $right['priority'];

                return $priority !== 0 ? $priority : $left['index'] <=> $right['index'];
            })
            ->values();
        $canonical = $orderedSizeRows->first()['row'];
        $canonical['name'] = ProductVariantAxisNameResolver::SIZE;
        $canonical['name_en'] = 'Size';
        $canonical['value'] = $resolver->optionTokens(
            $orderedSizeRows->pluck('row.value'),
        )
            ->map(fn (string $value): string => $variantOptions->normalize(
                ProductVariantAxisNameResolver::SIZE,
                $value,
            ))
            ->filter()
            ->unique(fn (string $value): string => $variantOptions->identity(
                ProductVariantAxisNameResolver::SIZE,
                $value,
            ))
            ->implode(' | ');
        $canonical['variation'] = $sizeRows->contains(
            fn (array $row): bool => (bool) ($row['row']['variation'] ?? false),
        );
        $insertAt = (int) $sizeRows->min('index');
        $result = [];

        foreach ($rows as $row) {
            if ($row['index'] === $insertAt) {
                $result[] = $canonical;
            }

            if (! $row['is_size']) {
                $result[] = $row['row'];
            }
        }

        return $result;
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
