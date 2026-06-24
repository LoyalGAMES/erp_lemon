<datalist id="{{ $nameId ?? 'product-parameter-name-options' }}">
    @foreach ($parameterOptions as $parameterOption)
        <option value="{{ $parameterOption['name'] }}">{{ $parameterOption['is_variant'] ? 'Wariant · ' : '' }}{{ implode(', ', array_slice((array) $parameterOption['values'], 0, 6)) }}</option>
    @endforeach
</datalist>

<datalist id="{{ $valueId ?? 'product-parameter-value-options' }}">
    @foreach ($parameterOptions as $parameterOption)
        @foreach ((array) $parameterOption['values'] as $parameterValue)
            <option value="{{ $parameterValue }}">{{ $parameterOption['name'] }}</option>
        @endforeach
    @endforeach
</datalist>
