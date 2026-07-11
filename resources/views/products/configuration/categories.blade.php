@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'product-categories',
])

@push('styles')
    <link href="https://unpkg.com/sortable-tree/dist/sortable-tree.css" rel="stylesheet">
    <style>
        .product-config-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .product-config-panel { margin-bottom: 16px; }
        .category-create-shell > summary { list-style: none; cursor: pointer; }
        .category-create-shell > summary::-webkit-details-marker { display: none; }
        .category-create-form { padding: 16px; display: grid; grid-template-columns: minmax(180px, .7fr) minmax(220px, 1fr) minmax(260px, 1fr) auto; gap: 12px; align-items: end; }
        .category-create-form .full { grid-column: 1 / -2; }
        .category-tree-layout { display: grid; grid-template-columns: minmax(280px, .85fr) minmax(360px, 1fr); gap: 16px; padding: 16px; align-items: start; }
        .category-tree-root { min-height: 240px; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fffdfb; }
        .category-tree-empty { color: var(--muted); padding: 12px; }
        .category-node-label { display: flex; align-items: center; gap: 8px; min-width: 0; width: 100%; }
        .category-node-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 800; color: var(--text); }
        .category-node-meta { flex: 0 0 auto; display: inline-flex; gap: 6px; align-items: center; color: var(--muted); font-size: 12px; }
        .category-node-pill { border: 1px solid var(--border); border-radius: 999px; padding: 2px 7px; background: #fff; }
        .category-editor-panel { border: 1px solid var(--border); border-radius: 8px; background: #fff; padding: 14px; display: grid; gap: 12px; }
        .category-editor-panel[hidden] { display: none; }
        .category-editor-placeholder { border: 1px dashed var(--border); border-radius: 8px; padding: 20px; color: var(--muted); background: #fffdfb; }
        .category-editor-form { display: grid; gap: 12px; }
        .category-editor-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .category-editor-form textarea { min-height: 120px; resize: vertical; }
        .category-editor-actions { display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .category-path-preview { display: flex; gap: 8px; flex-wrap: wrap; color: var(--muted); font-size: 13px; }
        .category-path-preview span { border: 1px solid var(--border); border-radius: 999px; padding: 3px 8px; background: #fffdfb; }
        .category-technical-details { border-top: 1px solid var(--border); padding-top: 8px; }
        .category-technical-details summary { cursor: pointer; color: var(--muted); font-weight: 700; }
        .category-technical-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 10px; }
        .category-tree-root .tree,
        .category-tree-root .tree__subnodes { list-style: none; padding-left: 0; }
        .category-tree-root .tree__subnodes { margin-left: 18px; }
        .category-tree-root .tree__node { margin: 5px 0; }
        .category-tree-root .tree__label { width: 100%; border: 1px solid var(--border); border-radius: 8px; background: #fff; padding: 8px 10px; cursor: grab; }
        .category-tree-root .tree__label:hover { background: #fffdfb; border-color: var(--green-dark); }
        .category-tree-root .tree__label.is-selected { background: var(--green-soft); border-color: var(--green-dark); }
        .category-tree-root .tree__collapse { width: 28px; height: 28px; border: 1px solid var(--border); border-radius: 7px; background: #fff; color: var(--muted); }
        .category-tree-root .tree__node--dragging .tree__label { opacity: .55; }
        .category-tree-root .tree__node--drop-inside > .tree__label,
        .category-tree-root .tree__node--drop-before > .tree__label,
        .category-tree-root .tree__node--drop-after > .tree__label { border-color: var(--green-dark); background: var(--green-soft); }
        @media (max-width: 1080px) {
            .category-create-form, .category-tree-layout, .category-editor-grid, .category-technical-grid { grid-template-columns: 1fr; }
            .category-create-form .full { grid-column: 1 / -1; }
            .category-editor-actions { justify-content: flex-start; }
        }
    </style>
@endpush

@section('content')
    @php
        $categoryKey = fn ($category): string => ($category->sales_channel_id ?: 'global') . '|' . $category->external_id;
        $categoryByScopedExternalId = $categories->keyBy($categoryKey);
        $childrenByParentKey = $categories->groupBy(function ($category) use ($categoryByScopedExternalId): string {
            if (! $category->parent_external_id) {
                return '__root__';
            }

            $parentKey = ($category->sales_channel_id ?: 'global') . '|' . $category->parent_external_id;

            return $categoryByScopedExternalId->has($parentKey) ? $parentKey : '__root__';
        });
        $buildNodes = function ($parentKey) use (&$buildNodes, $childrenByParentKey, $categoryKey, $categoryUsage) {
            return $childrenByParentKey
                ->get($parentKey, collect())
                ->sortBy(fn ($category) => sprintf('%05d %s', (int) ($category->sort_order ?? 100), mb_strtolower($category->name)))
                ->map(function ($category) use (&$buildNodes, $categoryKey, $categoryUsage): array {
                    $usage = $categoryUsage->get(mb_strtolower($category->path ?: $category->name), $categoryUsage->get(mb_strtolower($category->name), 0));

                    return [
                        'data' => [
                            'id' => $category->id,
                            'name' => $category->name,
                            'path' => $category->path ?: $category->name,
                            'externalId' => $category->external_id,
                            'channel' => $category->sales_channel_id ?: 'global',
                            'salesChannel' => $category->salesChannel?->code ?? 'ERP',
                            'usage' => $usage,
                            'sortOrder' => (int) ($category->sort_order ?? 100),
                        ],
                        'nodes' => $buildNodes($categoryKey($category)),
                    ];
                })
                ->values()
                ->all();
        };
        $treeNodes = $buildNodes('__root__');
    @endphp

    <div class="product-config-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('products.index') }}">Lista produktów</a>
            <a class="button secondary" href="{{ route('products.parameters.index') }}">Parametry</a>
        </div>
        <div class="toolbar-note">Drzewo kategorii PIM i WooCommerce</div>
    </div>

    <details class="card product-config-panel category-create-shell">
        <summary class="panel-header">
            <span>Dodaj kategorię do drzewa</span>
            <span>+</span>
        </summary>
        <form class="category-create-form" method="POST" action="{{ route('products.categories.store') }}">
            @csrf
            <input type="hidden" name="sort_order" value="100">
            <label>Kanał
                <select name="sales_channel_id">
                    <option value="">Globalna ERP</option>
                    @foreach ($salesChannels as $channel)
                        <option value="{{ $channel->id }}" @selected(old('sales_channel_id') == $channel->id)>{{ $channel->code }} - {{ $channel->name }}</option>
                    @endforeach
                </select>
            </label>
            <label>Nazwa kategorii
                <input name="name" value="{{ old('name') }}" required placeholder="np. Koszule">
            </label>
            <label>Kategoria nadrzędna
                <input
                    value=""
                    list="category-parent-options"
                    placeholder="Brak - kategoria główna"
                    data-category-parent-lookup
                    data-category-parent-hidden="new-category-parent"
                >
                <input id="new-category-parent" type="hidden" name="parent_external_id" value="{{ old('parent_external_id') }}">
            </label>
            <button class="button" type="submit">Dodaj</button>
            <label class="full">Opis kategorii
                <textarea name="description" placeholder="Opis kategorii do PIM i WooCommerce">{{ old('description') }}</textarea>
            </label>
            <label>Kategoria GS1 (GPC)
                <select name="gs1_gpc_code" data-gpc-select data-gpc-label-target="new-category-gpc-label">
                    <option value="">Brak mapowania</option>
                    @foreach ($gpcOptions as $option)
                        <option value="{{ $option['code'] }}" data-label="{{ $option['label'] }}" @selected(old('gs1_gpc_code') === $option['code'])>{{ $option['code'] }} — {{ $option['label'] }}</option>
                    @endforeach
                </select>
                <input id="new-category-gpc-label" type="hidden" name="gs1_gpc_label" value="{{ old('gs1_gpc_label') }}">
            </label>
            <details class="category-technical-details full">
                <summary>Ustawienia techniczne</summary>
                <div class="category-technical-grid">
                    <label>ID WooCommerce / ERP
                        <input name="external_id" value="{{ old('external_id') }}" placeholder="Zostaw puste dla kategorii ERP">
                    </label>
                    <label>Adres URL kategorii
                        <input name="slug" value="{{ old('slug') }}" placeholder="np. koszule">
                    </label>
                </div>
            </details>
        </form>
    </details>

    <section class="card">
        <div class="panel-header">
            <span>Drzewo kategorii PIM</span>
            <span>{{ $categories->count() }} pozycji</span>
        </div>
        <div class="category-tree-layout">
            <div>
                <script id="category-tree-data" type="application/json">@json($treeNodes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
                <div id="category-sortable-tree" class="category-tree-root">
                    @if ($categories->isEmpty())
                        <div class="category-tree-empty">Brak kategorii. Dodaj pierwszą kategorię formularzem powyżej albo zaimportuj produkty z WooCommerce.</div>
                    @endif
                </div>
                <div class="toolbar-note" data-category-tree-status></div>
            </div>

            <div>
                <div class="category-editor-placeholder" data-category-editor-placeholder>Brak wybranej kategorii.</div>

                @foreach ($categories as $category)
                    @php
                        $usage = $categoryUsage->get(mb_strtolower($category->path ?: $category->name), $categoryUsage->get(mb_strtolower($category->name), 0));
                        $updateFormId = 'category-update-' . $category->id;
                        $deleteFormId = 'category-delete-' . $category->id;
                        $parentKey = $category->parent_external_id ? (($category->sales_channel_id ?: 'global') . '|' . $category->parent_external_id) : null;
                        $parentCategory = $parentKey ? $categoryByScopedExternalId->get($parentKey) : null;
                        $parentLabel = $parentCategory ? (($parentCategory->path ?: $parentCategory->name) . ($parentCategory->salesChannel ? ' | ' . $parentCategory->salesChannel->code : '')) : '';
                    @endphp
                    <section class="category-editor-panel" data-category-editor="{{ $category->id }}" hidden>
                        <div class="panel-header">
                            <span>{{ $category->name }}</span>
                            <span class="status">Użycie: {{ $usage }}</span>
                        </div>
                        <form id="{{ $updateFormId }}" class="category-editor-form" method="POST" action="{{ route('products.categories.update', $category) }}">
                            @csrf
                            @method('PUT')
                            <input id="category-sort-order-{{ $category->id }}" type="hidden" name="sort_order" value="{{ $category->sort_order ?? 100 }}">
                            <div class="category-editor-grid">
                                <label>Nazwa kategorii
                                    <input name="name" value="{{ $category->name }}" required>
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
                                    <input id="category-parent-{{ $category->id }}" type="hidden" name="parent_external_id" value="{{ $category->parent_external_id }}">
                                </label>
                            </div>
                            <label>Opis kategorii
                                <textarea name="description" placeholder="Opis kategorii do PIM i WooCommerce">{{ $category->description }}</textarea>
                            </label>
                            <label>Kategoria GS1 (GPC)
                                <select name="gs1_gpc_code" data-gpc-select data-gpc-label-target="category-gpc-label-{{ $category->id }}">
                                    <option value="">Brak mapowania</option>
                                    @foreach ($gpcOptions as $option)
                                        <option value="{{ $option['code'] }}" data-label="{{ $option['label'] }}" @selected($category->gs1_gpc_code === $option['code'])>{{ $option['code'] }} — {{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                <input id="category-gpc-label-{{ $category->id }}" type="hidden" name="gs1_gpc_label" value="{{ $category->gs1_gpc_label }}">
                            </label>
                            <div class="category-path-preview">
                                <span>{{ $category->path ?: $category->name }}</span>
                                <span>{{ $category->salesChannel?->code ?? 'ERP' }}</span>
                            </div>
                            <details class="category-technical-details">
                                <summary>Ustawienia techniczne</summary>
                                <div class="category-technical-grid">
                                    <label>Kanał
                                        <select name="sales_channel_id">
                                            <option value="" @selected($category->sales_channel_id === null)>Globalna ERP</option>
                                            @foreach ($salesChannels as $channel)
                                                <option value="{{ $channel->id }}" @selected((int) $category->sales_channel_id === (int) $channel->id)>{{ $channel->code }} - {{ $channel->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>ID WooCommerce / ERP
                                        <input name="external_id" value="{{ $category->external_id }}">
                                    </label>
                                    <label>Adres URL kategorii
                                        <input name="slug" value="{{ $category->slug }}" placeholder="np. koszule">
                                    </label>
                                </div>
                            </details>
                        </form>
                        <form id="{{ $deleteFormId }}" method="POST" action="{{ route('products.categories.destroy', $category) }}" onsubmit="return confirm('Usunąć kategorię produktu?');">
                            @csrf
                            @method('DELETE')
                        </form>
                        <div class="category-editor-actions">
                            <button class="button secondary" type="submit" form="{{ $updateFormId }}">Zapisz</button>
                            <button class="button" style="background: var(--red);" type="submit" form="{{ $deleteFormId }}">Usuń</button>
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    </section>

    <datalist id="category-parent-options">
        @foreach ($categories as $category)
            <option
                value="{{ $category->path ?: $category->name }}{{ $category->salesChannel ? ' | ' . $category->salesChannel->code : '' }}"
                data-external-id="{{ $category->external_id }}"
                data-channel="{{ $category->sales_channel_id ?: 'global' }}"
            ></option>
        @endforeach
    </datalist>
@endsection

@push('scripts')
    <script src="https://unpkg.com/sortable-tree/dist/sortable-tree.js"></script>
    <script>
        (() => {
            const parentOptions = Array.from(document.querySelectorAll('#category-parent-options option'));
            const treeElement = document.getElementById('category-sortable-tree');
            const treeDataElement = document.getElementById('category-tree-data');
            const treeStatusElement = document.querySelector('[data-category-tree-status]');
            const csrfToken = @json(csrf_token());
            const categorySortUrl = @json(route('products.categories.sort'));
            const SortableTreeClass = window.SortableTree?.default || window.SortableTree;

            document.querySelectorAll('[data-gpc-select]').forEach((select) => {
                const syncLabel = () => {
                    const target = document.getElementById(select.dataset.gpcLabelTarget || '');
                    const option = select.options[select.selectedIndex];

                    if (target) target.value = option?.dataset.label || '';
                };

                select.addEventListener('change', syncLabel);
                syncLabel();
            });

            function escapeHtml(value) {
                return String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }

            function syncParentLookup(input) {
                const hidden = document.getElementById(input.dataset.categoryParentHidden || '');

                if (!hidden) {
                    return;
                }

                const match = parentOptions.find((option) => option.value === input.value);
                const channelSelect = input.closest('form')?.querySelector('[name="sales_channel_id"]');
                const expectedChannel = channelSelect ? (channelSelect.value || 'global') : (input.dataset.currentChannel || '');

                if (!input.value.trim()) {
                    hidden.value = '';
                    return;
                }

                if (match
                    && match.dataset.externalId !== input.dataset.currentCategory
                    && (!expectedChannel || match.dataset.channel === expectedChannel)
                ) {
                    hidden.value = match.dataset.externalId || '';
                    return;
                }

                hidden.value = '';
            }

            function updateTreeStatus(message) {
                if (treeStatusElement) {
                    treeStatusElement.textContent = message;
                }
            }

            function showCategoryEditor(categoryId, selectedLabel = null) {
                document.querySelector('[data-category-editor-placeholder]')?.setAttribute('hidden', 'hidden');
                document.querySelectorAll('[data-category-editor]').forEach((panel) => {
                    panel.hidden = panel.dataset.categoryEditor !== String(categoryId);
                });
                document.querySelectorAll('.category-tree-root .tree__label.is-selected').forEach((label) => {
                    label.classList.remove('is-selected');
                });
                selectedLabel?.classList.add('is-selected');
            }

            function directSiblingData(nodes, targetParentNode) {
                if (Array.isArray(targetParentNode?.subnodesData)) {
                    return targetParentNode.subnodesData;
                }

                return Array.isArray(nodes)
                    ? nodes.map((node) => node.data || node.dataset || node).filter((data) => data?.id)
                    : [];
            }

            function buildMoveItems(nodes, movedNode, targetParentNode) {
                const targetParentExternalId = targetParentNode?.data?.externalId || '';
                const siblings = directSiblingData(nodes, targetParentNode);
                const items = siblings
                    .filter((data) => data?.id)
                    .map((data, index) => ({
                        id: data.id,
                        parent_external_id: targetParentExternalId,
                        sort_order: (index + 1) * 10,
                    }));

                if (items.length > 0) {
                    return items;
                }

                return [{
                    id: movedNode.data.id,
                    parent_external_id: targetParentExternalId,
                    sort_order: Number(movedNode.data.sortOrder || 100),
                }];
            }

            function syncMoveInputs(items) {
                items.forEach((item) => {
                    const parentInput = document.getElementById(`category-parent-${item.id}`);
                    const sortInput = document.getElementById(`category-sort-order-${item.id}`);

                    if (parentInput) {
                        parentInput.value = item.parent_external_id || '';
                    }

                    if (sortInput) {
                        sortInput.value = item.sort_order;
                    }
                });
            }

            async function submitCategoryTreeChange({ nodes, movedNode, targetParentNode }) {
                const items = buildMoveItems(nodes, movedNode, targetParentNode);

                if (!items.length) {
                    return;
                }

                syncMoveInputs(items);
                updateTreeStatus('Zapisywanie układu...');

                try {
                    const response = await fetch(categorySortUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ items }),
                    });

                    if (!response.ok) {
                        throw new Error('Tree save failed');
                    }

                    updateTreeStatus('Układ zapisany.');
                } catch (error) {
                    window.alert('Nie udało się zapisać układu kategorii. Strona zostanie odświeżona.');
                    window.location.reload();
                }
            }

            document.querySelectorAll('[data-category-parent-lookup]').forEach((input) => {
                ['input', 'change'].forEach((eventName) => input.addEventListener(eventName, () => syncParentLookup(input)));
            });
            document.querySelectorAll('.category-create-form, .category-editor-form').forEach((form) => {
                form.querySelector('[name="sales_channel_id"]')?.addEventListener('change', () => {
                    const parentInput = form.querySelector('[data-category-parent-lookup]');

                    if (parentInput) {
                        syncParentLookup(parentInput);
                    }
                });
            });

            if (treeElement && SortableTreeClass) {
                const nodes = JSON.parse(treeDataElement?.textContent || '[]');

                if (nodes.length > 0) {
                    new SortableTreeClass({
                        nodes,
                        element: treeElement,
                        lockRootLevel: false,
                        initCollapseLevel: 1,
                        icons: {
                            collapsed: '+',
                            open: '-',
                        },
                        renderLabel: (data) => `
                            <span class="category-node-label">
                                <span class="category-node-name">${escapeHtml(data.name)}</span>
                            </span>
                        `,
                        confirm: async (movedNode, targetParentNode) => {
                            if (targetParentNode?.data && movedNode.data.channel !== targetParentNode.data.channel) {
                                window.alert('Kategorię można przeciągnąć tylko w obrębie tego samego kanału.');
                                return false;
                            }

                            return true;
                        },
                        onChange: async (result) => {
                            await submitCategoryTreeChange(result);
                        },
                        onClick: async (event, node) => {
                            showCategoryEditor(node.data.id, node.label);
                        },
                    });
                }
            } else if (treeElement) {
                treeElement.innerHTML = '<div class="category-tree-empty">Nie udało się załadować drzewa kategorii. Odśwież stronę i spróbuj ponownie.</div>';
            }
        })();
    </script>
@endpush
