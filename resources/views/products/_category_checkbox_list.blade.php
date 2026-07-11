@php
    $selectedCategoryIds = collect($selectedCategoryIds ?? [])
        ->map(fn ($id): int => (int) $id)
        ->filter()
        ->all();
@endphp

<div class="product-category-checklist" role="group" aria-label="Kategorie produktu">
    @forelse ($categoryOptions as $category)
        @if ($category['id'] ?? null)
            <label class="product-category-check">
                <input
                    name="category_ids[]"
                    type="checkbox"
                    value="{{ $category['id'] }}"
                    @checked(in_array((int) $category['id'], $selectedCategoryIds, true))
                >
                <span>
                    <strong>{{ $category['name'] }}</strong>
                    @if (($category['path'] ?? '') !== ($category['name'] ?? ''))
                        <small>{{ $category['path'] }}</small>
                    @endif
                    @if ($category['gs1_gpc_code'] ?? null)
                        <em>GS1 {{ $category['gs1_gpc_code'] }}</em>
                    @endif
                </span>
            </label>
        @endif
    @empty
        <span class="muted">Brak zdefiniowanych kategorii.</span>
    @endforelse
</div>
<small class="product-category-help">Zaznaczone pozycje są widoczne od razu. Pierwsza kategoria z mapowaniem GS1 może posłużyć do automatycznego EAN.</small>
