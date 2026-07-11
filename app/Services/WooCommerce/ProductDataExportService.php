<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use Illuminate\Support\Collection;
use RuntimeException;

final class ProductDataExportService
{
    public function __construct(
        private readonly WooCommerceClient $client,
    ) {}

    /**
     * @return array{exported:int,results:list<array<string,mixed>>}
     */
    public function export(Product $product): array
    {
        $product->loadMissing([
            'channelMappings.salesChannel',
            'variantChildren.channelMappings.salesChannel',
        ]);

        if ($product->channelMappings->isEmpty()) {
            throw new RuntimeException('Produkt nie ma mapowania do żadnego kanału WooCommerce.');
        }

        $results = [];

        foreach ($product->channelMappings as $mapping) {
            $integration = WordpressIntegration::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->first();

            if ($integration === null) {
                throw new RuntimeException("Brak aktywnej integracji WooCommerce dla kanału {$mapping->salesChannel?->code}.");
            }

            $isVariation = filled($mapping->external_variation_id);
            $variants = $this->variantChildren($product);
            $this->ensureRemoteCategories($product, $integration, (int) $mapping->sales_channel_id);
            $payload = $this->payload($product, $isVariation, (int) $mapping->sales_channel_id, 'pl');
            $payload = $this->preserveRemoteSkuWhenDuplicated($payload, $product, $mapping);

            if (! $isVariation) {
                $payload = $this->prepareVariablePayload($product, $variants, $payload);
            }

            $response = $this->client->updateProductData($integration, $mapping, $payload);

            $this->updateMappingAfterExport($mapping, $product, $payload, $response);
            $translationResults = $this->syncTranslations($product, $integration, $mapping, $isVariation, $variants);

            if ($translationResults === []) {
                $translationResults = $this->syncDiscoveredTranslationPublicationDates($product, $integration, $mapping, $isVariation);
            }
            $variantResults = $isVariation
                ? []
                : $this->exportOrCreateVariants(
                    $product,
                    $variants,
                    $integration,
                    (int) $mapping->sales_channel_id,
                    (string) ($response['id'] ?? $mapping->external_product_id),
                );

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $mapping->sales_channel_id,
                'wordpress_integration_id' => $integration->id,
                'direction' => 'out',
                'operation' => 'export_product_data',
                'status' => 'success',
                'external_resource' => $mapping->external_variation_id ? 'product_variation' : 'product',
                'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
                'request_payload' => $payload,
                'response_payload' => [
                    'id' => $response['id'] ?? null,
                    'sku' => $response['sku'] ?? null,
                    'name' => $response['name'] ?? null,
                    'regular_price' => $response['regular_price'] ?? null,
                    'translations' => $translationResults,
                ],
                'attempts' => 1,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $results[] = [
                'channel' => $mapping->salesChannel?->code,
                'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
                'response' => $response,
                'translations' => $translationResults,
                'variants' => $variantResults,
            ];
        }

        return [
            'exported' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @return array{mapping:ProductChannelMapping,response:array<string,mixed>,payload:array<string,mixed>,variant_mappings:list<ProductChannelMapping>,variant_responses:list<array<string,mixed>>,translation_responses:list<array<string,mixed>>}
     */
    public function create(Product $product, WordpressIntegration $integration): array
    {
        $product->loadMissing([
            'channelMappings.salesChannel',
            'variantChildren.channelMappings.salesChannel',
        ]);
        $integration->loadMissing('salesChannel');

        if ($integration->sales_channel_id === null) {
            throw new RuntimeException('Integracja WooCommerce nie ma przypisanego kanału sprzedaży.');
        }

        $alreadyMapped = $product->channelMappings
            ->contains(fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === (int) $integration->sales_channel_id);

        if ($alreadyMapped) {
            $channelCode = $integration->salesChannel?->code ?? (string) $integration->sales_channel_id;

            throw new RuntimeException("Produkt ma już mapowanie do kanału {$channelCode}.");
        }

        $variants = $this->variantChildren($product);
        $mappedVariant = $variants->first(fn (Product $variant): bool => $variant->channelMappings
            ->contains(fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === (int) $integration->sales_channel_id));

        if ($mappedVariant instanceof Product) {
            $channelCode = $integration->salesChannel?->code ?? (string) $integration->sales_channel_id;

            throw new RuntimeException("Wariant {$mappedVariant->sku} ma już mapowanie do kanału {$channelCode}.");
        }

        $this->ensureRemoteCategories($product, $integration, (int) $integration->sales_channel_id);
        $payload = $this->payload($product, false, (int) $integration->sales_channel_id, 'pl');
        $payload = $this->prepareVariablePayload($product, $variants, $payload);
        $response = $this->client->createProduct($integration, $payload);
        $externalId = $response['id'] ?? null;

        if ($externalId === null || (string) $externalId === '') {
            throw new RuntimeException('WooCommerce nie zwrócił ID utworzonego produktu.');
        }

        $payloadHash = $this->payloadHash($payload);
        $mapping = ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $integration->sales_channel_id,
            'external_product_id' => (string) $externalId,
            'external_variation_id' => null,
            'external_sku' => (string) ($response['sku'] ?? $product->sku),
            'stock_sync_enabled' => true,
            'metadata' => [
                'source' => 'erp',
                'created_via' => 'erp_product_create',
                'created_in_woocommerce_at' => now()->toDateTimeString(),
                'woocommerce_permalink' => $response['permalink'] ?? null,
                'last_product_export_at' => now()->toDateTimeString(),
                'last_product_export_status' => 'success',
                'last_product_export_payload_hash' => $payloadHash,
            ],
        ]);

        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $integration->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'create_product',
            'status' => 'success',
            'external_resource' => 'product',
            'external_id' => (string) $externalId,
            'request_payload' => $payload,
            'response_payload' => [
                'id' => $response['id'] ?? null,
                'sku' => $response['sku'] ?? null,
                'name' => $response['name'] ?? null,
                'regular_price' => $response['regular_price'] ?? null,
                'permalink' => $response['permalink'] ?? null,
            ],
            'attempts' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $variantMappings = [];
        $variantResponses = [];

        foreach ($variants as $variant) {
            $variantPayload = $this->variationPayload($product, $variant, (int) $integration->sales_channel_id);
            $variantResponse = $this->client->createProductVariation($integration, (string) $externalId, $variantPayload);
            $variationExternalId = $variantResponse['id'] ?? null;

            if ($variationExternalId === null || (string) $variationExternalId === '') {
                throw new RuntimeException("WooCommerce nie zwrócił ID wariantu {$variant->sku}.");
            }

            $variantMapping = ProductChannelMapping::query()->create([
                'product_id' => $variant->id,
                'sales_channel_id' => $integration->sales_channel_id,
                'external_product_id' => (string) $externalId,
                'external_variation_id' => (string) $variationExternalId,
                'external_sku' => (string) ($variantResponse['sku'] ?? $variant->sku),
                'stock_sync_enabled' => true,
                'metadata' => [
                    'source' => 'erp',
                    'created_via' => 'erp_product_variation_create',
                    'parent_product_id' => $product->id,
                    'created_in_woocommerce_at' => now()->toDateTimeString(),
                    'last_product_export_at' => now()->toDateTimeString(),
                    'last_product_export_status' => 'success',
                    'last_product_export_payload_hash' => $this->payloadHash($variantPayload),
                ],
            ]);

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $integration->sales_channel_id,
                'wordpress_integration_id' => $integration->id,
                'direction' => 'out',
                'operation' => 'create_product_variation',
                'status' => 'success',
                'external_resource' => 'product_variation',
                'external_id' => (string) $variationExternalId,
                'request_payload' => $variantPayload,
                'response_payload' => [
                    'id' => $variantResponse['id'] ?? null,
                    'sku' => $variantResponse['sku'] ?? null,
                    'regular_price' => $variantResponse['regular_price'] ?? null,
                ],
                'attempts' => 1,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $variantMappings[] = $variantMapping;
            $variantResponses[] = $variantResponse;
        }

        $translationResponses = $this->createTranslations(
            $product,
            $variants,
            $integration,
            (int) $integration->sales_channel_id,
            (string) $externalId,
        );

        return [
            'mapping' => $mapping,
            'response' => $response,
            'payload' => $payload,
            'variant_mappings' => $variantMappings,
            'variant_responses' => $variantResponses,
            'translation_responses' => $translationResponses,
        ];
    }

    /**
     * @param  Collection<int, Product>  $variants
     * @return list<array<string, mixed>>
     */
    private function createTranslations(
        Product $product,
        Collection $variants,
        WordpressIntegration $integration,
        int $salesChannelId,
        string $primaryExternalId,
    ): array {
        $results = [];

        foreach ($integration->productImportLanguages() as $language) {
            $language = trim((string) $language);

            if ($language === '' || $language === 'pl' || ! is_array(data_get($product->masterData(), "content.{$language}"))) {
                continue;
            }

            $payload = $this->payload($product, false, $salesChannelId, $language);
            $payload = $this->prepareVariablePayload($product, $variants, $payload);
            $desiredSku = $payload['sku'] ?? null;
            unset($payload['sku']);
            $payload['translations'] = ['pl' => (int) $primaryExternalId];

            $response = $this->client->createProductForLanguage($integration, $payload, $language);
            $translatedProductId = trim((string) ($response['id'] ?? ''));

            if ($translatedProductId === '') {
                throw new RuntimeException("WooCommerce nie zwrócił ID utworzonego tłumaczenia produktu ({$language}).");
            }

            if (filled($desiredSku)) {
                $response = $this->client->updateProductDataByIds($integration, $translatedProductId, null, [
                    'sku' => $desiredSku,
                ]);
            }

            $this->saveTranslationReference($product, $language, $translatedProductId, null, (string) $desiredSku);
            $variantResponses = [];

            foreach ($variants as $variant) {
                $variantPayload = $this->variationPayload($product, $variant, $salesChannelId, $language);
                $variantResponse = $this->client->createProductVariation($integration, $translatedProductId, $variantPayload);
                $translatedVariationId = trim((string) ($variantResponse['id'] ?? ''));

                if ($translatedVariationId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID tłumaczenia wariantu {$variant->sku} ({$language}).");
                }

                $this->saveTranslationReference($variant, $language, $translatedProductId, $translatedVariationId, $variant->sku);
                $variantResponses[] = $variantResponse;
            }

            $results[] = [
                'language' => $language,
                'product_id' => $translatedProductId,
                'response' => $response,
                'variants' => $variantResponses,
            ];
        }

        return $results;
    }

    private function saveTranslationReference(
        Product $product,
        string $language,
        string $externalProductId,
        ?string $externalVariationId,
        string $sku,
    ): void {
        $attributes = (array) $product->attributes;
        data_set($attributes, "woocommerce_translations.{$language}", [
            'product_id' => $externalProductId,
            'variation_id' => $externalVariationId,
            'sku' => $sku,
        ]);
        $product->forceFill(['attributes' => $attributes])->save();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function exportOrCreateVariants(Product $product, Collection $variants, WordpressIntegration $integration, int $salesChannelId, string $externalProductId): array
    {
        $results = [];

        foreach ($variants as $variant) {
            $mapping = $variant->channelMappings
                ->first(fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === $salesChannelId);

            $payload = $this->variationPayload($product, $variant, $salesChannelId);
            $operation = 'export_product_variation_data';

            if ($mapping instanceof ProductChannelMapping && filled($mapping->external_variation_id)) {
                $payload = $this->preserveRemoteSkuWhenDuplicated($payload, $variant, $mapping);
                $response = $this->client->updateProductData($integration, $mapping, $payload);
            } else {
                $response = $this->client->createProductVariation($integration, $externalProductId, $payload);
                $operation = 'create_product_variation';
                $variationExternalId = $response['id'] ?? null;

                if ($variationExternalId === null || (string) $variationExternalId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID wariantu {$variant->sku}.");
                }

                $mapping = ProductChannelMapping::query()->updateOrCreate(
                    [
                        'product_id' => $variant->id,
                        'sales_channel_id' => $salesChannelId,
                    ],
                    [
                        'external_product_id' => $externalProductId,
                        'external_variation_id' => (string) $variationExternalId,
                        'external_sku' => (string) ($response['sku'] ?? $variant->sku),
                        'stock_sync_enabled' => true,
                        'metadata' => [
                            'source' => 'erp',
                            'created_via' => 'erp_product_export_variation_create',
                            'parent_product_id' => $product->id,
                            'created_in_woocommerce_at' => now()->toDateTimeString(),
                        ],
                    ],
                );
            }

            $this->updateMappingAfterExport($mapping, $variant, $payload, $response);
            $translationResults = $this->syncVariantTranslations(
                $product,
                $variant,
                $integration,
                $salesChannelId,
                $mapping,
            );

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $salesChannelId,
                'wordpress_integration_id' => $integration->id,
                'direction' => 'out',
                'operation' => $operation,
                'status' => 'success',
                'external_resource' => 'product_variation',
                'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
                'request_payload' => $payload,
                'response_payload' => [
                    'id' => $response['id'] ?? null,
                    'sku' => $response['sku'] ?? null,
                    'regular_price' => $response['regular_price'] ?? null,
                    'translations' => $translationResults,
                ],
                'attempts' => 1,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $results[] = [
                'sku' => $variant->sku,
                'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
                'response' => $response,
                'translations' => $translationResults,
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function syncVariantTranslations(
        Product $parent,
        Product $variant,
        WordpressIntegration $integration,
        int $salesChannelId,
        ProductChannelMapping $primaryMapping,
    ): array {
        $results = [];

        foreach ((array) data_get($parent->attributes, 'woocommerce_translations', []) as $language => $parentReference) {
            if (! is_array($parentReference)) {
                continue;
            }

            $translatedParentId = trim((string) ($parentReference['product_id'] ?? ''));

            if ($translatedParentId === '' || $translatedParentId === (string) $primaryMapping->external_product_id) {
                continue;
            }

            $language = trim((string) $language) ?: 'en';
            $payload = $this->variationPayload($parent, $variant, $salesChannelId, $language);
            $variantReference = data_get($variant->attributes, "woocommerce_translations.{$language}");
            $translatedVariationId = is_array($variantReference)
                ? trim((string) ($variantReference['variation_id'] ?? ''))
                : '';

            if ($translatedVariationId !== '') {
                $response = $this->client->updateProductDataByIds(
                    $integration,
                    $translatedParentId,
                    $translatedVariationId,
                    $payload,
                );
                $operation = 'updated';
            } else {
                $response = $this->client->createProductVariation($integration, $translatedParentId, $payload);
                $translatedVariationId = trim((string) ($response['id'] ?? ''));
                $operation = 'created';

                if ($translatedVariationId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID wariantu {$variant->sku} dla tłumaczenia {$language}.");
                }

                $this->saveTranslationReference(
                    $variant,
                    $language,
                    $translatedParentId,
                    $translatedVariationId,
                    $variant->sku,
                );
            }

            $results[] = [
                'language' => $language,
                'product_id' => $translatedParentId,
                'variation_id' => $translatedVariationId,
                'operation' => $operation,
                'response_id' => $response['id'] ?? null,
            ];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, bool $isVariation = false, ?int $salesChannelId = null, string $language = 'pl'): array
    {
        $product->loadMissing('stockBalances');
        $master = $product->masterData();
        $retailPrice = data_get($master, 'prices.retail_price_pln');
        $salePrice = data_get($master, 'prices.sale_price_pln');
        $salePriceStartsAt = data_get($master, 'prices.sale_price_starts_at');
        $salePriceEndsAt = data_get($master, 'prices.sale_price_ends_at');
        $publicationDate = $this->dateTimeString(data_get($master, 'publication_date'));
        $description = data_get($master, "content.{$language}.description")
            ?? data_get($master, 'content.pl.description');
        $shortDescription = data_get($master, "content.{$language}.additional_description")
            ?? data_get($master, 'content.pl.additional_description');
        $hasLanguageContent = $language === 'pl' || is_array(data_get($master, "content.{$language}"));
        $images = $this->images($product);
        $manageStock = (bool) data_get($master, 'inventory.manage_stock', true);
        $stockQuantity = (int) floor(max(0, (float) $product->stockBalances->sum('quantity_available')));

        $payload = [
            'sku' => $product->sku,
            'global_unique_id' => $product->ean ?: '',
            'status' => $product->is_active ? (string) (data_get($master, 'publication_status') ?: 'publish') : 'draft',
            'manage_stock' => $manageStock,
            'stock_quantity' => $manageStock ? $stockQuantity : null,
            'stock_status' => $manageStock ? ($stockQuantity > 0 ? 'instock' : 'outofstock') : null,
            'backorders' => (string) data_get($master, 'inventory.backorders', 'no'),
            'low_stock_amount' => data_get($master, 'inventory.low_stock_amount') ?? '',
            'sold_individually' => (bool) data_get($master, 'inventory.sold_individually', false),
            'weight' => $product->weight_kg !== null ? $this->decimal($product->weight_kg, 4) : '',
            'dimensions' => [
                'height' => $this->decimal(data_get($master, 'dimensions.height_cm'), 2) ?? '',
                'width' => $this->decimal(data_get($master, 'dimensions.width_cm'), 2) ?? '',
                'length' => $this->decimal(data_get($master, 'dimensions.length_cm'), 2) ?? '',
            ],
            'meta_data' => $this->metaData($product, $master, $language),
        ];

        if ($publicationDate !== null) {
            $payload['date_created'] = $publicationDate;
        }

        if (! $isVariation) {
            if ($hasLanguageContent) {
                $payload['name'] = (string) (data_get($master, "content.{$language}.name") ?: data_get($master, 'content.pl.name') ?: $product->name);
                $payload['description'] = $description ?: '';
                $payload['short_description'] = $shortDescription ?: '';
            }

            $payload['type'] = (string) (data_get($master, 'product_type') ?: 'simple');
            $payload['attributes'] = $this->attributes($master);
            $payload['categories'] = $this->categories($master, $salesChannelId, $language);
            $payload['catalog_visibility'] = (string) (data_get($master, 'catalog_visibility') ?: 'visible');
            $payload['upsell_ids'] = $this->relatedProductIds((array) data_get($master, 'related_products.upsell_skus', []), $salesChannelId);
            $payload['cross_sell_ids'] = $this->relatedProductIds((array) data_get($master, 'related_products.cross_sell_skus', []), $salesChannelId);

            $payload['images'] = $images;
        } elseif ($hasLanguageContent) {
            $payload['description'] = $description ?: '';

            if ($images !== []) {
                $payload['image'] = $images[0];
            }
        }

        if ($isVariation) {
            unset($payload['sold_individually']);
        }

        if ($retailPrice !== null && $retailPrice !== '') {
            $payload['regular_price'] = $this->decimal($retailPrice, 2);
        }

        $payload['sale_price'] = $salePrice !== null && $salePrice !== ''
            ? $this->decimal($salePrice, 2)
            : '';
        $payload['date_on_sale_from'] = $this->dateString($salePriceStartsAt) ?? '';
        $payload['date_on_sale_to'] = $this->dateString($salePriceEndsAt) ?? '';

        return $this->removeEmptyDimensions($payload);
    }

    /**
     * @param  Collection<int, Product>  $variants
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function prepareVariablePayload(Product $product, Collection $variants, array $payload): array
    {
        if ($variants->isEmpty()) {
            return $payload;
        }

        $payload['type'] = 'variable';
        $payload['attributes'] = $this->variableAttributes($product, $variants);
        unset(
            $payload['regular_price'],
            $payload['sale_price'],
            $payload['date_on_sale_from'],
            $payload['date_on_sale_to'],
            $payload['manage_stock'],
            $payload['stock_quantity'],
            $payload['stock_status'],
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function variationPayload(Product $parent, Product $variant, int $salesChannelId, string $language = 'pl'): array
    {
        $payload = $this->payload($variant, true, $salesChannelId, $language);
        $parentMaster = $parent->masterData();
        $parentRegularPrice = data_get($parentMaster, 'prices.retail_price_pln');
        $parentSalePrice = data_get($parentMaster, 'prices.sale_price_pln');
        $parentSaleStartsAt = data_get($parentMaster, 'prices.sale_price_starts_at');
        $parentSaleEndsAt = data_get($parentMaster, 'prices.sale_price_ends_at');

        if (($payload['regular_price'] ?? '') === '' && $parentRegularPrice !== null && $parentRegularPrice !== '') {
            $payload['regular_price'] = $this->decimal($parentRegularPrice, 2);
        }

        if (($payload['sale_price'] ?? '') === '' && $parentSalePrice !== null && $parentSalePrice !== '') {
            $payload['sale_price'] = $this->decimal($parentSalePrice, 2);
        }

        if (($payload['date_on_sale_from'] ?? '') === '' && $parentSaleStartsAt !== null && $parentSaleStartsAt !== '') {
            $payload['date_on_sale_from'] = $this->dateString($parentSaleStartsAt) ?? '';
        }

        if (($payload['date_on_sale_to'] ?? '') === '' && $parentSaleEndsAt !== null && $parentSaleEndsAt !== '') {
            $payload['date_on_sale_to'] = $this->dateString($parentSaleEndsAt) ?? '';
        }

        $payload['attributes'] = $this->variationAttributes($parent, $variant);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $response
     */
    private function updateMappingAfterExport(
        ProductChannelMapping $mapping,
        Product $product,
        array $payload,
        array $response,
    ): void {
        $currentMetadata = $mapping->metadata ?? [];
        $metadata = array_merge($currentMetadata, [
            'source' => $currentMetadata['source'] ?? 'erp',
            'woocommerce_permalink' => $response['permalink'] ?? data_get($currentMetadata, 'woocommerce_permalink'),
            'last_product_export_at' => now()->toDateTimeString(),
            'last_product_export_status' => 'success',
            'last_product_export_sku_status' => array_key_exists('sku', $payload) ? 'updated' : 'preserved_remote_duplicate',
            'last_product_export_payload_hash' => $this->payloadHash($payload),
        ]);
        $responseSku = trim((string) ($response['sku'] ?? ''));
        $externalSku = $responseSku !== ''
            ? $responseSku
            : (array_key_exists('sku', $payload) ? $product->sku : $mapping->external_sku);

        $mapping->update([
            'external_sku' => $externalSku,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function preserveRemoteSkuWhenDuplicated(
        array $payload,
        Product $product,
        ProductChannelMapping $mapping,
    ): array {
        $remoteSku = trim((string) ($mapping->external_sku ?? ''));

        if (
            $product->isSyntheticWooSku()
            || ($remoteSku !== ''
                && $remoteSku === $product->sku
                && $this->hasDuplicateSkuOutsideWooFamily($product, $mapping, $remoteSku))
        ) {
            unset($payload['sku']);
        }

        return $payload;
    }

    private function hasDuplicateSkuOutsideWooFamily(
        Product $product,
        ProductChannelMapping $mapping,
        string $remoteSku,
    ): bool {
        if ($this->hasPolylangTranslationWithSameSku($product, $mapping)) {
            return false;
        }

        return ProductChannelMapping::query()
            ->where('sales_channel_id', $mapping->sales_channel_id)
            ->where('external_sku', $remoteSku)
            ->whereKeyNot($mapping->id)
            ->where('external_product_id', '!=', $mapping->external_product_id)
            ->exists();
    }

    private function hasPolylangTranslationWithSameSku(Product $product, ProductChannelMapping $mapping): bool
    {
        $externalProductId = (string) $mapping->external_product_id;
        $externalVariationId = $mapping->external_variation_id !== null
            ? (string) $mapping->external_variation_id
            : null;

        return collect((array) data_get($product->attributes, 'woocommerce_translations', []))
            ->filter(fn (mixed $translation): bool => is_array($translation))
            ->contains(function (array $translation) use ($product, $externalProductId, $externalVariationId): bool {
                $translationSku = trim((string) ($translation['sku'] ?? ''));
                $translationProductId = trim((string) ($translation['product_id'] ?? ''));
                $translationVariationId = isset($translation['variation_id'])
                    ? (string) $translation['variation_id']
                    : null;

                return $translationSku !== ''
                    && $translationSku === $product->sku
                    && ($translationProductId !== $externalProductId || $translationVariationId !== $externalVariationId);
            });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadHash(array $payload): string
    {
        return sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param  array<string, mixed>  $master
     * @return list<array{key:string,value:mixed}>
     */
    private function metaData(Product $product, array $master, string $language = 'pl'): array
    {
        $meta = collect([
            '_sempre_erp_product_id' => $product->id,
            '_sempre_erp_source' => 'erp',
            '_sempre_erp_ean' => $product->ean,
            '_sempre_erp_vat_rate' => (string) $product->vat_rate,
            '_sempre_erp_unit' => $product->unit,
            '_sempre_erp_catalog' => data_get($master, 'catalog'),
            '_sempre_erp_category' => data_get($master, 'category'),
            '_sempre_erp_producer' => data_get($master, 'producer'),
            '_sempre_erp_tags' => implode(', ', (array) data_get($master, 'tags', [])),
            '_sempre_erp_asin' => data_get($master, 'asin'),
            '_sempre_erp_publication_date' => data_get($master, 'publication_date'),
            '_sempre_erp_developed' => data_get($master, 'developed') ? '1' : '0',
            '_sempre_erp_location' => data_get($master, 'stock.location'),
            '_sempre_erp_name_en' => data_get($master, 'content.en.name'),
            '_sempre_erp_description_en' => data_get($master, 'content.en.description'),
            '_sempre_erp_short_description_en' => data_get($master, 'content.en.additional_description'),
            '_sempre_erp_upsell_skus' => implode(', ', (array) data_get($master, 'related_products.upsell_skus', [])),
            '_sempre_erp_cross_sell_skus' => implode(', ', (array) data_get($master, 'related_products.cross_sell_skus', [])),
            '_sempre_erp_product_type' => data_get($master, 'product_type'),
            '_sempre_erp_variant_attribute' => data_get($master, 'variant_attribute'),
            '_sempre_erp_updated_at' => now()->toIso8601String(),
        ])
            ->filter(fn ($value): bool => $value !== null && $value !== '')
            ->map(fn ($value, string $key): array => ['key' => $key, 'value' => $value])
            ->values()
            ->all();

        return array_merge($meta, [
            ['key' => '_ean', 'value' => $product->ean ?: ''],
            ['key' => '_lemon_product_label_text', 'value' => (string) data_get($master, "custom_label.{$language}", '')],
            ['key' => '_lemon_product_label_bg_color', 'value' => (string) data_get($master, 'custom_label.bg_color', '')],
            ['key' => '_lemon_product_label_text_color', 'value' => (string) data_get($master, 'custom_label.text_color', '')],
        ]);
    }

    /**
     * @param  array<string, mixed>  $master
     * @return list<array{name:string,visible:bool,variation:bool,options:list<string>}>
     */
    private function attributes(array $master): array
    {
        return collect(data_get($master, 'parameters', []))
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row): ?array {
                $name = trim((string) ($row['name'] ?? ''));
                $value = trim((string) ($row['value'] ?? ''));

                if ($name === '' || $value === '') {
                    return null;
                }

                return [
                    'name' => $name,
                    'visible' => true,
                    'variation' => (bool) ($row['variation'] ?? false),
                    'options' => [$value],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Product>  $variants
     * @return list<array{name:string,visible:bool,variation:bool,options:list<string>}>
     */
    private function variableAttributes(Product $product, Collection $variants): array
    {
        $master = $product->masterData();
        $variantAttribute = $this->variantAttributeName($product, $variants);
        $variantOptions = $variants
            ->map(fn (Product $variant): string => $this->variationOption($variant, $variantAttribute))
            ->filter(fn (string $option): bool => $option !== '')
            ->unique()
            ->values()
            ->all();

        return collect($this->attributes($master))
            ->reject(fn (array $attribute): bool => mb_strtolower($attribute['name']) === mb_strtolower($variantAttribute))
            ->push([
                'name' => $variantAttribute,
                'visible' => true,
                'variation' => true,
                'options' => $variantOptions,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{name:string,option:string}>
     */
    private function variationAttributes(Product $parent, Product $variant): array
    {
        $variantAttribute = $this->variantAttributeName($parent, collect([$variant]));

        return [[
            'name' => $variantAttribute,
            'option' => $this->variationOption($variant, $variantAttribute),
        ]];
    }

    /**
     * @param  Collection<int, Product>  $variants
     */
    private function variantAttributeName(Product $product, Collection $variants): string
    {
        $name = trim((string) data_get($product->masterData(), 'variant_attribute', ''));

        if ($name !== '') {
            return $name;
        }

        foreach ($variants as $variant) {
            foreach ((array) data_get($variant->masterData(), 'parameters', []) as $parameter) {
                if (! is_array($parameter) || ! ($parameter['variation'] ?? false)) {
                    continue;
                }

                $candidate = trim((string) ($parameter['name'] ?? ''));

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return 'Rozmiar';
    }

    private function variationOption(Product $variant, string $variantAttribute): string
    {
        foreach ((array) data_get($variant->masterData(), 'parameters', []) as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = trim((string) ($parameter['name'] ?? ''));
            $value = trim((string) ($parameter['value'] ?? ''));

            if ($value !== '' && mb_strtolower($name) === mb_strtolower($variantAttribute)) {
                return $value;
            }
        }

        foreach ((array) data_get($variant->masterData(), 'parameters', []) as $parameter) {
            if (is_array($parameter) && ($parameter['variation'] ?? false) && trim((string) ($parameter['value'] ?? '')) !== '') {
                return trim((string) $parameter['value']);
            }
        }

        foreach ($variant->wooVariationAttributes() as $attribute) {
            $option = trim((string) ($attribute['option'] ?? ''));

            if ($option !== '') {
                return $option;
            }
        }

        return trim($variant->name);
    }

    /**
     * @return Collection<int, Product>
     */
    private function variantChildren(Product $product): Collection
    {
        $product->loadMissing(['variantChildren.channelMappings.salesChannel']);

        return $product->variantChildren
            ->filter(fn (Product $variant): bool => $variant->is_active)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $master
     * @return list<array{id:int}>
     */
    private function categories(array $master, ?int $salesChannelId, string $language = 'pl'): array
    {
        if ($salesChannelId === null) {
            return [];
        }

        $categoryIds = collect((array) data_get($master, 'category_ids', []))->map(fn (mixed $id): int => (int) $id)->filter();
        $query = ProductCategory::query()->where('sales_channel_id', $salesChannelId);

        if ($categoryIds->isNotEmpty()) {
            $categories = $query->whereIn('id', $categoryIds)->get();
        } else {
            $categoryNames = collect((array) data_get($master, 'categories', []))
                ->push(data_get($master, 'category'))
                ->map(fn (mixed $name): string => trim((string) $name))
                ->filter()
                ->unique();
            $categories = $categoryNames->isEmpty()
                ? collect()
                : $query->where(fn ($builder) => $builder->whereIn('name', $categoryNames)->orWhereIn('path', $categoryNames))->get();
        }

        return $categories
            ->map(function (ProductCategory $category) use ($language): ?array {
                $externalId = data_get($category->metadata, "woocommerce_ids.{$language}")
                    ?? $category->external_id;

                return ctype_digit((string) $externalId) ? ['id' => (int) $externalId] : null;
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    private function ensureRemoteCategories(Product $product, WordpressIntegration $integration, int $salesChannelId): void
    {
        $categoryIds = collect((array) data_get($product->masterData(), 'category_ids', []))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter();

        if ($categoryIds->isEmpty()) {
            return;
        }

        $languages = collect($integration->productImportLanguages())
            ->map(fn (mixed $language): string => trim((string) $language))
            ->filter()
            ->unique()
            ->values();

        if ($languages->isEmpty()) {
            $languages = collect(['pl']);
        }

        foreach (ProductCategory::query()->whereIn('id', $categoryIds)->get() as $category) {
            $metadata = (array) $category->metadata;

            if (ctype_digit((string) $category->external_id) && blank(data_get($metadata, 'woocommerce_ids.pl'))) {
                data_set($metadata, 'woocommerce_ids.pl', (string) $category->external_id);
            }

            foreach ($languages as $language) {
                if (filled(data_get($metadata, "woocommerce_ids.{$language}"))) {
                    continue;
                }

                $response = $this->client->createProductCategory($integration, array_filter([
                    'name' => $category->name,
                    'slug' => $category->slug ?: null,
                    'description' => $category->description ?: '',
                ], fn (mixed $value): bool => $value !== null), $language);
                $externalId = trim((string) ($response['id'] ?? ''));

                if ($externalId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID utworzonej kategorii {$category->name} ({$language}).");
                }

                data_set($metadata, "woocommerce_ids.{$language}", $externalId);
            }

            $category->forceFill([
                'sales_channel_id' => $salesChannelId,
                'metadata' => $metadata,
            ])->save();
        }
    }

    /**
     * @param  list<string>  $skus
     * @return list<int>
     */
    private function relatedProductIds(array $skus, ?int $salesChannelId): array
    {
        $skus = collect($skus)
            ->map(fn (mixed $sku): string => trim((string) $sku))
            ->filter()
            ->unique()
            ->values();

        if ($skus->isEmpty() || $salesChannelId === null) {
            return [];
        }

        return ProductChannelMapping::query()
            ->where('sales_channel_id', $salesChannelId)
            ->whereIn('product_id', Product::query()
                ->whereIn('sku', $skus->all())
                ->select('id'))
            ->whereNotNull('external_product_id')
            ->pluck('external_product_id')
            ->map(fn (mixed $id): ?int => ctype_digit((string) $id) ? (int) $id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, string>>
     */
    private function images(Product $product): array
    {
        return collect($product->mediaImages())
            ->map(function (array $image): ?array {
                $src = trim((string) ($image['src'] ?? ''));

                if ($src === '') {
                    return null;
                }

                $payload = [
                    'src' => $this->absoluteMediaUrl($src),
                ];

                if (filled($image['alt'] ?? null)) {
                    $payload['alt'] = (string) $image['alt'];
                }

                if (filled($image['name'] ?? null)) {
                    $payload['name'] = (string) $image['name'];
                }

                return $payload;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function absoluteMediaUrl(string $src): string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }

        return url('/'.ltrim($src, '/'));
    }

    private function decimal(mixed $value, int $precision): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, $precision, '.', '');
    }

    private function dateString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 10);
    }

    private function dateTimeString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        $value = str_replace(' ', 'T', $value);
        $value = mb_substr($value, 0, 19);

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
            return $value.':00';
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function syncTranslations(
        Product $product,
        WordpressIntegration $integration,
        ProductChannelMapping $mapping,
        bool $isVariation,
        Collection $variants,
    ): array {
        $results = [];
        $mainProductId = (string) $mapping->external_product_id;
        $mainVariationId = filled($mapping->external_variation_id) ? (string) $mapping->external_variation_id : null;

        foreach ((array) data_get($product->attributes, 'woocommerce_translations', []) as $language => $reference) {
            if (! is_array($reference)) {
                continue;
            }

            $externalProductId = trim((string) ($reference['product_id'] ?? ''));
            $externalVariationId = filled($reference['variation_id'] ?? null) ? (string) $reference['variation_id'] : null;

            if ($externalProductId === '' || ($externalProductId === $mainProductId && $externalVariationId === $mainVariationId)) {
                continue;
            }

            $language = in_array((string) $language, ['pl', 'en'], true) ? (string) $language : 'en';

            if (! $this->hasTranslationData($product, $language)) {
                continue;
            }

            $payload = $this->payload($product, $isVariation, (int) $mapping->sales_channel_id, $language);
            $payload = $this->preserveRemoteSkuWhenDuplicated($payload, $product, $mapping);

            if (! $isVariation) {
                $payload = $this->prepareVariablePayload($product, $variants, $payload);
            }

            $response = $this->client->updateProductDataByIds(
                $integration,
                $externalProductId,
                $externalVariationId,
                $payload,
            );
            $results[] = [
                'language' => $language,
                'product_id' => $externalProductId,
                'variation_id' => $externalVariationId,
                'status' => 'updated',
                'response_id' => $response['id'] ?? null,
            ];
        }

        return $results;
    }

    private function hasTranslationData(Product $product, string $language): bool
    {
        $master = $product->masterData();

        return is_array(data_get($master, "content.{$language}"))
            || filled($product->ean)
            || filled(data_get($master, 'prices.retail_price_pln'))
            || filled(data_get($master, 'prices.sale_price_pln'))
            || is_array(data_get($master, 'inventory'))
            || filled(data_get($master, "custom_label.{$language}"));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function syncDiscoveredTranslationPublicationDates(
        Product $product,
        WordpressIntegration $integration,
        ProductChannelMapping $mapping,
        bool $isVariation,
    ): array {
        if ($isVariation) {
            return [];
        }

        $publicationDate = $this->dateTimeString(data_get($product->masterData(), 'publication_date'));

        if ($publicationDate === null || trim($product->sku) === '') {
            return [];
        }

        return $this->client->updateProductPublicationDateTranslations(
            $integration,
            $mapping,
            $product->sku,
            $publicationDate,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function removeEmptyDimensions(array $payload): array
    {
        if (isset($payload['dimensions']) && is_array($payload['dimensions'])) {
            $payload['dimensions'] = array_filter(
                $payload['dimensions'],
                fn ($value): bool => $value !== null && $value !== '0.00',
            );

            if ($payload['dimensions'] === []) {
                unset($payload['dimensions']);
            }
        }

        return array_filter($payload, fn ($value): bool => $value !== null);
    }
}
