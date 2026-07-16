# Lemon ERP for WooCommerce

Wtyczka dodaje do WooCommerce warstwę integracyjną wymaganą przez Lemon ERP:

- pola checkoutu w sekcji adresu: typ klienta, nazwa firmy oraz NIP dla zamówień firmowych,
- panel `Lemon ERP` na zamówieniu WooCommerce,
- jednoznaczny kontrakt tożsamości produktów, wariantów i kategorii dla sklepów z Polylang,
- idempotentne wiązanie polskich i angielskich kategorii utworzonych przez ERP,
- idempotentne przypisywanie języków i wiązanie tłumaczeń produktów utworzonych przez ERP,
- idempotentne wiązanie wariantów PL/EN po sprawdzeniu ich przetłumaczonych produktów nadrzędnych,
- bezpieczne współdzielenie SKU i globalnego GTIN/EAN wyłącznie przez zweryfikowane rodzeństwo tłumaczeń Polylang,
- idempotentne wiązanie osobnych terminów PL/EN globalnych atrybutów WooCommerce,
- automatyczne wymuszenie obsługi języków Polylang dla wszystkich globalnych atrybutów WooCommerce (`pa_*`),
- ustawianie daty publikacji na produktach i wariantach przed ich zapisem przez WooCommerce REST,
- stabilna kolejność wartości globalnych atrybutów wariantowych na listach i kartach produktów,
- natychmiastowe, asynchroniczne powiadomienia ERP o utworzeniu lub zmianie konta klienta,
- REST endpoint do zapisu danych faktury na zamówieniu,
- opcjonalny zapis PDF poza WordPress Media Library.

## Instalacja

1. W Lemon ERP przejdź do `Integracje`.
2. Pobierz ZIP z karty `Wtyczka Lemon ERP for WooCommerce`.
3. W WordPress przejdź do `Wtyczki -> Dodaj nową -> Wyślij wtyczkę`.
4. Aktywuj `Lemon ERP for WooCommerce`.
5. Upewnij się, że użytkownik WordPress używany w Lemon ERP ma uprawnienia do edycji zamówień WooCommerce.
6. W Lemon ERP w integracji WooCommerce ustaw tryb faktur na `Wtyczka Lemon ERP bez Media Library`.

Przy aktualizacji wgraj nowy ZIP i zezwól WordPressowi na zastąpienie poprzedniej wersji. Odczyt kontraktu katalogu wymaga wersji co najmniej `0.2.0`, eksport powiązanych kategorii wersji co najmniej `0.3.0`, natychmiastowa synchronizacja klientów wersji co najmniej `0.4.1`, a bezpieczne wiązanie tłumaczeń produktów i automatyczny backfill wariantów wersji co najmniej `0.5.1`. Wersja `0.5.2` rejestruje wszystkie globalne taksonomie atrybutów WooCommerce jako tłumaczone w Polylang już podczas ładowania wtyczek i ukrywa tę wymuszoną decyzję na ekranie ustawień Polylang. Przy pierwszym uruchomieniu bezpiecznie przypisuje język także istniejącym wartościom tych atrybutów: zachowuje każde wcześniejsze przypisanie, rozpoznaje deterministyczny sufiks aktywnego języka (np. `-en`), a pozostałym wartościom nadaje domyślny język sklepu. Ten wznawialny bootstrap nie łączy samodzielnie par tłumaczeń; robi to dopiero ERP, mając pewne ID obu terminów. Wersja `0.5.3` dodaje osobny endpoint wiązania tłumaczeń wariantów. Przed zapisem sprawdza on całą rodzinę PL/EN, prawa edycji, aktywne języki, dokładną relację tłumaczeń produktów nadrzędnych oraz przynależność każdego wariantu do właściwego rodzica. Wersja `0.5.6` usuwa hooki modyfikujące atrybuty wariantów na storefrontcie i nie zapisuje już cache kolejności rozmiarów. Przy aktywacji albo pierwszym wejściu do panelu po aktualizacji jednorazowo usuwa historyczne transients `lemon_sizes_*` oraz unieważnia stare grupy persistent object cache `transient` i `products`. To wyłącznie adminowy purge starego stanu: wtyczka nie wymusza kolejności ani dostępności na froncie, a storefront korzysta z danych zapisanych w WooCommerce przez backend.

## Natychmiastowa synchronizacja klientów

ERP konfiguruje webhook automatycznie przez uwierzytelniony endpoint:

```text
POST /wp-json/wc-lemon-erp/v1/customer-webhook/configure
```

Przekazuje wyłącznie docelowy `delivery_url` i istniejący `consumer_key`. Wtyczka sprawdza, czy klucz należy do zalogowanego użytkownika i ma uprawnienia Odczyt/Zapis, po czym zapisuje tylko ID klucza oraz URL. Istniejący `consumer_secret` pozostaje w bazie WooCommerce i służy po obu stronach wyłącznie do wyliczenia podpisu HMAC-SHA256 — sekret nie jest wysyłany w konfiguracji ani w webhooku.

Zdarzenia `customer.created` i `customer.updated` są kolejkowane w Action Scheduler, więc rejestracja klienta nie czeka na odpowiedź ERP. Nieudane dostarczenie jest ponawiane z rosnącym opóźnieniem. Payload zawiera tylko identyfikator klienta i dane zdarzenia; pełny profil ERP pobiera przez oficjalny endpoint WooCommerce `/wc/v3/customers/{id}`.

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

ERP przypisuje języki i wiąże utworzone osobno produkty przez endpoint uwierzytelniany kluczem i sekretem WooCommerce REST:

```text
POST /wp-json/wc-lemon-erp/v1/catalog/products/translations
```

Payload `translations` jest mapą kodu języka na ID produktu, np. `{"pl": 700143, "en": 750099}`. Klucz WooCommerce musi mieć uprawnienia odczyt/zapis, a jego użytkownik uprawnienie `manage_woocommerce` oraz prawo edycji każdego wskazanego produktu. Wtyczka przed pierwszym zapisem sprawdza całą mapę, aktywne języki, typ i status wszystkich postów, uprawnienia oraz wcześniejsze rodziny tłumaczeń. Ponowienie identycznego żądania nie wykonuje kolejnych zapisów.

Warianty przetłumaczonych produktów ERP wiąże osobnym endpointem:

```text
POST /wp-json/wc-lemon-erp/v1/catalog/products/variations/translations
```

Body zawiera dwie mapy z identycznym zestawem języków, np. `{"parents":{"pl":700143,"en":750099},"translations":{"pl":700144,"en":750100}}`. Każdy element `parents` musi należeć do tej samej, już powiązanej rodziny tłumaczeń Polylang i dokładnie odpowiadać podanemu językowi. Mapa może być podzbiorem większej rodziny, np. PL/EN podczas stopniowego tworzenia wariantów produktu PL/EN/DE. Każdy element `translations` musi być wariantem odpowiadającego mu produktu nadrzędnego. Wtyczka odrzuca obce rodziny i istniejące sprzeczne przypisania języka, wykonuje zapis dopiero po pełnej walidacji i potwierdza dokładny wynik. Identyczne ponowienie nie wykonuje dodatkowych zapisów.

WooCommerce sprawdza unikalność SKU oraz pola `global_unique_id` (GTIN, UPC, EAN lub ISBN). Wtyczka zachowuje wynik tej kontroli i dopuszcza wspólną wartość tylko wtedy, gdy wszystkie znalezione duplikaty są innymi językowo członkami tej samej, obustronnie potwierdzonej rodziny Polylang oraz mają dokładnie ten sam typ (`product` albo `product_variation`). Dla wariantów również ich rodzice muszą należeć do jednej rodziny tłumaczeń. Duplikaty niepowiązane, obce i mieszające produkt z wariantem pozostają zablokowane.

Wartości jednego globalnego atrybutu ERP wiąże przez endpoint uwierzytelniany tym samym kluczem WooCommerce:

```text
POST /wp-json/wc-lemon-erp/v1/catalog/products/attributes/{attribute_id}/terms/translations
```

Body ma postać `{"translations":{"pl":301,"en":302}}`. `attribute_id` jest numerycznym ID globalnego atrybutu WooCommerce; wtyczka sama rozwiązuje i ogranicza taksonomię do konkretnego `pa_*`. Każdy język musi wskazywać osobny term — także dla neutralnych wartości takich jak `S` — a wszystkie termy muszą należeć dokładnie do tej samej taksonomii. Użytkownik kluczy REST musi mieć jednocześnie uprawnienia `manage_woocommerce`, `manage_product_terms` i prawo edycji każdego termu. Przed zmianą wtyczka sprawdza aktywne języki, wcześniejsze rodziny oraz pełną mapę; identyczne ponowienie jest idempotentne i zwraca tę samą zweryfikowaną relację.

Przed automatycznym backfillem ERP potwierdza wersję wtyczki, aktywny Polylang, wymagane języki, przetłumaczenie wszystkich taksonomii `pa_*` oraz pełne zakończenie bootstrapu istniejących wartości bez wykonywania zmian. Odpowiedź zawiera wersję i stan bootstrapu, liczbę niegotowych taksonomii oraz liczbę wartości bez języka:

```text
GET /wp-json/wc-lemon-erp/v1/catalog/products/translations/capabilities
```

ERP wiąże utworzone osobno termy językowe przez uwierzytelniony endpoint:

```text
POST /wp-json/lemon-erp/v1/catalog/categories/translations
```

Payload `translations` jest mapą kodu języka na ID kategorii, np. `{"pl": 81, "en": 144}`. Wtyczka przypisuje języki i zapisuje jedną rodzinę tłumaczeń Polylang; ponowienie tego samego żądania jest bezpieczne.

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
