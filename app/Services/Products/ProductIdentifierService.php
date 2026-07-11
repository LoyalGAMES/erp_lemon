<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Gs1\Gs1GtinService;
use Illuminate\Support\Str;
use Throwable;

final class ProductIdentifierService
{
    public function __construct(
        private readonly Gs1GtinService $gtinService,
    ) {}

    public function temporarySku(): string
    {
        return 'AUTO-'.Str::upper((string) Str::ulid());
    }

    public function ensureSku(Product $product, bool $force = false): bool
    {
        if (! $force && filled($product->sku) && ! str_starts_with($product->sku, 'AUTO-')) {
            return false;
        }

        $base = 'SEM-'.str_pad((string) $product->id, 8, '0', STR_PAD_LEFT);
        $sku = $base;
        $suffix = 1;

        while (Product::query()->where('sku', $sku)->whereKeyNot($product->id)->exists()) {
            $sku = $base.'-'.$suffix++;
        }

        $product->forceFill(['sku' => $sku])->save();

        return true;
    }

    /**
     * @return array{generated:bool,error:?string}
     */
    public function ensureEan(Product $product): array
    {
        if (filled($product->ean)) {
            return ['generated' => false, 'error' => null];
        }

        $category = $this->gs1Category($product);

        if (! $category instanceof ProductCategory || blank($category->gs1_gpc_code)) {
            return [
                'generated' => false,
                'error' => 'EAN nie został nadany: przypisz produktowi kategorię z mapowaniem GS1.',
            ];
        }

        try {
            $this->gtinService->generateForProduct(
                $product,
                (string) $category->gs1_gpc_code,
                $category->gs1_gpc_label,
            );

            return ['generated' => true, 'error' => null];
        } catch (Throwable $exception) {
            report($exception);

            return ['generated' => false, 'error' => 'EAN nie został nadany: '.$exception->getMessage()];
        }
    }

    private function gs1Category(Product $product): ?ProductCategory
    {
        $master = $product->masterData();
        $categoryIds = collect((array) data_get($master, 'category_ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->values();

        if ($categoryIds->isNotEmpty()) {
            $categories = ProductCategory::query()
                ->whereIn('id', $categoryIds)
                ->whereNotNull('gs1_gpc_code')
                ->get()
                ->keyBy('id');

            return $categoryIds
                ->map(fn (int $id): ?ProductCategory => $categories->get($id))
                ->first(fn (?ProductCategory $category): bool => $category instanceof ProductCategory);
        }

        $parent = $product->variantParents()->first();

        if ($parent instanceof Product) {
            $parentCategory = $this->gs1Category($parent);

            if ($parentCategory instanceof ProductCategory) {
                return $parentCategory;
            }
        }

        $legacyCategory = trim((string) data_get($master, 'category', ''));

        return $legacyCategory === '' ? null : ProductCategory::query()
            ->whereNotNull('gs1_gpc_code')
            ->where(fn ($query) => $query->where('name', $legacyCategory)->orWhere('path', $legacyCategory))
            ->first();
    }
}
