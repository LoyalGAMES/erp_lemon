<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\LegacyVariantFamilyBackfillService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('product_channel_mappings')) {
            return;
        }

        $backfill = app(LegacyVariantFamilyBackfillService::class);
        $markedProductIds = [];

        ProductChannelMapping::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with(['product.parentRelations'])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($backfill, &$markedProductIds): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($markedProductIds[$product->id])
                        || ! $this->isCanonicalErpRoot($product)
                    ) {
                        continue;
                    }

                    $master = $product->masterData();
                    $polishText = trim((string) data_get($master, 'shipping.text', ''));
                    $englishText = trim((string) data_get($master, 'shipping.text_en', ''));

                    if ($polishText === '' && $englishText === '') {
                        continue;
                    }

                    if ($englishText === '') {
                        $translatedText = $this->defaultEnglishShippingText($polishText);

                        if ($translatedText !== null) {
                            $attributes = (array) $product->attributes;
                            data_set($attributes, 'master.shipping.text_en', $translatedText);
                            $product->forceFill(['attributes' => $attributes])->save();
                        }
                    }

                    $backfill->markPendingRevision(
                        $product,
                        LegacyVariantFamilyBackfillService::STOREFRONT_TRANSLATIONS_SYNC_REVISION,
                    );
                    $markedProductIds[$product->id] = true;
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op: completed remote metadata updates are not reversible.
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

    private function defaultEnglishShippingText(string $polishText): ?string
    {
        if (preg_match('/^Planowana wysyłka\s*:/iu', $polishText) === 1) {
            return (string) preg_replace(
                '/^Planowana wysyłka\s*:/iu',
                'Planned shipping:',
                $polishText,
            );
        }

        if ($polishText === 'Wysyłka za {days} dni: {date}') {
            return 'Shipping in {days} days: {date}';
        }

        return null;
    }
};
