@php
    $supplierMaster = isset($supplierMaster) && is_array($supplierMaster) ? $supplierMaster : [];
    $storedSuppliers = collect((array) data_get($supplierMaster, 'suppliers', []))->values();
    $oldNames = old('suppliers.name');
    $supplierRows = collect(range(0, max(2, $storedSuppliers->count() - 1)))->map(function (int $index) use ($storedSuppliers, $oldNames): array {
        $stored = (array) $storedSuppliers->get($index, []);

        return [
            'name' => is_array($oldNames) ? ($oldNames[$index] ?? null) : ($stored['name'] ?? null),
            'product_code' => is_array($oldNames) ? old("suppliers.product_code.{$index}") : ($stored['product_code'] ?? null),
            'purchase_price_pln' => is_array($oldNames) ? old("suppliers.purchase_price_pln.{$index}") : ($stored['purchase_price_pln'] ?? null),
        ];
    });
@endphp

<div class="product-form-section">
    <h4>Dostawcy</h4>
    <p class="muted">Możesz zapisać kilku dostawców wraz z ich kodem produktu i ceną zakupu.</p>
    @foreach ($supplierRows as $supplier)
        <div class="product-form-grid three">
            <label>Nazwa dostawcy
                <input name="suppliers[name][]" value="{{ $supplier['name'] }}">
            </label>
            <label>Kod produktu dostawcy
                <input name="suppliers[product_code][]" value="{{ $supplier['product_code'] }}">
            </label>
            <label>Cena zakupu u dostawcy
                <input name="suppliers[purchase_price_pln][]" type="number" step="0.01" min="0" value="{{ $supplier['purchase_price_pln'] }}">
            </label>
        </div>
    @endforeach
</div>
