@php
    $fieldName = $fieldName ?? 'variant_attribute';
    $selectedValue = (string) old($fieldName, $value ?? '');
    $variantAttributeOptions = collect($parameterOptions ?? [])
        ->filter(fn ($parameter): bool => is_array($parameter))
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
        <option value="{{ $option }}" @selected($selectedValue === $option)>{{ $option }}</option>
    @endforeach
</select>
