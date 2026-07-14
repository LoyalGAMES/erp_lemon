<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHUNK_SIZE = 250;

    public function up(): void
    {
        if (! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('products')
        ) {
            return;
        }

        $canRepairTranslationFlag = Schema::hasColumn('products', 'is_translation');

        DB::table('product_channel_mappings')
            ->select([
                'id',
                'product_id',
                'sales_channel_id',
                'external_product_id',
                'external_variation_id',
                'metadata',
            ])
            ->whereNotNull('external_variation_id')
            ->where('external_variation_id', '!=', '')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($variationMappings) use ($canRepairTranslationFlag): void {
                foreach ($variationMappings as $variationMapping) {
                    $externalProductId = trim((string) $variationMapping->external_product_id);
                    $externalVariationId = trim((string) $variationMapping->external_variation_id);

                    if ($externalProductId === '' || $externalVariationId === '') {
                        continue;
                    }

                    $parentMappings = DB::table('product_channel_mappings')
                        ->select(['id', 'product_id', 'metadata'])
                        ->where('sales_channel_id', $variationMapping->sales_channel_id)
                        ->where('external_product_id', $externalProductId)
                        ->where('product_id', '!=', $variationMapping->product_id)
                        ->where(function ($query): void {
                            $query
                                ->whereNull('external_variation_id')
                                ->orWhere('external_variation_id', '')
                                ->orWhereRaw("TRIM(external_variation_id) = ''");
                        })
                        ->limit(2)
                        ->get();

                    if ($parentMappings->count() !== 1) {
                        continue;
                    }

                    $parentMapping = $parentMappings->first();
                    $parentProductId = (int) $parentMapping->product_id;
                    $childProductId = (int) $variationMapping->product_id;
                    $relationIdentity = [
                        'parent_product_id' => $parentProductId,
                        'child_product_id' => $childProductId,
                        'relation_type' => 'variant',
                    ];
                    $existingParentIds = DB::table('product_relations')
                        ->where('child_product_id', $childProductId)
                        ->where('relation_type', 'variant')
                        ->pluck('parent_product_id')
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique();

                    if ($existingParentIds->isNotEmpty() && ! $existingParentIds->contains($parentProductId)) {
                        continue;
                    }

                    $relationExists = DB::table('product_relations')
                        ->where($relationIdentity)
                        ->exists();

                    if (! $relationExists) {
                        $childAttributes = $this->decodeJson(
                            DB::table('products')->where('id', $childProductId)->value('attributes'),
                        );
                        $now = now();

                        DB::table('product_relations')->updateOrInsert(
                            $relationIdentity,
                            [
                                'sort_order' => $this->variationSortOrder($childAttributes),
                                'metadata' => json_encode([
                                    'source' => 'woocommerce_mapping_relation_repair',
                                    'sales_channel_id' => (int) $variationMapping->sales_channel_id,
                                    'external_product_id' => $externalProductId,
                                    'external_variation_id' => $externalVariationId,
                                    'parent_mapping_id' => (int) $parentMapping->id,
                                    'child_mapping_id' => (int) $variationMapping->id,
                                    'parent_product_id' => $parentProductId,
                                    'child_product_id' => $childProductId,
                                    'repaired_at' => $now->toISOString(),
                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                                'created_at' => $now,
                                'updated_at' => $now,
                            ],
                        );
                    }

                    if ($canRepairTranslationFlag
                        && $this->isPrimaryPolishMapping($this->decodeJson($variationMapping->metadata))
                    ) {
                        DB::table('products')
                            ->where('id', $childProductId)
                            ->update(['is_translation' => false]);
                    }

                    if ($canRepairTranslationFlag
                        && $this->isPrimaryPolishMapping($this->decodeJson($parentMapping->metadata))
                    ) {
                        DB::table('products')
                            ->where('id', $parentProductId)
                            ->update(['is_translation' => false]);
                    }
                }
            }, 'id');
    }

    public function down(): void
    {
        // Deliberate no-op: inferred relations may already be used by stock and order data.
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function variationSortOrder(array $attributes): int
    {
        $menuOrder = data_get($attributes, 'woocommerce_raw_payload.menu_order')
            ?? data_get($attributes, 'master.woocommerce_raw_payload.menu_order');

        if (! is_numeric($menuOrder)) {
            return 100;
        }

        return max(0, min(65535, (int) $menuOrder));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function isPrimaryPolishMapping(array $metadata): bool
    {
        $language = mb_strtolower(trim((string) ($metadata['language'] ?? '')));
        $mappingRole = mb_strtolower(trim((string) ($metadata['mapping_role'] ?? '')));
        $isExplicitEnglishAlias = $language === 'en'
            || str_starts_with($language, 'en-')
            || str_starts_with($language, 'en_');

        if ($isExplicitEnglishAlias) {
            return false;
        }

        return $mappingRole === 'primary' || $language === '' || $language === 'pl';
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
