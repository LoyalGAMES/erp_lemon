<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\WordpressIntegration;
use App\Services\Products\ProductVariantOptionNormalizer;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('wordpress_integrations')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $integrations = WordpressIntegration::query()
            ->with('salesChannel')
            ->get()
            ->groupBy('sales_channel_id')
            ->filter(fn ($channelIntegrations): bool => $channelIntegrations->count() === 1)
            ->map(fn ($channelIntegrations): WordpressIntegration => $channelIntegrations->first())
            ->filter(fn (WordpressIntegration $integration): bool => $integration->salesChannel?->is_active
                && $integration->salesChannel?->type === 'woocommerce'
                && $this->hasTranslatedProductExport($integration))
            ->keyBy('sales_channel_id');

        if ($integrations->isEmpty()) {
            return;
        }

        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $variantOptions = app(ProductVariantOptionNormalizer::class);
        $sizeOrders = Schema::hasTable('product_parameter_definitions')
            ? $this->normalizeSizeDictionaries($variantOptions)
            : [];
        $markedProductIds = [];

        ProductChannelMapping::query()
            ->whereIn('sales_channel_id', $integrations->keys())
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with(['product.channelMappings', 'product.parentRelations', 'product.variantChildren'])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use (
                $backfill,
                $sizeOrders,
                $variantOptions,
                &$markedProductIds,
            ): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($markedProductIds[$product->id])
                        || ! $this->isCanonicalErpRoot($product)
                        || $product->channelMappings->pluck('sales_channel_id')->unique()->count() !== 1
                        || ! $this->isPrimaryPolishMapping($mapping)
                    ) {
                        continue;
                    }

                    if (Schema::hasTable('product_relations')) {
                        $this->normalizeVariantRelationOrder($product, $sizeOrders, $variantOptions);
                    }
                    $backfill->markPendingRevision(
                        $product,
                        LegacyVariantFamilyBackfillService::PUBLICATION_DATE_AND_ATTRIBUTE_ORDER_REVISION,
                    );
                    $markedProductIds[$product->id] = true;
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op: completed remote exports and their corrected dates
        // and storefront ordering must not be undone on application rollback.
    }

    private function hasTranslatedProductExport(WordpressIntegration $integration): bool
    {
        return collect($integration->productExportLanguages())
            ->contains(fn (string $language): bool => $language !== 'pl');
    }

    /**
     * Repair the imported size dictionary once, while keeping PL/EN values
     * aligned. Future operator changes remain authoritative because the
     * exporter reads the stored row order directly.
     *
     * @return array<string, int>
     */
    private function normalizeSizeDictionaries(ProductVariantOptionNormalizer $variantOptions): array
    {
        $orders = [];

        foreach (ProductParameterDefinition::query()->orderBy('id')->get() as $definition) {
            if (! $definition->is_variant || ! collect([
                $definition->name,
                $definition->name_en,
                $definition->slug,
            ])->filter()->contains(
                fn (mixed $name): bool => $variantOptions->isSizeAttribute((string) $name),
            )) {
                continue;
            }

            $valuesEn = array_values((array) $definition->values_en);
            $pairs = collect(array_values((array) $definition->values))
                ->map(fn (mixed $value, int $index): array => [
                    'value' => trim((string) $value),
                    'value_en' => trim((string) ($valuesEn[$index] ?? '')),
                    'index' => $index,
                    'rank' => $this->canonicalSizeRank((string) $value),
                ])
                ->filter(fn (array $pair): bool => $pair['value'] !== '')
                ->sort(function (array $left, array $right): int {
                    if ($left['rank'] === null && $right['rank'] === null) {
                        return $left['index'] <=> $right['index'];
                    }

                    if ($left['rank'] === null) {
                        return 1;
                    }

                    if ($right['rank'] === null) {
                        return -1;
                    }

                    return $left['rank'] <=> $right['rank'] ?: $left['index'] <=> $right['index'];
                })
                ->values();
            $values = $pairs->pluck('value')->all();
            $alignedValuesEn = $pairs->pluck('value_en')->all();
            $targetValuesEn = collect($alignedValuesEn)->contains(fn (string $value): bool => $value !== '')
                ? $alignedValuesEn
                : null;

            if ($values !== array_values((array) $definition->values)
                || (array) $targetValuesEn !== $valuesEn
            ) {
                $definition->forceFill([
                    'values' => $values,
                    'values_en' => $targetValuesEn,
                    'metadata' => array_replace_recursive((array) $definition->metadata, [
                        'storefront_value_order' => [
                            'normalized_by' => LegacyVariantFamilyBackfillService::PUBLICATION_DATE_AND_ATTRIBUTE_ORDER_REVISION,
                            'normalized_at' => now()->toISOString(),
                        ],
                    ]),
                ])->save();
            }

            foreach ($values as $index => $value) {
                $orders[$variantOptions->identity((string) $definition->name, $value)] = ($index + 1) * 10;
            }
        }

        return $orders;
    }

    /** @param array<string, int> $sizeOrders */
    private function normalizeVariantRelationOrder(
        Product $product,
        array $sizeOrders,
        ProductVariantOptionNormalizer $variantOptions,
    ): void {
        $variantAttribute = trim((string) data_get($product->masterData(), 'variant_attribute', ''));

        if ($product->variantChildren->count() < 2
            || ! $variantOptions->isSizeAttribute($variantAttribute)
        ) {
            return;
        }

        $rows = $product->variantChildren
            ->map(function (Product $variant) use ($sizeOrders, $variantAttribute, $variantOptions): array {
                $sizeParameters = collect((array) data_get($variant->masterData(), 'parameters', []))
                    ->filter(fn (mixed $candidate): bool => is_array($candidate)
                        && $variantOptions->isSizeAttribute((string) ($candidate['name'] ?? ''))
                        && trim((string) ($candidate['value'] ?? '')) !== '')
                    ->values();
                $parameter = $sizeParameters->first(
                    fn (array $candidate): bool => (bool) ($candidate['variation'] ?? false),
                );

                // Old size families did not always persist the variation flag.
                // A single unambiguous size parameter is still safe because the
                // parent explicitly declares size as its variant attribute.
                if (! is_array($parameter) && $sizeParameters->count() === 1) {
                    $parameter = $sizeParameters->first();
                }

                $value = is_array($parameter) ? trim((string) ($parameter['value'] ?? '')) : '';
                $identity = $value !== ''
                    ? $variantOptions->identity($variantAttribute, $value)
                    : '';

                return [
                    'variant' => $variant,
                    'rank' => $identity !== ''
                        ? ($sizeOrders[$identity] ?? $this->canonicalSizeRank($value))
                        : null,
                    'existing_order' => (int) ($variant->pivot?->sort_order ?? 0),
                ];
            })
            ->sort(function (array $left, array $right): int {
                if ($left['rank'] === null && $right['rank'] === null) {
                    return $left['existing_order'] <=> $right['existing_order']
                        ?: $left['variant']->id <=> $right['variant']->id;
                }

                if ($left['rank'] === null) {
                    return 1;
                }

                if ($right['rank'] === null) {
                    return -1;
                }

                return $left['rank'] <=> $right['rank']
                    ?: $left['existing_order'] <=> $right['existing_order']
                    ?: $left['variant']->id <=> $right['variant']->id;
            })
            ->values();

        foreach ($rows as $index => $row) {
            $targetOrder = ($index + 1) * 10;

            ProductRelation::query()
                ->whereKey($row['variant']->pivot?->id)
                ->where(function ($query) use ($targetOrder): void {
                    $query
                        ->whereNull('sort_order')
                        ->orWhere('sort_order', '!=', $targetOrder);
                })
                ->update(['sort_order' => $targetOrder]);
        }
    }

    private function canonicalSizeRank(string $value): ?int
    {
        $value = mb_strtoupper(trim((string) preg_replace('/\s+/u', '', $value)));
        $value = str_replace(['–', '—', '-'], '/', $value);
        $aliases = [
            'ONESIZE' => 0,
            'ONE/SIZE' => 0,
            'UNIWERSALNY' => 0,
            'XXXXS' => 100,
            'XXXS' => 200,
            'XXS' => 300,
            'XXS/XS' => 350,
            'XS' => 400,
            'XS/S' => 450,
            'S' => 500,
            'S/M' => 550,
            'M' => 600,
            'M/L' => 650,
            'L' => 700,
            'L/XL' => 750,
            'XL' => 800,
            'XL/XXL' => 850,
            'XXL' => 900,
            '2XL' => 900,
            'XXXL' => 1000,
            '3XL' => 1000,
            '4XL' => 1100,
            '5XL' => 1200,
            '6XL' => 1300,
        ];

        if (array_key_exists($value, $aliases)) {
            return $aliases[$value];
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)(?:\/(\d+(?:[.,]\d+)?))?$/', $value, $matches) === 1) {
            $from = (float) str_replace(',', '.', $matches[1]);
            $to = isset($matches[2]) ? (float) str_replace(',', '.', $matches[2]) : $from;

            return 10_000 + (int) round($from * 100) + (int) round($to);
        }

        return null;
    }

    private function isCanonicalErpRoot(Product $product): bool
    {
        return ! $product->is_translation
            && trim((string) $product->sku) !== ''
            && $product->masterSource() === 'erp'
            && data_get($product->masterData(), 'product_type') !== 'variation'
            && ! $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            );
    }

    private function isPrimaryPolishMapping(ProductChannelMapping $mapping): bool
    {
        $metadata = (array) $mapping->metadata;
        $language = mb_strtolower(trim((string) ($metadata['language'] ?? '')));
        $role = mb_strtolower(trim((string) ($metadata['mapping_role'] ?? '')));
        $isPolish = $language === ''
            || $language === 'pl'
            || str_starts_with($language, 'pl-')
            || str_starts_with($language, 'pl_');

        return ! (bool) ($metadata['is_translation'] ?? false)
            && $isPolish
            && in_array($role, ['', 'primary'], true)
            && ctype_digit(trim((string) $mapping->external_product_id))
            && (int) $mapping->external_product_id > 0;
    }
};
