@if ($storefrontHidden)
    <form method="POST" action="{{ route('products.storefront.reveal', $storefrontProduct) }}" onsubmit="return confirm('Odkryć produkt i wszystkie jego warianty w sklepie? Wrócą do katalogu, wyszukiwarki, kategorii i rekomendacji, ale stan w WooCommerce pozostanie równy 0 do ręcznego potwierdzenia.');">
        @csrf
        <button class="button secondary" type="submit">Odkryj produkt</button>
    </form>
@else
    <form method="POST" action="{{ route('products.storefront.hide', $storefrontProduct) }}" onsubmit="return confirm('Ukryć produkt i wszystkie jego warianty w katalogu, wyszukiwarce, kategoriach i rekomendacjach oraz ustawić ich stan w WooCommerce na 0? Bezpośredni link pozostanie aktywny.');">
        @csrf
        <button class="button secondary" type="submit">Ukryj produkt</button>
    </form>
@endif

@if ($stockVerificationRequired && ! $storefrontHidden)
    <form method="POST" action="{{ route('products.storefront.verify-stock', $storefrontProduct) }}" onsubmit="return confirm('Potwierdzasz ręczną weryfikację stanu całej rodziny produktu? Aktualny stan ERP zostanie wysłany do WooCommerce.');">
        @csrf
        <button class="button secondary" type="submit">Potwierdź stan</button>
    </form>
@endif
