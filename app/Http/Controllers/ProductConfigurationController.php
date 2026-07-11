<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductParameterDefinition;
use App\Models\SalesChannel;
use App\Services\Gs1\Gs1SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductConfigurationController extends Controller
{
    public function categories(Gs1SettingsService $gs1Settings): View
    {
        return view('products.configuration.categories', [
            'categories' => $this->primaryCategories(),
            'salesChannels' => SalesChannel::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
            'categoryUsage' => $this->categoryUsage(),
            'gpcOptions' => $gs1Settings->publicConfiguration()['gpc_options'],
            'module' => 'product-categories',
            'title' => 'Kategorie produktów',
            'subtitle' => 'Wspólny słownik kategorii PIM używany w formularzu produktu i eksporcie do WooCommerce.',
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $this->categoryData($request);

        $category = ProductCategory::query()->create($validated);
        $this->refreshChildCategoryPaths($category);

        return back()->with('status', 'Kategoria produktu została dodana.');
    }

    public function updateCategory(Request $request, ProductCategory $category): RedirectResponse
    {
        $category->update($this->categoryData($request, $category));
        $this->refreshChildCategoryPaths($category->fresh() ?? $category);

        return back()->with('status', 'Kategoria produktu została zapisana.');
    }

    public function sortCategories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:product_categories,id'],
            'items.*.parent_external_id' => ['nullable', 'string', 'max:255'],
            'items.*.sort_order' => ['required', 'integer', 'min:0', 'max:65000'],
        ]);

        $items = collect($validated['items']);
        $categories = ProductCategory::query()
            ->whereKey($items->pluck('id')->all())
            ->get()
            ->keyBy('id');
        $updated = collect();

        foreach ($items as $item) {
            $category = $categories->get((int) $item['id']);

            if (! $category instanceof ProductCategory) {
                continue;
            }

            $parentExternalId = $this->nullableString($item['parent_external_id'] ?? null);

            if ($parentExternalId !== null && ! $this->categoryScope(ProductCategory::query(), $category->sales_channel_id)
                ->where('external_id', $parentExternalId)
                ->exists()
            ) {
                $parentExternalId = null;
            }

            if ($parentExternalId === (string) $category->external_id
                || ($parentExternalId !== null && $this->wouldCreateCategoryCycle($category, $parentExternalId, $category->sales_channel_id))
            ) {
                $parentExternalId = null;
            }

            $category->forceFill([
                'parent_external_id' => $parentExternalId,
                'sort_order' => (int) $item['sort_order'],
                'path' => $this->categoryPath($category->sales_channel_id, $category->name, $parentExternalId, $category),
            ])->save();

            $updated->push($category->fresh() ?? $category);
        }

        $updated->each(function (ProductCategory $category): void {
            $this->refreshChildCategoryPaths($category);
        });

        return response()->json(['status' => 'ok']);
    }

    public function destroyCategory(ProductCategory $category): RedirectResponse
    {
        $this->detachChildCategories($category);
        $category->delete();

        return back()->with('status', 'Kategoria produktu została usunięta.');
    }

    public function parameters(): View
    {
        return view('products.configuration.parameters', [
            'definitions' => ProductParameterDefinition::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'discoveredParameters' => $this->discoveredParameterOptions(),
            'module' => 'product-parameters',
            'title' => 'Parametry produktów',
            'subtitle' => 'Słownik atrybutów PIM dla produktów, wariantów i eksportu do WooCommerce.',
        ]);
    }

    public function storeParameter(Request $request): RedirectResponse
    {
        ProductParameterDefinition::query()->create($this->parameterData($request));

        return back()->with('status', 'Parametr produktu został dodany.');
    }

    public function updateParameter(Request $request, ProductParameterDefinition $parameter): RedirectResponse
    {
        $parameter->update($this->parameterData($request, $parameter));

        return back()->with('status', 'Parametr produktu został zapisany.');
    }

    public function destroyParameter(ProductParameterDefinition $parameter): RedirectResponse
    {
        $parameter->delete();

        return back()->with('status', 'Parametr produktu został usunięty.');
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryData(Request $request, ?ProductCategory $category = null): array
    {
        $salesChannelId = $request->filled('sales_channel_id') ? (int) $request->input('sales_channel_id') : null;
        $validated = $request->validate([
            'sales_channel_id' => ['nullable', 'integer', 'exists:sales_channels,id'],
            'external_id' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('product_categories', 'external_id')
                    ->where(fn ($query) => $salesChannelId === null
                        ? $query->whereNull('sales_channel_id')
                        : $query->where('sales_channel_id', $salesChannelId))
                    ->ignore($category?->id),
            ],
            'parent_external_id' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'slug_en' => ['nullable', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
            'description_en' => ['nullable', 'string', 'max:8000'],
            'gs1_gpc_code' => ['nullable', 'string', 'regex:/^\d{8}$/'],
            'gs1_gpc_label' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ]);

        $name = trim((string) $validated['name']);
        $parentExternalId = $this->nullableString($validated['parent_external_id'] ?? null);
        $slug = trim((string) ($validated['slug'] ?? '')) ?: Str::slug($name);
        $externalId = trim((string) ($validated['external_id'] ?? ''));

        if ($externalId === '') {
            $externalId = $this->uniqueErpCategoryExternalId($salesChannelId, $slug ?: Str::slug($name), $category);
        }

        if ($parentExternalId !== null && ! $this->categoryScope(ProductCategory::query(), $salesChannelId)
            ->where('external_id', $parentExternalId)
            ->when($category !== null, fn ($query) => $query->whereKeyNot($category->id))
            ->exists()
        ) {
            $parentExternalId = null;
        }

        if ($category !== null && $parentExternalId === (string) $category->external_id) {
            $parentExternalId = null;
        }

        if ($category !== null && $parentExternalId !== null && $this->wouldCreateCategoryCycle($category, $parentExternalId, $salesChannelId)) {
            $parentExternalId = null;
        }

        $metadata = array_merge((array) $category?->metadata, [
            'source' => ctype_digit($externalId) ? 'woocommerce' : 'erp',
            'managed_in_erp' => true,
        ]);
        $englishTranslation = array_filter([
            'name' => $this->nullableString($validated['name_en'] ?? null),
            'slug' => $this->nullableString($validated['slug_en'] ?? null),
            'description' => $this->nullableString($validated['description_en'] ?? null),
        ], fn (?string $value): bool => $value !== null);

        if ($englishTranslation === []) {
            data_forget($metadata, 'translations.en');
        } else {
            data_set($metadata, 'translations.en', $englishTranslation);
        }

        return [
            'sales_channel_id' => $salesChannelId,
            'external_id' => $externalId,
            'parent_external_id' => $parentExternalId,
            'name' => $name,
            'slug' => $slug ?: null,
            'path' => $this->categoryPath($salesChannelId, $name, $parentExternalId, $category),
            'description' => $this->nullableString($validated['description'] ?? null),
            'gs1_gpc_code' => $this->nullableString($validated['gs1_gpc_code'] ?? null),
            'gs1_gpc_label' => $this->nullableString($validated['gs1_gpc_label'] ?? null),
            'sort_order' => (int) ($validated['sort_order'] ?? $category?->sort_order ?? 100),
            'metadata' => $metadata,
        ];
    }

    /**
     * @return Collection<int, ProductCategory>
     */
    private function primaryCategories(): Collection
    {
        return ProductCategory::query()
            ->with('salesChannel')
            ->orderBy('sales_channel_id')
            ->orderBy('parent_external_id')
            ->orderBy('sort_order')
            ->orderBy('path')
            ->orderBy('name')
            ->get()
            ->reject(function (ProductCategory $category): bool {
                $woocommerceIds = (array) data_get($category->metadata, 'woocommerce_ids', []);

                return filled($woocommerceIds['en'] ?? null)
                    && blank($woocommerceIds['pl'] ?? null);
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterData(Request $request, ?ProductParameterDefinition $parameter = null): array
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_parameter_definitions', 'name')->ignore($parameter?->id),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('product_parameter_definitions', 'slug')->ignore($parameter?->id),
            ],
            'input_type' => ['required', 'string', 'in:text,number,select,multiselect,boolean'],
            'values_text' => ['nullable', 'string', 'max:8000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ]);

        $name = trim((string) $validated['name']);
        $slug = trim((string) ($validated['slug'] ?? '')) ?: Str::slug($name);
        $slug = $this->uniqueParameterSlug($slug ?: Str::random(8), $parameter);

        return [
            'name' => $name,
            'slug' => $slug,
            'input_type' => $validated['input_type'],
            'values' => $this->valueList($validated['values_text'] ?? ''),
            'is_variant' => $request->boolean('is_variant'),
            'is_required' => $request->boolean('is_required'),
            'sort_order' => (int) ($validated['sort_order'] ?? 100),
            'metadata' => [
                'managed_in_erp' => true,
            ],
        ];
    }

    /**
     * @return Collection<string, int>
     */
    private function categoryUsage(): Collection
    {
        return Product::query()
            ->whereNotNull('attributes')
            ->get(['attributes'])
            ->map(fn (Product $product): ?string => $this->nullableString(data_get((array) $product->attributes, 'master.category')))
            ->filter()
            ->map(fn (string $category): string => mb_strtolower($category))
            ->countBy();
    }

    /**
     * @return Collection<int, array{name:string,values:list<string>,usage:int}>
     */
    private function discoveredParameterOptions(): Collection
    {
        return Product::query()
            ->whereNotNull('attributes')
            ->get(['attributes'])
            ->flatMap(function (Product $product): array {
                return collect((array) data_get((array) $product->attributes, 'master.parameters', []))
                    ->filter(fn ($row): bool => is_array($row))
                    ->map(fn (array $row): array => [
                        'name' => trim((string) ($row['name'] ?? '')),
                        'value' => trim((string) ($row['value'] ?? '')),
                    ])
                    ->filter(fn (array $row): bool => $row['name'] !== '')
                    ->values()
                    ->all();
            })
            ->groupBy(fn (array $row): string => mb_strtolower($row['name']))
            ->map(fn (Collection $rows): array => [
                'name' => (string) $rows->first()['name'],
                'values' => $rows->pluck('value')->filter()->unique()->sort()->values()->all(),
                'usage' => $rows->count(),
            ])
            ->sortBy('name')
            ->values();
    }

    /**
     * @return list<string>
     */
    private function valueList(mixed $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', (string) ($value ?? '')) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique(fn (string $item): string => mb_strtolower($item))
            ->values()
            ->all();
    }

    private function categoryPath(mixed $salesChannelId, string $name, ?string $parentExternalId, ?ProductCategory $category = null): string
    {
        $parent = $parentExternalId !== null
            ? $this->categoryScope(ProductCategory::query(), $salesChannelId)
                ->where('external_id', $parentExternalId)
                ->when($category !== null, fn ($query) => $query->whereKeyNot($category->id))
                ->first()
            : null;

        if (! $parent instanceof ProductCategory) {
            return $name;
        }

        return trim(($parent->path ?: $parent->name).' > '.$name);
    }

    private function refreshChildCategoryPaths(ProductCategory $category, array &$visited = []): void
    {
        $key = ((string) ($category->sales_channel_id ?? 'global')).':'.(string) $category->external_id;

        if (isset($visited[$key])) {
            return;
        }

        $visited[$key] = true;

        $children = $this->categoryScope(ProductCategory::query(), $category->sales_channel_id)
            ->where('parent_external_id', $category->external_id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        foreach ($children as $child) {
            $childPath = trim(($category->path ?: $category->name).' > '.$child->name);

            if ($child->path !== $childPath) {
                $child->forceFill(['path' => $childPath])->save();
            }

            $this->refreshChildCategoryPaths($child, $visited);
        }
    }

    private function detachChildCategories(ProductCategory $category): void
    {
        $children = $this->categoryScope(ProductCategory::query(), $category->sales_channel_id)
            ->where('parent_external_id', $category->external_id)
            ->get();

        foreach ($children as $child) {
            $child->forceFill([
                'parent_external_id' => null,
                'path' => $child->name,
            ])->save();

            $this->refreshChildCategoryPaths($child);
        }
    }

    private function wouldCreateCategoryCycle(ProductCategory $category, string $parentExternalId, mixed $salesChannelId): bool
    {
        $visited = [];
        $currentParentExternalId = $parentExternalId;

        while ($currentParentExternalId !== '') {
            if ($currentParentExternalId === (string) $category->external_id) {
                return true;
            }

            if (isset($visited[$currentParentExternalId])) {
                return true;
            }

            $visited[$currentParentExternalId] = true;
            $parent = $this->categoryScope(ProductCategory::query(), $salesChannelId)
                ->where('external_id', $currentParentExternalId)
                ->first();

            if (! $parent instanceof ProductCategory) {
                return false;
            }

            $currentParentExternalId = (string) ($this->nullableString($parent->parent_external_id) ?? '');
        }

        return false;
    }

    private function categoryScope($query, mixed $salesChannelId)
    {
        return $salesChannelId === null
            ? $query->whereNull('sales_channel_id')
            : $query->where('sales_channel_id', $salesChannelId);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function uniqueErpCategoryExternalId(mixed $salesChannelId, string $slug, ?ProductCategory $category = null): string
    {
        $scope = $salesChannelId ?: 'global';
        $base = 'erp:'.$scope.':'.($slug ?: Str::random(8));
        $candidate = $base;
        $index = 2;

        while (ProductCategory::query()
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_id', $candidate)
            ->when($category !== null, fn ($query) => $query->whereKeyNot($category->id))
            ->exists()
        ) {
            $candidate = $base.'-'.$index;
            $index++;
        }

        return $candidate;
    }

    private function uniqueParameterSlug(string $slug, ?ProductParameterDefinition $parameter = null): string
    {
        $base = $slug ?: Str::random(8);
        $candidate = $base;
        $index = 2;

        while (ProductParameterDefinition::query()
            ->where('slug', $candidate)
            ->when($parameter !== null, fn ($query) => $query->whereKeyNot($parameter->id))
            ->exists()
        ) {
            $candidate = $base.'-'.$index;
            $index++;
        }

        return $candidate;
    }
}
