<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\AppSetting;

final class ProductEditFieldSettingsService
{
    private const KEY = 'product_edit_visible_fields';

    private const SCHEMA_VERSION = 2;

    /** @var list<string> */
    private const VERSION_2_FIELDS = [
        'lemon_shipping_days',
        'lemon_shipping_text',
        'lemon_preorder',
    ];

    /**
     * @return array<string, bool>
     */
    public function visibleFields(): array
    {
        $stored = AppSetting::query()->where('key', self::KEY)->value('value');
        if (! is_array($stored) || ! array_key_exists('visible_fields', $stored)) {
            return array_fill_keys($this->keys(), true);
        }

        $selected = $this->expandLegacyFields((array) $stored['visible_fields']);

        if ((int) ($stored['schema_version'] ?? 1) < self::SCHEMA_VERSION) {
            $selected = array_values(array_unique([...$selected, ...self::VERSION_2_FIELDS]));
        }

        return collect($this->keys())
            ->mapWithKeys(fn (string $key): array => [$key => in_array($key, $selected, true)])
            ->all();
    }

    /**
     * @return list<array{key:string,label:string,section:string}>
     */
    public function definitions(): array
    {
        return [
            ['key' => 'name', 'label' => 'Nazwa produktu (PL)', 'section' => 'Dane podstawowe'],
            ['key' => 'catalog', 'label' => 'Katalog', 'section' => 'Dane podstawowe'],
            ['key' => 'categories', 'label' => 'Kategorie', 'section' => 'Dane podstawowe'],
            ['key' => 'tags', 'label' => 'Tagi', 'section' => 'Dane podstawowe'],
            ['key' => 'sku', 'label' => 'SKU', 'section' => 'Dane podstawowe'],
            ['key' => 'ean', 'label' => 'EAN', 'section' => 'Dane podstawowe'],
            ['key' => 'asin', 'label' => 'ASIN', 'section' => 'Dane podstawowe'],
            ['key' => 'weight', 'label' => 'Waga', 'section' => 'Dane podstawowe'],
            ['key' => 'height', 'label' => 'Wysokość', 'section' => 'Dane podstawowe'],
            ['key' => 'width', 'label' => 'Szerokość', 'section' => 'Dane podstawowe'],
            ['key' => 'length', 'label' => 'Długość', 'section' => 'Dane podstawowe'],
            ['key' => 'unit', 'label' => 'Jednostka', 'section' => 'Dane podstawowe'],
            ['key' => 'is_active', 'label' => 'Status aktywności', 'section' => 'Dane podstawowe'],
            ['key' => 'publication_status', 'label' => 'Status publikacji', 'section' => 'Dane podstawowe'],
            ['key' => 'publication_date', 'label' => 'Data publikacji', 'section' => 'Dane podstawowe'],
            ['key' => 'catalog_visibility', 'label' => 'Widoczność w WooCommerce', 'section' => 'Dane podstawowe'],
            ['key' => 'product_type', 'label' => 'Typ produktu', 'section' => 'Dane podstawowe'],
            ['key' => 'variant_attribute', 'label' => 'Atrybut wariantu', 'section' => 'Dane podstawowe'],
            ['key' => 'developed', 'label' => 'Kompletność danych PIM', 'section' => 'Dane podstawowe'],
            ['key' => 'stock_balances', 'label' => 'Tabele stanów magazynowych', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'wholesale_price', 'label' => 'Cena hurtowa', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'retail_price', 'label' => 'Cena detaliczna', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'sale_price', 'label' => 'Cena promocyjna', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'sale_price_starts_at', 'label' => 'Promocja od', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'sale_price_ends_at', 'label' => 'Promocja do', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'vat_rate', 'label' => 'VAT', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'warehouse_location', 'label' => 'Lokalizacja magazynowa', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'purchase_price', 'label' => 'Cena zakupu', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'extra_cost', 'label' => 'Koszt dodatkowy', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'manage_stock', 'label' => 'Zarządzanie stanem', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'backorders', 'label' => 'Zamówienia oczekujące', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'low_stock_amount', 'label' => 'Niski próg stanu', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'sold_individually', 'label' => 'Sprzedaż pojedyncza', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'supplier_name', 'label' => 'Nazwa dostawcy', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'supplier_product_code', 'label' => 'Kod produktu dostawcy', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'supplier_purchase_price', 'label' => 'Cena zakupu u dostawcy', 'section' => 'Sprzedaż i magazyn'],
            ['key' => 'name_en', 'label' => 'Nazwa produktu (EN)', 'section' => 'Informacje'],
            ['key' => 'custom_label_pl', 'label' => 'Custom label (PL)', 'section' => 'Informacje'],
            ['key' => 'custom_label_en', 'label' => 'Custom label (EN)', 'section' => 'Informacje'],
            ['key' => 'custom_label_bg_color', 'label' => 'Tło etykiety', 'section' => 'Informacje'],
            ['key' => 'custom_label_text_color', 'label' => 'Kolor tekstu etykiety', 'section' => 'Informacje'],
            ['key' => 'lemon_shipping_days', 'label' => 'Dni do wysyłki', 'section' => 'Informacje'],
            ['key' => 'lemon_shipping_text', 'label' => 'Tekst terminu wysyłki', 'section' => 'Informacje'],
            ['key' => 'lemon_preorder', 'label' => 'Przedsprzedaż', 'section' => 'Informacje'],
            ['key' => 'description_pl', 'label' => 'Opis PL', 'section' => 'Informacje'],
            ['key' => 'description_en', 'label' => 'Opis EN', 'section' => 'Informacje'],
            ['key' => 'short_description_pl', 'label' => 'Krótki opis PL', 'section' => 'Informacje'],
            ['key' => 'short_description_en', 'label' => 'Krótki opis EN', 'section' => 'Informacje'],
            ['key' => 'related_upsell_products', 'label' => 'Sprzedaż dodatkowa', 'section' => 'Informacje'],
            ['key' => 'related_cross_sell_products', 'label' => 'Sprzedaż krzyżowa', 'section' => 'Informacje'],
            ['key' => 'parameters', 'label' => 'Parametry produktu', 'section' => 'Informacje'],
            ['key' => 'variants', 'label' => 'Warianty i relacje', 'section' => 'Warianty i media'],
            ['key' => 'media', 'label' => 'Media produktu', 'section' => 'Warianty i media'],
        ];
    }

    /**
     * @param  list<string>  $visibleFields
     * @return array<string, bool>
     */
    public function update(array $visibleFields): array
    {
        $allowed = $this->keys();
        $selected = collect($visibleFields)
            ->map(fn (mixed $field): string => (string) $field)
            ->intersect($allowed)
            ->unique()
            ->values()
            ->all();

        AppSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => [
                'schema_version' => self::SCHEMA_VERSION,
                'visible_fields' => $selected,
            ]],
        );

        return collect($allowed)
            ->mapWithKeys(fn (string $field): array => [$field => in_array($field, $selected, true)])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        return collect($this->definitions())->pluck('key')->values()->all();
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function expandLegacyFields(array $fields): array
    {
        $legacyFields = [
            'sale_dates' => ['sale_price_starts_at', 'sale_price_ends_at'],
            'custom_labels' => ['custom_label_pl', 'custom_label_en', 'custom_label_bg_color', 'custom_label_text_color'],
            'related_products' => ['related_upsell_products', 'related_cross_sell_products'],
        ];

        return collect($fields)
            ->flatMap(fn (mixed $field): array => $legacyFields[(string) $field] ?? [(string) $field])
            ->values()
            ->all();
    }
}
