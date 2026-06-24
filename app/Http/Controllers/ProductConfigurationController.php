<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductParameterDefinition;
use App\Models\SalesChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductConfigurationController extends Controller
{
    public function categories(): View
    {
        return view('products.configuration.categories', [
            'categories' => ProductCategory::query()
                ->with('salesChannel')
                ->orderBy('sales_channel_id')
                ->orderBy('path')
                ->orderBy('name')
                ->get(),
            'salesChannels' => SalesChannel::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
            'categoryUsage' => $this->categoryUsage(),
            'module' => 'product-categories',
            'title' => 'Kategorie produktów',
            'subtitle' => 'Wspólny słownik kategorii PIM używany w formularzu produktu i eksporcie do WooCommerce.',
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $validated = $this->categoryData($request);

        ProductCategory::query()->create($validated);

        return back()->with('status', 'Kategoria produktu została dodana.');
    }

    public function updateCategory(Request $request, ProductCategory $category): RedirectResponse
    {
        $category->update($this->categoryData($request, $category));

        return back()->with('status', 'Kategoria produktu została zapisana.');
    }

    public function destroyCategory(ProductCategory $category): RedirectResponse
    {
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
            'slug' => ['nullable', 'string', 'max:255'],
            'path' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:8000'],
        ]);

        $name = trim((string) $validated['name']);
        $path = trim((string) ($validated['path'] ?? '')) ?: $name;
        $slug = trim((string) ($validated['slug'] ?? '')) ?: Str::slug($path);
        $externalId = trim((string) ($validated['external_id'] ?? ''));

        if ($externalId === '') {
            $externalId = $this->uniqueErpCategoryExternalId($salesChannelId, $slug ?: Str::slug($name), $category);
        }

        return [
            'sales_channel_id' => $salesChannelId,
            'external_id' => $externalId,
            'parent_external_id' => $this->nullableString($validated['parent_external_id'] ?? null),
            'name' => $name,
            'slug' => $slug ?: null,
            'path' => $path,
            'description' => $this->nullableString($validated['description'] ?? null),
            'metadata' => [
                'source' => ctype_digit($externalId) ? 'woocommerce' : 'erp',
                'managed_in_erp' => true,
            ],
        ];
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

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function uniqueErpCategoryExternalId(mixed $salesChannelId, string $slug, ?ProductCategory $category = null): string
    {
        $scope = $salesChannelId ?: 'global';
        $base = 'erp:' . $scope . ':' . ($slug ?: Str::random(8));
        $candidate = $base;
        $index = 2;

        while (ProductCategory::query()
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_id', $candidate)
            ->when($category !== null, fn ($query) => $query->whereKeyNot($category->id))
            ->exists()
        ) {
            $candidate = $base . '-' . $index;
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
            $candidate = $base . '-' . $index;
            $index++;
        }

        return $candidate;
    }
}
