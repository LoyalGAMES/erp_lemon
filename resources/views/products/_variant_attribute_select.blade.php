@php
    $fieldName = $fieldName ?? 'variant_attribute';
    $selectedValue = (string) old($fieldName, $value ?? '');
    $variantAttributeRows = collect($parameterOptions ?? [])
        ->filter(fn ($parameter): bool => is_array($parameter))
        ->values();
    $canonicalSelection = $variantAttributeRows->first(function (array $parameter) use ($selectedValue): bool {
        return collect((array) ($parameter['canonicalized_aliases'] ?? []))
            ->contains(fn ($alias): bool => mb_strtolower(trim((string) $alias)) === mb_strtolower(trim($selectedValue)));
    });

    if (is_array($canonicalSelection) && filled($canonicalSelection['name'] ?? null)) {
        $selectedValue = (string) $canonicalSelection['name'];
    }

    $variantAttributeOptions = $variantAttributeRows
        ->sortByDesc(fn (array $parameter): bool => (bool) ($parameter['is_variant'] ?? false))
        ->pluck('name')
        ->merge(['Rozmiar', 'Kolor', 'Materiał'])
        ->when($selectedValue !== '', fn ($items) => $items->push($selectedValue))
        ->filter()
        ->unique(fn (string $name): string => mb_strtolower($name))
        ->values();
@endphp

<select name="{{ $fieldName }}" data-variant-attribute-select>
    <option value="">Wybierz ze słownika parametrów</option>
    @foreach ($variantAttributeOptions as $option)
        <option value="{{ $option }}" @selected(mb_strtolower($selectedValue) === mb_strtolower($option))>{{ $option }}</option>
    @endforeach
</select>
