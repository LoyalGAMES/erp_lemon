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
    ) {
    }

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

            $payload = $this->payload($product, filled($mapping->external_variation_id), (int) $mapping->sales_channel_id);
            $response = $this->client->updateProductData($integration, $mapping, $payload);

            $this->updateMappingAfterExport($mapping, $product, $payload, $response);
            $variantResults = $this->exportMappedVariants($product, $integration, (int) $mapping->sales_channel_id);

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
                ],
                'attempts' => 1,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $results[] = [
                'channel' => $mapping->salesChannel?->code,
                'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
                'response' => $response,
                'variants' => $variantResults,
            ];
        }

        return [
            'exported' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @return array{mapping:ProductChannelMapping,response:array<string,mixed>,payload:array<string,mixed>,variant_mappings:list<ProductChannelMapping>,variant_responses:list<array<string,mixed>>}
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

        $payload = $this->payload($product, false, (int) $integration->sales_channel_id);
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

        return [
            'mapping' => $mapping,
            'response' => $response,
            'payload' => $payload,
            'variant_mappings' => $variantMappings,
            'variant_responses' => $variantResponses,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function exportMappedVariants(Product $product, WordpressIntegration $integration, int $salesChannelId): array
    {
        $results = [];

        foreach ($this->variantChildren($product) as $variant) {
            $mapping = $variant->channelMappings
                ->first(fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === $salesChannelId);

            if (! $mapping instanceof ProductChannelMapping) {
                continue;
            }

            $payload = $this->variationPayload($product, $variant, $salesChannelId);
            $response = $this->client->updateProductData($integration, $mapping, $payload);
            $this->updateMappingAfterExport($mapping, $variant, $payload, $response);

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $salesChannelId,
                'wordpress_integration_id' => $integration->id,
                'direction' => 'out',
                'operation' => 'export_product_variation_data',
                'status' => 'success',
                'external_resource' => 'product_variation',
                'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
                'request_payload' => $payload,
                'response_payload' => [
                    'id' => $response['id'] ?? null,
                    'sku' => $response['sku'] ?? null,
                    'regular_price' => $response['regular_price'] ?? null,
                ],
                'attempts' => 1,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $results[] = [
                'sku' => $variant->sku,
                'external_id' => $mapping->external_variation_id ?? $mapping->external_product_id,
                'response' => $response,
            ];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product, bool $isVariation = false, ?int $salesChannelId = null): array
    {
        $master = $product->masterData();
        $retailPrice = data_get($master, 'prices.retail_price_pln');
        $description = data_get($master, 'content.pl.description');
        $shortDescription = data_get($master, 'content.pl.additional_description');
        $images = $this->images($product);

        $payload = [
            'sku' => $product->sku,
            'status' => $product->is_active ? (string) (data_get($master, 'publication_status') ?: 'publish') : 'draft',
            'weight' => $product->weight_kg !== null ? $this->decimal($product->weight_kg, 4) : null,
            'dimensions' => [
                'height' => $this->decimal(data_get($master, 'dimensions.height_cm'), 2),
                'width' => $this->decimal(data_get($master, 'dimensions.width_cm'), 2),
                'length' => $this->decimal(data_get($master, 'dimensions.length_cm'), 2),
            ],
            'meta_data' => $this->metaData($product, $master),
        ];

        if (! $isVariation) {
            $payload['name'] = (string) (data_get($master, 'content.pl.name') ?: $product->name);
            $payload['description'] = $description ?: '';
            $payload['short_description'] = $shortDescription ?: '';
            $payload['attributes'] = $this->attributes($master);
            $payload['categories'] = $this->categories($master, $salesChannelId);
            $payload['catalog_visibility'] = (string) (data_get($master, 'catalog_visibility') ?: 'visible');
            $payload['upsell_ids'] = $this->relatedProductIds((array) data_get($master, 'related_products.upsell_skus', []), $salesChannelId);
            $payload['cross_sell_ids'] = $this->relatedProductIds((array) data_get($master, 'related_products.cross_sell_skus', []), $salesChannelId);

            if ($images !== []) {
                $payload['images'] = $images;
            }
        } else {
            $payload['description'] = $description ?: '';

            if ($images !== []) {
                $payload['image'] = $images[0];
            }
        }

        if ($retailPrice !== null && $retailPrice !== '') {
            $payload['regular_price'] = $this->decimal($retailPrice, 2);
        }

        return $this->removeEmptyDimensions($payload);
    }

    /**
     * @param Collection<int, Product> $variants
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareVariablePayload(Product $product, Collection $variants, array $payload): array
    {
        if ($variants->isEmpty()) {
            return $payload;
        }

        $payload['type'] = 'variable';
        $payload['attributes'] = $this->variableAttributes($product, $variants);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function variationPayload(Product $parent, Product $variant, int $salesChannelId): array
    {
        $payload = $this->payload($variant, true, $salesChannelId);
        $payload['attributes'] = $this->variationAttributes($parent, $variant);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
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
            'last_product_export_payload_hash' => $this->payloadHash($payload),
        ]);

        $mapping->update([
            'external_sku' => $product->sku,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadHash(array $payload): string
    {
        return sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param array<string, mixed> $master
     * @return list<array{key:string,value:mixed}>
     */
    private function metaData(Product $product, array $master): array
    {
        return collect([
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
    }

    /**
     * @param array<string, mixed> $master
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
     * @param Collection<int, Product> $variants
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
     * @param Collection<int, Product> $variants
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
     * @param array<string, mixed> $master
     * @return list<array{id:int}>
     */
    private function categories(array $master, ?int $salesChannelId): array
    {
        $categoryName = trim((string) data_get($master, 'category', ''));

        if ($categoryName === '' || $salesChannelId === null) {
            return [];
        }

        $category = ProductCategory::query()
            ->where('sales_channel_id', $salesChannelId)
            ->where(function ($query) use ($categoryName): void {
                $query->where('name', $categoryName)
                    ->orWhere('path', $categoryName);
            })
            ->first();

        if (! $category instanceof ProductCategory || ! ctype_digit((string) $category->external_id)) {
            return [];
        }

        return [['id' => (int) $category->external_id]];
    }

    /**
     * @param list<string> $skus
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

        return url('/' . ltrim($src, '/'));
    }

    private function decimal(mixed $value, int $precision): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, $precision, '.', '');
    }

    /**
     * @param array<string, mixed> $payload
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

        return array_filter($payload, fn ($value): bool => $value !== null && $value !== []);
    }
}
