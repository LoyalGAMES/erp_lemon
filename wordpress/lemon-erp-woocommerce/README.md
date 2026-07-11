# Lemon ERP for WooCommerce

Wtyczka dodaje do WooCommerce warstwę integracyjną wymaganą przez Lemon ERP:

- pola checkoutu w sekcji adresu: typ klienta, nazwa firmy oraz NIP dla zamówień firmowych,
- panel `Lemon ERP` na zamówieniu WooCommerce,
- jednoznaczny kontrakt tożsamości produktów, wariantów i kategorii dla sklepów z Polylang,
- REST endpoint do zapisu danych faktury na zamówieniu,
- opcjonalny zapis PDF poza WordPress Media Library.

## Instalacja

1. W Lemon ERP przejdź do `Integracje`.
2. Pobierz ZIP z karty `Wtyczka Lemon ERP for WooCommerce`.
3. W WordPress przejdź do `Wtyczki -> Dodaj nową -> Wyślij wtyczkę`.
4. Aktywuj `Lemon ERP for WooCommerce`.
5. Upewnij się, że użytkownik WordPress używany w Lemon ERP ma uprawnienia do edycji zamówień WooCommerce.
6. W Lemon ERP w integracji WooCommerce ustaw tryb faktur na `Wtyczka Lemon ERP bez Media Library`.

Przy aktualizacji wgraj nowy ZIP i zezwól WordPressowi na zastąpienie poprzedniej wersji. Kontrakt katalogu wymaga wersji wtyczki co najmniej `0.2.0`.

## Kontrakt katalogu

Wtyczka rozszerza oficjalne odpowiedzi WooCommerce REST dla produktów, wariantów i kategorii o pola:

- `lemon_erp_catalog_contract` - numer kontraktu, obecnie `1`,
- `lemon_erp_language` - kod języka Polylang, np. `pl` albo `en`,
- `lemon_erp_translations` - mapa `język => ID` zawierająca wyłącznie tłumaczenia tego samego rodzaju zasobu,
- `lemon_erp_translation_group` - stabilny identyfikator rodziny, np. `product:700143|750099`.

Jeżeli Polylang nie jest aktywny albo zasób nie ma przypisanego języka, mapa zawiera bieżące ID pod kluczem `default`, a `lemon_erp_language` ma wartość `null`.

Przykład produktu:

```json
{
  "id": 700143,
  "sku": "BLS6A4FE375DAA5D",
  "lemon_erp_catalog_contract": 1,
  "lemon_erp_language": "pl",
  "lemon_erp_translations": {
    "en": 750099,
    "pl": 700143
  },
  "lemon_erp_translation_group": "product:700143|750099"
}
```

Dla wariantu `lemon_erp_translations` zawiera tylko ID wariantów i grupa ma prefiks `variation:`. Identyfikatory rodziców są udostępnione osobno w `lemon_erp_parent_translations` i `lemon_erp_parent_translation_group`, dzięki czemu produkt główny nigdy nie zostanie połączony z wariantem.

Jeżeli Polylang nie udostępnia bezpośredniej relacji między wariantami, wtyczka szuka odpowiednika wyłącznie w przetłumaczonym produkcie głównym: najpierw po identycznym SKU, a następnie po identycznym zestawie atrybutów. Niejednoznaczny wynik nie jest automatycznie łączony.

Stan kontraktu można sprawdzić przez uwierzytelniony endpoint:

```text
GET /wp-json/lemon-erp/v1/catalog/capabilities
```

Odpowiedź zawiera `catalog_contract`, `plugin_version`, `polylang_active`, aktywne języki i nazwy pól kontraktu. Dostęp wymaga uprawnienia `manage_woocommerce` albo `edit_products`.

## Endpoint ERP

Lemon ERP wysyła fakturę do:

```text
POST /wp-json/lemon-erp/v1/orders/{order_id}/invoice
```

Endpoint zapisuje metadane faktury w zamówieniu WooCommerce. Jeżeli ERP przekaże `file_base64`, PDF zostanie zapisany w `wp-content/uploads/lemon-erp-invoices`, ale nie zostanie dodany do Media Library.

## Meta pola

Checkout zapisuje:

- `_lemon_erp_customer_type` - `private` albo `company`,
- `_lemon_erp_billing_nip` - NIP,
- `_billing_nip` - kompatybilność z innymi integracjami.

W Checkout Blocks pola są rejestrowane jako pola adresowe, żeby nie tworzyć osobnego kroku `Dodatkowe informacje o zamówieniu`. Nazwa firmy oraz NIP są wymagane i widoczne tylko po wyborze `Firma`.

Faktury zapisują się pod prefiksem:

- `_sempre_erp_invoice_` dla faktury sprzedaży,
- `_sempre_erp_correction_invoice_` dla korekty.
