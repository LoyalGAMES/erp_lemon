# Lemon Woo Returns

Modul WordPress/WooCommerce do przyjmowania zgloszen zwrotow przez klientow zalogowanych i niezalogowanych. Formularz dziala jako shortcode `[ll_return_form]` oraz widget Elementora `Formularz zwrotu`.

## Przeplyw

1. Klient podaje numer zamowienia oraz e-mail albo telefon.
2. Formularz pokazuje produkty z zamowienia i pozwala wybrac pozycje, ilosc oraz powod zwrotu.
3. Klient wybiera forme odeslania: wlasna przesylka albo Wygodne Zwroty.
4. Zgloszenie zwrotu jest najpierw trwale zapisywane lokalnie, a nastepnie wysylane do ERP. Po sukcesie klient dostaje numer zgloszenia. Dla Wygodnych Zwrotow pojawia sie link do nadania.
5. Status zgloszenia jest synchronizowany z ERP. Dopiero status finalny, np. `Zwrot zrealizowany`, tworzy natywny zwrot WooCommerce na pozycjach zgloszenia.

## Instalacja

Wgraj katalog `lemon-woo-returns` do `wp-content/plugins/` i wlacz wtyczke w WordPressie.

Panel ustawien: `WooCommerce -> Ustawienia zwrotow`.

Rejestr zgloszen: `WooCommerce -> Zgloszenia zwrotow`.

## ERP

Wtyczka obsluguje dwa endpointy JSON:

- `Endpoint wyszukiwania zamowienia`
- `Endpoint tworzenia zwrotu`
- `Endpoint statusu zwrotu`

Token z ustawien jest wysylany naglowkami `Authorization: Bearer ...` oraz `X-API-Key`.

### Wyszukiwanie zamowienia

Request:

```json
{
  "order_reference": "12345",
  "contact": "klient@example.com",
  "site_url": "https://example.com"
}
```

Minimalna odpowiedz:

```json
{
  "order": {
    "source": "erp",
    "order_id": "ERP-12345",
    "order_reference": "12345",
    "order_number": "12345",
    "currency": "PLN",
    "customer_email": "klient@example.com",
    "customer_phone": "+48123123123",
    "items": [
      {
        "id": "line-1",
        "name": "Produkt",
        "sku": "SKU-1",
        "quantity": 1,
        "image": "https://example.com/product.jpg",
        "price": 199.99
      }
    ]
  }
}
```

Bez endpointu wyszukiwania wtyczka uzywa lokalnych zamowien WooCommerce.

### Tworzenie zwrotu

Wysylany payload zawiera m.in.:

```json
{
  "return_reference": "LLR-20260707-ABC123",
  "local_return_id": 123,
  "order_reference": "12345",
  "order_number": "12345",
  "return_method": "wygodne_zwroty",
  "customer_contact": "klient@example.com",
  "items": [
    {
      "id": "line-1",
      "name": "Produkt",
      "sku": "SKU-1",
      "quantity": 1,
      "reason": "wrong_size"
    }
  ]
}
```

Oczekiwana odpowiedz sukcesu:

```json
{
  "success": true,
  "external_id": "ERP-RETURN-123",
  "status": "pending_package"
}
```

Jesli odpowiedz od razu zawiera status finalny, np. `Zwrot zrealizowany`, wtyczka od razu utworzy natywny refund WooCommerce.

### Synchronizacja statusu

Cron odpytuje endpoint statusu co 15 minut dla zgloszen oczekujacych na finalny status.

Request:

```json
{
  "return_reference": "LLR-20260707-ABC123",
  "local_return_id": 123,
  "order_reference": "12345",
  "external_id": "ERP-RETURN-123",
  "site_url": "https://example.com"
}
```

Odpowiedz:

```json
{
  "success": true,
  "external_id": "ERP-RETURN-123",
  "status": "Zwrot zrealizowany"
}
```

ERP moze tez wypchnac status webhookiem:

`POST /wp-json/lemon-returns/v1/status`

Naglowek autoryzacyjny:

`X-Lemon-Returns-Token: <token webhooka>` albo `Authorization: Bearer <token webhooka>`.

Payload webhooka:

```json
{
  "return_reference": "LLR-20260707-ABC123",
  "external_id": "ERP-RETURN-123",
  "status": "Zwrot zrealizowany"
}
```

Statusy finalne konfiguruje sie w panelu ustawien. Domyslnie finalne sa m.in. `Zwrot zrealizowany`, `zrealizowany`, `completed`, `realized`, `return_completed`.

Jesli endpoint tworzenia zwrotu nie jest ustawiony albo ERP jest chwilowo niedostepne, wtyczka zachowuje zgloszenie w panelu `Zgloszenia zwrotow`. Panel ustawien pokazuje wtedy ostrzezenie. Po poprawieniu konfiguracji wtyczka automatycznie ponawia endpoint tworzenia zwrotu z tym samym `return_reference`, wiec istniejace lokalne zgloszenia sa bezpiecznie uzupelniane w ERP bez duplikatow. Przycisk `Wyslij oczekujace i synchronizuj z ERP teraz` uruchamia te probe od razu.

Status biznesowy jest synchronizowany dopiero po skutecznym utworzeniu zgloszenia w ERP. Rekordy w trybie `local`, `submitting` i `erp_failed` sa najpierw wysylane ponownie do endpointu tworzenia, a nie do endpointu statusu.

## WooCommerce refund

Po statusie finalnym z ERP wtyczka:

- tworzy natywny refund WooCommerce przez `wc_create_refund`,
- refunduje tylko pozycje i ilosci z wybranego zgloszenia,
- nie uruchamia zwrotu platnosci przez bramke (`refund_payment=false`),
- opcjonalnie przywraca stany magazynowe,
- opcjonalnie ustawia status zamowienia WooCommerce na `refunded`.

Do utworzenia natywnego refundu payload zgloszenia musi zawierac lokalny `order_id` WooCommerce oraz identyfikatory pozycji zamowienia WooCommerce w polu `items[].id`. Fallback WooCommerce robi to automatycznie; przy wyszukiwaniu zamowienia przez ERP endpoint powinien zwrocic te lokalne identyfikatory.

## Filtry dla niestandardowej integracji

- `ll_returns_erp_lookup_order`
- `ll_returns_erp_create_return`
- `ll_returns_erp_get_return_status`
- `ll_returns_erp_status_payload`
- `ll_returns_erp_return_payload`
- `ll_returns_erp_status_map`
- `ll_returns_find_woocommerce_order`
- `ll_returns_return_reasons`
