@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => 'product-categories',
])

@push('styles')
    <style>
        .product-config-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .product-config-panel { margin-bottom: 16px; }
        .category-create-form { padding: 16px; display: grid; grid-template-columns: minmax(180px, .7fr) minmax(220px, 1fr) minmax(260px, 1fr) auto; gap: 12px; align-items: end; }
        .category-create-form .full { grid-column: 1 / -2; }
        .category-tree-shell { display: grid; gap: 12px; padding: 14px; }
        .category-root-drop { border: 1px dashed var(--border); border-radius: 8px; padding: 10px 12px; color: var(--muted); background: #fffdfb; }
        .category-root-drop.drag-over, .category-tree-card.drag-over { border-color: var(--green-dark); background: var(--green-soft); }
        .category-tree-list { list-style: none; display: grid; gap: 10px; padding: 0; margin: 0; }
        .category-tree-item .category-tree-list { margin-top: 10px; margin-left: min(28px, calc(var(--category-depth, 0) * 16px + 12px)); }
        .category-tree-card { border: 1px solid var(--border); border-radius: 8px; background: #fff; padding: 12px; display: grid; gap: 10px; }
        .category-tree-main { display: grid; grid-template-columns: auto minmax(260px, 1fr) minmax(280px, 1fr) auto; gap: 12px; align-items: start; }
        .category-tree-fields, .category-tree-description, .category-technical-grid { display: grid; gap: 10px; }
        .category-tree-description textarea { min-height: 80px; resize: vertical; }
        .category-tree-actions { display: grid; gap: 8px; justify-items: end; min-width: 120px; }
        .category-drag-handle { width: 34px; height: 34px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; cursor: grab; color: var(--muted); font-weight: 850; }
        .category-tree-item.dragging { opacity: .55; }
        .category-tree-path { display: flex; gap: 8px; flex-wrap: wrap; color: var(--muted); font-size: 13px; }
        .category-tree-path span { border: 1px solid var(--border); border-radius: 999px; padding: 3px 8px; background: #fffdfb; }
        .category-technical-details { border-top: 1px solid var(--border); padding-top: 8px; }
        .category-technical-details summary { cursor: pointer; color: var(--muted); font-weight: 700; }
        .category-technical-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 10px; }
        @media (max-width: 1080px) {
            .category-create-form, .category-tree-main, .category-technical-grid { grid-template-columns: 1fr; }
            .category-create-form .full { grid-column: 1 / -1; }
            .category-tree-actions { justify-items: start; }
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
        $rootCategories = $childrenByParentKey->get('__root__', collect())->sortBy('name');
    @endphp

    <div class="product-config-toolbar">
        <div class="inline-actions">
            <a class="button secondary" href="{{ route('products.index') }}">Lista produktów</a>
            <a class="button secondary" href="{{ route('products.parameters.index') }}">Parametry</a>
        </div>
        <div class="toolbar-note">Przeciągnij kategorię na inną kategorię, aby zmienić nadrzędną. Upuść na górny obszar, aby przenieść ją na poziom główny.</div>
    </div>

    <section class="card product-config-panel">
        <div class="panel-header">Dodaj kategorię do drzewa</div>
        <form class="category-create-form" method="POST" action="{{ route('products.categories.store') }}">
            @csrf
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
    </section>

    <section class="card">
        <div class="panel-header">
            <span>Drzewo kategorii PIM</span>
            <span>{{ $categories->count() }} pozycji</span>
        </div>
        <div class="category-tree-shell">
            <div class="category-root-drop" data-category-root-drop>Upuść tutaj, aby przenieść kategorię na poziom główny</div>
            <ol class="category-tree-list">
                @forelse ($rootCategories as $category)
                    @include('products.configuration._category_tree_node', [
                        'category' => $category,
                        'childrenByParentKey' => $childrenByParentKey,
                        'categoryByScopedExternalId' => $categoryByScopedExternalId,
                        'categoryKey' => $categoryKey,
                        'categoryUsage' => $categoryUsage,
                        'salesChannels' => $salesChannels,
                        'depth' => 0,
                    ])
                @empty
                    <li class="toolbar-note">Brak kategorii. Dodaj pierwszą kategorię formularzem powyżej albo zaimportuj produkty z WooCommerce.</li>
                @endforelse
            </ol>
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
    <script>
        (() => {
            const parentOptions = Array.from(document.querySelectorAll('#category-parent-options option'));

            function syncParentLookup(input) {
                const hidden = document.getElementById(input.dataset.categoryParentHidden || '');

                if (!hidden) {
                    return;
                }

                const match = parentOptions.find((option) => option.value === input.value);

                if (!input.value.trim()) {
                    hidden.value = '';
                    return;
                }

                if (match && match.dataset.externalId !== input.dataset.currentCategory) {
                    hidden.value = match.dataset.externalId || '';
                }
            }

            document.querySelectorAll('[data-category-parent-lookup]').forEach((input) => {
                ['input', 'change'].forEach((eventName) => input.addEventListener(eventName, () => syncParentLookup(input)));
            });

            let draggedNode = null;

            document.querySelectorAll('[data-category-node]').forEach((node) => {
                const handle = node.querySelector('.category-drag-handle');
                const card = node.querySelector('.category-tree-card');

                handle?.addEventListener('dragstart', (event) => {
                    draggedNode = node;
                    node.classList.add('dragging');
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', node.dataset.categoryExternalId || '');
                });

                handle?.addEventListener('dragend', () => {
                    node.classList.remove('dragging');
                    document.querySelectorAll('.drag-over').forEach((item) => item.classList.remove('drag-over'));
                    draggedNode = null;
                });

                card?.addEventListener('dragover', (event) => {
                    if (draggedNode && draggedNode !== node) {
                        event.preventDefault();
                        event.stopPropagation();
                        card.classList.add('drag-over');
                    }
                });

                card?.addEventListener('dragleave', () => {
                    card.classList.remove('drag-over');
                });

                card?.addEventListener('drop', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    card.classList.remove('drag-over');
                    moveDraggedCategory(node);
                });
            });

            document.querySelector('[data-category-root-drop]')?.addEventListener('dragover', (event) => {
                if (draggedNode) {
                    event.preventDefault();
                    event.currentTarget.classList.add('drag-over');
                }
            });

            document.querySelector('[data-category-root-drop]')?.addEventListener('dragleave', (event) => {
                event.currentTarget.classList.remove('drag-over');
            });

            document.querySelector('[data-category-root-drop]')?.addEventListener('drop', (event) => {
                event.preventDefault();
                event.currentTarget.classList.remove('drag-over');
                moveDraggedCategory(null);
            });

            function moveDraggedCategory(targetNode) {
                if (!draggedNode) {
                    return;
                }

                if (targetNode && draggedNode.dataset.categoryId === targetNode.dataset.categoryId) {
                    return;
                }

                if (targetNode && draggedNode.contains(targetNode)) {
                    window.alert('Nie można przenieść kategorii pod jej własną podkategorię.');
                    return;
                }

                if (targetNode && draggedNode.dataset.categoryChannel !== targetNode.dataset.categoryChannel) {
                    window.alert('Kategorię można przeciągnąć tylko w obrębie tego samego kanału.');
                    return;
                }

                const hidden = draggedNode.querySelector('[name="parent_external_id"]');
                const form = draggedNode.querySelector('form[id^="category-update-"]');

                if (!hidden || !form) {
                    return;
                }

                hidden.value = targetNode?.dataset.categoryExternalId || '';

                if (window.confirm('Zapisać nowe położenie kategorii w drzewie?')) {
                    form.requestSubmit ? form.requestSubmit() : form.submit();
                }
            }
        })();
    </script>
@endpush
