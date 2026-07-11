@php
    $variantProduct = $variantProduct ?? null;
    $selectedVariantAttribute = (string) old('variant_attribute', $selectedVariantAttribute ?? data_get($variantProduct?->masterData() ?? [], 'variant_attribute', ''));
    $variantValueDefinitions = collect($parameterOptions ?? [])
        ->filter(fn ($parameter): bool => is_array($parameter) && filled($parameter['name'] ?? null))
        ->mapWithKeys(fn (array $parameter): array => [
            (string) $parameter['name'] => collect((array) ($parameter['values'] ?? []))
                ->map(fn ($value): string => trim((string) $value))
                ->filter()
                ->unique(fn (string $value): string => mb_strtolower($value))
                ->values()
                ->all(),
        ])
        ->all();
    $existingVariantValues = $variantProduct && $variantProduct->relationLoaded('variantChildren')
        ? $variantProduct->variantChildren
            ->flatMap(function ($variant) use ($selectedVariantAttribute): array {
                return collect((array) data_get($variant->masterData(), 'parameters', []))
                    ->filter(fn ($parameter): bool => is_array($parameter))
                    ->filter(fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? ''))) === mb_strtolower($selectedVariantAttribute))
                    ->pluck('value')
                    ->map(fn ($value): string => trim((string) $value))
                    ->filter()
                    ->all();
            })
            ->unique(fn (string $value): string => mb_strtolower($value))
            ->values()
            ->all()
        : [];
    $selectedNewVariantValues = collect((array) old('new_variant_values', []))->map(fn ($value): string => (string) $value)->all();
@endphp

@once
    @push('styles')
        <style>
            .new-variant-values { display: grid; grid-template-columns: minmax(220px, 1fr) minmax(220px, 1fr); gap: 12px; padding: 14px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
            .new-variant-values .full { grid-column: 1 / -1; }
            .new-variant-values select { min-height: 128px; }
            @media (max-width: 720px) { .new-variant-values { grid-template-columns: 1fr; } .new-variant-values .full { grid-column: auto; } }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                document.querySelectorAll('[data-new-variant-values]').forEach((container) => {
                    const form = container.closest('form');
                    const attributeSelect = form?.querySelector('[data-variant-attribute-select]');
                    const valueSelect = container.querySelector('[data-new-variant-value-select]');
                    const definitions = JSON.parse(container.dataset.variantDefinitions || '{}');
                    const existing = new Set(JSON.parse(container.dataset.existingVariantValues || '[]').map((value) => String(value).toLocaleLowerCase()));

                    if (!attributeSelect || !valueSelect) return;

                    const render = () => {
                        const selected = new Set(Array.from(valueSelect.selectedOptions).map((option) => option.value));
                        const values = definitions[attributeSelect.value] || [];
                        valueSelect.replaceChildren();

                        values.forEach((value) => {
                            if (existing.has(String(value).toLocaleLowerCase())) return;
                            const option = document.createElement('option');
                            option.value = value;
                            option.textContent = value;
                            option.selected = selected.has(value);
                            valueSelect.append(option);
                        });
                    };

                    attributeSelect.addEventListener('change', render);
                    render();
                });
            })();
        </script>
    @endpush
@endonce

<div
    class="new-variant-values"
    data-new-variant-values
    data-variant-definitions="{{ json_encode($variantValueDefinitions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
    data-existing-variant-values="{{ json_encode($existingVariantValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}"
>
    <div class="full">
        <strong>Utwórz nowe warianty z wartości atrybutu</strong>
        <div class="toolbar-note">Zaznacz np. S, M i L. Przy zapisie ERP utworzy osobne warianty, nada im SKU oraz EAN i połączy je z tym produktem.</div>
    </div>
    <label>Wartości ze słownika
        <select name="new_variant_values[]" multiple data-new-variant-value-select>
            @foreach ((array) ($variantValueDefinitions[$selectedVariantAttribute] ?? []) as $variantValue)
                @if (! in_array(mb_strtolower($variantValue), array_map('mb_strtolower', $existingVariantValues), true))
                    <option value="{{ $variantValue }}" @selected(in_array($variantValue, $selectedNewVariantValues, true))>{{ $variantValue }}</option>
                @endif
            @endforeach
        </select>
    </label>
    <label>Własne wartości
        <textarea name="new_variant_values_custom" placeholder="Jedna wartość w wierszu, np.&#10;XS&#10;S/M">{{ old('new_variant_values_custom') }}</textarea>
    </label>
</div>
