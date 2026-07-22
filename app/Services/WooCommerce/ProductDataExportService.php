<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\WordpressIntegration;
use App\Services\Inventory\ChannelStockAvailabilityService;
use App\Services\Products\LegacySizeVariantAxisResolver;
use App\Services\Products\ProductDescriptionSanitizer;
use App\Services\Products\ProductVariantAxisNameResolver;
use App\Services\Products\ProductVariantInheritanceService;
use App\Services\Products\ProductVariantOptionNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ProductDataExportService
{
    public const STOREFRONT_METADATA_SYNC_PATH = 'product_data_export.storefront_metadata';

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly ProductDescriptionSanitizer $descriptionSanitizer,
        private readonly ProductVariantInheritanceService $variantInheritance,
        private readonly ProductVariantAxisNameResolver $variantAxisNames,
        private readonly ProductVariantOptionNormalizer $variantOptions,
        private readonly LegacySizeVariantAxisResolver $legacySizeAxis,
        private readonly ChannelStockAvailabilityService $channelStock,
        private readonly WooCommerceSizeDictionaryOrder $sizeOrder,
        private readonly WooVariationMappingRelinker $variationRelinker,
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
            $variantContext = $isVariation
                ? $this->variantExportContext($product, $mapping)
                : null;
            $payloadProduct = $variantContext['variant'] ?? $product;
            $variantParent = $variantContext['parent'] ?? null;
            $variants = $this->variantChildren($product);
            $this->assertProductTranslationCreationReady(
                $integration,
                $variantParent instanceof Product || $variants->isNotEmpty(),
            );
            $this->ensureRemoteCategories(
                $variantParent instanceof Product ? $variantParent : $product,
                $integration,
                (int) $mapping->sales_channel_id,
            );
            $attributeFamilyParent = $variantParent instanceof Product ? $variantParent : $product;
            $attributeFamilyVariants = $variantParent instanceof Product
                ? $this->variantChildren($variantParent)
                : $variants;
            $this->preflightGlobalAttributeTranslations(
                $attributeFamilyParent,
                $attributeFamilyVariants,
                $integration,
                (int) $mapping->sales_channel_id,
            );
            if (! $isVariation) {
                $this->removePendingVariants($product, $integration, $mapping);
                $this->pruneDeadLegacyTranslationSnapshot($product, $integration);
            }
            $payload = $variantParent instanceof Product
                ? $this->variationPayload(
                    $variantParent,
                    $payloadProduct,
                    (int) $mapping->sales_channel_id,
                    'pl',
                )
                : $this->payload($product, $isVariation, (int) $mapping->sales_channel_id, 'pl');
            $payload = $this->preserveRemoteSkuWhenDuplicated($payload, $payloadProduct, $mapping);

            if (! $isVariation) {
                $payload = $this->prepareVariablePayload($product, $variants, $payload);
            }

            $payload = $this->globalizeProductAttributes($integration, $payload, 'pl');

            $response = $this->client->updateProductData($integration, $mapping, $payload);

            $this->updateMappingAfterExport($mapping, $product, $payload, $response);
            $creationInProgress = ! $isVariation
                && data_get($mapping->metadata, 'creation_state') === 'creating';
            $missingTranslations = ! $isVariation
                && $this->hasMissingTranslationReferences(
                    $product,
                    $integration,
                    (int) $mapping->sales_channel_id,
                );
            $variantsPreparedBeforeTranslations = $creationInProgress || $missingTranslations;
            $variantResults = $variantsPreparedBeforeTranslations
                ? $this->exportOrCreateVariants(
                    $product,
                    $variants,
                    $integration,
                    (int) $mapping->sales_channel_id,
                    (string) ($response['id'] ?? $mapping->external_product_id),
                    false,
                )
                : [];
            $translationResults = $this->syncTranslations(
                $payloadProduct,
                $integration,
                $mapping,
                $isVariation,
                $variants,
                $variantParent,
                $response,
                $variantResults,
            );

            $missingTranslations = ! $isVariation
                && $this->hasMissingTranslationReferences(
                    $product,
                    $integration,
                    (int) $mapping->sales_channel_id,
                );

            if ($translationResults === [] || $missingTranslations) {
                $translationResults = array_merge($translationResults, $this->syncDiscoveredTranslations(
                    $product,
                    $integration,
                    $mapping,
                    $isVariation,
                    $variants,
                    $missingTranslations,
                ));
            }

            $createdMissingTranslations = false;

            if (! $isVariation && $this->hasMissingTranslationReferences(
                $product,
                $integration,
                (int) $mapping->sales_channel_id,
            )) {
                $translationResults = array_merge($translationResults, $this->createTranslations(
                    $product,
                    $variants,
                    $integration,
                    (int) $mapping->sales_channel_id,
                    (string) $mapping->external_product_id,
                    $variantResults,
                ));
                $createdMissingTranslations = true;
            }

            if ($variantsPreparedBeforeTranslations
                && ! $creationInProgress
                && ! $createdMissingTranslations
            ) {
                $this->syncFamilyVariantTranslations(
                    $product,
                    $variants,
                    $integration,
                    (int) $mapping->sales_channel_id,
                    $variantResults,
                );
            }

            if (! $isVariation && ! $variantsPreparedBeforeTranslations) {
                $variantResults = $this->exportOrCreateVariants(
                    $product,
                    $variants,
                    $integration,
                    (int) $mapping->sales_channel_id,
                    (string) ($response['id'] ?? $mapping->external_product_id),
                );
            }

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
     * Backward-compatible entry point for the historical label backfill.
     *
     * @return array{exported:int,results:list<array<string,mixed>>}
     */
    public function exportCustomLabels(Product $product): array
    {
        return $this->exportStorefrontMetadata($product);
    }

    /**
     * Update only custom storefront metadata consumed by Lemon Elementor
     * Theme. This deliberately avoids catalog, attribute and variant-axis
     * work so label, shipping-date and preorder settings cannot be blocked by
     * an unrelated full-export repair.
     *
     * @return array{exported:int,results:list<array<string,mixed>>}
     */
    public function exportStorefrontMetadata(Product $product): array
    {
        $product->loadMissing('channelMappings.salesChannel');
        $mappings = $product->channelMappings;

        if ($mappings->isEmpty()) {
            throw new RuntimeException('Produkt nie ma mapowania do WooCommerce.');
        }

        $results = [];
        $master = $product->masterData();

        foreach ($mappings as $mapping) {
            $integration = WordpressIntegration::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->first();

            if (! $integration instanceof WordpressIntegration) {
                throw new RuntimeException("Brak aktywnej integracji WooCommerce dla kanału {$mapping->salesChannel?->code}.");
            }

            $isVariation = filled($mapping->external_variation_id)
                && trim((string) $mapping->external_variation_id) !== '0';
            $payloadsByLanguage = collect($integration->productExportLanguages())
                ->mapWithKeys(fn (string $language): array => [$language => [
                    'meta_data' => array_merge(
                        $this->customProductLabelMetaData($product, $language, $master),
                        $this->shippingMetaData($master, $language),
                    ),
                ]])
                ->all();
            $primaryPayload = (array) ($payloadsByLanguage['pl'] ?? [
                'meta_data' => array_merge(
                    $this->customProductLabelMetaData($product, 'pl', $master),
                    $this->shippingMetaData($master, 'pl'),
                ),
            ]);
            $primaryResponse = $this->client->updateProductDataByIds(
                $integration,
                (string) $mapping->external_product_id,
                $isVariation ? (string) $mapping->external_variation_id : null,
                $primaryPayload,
                'pl',
            );
            $targets = [[
                'language' => 'pl',
                'product_id' => (string) $mapping->external_product_id,
                'variation_id' => $isVariation ? (string) $mapping->external_variation_id : null,
                'status' => 'updated',
            ]];
            $missingPayloads = [];
            $references = $this->translationReferences($product, (int) $mapping->sales_channel_id);

            foreach ($payloadsByLanguage as $language => $payload) {
                $language = mb_strtolower(trim((string) $language));

                if ($language === '' || $language === 'pl') {
                    continue;
                }

                $externalProductId = trim((string) data_get($references, "{$language}.product_id", ''));
                $externalVariationId = $isVariation
                    ? trim((string) data_get($references, "{$language}.variation_id", ''))
                    : '';

                if ($externalProductId === '' || ($isVariation && $externalVariationId === '')) {
                    if (! $isVariation) {
                        $missingPayloads[$language] = $payload;
                    }

                    continue;
                }

                $this->client->updateProductDataByIds(
                    $integration,
                    $externalProductId,
                    $isVariation ? $externalVariationId : null,
                    $payload,
                    $language,
                );
                $targets[] = [
                    'language' => $language,
                    'product_id' => $externalProductId,
                    'variation_id' => $isVariation ? $externalVariationId : null,
                    'status' => 'updated',
                ];
            }

            if ($missingPayloads !== []) {
                $discovered = $this->client->updateDiscoveredProductTranslations(
                    $integration,
                    $mapping,
                    (string) $product->sku,
                    $missingPayloads,
                );

                foreach ($discovered as $target) {
                    $language = mb_strtolower(trim((string) ($target['language'] ?? '')));
                    $externalProductId = trim((string) ($target['product_id'] ?? ''));

                    if ($language === '' || $externalProductId === '') {
                        continue;
                    }

                    $this->saveTranslationReference(
                        $product,
                        (int) $mapping->sales_channel_id,
                        $language,
                        $externalProductId,
                        null,
                        (string) $product->sku,
                    );
                    $targets[] = $target;
                }
            }

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $mapping->sales_channel_id,
                'wordpress_integration_id' => $integration->id,
                'direction' => 'out',
                'operation' => 'export_product_labels',
                'status' => 'success',
                'external_resource' => $isVariation ? 'product_variation' : 'product',
                'external_id' => $isVariation
                    ? (string) $mapping->external_variation_id
                    : (string) $mapping->external_product_id,
                'request_payload' => $payloadsByLanguage,
                'response_payload' => [
                    'id' => $primaryResponse['id'] ?? null,
                    'targets' => $targets,
                ],
                'attempts' => 1,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $results[] = [
                'channel' => $mapping->salesChannel?->code,
                'external_id' => $isVariation
                    ? (string) $mapping->external_variation_id
                    : (string) $mapping->external_product_id,
                'response' => $primaryResponse,
                'translations' => array_values(array_filter(
                    $targets,
                    fn (array $target): bool => ($target['language'] ?? 'pl') !== 'pl',
                )),
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

        $this->assertProductTranslationCreationReady(
            $integration,
            $this->variantChildren($product)->isNotEmpty(),
        );

        $existingMapping = $product->channelMappings
            ->first(fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === (int) $integration->sales_channel_id);

        if ($existingMapping instanceof ProductChannelMapping) {
            $exportResult = $this->export($product);
            $this->markCreationCompleted($existingMapping);
            $channelResult = collect($exportResult['results'])->first(
                fn (array $result): bool => (string) ($result['external_id'] ?? '') === (string) $existingMapping->external_product_id,
            ) ?? [];

            $resumedPayload = $this->payload($product, false, (int) $integration->sales_channel_id, 'pl');
            $resumedPayload = $this->prepareVariablePayload(
                $product,
                $this->variantChildren($product),
                $resumedPayload,
            );
            $resumedPayload = $this->globalizeProductAttributes($integration, $resumedPayload, 'pl');

            return [
                'mapping' => $existingMapping->refresh(),
                'response' => (array) ($channelResult['response'] ?? []),
                'payload' => $resumedPayload,
                'variant_mappings' => ProductChannelMapping::query()
                    ->where('sales_channel_id', $integration->sales_channel_id)
                    ->whereIn('product_id', $this->variantChildren($product)->pluck('id'))
                    ->get()
                    ->all(),
                'variant_responses' => (array) ($channelResult['variants'] ?? []),
                'translation_responses' => (array) ($channelResult['translations'] ?? []),
                'resumed' => true,
            ];
        }

        $variants = $this->variantChildren($product);
        $mappedVariant = $variants->first(fn (Product $variant): bool => $variant->channelMappings
            ->contains(fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === (int) $integration->sales_channel_id));

        if ($mappedVariant instanceof Product) {
            $channelCode = $integration->salesChannel?->code ?? (string) $integration->sales_channel_id;

            throw new RuntimeException("Wariant {$mappedVariant->sku} ma już mapowanie do kanału {$channelCode}.");
        }

        $this->ensureRemoteCategories($product, $integration, (int) $integration->sales_channel_id);
        $this->preflightGlobalAttributeTranslations(
            $product,
            $variants,
            $integration,
            (int) $integration->sales_channel_id,
        );
        $payload = $this->payload($product, false, (int) $integration->sales_channel_id, 'pl');
        $payload = $this->prepareVariablePayload($product, $variants, $payload);
        $payload = $this->globalizeProductAttributes($integration, $payload, 'pl');
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
                'creation_state' => 'creating',
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
        $primaryVariantResults = [];

        foreach ($variants as $variant) {
            $variantPayload = $this->variationPayload($product, $variant, (int) $integration->sales_channel_id);
            $variantPayload = $this->globalizeProductAttributes($integration, $variantPayload, 'pl');
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
            $primaryVariantResults[] = [
                'sku' => $variant->sku,
                'response' => $variantResponse,
            ];
        }

        $translationResponses = $this->createTranslations(
            $product,
            $variants,
            $integration,
            (int) $integration->sales_channel_id,
            (string) $externalId,
            $primaryVariantResults,
        );
        $this->markCreationCompleted($mapping);

        return [
            'mapping' => $mapping,
            'response' => $response,
            'payload' => $payload,
            'variant_mappings' => $variantMappings,
            'variant_responses' => $variantResponses,
            'translation_responses' => $translationResponses,
        ];
    }

    private function assertProductTranslationCreationReady(
        WordpressIntegration $integration,
        bool $requiresVariantTranslations = false,
    ): void {
        $languages = $integration->productExportLanguages();
        $needsTranslations = collect($languages)->contains(
            fn (mixed $language): bool => mb_strtolower(trim((string) $language)) !== 'pl',
        );

        if ($needsTranslations
            && ! $this->client->productTranslationLinkingAvailable($integration, $languages)
        ) {
            throw WooCommerceProductTranslationNotReadyException::forRequiredLanguages();
        }

        if ($needsTranslations
            && $requiresVariantTranslations
            && ! $this->client->productVariationTranslationLinkingAvailable($integration, $languages)
        ) {
            throw WooCommerceProductTranslationNotReadyException::forRequiredVariantLanguages();
        }
    }

    /**
     * @param  Collection<int, Product>  $variants
     * @param  list<array<string, mixed>>  $primaryVariantResults
     * @return list<array<string, mixed>>
     */
    private function createTranslations(
        Product $product,
        Collection $variants,
        WordpressIntegration $integration,
        int $salesChannelId,
        string $primaryExternalId,
        array $primaryVariantResults = [],
    ): array {
        $results = [];

        foreach ($this->exportLanguages($product, $integration) as $language) {
            $language = trim((string) $language);

            if ($language === '' || $language === 'pl') {
                continue;
            }

            $existingReference = $this->translationReferences($product, $salesChannelId)[$language] ?? null;
            $localizedPayload = $this->payload($product, false, $salesChannelId, $language, null, null, true);
            $localizedPayload = $this->prepareVariablePayload($product, $variants, $localizedPayload, $language);
            $localizedPayload = $this->globalizeProductAttributes($integration, $localizedPayload, $language);
            $desiredSku = (string) ($localizedPayload['sku'] ?? $product->sku);
            $translatedProductId = is_array($existingReference)
                ? trim((string) ($existingReference['product_id'] ?? ''))
                : '';
            $translationCreationPending = $this->productTranslationCreationPending(
                $product,
                $salesChannelId,
                $language,
            );

            if ($translatedProductId === '') {
                $translationCreation = $this->beginTranslationCreation(
                    $product,
                    $salesChannelId,
                    $language,
                );
                $creationPayload = $localizedPayload;
                unset($creationPayload['sku']);

                $response = $this->client->createProductForLanguage(
                    $integration,
                    $creationPayload,
                    $language,
                    $translationCreation['token'],
                    $translationCreation['resume'],
                );
                $translatedProductId = trim((string) ($response['id'] ?? ''));

                if ($translatedProductId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID utworzonego tłumaczenia produktu ({$language}).");
                }

                // Persist the allocated ID before linking. If Polylang linking
                // fails, a retry resumes from this ID instead of duplicating EN.
                $this->saveTranslationReference($product, $salesChannelId, $language, $translatedProductId, null, $desiredSku);
                $translationCreationPending = true;
            } else {
                $response = ['id' => $translatedProductId, 'resumed' => true];
            }

            // Establish the Polylang relation before assigning the SKU shared
            // with the Polish product or creating translated variations.
            $linkResponse = $this->linkKnownProductTranslations(
                $product,
                $integration,
                $salesChannelId,
                $primaryExternalId,
            );

            // A resumed translation may have been allocated by an older or
            // interrupted export. Reapply the complete localized payload after
            // Polylang linking, not just the shared SKU, so its publication
            // date, content, media and attributes are repaired in one pass.
            if ($desiredSku !== '') {
                $localizedPayload['sku'] = $desiredSku;
            } else {
                unset($localizedPayload['sku']);
            }
            $response = $this->client->updateProductDataByIds(
                $integration,
                $translatedProductId,
                null,
                $localizedPayload,
                $language,
            );

            // The allocated alias is only a recovery handle until both the
            // Polylang link and the canonical full write have succeeded. Keep
            // the durable pending marker across either crash window so the
            // next export retries this same remote product instead of treating
            // a half-linked draft as complete.
            if ($translationCreationPending) {
                $this->completeTranslationCreation(
                    $product,
                    $salesChannelId,
                    $language,
                    $translatedProductId,
                );
            }
            $variantResponses = [];

            foreach ($variants as $variant) {
                // The collection was loaded before the Polish mappings were
                // created, so querying here avoids using a stale relation.
                $primaryMapping = ProductChannelMapping::query()
                    ->where('product_id', $variant->id)
                    ->where('sales_channel_id', $salesChannelId)
                    ->first();

                if (! $primaryMapping instanceof ProductChannelMapping || ! filled($primaryMapping->external_variation_id)) {
                    throw new RuntimeException("Wariant {$variant->sku} nie ma polskiego mapowania WooCommerce wymaganego do utworzenia tłumaczenia {$language}.");
                }

                $primaryResponse = collect($primaryVariantResults)
                    ->first(fn (mixed $result): bool => is_array($result)
                        && (string) ($result['sku'] ?? '') === $variant->sku);

                $variantResponses = array_merge(
                    $variantResponses,
                    $this->syncVariantTranslations(
                        $product,
                        $variant,
                        $integration,
                        $salesChannelId,
                        $primaryMapping,
                        (array) data_get($primaryResponse, 'response', []),
                    ),
                );
            }

            $results[] = [
                'language' => $language,
                'product_id' => $translatedProductId,
                'response' => $response,
                'variants' => $variantResponses,
                'translation_link' => $linkResponse === null ? null : [
                    'linked' => true,
                    'translation_group' => $linkResponse['translation_group'] ?? null,
                ],
            ];
        }

        return $results;
    }

    /**
     * @return array{token:string,resume:bool}
     */
    private function beginTranslationCreation(
        Product $product,
        int $salesChannelId,
        string $language,
    ): array {
        return DB::transaction(function () use ($product, $salesChannelId, $language): array {
            $mapping = ProductChannelMapping::query()
                ->where('product_id', $product->id)
                ->where('sales_channel_id', $salesChannelId)
                ->whereNull('external_variation_id')
                ->lockForUpdate()
                ->firstOrFail();
            $metadata = (array) $mapping->metadata;
            $creationStates = (array) data_get($metadata, 'product_translation_creation', []);
            $state = (array) ($creationStates[$language] ?? []);
            $token = trim((string) ($state['token'] ?? ''));
            $resume = $token !== '';

            if (! $resume) {
                $token = (string) Str::uuid();
                $state['started_at'] = now()->toISOString();
            } else {
                $state['last_resumed_at'] = now()->toISOString();
            }

            $state['token'] = $token;
            $state['pending'] = true;
            $creationStates[$language] = $state;
            data_set($metadata, 'product_translation_creation', $creationStates);
            $mapping->forceFill(['metadata' => $metadata])->save();

            return ['token' => $token, 'resume' => $resume];
        });
    }

    private function completeTranslationCreation(
        Product $product,
        int $salesChannelId,
        string $language,
        string $externalProductId,
    ): void {
        DB::transaction(function () use ($product, $salesChannelId, $language, $externalProductId): void {
            $mapping = ProductChannelMapping::query()
                ->where('product_id', $product->id)
                ->where('sales_channel_id', $salesChannelId)
                ->whereNull('external_variation_id')
                ->lockForUpdate()
                ->first();

            if (! $mapping instanceof ProductChannelMapping) {
                return;
            }

            $metadata = (array) $mapping->metadata;
            $creationStates = (array) data_get($metadata, 'product_translation_creation', []);
            $state = (array) ($creationStates[$language] ?? []);
            $state['pending'] = false;
            $state['external_product_id'] = $externalProductId;
            $state['completed_at'] = now()->toISOString();
            $creationStates[$language] = $state;
            data_set($metadata, 'product_translation_creation', $creationStates);
            $mapping->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function productTranslationCreationPending(
        Product $product,
        int $salesChannelId,
        string $language,
    ): bool {
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->where('sales_channel_id', $salesChannelId)
            ->whereNull('external_variation_id')
            ->first();
        $metadata = (array) $mapping?->metadata;

        return data_get($metadata, "product_translation_creation.{$language}.pending") === true;
    }

    /**
     * @return array{token:string,resume:bool,external_variation_id:string}
     */
    private function beginVariantTranslationCreation(
        ProductChannelMapping $primaryMapping,
        string $language,
        string $translatedParentId,
    ): array {
        return DB::transaction(function () use ($primaryMapping, $language, $translatedParentId): array {
            $mapping = ProductChannelMapping::query()
                ->lockForUpdate()
                ->findOrFail($primaryMapping->id);
            $metadata = (array) $mapping->metadata;
            $creationStates = (array) data_get($metadata, 'variation_translation_creation', []);
            $state = (array) ($creationStates[$language] ?? []);
            $token = trim((string) ($state['token'] ?? ''));
            $resume = $token !== '';
            $allocatedParentId = trim((string) ($state['external_product_id'] ?? ''));

            if ($allocatedParentId !== '' && $allocatedParentId !== $translatedParentId) {
                throw new RuntimeException(
                    "Rozpoczęte tłumaczenie wariantu {$language} wskazuje inny produkt nadrzędny WooCommerce #{$allocatedParentId}.",
                );
            }

            if (! $resume) {
                $token = (string) Str::uuid();
                $state['started_at'] = now()->toISOString();
            } else {
                $state['last_resumed_at'] = now()->toISOString();
            }

            $state['token'] = $token;
            $state['pending'] = true;
            $state['external_product_id'] = $translatedParentId;
            $creationStates[$language] = $state;
            data_set($metadata, 'variation_translation_creation', $creationStates);
            $mapping->forceFill(['metadata' => $metadata])->save();

            return [
                'token' => $token,
                'resume' => $resume,
                'external_variation_id' => trim((string) ($state['external_variation_id'] ?? '')),
            ];
        });
    }

    private function storeVariantTranslationAllocation(
        ProductChannelMapping $primaryMapping,
        string $language,
        string $token,
        string $translatedParentId,
        string $translatedVariationId,
    ): void {
        DB::transaction(function () use (
            $primaryMapping,
            $language,
            $token,
            $translatedParentId,
            $translatedVariationId,
        ): void {
            $mapping = ProductChannelMapping::query()
                ->lockForUpdate()
                ->findOrFail($primaryMapping->id);
            $metadata = (array) $mapping->metadata;
            $creationStates = (array) data_get($metadata, 'variation_translation_creation', []);
            $state = (array) ($creationStates[$language] ?? []);

            if (! hash_equals(trim((string) ($state['token'] ?? '')), $token)) {
                throw new RuntimeException(
                    "Token rozpoczętego tłumaczenia wariantu {$language} zmienił się podczas eksportu.",
                );
            }

            $allocatedVariationId = trim((string) ($state['external_variation_id'] ?? ''));

            if ($allocatedVariationId !== '' && $allocatedVariationId !== $translatedVariationId) {
                throw new RuntimeException(
                    "Rozpoczęte tłumaczenie wariantu {$language} ma już inne ID WooCommerce #{$allocatedVariationId}.",
                );
            }

            $state['pending'] = true;
            $state['external_product_id'] = $translatedParentId;
            $state['external_variation_id'] = $translatedVariationId;
            $state['allocated_at'] ??= now()->toISOString();
            $creationStates[$language] = $state;
            data_set($metadata, 'variation_translation_creation', $creationStates);
            $mapping->forceFill(['metadata' => $metadata])->save();
        });
    }

    private function pendingVariantTranslationCreationToken(
        ProductChannelMapping $primaryMapping,
        string $language,
    ): ?string {
        $mapping = ProductChannelMapping::query()->find($primaryMapping->id);
        $state = (array) data_get($mapping?->metadata, "variation_translation_creation.{$language}", []);
        $token = trim((string) ($state['token'] ?? ''));

        return ($state['pending'] ?? null) === true && $token !== '' ? $token : null;
    }

    private function completeVariantTranslationCreation(
        ProductChannelMapping $primaryMapping,
        string $language,
        string $token,
        string $translatedParentId,
        string $translatedVariationId,
    ): void {
        DB::transaction(function () use (
            $primaryMapping,
            $language,
            $token,
            $translatedParentId,
            $translatedVariationId,
        ): void {
            $mapping = ProductChannelMapping::query()
                ->lockForUpdate()
                ->find($primaryMapping->id);

            if (! $mapping instanceof ProductChannelMapping) {
                return;
            }

            $metadata = (array) $mapping->metadata;
            $creationStates = (array) data_get($metadata, 'variation_translation_creation', []);
            $state = (array) ($creationStates[$language] ?? []);

            if (! hash_equals(trim((string) ($state['token'] ?? '')), $token)) {
                throw new RuntimeException(
                    "Nie można zakończyć tłumaczenia wariantu {$language}: token eksportu nie jest już aktualny.",
                );
            }

            $state['pending'] = false;
            $state['external_product_id'] = $translatedParentId;
            $state['external_variation_id'] = $translatedVariationId;
            $state['completed_at'] = now()->toISOString();
            $creationStates[$language] = $state;
            data_set($metadata, 'variation_translation_creation', $creationStates);
            $mapping->forceFill(['metadata' => $metadata])->save();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function linkKnownProductTranslations(
        Product $product,
        WordpressIntegration $integration,
        int $salesChannelId,
        string $primaryExternalId,
    ): ?array {
        $translationMap = ['pl' => (int) $primaryExternalId];
        $exportLanguages = collect($integration->productExportLanguages())->flip();

        foreach ($this->translationReferences($product, $salesChannelId) as $language => $reference) {
            $externalProductId = trim((string) ($reference['product_id'] ?? ''));

            if ($language !== 'pl'
                && $exportLanguages->has((string) $language)
                && ctype_digit($externalProductId)
            ) {
                $translationMap[(string) $language] = (int) $externalProductId;
            }
        }

        if (count($translationMap) <= 1) {
            return null;
        }

        $this->setTranslationLinkPending($product, $salesChannelId, true);
        $linkResponse = $this->client->linkProductTranslations($integration, $translationMap);
        $this->setTranslationLinkPending($product, $salesChannelId, false);

        return $linkResponse;
    }

    private function saveTranslationReference(
        Product $product,
        int $salesChannelId,
        string $language,
        string $externalProductId,
        ?string $externalVariationId,
        string $sku,
    ): void {
        DB::transaction(function () use ($product, $salesChannelId, $language, $externalProductId, $externalVariationId, $sku): void {
            $lockedProduct = Product::query()
                ->lockForUpdate()
                ->findOrFail($product->id);
            $attributes = (array) $lockedProduct->attributes;
            data_set($attributes, "woocommerce_translations.{$language}", [
                'product_id' => $externalProductId,
                'variation_id' => $externalVariationId,
                'sku' => $sku,
            ]);
            $lockedProduct->forceFill(['attributes' => $attributes])->save();

            $externalKey = ProductChannelAlias::externalKey($externalProductId, $externalVariationId);
            $alias = ProductChannelAlias::query()
                ->where('sales_channel_id', $salesChannelId)
                ->where('external_key', $externalKey)
                ->lockForUpdate()
                ->first();

            if ($alias instanceof ProductChannelAlias && (int) $alias->product_id !== (int) $lockedProduct->id) {
                throw new RuntimeException(
                    "Identyfikator WooCommerce {$externalKey} jest już przypisany do innego produktu ERP w tym kanale.",
                );
            }

            $alias ??= new ProductChannelAlias([
                'sales_channel_id' => $salesChannelId,
                'external_key' => $externalKey,
            ]);
            $alias->fill([
                'product_id' => $lockedProduct->id,
                'external_product_id' => $externalProductId,
                'external_variation_id' => $externalVariationId,
                'external_sku' => $sku,
                'language' => $language,
                'source_product_id' => $alias->source_product_id,
                'metadata' => array_merge((array) $alias->metadata, [
                    'source' => 'erp_polylang_export',
                    'synced_at' => now()->toISOString(),
                ]),
            ])->save();
        });

        $product->refresh();
    }

    /**
     * @return array<string, array{product_id:string,variation_id:?string,sku:?string}>
     */
    /**
     * The imported `woocommerce_translations` snapshot is only a hint. When an
     * operator permanently deletes a translated post in Woo, a stale snapshot
     * entry would keep reporting the translation as existing, so
     * hasMissingTranslationReferences() never lets createTranslations() rebuild
     * it — the family stays monolingual forever. Verify each snapshot entry
     * against live Woo once per full export and drop the confirmed-dead ones;
     * anything but a definitive 404 propagates so a transient fault can never
     * erase a valid reference. Alias-backed families skip the check entirely
     * (aliases are authoritative and actively maintained).
     */
    public function pruneDeadLegacyTranslationSnapshot(
        Product $product,
        WordpressIntegration $integration,
    ): void {
        $snapshot = (array) data_get($product->attributes, 'woocommerce_translations', []);

        if ($snapshot === []) {
            return;
        }

        if (ProductChannelAlias::query()
            ->where('product_id', $product->id)
            ->whereNotNull('language')
            ->exists()) {
            return;
        }

        $dead = [];

        foreach ($snapshot as $language => $reference) {
            $externalProductId = trim((string) (is_array($reference) ? ($reference['product_id'] ?? '') : ''));

            if ($externalProductId === '') {
                continue;
            }

            try {
                $this->client->productById($integration, $externalProductId);
            } catch (RequestException $exception) {
                if ($exception->response?->status() !== 404) {
                    throw $exception;
                }

                $dead[] = $language;
            }
        }

        if ($dead === []) {
            return;
        }

        $attributes = (array) $product->attributes;
        $attributes['woocommerce_translations'] = collect($snapshot)
            ->except($dead)
            ->all();
        $product->forceFill(['attributes' => $attributes])->save();
        $product->refresh();
    }

    private function translationReferences(Product $product, int $salesChannelId): array
    {
        $canonicalHandoffTargets = $this->canonicalTranslationHandoffTargets($product);
        $aliases = ProductChannelAlias::query()
            ->where('product_id', $product->id)
            ->where('sales_channel_id', $salesChannelId)
            ->whereNotNull('language')
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductChannelAlias $alias): bool => $alias->isOutboundSyncEnabled());

        if ($aliases->isNotEmpty()) {
            return $aliases
                ->groupBy(fn (ProductChannelAlias $alias): string => (string) $alias->language)
                ->mapWithKeys(function (Collection $languageAliases, string $language) use (
                    $canonicalHandoffTargets,
                ): array {
                    // A merge alias remains useful for historical inbound
                    // identities. If a current contract alias for the same
                    // language also exists, it must be the sole translation
                    // reference used to build parent/variation endpoints.
                    $language = mb_strtolower(trim($language));
                    $handoffProductId = $canonicalHandoffTargets[$language] ?? null;
                    $alias = $handoffProductId === null
                        ? null
                        : $languageAliases->first(
                            fn (ProductChannelAlias $candidate): bool => trim((string) $candidate->external_product_id)
                                === $handoffProductId
                                && blank($candidate->external_variation_id),
                        );

                    if ($handoffProductId !== null && ! $alias instanceof ProductChannelAlias) {
                        throw new RuntimeException(
                            "Brak aktywnego aliasu {$language} dla zweryfikowanego produktu WooCommerce #{$handoffProductId}.",
                        );
                    }

                    $alias ??= $languageAliases->first(
                        fn (ProductChannelAlias $candidate): bool => blank($candidate->source_product_id)
                            && data_get($candidate->metadata, 'product_merge') === null,
                    ) ?? $languageAliases->first();

                    return [$language => [
                        'product_id' => (string) $alias->external_product_id,
                        'variation_id' => $alias->external_variation_id !== null
                            ? (string) $alias->external_variation_id
                            : null,
                        'sku' => $alias->external_sku !== null ? (string) $alias->external_sku : null,
                    ]];
                })
                ->all();
        }

        // Once a product has any channel-scoped translation aliases, the
        // legacy unscoped attributes must never leak IDs into another store.
        if (ProductChannelAlias::query()
            ->where('product_id', $product->id)
            ->whereNotNull('language')
            ->exists()) {
            return [];
        }

        $mappedChannelIds = ProductChannelMapping::query()
            ->where('product_id', $product->id)
            ->distinct()
            ->pluck('sales_channel_id');

        if ($mappedChannelIds->count() !== 1 || (int) $mappedChannelIds->first() !== $salesChannelId) {
            return [];
        }

        return collect((array) data_get($product->attributes, 'woocommerce_translations', []))
            ->filter(fn (mixed $reference): bool => is_array($reference))
            ->map(fn (array $reference): array => [
                'product_id' => (string) ($reference['product_id'] ?? ''),
                'variation_id' => filled($reference['variation_id'] ?? null)
                    ? (string) $reference['variation_id']
                    : null,
                'sku' => filled($reference['sku'] ?? null) ? (string) $reference['sku'] : null,
            ])
            ->all();
    }

    /** @return array<string,string> */
    private function canonicalTranslationHandoffTargets(Product $product): array
    {
        $handoff = (array) data_get(
            $product->masterData(),
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );

        if (! WooOwnedVariantAxisRepairService::isSynchronizedRevision($handoff['revision'] ?? null)
            || blank($handoff['canonical_full_export_handoff_at'] ?? null)
        ) {
            return [];
        }

        $targets = [];

        foreach ((array) ($handoff['rebuild_simple_translations'] ?? []) as $target) {
            if (! is_array($target)) {
                throw new RuntimeException('Zweryfikowane przekazanie odbudowy tłumaczenia ma niepoprawny cel.');
            }

            $language = mb_strtolower(trim((string) ($target['language'] ?? '')));
            $externalProductId = trim((string) ($target['external_product_id'] ?? ''));

            if (preg_match('/^[a-z][a-z0-9_-]*$/', $language) !== 1
                || preg_match('/^[1-9]\d*$/', $externalProductId) !== 1
                || isset($targets[$language])
            ) {
                throw new RuntimeException('Zweryfikowane przekazanie odbudowy tłumaczenia nie jest jednoznaczne.');
            }

            $targets[$language] = $externalProductId;
        }

        return $targets;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function exportOrCreateVariants(
        Product $product,
        Collection $variants,
        WordpressIntegration $integration,
        int $salesChannelId,
        string $externalProductId,
        bool $syncTranslations = true,
    ): array {
        $results = [];

        foreach ($variants as $variant) {
            $mapping = ProductChannelMapping::query()
                ->where('product_id', $variant->id)
                ->where('sales_channel_id', $salesChannelId)
                ->first();
            $aliasTarget = null;
            $targetMapping = $mapping;

            if ($mapping instanceof ProductChannelMapping
                && filled($mapping->external_variation_id)
                && trim((string) $mapping->external_product_id) !== $externalProductId
            ) {
                $aliasTargets = ProductChannelAlias::query()
                    ->where('product_id', $variant->id)
                    ->where('sales_channel_id', $salesChannelId)
                    ->where('external_product_id', $externalProductId)
                    ->whereNotNull('external_variation_id')
                    ->get()
                    ->filter(fn (ProductChannelAlias $alias): bool => $alias->isOutboundSyncEnabled())
                    ->values();
                $aliasTarget = $aliasTargets->count() === 1 ? $aliasTargets->first() : null;

                if (! $aliasTarget instanceof ProductChannelAlias
                    || blank($aliasTarget->external_variation_id)
                ) {
                    throw new RuntimeException(
                        "Wariant {$variant->sku} jest zmapowany do innego rodzica WooCommerce i nie ma dokładnego aliasu pod produktem #{$externalProductId}.",
                    );
                }

                $targetMapping = new ProductChannelMapping([
                    'product_id' => $variant->id,
                    'sales_channel_id' => $salesChannelId,
                    'external_product_id' => $aliasTarget->external_product_id,
                    'external_variation_id' => $aliasTarget->external_variation_id,
                    'external_sku' => $aliasTarget->external_sku,
                    'stock_sync_enabled' => true,
                ]);
            }

            $payload = $this->variationPayload($product, $variant, $salesChannelId);
            $payload = $this->globalizeProductAttributes($integration, $payload, 'pl');
            $operation = 'export_product_variation_data';

            if ($targetMapping instanceof ProductChannelMapping
                && filled($targetMapping->external_variation_id)
            ) {
                $payload = $this->preserveRemoteSkuWhenDuplicated($payload, $variant, $targetMapping);

                if ($aliasTarget instanceof ProductChannelAlias) {
                    // The alias can intentionally preserve a blank historical
                    // Woo SKU while the canonical mapping owns the ERP SKU.
                    unset($payload['sku']);
                }

                try {
                    $response = $this->client->updateProductData($integration, $targetMapping, $payload);
                } catch (RequestException $exception) {
                    if ($aliasTarget instanceof ProductChannelAlias
                        || ! $this->isDeletedWooVariationResponse($exception)
                    ) {
                        throw $exception;
                    }

                    $recovery = $this->recoverDeletedMappedVariation(
                        $product,
                        $variant,
                        $integration,
                        $mapping,
                        $payload,
                    );
                    $mapping = $recovery['mapping'];
                    $response = $recovery['response'];
                    $operation = $recovery['operation'];
                }
            } else {
                $created = $this->variationRelinker->createVariationResolvingSkuConflict(
                    $integration,
                    $salesChannelId,
                    $externalProductId,
                    $payload,
                );
                $response = $created['response'];
                $operation = 'create_product_variation';
                $variationExternalId = $response['id'] ?? null;

                if ($variationExternalId === null || (string) $variationExternalId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID wariantu {$variant->sku}.");
                }

                $creationMetadata = [
                    'source' => 'erp',
                    'created_via' => 'erp_product_export_variation_create',
                    'parent_product_id' => $product->id,
                    'created_in_woocommerce_at' => now()->toDateTimeString(),
                ];

                if ($created['resolution'] !== 'created') {
                    $creationMetadata['sku_conflict_resolution'] = $created['resolution'];
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
                        'metadata' => $creationMetadata,
                    ],
                );
                $targetMapping = $mapping;
            }

            if ($aliasTarget instanceof ProductChannelAlias) {
                $this->updateAliasAfterExport($aliasTarget, $payload, $response);
            } else {
                $this->updateMappingAfterExport($mapping, $variant, $payload, $response);
            }
            $translationResults = $syncTranslations && ! $aliasTarget instanceof ProductChannelAlias
                ? $this->syncVariantTranslations(
                    $product,
                    $variant,
                    $integration,
                    $salesChannelId,
                    $mapping,
                    $response,
                )
                : [];

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $salesChannelId,
                'wordpress_integration_id' => $integration->id,
                'direction' => 'out',
                'operation' => $operation,
                'status' => 'success',
                'external_resource' => 'product_variation',
                'external_id' => $targetMapping->external_variation_id ?? $targetMapping->external_product_id,
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
                'external_id' => $targetMapping->external_variation_id ?? $targetMapping->external_product_id,
                'response' => $response,
                'translations' => $translationResults,
            ];
        }

        return $results;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $response */
    private function updateAliasAfterExport(
        ProductChannelAlias $alias,
        array $payload,
        array $response,
    ): void {
        DB::transaction(function () use ($alias, $payload, $response): void {
            $locked = ProductChannelAlias::query()->lockForUpdate()->findOrFail($alias->id);
            $metadata = (array) $locked->metadata;
            data_set($metadata, 'product_data_export', [
                'last_exported_at' => now()->toISOString(),
                'last_export_status' => 'success',
                'payload_hash' => $this->payloadHash($payload),
                'response_id' => $response['id'] ?? null,
            ]);
            $locked->forceFill(['metadata' => $metadata])->save();
        }, 3);

        $alias->refresh();
    }

    private function isDeletedWooVariationResponse(RequestException $exception): bool
    {
        return $exception->response?->status() === 404
            && trim((string) data_get($exception->response?->json(), 'code'))
                === 'woocommerce_rest_product_variation_invalid_id';
    }

    /**
     * Woo assigns a new ID when an operator deletes and recreates a variation.
     * Reuse an exact live SKU/attribute match when one already exists;
     * otherwise recreate the confirmed-missing child and atomically replace
     * only its stale local mapping.
     *
     * @param  array<string,mixed>  $payload
     * @return array{mapping:ProductChannelMapping,response:array<string,mixed>,operation:string}
     */
    private function recoverDeletedMappedVariation(
        Product $parent,
        Product $variant,
        WordpressIntegration $integration,
        ProductChannelMapping $mapping,
        array $payload,
    ): array {
        return $this->variationRelinker->recoverByPayload(
            $parent,
            $variant,
            $integration,
            $mapping,
            $payload,
        );
    }

    /**
     * @param  Collection<int, Product>  $variants
     * @param  list<array<string, mixed>>  $primaryVariantResults
     */
    private function syncFamilyVariantTranslations(
        Product $parent,
        Collection $variants,
        WordpressIntegration $integration,
        int $salesChannelId,
        array $primaryVariantResults = [],
    ): void {
        foreach ($variants as $variant) {
            $mapping = ProductChannelMapping::query()
                ->where('product_id', $variant->id)
                ->where('sales_channel_id', $salesChannelId)
                ->whereNotNull('external_variation_id')
                ->first();

            if (! $mapping instanceof ProductChannelMapping) {
                continue;
            }

            $primaryResponse = collect($primaryVariantResults)
                ->first(fn (mixed $result): bool => is_array($result)
                    && (string) ($result['sku'] ?? '') === $variant->sku);

            $this->syncVariantTranslations(
                $parent,
                $variant,
                $integration,
                $salesChannelId,
                $mapping,
                (array) data_get($primaryResponse, 'response', []),
            );
        }
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
        array $primaryResponse = [],
    ): array {
        $results = [];
        $exportLanguages = collect($integration->productExportLanguages())->flip();
        $parentReferences = $this->translationReferences($parent, $salesChannelId);
        $variantReferences = $this->translationReferences($variant, $salesChannelId);

        foreach ($parentReferences as $language => $parentReference) {
            if (! is_array($parentReference) || ! $exportLanguages->has((string) $language)) {
                continue;
            }

            $translatedParentId = trim((string) ($parentReference['product_id'] ?? ''));

            if ($translatedParentId === '' || $translatedParentId === (string) $primaryMapping->external_product_id) {
                continue;
            }

            $language = trim((string) $language) ?: 'en';
            $payload = $this->variationPayload($parent, $variant, $salesChannelId, $language);
            $payload = $this->inheritPrimaryVariationCommerce(
                $payload,
                $integration,
                (string) $primaryMapping->external_product_id,
                (string) $primaryMapping->external_variation_id,
                $primaryResponse,
            );
            $payload = $this->globalizeProductAttributes($integration, $payload, $language);
            $variantReference = $variantReferences[$language] ?? null;
            $referencedParentId = is_array($variantReference)
                ? trim((string) ($variantReference['product_id'] ?? ''))
                : '';
            $referencedVariationId = is_array($variantReference)
                ? trim((string) ($variantReference['variation_id'] ?? ''))
                : '';

            // Old imports sometimes stored a Polish child post as the EN
            // alias. WordPress post IDs are global, so a translated child must
            // belong to the exact translated parent and differ from the PL
            // variation ID. Treat an invalid alias as absent; the token-aware
            // discovery/create path below will recover the real EN child
            // without modifying or duplicating the Polish variation.
            $translatedVariationId = $referencedParentId === $translatedParentId
                && ctype_digit($referencedVariationId)
                && $referencedVariationId !== (string) $primaryMapping->external_variation_id
                    ? $referencedVariationId
                    : '';
            $creationToken = $this->pendingVariantTranslationCreationToken(
                $primaryMapping,
                $language,
            );

            if ($translatedVariationId !== '') {
                $operation = 'updated';
            } else {
                $translationCreation = $this->beginVariantTranslationCreation(
                    $primaryMapping,
                    $language,
                    $translatedParentId,
                );
                $creationToken = $translationCreation['token'];
                $translatedVariationId = $translationCreation['external_variation_id'];

                if ($translatedVariationId === '' && ! $translationCreation['resume']) {
                    $discoveredVariation = $this->client->findProductVariation(
                        $integration,
                        $translatedParentId,
                        $primaryMapping->external_variation_id,
                        $variant->sku,
                        (array) ($payload['attributes'] ?? []),
                        $language,
                    );
                    $translatedVariationId = trim((string) ($discoveredVariation['id'] ?? ''));
                }

                if ($translatedVariationId !== '') {
                    $operation = 'updated_discovered';
                } else {
                    $response = $this->client->createProductVariationForLanguage(
                        $integration,
                        $translatedParentId,
                        $payload,
                        $language,
                        $translationCreation['token'],
                        $translationCreation['resume'],
                    );
                    $translatedVariationId = trim((string) ($response['id'] ?? ''));
                    $operation = 'created';
                }

                if ($translatedVariationId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID wariantu {$variant->sku} dla tłumaczenia {$language}.");
                }

                // The allocated ID is durable, but deliberately not exposed as
                // a catalog alias yet. A retry can resume it after link/update
                // failure without treating a half-linked variation as valid.
                $this->storeVariantTranslationAllocation(
                    $primaryMapping,
                    $language,
                    $translationCreation['token'],
                    $translatedParentId,
                    $translatedVariationId,
                );
            }

            // Link before assigning the canonical SKU. Both the child and its
            // translated parent map are verified by plugin 0.5.3, making this
            // call safe and idempotent for existing and historical children.
            $variationTranslationMap = [
                'pl' => (string) $primaryMapping->external_variation_id,
            ];
            $parentTranslationMap = [
                'pl' => (string) $primaryMapping->external_product_id,
            ];

            foreach ($parentReferences as $knownLanguage => $knownParentReference) {
                $knownLanguage = trim((string) $knownLanguage);
                $knownParentId = trim((string) data_get($knownParentReference, 'product_id', ''));
                $knownVariationId = $knownLanguage === $language
                    ? $translatedVariationId
                    : trim((string) data_get($variantReferences, "{$knownLanguage}.variation_id", ''));

                if ($knownLanguage === ''
                    || $knownLanguage === 'pl'
                    || ! $exportLanguages->has($knownLanguage)
                    || ! ctype_digit($knownParentId)
                    || ! ctype_digit($knownVariationId)
                ) {
                    continue;
                }

                $variationTranslationMap[$knownLanguage] = $knownVariationId;
                $parentTranslationMap[$knownLanguage] = $knownParentId;
            }

            $linkResponse = $this->client->linkProductVariationTranslations(
                $integration,
                $variationTranslationMap,
                $parentTranslationMap,
            );
            $response = $this->client->updateProductDataByIds(
                $integration,
                $translatedParentId,
                $translatedVariationId,
                $payload,
                $language,
            );

            // Publish the local alias only after WordPress has verified the
            // language relation and Woo accepted the complete canonical data.
            $this->saveTranslationReference(
                $variant,
                $salesChannelId,
                $language,
                $translatedParentId,
                $translatedVariationId,
                $variant->sku,
            );
            $variantReferences[$language] = [
                'product_id' => $translatedParentId,
                'variation_id' => $translatedVariationId,
                'sku' => $variant->sku,
            ];

            if ($creationToken !== null) {
                $this->completeVariantTranslationCreation(
                    $primaryMapping,
                    $language,
                    $creationToken,
                    $translatedParentId,
                    $translatedVariationId,
                );
            }

            $results[] = [
                'language' => $language,
                'product_id' => $translatedParentId,
                'variation_id' => $translatedVariationId,
                'operation' => $operation,
                'response_id' => $response['id'] ?? null,
                'translation_link' => [
                    'linked' => true,
                    'translation_group' => $linkResponse['translation_group'] ?? null,
                ],
            ];
        }

        return $results;
    }

    /**
     * A translated variation must never be published without the commercial
     * data of its exact Polish counterpart. Historical copied families can
     * have an empty inherited ERP price even though their mapped PL variation
     * still carries the valid value. Prefer the response from the PL PUT; when
     * this path did not perform that PUT, read the exact mapped variation.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $primaryResponse
     * @return array<string, mixed>
     */
    private function inheritPrimaryVariationCommerce(
        array $payload,
        WordpressIntegration $integration,
        string $externalProductId,
        string $externalVariationId,
        array $primaryResponse = [],
    ): array {
        if (($payload['regular_price'] ?? '') !== '') {
            return $payload;
        }

        if (! filled($primaryResponse['regular_price'] ?? null)) {
            $primaryResponse = $this->client->productVariation(
                $integration,
                $externalProductId,
                $externalVariationId,
            );
        }

        if (! filled($primaryResponse['regular_price'] ?? null)) {
            throw new RuntimeException(
                "Polski wariant WooCommerce #{$externalProductId}/{$externalVariationId} nie ma ceny regularnej; eksport tłumaczenia został przerwany.",
            );
        }

        $payload['regular_price'] = $this->decimal($primaryResponse['regular_price'], 2);
        $payload['sale_price'] = filled($primaryResponse['sale_price'] ?? null)
            ? $this->decimal($primaryResponse['sale_price'], 2)
            : '';
        $payload['date_on_sale_from'] = filled($primaryResponse['date_on_sale_from'] ?? null)
            ? (string) $primaryResponse['date_on_sale_from']
            : '';
        $payload['date_on_sale_to'] = filled($primaryResponse['date_on_sale_to'] ?? null)
            ? (string) $primaryResponse['date_on_sale_to']
            : '';

        return $payload;
    }

    private function removePendingVariants(
        Product $parent,
        WordpressIntegration $integration,
        ProductChannelMapping $parentMapping,
    ): void {
        $pendingMappings = ProductChannelMapping::query()
            ->with('product')
            ->where('sales_channel_id', $parentMapping->sales_channel_id)
            ->where('external_product_id', $parentMapping->external_product_id)
            ->whereNotNull('external_variation_id')
            ->get()
            ->filter(fn (ProductChannelMapping $mapping): bool => (int) data_get(
                $mapping->metadata,
                'pending_variant_removal.parent_product_id',
            ) === (int) $parent->id);
        $parentTranslations = $this->translationReferences($parent, (int) $parentMapping->sales_channel_id);

        foreach ($pendingMappings as $mapping) {
            $variant = $mapping->product;

            if ($variant instanceof Product) {
                $attributes = (array) $variant->attributes;

                foreach ($this->translationReferences($variant, (int) $parentMapping->sales_channel_id) as $language => $reference) {
                    if (! is_array($reference)) {
                        continue;
                    }

                    $translatedProductId = trim((string) ($reference['product_id'] ?? ''));
                    $translatedVariationId = trim((string) ($reference['variation_id'] ?? ''));
                    $expectedParentId = trim((string) data_get($parentTranslations, "{$language}.product_id", ''));

                    if ($translatedProductId === '' || $translatedVariationId === '' || $translatedProductId !== $expectedParentId) {
                        continue;
                    }

                    $this->client->deleteProductVariation($integration, $translatedProductId, $translatedVariationId);
                    data_forget($attributes, "woocommerce_translations.{$language}");
                    ProductChannelAlias::query()
                        ->where('product_id', $variant->id)
                        ->where('sales_channel_id', $parentMapping->sales_channel_id)
                        ->where('external_key', ProductChannelAlias::externalKey($translatedProductId, $translatedVariationId))
                        ->delete();
                }

                $variant->forceFill(['attributes' => $attributes])->save();
            }

            $this->client->deleteProductVariation(
                $integration,
                (string) $mapping->external_product_id,
                (string) $mapping->external_variation_id,
            );

            IntegrationSyncLog::query()->create([
                'sales_channel_id' => $mapping->sales_channel_id,
                'wordpress_integration_id' => $integration->id,
                'direction' => 'out',
                'operation' => 'delete_product_variation',
                'status' => 'success',
                'external_resource' => 'product_variation',
                'external_id' => $mapping->external_variation_id,
                'request_payload' => [
                    'parent_product_id' => $parent->id,
                    'product_id' => $mapping->product_id,
                ],
                'response_payload' => ['deleted' => true],
                'attempts' => 1,
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            $mapping->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        Product $product,
        bool $isVariation = false,
        ?int $salesChannelId = null,
        string $language = 'pl',
        ?bool $stockReleasePending = null,
        ?array $effectiveMaster = null,
        bool $fallbackLanguageContent = false,
    ): array {
        $product->loadMissing('channelMappings');
        $master = $effectiveMaster ?? $product->masterData();
        $retailPrice = data_get($master, 'prices.retail_price_pln');
        $salePrice = data_get($master, 'prices.sale_price_pln');
        $salePriceStartsAt = data_get($master, 'prices.sale_price_starts_at');
        $salePriceEndsAt = data_get($master, 'prices.sale_price_ends_at');
        $publicationDate = $this->dateTimeString(data_get($master, 'publication_date'));
        $lowStockAmount = data_get($master, 'inventory.low_stock_amount');
        $description = $this->descriptionSanitizer->sanitize(
            data_get($master, "content.{$language}.description")
                ?? data_get($master, 'content.pl.description'),
        );
        $shortDescription = $this->descriptionSanitizer->sanitize(
            data_get($master, "content.{$language}.additional_description")
                ?? data_get($master, 'content.pl.additional_description'),
        );
        $hasLanguageContent = $fallbackLanguageContent
            || $language === 'pl'
            || is_array(data_get($master, "content.{$language}"));
        $images = $this->images($product);
        $forceStorefrontStockZero = $product->forcesStorefrontStockZero();
        $stockReleasePending ??= $salesChannelId !== null
            && $this->mappingHasStockReleasePending($product, $salesChannelId);
        $manageStock = $forceStorefrontStockZero || (bool) data_get($master, 'inventory.manage_stock', true);
        $stockQuantity = $forceStorefrontStockZero
            ? 0
            : $this->freshStockQuantity($product, $salesChannelId);

        $payload = [
            'sku' => $product->sku,
            'global_unique_id' => $product->ean ?: '',
            'status' => $product->is_active ? (string) (data_get($master, 'publication_status') ?: 'publish') : 'draft',
            'manage_stock' => $manageStock,
            'stock_quantity' => $manageStock ? $stockQuantity : null,
            'stock_status' => $manageStock
                ? ($stockQuantity > 0 ? 'instock' : 'outofstock')
                : ($stockReleasePending ? 'instock' : null),
            'backorders' => $forceStorefrontStockZero
                ? 'no'
                : (string) data_get($master, 'inventory.backorders', 'no'),
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
            $payload['date_created'] = $this->dateTimeWithOffset($publicationDate);
        }

        // Always send the key: omitting it would leave the previous threshold
        // in WooCommerce forever after the operator clears the field in ERP.
        // Cleared value must be JSON null — the REST schema types this field
        // integer|null and rejects '' with rest_invalid_param (unlike the
        // string-typed prices, where '' is the documented clear sentinel).
        $payload['low_stock_amount'] = $lowStockAmount !== null && $lowStockAmount !== ''
            ? $lowStockAmount
            : null;

        if (! $isVariation) {
            if ($hasLanguageContent) {
                $payload['name'] = (string) (data_get($master, "content.{$language}.name") ?: data_get($master, 'content.pl.name') ?: $product->name);
                $payload['description'] = $description ?: '';
                $payload['short_description'] = $shortDescription ?: '';
            }

            $payload['type'] = (string) (data_get($master, 'product_type') ?: 'simple');
            $payload['attributes'] = $this->attributes($master, $language);
            $payload['categories'] = $this->categories($master, $salesChannelId, $language);
            $pendingRestoreVisibility = in_array(
                $product->storefront_restore_visibility,
                ['visible', 'catalog', 'search'],
                true,
            ) ? $product->storefront_restore_visibility : null;
            $payload['catalog_visibility'] = $product->isStorefrontHidden()
                ? 'hidden'
                : (string) ($pendingRestoreVisibility ?? data_get($master, 'catalog_visibility') ?: 'visible');
            $payload['upsell_ids'] = $this->relatedProductIds((array) data_get($master, 'related_products.upsell_skus', []), $salesChannelId);
            $payload['cross_sell_ids'] = $this->relatedProductIds((array) data_get($master, 'related_products.cross_sell_skus', []), $salesChannelId);

            $payload['images'] = $images;
        } else {
            if ($hasLanguageContent) {
                $payload['description'] = $description ?: '';
            }

            $payload['image'] = $images[0] ?? [];
        }

        if ($isVariation) {
            // Woo exposes variation `date_created` read-only. The canonical
            // date remains in `_sempre_erp_publication_date`; plugin 0.5.x
            // applies that value to the underlying variation post pre-insert.
            unset($payload['sold_individually'], $payload['date_created']);
        }

        // Mirror the sale_price contract below: an empty value must reach Woo
        // as '' so a price cleared in ERP actually clears in the shop instead
        // of silently selling at the stale amount. variationPayload() relies on
        // the '' sentinel to inherit the parent family price.
        $payload['regular_price'] = $retailPrice !== null && $retailPrice !== ''
            ? $this->decimal($retailPrice, 2)
            : '';

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
    private function prepareVariablePayload(Product $product, Collection $variants, array $payload, string $language = 'pl'): array
    {
        if ($variants->isEmpty()) {
            return $payload;
        }

        $payload['type'] = 'variable';
        $payload['attributes'] = $this->variableAttributes($product, $variants, $language);
        $payload['meta_data'] = $this->withVariantAttributeMeta(
            (array) ($payload['meta_data'] ?? []),
            $this->variantAttributeName($product, $variants),
        );
        // This ERP keeps sellable stock on child SKUs. Explicitly clearing the
        // variable parent's inventory/default selection also repairs stale Woo
        // settings left by older imports and removed variant axes.
        $payload['manage_stock'] = false;
        $payload['default_attributes'] = [];
        unset(
            $payload['regular_price'],
            $payload['sale_price'],
            $payload['date_on_sale_from'],
            $payload['date_on_sale_to'],
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
        $parentMaster = $parent->masterData();
        $master = $this->variantInheritance->masterData($parent, $variant);
        // Variable parents deliberately do not manage stock in WooCommerce,
        // but every child SKU must. An inherited variant otherwise copies the
        // parent's false flag and the final canonical PUT cannot restore the
        // quantity after the temporary translated child was created at zero.
        data_set($master, 'inventory.manage_stock', true);
        // A variation is published as part of its parent family. Historical
        // child records may still contain their own stale date (or none at
        // all), therefore the parent date is the only canonical value for PL
        // and every translated variation.
        data_set($master, 'publication_date', data_get($parentMaster, 'publication_date'));
        $payload = $this->payload(
            $variant,
            true,
            $salesChannelId,
            $language,
            $this->mappingHasStockReleasePending($parent, $salesChannelId),
            $master,
        );
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

        $payload['attributes'] = $this->variationAttributes($parent, $variant, $language);
        $payload['meta_data'] = $this->withVariantAttributeMeta(
            (array) ($payload['meta_data'] ?? []),
            $this->variantAttributeName($parent, $this->variantChildren($parent)),
        );
        $relationOrder = max(0, (int) ($variant->pivot?->sort_order ?? 100));
        $dictionaryOrder = data_get($payload, 'attributes.0.source_option_orders.0');
        $menuOrder = is_numeric($dictionaryOrder) ? (int) $dictionaryOrder : $relationOrder;
        // WooCommerce 10.9 ignores a falsey menu_order, hence the lower bound
        // of one. Dictionary ranks are shared by the parent taxonomy terms
        // and every child, so a later full export cannot reverse the family.
        $payload['menu_order'] = max(1, min(65535, $menuOrder));
        $payload['status'] = $parent->is_active && $variant->is_active
            ? (string) (data_get($parentMaster, 'publication_status') ?: 'publish')
            : 'draft';

        return $payload;
    }

    /**
     * Read stock from its authoritative tables for every outbound payload.
     * Product export can span the Polish product, translations and all child
     * variations; reusing an Eloquent relation loaded at the beginning of that
     * work would let a stale zero survive after inventory changed mid-export.
     */
    private function freshStockQuantity(Product $product, ?int $salesChannelId): int
    {
        if ($salesChannelId !== null) {
            $availability = $this->channelStock->availabilityForProduct(
                $salesChannelId,
                (int) $product->id,
            );

            // Match the dedicated stock-sync pipeline whenever the channel
            // has warehouse routes. With no routes, retain the legacy sum of
            // every balance, but query it fresh instead of reading a cached
            // model relation.
            if ($availability['breakdown'] !== []) {
                return (int) floor(max(0, $availability['quantity']));
            }
        }

        return (int) floor(max(0, (float) $product->stockBalances()
            ->sum('quantity_available')));
    }

    /**
     * Keep the ERP round-trip marker aligned with the concrete axis emitted
     * in the canonical Woo attributes. Otherwise a later Woo import can
     * resurrect the legacy generic `wariant`/`BLVariant` declaration.
     *
     * @param  list<array{key:string,value:mixed}>  $metaData
     * @return list<array{key:string,value:mixed}>
     */
    private function withVariantAttributeMeta(array $metaData, string $variantAttribute): array
    {
        $found = false;
        $metaData = collect($metaData)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row) use ($variantAttribute, &$found): array {
                if (($row['key'] ?? null) !== '_sempre_erp_variant_attribute') {
                    return $row;
                }

                $found = true;
                $row['value'] = $variantAttribute;

                return $row;
            })
            ->values();

        if (! $found) {
            $metaData->push([
                'key' => '_sempre_erp_variant_attribute',
                'value' => $variantAttribute,
            ]);
        }

        return $metaData->all();
    }

    private function mappingHasStockReleasePending(Product $product, int $salesChannelId): bool
    {
        $product->loadMissing('channelMappings');

        return $product->channelMappings->contains(
            fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === $salesChannelId
                && data_get($mapping->metadata, 'product_data_export.stock_release_pending') === true,
        );
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
        DB::transaction(function () use ($mapping, $product, $payload, $response): void {
            $lockedMapping = ProductChannelMapping::query()
                ->lockForUpdate()
                ->findOrFail($mapping->id);
            $currentMetadata = $lockedMapping->metadata ?? [];
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
                : (array_key_exists('sku', $payload) ? $product->sku : $lockedMapping->external_sku);

            $lockedMapping->update([
                'external_sku' => $externalSku,
                'metadata' => $metadata,
            ]);
        });

        $mapping->refresh();
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

        return collect($this->translationReferences($product, (int) $mapping->sales_channel_id))
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
            '_sempre_erp_publication_date' => $this->dateTimeString(data_get($master, 'publication_date')),
            '_sempre_erp_developed' => data_get($master, 'developed') ? '1' : '0',
            '_sempre_erp_location' => data_get($master, 'stock.location'),
            '_sempre_erp_name_en' => data_get($master, 'content.en.name'),
            '_sempre_erp_description_en' => $this->descriptionSanitizer->sanitize(
                data_get($master, 'content.en.description'),
            ),
            '_sempre_erp_short_description_en' => $this->descriptionSanitizer->sanitize(
                data_get($master, 'content.en.additional_description'),
            ),
            '_sempre_erp_upsell_skus' => implode(', ', (array) data_get($master, 'related_products.upsell_skus', [])),
            '_sempre_erp_cross_sell_skus' => implode(', ', (array) data_get($master, 'related_products.cross_sell_skus', [])),
            '_sempre_erp_product_type' => data_get($master, 'product_type'),
            '_sempre_erp_variant_attribute' => data_get($master, 'variant_attribute'),
            '_sempre_erp_storefront_hidden' => $product->isStorefrontHidden() ? '1' : '0',
            '_sempre_erp_stock_verification_required' => $product->requiresStockVerification() ? '1' : '0',
            '_sempre_erp_updated_at' => now()->toIso8601String(),
        ])
            // Send every key, coercing null to ''. Filtering empties out meant
            // a value cleared in ERP never overwrote its Woo meta, so the shop
            // kept stale tags, producer, EN content etc. indefinitely.
            ->map(fn ($value, string $key): array => ['key' => $key, 'value' => $value ?? ''])
            ->values()
            ->all();

        return array_merge(
            $meta,
            [['key' => '_ean', 'value' => $product->ean ?: '']],
            $this->customProductLabelMetaData($product, $language, $master),
            $this->shippingMetaData($master, $language),
        );
    }

    /**
     * @param  array<string, mixed>|null  $master
     * @return list<array{key:string,value:string}>
     */
    private function customProductLabelMetaData(
        Product $product,
        string $language,
        ?array $master = null,
    ): array {
        $master ??= $product->masterData();
        $language = mb_strtolower(trim($language)) ?: 'pl';

        return [
            ['key' => '_lemon_product_label_text', 'value' => (string) data_get($master, "custom_label.{$language}", '')],
            ['key' => '_lemon_product_label_bg_color', 'value' => (string) data_get($master, 'custom_label.bg_color', '')],
            ['key' => '_lemon_product_label_text_color', 'value' => (string) data_get($master, 'custom_label.text_color', '')],
        ];
    }

    /**
     * Always send all three keys so clearing a value in ERP also clears stale
     * WooCommerce metadata left by an earlier product export.
     *
     * @param  array<string, mixed>  $master
     * @return list<array{key:string,value:string}>
     */
    private function shippingMetaData(array $master, string $language = 'pl'): array
    {
        $days = data_get($master, 'shipping.days');
        $language = mb_strtolower(trim($language)) ?: 'pl';
        $textPath = $language === 'en' ? 'shipping.text_en' : 'shipping.text';

        return [
            ['key' => 'lemon_shipping_days', 'value' => $days === null || $days === '' ? '' : (string) (int) $days],
            ['key' => 'lemon_shipping_text', 'value' => (string) data_get($master, $textPath, '')],
            ['key' => 'lemon_preorder', 'value' => data_get($master, 'shipping.preorder', false) ? 'yes' : 'no'],
        ];
    }

    /**
     * @param  array<string, mixed>  $master
     * @return list<array{source_name:string,source_options:list<string>,source_option_orders:list<int|null>,source_position:int,position:int,name:string,visible:bool,variation:bool,options:list<string>}>
     */
    private function attributes(array $master, string $language = 'pl'): array
    {
        return collect(data_get($master, 'parameters', []))
            ->filter(fn ($row): bool => is_array($row))
            ->values()
            ->map(function (array $row, int $index) use ($language): ?array {
                $name = $this->translatedParameterName($row, $language);
                $value = $this->translatedParameterValue($row, $language);

                if ($name === '' || $value === '') {
                    return null;
                }

                [$sourceOptions, $localizedOptions] = $this->parameterAttributeOptions(
                    $row,
                    $value,
                    $language,
                );

                return [
                    'source_name' => trim((string) ($row['name'] ?? $name)),
                    'source_options' => $sourceOptions,
                    'source_option_orders' => $this->parameterOptionMenuOrders(
                        $row,
                        $sourceOptions,
                    ),
                    'source_position' => $this->parameterAttributePosition($row, $index),
                    'source_index' => $index,
                    'name' => $name,
                    'visible' => true,
                    'variation' => (bool) ($row['variation'] ?? false),
                    'options' => $localizedOptions,
                ];
            })
            ->filter()
            ->pipe(fn (Collection $attributes): array => $this->withCanonicalAttributePositions($attributes));
    }

    /**
     * A historical direct Size parameter stores a parent's complete option
     * list in one scalar (`M/L, S/M` or `36 | 37 | 38`). It is aggregate ERP
     * data, not one Woo taxonomy term. Split only direct Size aliases here;
     * values of unrelated attributes such as an informational colour remain
     * untouched because their punctuation can be meaningful catalog content.
     *
     * @param  array<string,mixed>  $parameter
     * @return array{0:list<string>,1:list<string>}
     */
    private function parameterAttributeOptions(
        array $parameter,
        string $localizedValue,
        string $language,
    ): array {
        $sourceValue = trim((string) ($parameter['value'] ?? $localizedValue));
        $isDirectSize = collect([
            $parameter['name'] ?? null,
            $parameter['name_en'] ?? null,
            $parameter['slug'] ?? null,
        ])
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->contains(fn (string $name): bool => $this->variantAxisNames->isDirectSizeAlias($name));

        if (! $isDirectSize) {
            return [[$sourceValue], [$localizedValue]];
        }

        $sourceOptions = $this->variantAxisNames->optionTokens([$sourceValue]);
        $localizedOptions = $this->variantAxisNames->optionTokens([$localizedValue]);

        if ($sourceOptions->isEmpty()) {
            return [[$sourceValue], [$localizedValue]];
        }

        $language = mb_strtolower(trim($language)) ?: 'pl';

        if ($language !== 'pl' && $localizedValue === $sourceValue) {
            $dictionary = collect();

            foreach ($this->sizeOrder->entries($language) as $entry) {
                foreach ((array) $entry['source_aliases'] as $alias) {
                    $dictionary->put(
                        $this->sizeOrder->key((string) $alias),
                        (string) $entry['localized'],
                    );
                }
            }

            $fromDictionary = $sourceOptions
                ->map(fn (string $option): string => trim((string) $dictionary->get(
                    $this->sizeOrder->key($option),
                    '',
                )));

            if ($fromDictionary->every(fn (string $option): bool => $option !== '')) {
                $localizedOptions = $fromDictionary;
            }
        }

        if ($localizedOptions->count() !== $sourceOptions->count()) {
            throw new RuntimeException(
                'Lista wartości Rozmiar/Size ma inną liczbę pozycji w wersji źródłowej i tłumaczeniu.',
            );
        }

        return [$sourceOptions->values()->all(), $localizedOptions->values()->all()];
    }

    /**
     * @param  Collection<int, Product>  $variants
     * @return list<array{source_name:string,source_options:list<string>,source_option_orders:list<int|null>,source_position:int,position:int,name:string,visible:bool,variation:bool,options:list<string>}>
     */
    private function variableAttributes(Product $product, Collection $variants, string $language = 'pl'): array
    {
        $master = $product->masterData();
        $sourceVariantAttribute = $this->variantAttributeName($product, $variants);
        $translationSource = $this->parameterRowForName($product, $sourceVariantAttribute)
            ?? $variants
                ->map(fn (Product $variant): ?array => $this->parameterRowForName($variant, $sourceVariantAttribute))
                ->first(fn (?array $parameter): bool => $parameter !== null)
            ?? ['name' => $sourceVariantAttribute];
        $variantAttribute = $this->renderedVariantAttributeName(
            $sourceVariantAttribute,
            $translationSource,
            $language,
            $product,
            $variants,
        );
        $variantOptionPairs = $variants
            ->map(function (Product $variant) use (
                $product,
                $sourceVariantAttribute,
                $variantAttribute,
                $language,
            ): array {
                return [
                    'source' => $this->canonicalVariantOption(
                        $product,
                        $sourceVariantAttribute,
                        $this->variationOption(
                            $variant,
                            $sourceVariantAttribute,
                            $sourceVariantAttribute,
                            'pl',
                        ),
                    ),
                    'localized' => $this->canonicalVariantOption(
                        $product,
                        $sourceVariantAttribute,
                        $this->variationOption(
                            $variant,
                            $sourceVariantAttribute,
                            $variantAttribute,
                            $language,
                        ),
                    ),
                ];
            })
            ->filter(fn (array $pair): bool => $pair['source'] !== '' && $pair['localized'] !== '')
            ->groupBy(fn (array $pair): string => $this->variantOptions->identity(
                $sourceVariantAttribute,
                $pair['source'],
            ))
            ->map(function (Collection $pairs) use ($variantAttribute): array {
                $localized = $pairs
                    ->unique(fn (array $pair): string => $this->variantOptions->identity(
                        $variantAttribute,
                        $pair['localized'],
                    ))
                    ->values();

                if ($localized->count() !== 1) {
                    throw new RuntimeException('Jedna źródłowa opcja wariantu ma kilka różnych tłumaczeń.');
                }

                return $localized->first();
            })
            ->values();
        $localizedCollisions = $variantOptionPairs
            ->groupBy(fn (array $pair): string => $this->variantOptions->identity(
                $variantAttribute,
                $pair['localized'],
            ))
            ->first(fn (Collection $pairs): bool => $pairs->count() > 1);

        if ($localizedCollisions instanceof Collection) {
            throw new RuntimeException('Dwie źródłowe opcje wariantu mają to samo tłumaczenie.');
        }

        $variantOptionPairs = $this->orderVariantOptionPairs(
            $translationSource,
            $variantOptionPairs,
        );
        $variantOptions = $variantOptionPairs->pluck('localized')->all();
        $sourceVariantOptions = $variantOptionPairs->pluck('source')->all();
        $attributes = collect($this->attributes($master, $language));
        $knownSizeOptions = $this->knownSizeOptions();
        $isVariantAxis = fn (array $attribute): bool => in_array(
            mb_strtolower(trim((string) ($attribute['source_name'] ?? $attribute['name']))),
            [mb_strtolower($sourceVariantAttribute), mb_strtolower($variantAttribute)],
            true,
        ) || $this->isLegacyGenericVariantAttribute(
            $attribute,
            $sourceVariantAttribute,
            $knownSizeOptions,
        ) || ($this->variantAxisNames->isDirectSizeAlias($sourceVariantAttribute)
            && $this->variantAxisNames->isDirectSizeAlias(
                (string) ($attribute['source_name'] ?? $attribute['name']),
            )
        );
        $existingAxis = $attributes->filter($isVariantAxis);
        $configuredAxisPosition = $this->parameterAttributeSortOrder($translationSource);
        $axisSourcePosition = $configuredAxisPosition
            ?? ($existingAxis->isNotEmpty()
                ? (int) $existingAxis->min('source_position')
                : 100_000 + $attributes->count());
        $axisSourceIndex = $existingAxis->isNotEmpty()
            ? (int) $existingAxis->min('source_index')
            : $attributes->count();

        $attributes = $attributes
            // The ERP variant model intentionally supports one variant axis.
            // Imported/stale flags must not make Woo require an additional
            // attribute that none of the child variations supplies.
            ->map(fn (array $attribute): array => array_merge($attribute, ['variation' => false]))
            ->reject($isVariantAxis)
            ->push([
                'source_name' => $sourceVariantAttribute,
                'source_options' => $sourceVariantOptions,
                'source_option_orders' => $this->parameterOptionMenuOrders(
                    $translationSource,
                    $sourceVariantOptions,
                ),
                'source_position' => $axisSourcePosition,
                'source_index' => $axisSourceIndex,
                'name' => $variantAttribute,
                'visible' => true,
                'variation' => true,
                'options' => $variantOptions,
            ]);

        return $this->withCanonicalAttributePositions($attributes);
    }

    /**
     * A generic legacy name is an alias of the selected size axis only when
     * its own values identify it as size data. An independent BLVariant
     * parameter containing colours must remain a normal Woo attribute.
     *
     * @param  array<string, mixed>  $attribute
     * @param  Collection<int, string>  $knownSizeOptions
     */
    private function isLegacyGenericVariantAttribute(
        array $attribute,
        string $selectedVariantAttribute,
        Collection $knownSizeOptions,
    ): bool {
        if ($this->legacySizeAxis->isLegacyGeneric($selectedVariantAttribute)
            || ! $this->variantAxisNames->isDirectSizeAlias($selectedVariantAttribute)
        ) {
            return false;
        }

        $attributeName = trim((string) ($attribute['source_name'] ?? $attribute['name'] ?? ''));

        return $this->legacySizeAxis->isLegacyGeneric($attributeName)
            && $this->variantAxisNames->resolve(
                $attributeName,
                (array) ($attribute['source_options'] ?? $attribute['options'] ?? []),
                $knownSizeOptions,
            ) === ProductVariantAxisNameResolver::SIZE;
    }

    /** @param array<string, mixed> $translationSource */
    private function renderedVariantAttributeName(
        string $sourceVariantAttribute,
        array $translationSource,
        string $language,
        Product $product,
        Collection $variants,
    ): string {
        if ($this->protectedMappedLegacyVariantAttribute($product, $variants) !== null) {
            return $this->translatedParameterName($translationSource, $language);
        }

        if ($this->variantAxisNames->isDirectSizeAlias($sourceVariantAttribute)) {
            return $language === '' || $language === 'pl'
                ? ProductVariantAxisNameResolver::SIZE
                : 'Size';
        }

        return $this->translatedParameterName($translationSource, $language);
    }

    /**
     * @return list<array{source_name:string,source_options:list<string>,source_option_orders:list<int|null>,name:string,option:string}>
     */
    private function variationAttributes(Product $parent, Product $variant, string $language = 'pl'): array
    {
        $familyVariants = $this->variantChildren($parent);

        if (! $familyVariants->contains(fn (Product $candidate): bool => $candidate->is($variant))) {
            $familyVariants->push($variant);
        }

        $sourceVariantAttribute = $this->variantAttributeName($parent, $familyVariants);
        $translationSource = $this->parameterRowForName($parent, $sourceVariantAttribute)
            ?? $this->parameterRowForName($variant, $sourceVariantAttribute)
            ?? ['name' => $sourceVariantAttribute];
        $variantAttribute = $this->renderedVariantAttributeName(
            $sourceVariantAttribute,
            $translationSource,
            $language,
            $parent,
            $familyVariants,
        );

        $sourceOption = $this->canonicalVariantOption(
            $parent,
            $sourceVariantAttribute,
            $this->variationOption(
                $variant,
                $sourceVariantAttribute,
                $sourceVariantAttribute,
                'pl',
            ),
        );

        return [[
            'source_name' => $sourceVariantAttribute,
            'source_options' => [$sourceOption],
            'source_option_orders' => $this->parameterOptionMenuOrders(
                $translationSource,
                [$sourceOption],
            ),
            'name' => $variantAttribute,
            'option' => $this->canonicalVariantOption(
                $parent,
                $sourceVariantAttribute,
                $this->variationOption(
                    $variant,
                    $sourceVariantAttribute,
                    $variantAttribute,
                    $language,
                ),
            ),
        ]];
    }

    /**
     * Convert ERP's internal name-based attributes into WooCommerce global
     * taxonomy references. `source_name` is intentionally kept only while
     * resolving the canonical ID and is never sent to WooCommerce.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function globalizeProductAttributes(
        WordpressIntegration $integration,
        array $payload,
        string $language,
    ): array {
        $attributes = collect((array) ($payload['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->values();

        if ($attributes->isEmpty()) {
            $payload['attributes'] = [];

            return $payload;
        }

        $resolved = [];
        $parentAttributeIndexes = [];
        $hasParentAttributes = false;

        foreach ($attributes as $attribute) {
            $sourceName = trim((string) ($attribute['source_name'] ?? $attribute['name'] ?? ''));
            $isVariationPayload = array_key_exists('option', $attribute);
            $options = $isVariationPayload
                ? [trim((string) ($attribute['option'] ?? ''))]
                : collect((array) ($attribute['options'] ?? []))
                    ->map(fn (mixed $option): string => trim((string) $option))
                    ->filter()
                    ->values()
                    ->all();
            $sourceOptions = collect((array) ($attribute['source_options'] ?? $options))
                ->map(fn (mixed $option): string => trim((string) $option))
                ->values()
                ->all();
            $global = $this->client->ensureGlobalProductAttribute(
                $integration,
                $sourceName,
                $options,
                $language,
                $sourceOptions,
                (array) ($attribute['source_option_orders'] ?? []),
            );
            $attributeId = (int) $global['id'];

            if ($isVariationPayload) {
                $resolved[] = [
                    'id' => $attributeId,
                    'option' => (string) ($global['options'][0] ?? $attribute['option'] ?? ''),
                ];

                continue;
            }

            $hasParentAttributes = true;
            $normalized = [
                'id' => $attributeId,
                'position' => max(0, (int) ($attribute['position'] ?? count($resolved))),
                'visible' => (bool) ($attribute['visible'] ?? true),
                'variation' => (bool) ($attribute['variation'] ?? false),
                'options' => array_values((array) $global['options']),
            ];

            // A legacy/imported ERP record may contain the same parameter more
            // than once. Woo requires one row per global taxonomy ID, so merge
            // its options while preserving their first-seen order.
            if (array_key_exists($attributeId, $parentAttributeIndexes)) {
                $index = $parentAttributeIndexes[$attributeId];
                $resolved[$index]['position'] = min(
                    (int) $resolved[$index]['position'],
                    (int) $normalized['position'],
                );
                $resolved[$index]['visible'] = $resolved[$index]['visible'] || $normalized['visible'];
                $resolved[$index]['variation'] = $resolved[$index]['variation'] || $normalized['variation'];
                $resolved[$index]['options'] = collect(array_merge(
                    (array) $resolved[$index]['options'],
                    $normalized['options'],
                ))
                    ->unique(fn (mixed $option): string => mb_strtolower(trim((string) $option)))
                    ->values()
                    ->all();

                continue;
            }

            $parentAttributeIndexes[$attributeId] = count($resolved);
            $resolved[] = $normalized;
        }

        if ($hasParentAttributes) {
            $resolved = collect($resolved)
                ->sortBy('position', SORT_NUMERIC)
                ->values()
                ->map(function (array $attribute, int $position): array {
                    $attribute['position'] = $position;

                    return $attribute;
                })
                ->all();
        }

        $payload['attributes'] = $resolved;

        return $payload;
    }

    /**
     * Resolve every localized taxonomy term, and link its Polylang term family,
     * before the first remote product/variation mutation. This keeps a failed
     * bilingual export from leaving WooCommerce with a parent that references
     * only one side of a translated global attribute.
     *
     * @param  Collection<int, Product>  $variants
     */
    private function preflightGlobalAttributeTranslations(
        Product $product,
        Collection $variants,
        WordpressIntegration $integration,
        int $salesChannelId,
    ): void {
        // Existing legacy aliases and discovery can still export a configured
        // language even when the local product has no content bucket for it.
        // Preflight the complete channel language set so those later paths
        // cannot discover/link attribute terms only after the Polish product
        // has already been mutated.
        foreach ($integration->productExportLanguages() as $language) {
            $language = trim((string) $language) ?: 'pl';
            $payload = $this->payload($product, false, $salesChannelId, $language);
            $payload = $this->prepareVariablePayload($product, $variants, $payload, $language);
            $this->globalizeProductAttributes($integration, $payload, $language);

            foreach ($variants as $variant) {
                $variantPayload = $this->variationPayload(
                    $product,
                    $variant,
                    $salesChannelId,
                    $language,
                );
                $this->globalizeProductAttributes($integration, $variantPayload, $language);
            }
        }
    }

    /**
     * @param  Collection<int, Product>  $variants
     */
    private function variantAttributeName(Product $product, Collection $variants): string
    {
        $name = trim((string) data_get($product->masterData(), 'variant_attribute', ''));
        $protectedLegacyAxis = $this->protectedMappedLegacyVariantAttribute($product, $variants);

        if ($protectedLegacyAxis !== null) {
            return $protectedLegacyAxis;
        }

        $recoveredSizeAxis = $this->legacySizeAxis->recover($product, $variants);

        if ($recoveredSizeAxis !== null) {
            return $this->canonicalVariantAttributeName($recoveredSizeAxis, $product, $variants);
        }

        if ($name !== '') {
            return $this->canonicalVariantAttributeName($name, $product, $variants);
        }

        $relationCandidates = $variants
            ->map(function (Product $variant): string {
                $metadata = $variant->pivot?->getAttribute('metadata');

                if (is_string($metadata)) {
                    $decoded = json_decode($metadata, true);
                    $metadata = is_array($decoded) ? $decoded : [];
                }

                return trim((string) data_get($metadata, 'variant_attribute', ''));
            })
            ->filter();
        $relationCandidate = $this->singleAttributeCandidate($relationCandidates);

        if ($relationCandidate !== null) {
            return $this->canonicalVariantAttributeName($relationCandidate, $product, $variants);
        }

        $commonVariantCandidates = $variants
            ->flatMap(function (Product $variant): array {
                return collect((array) data_get($variant->masterData(), 'parameters', []))
                    ->filter(fn (mixed $parameter): bool => is_array($parameter)
                        && (bool) ($parameter['variation'] ?? false))
                    ->map(fn (array $parameter): string => trim((string) ($parameter['name'] ?? '')))
                    ->filter()
                    ->unique(fn (string $candidate): string => mb_strtolower($candidate))
                    ->map(fn (string $candidate): array => [
                        'name' => $candidate,
                        'product_id' => (int) $variant->id,
                    ])
                    ->values()
                    ->all();
            })
            ->groupBy(fn (array $candidate): string => mb_strtolower($candidate['name']))
            ->filter(fn (Collection $candidates): bool => $candidates
                ->pluck('product_id')
                ->unique()
                ->count() === $variants->count())
            ->map(fn (Collection $candidates): string => (string) $candidates->first()['name'])
            ->values();
        $commonVariantCandidate = $this->singleAttributeCandidate($commonVariantCandidates);

        if ($commonVariantCandidate !== null) {
            return $this->canonicalVariantAttributeName($commonVariantCandidate, $product, $variants);
        }

        $parentCandidates = collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && (bool) ($parameter['variation'] ?? false))
            ->map(fn (array $parameter): string => trim((string) ($parameter['name'] ?? '')))
            ->filter();
        $parentCandidate = $this->singleAttributeCandidate($parentCandidates);

        if ($parentCandidate !== null) {
            return $this->canonicalVariantAttributeName($parentCandidate, $product, $variants);
        }

        return ProductVariantAxisNameResolver::SIZE;
    }

    /**
     * @param  Collection<int, Product>  $variants
     */
    private function canonicalVariantAttributeName(
        string $attributeName,
        Product $product,
        Collection $variants,
    ): string {
        $genericLegacyAxis = $this->variantAxisNames->isGenericSizeAlias($attributeName);
        $matches = fn (mixed $candidate): bool => mb_strtolower(trim((string) $candidate))
            === mb_strtolower(trim($attributeName))
            || ($genericLegacyAxis && (
                $this->variantAxisNames->isGenericSizeAlias((string) $candidate)
                || $this->variantAxisNames->isDirectSizeAlias((string) $candidate)
            ));
        $options = collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && $matches($parameter['name'] ?? ''))
            ->pluck('value');

        foreach ($variants as $variant) {
            $options = $options->merge(
                collect((array) data_get($variant->masterData(), 'parameters', []))
                    ->filter(fn (mixed $parameter): bool => is_array($parameter)
                        && $matches($parameter['name'] ?? ''))
                    ->pluck('value'),
            );

            foreach ($variant->wooVariationAttributes() as $attribute) {
                if ($matches($attribute['name'] ?? '')) {
                    $options->push($attribute['option'] ?? null);
                }
            }

            $metadata = $variant->pivot?->getAttribute('metadata');

            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $metadata = is_array($decoded) ? $decoded : [];
            }

            if ($matches(data_get($metadata, 'variant_attribute'))) {
                $options->push(data_get($metadata, 'variant_option'));
            }
        }

        return $this->variantAxisNames->resolve(
            $attributeName,
            $options,
            $this->knownSizeOptions(),
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function knownSizeOptions(): Collection
    {
        return ProductParameterDefinition::query()
            ->get(['name', 'name_en', 'slug', 'values', 'values_en'])
            ->filter(fn (ProductParameterDefinition $definition): bool => collect([
                $definition->name,
                $definition->name_en,
                $definition->slug,
            ])->contains(fn (mixed $name): bool => $this->variantAxisNames
                ->isDirectSizeAlias((string) $name)))
            ->flatMap(fn (ProductParameterDefinition $definition): array => [
                ...(array) $definition->values,
                ...(array) $definition->values_en,
            ])
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * A normal product export must never perform the parent-first half of a
     * remote taxonomy repair. Until the dedicated remote-first repair has
     * persisted its synchronized marker, keep an already mapped legacy family
     * on the axis currently represented by its Woo snapshot.
     *
     * @param  Collection<int, Product>  $variants
     */
    private function protectedMappedLegacyVariantAttribute(
        Product $product,
        Collection $variants,
    ): ?string {
        $product->loadMissing('channelMappings.salesChannel');
        $hasParentMapping = $product->channelMappings->contains(
            fn (ProductChannelMapping $mapping): bool => filled($mapping->external_product_id)
                && ! filled($mapping->external_variation_id)
                && $mapping->salesChannel?->type === 'woocommerce'
                && (bool) $mapping->salesChannel?->is_active,
        );

        if (! $hasParentMapping) {
            return null;
        }

        $localState = (array) data_get(
            $product->masterData(),
            WooOwnedVariantAxisRepairService::STATE_PATH,
            [],
        );

        if (WooOwnedVariantAxisRepairService::isSynchronizedRevision(
            $localState['revision'] ?? null,
        )) {
            return null;
        }

        $isLegacyAxis = function (mixed $name): bool {
            $name = trim((string) $name);

            return $name !== '' && (
                $this->legacySizeAxis->isLegacyGeneric($name)
                || $this->variantAxisNames->isLegacyPluralSizeAlias($name)
            );
        };
        $rawAxisNames = collect((array) data_get($product->attributes, 'woocommerce_attributes', []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (bool) ($attribute['variation'] ?? false))
            ->map(fn (array $attribute): string => trim((string) (
                $attribute['name'] ?? $attribute['slug'] ?? ''
            )))
            ->filter();

        foreach ($variants as $variant) {
            $rawAxisNames = $rawAxisNames->merge(
                collect($variant->wooVariationAttributes())
                    ->filter(fn (mixed $attribute): bool => is_array($attribute))
                    ->map(fn (array $attribute): string => trim((string) (
                        $attribute['name'] ?? $attribute['slug'] ?? ''
                    )))
                    ->filter(),
            );
        }

        $rawAxisNames = $rawAxisNames
            ->unique(fn (string $axis): string => mb_strtolower($axis))
            ->values();
        $legacyRawAxis = $rawAxisNames->first($isLegacyAxis);

        if (is_string($legacyRawAxis) && $legacyRawAxis !== '') {
            return $legacyRawAxis;
        }

        // A concrete raw snapshot proves Woo is already canonical. Fall back
        // to local metadata only when no remote-axis snapshot is available.
        if ($rawAxisNames->isNotEmpty()) {
            return null;
        }

        $declared = trim((string) data_get($product->masterData(), 'variant_attribute', ''));

        return $isLegacyAxis($declared) ? $declared : null;
    }

    /**
     * @param  Collection<int, string>  $candidates
     */
    private function singleAttributeCandidate(Collection $candidates): ?string
    {
        $candidates = $candidates
            ->filter()
            ->unique(fn (string $candidate): string => mb_strtolower(trim($candidate)))
            ->values();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $sizeCandidates = $candidates
            ->filter(fn (string $candidate): bool => $this->variantOptions->isSizeAttribute($candidate))
            ->values();

        return $sizeCandidates->count() === 1 ? $sizeCandidates->first() : null;
    }

    private function variationOption(
        Product $variant,
        string $sourceVariantAttribute,
        string $renderedVariantAttribute,
        string $language = 'pl',
    ): string {
        $parameters = collect((array) data_get($variant->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && trim((string) ($parameter['value'] ?? '')) !== '')
            ->values();
        $matching = $parameters
            ->filter(fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? '')))
                === mb_strtolower($sourceVariantAttribute))
            ->values();
        $parameter = $matching->first(fn (array $candidate): bool => (bool) ($candidate['variation'] ?? false)
            && ! $this->isAggregateVariantOption($candidate['value'] ?? null))
            ?? $matching->first(fn (array $candidate): bool => ! $this->isAggregateVariantOption(
                $candidate['value'] ?? null,
            ));
        $variantParameters = $parameters
            ->filter(fn (array $candidate): bool => (bool) ($candidate['variation'] ?? false)
                && ! $this->isAggregateVariantOption($candidate['value'] ?? null))
            ->values();

        if (! is_array($parameter) && $this->variantOptions->isSizeAttribute($sourceVariantAttribute)) {
            $sizeParameters = $variantParameters
                ->filter(fn (array $candidate): bool => $this->variantOptions->isSizeAttribute(
                    (string) ($candidate['name'] ?? ''),
                ))
                ->values();
            $parameter = $sizeParameters->count() === 1 ? $sizeParameters->first() : null;
        }

        if (! is_array($parameter) && $variantParameters->count() === 1) {
            $parameter = $variantParameters->first();
        }

        if (is_array($parameter)) {
            if (mb_strtolower(trim($sourceVariantAttribute))
                    === mb_strtolower(ProductVariantAxisNameResolver::SIZE)
                && $this->variantAxisNames->resolve(
                    (string) ($parameter['name'] ?? ''),
                    [$parameter['value'] ?? null],
                    $this->knownSizeOptions(),
                ) === ProductVariantAxisNameResolver::SIZE
            ) {
                $parameter = $this->canonicalSizeParameterRow($parameter);
            }

            return $this->variantOptions->normalize(
                $renderedVariantAttribute,
                $this->translatedParameterValue($parameter, $language),
            );
        }

        $wooAttributes = collect($variant->wooVariationAttributes())
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && trim((string) ($attribute['option'] ?? '')) !== '')
            ->values();
        $wooAttribute = $wooAttributes->first(fn (array $attribute): bool => in_array(
            mb_strtolower(trim((string) ($attribute['name'] ?? ''))),
            [mb_strtolower($sourceVariantAttribute), mb_strtolower($renderedVariantAttribute)],
            true,
        ));

        if (! is_array($wooAttribute) && $this->variantOptions->isSizeAttribute($sourceVariantAttribute)) {
            $sizeAttributes = $wooAttributes
                ->filter(fn (array $attribute): bool => $this->variantOptions->isSizeAttribute(
                    (string) ($attribute['name'] ?? ''),
                ))
                ->values();
            $wooAttribute = $sizeAttributes->count() === 1 ? $sizeAttributes->first() : null;
        }

        if (! is_array($wooAttribute) && $wooAttributes->count() === 1) {
            $wooAttribute = $wooAttributes->first();
        }

        if (is_array($wooAttribute)) {
            return $this->variantOptions->normalize(
                $renderedVariantAttribute,
                $this->translatedParameterValue([
                    'name' => $sourceVariantAttribute,
                    'value' => $wooAttribute['option'],
                ], $language),
            );
        }

        return $this->variantOptions->normalize($renderedVariantAttribute, trim($variant->name));
    }

    private function canonicalVariantOption(
        Product $parent,
        string $sourceVariantAttribute,
        string $option,
    ): string {
        return $this->legacySizeAxis->canonicalSizeOption(
            $parent,
            $sourceVariantAttribute,
            $option,
        ) ?? $option;
    }

    private function isAggregateVariantOption(mixed $value): bool
    {
        return preg_match('/[,;|]/u', trim((string) ($value ?? ''))) === 1;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parameterRowForName(Product $product, string $name): ?array
    {
        $normalizedName = mb_strtolower(trim($name));
        $parameters = collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter))
            ->values();
        $exact = $parameters->first(
            fn (array $parameter): bool => mb_strtolower(trim((string) ($parameter['name'] ?? '')))
                === $normalizedName,
        );
        $canonicalSizeRequested = $normalizedName
            === mb_strtolower(ProductVariantAxisNameResolver::SIZE);

        if (is_array($exact)) {
            return $canonicalSizeRequested
                ? $this->canonicalSizeParameterRow($exact)
                : $exact;
        }

        foreach ($parameters as $parameter) {
            if ($this->variantAxisNames->isDirectSizeAlias($name)
                && $this->variantAxisNames->isDirectSizeAlias(
                    (string) ($parameter['name'] ?? ''),
                )
            ) {
                return $canonicalSizeRequested
                    ? $this->canonicalSizeParameterRow($parameter)
                    : $parameter;
            }
        }

        if ($canonicalSizeRequested) {
            $knownSizeOptions = $this->knownSizeOptions();

            foreach ($parameters as $parameter) {
                if ($this->variantAxisNames->resolve(
                    (string) ($parameter['name'] ?? ''),
                    [$parameter['value'] ?? null],
                    $knownSizeOptions,
                ) === ProductVariantAxisNameResolver::SIZE) {
                    return $this->canonicalSizeParameterRow($parameter);
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $parameter */
    private function canonicalSizeParameterRow(array $parameter): array
    {
        $parameter['name'] = ProductVariantAxisNameResolver::SIZE;
        $parameter['name_en'] = 'Size';
        $parameter['slug'] = 'rozmiar';
        $parameter['_prefer_canonical_size_definition'] = true;

        return $parameter;
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function translatedParameterName(array $parameter, string $language): string
    {
        $sourceName = trim((string) ($parameter['name'] ?? ''));

        if ($language === '' || $language === 'pl') {
            return $sourceName;
        }

        $inlineName = trim((string) (
            $parameter["name_{$language}"]
            ?? data_get($parameter, "translations.{$language}.name")
            ?? ''
        ));

        if ($inlineName !== '') {
            return $inlineName;
        }

        $definition = $this->parameterDefinition($parameter);
        $translatedName = trim((string) (
            $definition?->getAttribute("name_{$language}")
            ?? data_get($definition?->metadata, "translations.{$language}.name")
            ?? ''
        ));

        return $translatedName !== '' ? $translatedName : $sourceName;
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function translatedParameterValue(array $parameter, string $language): string
    {
        $sourceValue = trim((string) ($parameter['value'] ?? ''));

        if ((bool) ($parameter['_prefer_canonical_size_definition'] ?? false)) {
            $canonicalValue = $this->canonicalSizeDictionaryValue(
                $parameter,
                $language,
            );

            if ($canonicalValue !== null) {
                return $canonicalValue;
            }
        }

        if ($language === '' || $language === 'pl') {
            return $sourceValue;
        }

        $inlineValue = trim((string) (
            $parameter["value_{$language}"]
            ?? data_get($parameter, "translations.{$language}.value")
            ?? ''
        ));

        if ($inlineValue !== '') {
            return $inlineValue;
        }

        $definition = $this->parameterDefinition($parameter);

        if (! $definition instanceof ProductParameterDefinition) {
            return $sourceValue;
        }

        $sourceValues = collect((array) $definition->values)->values();
        $translatedValues = collect((array) (
            $definition->getAttribute("values_{$language}")
            ?? data_get($definition->metadata, "translations.{$language}.values")
            ?? []
        ))->values();
        $sourceIndex = $sourceValues->search(
            fn (mixed $candidate): bool => mb_strtolower(trim((string) $candidate)) === mb_strtolower($sourceValue),
        );

        if ($sourceIndex === false) {
            return $sourceValue;
        }

        $translatedValue = trim((string) $translatedValues->get((int) $sourceIndex, ''));

        return $translatedValue !== '' ? $translatedValue : $sourceValue;
    }

    /** @param array<string, mixed> $parameter */
    private function canonicalSizeDictionaryValue(array $parameter, string $language): ?string
    {
        $language = trim($language) ?: 'pl';
        $inlineValue = trim((string) (
            $parameter["value_{$language}"]
            ?? data_get($parameter, "translations.{$language}.value")
            ?? ''
        ));
        $candidates = collect([
            $parameter['value'] ?? null,
            $inlineValue,
        ])
            ->filter(fn (mixed $value): bool => is_scalar($value) || $value instanceof \Stringable)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => $this->sizeOrder->key($value));

        foreach ($candidates as $candidate) {
            $localized = $this->sizeOrder->localizedOption($candidate, $language);

            if (is_string($localized) && $localized !== '') {
                return $localized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parameter
     */
    private function parameterDefinition(array $parameter): ?ProductParameterDefinition
    {
        $name = mb_strtolower(trim((string) ($parameter['name'] ?? '')));
        $slug = mb_strtolower(trim((string) ($parameter['slug'] ?? '')));

        if ($name === '' && $slug === '') {
            return null;
        }

        // Attribute dictionaries are operator-managed catalog data. Always
        // read the current rows so a long-running export cannot reuse an old
        // order after the dictionary was edited.
        $definitions = ProductParameterDefinition::query()->get();

        if ((bool) ($parameter['_prefer_canonical_size_definition'] ?? false)) {
            $canonicalSize = $definitions->first(
                fn (ProductParameterDefinition $definition): bool => mb_strtolower(trim(
                    (string) $definition->name,
                )) === mb_strtolower(ProductVariantAxisNameResolver::SIZE),
            );

            if ($canonicalSize instanceof ProductParameterDefinition) {
                return $canonicalSize;
            }
        }

        return $definitions->first(function (ProductParameterDefinition $definition) use ($name, $slug): bool {
            if ($slug !== '' && mb_strtolower(trim((string) $definition->slug)) === $slug) {
                return true;
            }

            return $name !== '' && mb_strtolower(trim((string) $definition->name)) === $name;
        });
    }

    /** @param array<string, mixed> $parameter */
    private function parameterAttributePosition(array $parameter, int $fallbackIndex): int
    {
        $configuredPosition = $this->parameterAttributeSortOrder($parameter);

        // Configured definitions come first; parameters unknown to the shared
        // dictionary retain their order from the product payload afterwards.
        return $configuredPosition ?? 100_000 + max(0, $fallbackIndex);
    }

    /** @param array<string, mixed> $parameter */
    private function parameterAttributeSortOrder(array $parameter): ?int
    {
        $definition = $this->parameterDefinition($parameter);

        return $definition instanceof ProductParameterDefinition
            ? max(0, (int) $definition->sort_order)
            : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $attributes
     * @return list<array<string, mixed>>
     */
    private function withCanonicalAttributePositions(Collection $attributes): array
    {
        return $attributes
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->sort(function (array $left, array $right): int {
                $comparison = (int) ($left['source_position'] ?? PHP_INT_MAX)
                    <=> (int) ($right['source_position'] ?? PHP_INT_MAX);

                if ($comparison !== 0) {
                    return $comparison;
                }

                return (int) ($left['source_index'] ?? PHP_INT_MAX)
                    <=> (int) ($right['source_index'] ?? PHP_INT_MAX);
            })
            ->values()
            ->map(function (array $attribute, int $position): array {
                $attribute['position'] = $position;

                return $attribute;
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $parameter
     * @param  Collection<int, array{source:string,localized:string}>  $pairs
     * @return Collection<int, array{source:string,localized:string}>
     */
    private function orderVariantOptionPairs(array $parameter, Collection $pairs): Collection
    {
        $orders = $this->parameterOptionMenuOrders(
            $parameter,
            $pairs->pluck('source')->all(),
        );

        return $pairs
            ->values()
            ->map(function (array $pair, int $index) use ($orders): array {
                $pair['dictionary_order'] = $orders[$index] ?? null;
                $pair['source_index'] = $index;

                return $pair;
            })
            ->sort(function (array $left, array $right): int {
                $leftKnown = is_numeric($left['dictionary_order']);
                $rightKnown = is_numeric($right['dictionary_order']);

                if ($leftKnown !== $rightKnown) {
                    return $leftKnown ? -1 : 1;
                }

                if ($leftKnown) {
                    $comparison = (int) $left['dictionary_order']
                        <=> (int) $right['dictionary_order'];

                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return (int) $left['source_index'] <=> (int) $right['source_index'];
            })
            ->values()
            ->map(function (array $pair): array {
                unset($pair['dictionary_order'], $pair['source_index']);

                return $pair;
            });
    }

    /**
     * The order of values in ERP's shared parameter dictionary is the global
     * storefront order for a WooCommerce taxonomy. Returning null for an
     * unknown value deliberately avoids letting the last exported product
     * redefine a catalog-wide term order from its local family order.
     *
     * @param  list<string>  $sourceOptions
     * @return list<int|null>
     */
    private function parameterOptionMenuOrders(array $parameter, array $sourceOptions): array
    {
        $definition = $this->parameterDefinition($parameter);
        $isSize = collect([
            $parameter['name'] ?? null,
            $parameter['name_en'] ?? null,
            $parameter['slug'] ?? null,
        ])
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->contains(fn (string $attributeName): bool => $this->sizeOrder->isSizeAxis(
                $attributeName,
                $sourceOptions,
                $definition,
            ));

        if ($isSize) {
            if (collect($sourceOptions)->contains(
                fn (mixed $option): bool => $this->isAggregateVariantOption($option),
            )) {
                // The informational parent row is replaced by the concrete
                // per-child axis in variableAttributes(); never create one
                // taxonomy term from its `S | M` aggregate placeholder.
                return array_fill(0, count($sourceOptions), null);
            }

            return $this->sizeOrder->menuOrders(
                $sourceOptions,
            );
        }

        if (! $definition instanceof ProductParameterDefinition) {
            return array_fill(0, count($sourceOptions), null);
        }

        $definitionName = (string) $definition->name;

        $dictionaryEntries = collect((array) $definition->values)
            ->map(fn (mixed $value): string => $this->variantOptions->identity(
                $definitionName,
                $value,
            ))
            ->filter()
            ->unique();

        $orders = $dictionaryEntries
            ->values()
            ->mapWithKeys(fn (string $identity, int $index): array => [
                $identity => ($index + 1) * 10,
            ])
            ->all();

        return collect($sourceOptions)
            ->map(fn (mixed $option): ?int => $orders[$this->variantOptions->identity(
                $definitionName,
                $option,
            )] ?? null)
            ->all();
    }

    /**
     * @return Collection<int, Product>
     */
    private function variantChildren(Product $product): Collection
    {
        $product->loadMissing(['variantChildren.channelMappings.salesChannel']);

        return $product->variantChildren
            ->filter(fn (Product $variant): bool => $variant->is_active || $variant->forcesStorefrontStockZero())
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

        $languages = collect($integration->productExportLanguages())
            ->map(fn (mixed $language): string => trim((string) $language))
            ->filter()
            ->unique()
            ->values();

        if ($languages->isEmpty()) {
            $languages = collect(['pl']);
        }

        $languages = $languages
            ->sortBy(fn (string $language): int => $language === 'pl' ? 0 : 1)
            ->values();
        $availableCategories = ProductCategory::query()
            ->where(fn ($query) => $query
                ->where('sales_channel_id', $salesChannelId)
                ->orWhereNull('sales_channel_id'))
            ->get();
        $selectedCategories = $availableCategories->whereIn('id', $categoryIds);
        $categories = $this->categoriesWithAncestors($selectedCategories, $availableCategories, $salesChannelId);

        foreach ($categories as $category) {
            $metadata = (array) $category->metadata;

            if (ctype_digit((string) $category->external_id) && blank(data_get($metadata, 'woocommerce_ids.pl'))) {
                data_set($metadata, 'woocommerce_ids.pl', (string) $category->external_id);
            }

            foreach ($languages as $language) {
                if (filled(data_get($metadata, "woocommerce_ids.{$language}"))) {
                    continue;
                }

                $translation = (array) data_get($metadata, "translations.{$language}", []);
                $parent = $this->categoryParent($category, $availableCategories, $salesChannelId);
                $parentExternalId = $parent instanceof ProductCategory
                    ? data_get($parent->metadata, "woocommerce_ids.{$language}")
                    : null;
                $primaryExternalId = data_get($metadata, 'woocommerce_ids.pl');
                $translations = $language !== 'pl' && ctype_digit((string) $primaryExternalId)
                    ? ['pl' => (int) $primaryExternalId]
                    : [];

                $response = $this->client->createProductCategory($integration, array_filter([
                    'name' => $translation['name'] ?? $category->name,
                    'slug' => $translation['slug'] ?? $category->slug ?: null,
                    'description' => $translation['description'] ?? $category->description ?: '',
                    'parent' => ctype_digit((string) $parentExternalId) ? (int) $parentExternalId : null,
                    'translations' => $translations !== [] ? $translations : null,
                ], fn (mixed $value): bool => $value !== null), $language);
                $externalId = trim((string) ($response['id'] ?? ''));

                if ($externalId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID utworzonej kategorii {$category->name} ({$language}).");
                }

                data_set($metadata, "woocommerce_ids.{$language}", $externalId);

                // Persist every allocated remote ID immediately. A failed link retry must
                // never create another category for the same ERP record.
                $category->forceFill([
                    'sales_channel_id' => $salesChannelId,
                    'metadata' => $metadata,
                ])->save();
            }

            $translationIds = $languages
                ->mapWithKeys(function (string $language) use ($metadata): array {
                    $externalId = trim((string) data_get($metadata, "woocommerce_ids.{$language}", ''));

                    return ctype_digit($externalId) ? [$language => (int) $externalId] : [];
                })
                ->all();
            $translationSignature = $this->categoryTranslationSignature($translationIds);

            if (count($translationIds) > 1
                && data_get($metadata, 'polylang.translation_signature') !== $translationSignature
            ) {
                $linkResponse = $this->client->linkProductCategoryTranslations($integration, $translationIds);
                data_set($metadata, 'polylang.translation_signature', $translationSignature);
                data_set($metadata, 'polylang.translation_group', $linkResponse['translation_group'] ?? null);
            }

            $category->forceFill([
                'sales_channel_id' => $salesChannelId,
                'metadata' => $metadata,
            ])->save();
        }
    }

    /**
     * @param  Collection<int, ProductCategory>  $selectedCategories
     * @param  Collection<int, ProductCategory>  $availableCategories
     * @return Collection<int, ProductCategory>
     */
    private function categoriesWithAncestors(
        Collection $selectedCategories,
        Collection $availableCategories,
        int $salesChannelId,
    ): Collection {
        $ordered = collect();
        $visited = [];

        $visit = function (ProductCategory $category) use (&$visit, &$visited, $ordered, $availableCategories, $salesChannelId): void {
            if (isset($visited[$category->id])) {
                return;
            }

            $visited[$category->id] = true;
            $parent = $this->categoryParent($category, $availableCategories, $salesChannelId);

            if ($parent instanceof ProductCategory) {
                $visit($parent);
            }

            $ordered->push($category);
        };

        foreach ($selectedCategories as $category) {
            $visit($category);
        }

        return $ordered->values();
    }

    /**
     * @param  Collection<int, ProductCategory>  $availableCategories
     */
    private function categoryParent(
        ProductCategory $category,
        Collection $availableCategories,
        int $salesChannelId,
    ): ?ProductCategory {
        $parentExternalId = trim((string) $category->parent_external_id);

        if ($parentExternalId === '') {
            return null;
        }

        return $availableCategories
            ->filter(function (ProductCategory $candidate) use ($category, $parentExternalId): bool {
                if ($candidate->is($category)) {
                    return false;
                }

                if ((string) $candidate->external_id === $parentExternalId) {
                    return true;
                }

                return collect((array) data_get($candidate->metadata, 'woocommerce_ids', []))
                    ->contains(fn (mixed $id): bool => (string) $id === $parentExternalId);
            })
            ->sortBy(function (ProductCategory $candidate) use ($category, $salesChannelId): int {
                if ((int) $candidate->sales_channel_id === (int) $category->sales_channel_id) {
                    return 0;
                }

                return (int) $candidate->sales_channel_id === $salesChannelId ? 1 : 2;
            })
            ->first();
    }

    /**
     * @param  array<string, int>  $translations
     */
    private function categoryTranslationSignature(array $translations): string
    {
        ksort($translations);

        return sha1(json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
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

    private function dateTimeWithOffset(mixed $value): ?string
    {
        $value = $this->dateTimeString($value);

        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(
                '!Y-m-d\TH:i:s',
                $value,
                (string) config('app.timezone', 'UTC'),
            )->format('Y-m-d\TH:i:sP');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Export languages are not coupled to import filtering. At the same time,
     * a genuinely monolingual legacy record must not suddenly create an empty
     * translation. New ERP records and copies always carry an `en` content
     * bucket, even when its fields intentionally fall back to Polish.
     *
     * @return list<string>
     */
    private function exportLanguages(Product $product, WordpressIntegration $integration): array
    {
        $master = $product->masterData();

        return collect($integration->productExportLanguages())
            ->filter(fn (string $language): bool => $language === 'pl'
                || is_array(data_get($master, "content.{$language}"))
                || ($language === 'en' && $this->variantInheritance->isCopiedFamily($product)))
            ->values()
            ->all();
    }

    private function hasMissingTranslationReferences(
        Product $product,
        WordpressIntegration $integration,
        int $salesChannelId,
    ): bool {
        $references = $this->translationReferences($product, $salesChannelId);

        foreach ($this->exportLanguages($product, $integration) as $language) {
            if ($language === 'pl') {
                continue;
            }

            if (! filled(data_get($references, "{$language}.product_id"))) {
                return true;
            }
        }

        return false;
    }

    private function setTranslationLinkPending(Product $product, int $salesChannelId, bool $pending): void
    {
        DB::transaction(function () use ($product, $salesChannelId, $pending): void {
            $mapping = ProductChannelMapping::query()
                ->where('product_id', $product->id)
                ->where('sales_channel_id', $salesChannelId)
                ->whereNull('external_variation_id')
                ->lockForUpdate()
                ->first();

            if (! $mapping instanceof ProductChannelMapping) {
                return;
            }

            $metadata = (array) $mapping->metadata;

            if ($pending) {
                data_set($metadata, 'product_translation_link.pending', true);
                data_set($metadata, 'product_translation_link.requested_at', now()->toISOString());
            } else {
                data_forget($metadata, 'product_translation_link.pending');
                data_set($metadata, 'product_translation_link.completed_at', now()->toISOString());
            }

            $mapping->forceFill(['metadata' => $metadata])->save();
        });
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
        ?Product $variantParent = null,
        array $primaryResponse = [],
        array $primaryVariantResults = [],
    ): array {
        $pendingTranslationCreation = collect((array) data_get(
            $mapping->metadata,
            'product_translation_creation',
            [],
        ))->contains(
            fn (mixed $state): bool => is_array($state)
                && data_get($state, 'pending') === true,
        );
        $shouldCreateOrResume = ! $isVariation && (
            data_get($mapping->metadata, 'creation_state') === 'creating'
            || data_get($mapping->metadata, 'product_translation_link.pending') === true
            || $pendingTranslationCreation
        );
        $results = ! $shouldCreateOrResume
            ? []
            : $this->createTranslations(
                $product,
                $variants,
                $integration,
                (int) $mapping->sales_channel_id,
                (string) $mapping->external_product_id,
                $primaryVariantResults,
            );
        $createdLanguages = collect($results)->pluck('language')->filter()->map(fn (mixed $language): string => (string) $language);
        $mainProductId = (string) $mapping->external_product_id;
        $mainVariationId = filled($mapping->external_variation_id) ? (string) $mapping->external_variation_id : null;
        $exportLanguages = collect($integration->productExportLanguages())->flip();

        foreach ($this->translationReferences($product, (int) $mapping->sales_channel_id) as $language => $reference) {
            if (! is_array($reference) || ! $exportLanguages->has((string) $language)) {
                continue;
            }

            $externalProductId = trim((string) ($reference['product_id'] ?? ''));
            $externalVariationId = filled($reference['variation_id'] ?? null) ? (string) $reference['variation_id'] : null;

            if ($externalProductId === '' || ($externalProductId === $mainProductId && $externalVariationId === $mainVariationId)) {
                continue;
            }

            $language = mb_strtolower(trim((string) $language));

            if ($language === '') {
                continue;
            }

            if ($createdLanguages->contains($language)) {
                continue;
            }

            $payload = $isVariation && $variantParent instanceof Product
                ? $this->variationPayload(
                    $variantParent,
                    $product,
                    (int) $mapping->sales_channel_id,
                    $language,
                )
                : $this->payload($product, $isVariation, (int) $mapping->sales_channel_id, $language);

            if ($isVariation && $variantParent instanceof Product) {
                $payload = $this->inheritPrimaryVariationCommerce(
                    $payload,
                    $integration,
                    (string) $mapping->external_product_id,
                    (string) $mapping->external_variation_id,
                    $primaryResponse,
                );
            }
            $payload = $this->preserveRemoteSkuWhenDuplicated($payload, $product, $mapping);

            if (! $isVariation) {
                $payload = $this->prepareVariablePayload($product, $variants, $payload, $language);
            }

            $payload = $this->globalizeProductAttributes($integration, $payload, $language);

            $response = $this->client->updateProductDataByIds(
                $integration,
                $externalProductId,
                $externalVariationId,
                $payload,
                $language,
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

    /**
     * @return array{parent:Product,variant:Product}|null
     */
    private function variantExportContext(Product $variant, ProductChannelMapping $mapping): ?array
    {
        $parent = ProductChannelMapping::query()
            ->with('product')
            ->where('sales_channel_id', $mapping->sales_channel_id)
            ->where('external_product_id', $mapping->external_product_id)
            ->whereNull('external_variation_id')
            ->where('product_id', '!=', $variant->id)
            ->first()?->product;

        if (! $parent instanceof Product) {
            $parents = $variant->variantParents()->get();
            $parent = $parents->count() === 1 ? $parents->first() : null;
        }

        if (! $parent instanceof Product) {
            return null;
        }

        $relatedVariant = $parent->variantChildren()
            ->where('products.id', $variant->id)
            ->first();

        return [
            'parent' => $parent,
            'variant' => $relatedVariant instanceof Product ? $relatedVariant : $variant,
        ];
    }

    private function markCreationCompleted(ProductChannelMapping $mapping): void
    {
        DB::transaction(function () use ($mapping): void {
            $lockedMapping = ProductChannelMapping::query()
                ->lockForUpdate()
                ->find($mapping->id);

            if (! $lockedMapping instanceof ProductChannelMapping) {
                return;
            }

            $metadata = (array) $lockedMapping->metadata;

            if (($metadata['creation_state'] ?? null) !== 'creating') {
                return;
            }

            $metadata['creation_state'] = 'completed';
            $metadata['creation_completed_at'] = now()->toDateTimeString();
            $lockedMapping->forceFill(['metadata' => $metadata])->save();
        });

        $mapping->refresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function syncDiscoveredTranslations(
        Product $product,
        WordpressIntegration $integration,
        ProductChannelMapping $mapping,
        bool $isVariation,
        Collection $variants,
        bool $force = false,
    ): array {
        $master = $product->masterData();
        $mediaWasEditedInErp = filled(data_get($master, 'media_updated_at'))
            || $variants->contains(
                fn (Product $variant): bool => filled(data_get($variant->masterData(), 'media_updated_at')),
            );

        if ($isVariation || trim($product->sku) === '') {
            return [];
        }

        if (! $force
            && ($this->dateTimeString(data_get($master, 'publication_date')) === null
                && ! $mediaWasEditedInErp
                && ! $product->isStorefrontHidden()
                && ! in_array($product->storefront_restore_visibility, ['visible', 'catalog', 'search'], true))
        ) {
            return [];
        }

        $payloads = collect($integration->productExportLanguages())
            ->mapWithKeys(function (mixed $language) use ($product, $integration, $mapping, $variants): array {
                $language = trim((string) $language) ?: 'pl';
                $payload = $this->payload($product, false, (int) $mapping->sales_channel_id, $language);
                $payload = $this->preserveRemoteSkuWhenDuplicated($payload, $product, $mapping);
                $payload = $this->prepareVariablePayload($product, $variants, $payload, $language);
                $payload = $this->globalizeProductAttributes($integration, $payload, $language);

                return [$language => $payload];
            })
            ->all();

        $results = $this->client->updateDiscoveredProductTranslations(
            $integration,
            $mapping,
            $product->sku,
            $payloads,
        );

        foreach ($results as $result) {
            $language = trim((string) ($result['language'] ?? ''));
            $externalProductId = trim((string) ($result['product_id'] ?? ''));

            if ($language === '' || $language === 'pl' || $externalProductId === '') {
                continue;
            }

            $this->saveTranslationReference(
                $product,
                (int) $mapping->sales_channel_id,
                $language,
                $externalProductId,
                null,
                $product->sku,
            );
        }

        return $results;
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

        // `low_stock_amount` is typed integer|null in the Woo REST schema:
        // null is its only clear-value ('' is rejected with
        // rest_invalid_param), so it must survive the null sweep — otherwise
        // a threshold cleared in ERP can never clear in WooCommerce.
        return array_filter(
            $payload,
            fn ($value, string $key): bool => $value !== null || $key === 'low_stock_amount',
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
