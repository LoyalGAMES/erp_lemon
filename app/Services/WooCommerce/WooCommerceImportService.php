<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\ExternalOrder;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Models\WordpressIntegration;
use App\Services\Automation\DocumentAutomationSettingsService;
use App\Services\Inventory\SalesChannelWarehouseResolver;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderWzDocumentService;
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
    ) {
    }

    /**
     * @return array<string, int>
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
            'mapping_overwrites' => 0,
            'created' => 0,
            'updated' => 0,
            'mapped' => 0,
            'stock_updated' => 0,
            'skipped' => 0,
            'skipped_missing_identifier' => 0,
            'products_total_before' => Product::query()->count(),
            'products_total_after' => 0,
            'channel_mappings_total_after' => 0,
        ];
        $seenSkus = [];

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

            $sku = $this->skuForImport($integration, $item);

            if ($sku === null) {
                $stats['skipped']++;
                $stats['skipped_missing_identifier']++;
                continue;
            }

            if (trim((string) ($item['sku'] ?? '')) === '') {
                $stats['synthetic_sku_items']++;
            }

            if (isset($seenSkus[$sku])) {
                $stats['duplicate_sku_items']++;
            } else {
                $seenSkus[$sku] = true;
            }

            DB::transaction(function () use ($integration, $item, $sku, &$stats): void {
                $product = Product::query()->firstOrNew(['sku' => $sku]);
                $isNew = ! $product->exists;
                $attributes = array_replace_recursive(
                    (array) $product->attributes,
                    $this->woocommerceAttributes($item),
                );

                if (! $product->isErpMaster()) {
                    $attributes['master'] = array_replace_recursive(
                        (array) data_get($attributes, 'master', []),
                        $this->importedMasterData($item),
                    );

                    $product->fill([
                        'name' => (string) ($item['name'] ?? $sku),
                        'ean' => $this->eanForImport($item),
                        'unit' => 'szt',
                        'vat_rate' => 23,
                        'weight_kg' => $this->nullableFloat($item['weight'] ?? null),
                        'quantity_precision' => 0,
                        'is_active' => true,
                        'attributes' => $attributes,
                    ]);
                } else {
                    $product->fill([
                        'attributes' => $attributes,
                    ]);
                }
                $product->save();

                $incomingExternalProductId = (string) $item['id'];
                $incomingExternalVariationId = isset($item['variation_id']) ? (string) $item['variation_id'] : null;
                $currentMapping = ProductChannelMapping::query()
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
                        'metadata' => ['source' => 'woocommerce_import'],
                    ],
                );

                $this->syncVariationRelation($integration, $product, $item);

                if (array_key_exists('stock_quantity', $item) && $item['stock_quantity'] !== null) {
                    $this->syncImportedStock($integration, $product, (float) $item['stock_quantity']);
                    $stats['stock_updated']++;
                }

                $this->syncItemCategories($integration, (array) ($item['categories'] ?? []));

                $isNew ? $stats['created']++ : $stats['updated']++;
                $stats['mapped']++;
            });
        }

        $stats['unique_skus_seen'] = count($seenSkus);
        $stats['products_total_after'] = Product::query()->count();
        $stats['channel_mappings_total_after'] = ProductChannelMapping::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->count();

        return $stats;
    }

    private function syncProductCategories(WordpressIntegration $integration): void
    {
        try {
            foreach ($this->client->productCategories($integration) as $category) {
                $this->upsertCategory($integration, $category);
            }
        } catch (Throwable) {
            // Product import remains useful even when a store blocks category reads.
        }
    }

    /**
     * @param list<array<string, mixed>> $categories
     */
    private function syncItemCategories(WordpressIntegration $integration, array $categories): void
    {
        foreach ($categories as $category) {
            if (is_array($category)) {
                $this->upsertCategory($integration, $category);
            }
        }
    }

    /**
     * @param array<string, mixed> $category
     */
    private function upsertCategory(WordpressIntegration $integration, array $category): void
    {
        $externalId = trim((string) ($category['id'] ?? ''));
        $name = $this->nullableString($category['name'] ?? null);

        if ($externalId === '' || $name === null) {
            return;
        }

        ProductCategory::query()->updateOrCreate(
            [
                'sales_channel_id' => $integration->sales_channel_id,
                'external_id' => $externalId,
            ],
            [
                'parent_external_id' => isset($category['parent']) && (string) $category['parent'] !== '0'
                    ? (string) $category['parent']
                    : null,
                'name' => $name,
                'slug' => $this->nullableString($category['slug'] ?? null),
                'path' => $this->nullableString($category['path'] ?? null) ?? $name,
                'description' => $this->nullableString($category['description'] ?? null),
                'count' => (int) ($category['count'] ?? 0),
                'metadata' => [
                    'source' => 'woocommerce',
                    'raw' => $category,
                    'synced_at' => now()->toISOString(),
                ],
            ],
        );
    }

    /**
     * @return array{created:int,updated:int,lines:int,reserved:int,released:int,reservation_skipped:int}
     */
    public function importOrders(WordpressIntegration $integration): array
    {
        $created = 0;
        $updated = 0;
        $lines = 0;
        $reserved = 0;
        $released = 0;
        $reservationSkipped = 0;

        foreach ($this->client->orders($integration) as $item) {
            $item['erp_imported_order_notes'] = $this->safeOrderNotes($integration, (string) $item['id']);

            $order = DB::transaction(function () use ($integration, $item, &$created, &$updated, &$lines, &$reserved, &$released, &$reservationSkipped): ExternalOrder {
                $order = ExternalOrder::query()->firstOrNew([
                    'sales_channel_id' => $integration->sales_channel_id,
                    'external_id' => (string) $item['id'],
                ]);
                $isNew = ! $order->exists;

                $order->fill([
                    'external_number' => (string) ($item['number'] ?? $item['id']),
                    'status' => (string) ($item['status'] ?? 'unknown'),
                    'currency' => (string) ($item['currency'] ?? 'PLN'),
                    'total_gross' => (float) ($item['total'] ?? 0),
                    'billing_data' => $item['billing'] ?? null,
                    'shipping_data' => $item['shipping'] ?? null,
                    'raw_payload' => $item,
                    'external_created_at' => $item['date_created'] ?? null,
                    'external_updated_at' => $item['date_modified'] ?? null,
                ]);
                $order->save();

                $order->lines()->delete();

                foreach (($item['line_items'] ?? []) as $line) {
                    $sku = trim((string) ($line['sku'] ?? ''));
                    $product = $sku !== '' ? Product::query()->where('sku', $sku)->first() : null;
                    $quantity = (float) ($line['quantity'] ?? 0);

                    $order->lines()->create([
                        'product_id' => $product?->id,
                        'external_line_id' => isset($line['id']) ? (string) $line['id'] : null,
                        'sku' => $sku !== '' ? $sku : null,
                        'name' => (string) ($line['name'] ?? 'Pozycja zamówienia'),
                        'quantity' => $quantity,
                        'unit_net_price' => isset($line['subtotal']) && $quantity > 0
                            ? (float) $line['subtotal'] / $quantity
                            : null,
                        'unit_gross_price' => isset($line['total']) && $quantity > 0
                            ? (float) $line['total'] / $quantity
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

            if ($this->automationSettings->actionEnabled('order.imported', 'order.wz.create')) {
                try {
                    $this->wzDocuments->ensureDrafts(
                        $order,
                        'order_import',
                        'Automatyczne WZ po imporcie zamówienia WooCommerce ' . $order->external_number,
                    );
                } catch (Throwable) {
                    // Import zamówienia i rezerwacji jest ważniejszy niż automatyczny szkic WZ.
                }
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'lines' => $lines,
            'reserved' => $reserved,
            'released' => $released,
            'reservation_skipped' => $reservationSkipped,
        ];
    }

    private function syncImportedStock(WordpressIntegration $integration, Product $product, float $quantity): void
    {
        $warehouse = $this->stockImportWarehouse($integration);
        $balance = StockBalance::query()->firstOrNew([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
        ]);

        $reserved = (float) ($balance->quantity_reserved ?? 0);
        $onHand = max(0, $quantity);

        $balance->fill([
            'quantity_on_hand' => $onHand,
            'quantity_reserved' => $reserved,
            'quantity_available' => max(0, $onHand - $reserved),
            'recalculated_at' => now(),
        ]);
        $balance->save();
    }

    /**
     * @param array<string, mixed> $item
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
     * @param array<string, mixed> $item
     */
    private function variationSortOrder(array $item): int
    {
        if (isset($item['menu_order']) && is_numeric($item['menu_order'])) {
            return max(0, min(65535, (int) $item['menu_order']));
        }

        return 100;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function skuForImport(WordpressIntegration $integration, array $item): ?string
    {
        $sku = trim((string) ($item['sku'] ?? ''));

        if ($sku !== '') {
            return $sku;
        }

        $externalId = trim((string) ($item['variation_id'] ?? $item['id'] ?? ''));

        if ($externalId === '') {
            return null;
        }

        $channel = Str::upper(Str::slug((string) ($integration->salesChannel?->code ?? $integration->sales_channel_id), '-'));
        $kind = isset($item['variation_id']) ? 'VARIANT' : 'PARENT';

        return "WC-{$channel}-{$kind}-{$externalId}";
    }

    /**
     * @param array<string, mixed> $item
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
            'woocommerce_description' => $this->nullableString($item['description'] ?? null),
            'woocommerce_short_description' => $this->nullableString($item['short_description'] ?? null),
            'woocommerce_image' => $this->primaryImage($item),
            'woocommerce_images' => $this->imageList($item['images'] ?? []),
            'woocommerce_parent_image' => $this->cleanImage($item['parent_image'] ?? null),
            'woocommerce_parent_images' => $this->imageList($item['parent_images'] ?? []),
            'woocommerce_raw_payload' => $this->compactRawPayload($item),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function importedMasterData(array $item): array
    {
        $categories = $this->nameList($item['categories'] ?? []);
        $images = $this->imageList($item['images'] ?? []);
        $stockQuantity = $this->nullableFloat($item['stock_quantity'] ?? null);

        if ($images === []) {
            $images = $this->imageList($item['parent_images'] ?? []);
        }

        $content = $this->translatedContent($item);

        return [
            'source' => 'woocommerce_import',
            'catalog' => 'Domyślny',
            'category' => $categories[0] ?? null,
            'producer' => 'SEMPRE',
            'tags' => $this->nameList($item['tags'] ?? []),
            'parameters' => $this->parameterList($item),
            'publication_status' => $this->nullableString($item['status'] ?? null) ?? 'publish',
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
            ],
            'stock' => [
                'quantity' => $stockQuantity,
                'ordered_quantity' => 0,
                'threshold' => 0,
                'location' => $this->nullableString($this->metaValue($item, ['_warehouse_location', 'warehouse_location', 'location'])),
            ],
            'related_products' => [
                'upsell_ids' => array_values((array) ($item['upsell_ids'] ?? [])),
                'cross_sell_ids' => array_values((array) ($item['cross_sell_ids'] ?? [])),
            ],
            'content' => $content,
            'media' => $images,
        ];
    }

    /**
     * @param array<string, mixed> $item
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
     * @param array<string, mixed> $item
     * @return array{name:string,description:?string,additional_description:?string}
     */
    private function contentForItem(array $item): array
    {
        return [
            'name' => (string) ($item['name'] ?? ''),
            'description' => $this->nullableString($item['description'] ?? null),
            'additional_description' => $this->nullableString($item['short_description'] ?? null),
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
     * @param array<string, mixed> $item
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
     * @param array<string, mixed> $item
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
     * @param mixed $images
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
     * @param mixed $image
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
     * @param mixed $items
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
     * @param array<string, mixed> $item
     * @param list<string> $keys
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
     * @param mixed $metaData
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
     * @param array<string, mixed> $item
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

    private function stockImportWarehouse(WordpressIntegration $integration): Warehouse
    {
        return $this->warehouseResolver->resolve($integration->sales_channel_id);
    }
}
