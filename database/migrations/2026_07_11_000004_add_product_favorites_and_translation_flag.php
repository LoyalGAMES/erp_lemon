<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_favorite')->default(false)->after('is_active');
            $table->boolean('is_translation')->default(false)->after('is_favorite');
            $table->index(['is_translation', 'is_favorite']);
        });

        if (! Schema::hasTable('product_channel_mappings')) {
            return;
        }

        DB::table('products')
            ->select(['id', 'attributes'])
            ->orderBy('id')
            ->each(function (object $product): void {
                $attributes = json_decode((string) ($product->attributes ?? '{}'), true);
                $translations = is_array($attributes) ? ($attributes['woocommerce_translations'] ?? []) : [];

                if (! is_array($translations)) {
                    return;
                }

                $salesChannelIds = DB::table('product_channel_mappings')
                    ->where('product_id', $product->id)
                    ->pluck('sales_channel_id');

                if ($salesChannelIds->isEmpty()) {
                    return;
                }

                foreach ($translations as $translation) {
                    $externalId = trim((string) (is_array($translation) ? ($translation['product_id'] ?? '') : ''));

                    if ($externalId === '') {
                        continue;
                    }

                    $translationProductIds = DB::table('product_channel_mappings')
                        ->where('external_product_id', $externalId)
                        ->whereIn('sales_channel_id', $salesChannelIds)
                        ->where('product_id', '!=', $product->id)
                        ->pluck('product_id');

                    if ($translationProductIds->isNotEmpty()) {
                        DB::table('products')
                            ->whereIn('id', $translationProductIds)
                            ->update(['is_translation' => true]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['is_translation', 'is_favorite']);
            $table->dropColumn(['is_favorite', 'is_translation']);
        });
    }
};
