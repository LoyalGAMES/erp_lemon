@php
    $categoryKey = $categoryKey ?? fn ($item): string => ($item->sales_channel_id ?: 'global').'|'.$item->external_id;
    $depth = $depth ?? 0;
    $usage = $categoryUsage->get(mb_strtolower($category->path ?: $category->name), $categoryUsage->get(mb_strtolower($category->name), 0));
    $childKey = $categoryKey($category);
    $children = $childrenByParentKey
        ->get($childKey, collect())
        ->sortBy(fn ($item) => sprintf('%05d %s', (int) ($item->sort_order ?? 100), mb_strtolower($item->name)));
@endphp

<li class="category-tree-item">
    <button
        class="category-tree-node"
        type="button"
        data-category-tree-select="{{ $category->id }}"
        style="--category-depth: {{ $depth }}"
    >
        <span class="category-tree-branch" aria-hidden="true">{{ $depth > 0 ? '↳' : '•' }}</span>
        <span class="category-tree-node-name">{{ $category->name }}</span>
        <span class="category-tree-node-meta">
            <span>{{ $category->salesChannel?->code ?? 'ERP' }}</span>
            <span>Użycie: {{ $usage }}</span>
        </span>
    </button>
    @if ($children->isNotEmpty())
        <ul class="category-tree-children">
            @foreach ($children as $child)
                @include('products.configuration._category_tree_node', [
                    'category' => $child,
                    'childrenByParentKey' => $childrenByParentKey,
                    'categoryKey' => $categoryKey,
                    'categoryUsage' => $categoryUsage,
                    'depth' => $depth + 1,
                ])
            @endforeach
        </ul>
    @endif
</li>
