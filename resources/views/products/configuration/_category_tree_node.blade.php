@php
    $nodeKey = $categoryKey($category);
    $children = $childrenByParentKey->get($nodeKey, collect());
    $usage = $categoryUsage->get(mb_strtolower($category->path ?: $category->name), $categoryUsage->get(mb_strtolower($category->name), 0));
    $updateFormId = 'category-update-' . $category->id;
    $deleteFormId = 'category-delete-' . $category->id;
    $parentLabel = '';
    $parentKey = $category->parent_external_id ? (($category->sales_channel_id ?: 'global') . '|' . $category->parent_external_id) : null;
    $parentCategory = $parentKey ? $categoryByScopedExternalId->get($parentKey) : null;

    if ($parentCategory) {
        $parentLabel = ($parentCategory->path ?: $parentCategory->name) . ($parentCategory->salesChannel ? ' | ' . $parentCategory->salesChannel->code : '');
    }
@endphp

<li
    class="category-tree-item"
    data-category-node
    data-category-id="{{ $category->id }}"
    data-category-external-id="{{ $category->external_id }}"
    data-category-channel="{{ $category->sales_channel_id ?: 'global' }}"
>
    <div class="category-tree-card" style="--category-depth: {{ $depth }}">
        <form id="{{ $updateFormId }}" method="POST" action="{{ route('products.categories.update', $category) }}">
            @csrf
            @method('PUT')
        </form>
        <form id="{{ $deleteFormId }}" method="POST" action="{{ route('products.categories.destroy', $category) }}" onsubmit="return confirm('Usunąć kategorię produktu?');">
            @csrf
            @method('DELETE')
        </form>

        <div class="category-tree-main">
            <button class="category-drag-handle" type="button" title="Przeciągnij kategorię" draggable="true">↕</button>
            <div class="category-tree-fields">
                <label>Nazwa kategorii
                    <input name="name" form="{{ $updateFormId }}" value="{{ $category->name }}" required>
                </label>
                <label>Kategoria nadrzędna
                    <input
                        value="{{ $parentLabel }}"
                        list="category-parent-options"
                        placeholder="Brak - kategoria główna"
                        data-category-parent-lookup
                        data-category-parent-hidden="category-parent-{{ $category->id }}"
                        data-current-category="{{ $category->external_id }}"
                        data-current-channel="{{ $category->sales_channel_id ?: 'global' }}"
                    >
                    <input id="category-parent-{{ $category->id }}" type="hidden" name="parent_external_id" form="{{ $updateFormId }}" value="{{ $category->parent_external_id }}">
                </label>
            </div>
            <div class="category-tree-description">
                <label>Opis kategorii
                    <textarea name="description" form="{{ $updateFormId }}" placeholder="Opis kategorii do PIM i WooCommerce">{{ $category->description }}</textarea>
                </label>
            </div>
            <div class="category-tree-actions">
                <span class="status">Użycie: {{ $usage }}</span>
                <button class="button secondary" type="submit" form="{{ $updateFormId }}">Zapisz</button>
                <button class="button" style="background: var(--red);" type="submit" form="{{ $deleteFormId }}">Usuń</button>
            </div>
        </div>

        <div class="category-tree-path">
            <span>{{ $category->path ?: $category->name }}</span>
            <span>{{ $category->salesChannel?->code ?? 'ERP' }}</span>
        </div>

        <details class="category-technical-details">
            <summary>Ustawienia techniczne</summary>
            <div class="category-technical-grid">
                <label>Kanał
                    <select name="sales_channel_id" form="{{ $updateFormId }}">
                        <option value="" @selected($category->sales_channel_id === null)>Globalna ERP</option>
                        @foreach ($salesChannels as $channel)
                            <option value="{{ $channel->id }}" @selected((int) $category->sales_channel_id === (int) $channel->id)>{{ $channel->code }} - {{ $channel->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>ID WooCommerce / ERP
                    <input name="external_id" form="{{ $updateFormId }}" value="{{ $category->external_id }}">
                </label>
                <label>Adres URL kategorii
                    <input name="slug" form="{{ $updateFormId }}" value="{{ $category->slug }}" placeholder="np. koszule">
                </label>
            </div>
        </details>
    </div>

    @if ($children->isNotEmpty())
        <ol class="category-tree-list">
            @foreach ($children->sortBy('name') as $child)
                @include('products.configuration._category_tree_node', [
                    'category' => $child,
                    'childrenByParentKey' => $childrenByParentKey,
                    'categoryByScopedExternalId' => $categoryByScopedExternalId,
                    'categoryKey' => $categoryKey,
                    'categoryUsage' => $categoryUsage,
                    'salesChannels' => $salesChannels,
                    'depth' => $depth + 1,
                ])
            @endforeach
        </ol>
    @endif
</li>
