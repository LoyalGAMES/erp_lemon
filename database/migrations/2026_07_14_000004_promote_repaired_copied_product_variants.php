<?php

use App\Models\Product;
use App\Models\ProductRelation;
use App\Services\Products\ProductVariantInheritanceService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $inheritance = app(ProductVariantInheritanceService::class);

        // Relation repair runs immediately before this migration. Revisit the
        // family after that repair so an old duplicated child whose Woo mapping
        // restored the missing relation also receives its parent binding and
        // effective master data in the same deployment.
        ProductRelation::query()
            ->with(['parentProduct', 'childProduct'])
            ->where('relation_type', 'variant')
            ->orderBy('id')
            ->chunkById(100, function ($relations) use ($inheritance): void {
                foreach ($relations as $relation) {
                    $parent = $relation->parentProduct;
                    $variant = $relation->childProduct;

                    if (! $parent instanceof Product
                        || ! $variant instanceof Product
                        || $inheritance->inheritedParentId($variant) !== null
                        || ! $inheritance->inheritsFromParent($variant, $parent)
                    ) {
                        continue;
                    }

                    $parentAttributes = (array) $parent->attributes;
                    $parentMaster = $parent->masterData();
                    $parentChanged = false;

                    if (! is_array(data_get($parentMaster, 'content.en'))) {
                        data_set($parentMaster, 'content.en', []);
                        $parentChanged = true;
                    }

                    if (! filled(data_get($parentMaster, 'publication_date'))
                        && $parent->is_active
                        && (string) data_get($parentMaster, 'publication_status', 'publish') === 'publish'
                    ) {
                        data_set($parentMaster, 'publication_date', now()->format('Y-m-d\TH:i'));
                        $parentChanged = true;
                    }

                    if ($parentChanged) {
                        $parentAttributes['master'] = $parentMaster;
                        $parent->forceFill(['attributes' => $parentAttributes])->save();
                    }

                    $inheritance->synchronizeVariant($parent, $variant);
                }
            });
    }

    public function down(): void
    {
        // This data repair deliberately keeps the promoted inheritance marker.
    }
};
