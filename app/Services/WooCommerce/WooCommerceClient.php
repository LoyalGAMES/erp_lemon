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
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class WooCommerceClient
{
    private const CATALOG_CONTRACT_VERSION = 1;

    private const CATALOG_PLUGIN_MINIMUM_VERSION = '0.2.0';

    private const PRODUCT_TRANSLATION_PLUGIN_MINIMUM_VERSION = '0.5.3';

    private const PRODUCT_VARIATION_TRANSLATION_PLUGIN_MINIMUM_VERSION = '0.5.3';

    private const PRODUCT_TRANSLATION_CREATION_META_KEY = '_sempre_erp_translation_creation_token';

    private const PRODUCT_TRANSLATION_CREATION_SKU_PREFIX = 'LEMON-TR-';

    private const PRODUCT_VARIATION_TRANSLATION_CREATION_META_KEY = '_sempre_erp_variation_translation_creation_token';

    private const PRODUCT_VARIATION_TRANSLATION_CREATION_SKU_PREFIX = 'LEMON-VTR-';

    /** @var array<string, list<array<string, mixed>>> */
    private array $globalProductAttributesCache = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $globalProductAttributeTermsCache = [];

    /** @var array<string, true> */
    private array $linkedGlobalProductAttributeTermTranslations = [];

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

        $response = $this->wordpressRequest($integration, retry: false)
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
     * @return array<string, mixed>
     */
    public function order(WordpressIntegration $integration, string|int $orderId): array
    {
        $response = $this->request($integration)
            ->get($this->endpoint($integration, '/orders/'.rawurlencode((string) $orderId)));

        if (! $response->successful()) {
            throw new RuntimeException("Pobranie zamówienia WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $order = $response->json();

        return is_array($order) ? $order : [];
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function customers(WordpressIntegration $integration): iterable
    {
        for ($page = 1; ; $page++) {
            if ($page > 10_000) {
                throw new RuntimeException('Import klientów przekroczył bezpieczny limit 1 000 000 rekordów.');
            }

            $customers = $this->customersPage($integration, $page);

            if ($customers === []) {
                break;
            }

            foreach ($customers as $customer) {
                yield $customer;
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function customersPage(
        WordpressIntegration $integration,
        int $page = 1,
        int $perPage = 100,
    ): array {
        // Read the terminal WordPress pagination response instead of letting
        // PendingRequest::retry turn its intentional HTTP 400 into an exception.
        // The queued customer import itself still has job-level retries.
        $response = $this->request($integration, retry: false)
            ->get($this->endpoint($integration, '/customers'), [
                'per_page' => max(1, min(100, $perPage)),
                'page' => max(1, $page),
                'orderby' => 'id',
                'order' => 'asc',
                'role' => 'customer',
            ]);

        if ($response->status() === 400
            && data_get($response->json(), 'code') === 'rest_post_invalid_page_number') {
            return [];
        }

        if (! $response->successful()) {
            throw new RuntimeException("Import klientów zwrócił HTTP {$response->status()}.");
        }

        $customers = $response->json();

        return is_array($customers)
            ? array_values(array_filter($customers, fn (mixed $customer): bool => is_array($customer)))
            : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function customer(WordpressIntegration $integration, string|int $customerId): array
    {
        $response = $this->request($integration)
            ->get($this->endpoint($integration, '/customers/'.rawurlencode((string) $customerId)));

        if (! $response->successful()) {
            throw new RuntimeException("Pobranie klienta WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $customer = $response->json();

        return is_array($customer) ? $customer : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function customersByEmail(WordpressIntegration $integration, string $email): array
    {
        $response = $this->request($integration)
            ->get($this->endpoint($integration, '/customers'), [
                'email' => trim($email),
                'per_page' => 100,
                'role' => 'all',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Wyszukanie klienta WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $customers = $response->json();

        return is_array($customers)
            ? array_values(array_filter($customers, fn (mixed $customer): bool => is_array($customer)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCustomer(WordpressIntegration $integration, array $payload): array
    {
        $response = $this->request($integration, retry: false)
            ->post($this->endpoint($integration, '/customers'), $payload);

        if (! $response->successful()) {
            $message = trim((string) data_get($response->json(), 'message', ''));
            $details = $message !== '' ? ': '.$message : '.';

            throw new RuntimeException("Utworzenie klienta WooCommerce zwróciło HTTP {$response->status()}{$details}");
        }

        $customer = $response->json();

        return is_array($customer) ? $customer : [];
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
        $response = $this->request($integration, retry: false)
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
    public function createProductForLanguage(
        WordpressIntegration $integration,
        array $payload,
        string $language,
        string $creationToken,
        bool $resume = false,
    ): array {
        $creationToken = trim($creationToken);

        if ($creationToken === '') {
            throw new RuntimeException('ERP nie przygotował tokenu idempotencji tłumaczenia produktu.');
        }

        if ($resume) {
            $existingProduct = $this->findProductForLanguageByCreationToken(
                $integration,
                $language,
                $creationToken,
            );

            if ($existingProduct !== null) {
                return array_merge($existingProduct, ['idempotent_recovery' => true]);
            }
        }

        $payload['meta_data'] = collect((array) ($payload['meta_data'] ?? []))
            ->filter(fn (mixed $meta): bool => is_array($meta))
            ->reject(fn (array $meta): bool => in_array(
                (string) ($meta['key'] ?? ''),
                [
                    self::PRODUCT_TRANSLATION_CREATION_META_KEY,
                    '_ean',
                    '_sempre_erp_ean',
                ],
                true,
            ))
            ->push([
                'key' => self::PRODUCT_TRANSLATION_CREATION_META_KEY,
                'value' => $creationToken,
            ])
            ->values()
            ->all();
        $payload['sku'] = $this->productTranslationCreationSku($creationToken);
        unset($payload['global_unique_id']);
        $payload['status'] = 'draft';
        $payload['catalog_visibility'] = 'hidden';
        $payload['manage_stock'] = true;
        $payload['stock_quantity'] = 0;
        $payload['stock_status'] = 'outofstock';
        $payload['backorders'] = 'no';
        $url = $this->endpoint($integration, '/products').'?lang='.rawurlencode($language);

        try {
            $response = $this->request($integration, retry: false)->post($url, $payload);
        } catch (Throwable $exception) {
            $existingProduct = $this->findProductForLanguageByCreationToken(
                $integration,
                $language,
                $creationToken,
            );

            if ($existingProduct !== null) {
                return array_merge($existingProduct, ['idempotent_recovery' => true]);
            }

            throw $exception;
        }

        if (! $response->successful()) {
            $existingProduct = $this->findProductForLanguageByCreationToken(
                $integration,
                $language,
                $creationToken,
            );

            if ($existingProduct !== null) {
                return array_merge($existingProduct, ['idempotent_recovery' => true]);
            }

            throw new RuntimeException("Utworzenie produktu {$language} w WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        if (! is_array($json) || ! filled($json['id'] ?? null)) {
            $existingProduct = $this->findProductForLanguageByCreationToken(
                $integration,
                $language,
                $creationToken,
            );

            if ($existingProduct !== null) {
                return array_merge($existingProduct, ['idempotent_recovery' => true]);
            }
        }

        return is_array($json) ? $json : [];
    }

    /**
     * Resolve a previously-created translated product after an ambiguous POST
     * failure. WooCommerce exposes product meta in its authenticated catalog
     * response. A deterministic temporary SKU makes the lookup efficient and
     * prevents two concurrent retries from creating separate products; the
     * private meta marker still verifies that the result belongs to this run.
     *
     * @return array<string, mixed>|null
     */
    public function findProductForLanguageByCreationToken(
        WordpressIntegration $integration,
        string $language,
        string $creationToken,
    ): ?array {
        $creationToken = trim($creationToken);

        if ($creationToken === '') {
            return null;
        }

        $response = $this->request($integration)->get(
            $this->endpoint($integration, '/products'),
            [
                'lang' => $language,
                'sku' => $this->productTranslationCreationSku($creationToken),
                'status' => 'any',
                'per_page' => 100,
            ],
        );

        if (! $response->successful()) {
            throw new RuntimeException("Wyszukanie rozpoczętego tłumaczenia produktu {$language} zwróciło HTTP {$response->status()}.");
        }

        $matches = collect($response->json())
            ->filter(fn (mixed $product): bool => is_array($product) && filled($product['id'] ?? null))
            ->filter(fn (array $product): bool => collect((array) ($product['meta_data'] ?? []))
                ->contains(fn (mixed $meta): bool => is_array($meta)
                    && (string) ($meta['key'] ?? '') === self::PRODUCT_TRANSLATION_CREATION_META_KEY
                    && is_scalar($meta['value'] ?? null)
                    && hash_equals($creationToken, (string) ($meta['value'] ?? ''))))
            ->keyBy(fn (array $product): string => (string) $product['id']);

        if (count($matches) > 1) {
            throw new RuntimeException("WooCommerce zawiera więcej niż jedno tłumaczenie produktu {$language} z tym samym tokenem ERP.");
        }

        return $matches->isEmpty() ? null : $matches->first();
    }

    private function productTranslationCreationSku(string $creationToken): string
    {
        return self::PRODUCT_TRANSLATION_CREATION_SKU_PREFIX.substr(hash('sha256', $creationToken), 0, 40);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createProductVariation(
        WordpressIntegration $integration,
        string $externalProductId,
        array $payload,
        ?string $language = null,
    ): array {
        $url = $this->endpoint($integration, "/products/{$externalProductId}/variations");

        if (filled($language)) {
            $url .= '?lang='.rawurlencode((string) $language);
        }

        $response = $this->request($integration, retry: false)
            ->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Utworzenie wariantu WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function productVariation(
        WordpressIntegration $integration,
        string $externalProductId,
        string $externalVariationId,
    ): array {
        $response = $this->request($integration)->get($this->endpoint(
            $integration,
            "/products/{$externalProductId}/variations/{$externalVariationId}",
        ));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Pobranie wariantu WooCommerce #{$externalProductId}/{$externalVariationId} zwróciło HTTP {$response->status()}.",
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Read one product without running the broad catalog-import transformer.
     * Corrective jobs use the live Woo payload as their safety preflight and
     * must never infer the current attribute axis from a stale ERP snapshot.
     *
     * @return array<string, mixed>
     */
    public function productById(
        WordpressIntegration $integration,
        string $externalProductId,
    ): array {
        $response = $this->request($integration)->get($this->endpoint(
            $integration,
            "/products/{$externalProductId}",
        ));

        if (! $response->successful()) {
            throw new RuntimeException(
                "Pobranie produktu WooCommerce #{$externalProductId} zwróciło HTTP {$response->status()}.",
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Read raw variations for one exact translated parent. Unlike the import
     * iterator, this method keeps their real parent/variation IDs and payload.
     *
     * @return list<array<string, mixed>>
     */
    public function productVariationsByParent(
        WordpressIntegration $integration,
        string $externalProductId,
        ?string $language = null,
    ): array {
        $items = [];

        for ($page = 1; $page <= 50; $page++) {
            $query = [
                'per_page' => 100,
                'page' => $page,
            ];

            if (filled($language)) {
                $query['lang'] = mb_strtolower(trim((string) $language));
            }

            $response = $this->request($integration)->get(
                $this->endpoint($integration, "/products/{$externalProductId}/variations"),
                $query,
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Pobranie wariantów WooCommerce #{$externalProductId} zwróciło HTTP {$response->status()} na stronie {$page}.",
                );
            }

            $variations = $response->json();

            if (! is_array($variations) || $variations === []) {
                break;
            }

            foreach ($variations as $variation) {
                if (is_array($variation)) {
                    $items[] = $variation;
                }
            }

            if (count($variations) < 100) {
                break;
            }
        }

        return $items;
    }

    /**
     * Create a translated variation without assigning the canonical SKU until
     * Polylang has linked it to the primary variation. WooCommerce otherwise
     * rejects the second post as a duplicate SKU. The deterministic temporary
     * SKU and private token make an ambiguous POST safe to resume.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createProductVariationForLanguage(
        WordpressIntegration $integration,
        string $externalProductId,
        array $payload,
        string $language,
        string $creationToken,
        bool $resume = false,
    ): array {
        $creationToken = trim($creationToken);

        if ($creationToken === '') {
            throw new RuntimeException('ERP nie przygotował tokenu idempotencji tłumaczenia wariantu.');
        }

        if ($resume) {
            $existingVariation = $this->findProductVariationForLanguageByCreationToken(
                $integration,
                $externalProductId,
                $language,
                $creationToken,
            );

            if ($existingVariation !== null) {
                return array_merge($existingVariation, ['idempotent_recovery' => true]);
            }
        }

        $payload['meta_data'] = collect((array) ($payload['meta_data'] ?? []))
            ->filter(fn (mixed $meta): bool => is_array($meta))
            ->reject(fn (array $meta): bool => in_array(
                (string) ($meta['key'] ?? ''),
                [
                    self::PRODUCT_VARIATION_TRANSLATION_CREATION_META_KEY,
                    '_ean',
                    '_sempre_erp_ean',
                ],
                true,
            ))
            ->push([
                'key' => self::PRODUCT_VARIATION_TRANSLATION_CREATION_META_KEY,
                'value' => $creationToken,
            ])
            ->values()
            ->all();
        $payload['sku'] = $this->productVariationTranslationCreationSku($creationToken);
        unset($payload['global_unique_id']);
        $payload['status'] = 'draft';
        $payload['manage_stock'] = true;
        $payload['stock_quantity'] = 0;
        $payload['stock_status'] = 'outofstock';
        $payload['backorders'] = 'no';
        $url = $this->endpoint($integration, "/products/{$externalProductId}/variations")
            .'?lang='.rawurlencode($language);

        try {
            $response = $this->request($integration, retry: false)->post($url, $payload);
        } catch (Throwable $exception) {
            $existingVariation = $this->findProductVariationForLanguageByCreationToken(
                $integration,
                $externalProductId,
                $language,
                $creationToken,
            );

            if ($existingVariation !== null) {
                return array_merge($existingVariation, ['idempotent_recovery' => true]);
            }

            throw $exception;
        }

        if (! $response->successful()) {
            $existingVariation = $this->findProductVariationForLanguageByCreationToken(
                $integration,
                $externalProductId,
                $language,
                $creationToken,
            );

            if ($existingVariation !== null) {
                return array_merge($existingVariation, ['idempotent_recovery' => true]);
            }

            $message = trim((string) data_get($response->json(), 'message', ''));
            $details = $message !== '' ? ": {$message}" : '.';

            throw new RuntimeException(
                "Utworzenie wariantu {$language} w WooCommerce zwróciło HTTP {$response->status()}{$details}",
            );
        }

        $json = $response->json();

        if (! is_array($json) || ! filled($json['id'] ?? null)) {
            $existingVariation = $this->findProductVariationForLanguageByCreationToken(
                $integration,
                $externalProductId,
                $language,
                $creationToken,
            );

            if ($existingVariation !== null) {
                return array_merge($existingVariation, ['idempotent_recovery' => true]);
            }
        }

        return is_array($json) ? $json : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findProductVariationForLanguageByCreationToken(
        WordpressIntegration $integration,
        string $externalProductId,
        string $language,
        string $creationToken,
    ): ?array {
        $creationToken = trim($creationToken);

        if ($creationToken === '') {
            return null;
        }

        $response = $this->request($integration)->get(
            $this->endpoint($integration, "/products/{$externalProductId}/variations"),
            [
                'lang' => $language,
                'sku' => $this->productVariationTranslationCreationSku($creationToken),
                'status' => 'any',
                'per_page' => 100,
            ],
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                "Wyszukanie rozpoczętego tłumaczenia wariantu {$language} produktu #{$externalProductId} zwróciło HTTP {$response->status()}.",
            );
        }

        $matches = collect($response->json())
            ->filter(fn (mixed $variation): bool => is_array($variation) && filled($variation['id'] ?? null))
            ->filter(fn (array $variation): bool => collect((array) ($variation['meta_data'] ?? []))
                ->contains(fn (mixed $meta): bool => is_array($meta)
                    && (string) ($meta['key'] ?? '') === self::PRODUCT_VARIATION_TRANSLATION_CREATION_META_KEY
                    && is_scalar($meta['value'] ?? null)
                    && hash_equals($creationToken, (string) ($meta['value'] ?? ''))))
            ->keyBy(fn (array $variation): string => (string) $variation['id']);

        if ($matches->count() > 1) {
            throw new RuntimeException(
                "WooCommerce zawiera więcej niż jedno tłumaczenie wariantu {$language} z tym samym tokenem ERP.",
            );
        }

        return $matches->isEmpty() ? null : $matches->first();
    }

    private function productVariationTranslationCreationSku(string $creationToken): string
    {
        return self::PRODUCT_VARIATION_TRANSLATION_CREATION_SKU_PREFIX
            .substr(hash('sha256', $creationToken), 0, 40);
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return array<string, mixed>|null
     */
    public function findProductVariation(
        WordpressIntegration $integration,
        string $externalProductId,
        ?string $primaryExternalVariationId,
        string $sku,
        array $attributes,
        ?string $language = null,
    ): ?array {
        $sku = trim($sku);
        $normalizedSku = mb_strtolower($sku);
        $primaryExternalVariationId = trim((string) $primaryExternalVariationId);
        $candidates = [];

        for ($page = 1; $page <= 50; $page++) {
            $query = [
                'per_page' => 100,
                'page' => $page,
            ];

            if (filled($language)) {
                $query['lang'] = $language;
            }

            $response = $this->request($integration)
                ->get($this->endpoint($integration, "/products/{$externalProductId}/variations"), $query);

            if (! $response->successful()) {
                throw new RuntimeException("Wyszukanie wariantów produktu WooCommerce #{$externalProductId} zwróciło HTTP {$response->status()}.");
            }

            $pageItems = $response->json();

            if (! is_array($pageItems) || $pageItems === []) {
                break;
            }

            foreach ($pageItems as $variation) {
                if (is_array($variation) && isset($variation['id'])) {
                    $candidates[] = $variation;
                }
            }

            if (count($pageItems) < 100) {
                break;
            }
        }

        if ($primaryExternalVariationId !== '') {
            $translationMatches = collect($candidates)
                ->filter(fn (array $variation): bool => in_array(
                    $primaryExternalVariationId,
                    $this->translationIds(
                        $variation['lemon_erp_translations'] ?? $variation['translations'] ?? [],
                    ),
                    true,
                ))
                ->values();

            if ($translationMatches->count() === 1) {
                return $translationMatches->first();
            }

            if ($translationMatches->isNotEmpty()) {
                $candidates = $translationMatches->all();
            }
        }

        if ($sku !== '') {
            $skuMatches = collect($candidates)
                ->filter(fn (array $variation): bool => mb_strtolower(
                    trim((string) ($variation['sku'] ?? '')),
                ) === $normalizedSku)
                ->values();

            if ($skuMatches->count() === 1) {
                return $skuMatches->first();
            }

            if ($skuMatches->isNotEmpty()) {
                $candidates = $skuMatches->all();
            }
        }

        $signature = $this->variationAttributeSignature($attributes);

        if ($signature === '') {
            return null;
        }

        $attributeMatches = collect($candidates)
            ->filter(fn (array $variation): bool => $this->variationAttributeSignature(
                (array) ($variation['attributes'] ?? []),
            ) === $signature)
            ->values();

        return $attributeMatches->count() === 1 ? $attributeMatches->first() : null;
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

        $response = $this->request($integration, retry: false)->post($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Utworzenie kategorii produktu w WooCommerce zwróciło HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Resolve one canonical WooCommerce product attribute and make sure every
     * localized option exists as a term of that taxonomy. Polish and English
     * product payloads deliberately receive the same attribute ID; only term
     * labels may differ by language.
     *
     * @param  list<string>  $options
     * @param  list<string>  $sourceOptions  Logical Polish values aligned with localized options.
     * @param  list<int|null>  $menuOrders  Canonical dictionary ranks aligned with source options.
     * @return array{id:int,name:string,options:list<string>,term_ids:list<int>}
     */
    public function ensureGlobalProductAttribute(
        WordpressIntegration $integration,
        string $sourceName,
        array $options,
        ?string $language = null,
        array $sourceOptions = [],
        array $menuOrders = [],
    ): array {
        $sourceName = trim($sourceName);

        if ($sourceName === '') {
            throw new RuntimeException('Nie można utworzyć globalnego atrybutu WooCommerce bez nazwy źródłowej.');
        }

        $optionPairs = collect($options)
            ->map(function (mixed $option, int $index) use ($sourceOptions, $menuOrders): array {
                $localized = trim((string) $option);
                $source = trim((string) ($sourceOptions[$index] ?? $localized));
                $menuOrder = $menuOrders[$index] ?? null;

                return [
                    'localized' => $localized,
                    'source' => $source !== '' ? $source : $localized,
                    'menu_order' => is_numeric($menuOrder)
                        ? max(0, min(65535, (int) $menuOrder))
                        : null,
                ];
            })
            ->filter(fn (array $pair): bool => $pair['localized'] !== '')
            ->unique(fn (array $pair): string => mb_strtolower($pair['localized']))
            ->values()
            ->all();
        $attribute = $this->findGlobalProductAttribute($integration, $sourceName);

        if ($attribute === null) {
            $slug = $this->globalProductAttributeSlug($sourceName);
            $response = null;
            $creationException = null;

            try {
                // Attribute creation is not safe to retry automatically. A
                // timeout may happen after Woo committed the taxonomy.
                $response = $this->request($integration, retry: false)
                    ->post($this->endpoint($integration, '/products/attributes'), [
                        'name' => $sourceName,
                        // WooCommerce's REST contract expects the registered
                        // taxonomy slug, including the `pa_` prefix.
                        'slug' => 'pa_'.$slug,
                        'type' => 'select',
                        'order_by' => 'menu_order',
                        'has_archives' => false,
                    ]);
            } catch (Throwable $exception) {
                $creationException = $exception;
            }

            $created = $response?->successful() ? $response->json() : null;

            if (is_array($created) && filled($created['id'] ?? null)) {
                $attribute = $created;
                $this->appendGlobalProductAttributeCache($integration, $created);
            } else {
                // Handle a concurrent create, a term_exists style collision,
                // and an ambiguous transport failure with one deterministic
                // refetch by canonical slug/name.
                $this->forgetGlobalProductAttributes($integration);
                $attribute = $this->findGlobalProductAttribute($integration, $sourceName);
            }

            if ($attribute === null) {
                $status = $response?->status();
                $details = $status !== null ? " (HTTP {$status})" : '';

                throw new RuntimeException(
                    "WooCommerce nie utworzył globalnego atrybutu produktu {$sourceName}{$details}.",
                    0,
                    $creationException,
                );
            }
        }

        $attributeId = (int) ($attribute['id'] ?? 0);

        if ($attributeId <= 0) {
            throw new RuntimeException("WooCommerce zwrócił nieprawidłowe ID globalnego atrybutu {$sourceName}.");
        }

        // An omitted field is not confirmation that the remote taxonomy uses
        // the requested order. Some Woo/Polylang response filters remove it,
        // while the storefront then falls back to alphabetical term order.
        if (collect($optionPairs)->contains(fn (array $pair): bool => $pair['menu_order'] !== null)
            && (string) ($attribute['order_by'] ?? '') !== 'menu_order'
        ) {
            $response = $this->request($integration)->put(
                $this->endpoint($integration, "/products/attributes/{$attributeId}"),
                ['order_by' => 'menu_order'],
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Ustawienie własnej kolejności globalnego atrybutu {$sourceName} zwróciło HTTP {$response->status()}.",
                );
            }

            $updated = $response->json();
            $attribute = array_merge(
                $attribute,
                is_array($updated) ? $updated : [],
                ['order_by' => 'menu_order'],
            );
            $this->replaceGlobalProductAttributeCache($integration, $attribute);
        }

        $resolvedOptions = [];
        $resolvedTermIds = [];
        $language = filled($language) ? mb_strtolower(trim((string) $language)) : null;

        foreach ($optionPairs as $pair) {
            $sourceTerm = null;
            $excludedTargetTermIds = [];

            if ($language !== null && $language !== 'pl') {
                $sourceTerm = $this->ensureGlobalProductAttributeTerm(
                    $integration,
                    $attributeId,
                    $pair['source'],
                    'pl',
                    [],
                    $pair['menu_order'],
                );
                $excludedTargetTermIds[] = (int) $sourceTerm['id'];
            }

            $term = $this->ensureGlobalProductAttributeTerm(
                $integration,
                $attributeId,
                $pair['localized'],
                $language,
                $excludedTargetTermIds,
                $pair['menu_order'],
            );
            $resolvedOptions[] = trim((string) ($term['name'] ?? $pair['localized'])) ?: $pair['localized'];
            $resolvedTermIds[] = (int) $term['id'];

            if (is_array($sourceTerm)) {
                $this->linkGlobalProductAttributeTermTranslations($integration, $attributeId, [
                    'pl' => (int) $sourceTerm['id'],
                    $language => (int) $term['id'],
                ]);
            }
        }

        return [
            'id' => $attributeId,
            'name' => trim((string) ($attribute['name'] ?? $sourceName)) ?: $sourceName,
            'options' => $resolvedOptions,
            'term_ids' => $resolvedTermIds,
        ];
    }

    /**
     * Resolve an already existing global product attribute without creating or
     * changing anything in WooCommerce. Maintenance repairs use this read-only
     * path so a custom-text legacy attribute can never create a second taxonomy.
     *
     * @return array<string, mixed>|null
     */
    public function globalProductAttributeByName(
        WordpressIntegration $integration,
        string $sourceName,
    ): ?array {
        return $this->findGlobalProductAttribute($integration, $sourceName);
    }

    /**
     * Read the existing terms of a global product attribute without creating,
     * translating or reordering them.
     *
     * @return list<array<string, mixed>>
     */
    public function globalProductAttributeTermsById(
        WordpressIntegration $integration,
        int $attributeId,
        ?string $language = null,
    ): array {
        if ($attributeId <= 0) {
            throw new RuntimeException('Pobranie wartości globalnego atrybutu wymaga prawidłowego ID.');
        }

        return $this->globalProductAttributeTerms(
            $integration,
            $attributeId,
            filled($language) ? mb_strtolower(trim((string) $language)) : null,
        );
    }

    /**
     * Switch an already existing global taxonomy to explicit term ordering.
     * This maintenance path never creates an attribute.
     *
     * @param  array<string, mixed>  $attribute
     * @return array<string, mixed>
     */
    public function setExistingGlobalProductAttributeMenuOrder(
        WordpressIntegration $integration,
        array $attribute,
    ): array {
        $attributeId = (int) ($attribute['id'] ?? 0);

        if ($attributeId <= 0) {
            throw new RuntimeException('Ustawienie kolejności globalnego atrybutu wymaga prawidłowego ID.');
        }

        if ((string) ($attribute['order_by'] ?? '') === 'menu_order') {
            return $attribute;
        }

        $response = $this->request($integration)->put(
            $this->endpoint($integration, "/products/attributes/{$attributeId}"),
            ['order_by' => 'menu_order'],
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                "Ustawienie własnej kolejności globalnego atrybutu #{$attributeId} zwróciło HTTP {$response->status()}.",
            );
        }

        $updated = $response->json();
        $attribute = array_merge(
            $attribute,
            is_array($updated) ? $updated : [],
            ['order_by' => 'menu_order'],
        );
        $this->replaceGlobalProductAttributeCache($integration, $attribute);

        return $attribute;
    }

    /**
     * Correct only an existing term. Product creation and Polylang linking are
     * deliberately outside this narrow maintenance operation.
     *
     * @param  array<string, mixed>  $term
     * @return array<string, mixed>
     */
    public function updateExistingGlobalProductAttributeTerm(
        WordpressIntegration $integration,
        int $attributeId,
        array $term,
        string $name,
        int $menuOrder,
    ): array {
        $termId = (int) ($term['id'] ?? 0);
        $name = trim($name);
        $menuOrder = max(0, min(65535, $menuOrder));

        if ($attributeId <= 0 || $termId <= 0 || $name === '') {
            throw new RuntimeException('Aktualizacja wartości globalnego atrybutu wymaga prawidłowego ID i nazwy.');
        }

        $payload = [];

        if (trim((string) ($term['name'] ?? '')) !== $name) {
            $payload['name'] = $name;
        }

        if (! array_key_exists('menu_order', $term)
            || (int) $term['menu_order'] !== $menuOrder
        ) {
            $payload['menu_order'] = $menuOrder;
        }

        if ($payload === []) {
            return $term;
        }

        $response = $this->request($integration)->put(
            $this->endpoint(
                $integration,
                "/products/attributes/{$attributeId}/terms/{$termId}",
            ),
            $payload,
        );

        if (! $response->successful()) {
            throw new RuntimeException(
                "Aktualizacja wartości #{$termId} globalnego atrybutu #{$attributeId} zwróciła HTTP {$response->status()}.",
            );
        }

        $updated = $response->json();
        $term = array_merge(
            $term,
            is_array($updated) ? $updated : [],
            $payload,
        );
        $this->replaceGlobalProductAttributeTermCache($integration, $attributeId, $term);

        return $term;
    }

    /** @param array<string, int> $translations */
    private function linkGlobalProductAttributeTermTranslations(
        WordpressIntegration $integration,
        int $attributeId,
        array $translations,
    ): void {
        ksort($translations);
        $signature = $this->globalProductAttributeCacheKey($integration)
            .'|'.$attributeId
            .'|'.sha1(json_encode($translations, JSON_UNESCAPED_SLASHES) ?: '');

        if (isset($this->linkedGlobalProductAttributeTermTranslations[$signature])) {
            return;
        }

        $this->linkProductAttributeTermTranslations($integration, $attributeId, $translations);
        $this->linkedGlobalProductAttributeTermTranslations[$signature] = true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findGlobalProductAttribute(
        WordpressIntegration $integration,
        string $sourceName,
    ): ?array {
        $slug = $this->globalProductAttributeSlug($sourceName);
        $normalizedName = mb_strtolower(trim($sourceName));
        $matches = collect($this->globalProductAttributes($integration))
            ->filter(function (array $attribute) use ($slug, $normalizedName): bool {
                $candidateSlug = $this->normalizeGlobalProductAttributeSlug(
                    (string) ($attribute['slug'] ?? ''),
                );
                $candidateName = mb_strtolower(trim((string) ($attribute['name'] ?? '')));

                return $candidateSlug === $slug || $candidateName === $normalizedName;
            })
            ->filter(fn (array $attribute): bool => (int) ($attribute['id'] ?? 0) > 0)
            ->unique(fn (array $attribute): int => (int) $attribute['id'])
            ->values();

        if ($matches->count() > 1) {
            throw new RuntimeException(
                "WooCommerce zawiera kilka globalnych atrybutów pasujących do {$sourceName}; eksport został przerwany, aby nie przypisać złej taksonomii.",
            );
        }

        return $matches->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function globalProductAttributes(WordpressIntegration $integration): array
    {
        $cacheKey = $this->globalProductAttributeCacheKey($integration);

        if (array_key_exists($cacheKey, $this->globalProductAttributesCache)) {
            return $this->globalProductAttributesCache[$cacheKey];
        }

        $attributes = [];

        for ($page = 1; $page <= 100; $page++) {
            $response = $this->request($integration)->get(
                $this->endpoint($integration, '/products/attributes'),
                ['per_page' => 100, 'page' => $page],
            );

            if (! $response->successful()) {
                throw new RuntimeException("Pobranie globalnych atrybutów WooCommerce zwróciło HTTP {$response->status()}.");
            }

            $items = $response->json();

            if (! is_array($items) || $items === []) {
                break;
            }

            $pageItems = collect($items)
                ->filter(fn (mixed $item): bool => is_array($item) && filled($item['id'] ?? null))
                ->values()
                ->all();
            array_push($attributes, ...$pageItems);

            if (count($items) < 100) {
                break;
            }
        }

        return $this->globalProductAttributesCache[$cacheKey] = $attributes;
    }

    /**
     * @param  list<int>  $excludedTermIds
     * @return array<string, mixed>
     */
    private function ensureGlobalProductAttributeTerm(
        WordpressIntegration $integration,
        int $attributeId,
        string $option,
        ?string $language,
        array $excludedTermIds = [],
        ?int $menuOrder = null,
    ): array {
        $language = filled($language) ? mb_strtolower(trim((string) $language)) : null;
        $term = $this->findGlobalProductAttributeTerm(
            $integration,
            $attributeId,
            $option,
            $language,
            $excludedTermIds,
        );

        if ($term === null) {
            $url = $this->endpoint($integration, "/products/attributes/{$attributeId}/terms");

            if ($language !== null) {
                $url .= '?lang='.rawurlencode($language);
            }

            $response = null;
            $creationException = null;

            try {
                // As above, never replay a mutating POST automatically.
                $termPayload = [
                    'name' => $option,
                    'slug' => $this->globalProductAttributeTermSlug($option, $language),
                ];

                if ($menuOrder !== null) {
                    $termPayload['menu_order'] = max(0, min(65535, $menuOrder));
                }

                $response = $this->request($integration, retry: false)->post($url, $termPayload);
            } catch (Throwable $exception) {
                $creationException = $exception;
            }

            $created = $response?->successful() ? $response->json() : null;

            if (is_array($created)
                && filled($created['id'] ?? null)
                && ! in_array((int) $created['id'], $excludedTermIds, true)
            ) {
                $term = $created;
                $this->appendGlobalProductAttributeTermCache($integration, $attributeId, $language, $created);
            } else {
                $this->forgetGlobalProductAttributeTerms($integration, $attributeId, $language);
                $term = $this->findGlobalProductAttributeTerm(
                    $integration,
                    $attributeId,
                    $option,
                    $language,
                    $excludedTermIds,
                );
            }

            if ($term === null) {
                $status = $response?->status();
                $details = $status !== null ? " (HTTP {$status})" : '';

                throw new RuntimeException(
                    "WooCommerce nie utworzył wartości {$option} globalnego atrybutu #{$attributeId}{$details}.",
                    0,
                    $creationException,
                );
            }
        }

        // Treat a missing response field as unknown and write the canonical
        // value once. The updated response is cached, keeping later calls in
        // this export idempotent.
        if ($menuOrder !== null
            && (! array_key_exists('menu_order', $term)
                || (int) $term['menu_order'] !== $menuOrder)
        ) {
            $response = $this->request($integration)->put(
                $this->endpoint(
                    $integration,
                    "/products/attributes/{$attributeId}/terms/".(int) $term['id'],
                ),
                ['menu_order' => $menuOrder],
            );

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Ustawienie kolejności wartości {$option} globalnego atrybutu #{$attributeId} zwróciło HTTP {$response->status()}.",
                );
            }

            $updated = $response->json();
            $term = array_merge(
                $term,
                is_array($updated) ? $updated : [],
                ['menu_order' => $menuOrder],
            );
            $this->replaceGlobalProductAttributeTermCache($integration, $attributeId, $term);
        }

        return $term;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findGlobalProductAttributeTerm(
        WordpressIntegration $integration,
        int $attributeId,
        string $option,
        ?string $language,
        array $excludedTermIds = [],
    ): ?array {
        $slug = $this->globalProductAttributeTermSlug($option, $language);
        $normalizedName = mb_strtolower(trim($option));
        $candidates = collect($this->globalProductAttributeTerms($integration, $attributeId, $language))
            ->filter(fn (array $term): bool => (int) ($term['id'] ?? 0) > 0)
            ->reject(fn (array $term): bool => in_array((int) $term['id'], $excludedTermIds, true))
            ->unique(fn (array $term): int => (int) $term['id'])
            ->values();

        $matches = $candidates
            ->filter(function (array $term) use ($slug, $normalizedName): bool {
                return Str::slug((string) ($term['slug'] ?? '')) === $slug
                    || mb_strtolower(trim((string) ($term['name'] ?? ''))) === $normalizedName;
            })
            ->values();

        if ($matches->isEmpty()) {
            return null;
        }

        if ($matches->count() === 1) {
            $match = $matches->first();

            if ($language === null) {
                return $match;
            }

            if ($this->globalProductAttributeTermMatchesLanguage($match, $language)) {
                return $match;
            }

            if ($this->globalProductAttributeTermHasLanguageIdentity($match)
                || $this->globalProductAttributeTermHasForeignLocalizedSlug(
                    $integration,
                    $match,
                    $option,
                    $language,
                )
            ) {
                return null;
            }

            return $match;
        }

        // Prefer an explicit Polylang identity when the REST response exposes
        // it. Some installations return terms from more than one language even
        // for a request containing ?lang=, so usage count alone must never be
        // treated as a language signal.
        $languageMatches = $language === null
            ? collect()
            : $matches->filter(
                fn (array $term): bool => $this->globalProductAttributeTermMatchesLanguage($term, $language),
            )->values();

        if ($languageMatches->count() === 1) {
            return $languageMatches->first();
        }

        if ($languageMatches->count() > 1) {
            $matches = $languageMatches;
        }

        // WooCommerce/Polylang can ignore `?lang=` for attribute-term
        // collections and return every translation with the same visible
        // name. Deterministic localized slugs still let us discard terms
        // that explicitly belong to another configured language. This keeps
        // the legacy Polish base slug (`sempre`) separate from `sempre-en`
        // without using the mutable usage count as a language signal.
        if ($language !== null && $languageMatches->isEmpty()) {
            $languageScopedMatches = $matches
                ->reject(fn (array $term): bool => $this->globalProductAttributeTermHasForeignLocalizedSlug(
                    $integration,
                    $term,
                    $option,
                    $language,
                ))
                ->values();

            if ($languageScopedMatches->isNotEmpty()) {
                $matches = $languageScopedMatches;
            }
        }

        // Keep a legacy term that is already used by the catalog instead of
        // switching products to an empty term left by an interrupted preflight.
        // This is safe only for the language-neutral base slug in the Polish
        // source language. A used `*-en` term must not win a Polish lookup.
        $allCountsKnown = $matches->every(
            fn (array $term): bool => array_key_exists('count', $term) && is_numeric($term['count']),
        );
        $usedMatches = $matches
            ->filter(fn (array $term): bool => (int) ($term['count'] ?? 0) > 0)
            ->values();

        // Polylang installations can expose all language terms even when the
        // WooCommerce REST request contains ?lang=. Labels such as "SEMPRE"
        // are identical in PL and EN, while ERP's deterministic slugs remain
        // distinct (sempre-pl / sempre-en). An exact localized slug is the only
        // safe fallback when usage counts do not identify one canonical term.
        $slugMatches = $matches
            ->filter(fn (array $term): bool => Str::slug((string) ($term['slug'] ?? '')) === $slug)
            ->values();

        // The unsuffixed legacy slug belongs to the Polish source catalog.
        // For another language, a single exact localized slug is therefore
        // safe when its only competitor is that Polish base term. Unknown
        // duplicate slugs remain ambiguous and still abort the export.
        if ($language !== null && $language !== 'pl' && $slugMatches->count() === 1) {
            $exactMatchId = (int) ($slugMatches->first()['id'] ?? 0);
            $baseSlug = $this->globalProductAttributeTermSlug($option);
            $otherMatches = $matches
                ->reject(fn (array $term): bool => (int) ($term['id'] ?? 0) === $exactMatchId)
                ->values();

            if ($otherMatches->isNotEmpty()
                && $otherMatches->every(
                    fn (array $term): bool => Str::slug((string) ($term['slug'] ?? '')) === $baseSlug,
                )
            ) {
                return $slugMatches->first();
            }
        }

        if ($usedMatches->count() > 1) {
            $slugMatches = collect();
        } elseif ($allCountsKnown && $usedMatches->count() === 1) {
            $used = $usedMatches->first();
            $usedSlug = Str::slug((string) ($used['slug'] ?? ''));
            $baseSlug = $this->globalProductAttributeTermSlug($option);

            if ($usedSlug === $slug
                || ($language === 'pl' && $usedSlug === $baseSlug)
            ) {
                return $used;
            }
        }

        if ($slugMatches->count() === 1) {
            return $slugMatches->first();
        }

        $details = $matches
            ->map(fn (array $term): string => sprintf(
                '#%d slug=%s count=%s',
                (int) ($term['id'] ?? 0),
                trim((string) ($term['slug'] ?? '')) ?: '-',
                array_key_exists('count', $term) ? (string) $term['count'] : '?',
            ))
            ->implode(', ');

        throw new RuntimeException(
            "WooCommerce zawiera kilka wartości {$option} globalnego atrybutu #{$attributeId} ({$details}); eksport został przerwany.",
        );
    }

    /** @param array<string, mixed> $term */
    private function globalProductAttributeTermHasLanguageIdentity(array $term): bool
    {
        return filled($term['lang'] ?? $term['language'] ?? null)
            || array_key_exists('translations', $term);
    }

    /** @param array<string, mixed> $term */
    private function globalProductAttributeTermMatchesLanguage(array $term, string $language): bool
    {
        $termId = (int) ($term['id'] ?? 0);
        $termLanguage = mb_strtolower(trim((string) ($term['lang'] ?? $term['language'] ?? '')));
        $translatedTermId = (int) data_get($term, "translations.{$language}", 0);

        return $termLanguage === $language
            || ($termId > 0 && $translatedTermId === $termId);
    }

    /** @param array<string, mixed> $term */
    private function globalProductAttributeTermHasForeignLocalizedSlug(
        WordpressIntegration $integration,
        array $term,
        string $option,
        string $language,
    ): bool {
        $termSlug = Str::slug((string) ($term['slug'] ?? ''));

        return collect($integration->productExportLanguages())
            ->map(fn (mixed $candidate): string => mb_strtolower(trim((string) $candidate)))
            ->filter(fn (string $candidate): bool => $candidate !== '' && $candidate !== $language)
            ->contains(
                fn (string $candidate): bool => $termSlug
                    === $this->globalProductAttributeTermSlug($option, $candidate),
            );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function globalProductAttributeTerms(
        WordpressIntegration $integration,
        int $attributeId,
        ?string $language,
    ): array {
        $cacheKey = $this->globalProductAttributeTermsCacheKey($integration, $attributeId, $language);

        if (array_key_exists($cacheKey, $this->globalProductAttributeTermsCache)) {
            return $this->globalProductAttributeTermsCache[$cacheKey];
        }

        $terms = [];

        for ($page = 1; $page <= 100; $page++) {
            $query = ['per_page' => 100, 'page' => $page];

            if (filled($language)) {
                $query['lang'] = $language;
            }

            $response = $this->request($integration)->get(
                $this->endpoint($integration, "/products/attributes/{$attributeId}/terms"),
                $query,
            );

            if (! $response->successful()) {
                throw new RuntimeException("Pobranie wartości globalnego atrybutu WooCommerce #{$attributeId} zwróciło HTTP {$response->status()}.");
            }

            $items = $response->json();

            if (! is_array($items) || $items === []) {
                break;
            }

            $pageItems = collect($items)
                ->filter(fn (mixed $item): bool => is_array($item) && filled($item['id'] ?? null))
                ->values()
                ->all();
            array_push($terms, ...$pageItems);

            if (count($items) < 100) {
                break;
            }
        }

        return $this->globalProductAttributeTermsCache[$cacheKey] = $terms;
    }

    /** @param array<string, mixed> $attribute */
    private function appendGlobalProductAttributeCache(WordpressIntegration $integration, array $attribute): void
    {
        $cacheKey = $this->globalProductAttributeCacheKey($integration);

        if (array_key_exists($cacheKey, $this->globalProductAttributesCache)) {
            $this->globalProductAttributesCache[$cacheKey][] = $attribute;
        }
    }

    /** @param array<string, mixed> $attribute */
    private function replaceGlobalProductAttributeCache(
        WordpressIntegration $integration,
        array $attribute,
    ): void {
        $cacheKey = $this->globalProductAttributeCacheKey($integration);

        if (! array_key_exists($cacheKey, $this->globalProductAttributesCache)) {
            return;
        }

        $attributeId = (int) ($attribute['id'] ?? 0);
        $this->globalProductAttributesCache[$cacheKey] = collect(
            $this->globalProductAttributesCache[$cacheKey],
        )
            ->map(fn (array $cached): array => (int) ($cached['id'] ?? 0) === $attributeId
                ? array_merge($cached, $attribute)
                : $cached)
            ->all();
    }

    private function forgetGlobalProductAttributes(WordpressIntegration $integration): void
    {
        unset($this->globalProductAttributesCache[$this->globalProductAttributeCacheKey($integration)]);
    }

    /** @param array<string, mixed> $term */
    private function appendGlobalProductAttributeTermCache(
        WordpressIntegration $integration,
        int $attributeId,
        ?string $language,
        array $term,
    ): void {
        $cacheKey = $this->globalProductAttributeTermsCacheKey($integration, $attributeId, $language);

        if (array_key_exists($cacheKey, $this->globalProductAttributeTermsCache)) {
            $this->globalProductAttributeTermsCache[$cacheKey][] = $term;
        }
    }

    /** @param array<string, mixed> $term */
    private function replaceGlobalProductAttributeTermCache(
        WordpressIntegration $integration,
        int $attributeId,
        array $term,
    ): void {
        $prefix = $this->globalProductAttributeCacheKey($integration).'|'.$attributeId.'|';
        $termId = (int) ($term['id'] ?? 0);

        foreach ($this->globalProductAttributeTermsCache as $cacheKey => $cachedTerms) {
            if (! str_starts_with($cacheKey, $prefix)) {
                continue;
            }

            $this->globalProductAttributeTermsCache[$cacheKey] = collect($cachedTerms)
                ->map(fn (array $cached): array => (int) ($cached['id'] ?? 0) === $termId
                    ? array_merge($cached, $term)
                    : $cached)
                ->all();
        }
    }

    private function forgetGlobalProductAttributeTerms(
        WordpressIntegration $integration,
        int $attributeId,
        ?string $language,
    ): void {
        unset($this->globalProductAttributeTermsCache[
            $this->globalProductAttributeTermsCacheKey($integration, $attributeId, $language)
        ]);
    }

    private function globalProductAttributeCacheKey(WordpressIntegration $integration): string
    {
        return (string) ($integration->getKey() ?: $integration->base_url);
    }

    private function globalProductAttributeTermsCacheKey(
        WordpressIntegration $integration,
        int $attributeId,
        ?string $language,
    ): string {
        return $this->globalProductAttributeCacheKey($integration)
            .'|'.$attributeId
            .'|'.(filled($language) ? mb_strtolower(trim((string) $language)) : '*');
    }

    private function globalProductAttributeSlug(string $name): string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'erp-'.substr(sha1($name), 0, 12);
        }

        if (strlen($slug) > 28) {
            $slug = substr($slug, 0, 19).'-'.substr(sha1($slug), 0, 8);
        }

        return $slug;
    }

    private function normalizeGlobalProductAttributeSlug(string $slug): string
    {
        $slug = preg_replace('/^pa_/', '', trim($slug)) ?? trim($slug);

        return Str::slug($slug);
    }

    private function globalProductAttributeTermSlug(string $option, ?string $language = null): string
    {
        $slug = Str::slug($option);

        if ($slug === '') {
            $slug = 'erp-'.substr(sha1($option), 0, 12);
        }

        if (filled($language)) {
            $slug .= '-'.Str::slug((string) $language);
        }

        return $slug;
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
            $response = $this->wordpressRequest($integration, retry: false)->post(
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

    /**
     * Link separately-created WooCommerce product posts as Polylang translations.
     *
     * This endpoint deliberately uses the WooCommerce consumer key and secret,
     * so product translation linking does not depend on WordPress Application
     * Password credentials used for media uploads.
     *
     * @param  array<string, int|string>  $translations
     * @return array<string, mixed>
     */
    public function linkProductTranslations(WordpressIntegration $integration, array $translations): array
    {
        $translations = $this->validatedProductTranslationMap($translations);

        try {
            $response = $this->request($integration, retry: false)
                ->withoutRedirecting()
                ->post(
                    $this->wordpressRestEndpoint($integration, '/wc-lemon-erp/v1/catalog/products/translations'),
                    ['translations' => $translations],
                );
        } catch (RequestException $exception) {
            $this->throwProductTranslationLinkHttpException($exception->response);
        }

        if (! $response->successful()) {
            $this->throwProductTranslationLinkHttpException($response);
        }

        $json = $response->json();

        if (! is_array($json)
            || data_get($json, 'linked') !== true
            || $this->confirmedProductTranslationMap(data_get($json, 'translations')) !== $translations
        ) {
            throw new RuntimeException('WordPress zwrócił niepełne potwierdzenie powiązania tłumaczeń produktów.');
        }

        return $json;
    }

    /**
     * Link language-specific WooCommerce variation posts only after their
     * parent products are already a verified Polylang family.
     *
     * @param  array<string, int|string>  $translations
     * @param  array<string, int|string>  $parents
     * @return array<string, mixed>
     */
    public function linkProductVariationTranslations(
        WordpressIntegration $integration,
        array $translations,
        array $parents,
    ): array {
        $translations = $this->validatedProductTranslationMap($translations);
        $parents = $this->validatedProductTranslationMap($parents);

        if (array_keys($translations) !== array_keys($parents)) {
            throw new RuntimeException(
                'Tłumaczenia wariantów i ich produktów nadrzędnych muszą obejmować te same języki.',
            );
        }

        try {
            $response = $this->request($integration, retry: false)
                ->withoutRedirecting()
                ->post(
                    $this->wordpressRestEndpoint(
                        $integration,
                        '/wc-lemon-erp/v1/catalog/products/variations/translations',
                    ),
                    [
                        'translations' => $translations,
                        'parents' => $parents,
                    ],
                );
        } catch (RequestException $exception) {
            $this->throwProductVariationTranslationLinkHttpException($exception->response);
        }

        if (! $response->successful()) {
            $this->throwProductVariationTranslationLinkHttpException($response);
        }

        $json = $response->json();

        if (! is_array($json)
            || data_get($json, 'linked') !== true
            || $this->confirmedProductTranslationMap(data_get($json, 'translations')) !== $translations
            || $this->confirmedProductTranslationMap(data_get($json, 'parents')) !== $parents
        ) {
            throw new RuntimeException(
                'WordPress zwrócił niepełne potwierdzenie powiązania tłumaczeń wariantów.',
            );
        }

        return $json;
    }

    /**
     * Link language-specific terms that belong to one WooCommerce global
     * product attribute. The endpoint resolves and validates the `pa_*`
     * taxonomy from the numeric Woo attribute ID.
     *
     * @param  array<string, int|string>  $translations
     * @return array<string, mixed>
     */
    public function linkProductAttributeTermTranslations(
        WordpressIntegration $integration,
        int $attributeId,
        array $translations,
    ): array {
        if ($attributeId <= 0) {
            throw new RuntimeException('ERP przygotował niepoprawne ID globalnego atrybutu WooCommerce.');
        }

        $translations = $this->validatedProductTranslationMap($translations);
        $url = $this->wordpressRestEndpoint(
            $integration,
            "/wc-lemon-erp/v1/catalog/products/attributes/{$attributeId}/terms/translations",
        );

        try {
            $response = $this->request($integration, retry: false)
                ->withoutRedirecting()
                ->post($url, ['translations' => $translations]);
        } catch (RequestException $exception) {
            $this->throwProductAttributeTermTranslationLinkHttpException($exception->response);
        }

        if (! $response->successful()) {
            $this->throwProductAttributeTermTranslationLinkHttpException($response);
        }

        $json = $response->json();

        if (! is_array($json)
            || data_get($json, 'linked') !== true
            || (int) data_get($json, 'attribute_id') !== $attributeId
            || $this->confirmedProductTranslationMap(data_get($json, 'translations')) !== $translations
        ) {
            throw new RuntimeException('WordPress zwrócił niepełne potwierdzenie powiązania tłumaczeń wartości atrybutu produktu.');
        }

        return $json;
    }

    /**
     * Check the non-mutating plugin endpoint before a historical backfill can
     * update or create any WooCommerce products. A missing/old plugin, missing
     * Polylang or unavailable export language leaves the family pending.
     *
     * @param  list<string>  $requiredLanguages
     */
    public function productTranslationLinkingAvailable(
        WordpressIntegration $integration,
        array $requiredLanguages = ['pl', 'en'],
    ): bool {
        $requiredLanguages = collect($requiredLanguages)
            ->map(fn (mixed $language): string => mb_strtolower(trim((string) $language)))
            ->filter()
            ->unique()
            ->values();

        // A Polish-only catalog never creates duplicated translation GTINs
        // and therefore must remain independent of the translation plugin.
        if ($requiredLanguages->reject(fn (string $language): bool => $language === 'pl')->isEmpty()) {
            return true;
        }

        try {
            $response = $this->request($integration, retry: false)
                ->withoutRedirecting()
                ->get($this->wordpressRestEndpoint(
                    $integration,
                    '/wc-lemon-erp/v1/catalog/products/translations/capabilities',
                ));
        } catch (Throwable) {
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $payload = $response->json();

        if (! is_array($payload)
            || ! is_string($payload['plugin_version'] ?? null)
            || version_compare($payload['plugin_version'], self::PRODUCT_TRANSLATION_PLUGIN_MINIMUM_VERSION, '<')
        ) {
            return false;
        }

        $availableLanguages = collect((array) ($payload['languages'] ?? []))
            ->map(fn (mixed $language): string => mb_strtolower(trim((string) $language)))
            ->filter()
            ->unique();

        return ($payload['available'] ?? null) === true
            && ($payload['attribute_term_translation_link_available'] ?? null) === true
            && $requiredLanguages->every(fn (string $language): bool => $availableLanguages->contains($language));
    }

    /**
     * A translated variable family additionally needs the semantic variation
     * linker capability exposed by the required 0.5.3 package.
     *
     * @param  list<string>  $requiredLanguages
     */
    public function productVariationTranslationLinkingAvailable(
        WordpressIntegration $integration,
        array $requiredLanguages = ['pl', 'en'],
    ): bool {
        try {
            $response = $this->request($integration, retry: false)
                ->withoutRedirecting()
                ->get($this->wordpressRestEndpoint(
                    $integration,
                    '/wc-lemon-erp/v1/catalog/products/translations/capabilities',
                ));
        } catch (Throwable) {
            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $payload = $response->json();

        if (! is_array($payload)
            || ! is_string($payload['plugin_version'] ?? null)
            || version_compare(
                $payload['plugin_version'],
                self::PRODUCT_VARIATION_TRANSLATION_PLUGIN_MINIMUM_VERSION,
                '<',
            )
            || ($payload['available'] ?? null) !== true
            || ($payload['attribute_term_translation_link_available'] ?? null) !== true
            || ($payload['variation_translation_link_available'] ?? null) !== true
            || ($payload['variation_translation_link_endpoint'] ?? null)
                !== '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations'
        ) {
            return false;
        }

        $requiredLanguages = collect($requiredLanguages)
            ->map(fn (mixed $language): string => mb_strtolower(trim((string) $language)))
            ->filter()
            ->unique()
            ->values();
        $availableLanguages = collect((array) ($payload['languages'] ?? []))
            ->map(fn (mixed $language): string => mb_strtolower(trim((string) $language)))
            ->filter()
            ->unique();

        return $requiredLanguages->every(
            fn (string $language): bool => $availableLanguages->contains($language),
        );
    }

    /**
     * Configure the Lemon ERP plugin to deliver signed customer lifecycle
     * events. The consumer secret never leaves either system: WordPress finds
     * the matching WooCommerce API key from the public consumer key and uses
     * its stored secret for HMAC signatures.
     *
     * @return array<string, mixed>
     */
    public function configureCustomerWebhook(
        WordpressIntegration $integration,
        string $deliveryUrl,
    ): array {
        $scheme = mb_strtolower((string) parse_url($deliveryUrl, PHP_URL_SCHEME));

        if (filter_var($deliveryUrl, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true)
        ) {
            throw new RuntimeException('ERP nie przygotował poprawnego adresu odbiorczego webhooka klientów.');
        }

        $payload = [
            'delivery_url' => $deliveryUrl,
            'consumer_key' => Crypt::decryptString($integration->consumer_key_encrypted),
        ];

        try {
            $response = $this->request($integration, retry: false)
                ->withoutRedirecting()
                ->post(
                    $this->wordpressRestEndpoint($integration, '/wc-lemon-erp/v1/customer-webhook/configure'),
                    $payload,
                );
        } catch (RequestException $exception) {
            $this->throwCustomerWebhookConfigurationException($exception->response);
        }

        // Compatibility for plugin 0.4.0, whose custom namespace is not
        // recognized by WooCommerce ck_/cs_ authentication. When WordPress
        // Application Password credentials are already configured, use them
        // only after a definitive missing-route response from the new API.
        if ($this->customerWebhookRouteIsMissing($response)
            && $integration->hasWordpressMediaCredentials()
        ) {
            try {
                $response = $this->wordpressRequest($integration, retry: false)
                    ->withoutRedirecting()
                    ->post(
                        $this->wordpressRestEndpoint($integration, '/lemon-erp/v1/customer-webhook/configure'),
                        $payload,
                    );
            } catch (RequestException $exception) {
                $this->throwCustomerWebhookConfigurationException($exception->response);
            }
        }

        if (! $response->successful()) {
            $this->throwCustomerWebhookConfigurationException($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function customerWebhookRouteIsMissing(Response $response): bool
    {
        $code = trim((string) data_get($response->json(), 'code', ''));

        return $response->status() === 404
            && ($code === '' || $code === 'rest_no_route');
    }

    private function throwCustomerWebhookConfigurationException(?Response $response): never
    {
        $status = $response?->status() ?? 0;
        $payload = $response?->json();
        $code = trim((string) data_get($payload, 'code', ''));
        $message = trim((string) data_get($payload, 'message', ''));

        if ($status === 404 && ($code === '' || $code === 'rest_no_route')) {
            throw new RuntimeException('Natychmiastowa synchronizacja klientów wymaga aktualnej wtyczki Lemon ERP for WooCommerce. Pobierz nowy ZIP z ekranu Integracje i zaktualizuj wtyczkę w WordPressie.');
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException('WordPress odrzucił konfigurację webhooka klientów. Sprawdź klucze WooCommerce REST i ich uprawnienia odczyt/zapis.');
        }

        if ($message !== '') {
            throw new RuntimeException("WordPress nie skonfigurował webhooka klientów: {$message}");
        }

        throw new RuntimeException("Konfiguracja webhooka klientów zwróciła HTTP {$status}.");
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
     * @param  array<string, int|string>  $translations
     * @return array<string, int>
     */
    private function validatedProductTranslationMap(array $translations): array
    {
        $normalized = [];

        foreach ($translations as $language => $productId) {
            $language = mb_strtolower(trim((string) $language));

            if (preg_match('/^[a-z][a-z0-9_-]*$/', $language) !== 1) {
                throw new RuntimeException('ERP przygotował niepoprawny kod języka tłumaczenia produktu.');
            }

            if (array_key_exists($language, $normalized)) {
                throw new RuntimeException("ERP przygotował język {$language} więcej niż raz w tłumaczeniach produktu.");
            }

            if (! is_int($productId) && ! is_string($productId)) {
                throw new RuntimeException("ERP przygotował niepoprawne ID produktu dla języka {$language}.");
            }

            $rawProductId = trim((string) $productId);

            if (preg_match('/^[1-9]\d*$/', $rawProductId) !== 1
                || (string) ((int) $rawProductId) !== $rawProductId
            ) {
                throw new RuntimeException("ERP przygotował niepoprawne ID produktu dla języka {$language}.");
            }

            $normalized[$language] = (int) $rawProductId;
        }

        if (count($normalized) < 2) {
            throw new RuntimeException('Powiązanie tłumaczeń produktu wymaga co najmniej dwóch języków.');
        }

        if (count(array_unique(array_values($normalized))) !== count($normalized)) {
            throw new RuntimeException('Każdy język tłumaczenia musi wskazywać inny produkt WooCommerce.');
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, int>|null
     */
    private function confirmedProductTranslationMap(mixed $translations): ?array
    {
        if (! is_array($translations)) {
            return null;
        }

        $normalized = [];

        foreach ($translations as $language => $productId) {
            if (! is_string($language)
                || preg_match('/^[a-z][a-z0-9_-]*$/', $language) !== 1
                || (! is_int($productId) && ! is_string($productId))
                || preg_match('/^[1-9]\d*$/', (string) $productId) !== 1
                || (string) ((int) $productId) !== (string) $productId
            ) {
                return null;
            }

            $normalized[$language] = (int) $productId;
        }

        ksort($normalized);

        return $normalized;
    }

    private function throwProductTranslationLinkHttpException(?Response $response): never
    {
        $status = $response?->status() ?? 0;
        $payload = $response?->json();
        $code = trim((string) data_get($payload, 'code', ''));
        $message = trim((string) data_get($payload, 'message', ''));

        if ($status === 404 && ($code === '' || $code === 'rest_no_route')) {
            throw new RuntimeException(
                'Powiązanie tłumaczeń produktów wymaga wtyczki Lemon ERP for WooCommerce co najmniej '
                .self::PRODUCT_TRANSLATION_PLUGIN_MINIMUM_VERSION
                .'. Pobierz nowy ZIP z ekranu Integracje i zaktualizuj wtyczkę w WordPressie.',
            );
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException('WordPress odrzucił powiązanie tłumaczeń produktów. Sprawdź klucze WooCommerce REST, ich uprawnienia odczyt/zapis oraz uprawnienie manage_woocommerce użytkownika.');
        }

        if ($message !== '') {
            throw new RuntimeException("WordPress nie powiązał tłumaczeń produktów: {$message}");
        }

        throw new RuntimeException("Powiązanie tłumaczeń produktów w WooCommerce zwróciło HTTP {$status}.");
    }

    private function throwProductVariationTranslationLinkHttpException(?Response $response): never
    {
        $status = $response?->status() ?? 0;
        $payload = $response?->json();
        $code = trim((string) data_get($payload, 'code', ''));
        $message = trim((string) data_get($payload, 'message', ''));

        if ($status === 404 && ($code === '' || $code === 'rest_no_route')) {
            throw new RuntimeException(
                'Powiązanie tłumaczeń wariantów wymaga wtyczki Lemon ERP for WooCommerce co najmniej '
                .self::PRODUCT_VARIATION_TRANSLATION_PLUGIN_MINIMUM_VERSION
                .'. Pobierz nowy ZIP z ekranu Integracje i zaktualizuj wtyczkę w WordPressie.',
            );
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException(
                'WordPress odrzucił powiązanie tłumaczeń wariantów. Sprawdź klucze WooCommerce REST, ich uprawnienia odczyt/zapis oraz uprawnienie manage_woocommerce użytkownika.',
            );
        }

        if ($message !== '') {
            throw new RuntimeException("WordPress nie powiązał tłumaczeń wariantów: {$message}");
        }

        throw new RuntimeException(
            "Powiązanie tłumaczeń wariantów w WooCommerce zwróciło HTTP {$status}.",
        );
    }

    private function throwProductAttributeTermTranslationLinkHttpException(?Response $response): never
    {
        $status = $response?->status() ?? 0;
        $payload = $response?->json();
        $code = trim((string) data_get($payload, 'code', ''));
        $message = trim((string) data_get($payload, 'message', ''));

        if ($status === 404 && ($code === '' || $code === 'rest_no_route')) {
            throw new RuntimeException(
                'Powiązanie tłumaczeń wartości globalnych atrybutów wymaga wtyczki Lemon ERP for WooCommerce co najmniej '
                .self::PRODUCT_TRANSLATION_PLUGIN_MINIMUM_VERSION
                .'. Pobierz nowy ZIP z ekranu Integracje i zaktualizuj wtyczkę w WordPressie.',
            );
        }

        if (in_array($status, [401, 403], true)) {
            throw new RuntimeException('WordPress odrzucił powiązanie tłumaczeń wartości globalnego atrybutu. Sprawdź uprawnienia manage_woocommerce i manage_product_terms użytkownika kluczy REST.');
        }

        if ($message !== '') {
            throw new RuntimeException("WordPress nie powiązał tłumaczeń wartości globalnego atrybutu: {$message}");
        }

        throw new RuntimeException("Powiązanie tłumaczeń wartości globalnego atrybutu w WooCommerce zwróciło HTTP {$status}.");
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
        ?string $language = null,
    ): array {
        $endpoint = filled($externalVariationId)
            ? "/products/{$externalProductId}/variations/{$externalVariationId}"
            : "/products/{$externalProductId}";
        $url = $this->endpoint($integration, $endpoint);

        if (filled($language) && filled($externalVariationId)) {
            $url .= '?lang='.rawurlencode((string) $language);
        }

        $response = $this->request($integration)
            ->put($url, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Eksport tłumaczenia produktu do WooCommerce zwrócił HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Correct a legacy variation axis with a mechanically restricted payload.
     * This guard prevents a maintenance job from ever sending commercial or
     * editorial fields such as stock, prices, dates, content or images.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateProductVariantAxisByIds(
        WordpressIntegration $integration,
        string $externalProductId,
        ?string $externalVariationId,
        array $payload,
        ?string $language = null,
    ): array {
        $allowedKeys = filled($externalVariationId)
            ? ['attributes', 'menu_order']
            : ['attributes', 'default_attributes'];
        $unexpectedKeys = array_values(array_diff(array_keys($payload), $allowedKeys));

        if ($unexpectedKeys !== []) {
            throw new RuntimeException(
                'Naprawa osi wariantów zawiera niedozwolone pola: '.implode(', ', $unexpectedKeys).'.',
            );
        }

        if (! isset($payload['attributes']) || ! is_array($payload['attributes'])) {
            throw new RuntimeException('Naprawa osi wariantów wymaga jawnej listy atrybutów.');
        }

        return $this->updateProductDataByIds(
            $integration,
            $externalProductId,
            $externalVariationId,
            $payload,
            $language,
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $payloadsByLanguage
     * @return list<array{language:?string,product_id:string,status:string}>
     */
    public function updateDiscoveredProductTranslations(
        WordpressIntegration $integration,
        ProductChannelMapping $mapping,
        string $sku,
        array $payloadsByLanguage,
    ): array {
        if (filled($mapping->external_variation_id)) {
            return [];
        }

        $mainProductId = (string) $mapping->external_product_id;
        $updated = [];
        $updatedIds = [];

        foreach (array_keys($payloadsByLanguage) as $language) {
            $language = $this->normalizeCatalogLanguage($language);

            if ($language === null || $language === 'pl') {
                continue;
            }

            $query = [
                'sku' => $sku,
                'per_page' => 20,
            ];

            $query['lang'] = $language;

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

                if (! $this->matchesRequestedLanguage($product, $language)) {
                    continue;
                }

                $translationId = (string) $product['id'];

                if ($translationId === '' || $translationId === $mainProductId || in_array($translationId, $updatedIds, true)) {
                    continue;
                }

                $translationIds = $this->translationIds(
                    $product['lemon_erp_translations'] ?? $product['translations'] ?? [],
                );

                if ($translationIds === [] || ! in_array($mainProductId, $translationIds, true)) {
                    continue;
                }

                $actualLanguage = $this->catalogItemLanguage($product) ?? $language;

                $updateResponse = $this->request($integration)
                    ->put(
                        $this->endpoint($integration, "/products/{$translationId}"),
                        (array) ($payloadsByLanguage[$actualLanguage] ?? $payloadsByLanguage[$language] ?? $payloadsByLanguage['pl'] ?? []),
                    );

                if (! $updateResponse->successful()) {
                    throw new RuntimeException("Aktualizacja danych tłumaczenia WooCommerce #{$translationId} zwróciła HTTP {$updateResponse->status()}.");
                }

                $updatedIds[] = $translationId;
                $updated[] = [
                    'language' => $actualLanguage,
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
            $response = $this->wordpressRequest($integration, retry: false)
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
        $response = $this->request($integration, retry: false)
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
        return $this->updateOrder($integration, $orderId, ['status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateOrder(
        WordpressIntegration $integration,
        string $orderId,
        array $payload,
        bool $retry = true,
    ): array {
        $response = $this->request($integration, retry: $retry)
            ->put($this->endpoint($integration, "/orders/{$orderId}"), $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Edycja zamówienia WooCommerce zwróciła HTTP {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function orderRefunds(WordpressIntegration $integration, string|int $orderId): array
    {
        $encodedOrderId = rawurlencode((string) $orderId);

        try {
            $response = $this->request($integration)
                ->get($this->endpoint($integration, "/orders/{$encodedOrderId}/refunds"), [
                    'context' => 'view',
                    'per_page' => 100,
                ]);
        } catch (RequestException $exception) {
            if ($exception->response instanceof Response) {
                throw $this->wooCommerceResponseException(
                    $exception->response,
                    'Pobranie zwrotów zamówienia z WooCommerce',
                );
            }

            throw $exception;
        }

        if (! $response->successful()) {
            throw $this->wooCommerceResponseException(
                $response,
                'Pobranie zwrotów zamówienia z WooCommerce',
            );
        }

        $refunds = $response->json();

        return is_array($refunds)
            ? array_values(array_filter($refunds, fn (mixed $refund): bool => is_array($refund)))
            : [];
    }

    /**
     * This payment-changing POST must never be retried automatically. A timeout
     * can occur after the gateway accepted the refund, and repeating the call
     * could return the customer's money twice.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createOrderRefund(
        WordpressIntegration $integration,
        string|int $orderId,
        array $payload,
    ): array {
        $encodedOrderId = rawurlencode((string) $orderId);
        $response = $this->request($integration, retry: false)
            ->post($this->endpoint($integration, "/orders/{$encodedOrderId}/refunds"), $payload);

        if (! $response->successful()) {
            throw $this->wooCommerceResponseException(
                $response,
                'Utworzenie zwrotu płatności w WooCommerce',
            );
        }

        $refund = $response->json();

        return is_array($refund) ? $refund : [];
    }

    private function wooCommerceResponseException(Response $response, string $operation): RuntimeException
    {
        $payload = $response->json();
        $code = is_array($payload) ? trim((string) ($payload['code'] ?? '')) : '';
        $message = is_array($payload) ? trim((string) ($payload['message'] ?? '')) : '';
        $codeDetails = $code !== '' ? ", {$code}" : '';
        $messageDetails = $message !== ''
            ? ': '.Str::limit(preg_replace('/\s+/', ' ', $message) ?? $message, 500, '…')
            : '.';

        return new RuntimeException(
            "{$operation} nie powiodło się (HTTP {$response->status()}{$codeDetails}){$messageDetails}"
        );
    }

    /**
     * @return array{contents:string,mime_type:string,filename:?string,source_url:?string,response_payload:?array<string,mixed>}
     */
    public function generateShippingLabel(
        WordpressIntegration $integration,
        string $orderId,
        string $orderNumber,
        ?string $parcelTemplate = null,
    ): array {
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
            'idempotency_key' => "sempre-shipment:{$integration->id}:{$orderId}",
        ];

        if ($parcelTemplate !== null) {
            $payload['parcel_template'] = $parcelTemplate;
        }

        $request = $this->shippingLabelRequest($integration, $settings, retry: $method === 'GET')
            ->withHeaders([
                'Idempotency-Key' => $payload['idempotency_key'],
                'X-Sempre-Idempotency-Key' => $payload['idempotency_key'],
            ]);
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

    /**
     * @param  list<array<string, mixed>>  $attributes
     */
    private function variationAttributeSignature(array $attributes): string
    {
        $normalized = [];

        foreach ($attributes as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $attributeId = (int) ($attribute['id'] ?? 0);
            $name = $attributeId > 0
                ? 'id-'.$attributeId
                : Str::slug((string) ($attribute['name'] ?? $attribute['slug'] ?? ''));
            $value = Str::slug((string) ($attribute['option'] ?? $attribute['value'] ?? ''));

            if ($name !== '' && $value !== '') {
                $normalized[$name] = $value;
            }
        }

        if ($normalized === []) {
            return '';
        }

        ksort($normalized);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function request(WordpressIntegration $integration, bool $retry = true): PendingRequest
    {
        $request = Http::timeout(30)
            ->acceptJson()
            ->withBasicAuth(
                Crypt::decryptString($integration->consumer_key_encrypted),
                Crypt::decryptString($integration->consumer_secret_encrypted),
            );

        return $retry ? $request->retry(2, 300) : $request;
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

    private function wordpressRequest(WordpressIntegration $integration, bool $retry = true): PendingRequest
    {
        $request = Http::timeout(60)
            ->acceptJson()
            ->withBasicAuth(
                (string) $integration->wp_api_username,
                $integration->wordpressApiPassword(),
            );

        return $retry ? $request->retry(2, 300) : $request;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function shippingLabelRequest(
        WordpressIntegration $integration,
        array $settings,
        bool $retry = true,
    ): PendingRequest {
        $request = Http::timeout(60)
            ->withHeaders([
                'Accept' => 'application/pdf, image/png, application/json;q=0.9, */*;q=0.8',
            ]);

        if ($retry) {
            $request = $request->retry(2, 300);
        }

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
                'response_payload' => $this->shippingLabelResponseMetadata($response),
            ];
        }

        if (str_contains($contentType, 'image/png')) {
            return [
                'contents' => $body,
                'mime_type' => 'image/png',
                'filename' => $this->filenameFromResponse($response),
                'source_url' => null,
                'response_payload' => $this->shippingLabelResponseMetadata($response),
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

    /**
     * Pozwala endpointowi wtyczki zwrócić dane przesyłki także wtedy, gdy body
     * odpowiedzi jest bezpośrednio plikiem PDF/PNG.
     *
     * @return array<string, string>|null
     */
    private function shippingLabelResponseMetadata(Response $response): ?array
    {
        $headers = [
            'provider' => ['X-Shipping-Provider', 'X-Carrier'],
            'label_number' => ['X-Label-Number', 'X-Shipment-Id'],
            'tracking_number' => ['X-Tracking-Number', 'X-Waybill-Number'],
        ];
        $metadata = [];

        foreach ($headers as $key => $candidates) {
            foreach ($candidates as $header) {
                $value = trim((string) $response->header($header));

                if ($value !== '') {
                    $metadata[$key] = $value;
                    break;
                }
            }
        }

        return $metadata !== [] ? $metadata : null;
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
