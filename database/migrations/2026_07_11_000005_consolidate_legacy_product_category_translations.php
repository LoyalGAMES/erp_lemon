<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_categories')) {
            return;
        }

        $categories = DB::table('product_categories')
            ->select(['id', 'sales_channel_id', 'external_id', 'metadata'])
            ->orderBy('id')
            ->get();
        $primaryByEnglishExternalId = [];

        foreach ($categories as $category) {
            $metadata = json_decode((string) ($category->metadata ?? '{}'), true);
            $englishExternalId = trim((string) (is_array($metadata) ? ($metadata['woocommerce_ids']['en'] ?? '') : ''));

            if ($englishExternalId === '' || $englishExternalId === (string) $category->external_id) {
                continue;
            }

            $primaryByEnglishExternalId[$this->key($category->sales_channel_id, $englishExternalId)] = (int) $category->id;
        }

        foreach ($categories as $category) {
            $primaryCategoryId = $primaryByEnglishExternalId[$this->key($category->sales_channel_id, (string) $category->external_id)] ?? null;

            if ($primaryCategoryId === null || $primaryCategoryId === (int) $category->id) {
                continue;
            }

            $metadata = json_decode((string) ($category->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $woocommerceIds = (array) ($metadata['woocommerce_ids'] ?? []);
            unset($woocommerceIds['pl']);
            $woocommerceIds['en'] ??= (string) $category->external_id;
            $metadata['woocommerce_ids'] = $woocommerceIds;
            $metadata['legacy_translation_row'] = true;
            $metadata['translation_of_category_id'] = $primaryCategoryId;

            DB::table('product_categories')
                ->where('id', $category->id)
                ->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        // Legacy source rows are intentionally preserved; only their role is marked.
    }

    private function key(mixed $salesChannelId, string $externalId): string
    {
        return ($salesChannelId ?: 'global').'|'.$externalId;
    }
};
