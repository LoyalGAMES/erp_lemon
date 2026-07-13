<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCategoryChannelAlias;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\StockBalance;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WarehouseChannelRoute;
use App\Models\WordpressIntegration;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Customers\CustomerAccountClaimService;
use App\Services\Inventory\SalesChannelWarehouseResolver;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderStatusPolicyService;
use App\Services\Orders\OrderWzDocumentService;
use App\Services\Products\ProductCategoryTranslationMergeService;
use App\Services\Products\ProductDescriptionSanitizer;
use App\Services\Products\ProductParameterTranslationService;
use App\Services\Products\ProductTranslationMergeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class WooCommerceImportService
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly SalesChannelWarehouseResolver $warehouseResolver,
        private readonly StockReservationService $reservationService,
        private readonly DocumentAutomationSettingsService $automationSettings,
        private readonly OrderWzDocumentService $wzDocuments,
        private readonly CustomerCommunicationService $communication,
        private readonly OrderStatusPolicyService $statusPolicy,
        private readonly ProductTranslationMergeService $translationMerge,
        private readonly ProductCategoryTranslationMergeService $categoryTranslationMerge,
        private readonly ProductParameterTranslationService $parameterTranslations,
        private readonly ProductDescriptionSanitizer $descriptionSanitizer,
        private readonly WooCommerceCustomerSyncService $customerSync,
        private readonly CustomerAccountClaimService $customerAccountClaims,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function importProducts(WordpressIntegration $integration): array
    {
        $stats = [
            'source_items' => 0,
            'source_products' => 0,
            'source_variations' => 0,
            'source_variable_parents' => 0,
            'source_simple_products' => 0,
            'unique_skus_seen' => 0,
            'synthetic_sku_items' => 0,
            'duplicate_sku_items' => 0,
            'duplicate_sku_groups_count' => 0,
            'duplicate_sku_groups' => [],
            'duplicate_sku_resolved' => 0,
            'duplicate_ean_items' => 0,
            'translation_eans_reclaimed' => 0,
            'translation_products_reclassified' => 0,
            'translation_aliases_mapped' => 0,
            'translation_products_merged' => 0,
            'parameter_definitions_localized' => 0,
            'parameter_definitions_merged' => 0,
            'mapping_overwrites' => 0,
            'created' => 0,
            'updated' => 0,
            'mapped' => 0,
            'stock_updated' => 0,
            'stock_skipped_ambiguous_routes' => 0,
            'stock_skipped_pending_export' => 0,
            'stock_skipped_waiting_reservations' => 0,
            'skipped' => 0,
            'skipped_missing_identifier' => 0,
            'products_total_before' => Product::query()->count(),
            'products_primary_before' => Product::query()->where('is_translation', false)->count(),
            'categories_total_before' => ProductCategory::query()
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->count(),
            'category_aliases_total_before' => ProductCategoryChannelAlias::query()
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->count(),
            'products_total_after' => 0,
            'products_primary_after' => 0,
            'products_historical_aliases_after' => 0,
            'channel_mappings_total_after' => 0,
            'categories_total_after' => 0,
            'category_aliases_total_after' => 0,
        ];
        $seenSourceSkus = [];
        $seenSourceSkuLabels = [];
        $seenSourceIdentities = [];
        $duplicateSkuGroups = [];

        $this->syncProductCategories($integration);

        foreach ($this->client->products($integration) as $item) {
            $stats['source_items']++;
            if (isset($item['variation_id'])) {
                $stats['source_variations']++;
            } else {
                $stats['source_products']++;

                if (($item['type'] ?? null) === 'variable') {
                    $stats['source_variable_parents']++;
                } elseif (($item['type'] ?? null) === 'simple') {
                    $stats['source_simple_products']++;
                }
            }

            $sourceSku = trim((string) ($item['sku'] ?? ''));
            $sku = $this->skuForImport($integration, $item);

            if ($sku === null) {
                $stats['skipped']++;
                $stats['skipped_missing_identifier']++;

                continue;
            }

            if ($sourceSku === '') {
                $stats['synthetic_sku_items']++;
            }

            $entityKind = isset($item['variation_id']) ? 'variation' : 'product';
            $seenSku = $entityKind.':'.Str::lower($sourceSku !== '' ? $sourceSku : $sku);
            $sourceIdentity = $this->wooItemIdentityForDuplicateSku($item);
            $isRepeatedSourceItem = isset($seenSourceIdentities[$seenSku][$sourceIdentity]);
            $isDuplicateSku = isset($seenSourceSkus[$seenSku]) && ! $isRepeatedSourceItem;

            if ($isDuplicateSku) {
                $stats['duplicate_sku_items']++;
            }

            $erpProductId = DB::transaction(function () use ($integration, $item, $sku, &$stats): int {
                $this->syncItemCategories($integration, (array) ($item['categories'] ?? []), $this->normalizeLanguage($item['erp_import_language'] ?? 'pl'));
                $this->syncTranslationCategories($integration, $item);
                $parameterSync = $this->parameterTranslations->syncFromWooItem($item);
                $stats['parameter_definitions_localized'] += $parameterSync['localized'];
                $stats['parameter_definitions_merged'] += $parameterSync['merged'];
                [$product, $resolvedDuplicateSku] = $this->productForWooItem($integration, $item, $sku);
                $isNew = ! $product->exists;

                if (! $isNew) {
                    $product = Product::query()->lockForUpdate()->findOrFail($product->id);
                }

                if ($resolvedDuplicateSku) {
                    $stats['duplicate_sku_resolved']++;
                }

                $attributes = array_replace_recursive(
                    (array) $product->attributes,
                    $this->woocommerceAttributes($item),
                );

                if (! $product->isErpMaster()) {
                    $incomingEan = $this->eanForImport($item);
                    $eanResolution = $this->eanResolutionForImport($integration, $product, $item, $incomingEan);
                    $eanConflict = $eanResolution['conflict'];
                    $masterData = $this->importedMasterData($integration, $item);

                    if ($eanConflict !== null) {
                        $stats['duplicate_ean_items']++;
                        $masterData['ean'] = null;
                        data_set($attributes, 'master.identifier_conflict', $eanConflict);
                    } elseif ($incomingEan !== null) {
                        if ($eanResolution['reclaimed_translation_ean']) {
                            $stats['translation_eans_reclaimed']++;
                        }

                        Arr::forget($attributes, 'master.identifier_conflict');
                    }

                    $mergedMaster = array_replace_recursive(
                        (array) data_get($attributes, 'master', []),
                        $masterData,
                    );
                    $mergedMaster['media'] = $masterData['media'];
                    $attributes['master'] = $mergedMaster;

                    $product->fill([
                        'name' => (string) ($item['name'] ?? $sku),
                        'ean' => $eanConflict === null ? $incomingEan : null,
                        'unit' => 'szt',
                        'vat_rate' => 23,
                        'weight_kg' => $this->nullableFloat($item['weight'] ?? null),
                        'quantity_precision' => 0,
                        'is_active' => true,
                        'is_translation' => false,
                        'attributes' => $attributes,
                    ]);
                } else {
                    $product->fill([
                        'is_translation' => false,
                        'attributes' => $attributes,
                    ]);
                }
                $product->save();

                $incomingExternalProductId = (string) $item['id'];
                $incomingExternalVariationId = isset($item['variation_id']) ? (string) $item['variation_id'] : null;
                $currentMapping = $this->mappingForWooItem($integration, $item)
                    ?? ProductChannelMapping::query()
                        ->where('product_id', $product->id)
                        ->where('sales_channel_id', $integration->sales_channel_id)
                        ->first();

                if ($currentMapping instanceof ProductChannelMapping
                    && (
                        (string) $currentMapping->external_product_id !== $incomingExternalProductId
                        || ($currentMapping->external_variation_id !== null ? (string) $currentMapping->external_variation_id : null) !== $incomingExternalVariationId
                    )
                ) {
                    $stats['mapping_overwrites']++;
                }

                $lockedMapping = ProductChannelMapping::query()
                    ->where('product_id', $product->id)
                    ->where('sales_channel_id', $integration->sales_channel_id)
                    ->lockForUpdate()
                    ->first();

                ProductChannelMapping::query()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'sales_channel_id' => $integration->sales_channel_id,
                    ],
                    [
                        'external_product_id' => $incomingExternalProductId,
                        'external_variation_id' => $incomingExternalVariationId,
                        'external_sku' => trim((string) ($item['sku'] ?? '')) ?: $sku,
                        'stock_sync_enabled' => true,
                        'metadata' => array_merge($lockedMapping?->metadata ?? [], [
                            'source' => 'woocommerce_import',
                            'language' => $this->normalizeLanguage($item['erp_import_language'] ?? 'pl'),
                            'mapping_role' => 'primary',
                        ]),
                    ],
                );

                $this->syncVariationRelation($integration, $product, $item);

                $translationSync = $this->syncTranslationAliases($integration, $product, $item);
                $stats['translation_aliases_mapped'] += $translationSync['aliases'];
                $stats['translation_products_merged'] += $translationSync['merged'];

                $isNew ? $stats['created']++ : $stats['updated']++;
                $stats['mapped']++;

                return (int) $product->id;
            });

            if (array_key_exists('stock_quantity', $item) && $item['stock_quantity'] !== null) {
                $stockSkipReason = $this->syncImportedStock(
                    $integration,
                    Product::query()->findOrFail($erpProductId),
                    (float) $item['stock_quantity'],
                    CarbonImmutable::now('UTC'),
                );

                if ($stockSkipReason === null) {
                    $stats['stock_updated']++;
                } else {
                    $stats[$stockSkipReason]++;
                }
            }

            $diagnosticItem = $this->duplicateSkuDiagnosticItem($item, $erpProductId);
            $seenSourceIdentities[$seenSku][$sourceIdentity] = true;

            if ($isDuplicateSku) {
                if (! isset($duplicateSkuGroups[$seenSku])) {
                    $duplicateSkuGroups[$seenSku] = [
                        'sku' => $seenSourceSkuLabels[$seenSku],
                        'entity_kind' => $entityKind,
                        'occurrences' => 1,
                        'items' => [$seenSourceSkus[$seenSku]],
                    ];
                }

                $duplicateSkuGroups[$seenSku]['items'][] = $diagnosticItem;
                $duplicateSkuGroups[$seenSku]['occurrences'] = count($duplicateSkuGroups[$seenSku]['items']);
            } elseif (! isset($seenSourceSkus[$seenSku])) {
                $seenSourceSkus[$seenSku] = $diagnosticItem;
                $seenSourceSkuLabels[$seenSku] = $sourceSku !== '' ? $sourceSku : $sku;
            }
        }

        $this->syncImportedRelatedProductSkus($integration);

        $stats['unique_skus_seen'] = count($seenSourceSkus);
        $stats['duplicate_sku_groups_count'] = count($duplicateSkuGroups);
        $stats['duplicate_sku_groups'] = array_values($duplicateSkuGroups);
        $stats['products_total_after'] = Product::query()->count();
        $stats['products_primary_after'] = Product::query()->where('is_translation', false)->count();
        $stats['products_historical_aliases_after'] = Product::query()->where('is_translation', true)->count();
        $stats['channel_mappings_total_after'] = ProductChannelMapping::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->count();
        $stats['categories_total_after'] = ProductCategory::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->count();
        $stats['category_aliases_total_after'] = ProductCategoryChannelAlias::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->count();

        return $stats;
    }

    /**
     * Keep diagnostic payload deliberately small and limited to identifiers
     * required to find the conflicting records in ERP and WooCommerce.
     *
     * @param  array<string, mixed>  $item
     * @return array{erp_product_id:int,woo_product_id:string,woo_variation_id:?string,name:string,language:string,permalink:?string}
     */
    private function duplicateSkuDiagnosticItem(array $item, int $erpProductId): array
    {
        $permalink = $this->nullableString($item['permalink'] ?? $item['parent_permalink'] ?? null);

        return [
            'erp_product_id' => $erpProductId,
            'woo_product_id' => trim((string) ($item['id'] ?? '')),
            'woo_variation_id' => isset($item['variation_id'])
                ? trim((string) $item['variation_id'])
                : null,
            'name' => Str::limit(trim((string) ($item['name'] ?? '')), 255, ''),
            'language' => $this->normalizeLanguage($item['erp_import_language'] ?? 'pl'),
            'permalink' => $permalink !== null ? Str::limit($permalink, 1000, '') : null,
        ];
    }

    /**
     * Pagination can move while WooCommerce is being read and return the same
     * record more than once. Such a repeated identity is not a SKU conflict.
     *
     * @param  array<string, mixed>  $item
     */
    private function wooItemIdentityForDuplicateSku(array $item): string
    {
        return trim((string) ($item['id'] ?? '')).'|'.trim((string) ($item['variation_id'] ?? ''));
    }

    private function syncProductCategories(WordpressIntegration $integration): void
    {
        $primaryLanguage = $this->normalizeLanguage($integration->productImportLanguages()[0] ?? 'pl');

        foreach ($this->client->productCategories($integration) as $category) {
            $this->upsertCategory(
                $integration,
                $category,
                $this->normalizeLanguage($category['erp_import_language'] ?? $primaryLanguage),
            );
        }
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     */
    private function syncItemCategories(WordpressIntegration $integration, array $categories, string $language = 'pl'): void
    {
        foreach ($categories as $category) {
            if (is_array($category)) {
                $this->upsertCategory($integration, $category, $language);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function upsertCategory(
        WordpressIntegration $integration,
        array $category,
        string $language = 'pl',
    ): ?ProductCategory {
        $externalId = trim((string) ($category['id'] ?? ''));
        $name = $this->nullableString($category['name'] ?? null);

        if ($externalId === '' || $name === null) {
            return null;
        }

        $language = $this->normalizeLanguage($category['erp_import_language'] ?? $language);
        $translationIds = $this->verifiedCategoryTranslationIds($category);
        $canonicalExternalId = $translationIds['pl'] ?? ($translationIds[$language] ?? $externalId);
        $familyExternalIds = collect([$externalId, ...array_values($translationIds)])
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        return DB::transaction(function () use (
            $integration,
            $category,
            $externalId,
            $name,
            $language,
            $translationIds,
            $canonicalExternalId,
            $familyExternalIds,
        ): ProductCategory {
            $aliases = ProductCategoryChannelAlias::query()
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->whereIn('external_id', $familyExternalIds)
                ->get();
            $candidateIds = $aliases->pluck('product_category_id');
            $candidates = ProductCategory::query()
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->where(function ($query) use ($familyExternalIds, $candidateIds): void {
                    $query->whereIn('external_id', $familyExternalIds);

                    if ($candidateIds->isNotEmpty()) {
                        $query->orWhereIn('id', $candidateIds);
                    }
                })
                ->lockForUpdate()
                ->get();
            $canonicalAliasCategoryId = $aliases
                ->firstWhere('external_id', $canonicalExternalId)
                ?->product_category_id;
            $currentAliasCategoryId = $aliases
                ->firstWhere('external_id', $externalId)
                ?->product_category_id;
            $canonical = $candidates->first(
                fn (ProductCategory $candidate): bool => (string) $candidate->external_id === $canonicalExternalId,
            )
                ?? $candidates->firstWhere('id', $canonicalAliasCategoryId)
                ?? $candidates->first(
                    fn (ProductCategory $candidate): bool => (string) $candidate->external_id === $externalId,
                )
                ?? $candidates->firstWhere('id', $currentAliasCategoryId)
                ?? $candidates->first();

            if (! $canonical instanceof ProductCategory) {
                $canonical = new ProductCategory;
            }

            if ($canonical->exists
                && $translationIds !== []
                && $language === 'pl'
                && (string) $canonical->external_id !== $canonicalExternalId
                && ! ProductCategory::query()
                    ->where('sales_channel_id', $integration->sales_channel_id)
                    ->where('external_id', $canonicalExternalId)
                    ->whereKeyNot($canonical->id)
                    ->exists()
            ) {
                $canonical->external_id = $canonicalExternalId;
            }

            $metadata = array_merge((array) $canonical->metadata, [
                'source' => 'woocommerce',
                'raw' => $category,
                'synced_at' => now()->toISOString(),
            ]);
            data_set($metadata, "raw_by_language.{$language}", $category);
            data_set($metadata, "woocommerce_ids.{$language}", $externalId);

            foreach ($translationIds as $translationLanguage => $translationExternalId) {
                data_set($metadata, "woocommerce_ids.{$translationLanguage}", $translationExternalId);
            }

            foreach ((array) ($category['erp_translations'] ?? []) as $translationLanguage => $translatedCategory) {
                if (! is_array($translatedCategory)) {
                    continue;
                }

                $translationLanguage = $this->normalizeLanguage($translationLanguage);
                data_set($metadata, "translations.{$translationLanguage}", $this->categoryTranslation($translatedCategory));
                data_set($metadata, "raw_by_language.{$translationLanguage}", $translatedCategory);
            }

            if ($language !== 'pl') {
                data_set($metadata, "translations.{$language}", $this->categoryTranslation($category));
            }

            $translationGroup = $this->verifiedCategoryTranslationGroup($category);

            if ($translationGroup !== null) {
                data_set($metadata, 'translation_group', $translationGroup);
                data_set($metadata, 'catalog_contract', 1);
            }

            $canonical->fill([
                'sales_channel_id' => $integration->sales_channel_id,
                'external_id' => $canonical->external_id ?: $canonicalExternalId,
                'metadata' => $metadata,
            ]);

            if (! $canonical->exists || $language === 'pl') {
                $canonical->fill([
                    'parent_external_id' => isset($category['parent']) && (string) $category['parent'] !== '0'
                        ? (string) $category['parent']
                        : null,
                    'name' => $name,
                    'slug' => $this->nullableString($category['slug'] ?? null),
                    'path' => $this->nullableString($category['path'] ?? null) ?? $name,
                    'description' => $this->nullableString($category['description'] ?? null),
                    'count' => (int) ($category['count'] ?? 0),
                    'sort_order' => (int) ($category['menu_order'] ?? 100),
                ]);
            }

            $canonical->save();

            if ($translationIds !== []) {
                foreach ($candidates->where('id', '!=', $canonical->id) as $duplicate) {
                    $canonical = $this->categoryTranslationMerge->merge($canonical, $duplicate);
                }
            }

            $aliasLanguages = $translationIds;
            $aliasLanguages[$language] = $externalId;

            foreach ($aliasLanguages as $aliasLanguage => $aliasExternalId) {
                $existingAlias = ProductCategoryChannelAlias::query()
                    ->where('sales_channel_id', $integration->sales_channel_id)
                    ->where('external_id', $aliasExternalId)
                    ->first();

                ProductCategoryChannelAlias::query()->updateOrCreate(
                    [
                        'sales_channel_id' => $integration->sales_channel_id,
                        'external_id' => $aliasExternalId,
                    ],
                    [
                        'product_category_id' => $canonical->id,
                        'language' => $this->normalizeLanguage($aliasLanguage),
                        'translation_group' => $translationGroup ?? $existingAlias?->translation_group,
                        'metadata' => array_replace_recursive((array) $existingAlias?->metadata, [
                            'source' => 'woocommerce_import',
                            'catalog_contract' => $translationGroup !== null
                                ? 1
                                : data_get($existingAlias?->metadata, 'catalog_contract'),
                        ]),
                    ],
                );
            }

            return $canonical->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncTranslationCategories(WordpressIntegration $integration, array $item): void
    {
        foreach ((array) ($item['erp_translations'] ?? []) as $language => $translatedItem) {
            if (! is_array($translatedItem)) {
                continue;
            }

            $translatedCategories = array_values(array_filter((array) ($translatedItem['categories'] ?? []), 'is_array'));

            foreach ($translatedCategories as $category) {
                $externalId = trim((string) ($category['id'] ?? ''));
                $local = $this->categoryForExternalId($integration, $externalId);

                if (! $local instanceof ProductCategory || $externalId === '') {
                    continue;
                }

                $language = $this->normalizeLanguage($language);
                $metadata = (array) $local->metadata;
                data_set($metadata, 'woocommerce_ids.'.$language, $externalId);
                data_set($metadata, 'translations.'.$language, $this->categoryTranslation($category));
                data_set($metadata, 'raw_by_language.'.$language, $category);
                $local->update(['metadata' => $metadata]);

                ProductCategoryChannelAlias::query()->updateOrCreate(
                    [
                        'sales_channel_id' => $integration->sales_channel_id,
                        'external_id' => $externalId,
                    ],
                    [
                        'product_category_id' => $local->id,
                        'language' => $language,
                        'translation_group' => data_get($metadata, 'translation_group'),
                        'metadata' => ['source' => 'woocommerce_product_translation'],
                    ],
                );
            }
        }
    }

    private function categoryForExternalId(
        WordpressIntegration $integration,
        string $externalId,
    ): ?ProductCategory {
        if ($externalId === '') {
            return null;
        }

        $alias = ProductCategoryChannelAlias::query()
            ->forExternalId((int) $integration->sales_channel_id, $externalId)
            ->first();

        if ($alias instanceof ProductCategoryChannelAlias) {
            return $alias->productCategory()->first();
        }

        return ProductCategory::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->where('external_id', $externalId)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<string, string>
     */
    private function verifiedCategoryTranslationIds(array $category): array
    {
        if ($this->verifiedCategoryTranslationGroup($category) === null) {
            return [];
        }

        $ids = collect((array) ($category['lemon_erp_translations'] ?? []))
            ->mapWithKeys(function (mixed $id, mixed $language): array {
                $id = trim((string) $id);
                $language = $this->normalizeLanguage($language);

                return $id !== '' ? [$language => $id] : [];
            })
            ->all();
        $currentId = trim((string) ($category['id'] ?? ''));

        return $currentId !== '' && in_array($currentId, $ids, true) ? $ids : [];
    }

    /**
     * @param  array<string, mixed>  $category
     */
    private function verifiedCategoryTranslationGroup(array $category): ?string
    {
        if ((int) ($category['lemon_erp_catalog_contract'] ?? 0) !== 1) {
            return null;
        }

        $group = trim((string) ($category['lemon_erp_translation_group'] ?? ''));
        $translations = (array) ($category['lemon_erp_translations'] ?? []);

        return $group !== '' && $translations !== [] ? $group : null;
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array{name:?string,slug:?string,description:?string,path:?string,parent_external_id:?string}
     */
    private function categoryTranslation(array $category): array
    {
        return [
            'name' => $this->nullableString($category['name'] ?? null),
            'slug' => $this->nullableString($category['slug'] ?? null),
            'description' => $this->nullableString($category['description'] ?? null),
            'path' => $this->nullableString($category['path'] ?? null),
            'parent_external_id' => isset($category['parent']) && (string) $category['parent'] !== '0'
                ? (string) $category['parent']
                : null,
        ];
    }

    /**
     * @return array{created:int,updated:int,lines:int,reserved:int,released:int,reservation_skipped:int,pages:int,has_more:bool,next_page:?int}
     */
    public function importOrders(
        WordpressIntegration $integration,
        ?CarbonImmutable $modifiedAfter = null,
        int $firstPage = 1,
    ): array {
        $guestInvitationBaselineAt = $this->guestInvitationBaseline($integration);
        $created = 0;
        $updated = 0;
        $lines = 0;
        $reserved = 0;
        $released = 0;
        $reservationSkipped = 0;
        $pages = 0;
        $pageLimit = $integration->orderImportSettings()['page_limit'];
        $startPage = max(1, $firstPage);
        $lastPage = $startPage + $pageLimit - 1;
        $nextPage = $startPage;

        for ($page = $startPage; $page <= $lastPage; $page++) {
            $items = $this->client->ordersPage($integration, $page, $modifiedAfter);

            if ($items === []) {
                return $this->orderImportStats(
                    $created,
                    $updated,
                    $lines,
                    $reserved,
                    $released,
                    $reservationSkipped,
                    $pages,
                );
            }

            $pages++;

            foreach ($items as $item) {
                $existingOrder = ExternalOrder::query()
                    ->where('sales_channel_id', $integration->sales_channel_id)
                    ->where('external_id', (string) ($item['id'] ?? ''))
                    ->first(['id', 'external_updated_at', 'raw_payload']);
                $item['erp_imported_order_notes'] = $this->orderNotesForImport($integration, $item, $existingOrder);
                $wasCreated = false;
                $previousStatus = null;

                $order = DB::transaction(function () use ($integration, $item, &$created, &$updated, &$lines, &$reserved, &$released, &$reservationSkipped, &$wasCreated, &$previousStatus): ExternalOrder {
                    $order = ExternalOrder::query()->firstOrNew([
                        'sales_channel_id' => $integration->sales_channel_id,
                        'external_id' => (string) $item['id'],
                    ]);

                    if ($order->exists) {
                        $order = ExternalOrder::query()
                            ->lockForUpdate()
                            ->findOrFail($order->id);
                    }

                    $isNew = ! $order->exists;
                    $wasCreated = $isNew;
                    $previousStatus = $order->exists ? (string) $order->status : null;
                    $existingRawPayload = (array) $order->raw_payload;
                    $splitAllocations = $order->exists ? $this->splitAllocationsForOrder($order) : [];
                    $importLines = $this->importableOrderLines($item, $splitAllocations);
                    $rawPayload = $this->rawPayloadForImportedOrder($item, $existingRawPayload, $splitAllocations);

                    $order->fill([
                        'wordpress_integration_id' => $integration->id,
                        'external_number' => (string) ($item['number'] ?? $item['id']),
                        'status' => (string) ($item['status'] ?? 'unknown'),
                        'currency' => (string) ($item['currency'] ?? 'PLN'),
                        'total_gross' => $splitAllocations === []
                            ? (float) ($item['total'] ?? 0)
                            : $this->grossTotalFromImportLines($importLines),
                        'billing_data' => $item['billing'] ?? null,
                        'shipping_data' => $item['shipping'] ?? null,
                        'raw_payload' => $rawPayload,
                        'external_created_at' => $this->wooCommerceDateTime($item, 'date_created'),
                        'external_updated_at' => $this->wooCommerceDateTime($item, 'date_modified'),
                    ]);
                    $order->save();

                    $this->customerSync->syncFromOrder($integration, $order, $item);

                    $order->lines()->delete();

                    foreach ($importLines as $line) {
                        $sku = trim((string) ($line['sku'] ?? ''));
                        $product = $this->productForOrderLine($integration, $line, $sku);
                        $quantity = (float) ($line['quantity'] ?? 0);
                        $sourceQuantity = (float) ($line['sempre_erp_source_quantity'] ?? $quantity);

                        $order->lines()->create([
                            'product_id' => $product?->id,
                            'external_line_id' => isset($line['id']) ? (string) $line['id'] : null,
                            'canonical_external_line_id' => isset($line['id']) ? (string) $line['id'] : null,
                            'sku' => $sku !== '' ? $sku : null,
                            'name' => (string) ($line['name'] ?? 'Pozycja zamówienia'),
                            'quantity' => $quantity,
                            'unit_net_price' => isset($line['subtotal']) && $sourceQuantity > 0
                                ? (float) $line['subtotal'] / $sourceQuantity
                                : null,
                            'unit_gross_price' => isset($line['total']) && $sourceQuantity > 0
                                ? (float) $line['total'] / $sourceQuantity
                                : null,
                            'vat_rate' => null,
                            'raw_payload' => $line,
                        ]);
                        $lines++;
                    }

                    $reservationStats = $this->reservationService->syncForOrder($order);
                    $reserved += $reservationStats['reserved'];
                    $released += $reservationStats['released'];
                    $reservationSkipped += $reservationStats['skipped'];

                    $isNew ? $created++ : $updated++;

                    return $order->fresh();
                });

                $isFulfillmentStatus = $this->statusPolicy->isFulfillmentStatus((string) $order->status);

                if (
                    $isFulfillmentStatus
                    && $this->automationSettings->actionEnabled('order.imported', 'order.wz.create')
                ) {
                    try {
                        $this->wzDocuments->ensureDrafts(
                            $order,
                            'order_import',
                            'Automatyczne WZ po imporcie zamówienia WooCommerce '.$order->external_number,
                        );
                    } catch (Throwable) {
                        // Import zamówienia i rezerwacji jest ważniejszy niż automatyczny szkic WZ.
                    }
                }

                $statusChanged = $wasCreated || mb_strtolower((string) $previousStatus) !== mb_strtolower((string) $order->status);
                $notificationTrigger = $statusChanged
                    ? $this->orderNotificationTrigger((string) $order->status, $wasCreated)
                    : null;

                if ($notificationTrigger !== null) {
                    $this->communication->sendOrderStatus($order, $notificationTrigger);
                }

                $this->sendGuestAccountInvitationForOrder(
                    $integration,
                    $order,
                    $item,
                    $guestInvitationBaselineAt,
                );
            }

            $nextPage = $page + 1;
        }

        $hasMore = $this->client->ordersPage($integration, $nextPage, $modifiedAfter) !== [];

        return $this->orderImportStats(
            $created,
            $updated,
            $lines,
            $reserved,
            $released,
            $reservationSkipped,
            $pages,
            $hasMore,
            $hasMore ? $nextPage : null,
        );
    }

    /**
     * @return array{created:int,updated:int,lines:int,reserved:int,released:int,reservation_skipped:int,pages:int,has_more:bool,next_page:?int}
     */
    private function orderImportStats(
        int $created,
        int $updated,
        int $lines,
        int $reserved,
        int $released,
        int $reservationSkipped,
        int $pages,
        bool $hasMore = false,
        ?int $nextPage = null,
    ): array {
        return [
            'created' => $created,
            'updated' => $updated,
            'lines' => $lines,
            'reserved' => $reserved,
            'released' => $released,
            'reservation_skipped' => $reservationSkipped,
            'pages' => $pages,
            'has_more' => $hasMore,
            'next_page' => $nextPage,
        ];
    }

    private function orderNotificationTrigger(string $status, bool $created): ?string
    {
        return match (mb_strtolower(trim($status))) {
            'processing' => 'order_received',
            'pending', 'on-hold' => $created ? 'order_created' : null,
            'cancelled' => 'order_cancelled',
            'failed' => 'order_payment_failed',
            'refunded' => 'order_refunded',
            default => null,
        };
    }

    /**
     * Konto klienta nie może blokować importu zamówienia. Każda wiadomość jest
     * deduplikowana w CustomerCommunicationService, a claim jest przypisany do
     * dokładnie jednego zamówienia.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendGuestAccountInvitationForOrder(
        WordpressIntegration $integration,
        ExternalOrder $order,
        array $payload,
        CarbonImmutable $baselineAt,
    ): void {
        if ((int) ($payload['customer_id'] ?? 0) > 0) {
            return;
        }

        $orderCreatedAt = $this->orderCreatedAtUtc($order);

        if ($orderCreatedAt === null
            || $orderCreatedAt->lt($baselineAt)
            || $orderCreatedAt->lt(CarbonImmutable::instance(now())->subDays(7)->utc())
            || in_array(mb_strtolower((string) $order->status), ['cancelled', 'refunded'], true)
            || $order->customerMessages()
                ->where('type', CustomerCommunicationService::TYPE_AUTOMATED)
                ->where('trigger', 'guest_account_invitation')
                ->whereIn('status', ['held', 'pending', 'sent', 'failed', 'skipped'])
                ->exists()) {
            return;
        }

        try {
            $customer = $order->customer()->first();

            if ($customer === null) {
                return;
            }

            $claim = $this->customerAccountClaims->createOrRefresh($customer, $order, $integration);
            $this->communication->sendGuestAccountInvitation(
                $customer,
                $order,
                $this->customerAccountClaims->signedUrl($claim),
            );
        } catch (Throwable $exception) {
            // Import, rezerwacje i potwierdzenie zamówienia są ważniejsze niż
            // dodatkowa wiadomość konta. Kolejny import zamówienia ponowi próbę,
            // dopóki nie istnieje deduplikujący wpis CustomerMessage.
            report($exception);
        }
    }

    /**
     * Pierwszy import zamówień ustala granicę historyczną osobno dla każdej
     * integracji. Blokada wiersza chroni pozostałe ustawienia przed utratą przy
     * równoległym imporcie lub zapisie konfiguracji.
     */
    private function guestInvitationBaseline(WordpressIntegration $integration): CarbonImmutable
    {
        return DB::transaction(function () use ($integration): CarbonImmutable {
            $lockedIntegration = WordpressIntegration::query()
                ->lockForUpdate()
                ->findOrFail($integration->getKey());
            $stored = data_get($lockedIntegration->settings, 'customer_import.guest_invitation_baseline_at');

            if (is_string($stored) && trim($stored) !== '') {
                try {
                    return CarbonImmutable::parse($stored);
                } catch (Throwable) {
                    // Niepoprawna wartość jest bezpiecznie zastępowana nową granicą.
                }
            }

            $baselineAt = CarbonImmutable::instance(now())->startOfSecond();
            $settings = (array) $lockedIntegration->settings;
            $customerImport = (array) data_get($settings, 'customer_import', []);
            $customerImport['guest_invitation_baseline_at'] = $baselineAt->toIso8601String();
            $settings['customer_import'] = $customerImport;
            $lockedIntegration->update(['settings' => $settings]);

            return $baselineAt;
        });
    }

    private function orderCreatedAtUtc(ExternalOrder $order): ?CarbonImmutable
    {
        $value = $order->getRawOriginal('external_created_at')
            ?? $order->getRawOriginal('created_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            // Daty WooCommerce są zapisywane w bazie jako UTC. Parsowanie surowej
            // wartości omija domyślną strefę castów Eloquent i daje poprawne
            // porównanie z granicą zapisaną jako ISO 8601.
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<array<string, mixed>>  $splitAllocations
     * @return list<array<string, mixed>>
     */
    private function importableOrderLines(array $item, array $splitAllocations): array
    {
        return collect((array) ($item['line_items'] ?? []))
            ->map(function (array $line) use ($splitAllocations): ?array {
                $sourceQuantity = (float) ($line['quantity'] ?? 0);
                $splitQuantity = $this->splitQuantityForLine($line, $splitAllocations);
                $remainingQuantity = max(0, $sourceQuantity - $splitQuantity);

                if ($remainingQuantity <= 0) {
                    return null;
                }

                $line['quantity'] = $remainingQuantity;
                $line['sempre_erp_source_quantity'] = $sourceQuantity;
                $line['sempre_erp_split_quantity'] = $splitQuantity;

                return $line;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  list<array<string, mixed>>  $splitAllocations
     */
    private function splitQuantityForLine(array $line, array $splitAllocations): float
    {
        $externalLineId = isset($line['id']) ? (string) $line['id'] : '';
        $sku = trim((string) ($line['sku'] ?? ''));

        return collect($splitAllocations)
            ->filter(function (array $allocation) use ($externalLineId, $sku): bool {
                $sourceExternalLineId = trim((string) ($allocation['source_external_line_id'] ?? ''));

                if ($sourceExternalLineId !== '' && $externalLineId !== '') {
                    return $sourceExternalLineId === $externalLineId;
                }

                return $sku !== '' && trim((string) ($allocation['sku'] ?? '')) === $sku;
            })
            ->sum(fn (array $allocation): float => (float) ($allocation['split_quantity'] ?? 0));
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $existingRawPayload
     * @param  list<array<string, mixed>>  $splitAllocations
     * @return array<string, mixed>
     */
    private function rawPayloadForImportedOrder(array $item, array $existingRawPayload, array $splitAllocations): array
    {
        if ($splitAllocations === []) {
            return $item;
        }

        $item['sempre_erp_split_child_orders'] = array_values(array_unique(array_filter([
            ...((array) data_get($existingRawPayload, 'sempre_erp_split_child_orders', [])),
            ...collect($splitAllocations)->pluck('child_external_id')->filter()->values()->all(),
        ])));
        $item['sempre_erp_split_allocations'] = $splitAllocations;
        $item['sempre_erp_split_import_adjusted_at'] = now()->toISOString();

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $importLines
     */
    private function grossTotalFromImportLines(array $importLines): float
    {
        return round(collect($importLines)->sum(function (array $line): float {
            $quantity = (float) ($line['quantity'] ?? 0);
            $sourceQuantity = (float) ($line['sempre_erp_source_quantity'] ?? $quantity);

            if (! isset($line['total']) || $sourceQuantity <= 0) {
                return 0.0;
            }

            return ((float) $line['total'] / $sourceQuantity) * $quantity;
        }), 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function splitAllocationsForOrder(ExternalOrder $order): array
    {
        $childOrders = ExternalOrder::query()
            ->with('lines')
            ->where('sales_channel_id', $order->sales_channel_id)
            ->where('external_id', 'like', $order->external_id.'-SPLIT-%')
            ->get();

        if ($childOrders->isNotEmpty()) {
            return $childOrders
                ->flatMap(function (ExternalOrder $childOrder) use ($order) {
                    return $childOrder->lines->map(function ($line) use ($childOrder, $order): array {
                        $sourceExternalLineId = trim((string) (
                            $line->canonical_external_line_id
                            ?: data_get($line->raw_payload, 'sempre_erp_split.root_external_line_id')
                            ?: data_get($line->raw_payload, 'sempre_erp_split.source_external_line_id', '')
                        ));
                        $externalLineId = (string) ($line->external_line_id ?? '');

                        if ($sourceExternalLineId === '' && $externalLineId !== '') {
                            $sourceExternalLineId = (string) preg_replace('/-S\d+$/', '', $externalLineId);
                        }

                        return [
                            'child_external_id' => $childOrder->external_id,
                            'child_external_number' => $childOrder->external_number,
                            'parent_external_id' => $order->external_id,
                            'source_external_line_id' => $sourceExternalLineId,
                            'sku' => $line->sku,
                            'product_id' => $line->product_id,
                            'split_quantity' => (float) $line->quantity,
                        ];
                    });
                })
                ->values()
                ->all();
        }

        return collect((array) data_get($order->raw_payload, 'sempre_erp_split_allocations', []))
            ->filter(fn (mixed $allocation): bool => is_array($allocation))
            ->values()
            ->all();
    }

    private function syncImportedStock(
        WordpressIntegration $integration,
        Product $product,
        float $quantity,
        CarbonImmutable $observedAt,
    ): ?string {
        $warehouse = $this->stockImportWarehouse($integration);

        if (! $this->stockImportIsUnambiguous($integration, $warehouse)) {
            return 'stock_skipped_ambiguous_routes';
        }

        return DB::transaction(function () use ($integration, $product, $quantity, $warehouse, $observedAt): ?string {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $stockGuardProductIds = ProductRelation::query()
                ->where('child_product_id', $product->id)
                ->where('relation_type', 'variant')
                ->pluck('parent_product_id')
                ->map(fn ($id): int => (int) $id)
                ->push((int) $product->id)
                ->unique()
                ->values();
            $stockGuardMappings = ProductChannelMapping::query()
                ->whereIn('product_id', $stockGuardProductIds->all())
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->lockForUpdate()
                ->get();

            if ($stockGuardMappings->contains(fn (ProductChannelMapping $mapping): bool => filled(
                data_get($mapping->metadata, 'product_data_export.pending_token'),
            ))) {
                return 'stock_skipped_pending_export';
            }

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
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();

            $exportQuery = StockSyncQueueItem::query()
                ->where('product_id', $product->id)
                ->where('sales_channel_id', $integration->sales_channel_id);

            if ((clone $exportQuery)
                ->whereIn('status', ['pending', 'queued', 'running'])
                ->exists()
            ) {
                return 'stock_skipped_pending_export';
            }

            $latestExportStatus = $exportQuery
                ->latest('id')
                ->value('status');

            if ($latestExportStatus === 'failed') {
                return 'stock_skipped_pending_export';
            }

            $remoteAvailable = max(0, $quantity);

            // The Woo value is an availability snapshot, not physical stock.
            // Reservations for orders created before this observation have
            // already been deducted by Woo and must be added back to on_hand.
            $this->reservationService->applySourceAvailabilitySnapshot(
                (int) $warehouse->id,
                (int) $product->id,
                (int) $integration->sales_channel_id,
                $remoteAvailable,
                $observedAt,
            );

            return null;
        }, 3);
    }

    private function stockImportIsUnambiguous(WordpressIntegration $integration, Warehouse $warehouse): bool
    {
        if (! $integration->stock_export_enabled) {
            return true;
        }

        $routes = WarehouseChannelRoute::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->where('push_stock', true)
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true))
            ->get(['warehouse_id', 'stock_buffer']);

        if ($routes->count() !== 1) {
            return false;
        }

        $route = $routes->first();

        return (int) $route->warehouse_id === (int) $warehouse->id
            && abs((float) $route->stock_buffer) < 0.0001;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0:Product,1:bool}
     */
    private function productForWooItem(WordpressIntegration $integration, array $item, string $sku): array
    {
        $externalMapping = $this->mappingForWooItem($integration, $item);

        if ($externalMapping?->product instanceof Product) {
            return [$externalMapping->product, false];
        }

        $externalAlias = $this->aliasForWooItem($integration, $item);

        if ($externalAlias?->product instanceof Product) {
            return [$externalAlias->product, false];
        }

        $product = Product::query()->where('sku', $sku)->first();

        if (! $product instanceof Product
            || ! $this->hasDifferentWooMapping($product, $integration, $item)
            || $this->productIsInWooTranslationFamily($product, $integration, $item)
        ) {
            return [
                $product ?? Product::query()->firstOrNew(['sku' => $sku]),
                false,
            ];
        }

        $syntheticSku = $this->syntheticSkuForWooItem($integration, $item) ?? $sku.'-WC-DUPLICATE';

        return [Product::query()->firstOrNew(['sku' => $syntheticSku]), true];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function hasDifferentWooMapping(Product $product, WordpressIntegration $integration, array $item): bool
    {
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->first();

        if (! $mapping instanceof ProductChannelMapping) {
            return false;
        }

        $externalProductId = (string) ($item['id'] ?? '');
        $externalVariationId = isset($item['variation_id']) ? (string) $item['variation_id'] : null;

        return (string) $mapping->external_product_id !== $externalProductId
            || ($mapping->external_variation_id !== null ? (string) $mapping->external_variation_id : null) !== $externalVariationId;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mappingForWooItem(WordpressIntegration $integration, array $item): ?ProductChannelMapping
    {
        $externalProductId = trim((string) ($item['id'] ?? ''));

        if ($externalProductId === '') {
            return null;
        }

        return ProductChannelMapping::query()
            ->with('product')
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->where('external_product_id', $externalProductId)
            ->when(
                isset($item['variation_id']),
                fn ($query) => $query->where('external_variation_id', (string) $item['variation_id']),
                fn ($query) => $query->whereNull('external_variation_id'),
            )
            ->first();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function aliasForWooItem(WordpressIntegration $integration, array $item): ?ProductChannelAlias
    {
        $externalProductId = trim((string) ($item['id'] ?? ''));

        if ($externalProductId === '') {
            return null;
        }

        return ProductChannelAlias::query()
            ->with('product')
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->where('external_key', ProductChannelAlias::externalKey(
                $externalProductId,
                isset($item['variation_id']) ? (string) $item['variation_id'] : null,
            ))
            ->first();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function productIsInWooTranslationFamily(
        Product $product,
        WordpressIntegration $integration,
        array $item,
    ): bool {
        $externalKeys = collect($this->translationReferences($item))
            ->map(fn (array $reference): string => ProductChannelAlias::externalKey(
                $reference['product_id'],
                $reference['variation_id'],
            ))
            ->push(ProductChannelAlias::externalKey(
                (string) ($item['id'] ?? ''),
                isset($item['variation_id']) ? (string) $item['variation_id'] : null,
            ))
            ->filter()
            ->unique()
            ->values();

        if ($externalKeys->isEmpty()) {
            return false;
        }

        $mapped = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->get(['external_product_id', 'external_variation_id'])
            ->contains(fn (ProductChannelMapping $mapping): bool => $externalKeys->contains(
                ProductChannelAlias::externalKey(
                    (string) $mapping->external_product_id,
                    $mapping->external_variation_id !== null ? (string) $mapping->external_variation_id : null,
                ),
            ));

        return $mapped || ProductChannelAlias::query()
            ->where('product_id', $product->id)
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->whereIn('external_key', $externalKeys->all())
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{aliases:int,merged:int}
     */
    private function syncTranslationAliases(
        WordpressIntegration $integration,
        Product $canonical,
        array $item,
    ): array {
        $aliases = 0;
        $merged = 0;
        $primaryKey = ProductChannelAlias::externalKey(
            (string) ($item['id'] ?? ''),
            isset($item['variation_id']) ? (string) $item['variation_id'] : null,
        );

        foreach ((array) ($item['erp_translations'] ?? []) as $language => $translation) {
            if (! is_array($translation)) {
                continue;
            }

            $externalProductId = trim((string) ($translation['id'] ?? ''));
            $externalVariationId = isset($translation['variation_id'])
                ? trim((string) $translation['variation_id'])
                : null;

            if ($externalProductId === '') {
                continue;
            }

            $externalKey = ProductChannelAlias::externalKey($externalProductId, $externalVariationId);

            if ($externalKey === $primaryKey) {
                continue;
            }

            $legacyMapping = ProductChannelMapping::query()
                ->with('product')
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->where('external_product_id', $externalProductId)
                ->when(
                    filled($externalVariationId),
                    fn ($query) => $query->where('external_variation_id', $externalVariationId),
                    fn ($query) => $query->whereNull('external_variation_id'),
                )
                ->first();
            $sourceProductId = $legacyMapping?->product_id;

            if ($legacyMapping?->product instanceof Product
                && (int) $legacyMapping->product_id !== (int) $canonical->id
            ) {
                $this->translationMerge->merge($canonical, $legacyMapping->product, [
                    'reason' => 'woocommerce_polylang_import',
                    'sales_channel_id' => (int) $integration->sales_channel_id,
                    'language' => $this->normalizeLanguage($language),
                    'external_product_id' => $externalProductId,
                    'external_variation_id' => $externalVariationId,
                ]);
                $merged++;
            }

            $existingAlias = ProductChannelAlias::query()
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->where('external_key', $externalKey)
                ->first();

            ProductChannelAlias::query()->updateOrCreate(
                [
                    'sales_channel_id' => $integration->sales_channel_id,
                    'external_key' => $externalKey,
                ],
                [
                    'product_id' => $canonical->id,
                    'external_product_id' => $externalProductId,
                    'external_variation_id' => filled($externalVariationId) ? $externalVariationId : null,
                    'external_sku' => $this->nullableString($translation['sku'] ?? null),
                    'language' => $this->normalizeLanguage($language),
                    'source_product_id' => $sourceProductId,
                    'metadata' => array_replace_recursive((array) $existingAlias?->metadata, [
                        'source' => 'woocommerce_polylang_import',
                        'translation_group' => $item['lemon_erp_translation_group'] ?? null,
                        'synced_at' => now()->toISOString(),
                    ]),
                ],
            );
            $aliases++;
        }

        return ['aliases' => $aliases, 'merged' => $merged];
    }

    /**
     * Resolve an order line by its immutable Woo identity first. SKU is only
     * a compatibility fallback because Polylang translations may omit or
     * temporarily duplicate it.
     *
     * @param  array<string, mixed>  $line
     */
    private function productForOrderLine(
        WordpressIntegration $integration,
        array $line,
        string $sku,
    ): ?Product {
        $externalProductId = trim((string) ($line['product_id'] ?? ''));
        $externalVariationId = trim((string) ($line['variation_id'] ?? ''));
        $externalVariationId = $externalVariationId !== '' && $externalVariationId !== '0'
            ? $externalVariationId
            : null;

        if ($externalProductId !== '' && $externalProductId !== '0') {
            $mapping = ProductChannelMapping::query()
                ->with('product')
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->where('external_product_id', $externalProductId)
                ->when(
                    $externalVariationId !== null,
                    fn ($query) => $query->where('external_variation_id', $externalVariationId),
                    fn ($query) => $query->whereNull('external_variation_id'),
                )
                ->first();

            if ($mapping?->product instanceof Product) {
                return $mapping->product;
            }

            $alias = ProductChannelAlias::query()
                ->with('product')
                ->where('sales_channel_id', $integration->sales_channel_id)
                ->where('external_key', ProductChannelAlias::externalKey($externalProductId, $externalVariationId))
                ->first();

            if ($alias?->product instanceof Product) {
                return $alias->product;
            }
        }

        if ($sku === '') {
            return null;
        }

        return Product::query()
            ->where('sku', $sku)
            ->orderBy('is_translation')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syncVariationRelation(WordpressIntegration $integration, Product $product, array $item): void
    {
        if (! isset($item['variation_id'], $item['id'])) {
            return;
        }

        $parentMapping = ProductChannelMapping::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->where('external_product_id', (string) $item['id'])
            ->whereNull('external_variation_id')
            ->first();

        if ($parentMapping === null || (int) $parentMapping->product_id === (int) $product->id) {
            return;
        }

        ProductRelation::query()->updateOrCreate(
            [
                'parent_product_id' => $parentMapping->product_id,
                'child_product_id' => $product->id,
                'relation_type' => 'variant',
            ],
            [
                'sort_order' => $this->variationSortOrder($item),
                'metadata' => [
                    'source' => 'woocommerce_import',
                    'sales_channel_id' => $integration->sales_channel_id,
                    'external_product_id' => (string) $item['id'],
                    'external_variation_id' => (string) $item['variation_id'],
                    'synced_at' => now()->toISOString(),
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function variationSortOrder(array $item): int
    {
        if (isset($item['menu_order']) && is_numeric($item['menu_order'])) {
            return max(0, min(65535, (int) $item['menu_order']));
        }

        return 100;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function skuForImport(WordpressIntegration $integration, array $item): ?string
    {
        $sku = trim((string) ($item['sku'] ?? ''));

        if ($sku !== '') {
            return $sku;
        }

        return $this->syntheticSkuForWooItem($integration, $item);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function syntheticSkuForWooItem(WordpressIntegration $integration, array $item): ?string
    {
        $externalId = trim((string) ($item['variation_id'] ?? $item['id'] ?? ''));

        if ($externalId === '') {
            return null;
        }

        $channel = Str::upper(Str::slug((string) ($integration->salesChannel?->code ?? $integration->sales_channel_id), '-'));
        $kind = isset($item['variation_id']) ? 'VARIANT' : 'PARENT';

        return "WC-{$channel}-{$kind}-{$externalId}";
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function woocommerceAttributes(array $item): array
    {
        return [
            'woocommerce_sku' => trim((string) ($item['sku'] ?? '')) ?: null,
            'woocommerce_product_id' => isset($item['id']) ? (string) $item['id'] : null,
            'woocommerce_variation_id' => isset($item['variation_id']) ? (string) $item['variation_id'] : null,
            'woocommerce_type' => $item['type'] ?? null,
            'woocommerce_status' => $item['status'] ?? null,
            'woocommerce_manage_stock' => $item['manage_stock'] ?? null,
            'woocommerce_stock_quantity' => $item['stock_quantity'] ?? null,
            'woocommerce_stock_status' => $item['stock_status'] ?? null,
            'woocommerce_price' => $item['price'] ?? null,
            'woocommerce_regular_price' => $item['regular_price'] ?? null,
            'woocommerce_sale_price' => $item['sale_price'] ?? null,
            'woocommerce_tax_status' => $item['tax_status'] ?? null,
            'woocommerce_tax_class' => $item['tax_class'] ?? null,
            'woocommerce_catalog_visibility' => $item['catalog_visibility'] ?? null,
            'woocommerce_parent_name' => $item['parent_name'] ?? null,
            'woocommerce_variation_name' => $item['variation_name'] ?? null,
            'woocommerce_variation_attributes' => $item['attributes'] ?? null,
            'woocommerce_default_attributes' => $item['default_attributes'] ?? null,
            'woocommerce_global_unique_id' => $item['global_unique_id'] ?? null,
            'woocommerce_ean' => $this->eanForImport($item),
            'woocommerce_permalink' => $item['permalink'] ?? null,
            'woocommerce_parent_permalink' => $item['parent_permalink'] ?? null,
            'woocommerce_categories' => $this->nameList($item['categories'] ?? []),
            'woocommerce_tags' => $this->nameList($item['tags'] ?? []),
            'woocommerce_attributes' => $item['attributes'] ?? null,
            'woocommerce_meta' => $this->metaKeyValue($item['meta_data'] ?? []),
            'woocommerce_upsell_ids' => $item['upsell_ids'] ?? null,
            'woocommerce_cross_sell_ids' => $item['cross_sell_ids'] ?? null,
            'woocommerce_translations' => $this->translationReferences($item),
            'woocommerce_description' => $this->descriptionSanitizer->sanitize(
                $this->nullableString($item['description'] ?? null),
            ),
            'woocommerce_short_description' => $this->descriptionSanitizer->sanitize(
                $this->nullableString($item['short_description'] ?? null),
            ),
            'woocommerce_image' => $this->primaryImage($item),
            'woocommerce_images' => $this->imageList($item['images'] ?? []),
            'woocommerce_parent_image' => $this->cleanImage($item['parent_image'] ?? null),
            'woocommerce_parent_images' => $this->imageList($item['parent_images'] ?? []),
            'woocommerce_raw_payload' => $this->compactRawPayload($item),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, array{product_id:string,variation_id:?string,sku:?string}>
     */
    private function translationReferences(array $item): array
    {
        return collect((array) ($item['erp_translations'] ?? []))
            ->map(function (mixed $translation): ?array {
                if (! is_array($translation)) {
                    return null;
                }

                $productId = trim((string) ($translation['id'] ?? ''));

                if ($productId === '') {
                    return null;
                }

                return [
                    'product_id' => $productId,
                    'variation_id' => isset($translation['variation_id']) ? (string) $translation['variation_id'] : null,
                    'sku' => $this->nullableString($translation['sku'] ?? null),
                ];
            })
            ->filter()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function importedMasterData(WordpressIntegration $integration, array $item): array
    {
        $categories = $this->nameList($item['categories'] ?? []);
        $categoryExternalIds = collect((array) ($item['categories'] ?? []))
            ->filter(fn (mixed $category): bool => is_array($category) && isset($category['id']))
            ->map(fn (array $category): string => (string) $category['id'])
            ->values();
        $categoryIds = $categoryExternalIds->isEmpty()
            ? []
            : $categoryExternalIds
                ->map(fn (string $externalId): ?int => $this->categoryForExternalId($integration, $externalId)?->id)
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        if (isset($item['variation_id'])) {
            $variationImage = $this->cleanImage($item['image'] ?? null);
            $images = $variationImage === null ? [] : [$variationImage];
        } else {
            $images = $this->imageList($item['images'] ?? []);
        }

        if ($images === [] && ! isset($item['variation_id'])) {
            $images = $this->imageList($item['parent_images'] ?? []);
        }

        $content = $this->translatedContent($item);

        return [
            'source' => 'woocommerce_import',
            'catalog' => 'Domyślny',
            'category' => $categories[0] ?? null,
            'categories' => $categories,
            'category_ids' => $categoryIds,
            'producer' => 'SEMPRE',
            'tags' => $this->nameList($item['tags'] ?? []),
            'parameters' => $this->parameterList($item),
            'publication_status' => $this->nullableString($item['status'] ?? null) ?? 'publish',
            'publication_date' => $this->nullableDateTimeString($item['date_created'] ?? null),
            'catalog_visibility' => $this->nullableString($item['catalog_visibility'] ?? null) ?? 'visible',
            'product_type' => $this->nullableString($item['type'] ?? null) ?? 'simple',
            'ean' => $this->eanForImport($item),
            'dimensions' => [
                'height_cm' => $this->nullableFloat(data_get($item, 'dimensions.height')),
                'width_cm' => $this->nullableFloat(data_get($item, 'dimensions.width')),
                'length_cm' => $this->nullableFloat(data_get($item, 'dimensions.length')),
            ],
            'prices' => [
                'retail_price_pln' => $this->nullableFloat($item['regular_price'] ?? $item['price'] ?? null),
                'sale_price_pln' => $this->nullableFloat($item['sale_price'] ?? null),
                'sale_price_starts_at' => $this->nullableDateString($item['date_on_sale_from'] ?? null),
                'sale_price_ends_at' => $this->nullableDateString($item['date_on_sale_to'] ?? null),
            ],
            'stock' => [
                'location' => $this->nullableString($this->metaValue($item, ['_warehouse_location', 'warehouse_location', 'location'])),
            ],
            'inventory' => [
                'manage_stock' => (bool) ($item['manage_stock'] ?? false),
                'backorders' => $this->nullableString($item['backorders'] ?? null) ?? 'no',
                'low_stock_amount' => $this->nullableFloat($item['low_stock_amount'] ?? null),
                'sold_individually' => (bool) ($item['sold_individually'] ?? false),
            ],
            'custom_label' => $this->customLabels($item),
            'related_products' => [
                'upsell_ids' => array_values((array) ($item['upsell_ids'] ?? [])),
                'cross_sell_ids' => array_values((array) ($item['cross_sell_ids'] ?? [])),
            ],
            'content' => $content,
            'media' => $images,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{pl:?string,en:?string,bg_color:?string,text_color:?string}
     */
    private function customLabels(array $item): array
    {
        $labels = ['pl' => null, 'en' => null];
        $primaryLanguage = $this->normalizeLanguage($item['erp_import_language'] ?? 'pl');
        $labels[$primaryLanguage] = $this->nullableString($this->metaValue($item, ['_lemon_product_label_text']));

        foreach ((array) ($item['erp_translations'] ?? []) as $language => $translation) {
            if (is_array($translation)) {
                $labels[$this->normalizeLanguage($language)] = $this->nullableString(
                    $this->metaValue($translation, ['_lemon_product_label_text'])
                );
            }
        }

        return [
            'pl' => $labels['pl'] ?? null,
            'en' => $labels['en'] ?? null,
            'bg_color' => $this->nullableString($this->metaValue($item, ['_lemon_product_label_bg_color'])),
            'text_color' => $this->nullableString($this->metaValue($item, ['_lemon_product_label_text_color'])),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, array{name:string,description:?string,additional_description:?string}>
     */
    private function translatedContent(array $item): array
    {
        $primaryLanguage = $this->normalizeLanguage($item['erp_import_language'] ?? 'pl');
        $content = [
            $primaryLanguage => $this->contentForItem($item),
        ];

        foreach ((array) ($item['erp_translations'] ?? []) as $language => $translatedItem) {
            if (! is_array($translatedItem)) {
                continue;
            }

            $content[$this->normalizeLanguage($language)] = $this->contentForItem($translatedItem);
        }

        if (! isset($content['pl'])) {
            $content['pl'] = $content[$primaryLanguage];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{name:string,description:?string,additional_description:?string}
     */
    private function contentForItem(array $item): array
    {
        return [
            'name' => (string) ($item['name'] ?? ''),
            'description' => $this->descriptionSanitizer->sanitize(
                $this->nullableString($item['description'] ?? null),
            ),
            'additional_description' => $this->descriptionSanitizer->sanitize(
                $this->nullableString($item['short_description'] ?? null),
            ),
        ];
    }

    private function normalizeLanguage(mixed $language): string
    {
        $language = mb_strtolower(trim((string) $language));

        return match ($language) {
            'default', '' => 'pl',
            default => $language,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array{name:string,value:string,variation:bool}>
     */
    private function parameterList(array $item): array
    {
        return collect((array) ($item['attributes'] ?? []))
            ->filter(fn ($attribute): bool => is_array($attribute))
            ->map(function (array $attribute): ?array {
                $name = $this->nullableString($attribute['name'] ?? null);

                if ($name === null) {
                    return null;
                }

                $value = $attribute['option'] ?? null;

                if ($value === null && isset($attribute['options']) && is_array($attribute['options'])) {
                    $value = implode(', ', array_filter(array_map('strval', $attribute['options'])));
                }

                return [
                    'name' => $name,
                    'value' => trim((string) ($value ?? '')),
                    'variation' => (bool) ($attribute['variation'] ?? isset($attribute['option'])),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function syncImportedRelatedProductSkus(WordpressIntegration $integration): void
    {
        $mappings = ProductChannelMapping::query()
            ->with('product')
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->whereNull('external_variation_id')
            ->get();
        $skuByExternalId = $mappings
            ->filter(fn (ProductChannelMapping $mapping): bool => $mapping->product instanceof Product)
            ->mapWithKeys(fn (ProductChannelMapping $mapping): array => [
                (string) $mapping->external_product_id => $mapping->product->sku,
            ]);

        foreach ($mappings as $mapping) {
            $product = $mapping->product;

            if (! $product instanceof Product || $product->isErpMaster()) {
                continue;
            }

            $attributes = (array) $product->attributes;
            $master = (array) data_get($attributes, 'master', []);

            foreach (['upsell', 'cross_sell'] as $type) {
                $ids = (array) data_get($master, "related_products.{$type}_ids", []);
                data_set($master, "related_products.{$type}_skus", collect($ids)
                    ->map(fn (mixed $id): ?string => $skuByExternalId->get((string) $id))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all());
            }

            data_set($attributes, 'master', $master);
            $product->forceFill(['attributes' => $attributes])->save();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function safeOrderNotes(WordpressIntegration $integration, string $orderId): array
    {
        try {
            return $this->client->orderNotes($integration, $orderId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<int, array<string, mixed>>
     */
    private function orderNotesForImport(
        WordpressIntegration $integration,
        array $item,
        ?ExternalOrder $existingOrder,
    ): array {
        $existingNotes = (array) data_get($existingOrder?->raw_payload, 'erp_imported_order_notes', []);
        $incomingUpdatedAt = $this->wooCommerceDateTime($item, 'date_modified');
        $storedUpdatedAt = $existingOrder?->external_updated_at?->utc()->format('Y-m-d H:i:s');

        if ($incomingUpdatedAt !== null && $incomingUpdatedAt === $storedUpdatedAt) {
            return $existingNotes;
        }

        return $this->safeOrderNotes($integration, (string) ($item['id'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function primaryImage(array $item): ?array
    {
        return $this->cleanImage($item['image'] ?? null)
            ?? $this->cleanImage(data_get($item, 'images.0'))
            ?? $this->cleanImage($item['parent_image'] ?? null)
            ?? $this->cleanImage(data_get($item, 'parent_images.0'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function imageList(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        return collect($images)
            ->map(fn ($image): ?array => $this->cleanImage($image))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cleanImage(mixed $image): ?array
    {
        if (! is_array($image)) {
            return null;
        }

        $src = trim((string) ($image['src'] ?? $image['url'] ?? ''));

        if ($src === '') {
            return null;
        }

        return [
            'id' => isset($image['id']) ? (string) $image['id'] : null,
            'src' => $src,
            'name' => isset($image['name']) ? (string) $image['name'] : null,
            'alt' => isset($image['alt']) ? (string) $image['alt'] : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function nameList(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        return collect($items)
            ->map(fn ($item): string => is_array($item) ? trim((string) ($item['name'] ?? '')) : trim((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    private function eanForImport(array $item): ?string
    {
        foreach ([
            $item['global_unique_id'] ?? null,
            $this->metaValue($item, ['_ean', 'ean', '_alg_ean', '_wpm_gtin_code', '_global_unique_id', '_wc_gpf_gtin']),
        ] as $value) {
            $value = preg_replace('/\s+/', '', trim((string) ($value ?? '')));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Assign an EAN held by a local translation back to its primary product.
     * Other collisions remain marked for manual review. The unique database
     * index remains the source of truth.
     *
     * @return array{conflict:array<string, int|string>|null,reclaimed_translation_ean:bool}
     */
    private function eanResolutionForImport(
        WordpressIntegration $integration,
        Product $product,
        array $item,
        ?string $ean,
    ): array {
        if ($ean === null) {
            return [
                'conflict' => null,
                'reclaimed_translation_ean' => false,
            ];
        }

        $existingProduct = Product::query()
            ->where('ean', $ean)
            ->when(
                $product->exists,
                fn ($query) => $query->where('id', '<>', $product->id),
            )
            ->lockForUpdate()
            ->first(['id', 'sku', 'attributes', 'is_translation']);

        if (! $existingProduct instanceof Product) {
            return [
                'conflict' => null,
                'reclaimed_translation_ean' => false,
            ];
        }

        if ($existingProduct->is_translation
            && $this->isTranslationOfImportedProduct($integration, $product, $item, $existingProduct)) {
            $attributes = (array) $existingProduct->attributes;
            data_set($attributes, 'master.ean', null);
            data_set($attributes, 'master.identifier_conflict', [
                'type' => 'translation_ean_reassigned',
                'previous_ean' => $ean,
                'detected_at' => now()->toISOString(),
                'resolution' => 'assigned_to_primary_product',
            ]);

            $existingProduct->forceFill([
                'ean' => null,
                'attributes' => $attributes,
            ])->save();

            return [
                'conflict' => null,
                'reclaimed_translation_ean' => true,
            ];
        }

        return [
            'conflict' => [
                'type' => 'duplicated_ean',
                'previous_ean' => $ean,
                'conflicting_product_id' => $existingProduct->id,
                'conflicting_product_sku' => $existingProduct->sku,
                'detected_at' => now()->toISOString(),
                'resolution' => 'cleared_for_manual_review',
            ],
            'reclaimed_translation_ean' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isTranslationOfImportedProduct(
        WordpressIntegration $integration,
        Product $product,
        array $item,
        Product $translation,
    ): bool {
        $references = collect([
            ...array_values((array) data_get($product->attributes, 'woocommerce_translations', [])),
            ...array_values($this->translationReferences($item)),
        ])
            ->filter(fn (mixed $reference): bool => is_array($reference))
            ->map(fn (array $reference): array => [
                'product_id' => trim((string) ($reference['product_id'] ?? '')),
                'variation_id' => isset($reference['variation_id']) ? (string) $reference['variation_id'] : null,
            ])
            ->filter(fn (array $reference): bool => $reference['product_id'] !== '')
            ->unique(fn (array $reference): string => $reference['product_id'].'|'.($reference['variation_id'] ?? ''));

        if ($references->isEmpty()) {
            return false;
        }

        $translationMappings = ProductChannelMapping::query()
            ->where('product_id', $translation->id)
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->get(['external_product_id', 'external_variation_id']);

        return $translationMappings->contains(function (ProductChannelMapping $mapping) use ($references): bool {
            $externalProductId = (string) $mapping->external_product_id;
            $externalVariationId = $mapping->external_variation_id !== null
                ? (string) $mapping->external_variation_id
                : null;

            return $references->contains(
                fn (array $reference): bool => $reference['product_id'] === $externalProductId
                    && $reference['variation_id'] === $externalVariationId,
            );
        });
    }

    /**
     * Mark legacy ERP rows that are mapped to a known Polylang translation.
     * Their mappings remain intact so historical stock and order references
     * are preserved, while the product catalogue presents one primary item.
     *
     * @param  array<string, mixed>  $item
     */
    private function markExistingTranslationProducts(
        WordpressIntegration $integration,
        Product $product,
        array $item,
    ): int {
        $references = collect($this->translationReferences($item))
            ->filter(fn (array $reference): bool => $reference['product_id'] !== '')
            ->unique(fn (array $reference): string => $reference['product_id'].'|'.($reference['variation_id'] ?? ''))
            ->values();

        if ($references->isEmpty()) {
            return 0;
        }

        $translationProductIds = $references
            ->flatMap(function (array $reference) use ($integration): array {
                return ProductChannelMapping::query()
                    ->where('sales_channel_id', $integration->sales_channel_id)
                    ->where('external_product_id', $reference['product_id'])
                    ->when(
                        $reference['variation_id'] !== null,
                        fn ($query) => $query->where('external_variation_id', $reference['variation_id']),
                        fn ($query) => $query->whereNull('external_variation_id'),
                    )
                    ->pluck('product_id')
                    ->all();
            })
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id !== (int) $product->id)
            ->unique()
            ->values();

        if ($translationProductIds->isEmpty()) {
            return 0;
        }

        return Product::query()
            ->whereIn('id', $translationProductIds->all())
            ->where('is_translation', false)
            ->update(['is_translation' => true]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $keys
     */
    private function metaValue(array $item, array $keys): mixed
    {
        $meta = $this->metaKeyValue($item['meta_data'] ?? []);

        foreach ($keys as $key) {
            if (array_key_exists($key, $meta) && filled($meta[$key])) {
                return $meta[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function metaKeyValue(mixed $metaData): array
    {
        if (! is_array($metaData)) {
            return [];
        }

        $meta = [];

        foreach ($metaData as $row) {
            if (! is_array($row) || ! isset($row['key'])) {
                continue;
            }

            $meta[(string) $row['key']] = $row['value'] ?? null;
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function compactRawPayload(array $item): array
    {
        return collect($item)
            ->except(['erp_translations'])
            ->all();
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

        return $value === null ? null : mb_substr(str_replace(' ', 'T', $value), 0, 16);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function wooCommerceDateTime(array $item, string $localKey): ?string
    {
        $gmtValue = $this->nullableString($item[$localKey.'_gmt'] ?? null);

        if ($gmtValue !== null) {
            return $this->parseDateTimeForDatabase($gmtValue, 'UTC');
        }

        $localValue = $this->nullableString($item[$localKey] ?? null);

        if ($localValue === null) {
            return null;
        }

        return $this->parseDateTimeForDatabase($localValue, 'Europe/Warsaw');
    }

    private function parseDateTimeForDatabase(string $value, string $timezone): ?string
    {
        try {
            return CarbonImmutable::parse($value, $timezone)
                ->utc()
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function stockImportWarehouse(WordpressIntegration $integration): Warehouse
    {
        return $this->warehouseResolver->resolve($integration->sales_channel_id);
    }
}
