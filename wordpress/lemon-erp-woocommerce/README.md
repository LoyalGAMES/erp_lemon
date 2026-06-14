# Lemon ERP for WooCommerce

Wtyczka dodaje do WooCommerce warstwę integracyjną wymaganą przez Lemon ERP:

- pola checkoutu: typ klienta oraz NIP,
- panel `Lemon ERP` na zamówieniu WooCommerce,
- REST endpoint do zapisu danych faktury na zamówieniu,
- opcjonalny zapis PDF poza WordPress Media Library.

## Instalacja

1. W Lemon ERP przejdź do `Integracje`.
2. Pobierz ZIP z karty `Wtyczka Lemon ERP for WooCommerce`.
3. W WordPress przejdź do `Wtyczki -> Dodaj nową -> Wyślij wtyczkę`.
4. Aktywuj `Lemon ERP for WooCommerce`.
5. Upewnij się, że użytkownik WordPress używany w Lemon ERP ma uprawnienia do edycji zamówień WooCommerce.
6. W Lemon ERP w integracji WooCommerce ustaw tryb faktur na `Wtyczka Lemon ERP bez Media Library`.

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

Faktury zapisują się pod prefiksem:

- `_sempre_erp_invoice_` dla faktury sprzedaży,
- `_sempre_erp_correction_invoice_` dla korekty.
