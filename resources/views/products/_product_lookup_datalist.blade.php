<datalist id="{{ $id ?? 'product-lookup-options' }}">
    @foreach ($productLookupOptions as $lookupProduct)
        <option value="{{ $lookupProduct['label'] }}" data-sku="{{ $lookupProduct['sku'] }}">{{ $lookupProduct['sku'] }}</option>
    @endforeach
</datalist>
