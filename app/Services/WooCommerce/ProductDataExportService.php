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
use App\Services\Products\ProductDescriptionSanitizer;
use App\Services\Products\ProductVariantInheritanceService;
use App\Services\Products\ProductVariantOptionNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ProductDataExportService
{
    /** @var Collection<int, ProductParameterDefinition>|null */
    private ?Collection $parameterDefinitions = null;

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly ProductDescriptionSanitizer $descriptionSanitizer,
        private readonly ProductVariantInheritanceService $variantInheritance,
        private readonly ProductVariantOptionNormalizer $variantOptions,
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
            $variantsPreparedBeforeTranslations = $creationInProgress
                || ($missingTranslations && $this->hasMissingPrimaryVariantMappings(
                    $variants,
                    (int) $mapping->sales_channel_id,
                ));
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
        }

        $translationResponses = $this->createTranslations(
            $product,
            $variants,
            $integration,
            (int) $integration->sales_channel_id,
            (string) $externalId,
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

        foreach ($this->exportLanguages($product, $integration) as $language) {
            $language = trim((string) $language);

            if ($language === '' || $language === 'pl') {
                continue;
            }

            $existingReference = $this->translationReferences($product, $salesChannelId)[$language] ?? null;
            $desiredSku = $product->sku;
            $translatedProductId = is_array($existingReference)
                ? trim((string) ($existingReference['product_id'] ?? ''))
                : '';

            if ($translatedProductId === '') {
                $translationCreation = $this->beginTranslationCreation(
                    $product,
                    $salesChannelId,
                    $language,
                );
                $payload = $this->payload($product, false, $salesChannelId, $language, null, null, true);
                $payload = $this->prepareVariablePayload($product, $variants, $payload, $language);
                $payload = $this->globalizeProductAttributes($integration, $payload, $language);
                $desiredSku = (string) ($payload['sku'] ?? $product->sku);
                unset($payload['sku']);

                $response = $this->client->createProductForLanguage(
                    $integration,
                    $payload,
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
                $this->completeTranslationCreation(
                    $product,
                    $salesChannelId,
                    $language,
                    $translatedProductId,
                );
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

            if ($desiredSku !== '') {
                $response = $this->client->updateProductDataByIds($integration, $translatedProductId, null, [
                    'sku' => $desiredSku,
                ], $language);
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

                $variantResponses = array_merge(
                    $variantResponses,
                    $this->syncVariantTranslations(
                        $product,
                        $variant,
                        $integration,
                        $salesChannelId,
                        $primaryMapping,
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
    private function translationReferences(Product $product, int $salesChannelId): array
    {
        $aliases = ProductChannelAlias::query()
            ->where('product_id', $product->id)
            ->where('sales_channel_id', $salesChannelId)
            ->whereNotNull('language')
            ->orderBy('id')
            ->get();

        if ($aliases->isNotEmpty()) {
            return $aliases
                ->mapWithKeys(fn (ProductChannelAlias $alias): array => [
                    (string) $alias->language => [
                        'product_id' => (string) $alias->external_product_id,
                        'variation_id' => $alias->external_variation_id !== null
                            ? (string) $alias->external_variation_id
                            : null,
                        'sku' => $alias->external_sku !== null ? (string) $alias->external_sku : null,
                    ],
                ])
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

            $payload = $this->variationPayload($product, $variant, $salesChannelId);
            $payload = $this->globalizeProductAttributes($integration, $payload, 'pl');
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
            $translationResults = $syncTranslations
                ? $this->syncVariantTranslations(
                    $product,
                    $variant,
                    $integration,
                    $salesChannelId,
                    $mapping,
                )
                : [];

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
     * @param  Collection<int, Product>  $variants
     */
    private function syncFamilyVariantTranslations(
        Product $parent,
        Collection $variants,
        WordpressIntegration $integration,
        int $salesChannelId,
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

            $this->syncVariantTranslations(
                $parent,
                $variant,
                $integration,
                $salesChannelId,
                $mapping,
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
    ): array {
        $results = [];
        $exportLanguages = collect($integration->productExportLanguages())->flip();

        foreach ($this->translationReferences($parent, $salesChannelId) as $language => $parentReference) {
            if (! is_array($parentReference) || ! $exportLanguages->has((string) $language)) {
                continue;
            }

            $translatedParentId = trim((string) ($parentReference['product_id'] ?? ''));

            if ($translatedParentId === '' || $translatedParentId === (string) $primaryMapping->external_product_id) {
                continue;
            }

            $language = trim((string) $language) ?: 'en';
            $payload = $this->variationPayload($parent, $variant, $salesChannelId, $language);
            $payload = $this->globalizeProductAttributes($integration, $payload, $language);
            $variantReference = $this->translationReferences($variant, $salesChannelId)[$language] ?? null;
            $translatedVariationId = is_array($variantReference)
                ? trim((string) ($variantReference['variation_id'] ?? ''))
                : '';

            if ($translatedVariationId !== '') {
                $response = $this->client->updateProductDataByIds(
                    $integration,
                    $translatedParentId,
                    $translatedVariationId,
                    $payload,
                    $language,
                );
                $operation = 'updated';
            } else {
                $discoveredVariation = $this->client->findProductVariation(
                    $integration,
                    $translatedParentId,
                    $primaryMapping->external_variation_id,
                    $variant->sku,
                    (array) ($payload['attributes'] ?? []),
                    $language,
                );
                $translatedVariationId = trim((string) ($discoveredVariation['id'] ?? ''));

                if ($translatedVariationId !== '') {
                    $response = $this->client->updateProductDataByIds(
                        $integration,
                        $translatedParentId,
                        $translatedVariationId,
                        $payload,
                        $language,
                    );
                    $operation = 'updated_discovered';
                } else {
                    $response = $this->client->createProductVariation(
                        $integration,
                        $translatedParentId,
                        $payload,
                        $language,
                    );
                    $translatedVariationId = trim((string) ($response['id'] ?? ''));
                    $operation = 'created';
                }

                if ($translatedVariationId === '') {
                    throw new RuntimeException("WooCommerce nie zwrócił ID wariantu {$variant->sku} dla tłumaczenia {$language}.");
                }

                $this->saveTranslationReference(
                    $variant,
                    $salesChannelId,
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
        $product->loadMissing(['stockBalances', 'channelMappings']);
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
            : (int) floor(max(0, (float) $product->stockBalances->sum('quantity_available')));

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

        if ($lowStockAmount !== null && $lowStockAmount !== '') {
            $payload['low_stock_amount'] = $lowStockAmount;
        }

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
            unset($payload['sold_individually'], $payload['date_created']);
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
    private function prepareVariablePayload(Product $product, Collection $variants, array $payload, string $language = 'pl'): array
    {
        if ($variants->isEmpty()) {
            return $payload;
        }

        $payload['type'] = 'variable';
        $payload['attributes'] = $this->variableAttributes($product, $variants, $language);
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
        $master = $this->variantInheritance->masterData($parent, $variant);
        $payload = $this->payload(
            $variant,
            true,
            $salesChannelId,
            $language,
            $this->mappingHasStockReleasePending($parent, $salesChannelId),
            $master,
        );
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

        $payload['attributes'] = $this->variationAttributes($parent, $variant, $language);
        $payload['menu_order'] = max(0, min(65535, (int) ($variant->pivot?->sort_order ?? 100)));
        $payload['status'] = $parent->is_active && $variant->is_active
            ? (string) (data_get($parentMaster, 'publication_status') ?: 'publish')
            : 'draft';

        return $payload;
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
     * @return list<array{source_name:string,source_options:list<string>,name:string,visible:bool,variation:bool,options:list<string>}>
     */
    private function attributes(array $master, string $language = 'pl'): array
    {
        return collect(data_get($master, 'parameters', []))
            ->filter(fn ($row): bool => is_array($row))
            ->map(function (array $row) use ($language): ?array {
                $name = $this->translatedParameterName($row, $language);
                $value = $this->translatedParameterValue($row, $language);

                if ($name === '' || $value === '') {
                    return null;
                }

                return [
                    'source_name' => trim((string) ($row['name'] ?? $name)),
                    'source_options' => [trim((string) ($row['value'] ?? $value))],
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
     * @return list<array{source_name:string,source_options:list<string>,name:string,visible:bool,variation:bool,options:list<string>}>
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
        $variantAttribute = $this->translatedParameterName($translationSource, $language);
        $variantOptionPairs = $variants
            ->map(function (Product $variant) use (
                $sourceVariantAttribute,
                $variantAttribute,
                $language,
            ): array {
                return [
                    'source' => $this->variationOption(
                        $variant,
                        $sourceVariantAttribute,
                        $sourceVariantAttribute,
                        'pl',
                    ),
                    'localized' => $this->variationOption(
                        $variant,
                        $sourceVariantAttribute,
                        $variantAttribute,
                        $language,
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

        $variantOptions = $variantOptionPairs->pluck('localized')->all();
        $sourceVariantOptions = $variantOptionPairs->pluck('source')->all();

        return collect($this->attributes($master, $language))
            // The ERP variant model intentionally supports one variant axis.
            // Imported/stale flags must not make Woo require an additional
            // attribute that none of the child variations supplies.
            ->map(fn (array $attribute): array => array_merge($attribute, ['variation' => false]))
            ->reject(fn (array $attribute): bool => in_array(
                mb_strtolower(trim((string) ($attribute['source_name'] ?? $attribute['name']))),
                [mb_strtolower($sourceVariantAttribute), mb_strtolower($variantAttribute)],
                true,
            ) || $this->isLegacyGenericVariantAttribute(
                (string) ($attribute['source_name'] ?? $attribute['name']),
                $sourceVariantAttribute,
            ))
            ->push([
                'source_name' => $sourceVariantAttribute,
                'source_options' => $sourceVariantOptions,
                'name' => $variantAttribute,
                'visible' => true,
                'variation' => true,
                'options' => $variantOptions,
            ])
            ->values()
            ->all();
    }

    private function isLegacyGenericVariantAttribute(string $attribute, string $selectedVariantAttribute): bool
    {
        $attribute = mb_strtolower(trim($attribute));
        $selectedVariantAttribute = mb_strtolower(trim($selectedVariantAttribute));

        return ! in_array($selectedVariantAttribute, ['wariant', 'variant'], true)
            && in_array($attribute, ['wariant', 'variant'], true);
    }

    /**
     * @return list<array{source_name:string,source_options:list<string>,name:string,option:string}>
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
        $variantAttribute = $this->translatedParameterName($translationSource, $language);

        return [[
            'source_name' => $sourceVariantAttribute,
            'source_options' => [$this->variationOption(
                $variant,
                $sourceVariantAttribute,
                $sourceVariantAttribute,
                'pl',
            )],
            'name' => $variantAttribute,
            'option' => $this->variationOption(
                $variant,
                $sourceVariantAttribute,
                $variantAttribute,
                $language,
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
            );
            $attributeId = (int) $global['id'];

            if ($isVariationPayload) {
                $resolved[] = [
                    'id' => $attributeId,
                    'option' => (string) ($global['options'][0] ?? $attribute['option'] ?? ''),
                ];

                continue;
            }

            $normalized = [
                'id' => $attributeId,
                'visible' => (bool) ($attribute['visible'] ?? true),
                'variation' => (bool) ($attribute['variation'] ?? false),
                'options' => array_values((array) $global['options']),
            ];

            // A legacy/imported ERP record may contain the same parameter more
            // than once. Woo requires one row per global taxonomy ID, so merge
            // its options while preserving their first-seen order.
            if (array_key_exists($attributeId, $parentAttributeIndexes)) {
                $index = $parentAttributeIndexes[$attributeId];
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

        if ($name !== '') {
            return $name;
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
            return $relationCandidate;
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
            return $commonVariantCandidate;
        }

        $parentCandidates = collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && (bool) ($parameter['variation'] ?? false))
            ->map(fn (array $parameter): string => trim((string) ($parameter['name'] ?? '')))
            ->filter();
        $parentCandidate = $this->singleAttributeCandidate($parentCandidates);

        if ($parentCandidate !== null) {
            return $parentCandidate;
        }

        return 'Rozmiar';
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

        foreach ((array) data_get($product->masterData(), 'parameters', []) as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            if (mb_strtolower(trim((string) ($parameter['name'] ?? ''))) === $normalizedName) {
                return $parameter;
            }
        }

        return null;
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

        $definitions = $this->parameterDefinitions ??= ProductParameterDefinition::query()->get();

        return $definitions->first(function (ProductParameterDefinition $definition) use ($name, $slug): bool {
            if ($slug !== '' && mb_strtolower(trim((string) $definition->slug)) === $slug) {
                return true;
            }

            return $name !== '' && mb_strtolower(trim((string) $definition->name)) === $name;
        });
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

    /**
     * @param  Collection<int, Product>  $variants
     */
    private function hasMissingPrimaryVariantMappings(Collection $variants, int $salesChannelId): bool
    {
        if ($variants->isEmpty()) {
            return false;
        }

        $mappedProductIds = ProductChannelMapping::query()
            ->where('sales_channel_id', $salesChannelId)
            ->whereIn('product_id', $variants->pluck('id'))
            ->whereNotNull('external_variation_id')
            ->pluck('product_id');

        return $mappedProductIds->unique()->count() !== $variants->pluck('id')->unique()->count();
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
    ): array {
        $shouldCreateOrResume = ! $isVariation && (
            data_get($mapping->metadata, 'creation_state') === 'creating'
            || data_get($mapping->metadata, 'product_translation_link.pending') === true
        );
        $results = ! $shouldCreateOrResume
            ? []
            : $this->createTranslations(
                $product,
                $variants,
                $integration,
                (int) $mapping->sales_channel_id,
                (string) $mapping->external_product_id,
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

        return array_filter($payload, fn ($value): bool => $value !== null);
    }
}
