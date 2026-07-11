<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCategoryChannelAlias;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProductCategoryTranslationMergeService
{
    public function merge(ProductCategory $canonical, ProductCategory $duplicate): ProductCategory
    {
        if ($canonical->is($duplicate)) {
            return $canonical;
        }

        return DB::transaction(function () use ($canonical, $duplicate): ProductCategory {
            $categories = ProductCategory::query()
                ->whereKey([$canonical->id, $duplicate->id])
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $canonical = $categories->get($canonical->id);
            $duplicate = $categories->get($duplicate->id);

            // A repeated import may have completed this merge already.
            if (! $canonical instanceof ProductCategory) {
                throw new InvalidArgumentException('Nie znaleziono kanonicznej kategorii produktu.');
            }

            if (! $duplicate instanceof ProductCategory) {
                return $canonical->refresh();
            }

            if ((int) $canonical->sales_channel_id !== (int) $duplicate->sales_channel_id) {
                throw new InvalidArgumentException('Nie można scalać kategorii z różnych kanałów sprzedaży.');
            }

            $this->reassignAliases($canonical, $duplicate);
            $this->reassignChildCategories($canonical, $duplicate);
            $this->reassignProductReferences($canonical, $duplicate);

            $canonical->forceFill([
                'metadata' => $this->mergedMetadata($canonical, $duplicate),
                'gs1_gpc_code' => $canonical->gs1_gpc_code ?: $duplicate->gs1_gpc_code,
                'gs1_gpc_label' => $canonical->gs1_gpc_label ?: $duplicate->gs1_gpc_label,
            ])->save();
            $duplicate->delete();

            return $canonical->refresh();
        });
    }

    private function reassignAliases(ProductCategory $canonical, ProductCategory $duplicate): void
    {
        ProductCategoryChannelAlias::query()
            ->where('product_category_id', $duplicate->id)
            ->update(['product_category_id' => $canonical->id]);
    }

    private function reassignChildCategories(ProductCategory $canonical, ProductCategory $duplicate): void
    {
        $oldPath = (string) ($duplicate->path ?: $duplicate->name);
        $newPath = (string) ($canonical->path ?: $canonical->name);

        ProductCategory::query()
            ->where('sales_channel_id', $canonical->sales_channel_id)
            ->whereNotIn('id', [$canonical->id, $duplicate->id])
            ->get()
            ->filter(fn (ProductCategory $category): bool => (string) $category->parent_external_id === (string) $duplicate->external_id
                || (string) $category->path === $oldPath
                || str_starts_with((string) $category->path, $oldPath.' > '))
            ->each(function (ProductCategory $child) use ($canonical, $duplicate, $oldPath, $newPath): void {
                $path = $this->replaceExactPathPrefix(
                    $child->path,
                    $oldPath,
                    $newPath,
                );

                $child->forceFill([
                    'parent_external_id' => (string) $child->parent_external_id === (string) $duplicate->external_id
                        ? $canonical->external_id
                        : $child->parent_external_id,
                    'path' => $path,
                ])->save();
            });
    }

    private function reassignProductReferences(ProductCategory $canonical, ProductCategory $duplicate): void
    {
        Product::query()
            ->whereNotNull('attributes')
            ->select(['id', 'attributes'])
            ->chunkById(200, function ($products) use ($canonical, $duplicate): void {
                foreach ($products as $product) {
                    $attributes = (array) $product->attributes;
                    $original = $attributes;
                    $categoryIds = collect((array) data_get($attributes, 'master.category_ids', []))
                        ->map(fn (mixed $id): int => (int) $id)
                        ->map(fn (int $id): int => $id === (int) $duplicate->id ? (int) $canonical->id : $id)
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    if (data_get($attributes, 'master.category_ids') !== null) {
                        data_set($attributes, 'master.category_ids', $categoryIds);
                    }

                    if ((int) data_get($attributes, 'master.category_id') === (int) $duplicate->id) {
                        data_set($attributes, 'master.category_id', (int) $canonical->id);
                    }

                    foreach (['master.category'] as $path) {
                        $value = data_get($attributes, $path);

                        if (is_string($value)) {
                            data_set($attributes, $path, $this->canonicalCategoryLabel($value, $canonical, $duplicate));
                        }
                    }

                    foreach (['master.categories', 'woocommerce_categories'] as $path) {
                        $values = data_get($attributes, $path);

                        if (! is_array($values)) {
                            continue;
                        }

                        data_set($attributes, $path, collect($values)
                            ->map(fn (mixed $value): mixed => is_string($value)
                                ? $this->canonicalCategoryLabel($value, $canonical, $duplicate)
                                : $value)
                            ->unique(fn (mixed $value): string => is_scalar($value) ? mb_strtolower((string) $value) : serialize($value))
                            ->values()
                            ->all());
                    }

                    if ($attributes !== $original) {
                        $product->forceFill(['attributes' => $attributes])->save();
                    }
                }
            });
    }

    private function canonicalCategoryLabel(
        string $value,
        ProductCategory $canonical,
        ProductCategory $duplicate,
    ): string {
        $value = trim($value);

        if ($value === trim((string) $duplicate->name)) {
            return (string) $canonical->name;
        }

        if ($value === trim((string) ($duplicate->path ?: $duplicate->name))) {
            return (string) ($canonical->path ?: $canonical->name);
        }

        return $value;
    }

    private function replaceExactPathPrefix(?string $path, string $from, string $to): ?string
    {
        if ($path === null || trim($path) === '') {
            return $path;
        }

        if ($path === $from) {
            return $to;
        }

        $prefix = $from.' > ';

        return str_starts_with($path, $prefix)
            ? $to.' > '.substr($path, strlen($prefix))
            : $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function mergedMetadata(ProductCategory $canonical, ProductCategory $duplicate): array
    {
        $metadata = array_replace_recursive(
            (array) $duplicate->metadata,
            (array) $canonical->metadata,
        );
        unset($metadata['legacy_translation_row'], $metadata['translation_of_category_id']);
        $metadata['translation_merge'] = array_merge(
            (array) ($metadata['translation_merge'] ?? []),
            [
                'merged_category_ids' => collect((array) data_get($metadata, 'translation_merge.merged_category_ids', []))
                    ->push((int) $duplicate->id)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
                'merged_at' => now()->toISOString(),
            ],
        );

        return $metadata;
    }
}
