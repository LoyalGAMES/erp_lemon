<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_category_channel_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('language', 16)->nullable();
            $table->string('translation_group')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['sales_channel_id', 'external_id'],
                'product_category_alias_channel_external_unique',
            );
            $table->index(
                ['product_category_id', 'sales_channel_id'],
                'product_category_alias_category_channel_index',
            );
            $table->index(
                ['sales_channel_id', 'translation_group'],
                'product_category_alias_translation_group_index',
            );
        });

        $categories = DB::table('product_categories')
            ->whereNotNull('sales_channel_id')
            ->select(['id', 'sales_channel_id', 'external_id', 'metadata', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get();
        $existingCategoryIds = $categories
            ->mapWithKeys(fn (object $category): array => [(int) $category->id => true])
            ->all();
        $canonicalByExternalId = [];

        // Existing canonical rows already contain the Polylang ID map in
        // metadata. Register those first so a separate historical EN row does
        // not become the owner of its external ID during the backfill.
        foreach ($categories as $category) {
            $metadata = json_decode((string) ($category->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];

            if (($metadata['legacy_translation_row'] ?? false) === true) {
                continue;
            }

            foreach ((array) ($metadata['woocommerce_ids'] ?? []) as $externalId) {
                $externalId = trim((string) $externalId);

                if ($externalId !== '') {
                    $canonicalByExternalId[$this->key($category->sales_channel_id, $externalId)] = (int) $category->id;
                }
            }
        }

        foreach ($categories as $category) {
            $metadata = json_decode((string) ($category->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $translationTargetId = (int) ($metadata['translation_of_category_id'] ?? 0);
            $externalIds = (array) ($metadata['woocommerce_ids'] ?? []);
            $externalIds[$this->languageForExternalId($externalIds, (string) $category->external_id) ?? 'default']
                = (string) $category->external_id;

            foreach ($externalIds as $language => $externalId) {
                $externalId = trim((string) $externalId);

                if ($externalId === '') {
                    continue;
                }

                $targetId = $canonicalByExternalId[$this->key($category->sales_channel_id, $externalId)]
                    ?? ($translationTargetId > 0 && isset($existingCategoryIds[$translationTargetId])
                        ? $translationTargetId
                        : (int) $category->id);

                DB::table('product_category_channel_aliases')->updateOrInsert(
                    [
                        'sales_channel_id' => $category->sales_channel_id,
                        'external_id' => $externalId,
                    ],
                    [
                        'product_category_id' => $targetId,
                        'language' => $language !== 'default' ? mb_strtolower(trim((string) $language)) : null,
                        'translation_group' => $metadata['translation_group'] ?? null,
                        'metadata' => json_encode([
                            'source' => 'legacy_product_category_backfill',
                        ], JSON_UNESCAPED_UNICODE),
                        'created_at' => $category->created_at ?? now(),
                        'updated_at' => $category->updated_at ?? now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_category_channel_aliases');
    }

    /**
     * @param  array<string, mixed>  $externalIds
     */
    private function languageForExternalId(array $externalIds, string $needle): ?string
    {
        foreach ($externalIds as $language => $externalId) {
            if (trim((string) $externalId) === trim($needle)) {
                return (string) $language;
            }
        }

        return null;
    }

    private function key(mixed $salesChannelId, string $externalId): string
    {
        return (string) $salesChannelId.'|'.trim($externalId);
    }
};
