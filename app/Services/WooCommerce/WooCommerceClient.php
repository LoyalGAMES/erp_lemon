<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class WooCommerceClient
{
    private const CATALOG_CONTRACT_VERSION = 1;

    private const CATALOG_PLUGIN_MINIMUM_VERSION = '0.2.0';

    public function test(WordpressIntegration $integration): array
    {
        $response = $this->request($integration)
            ->get($this->endpoint($integration, '/system_status'));

        if (! $response->successful()) {
            throw new RuntimeException("WooCommerce zwrócił HTTP {$response->status()}.");
        }

        $json = $response->json();

        return [
            'wp_version' => (string) data_get($json, 'environment.wp_version', '-'),
            'wc_version' => (string) data_get($json, 'environment.version', '-'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadMedia(WordpressIntegration $integration, string $absolutePath, string $filename, string $mimeType): array
    {
        if (! $integration->hasWordpressMediaCredentials()) {
            throw new RuntimeException('Brak danych WordPress REST do uploadu plików faktur.');
        }

        if (! File::exists($absolutePath)) {
            throw new RuntimeException('Nie znaleziono pliku faktury do uploadu.');
        }

        $response = $this->wordpressRequest($integration)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.addslashes($filename).'"',
                'Content-Type' => $mimeType,
            ])
            ->withBody(File::get($absolutePath), $mimeType)
            ->post($this->wordpressEndpoint($integration, '/media'));

        if (! $response->successful()) {
            throw new RuntimeException("Upload pliku faktury do WordPress zwrócił HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function products(WordpressIntegration $integration): iterable
    {
        $configuredLanguages = $integration->productImportLanguages();
        $languages = $configuredLanguages;
        $primaryLanguage = array_shift($languages);
        $primaryItems = $this->productsForLanguage($integration, $primaryLanguage);

        if ($primaryItems === [] && $primaryLanguage !== null) {
            $primaryLanguage = null;
            $primaryItems = $this->productsForLanguage($integration, null);
        }

        $itemsByLanguage = [
            $this->languageBucketKey($primaryLanguage) => $primaryItems,
        ];
        $translatedItemsByLanguage = [];

        foreach ($languages as $language) {
            $translatedItems = $this->productsForLanguage($integration, $language);
            $itemsByLanguage[$this->languageBucketKey($language)] = $translatedItems;
            $translatedItemsByLanguage[$this->languageBucketKey($language)] = [
                'language' => $language,
                'items' => $translatedItems,
            ];
        }

        $this->assertSafeMultilingualCatalog($configuredLanguages, $itemsByLanguage);

        $translations = [];

        foreach ($translatedItemsByLanguage as $translatedBucket) {
            $requestedLanguage = $translatedBucket['language'];

            foreach ($translatedBucket['items'] as $translatedItem) {
                $language = $this->catalogItemLanguage($translatedItem)
                    ?? $requestedLanguage
                    ?? 'default';

                foreach ($this->translationKeys($translatedItem) as $key) {
                    $translations[$key][$language] = $translatedItem;
                }
            }
        }

        foreach ($primaryItems as $item) {
            $item['erp_import_language'] = $this->catalogItemLanguage($item)
                ?? $primaryLanguage
                ?? 'default';
            $item['erp_translations'] = [];

            foreach ($this->translationKeys($item) as $key) {
                foreach ($translations[$key] ?? [] as $language => $translatedItem) {
                    if ($this->wooItemIdentity($translatedItem) === $this->wooItemIdentity($item)) {
                        continue;
                    }

                    $item['erp_translations'][$language] = $translatedItem;
                }
            }

            yield $item;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productsForLanguage(WordpressIntegration $integration, ?string $language): array
    {
        $items = [];

        for ($page = 1; $page <= 200; $page++) {
            $query = [
                'per_page' => 100,
                'page' => $page,
            ];

            if ($language !== null) {
                $query['lang'] = $language;
            }

            $response = $this->request($integration)
                ->get($this->endpoint($integration, '/products'), $query);

            if (! $response->successful()) {
                throw new RuntimeException("Import produktów zwrócił HTTP {$response->status()}.");
            }

            $products = $response->json();

            if (! is_array($products) || $products === []) {
                break;
            }

            foreach ($products as $product) {
                if (! is_array($product)) {
                    continue;
                }

                // WooCommerce does not always pass Polylang's `lang` query
                // argument through to its REST controller. When Polylang still
                // exposes the language in the response, honour it here instead
                // of importing both members of a translation pair as ERP items.
                if (! $this->matchesRequestedLanguage($product, $language)) {
                    continue;
                }

                $product['erp_import_language'] = $language ?? 'default';
                $items[] = $product;

                if (($product['type'] ?? null) === 'variable') {
                    foreach ($this->variations($integration, $product, $language) as $variation) {
                        if (! $this->matchesRequestedLanguage($variation, $language)) {
                            continue;
                        }

                        $variation['erp_import_language'] = $language ?? 'default';
                        $items[] = $variation;
                    }
                }
            }
        }

        return $items;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function productCategories(WordpressIntegration $integration): iterable
    {
        $configuredLanguages = $integration->productImportLanguages();

        if ($configuredLanguages === []) {
            $configuredLanguages = [null];
        }

        $primaryLanguage = $configuredLanguages[0] ?? null;
        $categoriesByLanguage = [];

        foreach ($configuredLanguages as $language) {
            $categoriesByLanguage[$this->languageBucketKey($language)] = $this->productCategoriesForLanguage(
                $integration,
                $language,
            );
        }

        $this->assertSafeMultilingualCategories($configuredLanguages, $categoriesByLanguage);

        $primaryCategories = $categoriesByLanguage[$this->languageBucketKey($primaryLanguage)] ?? [];
        $translations = [];

        foreach ($categoriesByLanguage as $language => $categories) {
            if ($language === $this->languageBucketKey($primaryLanguage)) {
                continue;
            }

            foreach ($categories as $category) {
                $translationKey = $this->verifiedLemonTranslationKey($category);

                if ($translationKey === null) {
                    continue;
                }

                $actualLanguage = $this->catalogItemLanguage($category) ?? $language;
                $translations[$translationKey][$actualLanguage] = $category;
            }
        }

        foreach ($primaryCategories as $category) {
            $category['erp_import_language'] = $this->catalogItemLanguage($category)
                ?? $primaryLanguage
                ?? 'default';
            $category['erp_translations'] = [];
            $translationKey = $this->verifiedLemonTranslationKey($category);

            if ($translationKey !== null) {
                foreach ($translations[$translationKey] ?? [] as $language => $translatedCategory) {
                    if ((string) ($translatedCategory['id'] ?? '') === (string) ($category['id'] ?? '')) {
                        continue;
                    }

                    $category['erp_translations'][$language] = $translatedCategory;
                }
            }

            yield $category;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function productCategoriesForLanguage(
        WordpressIntegration $integration,
        ?string $language,
    ): array {
        $items = [];

        for ($page = 1; $page <= 10; $page++) {
            $query = [
                'per_page' => 100,
                'page' => $page,
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'asc',
            ];

            if ($language !== null) {
                $query['lang'] = $language;
            }

            $response = $this->request($integration)
                ->get($this->endpoint($integration, '/products/categories'), $query);

            if (! $response->successful()) {
                throw new RuntimeException("Import kategorii produktów zwrócił HTTP {$response->status()}.");
            }

            $categories = $response->json();

            if (! is_array($categories) || $categories === []) {
                break;
            }

            foreach ($categories as $category) {
                if (is_array($category) && $this->matchesRequestedLanguage($category, $language)) {
                    $category['erp_import_language'] = $language ?? 'default';
                    $items[] = $category;
                }
            }
        }

        return $items;
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function orders(WordpressIntegration $integration, ?CarbonInterface $modifiedAfter = null): iterable
    {
        $settings = $integration->orderImportSettings();
        $pageLimit = max(1, min(500, (int) $settings['page_limit']));

        for ($page = 1; $page <= $pageLimit; $page++) {
            $orders = $this->ordersPage($integration, $page, $modifiedAfter);

            if ($orders === []) {
                break;
            }

            foreach ($orders as $order) {
                yield $order;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ordersPage(
        WordpressIntegration $integration,
        int $page,
        ?CarbonInterface $modifiedAfter = null,
    ): array {
        $query = [
            'per_page' => 100,
            'page' => max(1, $page),
            'status' => 'any',
            'orderby' => 'date',
            'order' => 'desc',
        ];

        if ($modifiedAfter instanceof CarbonInterface) {
            $query['modified_after'] = $modifiedAfter->toIso8601String();
        }

        $response = $this->request($integration)
            ->get($this->endpoint($integration, '/orders'), $query);

        if (! $response->successful()) {
            throw new RuntimeException("Import zamówień zwrócił HTTP {$response->status()}.");
        }

        $orders = $response->json();

        return is_array($orders)
            ? array_values(array_filter($orders, fn (mixed $order): bool => is_array($order)))
            : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function orderNotes(WordpressIntegration $integration, string $orderId): array
    {
        $response = $this->orderNotesRequest($integration)
            ->get($this->endpoint($integration, "/orders/{$orderId}/notes"), [
                'type' => 'any',
                'per_page' => 50,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Import notatek zamówienia zwrócił HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStock(WordpressIntegration $integration, ProductChannelMapping $mapping, float $quantity): array
    {
        $stockQuantity = (int) floor(max(0, $quantity));
        $payload = [
            'manage_stock' => true,
            'stock_quantity' => $stockQuantity,
            'stock_status' => $stockQuantity > 0 ? 'instock' : 'outofstock',
        ];

        $endpoint = $mapping->external_variation_id
            ? "/products/{$mapping->external_product_id}/variations/{$mapping->external_variation_id}"
            : "/products/{$mapping->external_product_id}";

        $response = $this->request($integration)
            ->put($this->endpoint($integration, $endpoint), $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Eksport stanu do WooCommerce zwrócił HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createProduct(WordpressIntegration $integration, array $payload): array
    {
        $response = $this->request($integration)
            ->post($this->endpoint($integration, '/products'), $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Utworzenie produktu w WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createProductForLanguage(WordpressIntegration $integration, array $payload, string $language): array
    {
        $url = $this->endpoint($integration, '/products').'?lang='.rawurlencode($language);
        $response = $this->request($integration)->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Utworzenie produktu {$language} w WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createProductVariation(WordpressIntegration $integration, string $externalProductId, array $payload): array
    {
        $response = $this->request($integration)
            ->post($this->endpoint($integration, "/products/{$externalProductId}/variations"), $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Utworzenie wariantu WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteProductVariation(
        WordpressIntegration $integration,
        string $externalProductId,
        string $externalVariationId,
    ): array {
        $endpoint = $this->endpoint(
            $integration,
            "/products/{$externalProductId}/variations/{$externalVariationId}",
        ).'?force=true';
        $response = $this->request($integration)->delete($endpoint);

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException("Usunięcie wariantu WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createProductCategory(WordpressIntegration $integration, array $payload, ?string $language = null): array
    {
        $url = $this->endpoint($integration, '/products/categories');

        if (filled($language)) {
            $url .= '?lang='.rawurlencode((string) $language);
        }

        $response = $this->request($integration)->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Utworzenie kategorii produktu w WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, int>  $translations
     * @return array<string, mixed>
     */
    public function linkProductCategoryTranslations(WordpressIntegration $integration, array $translations): array
    {
        if (! $integration->hasWordpressMediaCredentials()) {
            throw new RuntimeException('Brak loginu i hasła aplikacji WordPress REST wymaganych do powiązania tłumaczeń kategorii Polylang.');
        }

        try {
            $response = $this->wordpressRequest($integration)->post(
                $this->wordpressRestEndpoint($integration, '/lemon-erp/v1/catalog/categories/translations'),
                ['translations' => $translations],
            );
        } catch (RequestException $exception) {
            $this->throwCategoryTranslationLinkHttpException($exception->response);
        }

        if (! $response->successful()) {
            $this->throwCategoryTranslationLinkHttpException($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function throwCategoryTranslationLinkHttpException(?Response $response): never
    {
        $status = $response?->status() ?? 0;
        $payload = $response?->json();
        $code = trim((string) data_get($payload, 'code', ''));
        $message = trim((string) data_get($payload, 'message', ''));

        if ($status === 404 && ($code === '' || $code === 'rest_no_route')) {
            throw new RuntimeException('Powiązanie tłumaczeń kategorii w WooCommerce wymaga aktywnej wtyczki Lemon ERP co najmniej 0.3.0.');
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException('WordPress odrzucił powiązanie tłumaczeń kategorii. Sprawdź login, hasło aplikacji oraz uprawnienia użytkownika WordPress REST.');
        }

        if ($message !== '') {
            throw new RuntimeException("WordPress nie powiązał tłumaczeń kategorii: {$message}");
        }

        throw new RuntimeException("Powiązanie tłumaczeń kategorii w WooCommerce zwróciło HTTP {$status}.");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateProductData(WordpressIntegration $integration, ProductChannelMapping $mapping, array $payload): array
    {
        $endpoint = $mapping->external_variation_id
            ? "/products/{$mapping->external_product_id}/variations/{$mapping->external_variation_id}"
            : "/products/{$mapping->external_product_id}";

        $response = $this->request($integration)
            ->put($this->endpoint($integration, $endpoint), $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Eksport danych produktu do WooCommerce zwrócił HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateProductDataByIds(
        WordpressIntegration $integration,
        string $externalProductId,
        ?string $externalVariationId,
        array $payload,
    ): array {
        $endpoint = filled($externalVariationId)
            ? "/products/{$externalProductId}/variations/{$externalVariationId}"
            : "/products/{$externalProductId}";

        $response = $this->request($integration)
            ->put($this->endpoint($integration, $endpoint), $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Eksport tłumaczenia produktu do WooCommerce zwrócił HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return list<array{language:?string,product_id:string,status:string}>
     */
    public function updateProductPublicationDateTranslations(
        WordpressIntegration $integration,
        ProductChannelMapping $mapping,
        string $sku,
        string $dateCreated,
    ): array {
        if (filled($mapping->external_variation_id)) {
            return [];
        }

        $mainProductId = (string) $mapping->external_product_id;
        $updated = [];
        $updatedIds = [];

        foreach ($integration->productImportLanguages() as $language) {
            $query = [
                'sku' => $sku,
                'per_page' => 20,
            ];

            if ($language !== null) {
                $query['lang'] = $language;
            }

            $response = $this->request($integration)
                ->get($this->endpoint($integration, '/products'), $query);

            if (! $response->successful()) {
                throw new RuntimeException("Wyszukanie tłumaczeń Polylang produktu {$sku} zwróciło HTTP {$response->status()}.");
            }

            $products = $response->json();

            if (! is_array($products)) {
                continue;
            }

            foreach ($products as $product) {
                if (! is_array($product) || ! isset($product['id'])) {
                    continue;
                }

                $translationId = (string) $product['id'];

                if ($translationId === '' || $translationId === $mainProductId || in_array($translationId, $updatedIds, true)) {
                    continue;
                }

                $updateResponse = $this->request($integration)
                    ->put($this->endpoint($integration, "/products/{$translationId}"), [
                        'date_created' => $dateCreated,
                    ]);

                if (! $updateResponse->successful()) {
                    throw new RuntimeException("Aktualizacja daty publikacji tłumaczenia WooCommerce #{$translationId} zwróciła HTTP {$updateResponse->status()}.");
                }

                $updatedIds[] = $translationId;
                $updated[] = [
                    'language' => $language,
                    'product_id' => $translationId,
                    'status' => 'updated',
                ];
            }
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $invoiceData
     * @return array<string, mixed>
     */
    public function updateOrderInvoiceMeta(WordpressIntegration $integration, string $orderId, array $invoiceData): array
    {
        $prefix = ($invoiceData['invoice_type'] ?? null) === 'correction'
            ? '_sempre_erp_correction_invoice_'
            : '_sempre_erp_invoice_';

        $response = $this->request($integration)
            ->put($this->endpoint($integration, "/orders/{$orderId}"), [
                'meta_data' => [
                    ['key' => $prefix.'number', 'value' => $invoiceData['invoice_number'] ?? null],
                    ['key' => $prefix.'id', 'value' => $invoiceData['invoice_id'] ?? null],
                    ['key' => $prefix.'status', 'value' => $invoiceData['invoice_status'] ?? null],
                    ['key' => $prefix.'type', 'value' => $invoiceData['invoice_type'] ?? null],
                    ['key' => $prefix.'gross_total', 'value' => $invoiceData['gross_total'] ?? null],
                    ['key' => $prefix.'currency', 'value' => $invoiceData['currency'] ?? null],
                    ['key' => $prefix.'issued_at', 'value' => $invoiceData['issued_at'] ?? null],
                    ['key' => $prefix.'file_type', 'value' => $invoiceData['file_type'] ?? null],
                    ['key' => $prefix.'file_sha256', 'value' => $invoiceData['file_sha256'] ?? null],
                    ['key' => $prefix.'file_url', 'value' => $invoiceData['file_url'] ?? null],
                    ['key' => $prefix.'media_id', 'value' => $invoiceData['media_id'] ?? null],
                    ['key' => $prefix.'ksef_number', 'value' => $invoiceData['ksef_number'] ?? null],
                    ['key' => $prefix.'ksef_reference_number', 'value' => $invoiceData['ksef_reference_number'] ?? null],
                    ['key' => $prefix.'ksef_accepted_at', 'value' => $invoiceData['ksef_accepted_at'] ?? null],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Upload faktury do WooCommerce zwrócił HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $invoiceData
     * @return array<string, mixed>
     */
    public function upsertOrderInvoiceViaLemonPlugin(
        WordpressIntegration $integration,
        string $orderId,
        array $invoiceData,
        string $absolutePath,
    ): array {
        if (! $integration->hasWordpressMediaCredentials()) {
            throw new RuntimeException('Brak danych WordPress REST wymaganych przez wtyczkę Lemon ERP.');
        }

        if (! File::exists($absolutePath)) {
            throw new RuntimeException('Nie znaleziono pliku faktury do przekazania do wtyczki Lemon ERP.');
        }

        $payload = array_merge($invoiceData, [
            'filename' => basename($absolutePath),
            'file_base64' => base64_encode(File::get($absolutePath)),
            'add_note' => true,
        ]);

        try {
            $response = $this->wordpressRequest($integration)
                ->acceptJson()
                ->asJson()
                ->post($this->wordpressRestEndpoint($integration, "/lemon-erp/v1/orders/{$orderId}/invoice"), $payload);
        } catch (RequestException $exception) {
            $this->throwLemonPluginHttpException($exception->response?->status() ?? 0);
        }

        if (! $response->successful()) {
            $this->throwLemonPluginHttpException($response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function throwLemonPluginHttpException(int $status): never
    {
        if ($status === 404) {
            throw new RuntimeException('Wtyczka Lemon ERP for WooCommerce nie jest zainstalowana albo aktywna w WordPressie. Pobierz ZIP z ekranu Integracje w ERP, wgraj go w WordPress i ponów wysyłkę faktury.');
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException('WordPress odrzucił zapis faktury przez wtyczkę Lemon ERP. Sprawdź użytkownika WordPress REST i jego uprawnienia do edycji zamówień WooCommerce.');
        }

        throw new RuntimeException("Wtyczka Lemon ERP w WooCommerce zwróciła HTTP {$status}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function createOrderNote(WordpressIntegration $integration, string $orderId, string $note): array
    {
        $response = $this->request($integration)
            ->post($this->endpoint($integration, "/orders/{$orderId}/notes"), [
                'note' => $note,
                'customer_note' => false,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Dodanie notatki faktury do WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateOrderStatus(WordpressIntegration $integration, string $orderId, string $status): array
    {
        $response = $this->request($integration)
            ->put($this->endpoint($integration, "/orders/{$orderId}"), [
                'status' => $status,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Zmiana statusu zamówienia WooCommerce zwróciła HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array{contents:string,mime_type:string,filename:?string,source_url:?string,response_payload:?array<string,mixed>}
     */
    public function generateShippingLabel(WordpressIntegration $integration, string $orderId, string $orderNumber): array
    {
        $settings = $integration->shippingLabelSettings();
        $endpoint = trim((string) $settings['endpoint']);

        if ($endpoint === '') {
            throw new RuntimeException('Brak endpointu etykiet kurierskich w konfiguracji integracji.');
        }

        $method = strtoupper((string) $settings['method']);
        $url = $this->configuredEndpoint($integration, $endpoint, $orderId, $orderNumber);
        $payload = [
            'order_id' => $orderId,
            'order_number' => $orderNumber,
        ];

        $request = $this->shippingLabelRequest($integration, $settings);
        $response = $method === 'GET'
            ? $request->get($url, $payload)
            : $request->send($method, $url, ['json' => $payload]);

        if (! $response->successful()) {
            throw new RuntimeException("Generowanie etykiety w WooCommerce zwróciło HTTP {$response->status()}.");
        }

        return $this->extractShippingLabel($integration, $settings, $response);
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    private function variations(WordpressIntegration $integration, array $parentProduct, ?string $language = null): iterable
    {
        $productId = (int) $parentProduct['id'];

        for ($page = 1; $page <= 50; $page++) {
            $query = [
                'per_page' => 100,
                'page' => $page,
            ];

            if ($language !== null) {
                $query['lang'] = $language;
            }

            try {
                $response = $this->request($integration)
                    ->get($this->endpoint($integration, "/products/{$productId}/variations"), $query);
            } catch (RequestException $exception) {
                throw new RuntimeException(
                    "Import wariantów produktu WooCommerce #{$productId} zwrócił HTTP {$exception->response->status()} na stronie {$page}.",
                    0,
                    $exception,
                );
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Import wariantów produktu WooCommerce #{$productId} nie powiódł się na stronie {$page}: {$exception->getMessage()}",
                    0,
                    $exception,
                );
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Import wariantów produktu WooCommerce #{$productId} zwrócił HTTP {$response->status()} na stronie {$page}."
                );
            }

            $variations = $response->json() ?? [];

            if (! is_array($variations) || $variations === []) {
                break;
            }

            foreach ($variations as $variation) {
                $variation['variation_name'] = $variation['name'] ?? null;
                $variation['variation_id'] = $variation['id'] ?? null;
                $variation['parent_permalink'] = $parentProduct['permalink'] ?? null;
                $variation['parent_images'] = $parentProduct['images'] ?? [];
                $variation['parent_image'] = data_get($parentProduct, 'images.0');
                $variation['image'] = $variation['image'] ?? data_get($parentProduct, 'images.0');
                $variation['id'] = $productId;
                $variation['parent_name'] = $parentProduct['name'] ?? null;
                $variation['name'] = $this->variationDisplayName($parentProduct, $variation);
                $variation['type'] = 'variation';
                yield $variation;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function translationKeys(array $item): array
    {
        if ($this->hasCatalogContract($item)) {
            $translationGroup = trim((string) ($item['lemon_erp_translation_group'] ?? ''));

            if ($translationGroup !== '') {
                return ['lemon-erp-translation:'.$translationGroup];
            }

            $translationIds = $this->translationIds($item['lemon_erp_translations'] ?? []);

            if (count($translationIds) > 1) {
                return ['lemon-erp-translation-ids:'.implode('|', $translationIds)];
            }
        }

        $translationIds = collect((array) ($item['translations'] ?? []))
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        // Polylang exposes a stable translation family in REST responses. It
        // distinguishes actual translation twins from unrelated products that
        // happen to reuse a SKU, including a parent and its variation.
        if (count($translationIds) > 1) {
            return ['translation:'.implode('|', $translationIds)];
        }

        $keys = [];
        $sku = trim((string) ($item['sku'] ?? ''));

        if ($sku !== '') {
            $keys[] = 'sku:'.mb_strtolower($sku);
        }

        if (isset($item['variation_id'])) {
            $keys[] = 'variation:'.(string) $item['variation_id'];
        }

        if (isset($item['id']) && ! isset($item['variation_id'])) {
            $keys[] = 'product:'.(string) $item['id'];
        }

        return array_values(array_unique($keys));
    }

    /**
     * Accept responses without Polylang REST fields for backwards
     * compatibility, but validate the requested language whenever it is
     * explicitly present in the WooCommerce payload.
     *
     * @param  array<string, mixed>  $item
     */
    private function matchesRequestedLanguage(array $item, ?string $requestedLanguage): bool
    {
        if ($requestedLanguage === null) {
            return true;
        }

        if ($this->hasCatalogContract($item) && array_key_exists('lemon_erp_language', $item)) {
            $actualLanguage = $this->normalizeCatalogLanguage($item['lemon_erp_language']);

            // A null language is the explicit plugin representation of a
            // catalogue that is not managed by a multilingual extension.
            return $actualLanguage === null
                || $actualLanguage === mb_strtolower($requestedLanguage);
        }

        $actualLanguage = mb_strtolower(trim((string) ($item['lang'] ?? '')));

        return $actualLanguage === '' || $actualLanguage === mb_strtolower($requestedLanguage);
    }

    /**
     * Refuse to import the same unverified WooCommerce objects once per
     * configured language. Without a language/translation contract there is
     * no deterministic way to distinguish Polylang twins from genuine SKU
     * conflicts, so continuing would recreate duplicate ERP products.
     *
     * @param  list<string|null>  $configuredLanguages
     * @param  array<string, list<array<string, mixed>>>  $itemsByLanguage
     */
    private function assertSafeMultilingualCatalog(array $configuredLanguages, array $itemsByLanguage): void
    {
        $requestedLanguages = collect($configuredLanguages)
            ->filter(fn (?string $language): bool => $language !== null && trim($language) !== '')
            ->map(fn (string $language): string => mb_strtolower(trim($language)))
            ->unique()
            ->values();

        if ($requestedLanguages->count() < 2) {
            return;
        }

        /** @var array<string, array<string, bool>> $identityVerification */
        $identityVerification = [];
        $invalidDeclaredContracts = 0;

        foreach ($itemsByLanguage as $language => $items) {
            foreach ($items as $item) {
                $identity = $this->wooItemIdentity($item);

                if ($identity === null) {
                    continue;
                }

                $verified = $this->hasVerifiedCatalogIdentity($item);

                if ($this->declaresCatalogContract($item) && ! $verified) {
                    $invalidDeclaredContracts++;
                }

                $identityVerification[$identity][$language] = ($identityVerification[$identity][$language] ?? true)
                    && $verified;
            }
        }

        $unsafeIdentities = collect($identityVerification)
            ->filter(fn (array $languages): bool => count($languages) > 1 && in_array(false, $languages, true));

        if ($unsafeIdentities->isEmpty() && $invalidDeclaredContracts === 0) {
            return;
        }

        $problemCount = max($unsafeIdentities->count(), $invalidDeclaredContracts);

        throw new RuntimeException(
            'Import produktów wielojęzycznych został zatrzymany przed przetworzeniem pozycji: '
            .$problemCount.' obiektów WooCommerce nie ma zweryfikowanego kontraktu tłumaczeń '
            .'albo zostało zwróconych dla więcej niż jednego języka. Zaktualizuj i aktywuj wtyczkę Lemon ERP for WooCommerce '
            .'do wersji '.self::CATALOG_PLUGIN_MINIMUM_VERSION.' lub nowszej, a następnie ponów import.'
        );
    }

    /**
     * Categories must have the Lemon contract in a multilingual import. Unlike
     * products, category names and slugs cannot safely serve as a legacy join
     * key because translated terms routinely have completely different text.
     *
     * @param  list<string|null>  $configuredLanguages
     * @param  array<string, list<array<string, mixed>>>  $categoriesByLanguage
     */
    private function assertSafeMultilingualCategories(
        array $configuredLanguages,
        array $categoriesByLanguage,
    ): void {
        $requestedLanguages = collect($configuredLanguages)
            ->filter(fn (?string $language): bool => $language !== null && trim($language) !== '')
            ->map(fn (string $language): string => mb_strtolower(trim($language)))
            ->unique()
            ->values();

        if ($requestedLanguages->count() < 2) {
            return;
        }

        $invalid = collect($categoriesByLanguage)
            ->flatten(1)
            ->filter(fn (mixed $category): bool => is_array($category))
            ->filter(fn (array $category): bool => $this->verifiedLemonTranslationKey($category) === null)
            ->count();

        if ($invalid === 0) {
            return;
        }

        throw new RuntimeException(
            'Import kategorii wielojęzycznych został zatrzymany przed zapisem: '
            .$invalid.' kategorii WooCommerce nie ma zweryfikowanego kontraktu Polylang '
            .'(lemon_erp_translation_group oraz lemon_erp_translations). Zaktualizuj i aktywuj wtyczkę '
            .'Lemon ERP for WooCommerce do wersji '.self::CATALOG_PLUGIN_MINIMUM_VERSION
            .' lub nowszej, a następnie ponów import.'
        );
    }

    /**
     * Return a family key only when both independent parts of the Lemon
     * contract agree: the stable group and the complete set of external IDs.
     *
     * @param  array<string, mixed>  $item
     */
    private function verifiedLemonTranslationKey(array $item): ?string
    {
        if (! $this->hasCatalogContract($item) || ! $this->hasVerifiedCatalogIdentity($item)) {
            return null;
        }

        $group = trim((string) ($item['lemon_erp_translation_group'] ?? ''));
        $translationIds = $this->translationIds($item['lemon_erp_translations'] ?? []);

        if ($group === '' || $translationIds === []) {
            return null;
        }

        return 'lemon-category:'.$group.'|ids:'.implode('|', $translationIds);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function declaresCatalogContract(array $item): bool
    {
        return array_key_exists('lemon_erp_catalog_contract', $item)
            || array_key_exists('lemon_erp_language', $item)
            || array_key_exists('lemon_erp_translations', $item)
            || array_key_exists('lemon_erp_translation_group', $item);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function hasCatalogContract(array $item): bool
    {
        return (int) ($item['lemon_erp_catalog_contract'] ?? 0) === self::CATALOG_CONTRACT_VERSION;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function hasVerifiedCatalogIdentity(array $item): bool
    {
        if ($this->hasCatalogContract($item)) {
            $group = trim((string) ($item['lemon_erp_translation_group'] ?? ''));
            $translations = (array) ($item['lemon_erp_translations'] ?? []);
            $translationIds = $this->translationIds($translations);
            $language = $this->catalogItemLanguage($item);
            $currentId = isset($item['variation_id'])
                ? trim((string) $item['variation_id'])
                : trim((string) ($item['id'] ?? ''));

            return $group !== ''
                && $translationIds !== []
                && $currentId !== ''
                && in_array($currentId, $translationIds, true)
                && (count($translationIds) === 1 || $language !== null);
        }

        $legacyLanguage = $this->normalizeCatalogLanguage($item['lang'] ?? null);
        $legacyTranslations = (array) ($item['translations'] ?? []);
        $legacyTranslationIds = $this->translationIds($legacyTranslations);
        $currentId = isset($item['variation_id'])
            ? trim((string) $item['variation_id'])
            : trim((string) ($item['id'] ?? ''));

        return $legacyLanguage !== null
            && $legacyTranslationIds !== []
            && $currentId !== ''
            && in_array($currentId, $legacyTranslationIds, true);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function catalogItemLanguage(array $item): ?string
    {
        if ($this->hasCatalogContract($item) && array_key_exists('lemon_erp_language', $item)) {
            return $this->normalizeCatalogLanguage($item['lemon_erp_language']);
        }

        return $this->normalizeCatalogLanguage($item['lang'] ?? null);
    }

    private function normalizeCatalogLanguage(mixed $language): ?string
    {
        $language = mb_strtolower(trim((string) ($language ?? '')));

        return $language !== '' ? $language : null;
    }

    /**
     * @return list<string>
     */
    private function translationIds(mixed $translations): array
    {
        return collect(is_array($translations) ? $translations : [])
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function wooItemIdentity(array $item): ?string
    {
        $productId = trim((string) ($item['id'] ?? ''));

        if ($productId === '') {
            return null;
        }

        if (isset($item['variation_id'])) {
            $variationId = trim((string) $item['variation_id']);

            return $variationId !== '' ? 'variation:'.$productId.'|'.$variationId : null;
        }

        return 'product:'.$productId;
    }

    private function languageBucketKey(?string $language): string
    {
        return $this->normalizeCatalogLanguage($language) ?? 'default';
    }

    private function variationDisplayName(array $parentProduct, array $variation): string
    {
        $baseName = trim((string) ($parentProduct['name'] ?? ''));
        $options = collect($variation['attributes'] ?? [])
            ->map(fn (array $attribute): string => trim((string) ($attribute['option'] ?? $attribute['name'] ?? '')))
            ->filter()
            ->implode(' / ');

        if ($baseName !== '' && $options !== '') {
            return $baseName.' - '.$options;
        }

        if ($baseName !== '') {
            return $baseName;
        }

        return trim((string) ($variation['name'] ?? $variation['sku'] ?? 'Wariant'));
    }

    private function request(WordpressIntegration $integration): PendingRequest
    {
        return Http::timeout(30)
            ->retry(2, 300)
            ->acceptJson()
            ->withBasicAuth(
                Crypt::decryptString($integration->consumer_key_encrypted),
                Crypt::decryptString($integration->consumer_secret_encrypted),
            );
    }

    private function orderNotesRequest(WordpressIntegration $integration): PendingRequest
    {
        return Http::timeout(3)
            ->acceptJson()
            ->withBasicAuth(
                Crypt::decryptString($integration->consumer_key_encrypted),
                Crypt::decryptString($integration->consumer_secret_encrypted),
            );
    }

    private function wordpressRequest(WordpressIntegration $integration): PendingRequest
    {
        return Http::timeout(60)
            ->retry(2, 300)
            ->acceptJson()
            ->withBasicAuth(
                (string) $integration->wp_api_username,
                $integration->wordpressApiPassword(),
            );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function shippingLabelRequest(WordpressIntegration $integration, array $settings): PendingRequest
    {
        $request = Http::timeout(60)
            ->retry(2, 300)
            ->withHeaders([
                'Accept' => 'application/pdf, image/png, application/json;q=0.9, */*;q=0.8',
            ]);

        return match ((string) ($settings['auth'] ?? 'woocommerce')) {
            'wordpress' => $integration->hasWordpressMediaCredentials()
                ? $request->withBasicAuth($integration->wp_api_username, $integration->wordpressApiPassword())
                : throw new RuntimeException('Wybrano auth WordPress REST, ale integracja nie ma loginu i hasła aplikacji.'),
            'none' => $request,
            default => $request->withBasicAuth(
                Crypt::decryptString($integration->consumer_key_encrypted),
                Crypt::decryptString($integration->consumer_secret_encrypted),
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{contents:string,mime_type:string,filename:?string,source_url:?string,response_payload:?array<string,mixed>}
     */
    private function extractShippingLabel(WordpressIntegration $integration, array $settings, Response $response): array
    {
        $contentType = strtolower((string) $response->header('Content-Type'));
        $body = $response->body();

        if (str_contains($contentType, 'application/pdf') || str_starts_with($body, '%PDF')) {
            return [
                'contents' => $body,
                'mime_type' => 'application/pdf',
                'filename' => $this->filenameFromResponse($response),
                'source_url' => null,
                'response_payload' => null,
            ];
        }

        if (str_contains($contentType, 'image/png')) {
            return [
                'contents' => $body,
                'mime_type' => 'image/png',
                'filename' => $this->filenameFromResponse($response),
                'source_url' => null,
                'response_payload' => null,
            ];
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new RuntimeException('Endpoint etykiety nie zwrócił obsługiwanego pliku PDF/PNG ani JSON z adresem pliku.');
        }

        $filename = $this->configuredJsonValue($json, (string) ($settings['filename_key'] ?? ''), [
            'filename',
            'file_name',
            'label_filename',
            'data.filename',
        ]);

        $base64 = $this->configuredJsonValue($json, (string) ($settings['base64_key'] ?? ''), [
            'label_base64',
            'file_base64',
            'pdf_base64',
            'data.label_base64',
            'data.file_base64',
        ]);

        if (is_string($base64) && trim($base64) !== '') {
            return $this->labelFromBase64($base64, $filename, $json);
        }

        $fileUrl = $this->configuredJsonValue($json, (string) ($settings['url_key'] ?? ''), [
            'label_url',
            'file_url',
            'download_url',
            'url',
            'data.label_url',
            'data.file_url',
            'data.download_url',
            'label.download_url',
            'label.url',
        ]);

        if (is_string($fileUrl) && trim($fileUrl) !== '') {
            $downloadUrl = $this->absoluteUrl($integration, $fileUrl);
            $download = $this->shippingLabelRequest($integration, $settings)->get($downloadUrl);

            if (! $download->successful()) {
                throw new RuntimeException("Pobranie etykiety z WooCommerce zwróciło HTTP {$download->status()}.");
            }

            return [
                'contents' => $download->body(),
                'mime_type' => $this->mimeType($download),
                'filename' => $filename ?: $this->filenameFromResponse($download),
                'source_url' => $downloadUrl,
                'response_payload' => $this->compactPayload($json),
            ];
        }

        throw new RuntimeException('Endpoint etykiety zwrócił JSON, ale nie znaleziono URL ani base64 z plikiem etykiety.');
    }

    private function configuredEndpoint(WordpressIntegration $integration, string $endpoint, string $orderId, string $orderNumber): string
    {
        $endpoint = strtr($endpoint, [
            '{order_id}' => rawurlencode($orderId),
            '{order_number}' => rawurlencode($orderNumber),
        ]);

        return $this->absoluteUrl($integration, $endpoint);
    }

    private function absoluteUrl(WordpressIntegration $integration, string $url): string
    {
        $url = trim($url);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim($integration->base_url, '/').'/'.ltrim($url, '/');
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<string>  $fallbacks
     */
    private function configuredJsonValue(array $json, string $configuredKey, array $fallbacks): mixed
    {
        $keys = array_values(array_filter(array_merge([$configuredKey], $fallbacks)));

        foreach ($keys as $key) {
            $value = data_get($json, $key);

            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function labelFromBase64(string $base64, ?string $filename, array $json): array
    {
        $mimeType = 'application/pdf';

        if (preg_match('/^data:([^;]+);base64,(.+)$/', trim($base64), $matches) === 1) {
            $mimeType = strtolower($matches[1]);
            $base64 = $matches[2];
        }

        $decoded = base64_decode($base64, true);

        if ($decoded === false) {
            throw new RuntimeException('Endpoint etykiety zwrócił niepoprawny base64.');
        }

        return [
            'contents' => $decoded,
            'mime_type' => $mimeType,
            'filename' => $filename,
            'source_url' => null,
            'response_payload' => $this->compactPayload($json),
        ];
    }

    private function filenameFromResponse(Response $response): ?string
    {
        $disposition = (string) $response->header('Content-Disposition');

        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $matches) === 1) {
            return rawurldecode($matches[1]);
        }

        return null;
    }

    private function mimeType(Response $response): string
    {
        $contentType = strtolower((string) $response->header('Content-Type'));

        if (str_contains($contentType, 'image/png')) {
            return 'image/png';
        }

        if (str_contains($contentType, 'image/jpeg') || str_contains($contentType, 'image/jpg')) {
            return 'image/jpeg';
        }

        if (str_contains($contentType, 'text/plain')) {
            return 'text/plain';
        }

        return 'application/pdf';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactPayload(array $payload): array
    {
        $clean = $payload;

        foreach (['label_base64', 'file_base64', 'pdf_base64'] as $key) {
            if (array_key_exists($key, $clean)) {
                $clean[$key] = '[base64 omitted]';
            }
        }

        foreach (['data', 'label'] as $nestedKey) {
            if (isset($clean[$nestedKey]) && is_array($clean[$nestedKey])) {
                foreach (['label_base64', 'file_base64', 'pdf_base64'] as $key) {
                    if (array_key_exists($key, $clean[$nestedKey])) {
                        $clean[$nestedKey][$key] = '[base64 omitted]';
                    }
                }
            }
        }

        return $clean;
    }

    private function endpoint(WordpressIntegration $integration, string $path): string
    {
        return rtrim($integration->base_url, '/').'/wp-json/wc/v3'.$path;
    }

    private function wordpressEndpoint(WordpressIntegration $integration, string $path): string
    {
        return rtrim($integration->base_url, '/').'/wp-json/wp/v2'.$path;
    }

    private function wordpressRestEndpoint(WordpressIntegration $integration, string $path): string
    {
        return rtrim($integration->base_url, '/').'/wp-json/'.ltrim($path, '/');
    }
}
