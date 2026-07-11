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
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Inventory\SalesChannelWarehouseResolver;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderStatusPolicyService;
use App\Services\Orders\OrderWzDocumentService;
use Carbon\CarbonImmutable;
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
    ) {}

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
            'duplicate_sku_resolved' => 0,
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
        $seenSourceSkus = [];

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

            $seenSku = $sourceSku !== '' ? $sourceSku : $sku;

            if (isset($seenSourceSkus[$seenSku])) {
                $stats['duplicate_sku_items']++;
            } else {
                $seenSourceSkus[$seenSku] = true;
            }

            DB::transaction(function () use ($integration, $item, $sku, &$stats): void {
                [$product, $resolvedDuplicateSku] = $this->productForWooItem($integration, $item, $sku);
                $isNew = ! $product->exists;

                if ($resolvedDuplicateSku) {
                    $stats['duplicate_sku_resolved']++;
                }

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
                        'metadata' => array_merge($currentMapping?->metadata ?? [], [
                            'source' => 'woocommerce_import',
                        ]),
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

        $stats['unique_skus_seen'] = count($seenSourceSkus);
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
     * @param  list<array<string, mixed>>  $categories
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
     * @param  array<string, mixed>  $category
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
                'sort_order' => (int) ($category['menu_order'] ?? 100),
                'metadata' => [
                    'source' => 'woocommerce',
                    'raw' => $category,
                    'synced_at' => now()->toISOString(),
                ],
            ],
        );
    }

    /**
     * @return array{created:int,updated:int,lines:int,reserved:int,released:int,reservation_skipped:int,pages:int,has_more:bool,next_page:?int}
     */
    public function importOrders(
        WordpressIntegration $integration,
        ?CarbonImmutable $modifiedAfter = null,
        int $firstPage = 1,
    ): array {
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
                    $isNew = ! $order->exists;
                    $wasCreated = $isNew;
                    $previousStatus = $order->exists ? (string) $order->status : null;
                    $existingRawPayload = (array) $order->raw_payload;
                    $splitAllocations = $order->exists ? $this->splitAllocationsForOrder($order) : [];
                    $importLines = $this->importableOrderLines($item, $splitAllocations);
                    $rawPayload = $this->rawPayloadForImportedOrder($item, $existingRawPayload, $splitAllocations);

                    $order->fill([
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

                    $order->lines()->delete();

                    foreach ($importLines as $line) {
                        $sku = trim((string) ($line['sku'] ?? ''));
                        $product = $sku !== '' ? Product::query()->where('sku', $sku)->first() : null;
                        $quantity = (float) ($line['quantity'] ?? 0);
                        $sourceQuantity = (float) ($line['sempre_erp_source_quantity'] ?? $quantity);

                        $order->lines()->create([
                            'product_id' => $product?->id,
                            'external_line_id' => isset($line['id']) ? (string) $line['id'] : null,
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

                if ($wasCreated && $isFulfillmentStatus) {
                    $this->communication->sendOrderStatus($order, 'order_received');
                } elseif ($wasCreated && $this->shouldNotifyOrderCreated((string) $order->status)) {
                    $this->communication->sendOrderStatus($order, 'order_created');
                } elseif (
                    ! $wasCreated
                    && ! $this->statusPolicy->isFulfillmentStatus($previousStatus)
                    && $isFulfillmentStatus
                ) {
                    $this->communication->sendOrderStatus($order, 'order_received');
                }
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

    private function shouldNotifyOrderCreated(string $status): bool
    {
        return in_array(mb_strtolower(trim($status)), ['pending', 'on-hold'], true);
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
                        $sourceExternalLineId = trim((string) data_get($line->raw_payload, 'sempre_erp_split.source_external_line_id', ''));
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
     * @param  array<string, mixed>  $item
     * @return array{0:Product,1:bool}
     */
    private function productForWooItem(WordpressIntegration $integration, array $item, string $sku): array
    {
        $externalMapping = $this->mappingForWooItem($integration, $item);

        if ($externalMapping?->product instanceof Product) {
            return [$externalMapping->product, false];
        }

        $product = Product::query()->where('sku', $sku)->first();

        if (! $product instanceof Product || ! $this->hasDifferentWooMapping($product, $integration, $item)) {
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
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function importedMasterData(array $item): array
    {
        $categories = $this->nameList($item['categories'] ?? []);
        $images = $this->imageList($item['images'] ?? []);

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
