<?php

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use App\Services\Products\ProductVariantInheritanceService;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_channel_aliases')
            || ! Schema::hasTable('wordpress_integrations')
        ) {
            return;
        }

        $integrations = WordpressIntegration::query()
            ->get()
            ->groupBy('sales_channel_id')
            ->filter(fn ($channelIntegrations): bool => $channelIntegrations->count() === 1)
            ->map(fn ($channelIntegrations): WordpressIntegration => $channelIntegrations->first())
            ->filter(fn (WordpressIntegration $integration): bool => $this->translatedLanguages($integration) !== [])
            ->keyBy('sales_channel_id');

        if ($integrations->isEmpty()) {
            return;
        }

        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $inheritance = app(ProductVariantInheritanceService::class);
        $markedProductIds = [];

        ProductChannelMapping::query()
            ->whereIn('sales_channel_id', $integrations->keys())
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with(['product.channelMappings', 'product.parentRelations', 'salesChannel'])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use (
                $integrations,
                $backfill,
                $inheritance,
                &$markedProductIds,
            ): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($markedProductIds[$product->id])
                        || $product->is_translation
                        || trim((string) $product->sku) === ''
                        || $product->masterSource() !== 'erp'
                        || data_get($product->masterData(), 'product_type') === 'variation'
                        || $product->parentRelations->contains(
                            fn ($relation): bool => $relation->relation_type === 'variant',
                        )
                        || $product->channelMappings->pluck('sales_channel_id')->unique()->count() !== 1
                        || ! $mapping->salesChannel?->is_active
                        || $mapping->salesChannel?->type !== 'woocommerce'
                        || ! $this->isPrimaryPolishMapping($mapping)
                    ) {
                        continue;
                    }

                    $integration = $integrations->get((int) $mapping->sales_channel_id);

                    if (! $integration instanceof WordpressIntegration
                        || ! $this->hasMissingExportableTranslation(
                            $product,
                            $mapping,
                            $integration,
                            $inheritance,
                        )
                    ) {
                        continue;
                    }

                    $backfill->markPendingRevision(
                        $product,
                        LegacyVariantFamilyBackfillService::MISSING_PRODUCT_TRANSLATIONS_REVISION,
                    );
                    $markedProductIds[$product->id] = true;
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op: queued or completed external translations must not
        // be detached when application code is rolled back.
    }

    /**
     * @return list<string>
     */
    private function translatedLanguages(WordpressIntegration $integration): array
    {
        return collect($integration->productExportLanguages())
            ->map(fn (mixed $language): string => mb_strtolower(trim((string) $language)))
            ->filter(fn (string $language): bool => $language !== '' && $language !== 'pl')
            ->unique()
            ->values()
            ->all();
    }

    private function hasMissingExportableTranslation(
        Product $product,
        ProductChannelMapping $mapping,
        WordpressIntegration $integration,
        ProductVariantInheritanceService $inheritance,
    ): bool {
        $master = $product->masterData();

        foreach ($this->translatedLanguages($integration) as $language) {
            $hasExportableContent = is_array(data_get($master, "content.{$language}"))
                || ($language === 'en' && $inheritance->isCopiedFamily($product));

            if (! $hasExportableContent) {
                continue;
            }

            if ($this->hasPendingTranslationRepair($mapping, $language)
                || ! $this->hasTranslationReference($product, $mapping, $language)
            ) {
                return true;
            }
        }

        return false;
    }

    private function hasTranslationReference(
        Product $product,
        ProductChannelMapping $mapping,
        string $language,
    ): bool {
        $primaryExternalId = trim((string) $mapping->external_product_id);
        $aliases = ProductChannelAlias::query()
            ->where('product_id', $product->id)
            ->where('sales_channel_id', $mapping->sales_channel_id)
            ->whereRaw('LOWER(language) = ?', [$language])
            ->orderBy('id')
            ->get();

        if ($aliases->isNotEmpty()) {
            // A scoped reference means Woo already allocated the translation.
            // Ambiguous or malformed aliases are intentionally not recreated;
            // only an explicit pending creation/link state may resume them.
            return true;
        }

        // Once scoped aliases exist, unscoped legacy IDs must not leak from a
        // different shop into this channel.
        if (ProductChannelAlias::query()
            ->where('product_id', $product->id)
            ->whereNotNull('language')
            ->exists()
        ) {
            return false;
        }

        $mappedChannelIds = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->distinct()
            ->pluck('sales_channel_id');

        if ($mappedChannelIds->count() !== 1
            || (int) $mappedChannelIds->first() !== (int) $mapping->sales_channel_id
        ) {
            return false;
        }

        $externalProductId = trim((string) data_get(
            $product->attributes,
            "woocommerce_translations.{$language}.product_id",
            '',
        ));

        return $externalProductId !== '' && $externalProductId !== $primaryExternalId;
    }

    private function hasPendingTranslationRepair(
        ProductChannelMapping $mapping,
        string $language,
    ): bool {
        return data_get($mapping->metadata, 'creation_state') === 'creating'
            || data_get($mapping->metadata, 'product_translation_link.pending') === true
            || data_get(
                $mapping->metadata,
                "product_translation_creation.{$language}.pending",
            ) === true;
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
