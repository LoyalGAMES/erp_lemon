<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Jobs\RepairWooOwnedVariantAxisJob;
use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
use App\Models\WordpressIntegration;
use App\Services\Products\LegacySizeVariantAxisResolver;
use App\Services\Products\ProductVariantOptionNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Surgically replaces a historical Woo-owned `wariant`/`BLVariant` axis with
 * the already attached global Size taxonomy. The live remote family is the
 * source of truth and every configured language is preflighted before the
 * first PUT. No commercial or editorial product field is ever submitted.
 */
final class WooOwnedVariantAxisRepairService
{
    public const REVISION = 'woo_owned_size_variant_axis_2026_07_15_000017';

    public const STATE_PATH = 'maintenance.woo_owned_variant_axis_repair';

    /** @var list<string> */
    private const PROTECTED_PRODUCT_FIELDS = [
        'name',
        'description',
        'short_description',
        'sku',
        'global_unique_id',
        'status',
        'featured',
        'catalog_visibility',
        'date_created',
        'date_created_gmt',
        'date_on_sale_from',
        'date_on_sale_to',
        'price',
        'regular_price',
        'sale_price',
        'tax_status',
        'tax_class',
        'manage_stock',
        'stock_quantity',
        'stock_status',
        'backorders',
        'low_stock_amount',
        'sold_individually',
        'weight',
        'dimensions',
        'shipping_class',
        'shipping_class_id',
        'images',
        'image',
        'categories',
        'tags',
        'upsell_ids',
        'cross_sell_ids',
    ];

    /** @var Collection<int, ProductParameterDefinition>|null */
    private ?Collection $sizeDefinitions = null;

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly ProductVariantOptionNormalizer $variantOptions,
        private readonly LegacySizeVariantAxisResolver $legacySizeAxis,
    ) {}

    public function markPending(Product $product): int
    {
        return DB::transaction(function () use ($product): int {
            $mappings = $this->parentMappingsQuery($product->id)
                ->lockForUpdate()
                ->get();

            foreach ($mappings as $mapping) {
                $metadata = (array) $mapping->metadata;
                $state = (array) data_get($metadata, self::STATE_PATH, []);

                if (($state['revision'] ?? null) === self::REVISION
                    && in_array(($state['status'] ?? null), ['pending', 'queued', 'completed', 'manual_review'], true)
                ) {
                    continue;
                }

                data_set($metadata, self::STATE_PATH, [
                    'revision' => self::REVISION,
                    'status' => 'pending',
                    'requested_at' => now()->toISOString(),
                ]);
                $mapping->forceFill(['metadata' => $metadata])->save();
            }

            return $mappings->count();
        });
    }

    /**
     * @return array{scanned:int,dispatched:int,active:int,backoff:int,failed:int}
     */
    public function dispatchPending(int $limit = 10, int $staleMinutes = 120): array
    {
        $result = [
            'scanned' => 0,
            'dispatched' => 0,
            'active' => 0,
            'backoff' => 0,
            'failed' => 0,
        ];
        $seenProducts = [];

        $candidates = ProductChannelMapping::query()
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->where('metadata->'.str_replace('.', '->', self::STATE_PATH).'->revision', self::REVISION)
            ->whereIn('metadata->'.str_replace('.', '->', self::STATE_PATH).'->status', ['pending', 'queued'])
            ->lazyById(100);

        foreach ($candidates as $mapping) {
            if ($result['dispatched'] >= max(1, $limit)) {
                break;
            }

            if (isset($seenProducts[$mapping->product_id])) {
                continue;
            }

            $seenProducts[$mapping->product_id] = true;
            $result['scanned']++;
            $reservation = $this->reserve((int) $mapping->product_id, max(1, $staleMinutes));

            if ($reservation['status'] === 'active') {
                $result['active']++;

                continue;
            }

            if ($reservation['status'] === 'backoff') {
                $result['backoff']++;

                continue;
            }

            if ($reservation['status'] !== 'reserved') {
                continue;
            }

            try {
                RepairWooOwnedVariantAxisJob::dispatch(
                    $reservation['product_id'],
                    $reservation['token'],
                )->onConnection('database');
                $result['dispatched']++;
            } catch (Throwable $exception) {
                report($exception);
                $this->failReservation(
                    $reservation['product_id'],
                    $reservation['token'],
                    $exception,
                );
                $result['failed']++;
            }
        }

        return $result;
    }

    public function hasCurrentReservation(int $productId, string $token): bool
    {
        return $this->parentMappingsQuery($productId)
            ->get()
            ->contains(fn (ProductChannelMapping $mapping): bool => data_get(
                $mapping->metadata,
                self::STATE_PATH.'.pending_token',
            ) === $token);
    }

    /**
     * @return array{status:string,targets:int,mutations:int,reason?:string,languages?:list<string>}
     */
    public function repair(Product $product): array
    {
        $product->loadMissing([
            'channelMappings.salesChannel',
            'channelAliases',
            'variantChildren.channelMappings',
            'variantChildren.channelAliases',
            'parentRelations',
        ]);

        if (! $this->isWooOwnedRoot($product)) {
            return [
                'status' => 'manual_review',
                'targets' => 0,
                'mutations' => 0,
                'reason' => 'Produkt nie jest historycznym, głównym towarem WooCommerce.',
            ];
        }

        $targetResolution = $this->remoteTargets($product);
        $variationOptionHints = $this->localVariationOptionHints($product);

        if ($targetResolution['error'] !== null) {
            return [
                'status' => ($targetResolution['retryable'] ?? false) ? 'deferred' : 'manual_review',
                'targets' => count($targetResolution['targets']),
                'mutations' => 0,
                'reason' => $targetResolution['error'],
                'allow_full_export' => (bool) ($targetResolution['allow_full_export'] ?? false),
            ];
        }

        $plans = [];

        foreach ($targetResolution['targets'] as $target) {
            $parent = $this->client->productById(
                $target['integration'],
                $target['external_product_id'],
            );
            $variations = $this->client->productVariationsByParent(
                $target['integration'],
                $target['external_product_id'],
                $this->apiLanguage($target['language']),
            );
            $plan = $this->familyPlan(
                $parent,
                $variations,
                null,
                $variationOptionHints,
            );

            if ($plan['status'] === 'unsafe') {
                return [
                    'status' => 'manual_review',
                    'targets' => count($targetResolution['targets']),
                    'mutations' => 0,
                    'reason' => sprintf(
                        'WooCommerce %s #%s: %s',
                        mb_strtoupper($target['language']),
                        $target['external_product_id'],
                        $plan['reason'],
                    ),
                    'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                ];
            }

            $plans[] = [
                'target' => $target,
                'plan' => $plan,
                'parent' => $parent,
                'variations' => $variations,
                'protected' => $this->protectedSnapshot($parent, $variations),
            ];
        }

        $identity = $this->remoteIdentity($product, $plans);

        if ($identity['error'] !== null) {
            return [
                'status' => 'manual_review',
                'targets' => count($plans),
                'mutations' => 0,
                'reason' => $identity['error'],
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
            ];
        }

        $optionSignatures = collect($plans)
            ->map(fn (array $entry): string => implode('|', $entry['plan']['option_keys']))
            ->unique()
            ->values();

        if ($optionSignatures->count() !== 1) {
            return [
                'status' => 'manual_review',
                'targets' => count($plans),
                'mutations' => 0,
                'reason' => 'Polska i angielska rodzina WooCommerce mają różne zbiory rozmiarów.',
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
            ];
        }

        $skuOptionSignatures = collect($plans)->map(function (array $entry): string {
            $skuOptions = (array) $entry['plan']['sku_option_keys'];
            ksort($skuOptions);

            return (string) json_encode($skuOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        })->unique()->values();

        if (! $identity['contract'] && $skuOptionSignatures->count() !== 1) {
            return [
                'status' => 'manual_review',
                'targets' => count($plans),
                'mutations' => 0,
                'reason' => 'Polska i angielska rodzina przypisują te same SKU do różnych rozmiarów.',
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
            ];
        }

        // A legacy custom-text Size must be attached to the one taxonomy and
        // terms that already exist in WooCommerce. Resolve the full PL/EN
        // family read-only first; no taxonomy or term is ever created here.
        foreach ($plans as $index => $entry) {
            if ($entry['plan']['status'] !== 'requires_global') {
                continue;
            }

            try {
                $globalSize = $this->resolveExistingGlobalSize(
                    $entry['target'],
                    $entry['plan'],
                );
            } catch (Throwable $exception) {
                return [
                    'status' => 'manual_review',
                    'targets' => count($plans),
                    'mutations' => 0,
                    'reason' => sprintf(
                        'WooCommerce %s #%s: %s',
                        mb_strtoupper($entry['target']['language']),
                        $entry['target']['external_product_id'],
                        $exception->getMessage(),
                    ),
                    'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                ];
            }

            $resolvedPlan = $this->familyPlan(
                $entry['parent'],
                $entry['variations'],
                $globalSize,
                $variationOptionHints,
            );

            if ($resolvedPlan['status'] === 'unsafe' || $resolvedPlan['status'] === 'requires_global') {
                return [
                    'status' => 'manual_review',
                    'targets' => count($plans),
                    'mutations' => 0,
                    'reason' => sprintf(
                        'WooCommerce %s #%s: %s',
                        mb_strtoupper($entry['target']['language']),
                        $entry['target']['external_product_id'],
                        $resolvedPlan['reason'],
                    ),
                    'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                ];
            }

            $plans[$index]['plan'] = $resolvedPlan;
        }

        foreach (collect($plans)->groupBy(fn (array $entry): int => (int) $entry['target']['integration']->id) as $integrationPlans) {
            if ($integrationPlans->pluck('plan.size_id')->map(fn (mixed $id): int => (int) $id)->unique()->count() !== 1) {
                return [
                    'status' => 'manual_review',
                    'targets' => count($plans),
                    'mutations' => 0,
                    'reason' => 'Wersje językowe wskazują różne globalne taksonomie rozmiaru.',
                    'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                ];
            }
        }

        $mutations = 0;

        // Finish and verify one language at a time. If any write/read fails,
        // restore that exact language family to its preflight axis snapshot so
        // no parent can remain on `wariant` while only some children use Size.
        // Languages completed before a later failure stay internally coherent;
        // the idempotent retry will skip them and continue with the remainder.
        foreach ($plans as $index => $entry) {
            $target = $entry['target'];
            $hasMutations = $entry['plan']['variation_payloads'] !== []
                || $entry['plan']['parent_payload'] !== null;

            try {
                foreach ($entry['plan']['variation_payloads'] as $variationId => $payload) {
                    $this->client->updateProductVariantAxisByIds(
                        $target['integration'],
                        $target['external_product_id'],
                        (string) $variationId,
                        $payload,
                        $this->apiLanguage($target['language']),
                    );
                    $mutations++;
                }

                $payload = $entry['plan']['parent_payload'];

                if ($payload !== null) {
                    $this->client->updateProductVariantAxisByIds(
                        $target['integration'],
                        $target['external_product_id'],
                        null,
                        $payload,
                        null,
                    );
                    $mutations++;
                }

                $parent = $this->client->productById(
                    $target['integration'],
                    $target['external_product_id'],
                );
                $variations = $this->client->productVariationsByParent(
                    $target['integration'],
                    $target['external_product_id'],
                    $this->apiLanguage($target['language']),
                );
                $verified = $this->familyPlan($parent, $variations);

                if ($verified['status'] !== 'canonical'
                    || $verified['parent_payload'] !== null
                    || $verified['variation_payloads'] !== []
                ) {
                    throw new RuntimeException(
                        "WooCommerce nie potwierdził kanonicznej osi rozmiaru produktu #{$target['external_product_id']} po naprawie.",
                    );
                }

                if (! hash_equals(
                    $entry['protected'],
                    $this->protectedSnapshot($parent, $variations),
                )) {
                    throw new RuntimeException(
                        "Naprawa osi produktu #{$target['external_product_id']} zmieniła chronione dane handlowe lub treści.",
                    );
                }

                $plans[$index]['verified_parent'] = $parent;
                $plans[$index]['verified_variations'] = $variations;
            } catch (Throwable $failure) {
                if ($hasMutations) {
                    try {
                        $this->rollbackLanguageAxis($entry);
                    } catch (Throwable $rollbackFailure) {
                        throw new RuntimeException(
                            sprintf(
                                'Naprawa osi produktu #%s nie powiodła się, a WooCommerce nie potwierdził pełnego rollbacku: %s; pierwotny błąd: %s',
                                $target['external_product_id'],
                                $rollbackFailure->getMessage(),
                                $failure->getMessage(),
                            ),
                            previous: $rollbackFailure,
                        );
                    }
                }

                throw $failure;
            }
        }

        $this->synchronizeLocalAxisSnapshot($product, $plans);

        return [
            'status' => $mutations > 0 ? 'repaired' : 'already_canonical',
            'targets' => count($plans),
            'mutations' => $mutations,
            'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
        ];
    }

    /** @param array<string,mixed> $entry */
    private function rollbackLanguageAxis(array $entry): void
    {
        $target = $entry['target'];
        $expected = $this->axisSnapshot($entry['parent'], $entry['variations']);
        $rollbackErrors = [];

        foreach ($entry['variations'] as $variation) {
            try {
                $this->client->updateProductVariantAxisByIds(
                    $target['integration'],
                    $target['external_product_id'],
                    (string) $variation['id'],
                    [
                        'attributes' => $this->serializeRollbackVariationAttributes(
                            array_values((array) ($variation['attributes'] ?? [])),
                        ),
                        'menu_order' => (int) ($variation['menu_order'] ?? 0),
                    ],
                    $this->apiLanguage($target['language']),
                );
            } catch (Throwable $exception) {
                // Continue restoring the remaining children and parent. A
                // timeout can report failure even when Woo applied the PUT;
                // the final exact snapshot check is authoritative.
                $rollbackErrors[] = 'wariant #'.($variation['id'] ?? '?').': '.$exception->getMessage();
            }
        }

        try {
            $this->client->updateProductVariantAxisByIds(
                $target['integration'],
                $target['external_product_id'],
                null,
                [
                    'attributes' => collect((array) ($entry['parent']['attributes'] ?? []))
                        ->filter(fn (mixed $attribute): bool => is_array($attribute))
                        ->map(fn (array $attribute): array => $this->serializeParentAttribute($attribute))
                        ->values()
                        ->all(),
                    'default_attributes' => collect((array) ($entry['parent']['default_attributes'] ?? []))
                        ->filter(fn (mixed $attribute): bool => is_array($attribute))
                        ->map(fn (array $attribute): array => $this->serializeRollbackDefaultAttribute($attribute))
                        ->values()
                        ->all(),
                ],
                null,
            );
        } catch (Throwable $exception) {
            $rollbackErrors[] = 'rodzic: '.$exception->getMessage();
        }

        $parent = $this->client->productById(
            $target['integration'],
            $target['external_product_id'],
        );
        $variations = $this->client->productVariationsByParent(
            $target['integration'],
            $target['external_product_id'],
            $this->apiLanguage($target['language']),
        );

        if (! hash_equals($expected, $this->axisSnapshot($parent, $variations))) {
            throw new RuntimeException(
                "WooCommerce nie przywrócił dokładnego snapshotu osi produktu #{$target['external_product_id']}"
                    .($rollbackErrors === [] ? '.' : ': '.implode(' | ', $rollbackErrors)),
            );
        }
    }

    /**
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     */
    private function axisSnapshot(array $parent, array $variations): string
    {
        $snapshot = [
            'parent_attributes' => collect((array) ($parent['attributes'] ?? []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->map(fn (array $attribute): array => $this->serializeParentAttribute($attribute))
                ->values()
                ->all(),
            'parent_defaults' => collect((array) ($parent['default_attributes'] ?? []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->map(fn (array $attribute): array => $this->serializeRollbackDefaultAttribute($attribute))
                ->values()
                ->all(),
            'variations' => collect($variations)
                ->mapWithKeys(fn (array $variation): array => [
                    (string) ($variation['id'] ?? '') => [
                        'attributes' => $this->serializeRollbackVariationAttributes(
                            array_values((array) ($variation['attributes'] ?? [])),
                        ),
                        'menu_order' => (int) ($variation['menu_order'] ?? 0),
                    ],
                ])
                ->sortKeys()
                ->all(),
        ];

        return hash('sha256', (string) json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @param array<string,mixed> $attribute */
    private function serializeRollbackDefaultAttribute(array $attribute): array
    {
        $serialized = $this->serializeDefaultAttribute($attribute);
        $name = $this->attributeName($attribute);

        if ($name !== '') {
            $serialized = [
                ...array_key_exists('id', $serialized) ? ['id' => $serialized['id']] : [],
                'name' => $name,
                'option' => $serialized['option'],
            ];
        }

        return $serialized;
    }

    /**
     * @param  list<array<string,mixed>>  $attributes
     * @return list<array<string,mixed>>
     */
    private function serializeRollbackVariationAttributes(array $attributes): array
    {
        return collect($attributes)
            ->map(fn (array $attribute): array => $this->serializeRollbackDefaultAttribute($attribute))
            ->values()
            ->all();
    }

    /**
     * Replace only the local variant-axis snapshot with the already verified
     * primary Woo payload. This is intentionally synchronous: a broad product
     * import merges numeric arrays and can leave a removed legacy attribute
     * behind, allowing the next export to recreate the defect.
     *
     * @param  list<array<string,mixed>>  $plans
     */
    private function synchronizeLocalAxisSnapshot(Product $product, array $plans): void
    {
        $primary = collect($plans)->first(
            fn (array $entry): bool => (bool) data_get($entry, 'target.is_primary', false),
        ) ?? collect($plans)->first();

        if (! is_array($primary)
            || ! is_array($primary['verified_parent'] ?? null)
            || ! is_array($primary['verified_variations'] ?? null)
        ) {
            throw new RuntimeException('Brak zweryfikowanego głównego snapshotu WooCommerce.');
        }

        $verifiedParent = $primary['verified_parent'];
        $verifiedVariations = $primary['verified_variations'];
        $sizeAttributes = collect((array) ($verifiedParent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (int) ($attribute['id'] ?? 0) > 0
                && $this->isSizeAttribute($attribute)
                && (bool) ($attribute['variation'] ?? false))
            ->values();

        if ($sizeAttributes->count() !== 1) {
            throw new RuntimeException('Zweryfikowany rodzic nie ma jednej globalnej osi Rozmiar/Size.');
        }

        $size = $sizeAttributes->first();
        $sizeName = $this->attributeName($size) ?: 'Rozmiar';
        $orderedOptions = collect((array) ($size['options'] ?? []))
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->values()
            ->all();
        $remoteBySku = collect($verifiedVariations)
            ->mapWithKeys(fn (array $variation): array => [
                mb_strtoupper(trim((string) ($variation['sku'] ?? ''))) => $variation,
            ])
            ->filter(fn (array $variation, string $sku): bool => $sku !== '');

        DB::transaction(function () use (
            $product,
            $verifiedParent,
            $sizeName,
            $orderedOptions,
            $remoteBySku,
        ): void {
            $root = Product::query()->lockForUpdate()->find($product->id);

            if (! $root instanceof Product) {
                throw new RuntimeException('Rodzina ERP zniknęła podczas zapisu naprawy.');
            }

            $relations = ProductRelation::query()
                ->where('parent_product_id', $root->id)
                ->where('relation_type', 'variant')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $children = Product::query()
                ->whereIn('id', $relations->pluck('child_product_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($children->count() !== $relations->count()) {
                throw new RuntimeException('Rodzina ERP zmieniła skład podczas zapisu naprawy.');
            }

            $parentAttributes = (array) $root->attributes;
            $parentMaster = $root->masterData();
            $parentMaster['variant_attribute'] = $sizeName;
            $parentMaster['parameters'] = $this->canonicalParentParameters(
                $parentMaster,
                $sizeName,
                $orderedOptions,
            );
            data_set($parentMaster, self::STATE_PATH, [
                'revision' => self::REVISION,
                'variant_attribute' => $sizeName,
                'synchronized_at' => now()->toISOString(),
            ]);
            $parentAttributes['master'] = $parentMaster;
            $parentAttributes['woocommerce_attributes'] = array_values((array) ($verifiedParent['attributes'] ?? []));
            $parentAttributes['woocommerce_default_attributes'] = array_values((array) ($verifiedParent['default_attributes'] ?? []));
            $root->forceFill(['attributes' => $parentAttributes])->save();

            $localVariationPayloads = [];

            foreach ($relations as $relation) {
                $variant = $children->get($relation->child_product_id);
                $sku = mb_strtoupper(trim((string) $variant?->sku));
                $remote = $remoteBySku->get($sku);

                if (! $variant instanceof Product || ! is_array($remote)) {
                    throw new RuntimeException("Nie znaleziono zweryfikowanego wariantu Woo dla SKU {$sku}.");
                }

                $rows = array_values((array) ($remote['attributes'] ?? []));
                $option = trim((string) data_get($rows, '0.option', ''));
                $variantAttributes = (array) $variant->attributes;
                $variantMaster = $variant->masterData();
                $variantMaster['variant_attribute'] = $sizeName;
                $variantMaster['parameters'] = $this->canonicalVariantParameters(
                    $variantMaster,
                    $sizeName,
                    $option,
                );
                data_set($variantMaster, self::STATE_PATH, [
                    'revision' => self::REVISION,
                    'parent_product_id' => $root->id,
                    'variant_attribute' => $sizeName,
                    'variant_option' => $option,
                    'synchronized_at' => now()->toISOString(),
                ]);
                $variantAttributes['master'] = $variantMaster;
                $variantAttributes['woocommerce_variation_attributes'] = $rows;
                $variantAttributes['woocommerce_attributes'] = $rows;
                $variant->forceFill(['attributes' => $variantAttributes])->save();

                $relationMetadata = (array) $relation->metadata;
                $relationMetadata['variant_attribute'] = $sizeName;
                $relationMetadata['variant_option'] = $option;
                data_set($relationMetadata, self::STATE_PATH, [
                    'revision' => self::REVISION,
                    'synchronized_at' => now()->toISOString(),
                ]);
                $relation->forceFill([
                    'sort_order' => (int) ($remote['menu_order'] ?? 0),
                    'metadata' => $relationMetadata,
                ])->save();

                $localVariationPayloads[] = [
                    'id' => (string) ($remote['id'] ?? $variant->id),
                    'sku' => $variant->sku,
                    'attributes' => $rows,
                    'menu_order' => (int) ($remote['menu_order'] ?? 0),
                ];
            }

            $localPlan = $this->familyPlan([
                'id' => $verifiedParent['id'] ?? $root->id,
                'type' => 'variable',
                'attributes' => $parentAttributes['woocommerce_attributes'],
                'default_attributes' => $parentAttributes['woocommerce_default_attributes'],
            ], $localVariationPayloads);

            if ($localPlan['status'] !== 'canonical'
                || $localPlan['parent_payload'] !== null
                || $localPlan['variation_payloads'] !== []
            ) {
                throw new RuntimeException('Lokalny snapshot ERP nie potwierdził kanonicznej osi rozmiaru.');
            }
        }, 3);
    }

    /** @return list<mixed> */
    private function canonicalParentParameters(array $master, string $sizeName, array $options): array
    {
        $parameters = collect((array) data_get($master, 'parameters', []));
        $targets = $parameters->filter(fn (mixed $parameter): bool => is_array($parameter)
            && ($this->legacySizeAxis->isLegacyGeneric((string) ($parameter['name'] ?? ''))
                || $this->variantOptions->isSizeAttribute((string) ($parameter['name'] ?? ''))));
        $template = (array) ($targets->first(fn (array $parameter): bool => $this->variantOptions->isSizeAttribute(
            (string) ($parameter['name'] ?? ''),
        )) ?? $targets->first() ?? []);
        $value = implode(' | ', $options);

        $template['name'] = $sizeName;
        $template['name_en'] = 'Size';
        $template['value'] = $value;
        $template['variation'] = true;
        $this->synchronizeLocalizedParameter($template, $value, $sizeName);

        return $parameters
            ->reject(fn (mixed $parameter): bool => is_array($parameter)
                && ($this->legacySizeAxis->isLegacyGeneric((string) ($parameter['name'] ?? ''))
                    || $this->variantOptions->isSizeAttribute((string) ($parameter['name'] ?? ''))))
            ->values()
            ->push($template)
            ->all();
    }

    /** @return list<mixed> */
    private function canonicalVariantParameters(array $master, string $sizeName, string $option): array
    {
        $parameters = collect((array) data_get($master, 'parameters', []));
        $targets = $parameters->filter(fn (mixed $parameter): bool => is_array($parameter)
            && ($this->legacySizeAxis->isLegacyGeneric((string) ($parameter['name'] ?? ''))
                || $this->variantOptions->isSizeAttribute((string) ($parameter['name'] ?? ''))));
        $template = (array) ($targets->first(fn (array $parameter): bool => $this->variantOptions->isSizeAttribute(
            (string) ($parameter['name'] ?? ''),
        )) ?? $targets->first() ?? []);

        $template['name'] = $sizeName;
        $template['name_en'] = 'Size';
        $template['value'] = $option;
        $template['variation'] = true;
        $this->synchronizeLocalizedParameter($template, $option, $sizeName);

        return $parameters
            ->reject(fn (mixed $parameter): bool => is_array($parameter)
                && ($this->legacySizeAxis->isLegacyGeneric((string) ($parameter['name'] ?? ''))
                    || $this->variantOptions->isSizeAttribute((string) ($parameter['name'] ?? ''))))
            ->values()
            ->push($template)
            ->all();
    }

    /** @param array<string,mixed> $parameter */
    private function synchronizeLocalizedParameter(array &$parameter, string $value, string $sizeName): void
    {
        foreach (['value_pl', 'value_en'] as $key) {
            if (array_key_exists($key, $parameter)) {
                $parameter[$key] = $value;
            }
        }

        foreach ((array) ($parameter['translations'] ?? []) as $language => $translation) {
            if (! is_array($translation)) {
                continue;
            }

            if (array_key_exists('value', $translation)) {
                data_set($parameter, "translations.{$language}.value", $value);
            }

            if (array_key_exists('name', $translation)) {
                data_set(
                    $parameter,
                    "translations.{$language}.name",
                    $this->language($language) === 'en' ? 'Size' : $sizeName,
                );
            }
        }
    }

    public function isWooOwnedVariantRootCandidate(Product $product): bool
    {
        $product->loadMissing(['variantChildren', 'parentRelations']);

        return $this->isWooOwnedRoot($product)
            && $product->variantChildren->isNotEmpty()
            && $this->parentMappingsQuery((int) $product->id)->exists()
            && $this->hasLocalSizeAxisEvidence($product);
    }

    /**
     * Queue only families that locally identify Size (or its known legacy
     * generic alias). A valid Color-only Woo family must never be converted
     * into permanent manual_review merely because it has variants.
     */
    private function hasLocalSizeAxisEvidence(Product $product): bool
    {
        $product->loadMissing('variantChildren');
        $names = collect([
            data_get($product->masterData(), 'variant_attribute'),
            ...collect((array) data_get($product->masterData(), 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter))
                ->flatMap(fn (array $parameter): array => [
                    $parameter['name'] ?? null,
                    $parameter['name_en'] ?? null,
                    $parameter['slug'] ?? null,
                ])
                ->all(),
            ...collect((array) data_get($product->attributes, 'woocommerce_attributes', []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->flatMap(fn (array $attribute): array => [
                    $attribute['name'] ?? null,
                    $attribute['slug'] ?? null,
                ])
                ->all(),
        ]);

        foreach ($product->variantChildren as $variant) {
            $names->push(data_get($variant->masterData(), 'variant_attribute'));
            $names->push(...collect((array) data_get($variant->masterData(), 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter))
                ->flatMap(fn (array $parameter): array => [
                    $parameter['name'] ?? null,
                    $parameter['name_en'] ?? null,
                    $parameter['slug'] ?? null,
                ])
                ->all());
            $names->push(...collect((array) data_get(
                $variant->attributes,
                'woocommerce_variation_attributes',
                data_get($variant->attributes, 'woocommerce_attributes', []),
            ))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->flatMap(fn (array $attribute): array => [
                    $attribute['name'] ?? null,
                    $attribute['slug'] ?? null,
                ])
                ->all());
        }

        $hasConcreteSize = $names
            ->filter(fn (mixed $name): bool => is_scalar($name) && trim((string) $name) !== '')
            ->contains(fn (mixed $name): bool => $this->variantOptions->isSizeAttribute(
                (string) $name,
            ));

        if ($hasConcreteSize) {
            return true;
        }

        // A generic `wariant` name alone is not proof of Size: old catalogs
        // also used it for Color. Accept it only when the existing resolver
        // proves one concrete Size axis from the parent/children options.
        return $this->legacySizeAxis->recover(
            $product,
            $product->variantChildren,
        ) !== null;
    }

    /**
     * A small part of the historical catalog has variation rows whose remote
     * option was erased while the variation ID/SKU, stock and local imported
     * family stayed intact. Build a fail-closed SKU => Size hint from that
     * local family. It is used only when the live row has no option; a live
     * non-empty value must still agree with the hint.
     *
     * @return array<string, string> Canonical option keys keyed by uppercase SKU.
     */
    private function localVariationOptionHints(Product $product): array
    {
        $product->loadMissing('variantChildren');
        $children = $product->variantChildren;

        if ($children->isEmpty()) {
            return [];
        }

        $declared = trim((string) data_get($product->masterData(), 'variant_attribute', ''));
        $sizeAttribute = $this->variantOptions->isSizeAttribute($declared)
            ? $declared
            : $this->legacySizeAxis->recover($product, $children);

        if ($sizeAttribute === null || ! $this->variantOptions->isSizeAttribute($sizeAttribute)) {
            return [];
        }

        $parentOptions = collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && collect([
                    $parameter['name'] ?? null,
                    $parameter['name_en'] ?? null,
                    $parameter['slug'] ?? null,
                ])->filter()->contains(fn (mixed $name): bool => $this->attributeKey((string) $name)
                    === $this->attributeKey($sizeAttribute)))
            ->flatMap(fn (array $parameter): Collection => $this->localOptionValues(
                $parameter['value'] ?? null,
            ));

        if ($parentOptions->isEmpty()) {
            $parentOptions = collect((array) data_get($product->attributes, 'woocommerce_attributes', []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute)
                    && $this->isSizeAttribute($attribute))
                ->flatMap(fn (array $attribute): Collection => collect((array) ($attribute['options'] ?? [])));
        }

        $orderedOptions = $this->orderedSizeOptions($sizeAttribute, $parentOptions->all());
        $canonicalByKey = collect($orderedOptions)
            ->mapWithKeys(fn (string $option): array => [$this->optionKey($option) => $option]);

        if ($canonicalByKey->isEmpty()) {
            return [];
        }

        $hints = [];
        $usedOptionKeys = [];

        foreach ($children as $child) {
            $sku = mb_strtoupper(trim((string) $child->sku));

            if ($sku === '' || isset($hints[$sku])) {
                return [];
            }

            $rawOptions = collect((array) data_get($child->masterData(), 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter)
                    && collect([
                        $parameter['name'] ?? null,
                        $parameter['name_en'] ?? null,
                        $parameter['slug'] ?? null,
                    ])->filter()->contains(fn (mixed $name): bool => $this->legacySizeAxis->isLegacyGeneric(
                        (string) $name,
                    ) || $this->attributeKey((string) $name) === $this->attributeKey($sizeAttribute)))
                ->flatMap(fn (array $parameter): Collection => $this->localOptionValues(
                    $parameter['value'] ?? null,
                ));

            $relationOption = trim((string) data_get($child->pivot?->metadata, 'variant_option', ''));

            if ($relationOption !== '') {
                $rawOptions->push($relationOption);
            }

            collect((array) data_get(
                $child->attributes,
                'woocommerce_variation_attributes',
                data_get($child->attributes, 'woocommerce_attributes', []),
            ))
                ->filter(fn (mixed $attribute): bool => is_array($attribute)
                    && ($this->isGenericAttribute($attribute) || $this->isSizeAttribute($attribute)))
                ->each(function (array $attribute) use ($rawOptions): void {
                    $option = trim((string) ($attribute['option'] ?? ''));

                    if ($option !== '') {
                        $rawOptions->push($option);
                    }
                });

            $keys = $rawOptions
                ->map(fn (mixed $option): string => $this->optionKey((string) $option))
                ->filter()
                ->unique()
                ->values();

            if ($keys->count() !== 1 || ! $canonicalByKey->has($keys->first())) {
                return [];
            }

            $key = (string) $keys->first();

            if (isset($usedOptionKeys[$key])) {
                return [];
            }

            $usedOptionKeys[$key] = true;
            $hints[$sku] = $key;
        }

        $expected = $canonicalByKey->keys()->sort()->values()->all();
        $actual = collect(array_values($hints))->sort()->values()->all();

        return $expected === $actual ? $hints : [];
    }

    /** @return Collection<int, string> */
    private function localOptionValues(mixed $value): Collection
    {
        if (is_array($value)) {
            return collect($value)
                ->flatMap(fn (mixed $option): Collection => $this->localOptionValues($option))
                ->values();
        }

        if (! is_scalar($value)) {
            return collect();
        }

        return collect(preg_split('/\s*\|\s*/u', trim((string) $value)) ?: [])
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->values();
    }

    /**
     * The migration uses only the imported raw snapshot to narrow the queue.
     * The live remote preflight in repair() remains authoritative.
     */
    public function isLocalCandidate(Product $product): bool
    {
        $product->loadMissing(['variantChildren.channelMappings', 'parentRelations']);

        if (! $this->isWooOwnedRoot($product) || $product->variantChildren->isEmpty()) {
            return false;
        }

        $parentAttributes = (array) data_get($product->attributes, 'woocommerce_attributes', []);
        $variations = $product->variantChildren
            ->map(function (Product $variant): array {
                $mapping = $variant->channelMappings
                    ->first(fn (ProductChannelMapping $candidate): bool => filled(
                        $candidate->external_variation_id,
                    ));

                return [
                    'id' => $mapping?->external_variation_id ?? $variant->id,
                    'attributes' => (array) data_get(
                        $variant->attributes,
                        'woocommerce_variation_attributes',
                        data_get($variant->attributes, 'woocommerce_attributes', []),
                    ),
                    'menu_order' => (int) ($variant->pivot?->sort_order ?? 0),
                ];
            })
            ->values()
            ->all();

        if ($parentAttributes === [] || $variations === []) {
            return false;
        }

        return in_array($this->familyPlan([
            'id' => data_get($product->attributes, 'woocommerce_product_id'),
            'type' => 'variable',
            'attributes' => $parentAttributes,
            'default_attributes' => (array) data_get($product->attributes, 'woocommerce_default_attributes', []),
        ], $variations)['status'], ['repair', 'requires_global'], true);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function completeReservation(int $productId, string $token, array $result): void
    {
        DB::transaction(function () use ($productId, $token, $result): void {
            $this->parentMappingsQuery($productId)
                ->lockForUpdate()
                ->get()
                ->each(function (ProductChannelMapping $mapping) use ($token, $result): void {
                    $metadata = (array) $mapping->metadata;

                    if (data_get($metadata, self::STATE_PATH.'.pending_token') !== $token) {
                        return;
                    }

                    $resultStatus = (string) ($result['status'] ?? 'manual_review');
                    $status = match ($resultStatus) {
                        'repaired', 'already_canonical' => 'completed',
                        'deferred' => 'pending',
                        default => 'manual_review',
                    };
                    data_forget($metadata, self::STATE_PATH.'.pending_token');
                    data_forget($metadata, self::STATE_PATH.'.queued_at');
                    data_forget($metadata, self::STATE_PATH.'.next_attempt_at');
                    data_forget($metadata, self::STATE_PATH.'.failed_at');
                    data_forget($metadata, self::STATE_PATH.'.error');
                    data_set($metadata, self::STATE_PATH.'.status', $status);
                    data_set($metadata, self::STATE_PATH.'.result', $result);

                    if ($status === 'pending') {
                        data_set($metadata, self::STATE_PATH.'.next_attempt_at', now()->addMinutes(5)->toISOString());
                    } else {
                        data_set($metadata, self::STATE_PATH.'.completed_at', now()->toISOString());
                    }

                    $mapping->forceFill(['metadata' => $metadata])->save();
                });
        });
    }

    /** Hold every broad export until the remote and local axes are canonical. */
    public function blocksFullExport(Product $product): bool
    {
        $rootId = $this->familyRootId((int) $product->id);

        return $this->parentMappingsQuery($rootId)
            ->get()
            ->contains(function (ProductChannelMapping $mapping): bool {
                $state = (array) data_get($mapping->metadata, self::STATE_PATH, []);
                $status = (string) ($state['status'] ?? '');

                if (($state['revision'] ?? null) !== self::REVISION
                    || ! in_array($status, ['pending', 'queued', 'manual_review'], true)
                ) {
                    return false;
                }

                // A missing configured translation must be created by the full
                // exporter before this all-language repair can continue. The
                // shared family lock still serializes that export with repair.
                return ! ($status !== 'manual_review'
                    && data_get($state, 'result.allow_full_export') === true);
            });
    }

    public function failReservation(int $productId, string $token, Throwable $exception): void
    {
        DB::transaction(function () use ($productId, $token, $exception): void {
            $this->parentMappingsQuery($productId)
                ->lockForUpdate()
                ->get()
                ->each(function (ProductChannelMapping $mapping) use ($token, $exception): void {
                    $metadata = (array) $mapping->metadata;

                    if (data_get($metadata, self::STATE_PATH.'.pending_token') !== $token) {
                        return;
                    }

                    data_forget($metadata, self::STATE_PATH.'.pending_token');
                    data_set($metadata, self::STATE_PATH.'.status', 'pending');
                    data_set($metadata, self::STATE_PATH.'.failed_at', now()->toISOString());
                    data_set($metadata, self::STATE_PATH.'.next_attempt_at', now()->addMinutes(15)->toISOString());
                    data_set($metadata, self::STATE_PATH.'.error', $exception->getMessage());
                    $mapping->forceFill(['metadata' => $metadata])->save();
                });
        });
    }

    /**
     * @return array{status:string,product_id?:int,token?:string}
     */
    private function reserve(int $productId, int $staleMinutes): array
    {
        return DB::transaction(function () use ($productId, $staleMinutes): array {
            $mappings = $this->parentMappingsQuery($productId)
                ->lockForUpdate()
                ->get();
            $pending = $mappings->first(fn (ProductChannelMapping $mapping): bool => data_get($mapping->metadata, self::STATE_PATH.'.revision') === self::REVISION
                && in_array(data_get($mapping->metadata, self::STATE_PATH.'.status'), ['pending', 'queued'], true));

            if (! $pending instanceof ProductChannelMapping) {
                return ['status' => 'missing'];
            }

            $nextAttemptAt = $this->date(data_get(
                $pending->metadata,
                self::STATE_PATH.'.next_attempt_at',
            ));

            if ($nextAttemptAt instanceof CarbonImmutable && $nextAttemptAt->isFuture()) {
                return ['status' => 'backoff'];
            }

            $pendingToken = trim((string) data_get(
                $pending->metadata,
                self::STATE_PATH.'.pending_token',
                '',
            ));
            $queuedAt = $this->date(data_get($pending->metadata, self::STATE_PATH.'.queued_at'));

            if ($pendingToken !== ''
                && (! $queuedAt instanceof CarbonImmutable
                    || $queuedAt->gt(now()->subMinutes($staleMinutes)))
            ) {
                return ['status' => 'active'];
            }

            $token = (string) Str::uuid();

            foreach ($mappings as $mapping) {
                $metadata = (array) $mapping->metadata;

                if (data_get($metadata, self::STATE_PATH.'.revision') !== self::REVISION
                    || ! in_array(data_get($metadata, self::STATE_PATH.'.status'), ['pending', 'queued'], true)
                ) {
                    continue;
                }

                data_set($metadata, self::STATE_PATH.'.status', 'queued');
                data_set($metadata, self::STATE_PATH.'.pending_token', $token);
                data_set($metadata, self::STATE_PATH.'.queued_at', now()->toISOString());
                data_set(
                    $metadata,
                    self::STATE_PATH.'.attempts',
                    max(0, (int) data_get($metadata, self::STATE_PATH.'.attempts', 0)) + 1,
                );
                data_forget($metadata, self::STATE_PATH.'.next_attempt_at');
                $mapping->forceFill(['metadata' => $metadata])->save();
            }

            return [
                'status' => 'reserved',
                'product_id' => $productId,
                'token' => $token,
            ];
        });
    }

    private function parentMappingsQuery(int $productId)
    {
        return ProductChannelMapping::query()
            ->where('product_id', $productId)
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            });
    }

    public function familyRootId(int $productId): int
    {
        $rootId = ProductRelation::query()
            ->where('child_product_id', $productId)
            ->where('relation_type', 'variant')
            ->orderBy('id')
            ->value('parent_product_id');

        return is_numeric($rootId) && (int) $rootId > 0
            ? (int) $rootId
            : $productId;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function isWooOwnedRoot(Product $product): bool
    {
        return ! $product->is_translation
            && in_array($product->masterSource(), ['woocommerce', 'woocommerce_import'], true)
            && data_get($product->masterData(), 'product_type') !== 'variation'
            && ! $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            );
    }

    /**
     * @return array{
     *   targets:list<array{integration:WordpressIntegration,sales_channel_id:int,external_product_id:string,language:string,is_primary:bool}>,
     *   error:?string,
     *   retryable:bool,
     *   allow_full_export:bool
     * }
     */
    private function remoteTargets(Product $product): array
    {
        $targets = collect();

        foreach ($this->parentMappingsQuery((int) $product->id)->get() as $mapping) {

            $externalProductId = trim((string) $mapping->external_product_id);

            if (! ctype_digit($externalProductId) || (int) $externalProductId <= 0) {
                continue;
            }

            $integrations = WordpressIntegration::query()
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->orderBy('id')
                ->get();

            if ($integrations->count() !== 1) {
                return [
                    'targets' => $targets->all(),
                    'error' => $integrations->isEmpty()
                        ? 'Brak jednoznacznej integracji WooCommerce dla kanału produktu.'
                        : 'Kanał produktu ma kilka integracji WooCommerce; naprawa nie wybierze sklepu arbitralnie.',
                    'retryable' => $integrations->isEmpty(),
                    'allow_full_export' => false,
                ];
            }

            /** @var WordpressIntegration $integration */
            $integration = $integrations->first();

            $primaryLanguage = $this->language(
                data_get($mapping->metadata, 'language', 'pl'),
            );
            $targets->push([
                'integration' => $integration,
                'sales_channel_id' => (int) $mapping->sales_channel_id,
                'external_product_id' => $externalProductId,
                'language' => $primaryLanguage,
                'is_primary' => true,
            ]);

            ProductChannelAlias::query()
                ->where('product_id', $product->id)
                ->where('sales_channel_id', $mapping->sales_channel_id)
                ->where(function ($query): void {
                    $query
                        ->whereNull('external_variation_id')
                        ->orWhereIn('external_variation_id', ['', '0'])
                        ->orWhereRaw("TRIM(external_variation_id) = ''");
                })
                ->orderBy('id')
                ->get()
                ->each(function (ProductChannelAlias $alias) use ($integration, $mapping, $targets): void {
                    $externalProductId = trim((string) $alias->external_product_id);

                    if (! ctype_digit($externalProductId) || (int) $externalProductId <= 0) {
                        return;
                    }

                    $targets->push([
                        'integration' => $integration,
                        'sales_channel_id' => (int) $mapping->sales_channel_id,
                        'external_product_id' => $externalProductId,
                        'language' => $this->language($alias->language ?? 'en'),
                        'is_primary' => false,
                    ]);
                });
        }

        $targets = $targets
            ->unique(fn (array $target): string => $target['integration']->id.'|'.$target['external_product_id'])
            ->sortBy(fn (array $target): string => ($target['is_primary'] ? '0' : '1').'|'.$target['language'])
            ->values();

        if ($targets->isEmpty()) {
            return [
                'targets' => [],
                'error' => 'Brak głównego mapowania produktu WooCommerce.',
                'retryable' => true,
                'allow_full_export' => false,
            ];
        }

        $duplicateLanguageTarget = $targets
            ->groupBy(fn (array $target): string => $target['integration']->id.'|'.$target['language'])
            ->first(fn (Collection $languageTargets): bool => $languageTargets->count() > 1);

        if ($duplicateLanguageTarget instanceof Collection) {
            return [
                'targets' => $targets->all(),
                'error' => 'Jedna wersja językowa ma kilka różnych mapowań produktu WooCommerce.',
                'retryable' => false,
                'allow_full_export' => false,
            ];
        }

        foreach ($targets->groupBy('integration.id') as $integrationTargets) {
            /** @var WordpressIntegration $integration */
            $integration = $integrationTargets->first()['integration'];
            $requiredLanguages = collect([
                ...$integration->productImportLanguages(),
                ...$integration->productExportLanguages(),
            ])
                ->map(fn (mixed $language): string => $this->language($language))
                ->unique()
                ->values();
            $availableLanguages = $integrationTargets
                ->pluck('language')
                ->map(fn (mixed $language): string => $this->language($language))
                ->unique();
            $missing = $requiredLanguages->diff($availableLanguages)->values();

            if ($missing->isNotEmpty()) {
                return [
                    'targets' => $targets->all(),
                    'error' => 'Brak mapowania istniejącej wersji '.mb_strtoupper($missing->implode(', '))
                        .' — rodzina nie zostanie naprawiona częściowo.',
                    'retryable' => true,
                    'allow_full_export' => true,
                ];
            }
        }

        return [
            'targets' => $targets->all(),
            'error' => null,
            'retryable' => false,
            'allow_full_export' => false,
        ];
    }

    /**
     * Validate the authoritative Lemon/Polylang translation family whenever
     * the plugin contract is present. SKU identity is used only for a truly
     * legacy catalog where none of the returned objects declares that
     * contract. A partial contract is unsafe and never falls back to SKU.
     *
     * @param  list<array<string,mixed>>  $plans
     * @return array{contract:bool,error:?string}
     */
    private function remoteIdentity(Product $product, array $plans): array
    {
        $objects = collect($plans)->flatMap(fn (array $entry): array => [
            $entry['parent'],
            ...$entry['variations'],
        ]);
        $contract = $objects->contains(fn (array $item): bool => collect([
            'lemon_erp_catalog_contract',
            'lemon_erp_language',
            'lemon_erp_translations',
            'lemon_erp_translation_group',
            'lemon_erp_parent_translations',
            'lemon_erp_parent_translation_group',
        ])->contains(fn (string $key): bool => array_key_exists($key, $item)));

        if (! $contract) {
            foreach ($plans as $entry) {
                $error = $this->legacyRemoteFamilyIdentityError(
                    $product,
                    $entry['parent'],
                    $entry['variations'],
                );

                if ($error !== null) {
                    return [
                        'contract' => false,
                        'error' => sprintf(
                            'WooCommerce %s #%s: %s',
                            mb_strtoupper($entry['target']['language']),
                            $entry['target']['external_product_id'],
                            $error,
                        ),
                    ];
                }
            }

            return ['contract' => false, 'error' => null];
        }

        foreach (collect($plans)->groupBy(fn (array $entry): int => (int) $entry['target']['integration']->id) as $integrationPlans) {
            $languages = $integrationPlans
                ->pluck('target.language')
                ->map(fn (mixed $language): string => $this->language($language))
                ->sort()
                ->values()
                ->all();
            $expectedParents = $integrationPlans
                ->mapWithKeys(fn (array $entry): array => [
                    $this->language($entry['target']['language']) => (int) ($entry['parent']['id'] ?? 0),
                ])
                ->sortKeys()
                ->all();
            $parentGroup = null;

            foreach ($integrationPlans as $entry) {
                $language = $this->language($entry['target']['language']);
                $error = $this->contractItemError(
                    $entry['parent'],
                    $language,
                    'product',
                    $expectedParents,
                );

                if ($error !== null) {
                    return ['contract' => true, 'error' => 'WooCommerce '.mb_strtoupper($language).": {$error}"];
                }

                $group = trim((string) $entry['parent']['lemon_erp_translation_group']);

                if ($parentGroup !== null && $group !== $parentGroup) {
                    return ['contract' => true, 'error' => 'Rodzice PL/EN nie należą do tej samej grupy tłumaczeń.'];
                }

                $parentGroup = $group;
            }

            $primary = $integrationPlans->first(fn (array $entry): bool => (bool) $entry['target']['is_primary']);

            if (! is_array($primary)
                || mb_strtoupper(trim((string) ($primary['parent']['sku'] ?? '')))
                    !== mb_strtoupper(trim((string) $product->sku))
            ) {
                return ['contract' => true, 'error' => 'SKU głównego rodzica nie zgadza się z rodziną ERP.'];
            }

            $localSkus = $product->variantChildren
                ->pluck('sku')
                ->map(fn (mixed $sku): string => mb_strtoupper(trim((string) $sku)))
                ->filter()
                ->sort()
                ->values()
                ->all();
            $primarySkus = collect($primary['variations'])
                ->pluck('sku')
                ->map(fn (mixed $sku): string => mb_strtoupper(trim((string) $sku)))
                ->filter()
                ->sort()
                ->values()
                ->all();

            if ($localSkus === [] || $primarySkus !== $localSkus) {
                return ['contract' => true, 'error' => 'Polskie warianty nie odpowiadają dokładnie wariantom rodziny ERP.'];
            }

            $variationRecords = collect();
            $hasParentGroupField = $integrationPlans->contains(fn (array $entry): bool => collect($entry['variations'])
                ->contains(fn (array $variation): bool => array_key_exists('lemon_erp_parent_translation_group', $variation)));

            foreach ($integrationPlans as $entry) {
                $language = $this->language($entry['target']['language']);

                foreach ($entry['variations'] as $variation) {
                    foreach (['lemon_erp_language', 'lemon_erp_translations', 'lemon_erp_translation_group'] as $key) {
                        if (! array_key_exists($key, $variation)) {
                            return ['contract' => true, 'error' => 'Wariant #'.($variation['id'] ?? '?').' ma niepełny kontrakt tłumaczeń.'];
                        }
                    }

                    if ($this->language($variation['lemon_erp_language']) !== $language) {
                        return ['contract' => true, 'error' => 'Wariant #'.($variation['id'] ?? '?').' ma nieprawidłowy język tłumaczenia.'];
                    }

                    $map = $this->translationIdMap($variation['lemon_erp_translations']);
                    $id = (int) ($variation['id'] ?? 0);
                    $group = trim((string) $variation['lemon_erp_translation_group']);

                    if ($id <= 0 || ($map[$language] ?? 0) !== $id || $group === '') {
                        return ['contract' => true, 'error' => "Wariant #{$id} ma niespójną mapę tłumaczeń."];
                    }

                    if ($group !== $this->translationGroup('variation', $map)) {
                        return ['contract' => true, 'error' => "Wariant #{$id} ma nieprawidłowy identyfikator grupy tłumaczeń."];
                    }

                    if ($hasParentGroupField
                        && trim((string) ($variation['lemon_erp_parent_translation_group'] ?? '')) !== $parentGroup
                    ) {
                        return ['contract' => true, 'error' => "Wariant #{$id} wskazuje inną grupę rodziców."];
                    }

                    $variationRecords->push([
                        'language' => $language,
                        'id' => $id,
                        'group' => $group,
                        'map' => $map,
                        'option_key' => (string) data_get($entry, "plan.variation_option_keys.{$id}", ''),
                    ]);
                }
            }

            $groups = $variationRecords->groupBy('group');

            if ($groups->count() !== count($primary['variations'])) {
                return ['contract' => true, 'error' => 'Grupy tłumaczeń wariantów nie tworzą bijekcji 1:1.'];
            }

            foreach ($groups as $records) {
                $recordLanguages = $records->pluck('language')->sort()->values()->all();
                $expectedMap = $records->mapWithKeys(fn (array $record): array => [
                    $record['language'] => $record['id'],
                ])->sortKeys()->all();

                if ($recordLanguages !== $languages
                    || $records->contains(fn (array $record): bool => $record['map'] !== $expectedMap)
                    || $records->pluck('option_key')->filter()->unique()->count() !== 1
                    || $records->pluck('option_key')->contains('')
                ) {
                    return ['contract' => true, 'error' => 'Tłumaczenia wariantów nie przypisują jednoznacznie tych samych rozmiarów.'];
                }
            }
        }

        return ['contract' => true, 'error' => null];
    }

    /** @param array<string,mixed> $item @param array<string,int> $expectedMap */
    private function contractItemError(
        array $item,
        string $language,
        string $kind,
        array $expectedMap,
    ): ?string {
        foreach (['lemon_erp_language', 'lemon_erp_translations', 'lemon_erp_translation_group'] as $key) {
            if (! array_key_exists($key, $item)) {
                return 'Rodzic ma niepełny kontrakt tłumaczeń Lemon ERP.';
            }
        }

        if ($this->language($item['lemon_erp_language']) !== $language) {
            return 'Język rodzica nie zgadza się z mapowaniem ERP.';
        }

        $map = $this->translationIdMap($item['lemon_erp_translations']);
        ksort($expectedMap);

        if ($map !== $expectedMap || ($map[$language] ?? 0) !== (int) ($item['id'] ?? 0)) {
            return 'Mapa tłumaczeń rodzica nie zgadza się z mapowaniami ERP.';
        }

        if (trim((string) $item['lemon_erp_translation_group']) !== $this->translationGroup($kind, $map)) {
            return 'Grupa tłumaczeń rodzica jest niespójna.';
        }

        return null;
    }

    /** @return array<string,int> */
    private function translationIdMap(mixed $translations): array
    {
        $map = collect(is_array($translations) ? $translations : [])
            ->mapWithKeys(fn (mixed $id, mixed $language): array => [
                $this->language($language) => (int) $id,
            ])
            ->filter(fn (int $id): bool => $id > 0)
            ->sortKeys()
            ->all();

        return $map;
    }

    /** @param array<string,int> $map */
    private function translationGroup(string $kind, array $map): string
    {
        $ids = collect($map)->values()->unique()->sort(SORT_NUMERIC)->values()->implode('|');

        return "{$kind}:{$ids}";
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  list<array<string, mixed>>  $variations
     */
    private function legacyRemoteFamilyIdentityError(
        Product $product,
        array $parent,
        array $variations,
    ): ?string {
        $skuKey = static fn (mixed $sku): string => mb_strtoupper(trim((string) $sku));
        $localParentSku = $skuKey($product->sku);
        $remoteParentSku = $skuKey($parent['sku'] ?? '');

        if ($localParentSku === '' || $remoteParentSku !== $localParentSku) {
            return 'Identyfikator SKU rodzica nie zgadza się z mapowaniem ERP.';
        }

        $localVariationSkus = $product->variantChildren
            ->map(fn (Product $variant): string => $skuKey($variant->sku))
            ->filter()
            ->sort()
            ->values()
            ->all();
        $remoteVariationSkus = collect($variations)
            ->map(fn (array $variation): string => $skuKey($variation['sku'] ?? ''))
            ->filter()
            ->sort()
            ->values()
            ->all();

        if ($localVariationSkus === [] || $remoteVariationSkus !== $localVariationSkus) {
            return 'Zestaw SKU wariantów nie zgadza się z rodziną ERP.';
        }

        return null;
    }

    /**
     * Resolve only an existing global Size taxonomy and existing terms. This
     * is deliberately read-only: an ambiguous/missing taxonomy goes to manual
     * review instead of creating another WooCommerce attribute.
     *
     * @param  array{integration:WordpressIntegration,language:string}  $target
     * @param  array{ordered_options:list<string>}  $plan
     * @return array{id:int,name:string,slug:string,options:list<string>}
     */
    private function resolveExistingGlobalSize(array $target, array $plan): array
    {
        /** @var WordpressIntegration $integration */
        $integration = $target['integration'];
        $attributes = collect([
            $this->client->globalProductAttributeByName($integration, 'Rozmiar'),
            $this->client->globalProductAttributeByName($integration, 'Size'),
        ])
            ->filter(fn (mixed $attribute): bool => is_array($attribute) && (int) ($attribute['id'] ?? 0) > 0)
            ->unique(fn (array $attribute): int => (int) $attribute['id'])
            ->values();

        if ($attributes->count() !== 1) {
            throw new RuntimeException($attributes->isEmpty()
                ? 'Nie znaleziono istniejącego globalnego atrybutu Rozmiar/Size.'
                : 'Znaleziono kilka globalnych atrybutów Rozmiar/Size.');
        }

        $attribute = $attributes->first();
        $attributeId = (int) $attribute['id'];
        $language = $this->language($target['language']);
        $terms = collect($this->client->globalProductAttributeTermsById(
            $integration,
            $attributeId,
            $language,
        ));

        if ($terms->isEmpty()) {
            $terms = collect($this->client->globalProductAttributeTermsById(
                $integration,
                $attributeId,
                null,
            ));
        }

        $resolvedOptions = collect($plan['ordered_options'])
            ->map(function (string $option) use ($terms): string {
                $key = $this->optionKey($option);
                $matches = $terms
                    ->filter(fn (mixed $term): bool => is_array($term) && (int) ($term['id'] ?? 0) > 0)
                    ->filter(fn (array $term): bool => $this->optionKey((string) ($term['name'] ?? '')) === $key
                        || $this->optionKey((string) ($term['slug'] ?? '')) === $key)
                    ->unique(fn (array $term): int => (int) $term['id'])
                    ->values();

                if ($matches->count() !== 1) {
                    throw new RuntimeException(
                        "Istniejąca globalna taksonomia nie zawiera jednoznacznej wartości {$option}.",
                    );
                }

                return trim((string) ($matches->first()['name'] ?? $option)) ?: $option;
            })
            ->values()
            ->all();

        return [
            'id' => $attributeId,
            'name' => trim((string) ($attribute['name'] ?? 'Rozmiar')) ?: 'Rozmiar',
            'slug' => trim((string) ($attribute['slug'] ?? 'pa_rozmiar')) ?: 'pa_rozmiar',
            'options' => $resolvedOptions,
        ];
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  list<array<string, mixed>>  $variations
     * @param  array<string, string>  $variationOptionHints  Canonical option keys keyed by unique SKU.
     * @return array{
     *   status:'canonical'|'repair'|'requires_global'|'unsafe',
     *   reason:string,
     *   option_keys:list<string>,
     *   ordered_options:list<string>,
     *   sku_option_keys:array<string,string>,
     *   variation_option_keys:array<string,string>,
     *   size_id:int,
     *   parent_payload:?array<string,mixed>,
     *   variation_payloads:array<string,array<string,mixed>>
     * }
     */
    private function familyPlan(
        array $parent,
        array $variations,
        ?array $resolvedGlobalSize = null,
        array $variationOptionHints = [],
    ): array {
        if (isset($parent['type']) && (string) $parent['type'] !== 'variable') {
            return $this->unsafePlan('Produkt nie jest produktem wariantowym.');
        }

        $attributes = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->values();
        $genericAttributes = $attributes
            ->filter(fn (array $attribute): bool => $this->isGenericAttribute($attribute))
            ->values();
        $sizeAttributes = $attributes
            ->filter(fn (array $attribute): bool => $this->isSizeAttribute($attribute))
            ->values();

        if ($genericAttributes->count() > 1) {
            return $this->unsafePlan('Rodzic zawiera kilka tekstowych osi wariantu.');
        }

        if ($sizeAttributes->count() !== 1) {
            return $this->unsafePlan('Rodzic nie zawiera dokładnie jednego atrybutu Rozmiar/Size.');
        }

        $generic = $genericAttributes->first();
        $sourceSize = $sizeAttributes->first();
        $sourceSizeId = (int) ($sourceSize['id'] ?? 0);
        $size = $sourceSize;

        if ($sourceSizeId <= 0 && is_array($resolvedGlobalSize)) {
            $resolvedId = (int) ($resolvedGlobalSize['id'] ?? 0);

            if ($resolvedId <= 0) {
                return $this->unsafePlan('Istniejący globalny atrybut Rozmiar/Size ma nieprawidłowe ID.');
            }

            $size['id'] = $resolvedId;
            $size['name'] = trim((string) ($resolvedGlobalSize['name'] ?? 'Rozmiar')) ?: 'Rozmiar';
            $size['slug'] = (string) ($resolvedGlobalSize['slug'] ?? 'pa_rozmiar');
        }

        $sizeId = (int) ($size['id'] ?? 0);

        $otherVariationAxis = $attributes->contains(function (array $attribute) use ($generic, $sourceSize): bool {
            if (! (bool) ($attribute['variation'] ?? false)) {
                return false;
            }

            return ! $this->sameAttribute($attribute, $sourceSize)
                && (! is_array($generic) || ! $this->sameAttribute($attribute, $generic));
        });

        if ($otherVariationAxis) {
            return $this->unsafePlan('Rodzina ma drugą oś wariantową poza rozmiarem.');
        }

        $sizeName = $this->attributeName($sourceSize) ?: 'Rozmiar';
        $orderedOptions = $this->orderedSizeOptions(
            $sizeName,
            (array) ($sourceSize['options'] ?? []),
        );
        $canonicalByKey = collect($orderedOptions)
            ->mapWithKeys(fn (string $option): array => [$this->optionKey($option) => $option]);

        if ($canonicalByKey->isEmpty()) {
            return $this->unsafePlan('Rozmiar/Size nie zawiera żadnej jednoznacznej wartości.');
        }

        if ($sourceSizeId <= 0 && is_array($resolvedGlobalSize)) {
            $resolvedByKey = collect((array) ($resolvedGlobalSize['options'] ?? []))
                ->map(fn (mixed $option): string => trim((string) $option))
                ->filter()
                ->mapWithKeys(fn (string $option): array => [$this->optionKey($option) => $option]);

            if ($resolvedByKey->count() !== $canonicalByKey->count()
                || $resolvedByKey->keys()->sort()->values()->all()
                    !== $canonicalByKey->keys()->sort()->values()->all()
            ) {
                return $this->unsafePlan('Istniejąca globalna taksonomia nie ma dokładnie tych samych wartości rozmiaru.');
            }

            $orderedOptions = collect($orderedOptions)
                ->map(fn (string $option): string => (string) $resolvedByKey->get($this->optionKey($option)))
                ->all();
            $canonicalByKey = collect($orderedOptions)
                ->mapWithKeys(fn (string $option): array => [$this->optionKey($option) => $option]);
        }

        if (is_array($generic)) {
            $genericKeys = collect((array) ($generic['options'] ?? []))
                ->map(fn (mixed $option): string => $this->optionKey((string) $option))
                ->filter()
                ->unique()
                ->sort()
                ->values();

            if ($genericKeys->all() !== $canonicalByKey->keys()->sort()->values()->all()) {
                return $this->unsafePlan('Tekstowy wariant i globalny rozmiar mają inne wartości.');
            }

            if (! (bool) ($generic['variation'] ?? false)
                && ! (bool) ($size['variation'] ?? false)
            ) {
                return $this->unsafePlan('Ani tekstowy wariant, ani globalny rozmiar nie jest osią wariantową.');
            }
        } elseif (! (bool) ($sourceSize['variation'] ?? false)) {
            // A partially repaired family may already have a canonical parent
            // while its children still point at the old generic axis. The
            // parent must nevertheless identify Size as its sole variation.
            return $this->unsafePlan('Rozmiar nie jest osią wariantową.');
        }

        if ($variations === []) {
            return $this->unsafePlan('Rodzina nie ma wariantów do jednoznacznego przypisania.');
        }

        $variationOptions = [];
        $skuOptionKeys = [];
        $variationOptionKeys = [];
        $currentVariationAttributes = [];

        foreach ($variations as $variation) {
            $variationId = trim((string) ($variation['id'] ?? ''));

            if ($variationId === '') {
                return $this->unsafePlan('WooCommerce zwrócił wariant bez identyfikatora.');
            }

            $rows = collect((array) ($variation['attributes'] ?? []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->values();
            $recognized = $rows->filter(fn (array $attribute): bool => $this->isTargetAxisAttribute(
                $attribute,
                $sourceSize,
                $resolvedGlobalSize,
            ));
            $sku = mb_strtoupper(trim((string) ($variation['sku'] ?? '')));
            $hintKey = $sku === ''
                ? ''
                : trim((string) ($variationOptionHints[$sku] ?? ''));

            if ($recognized->count() !== $rows->count()) {
                return $this->unsafePlan("Wariant #{$variationId} ma dodatkową albo brakującą oś.");
            }

            $keys = $recognized
                ->map(fn (array $attribute): string => $this->optionKey(
                    (string) ($attribute['option'] ?? ''),
                ))
                ->filter()
                ->unique()
                ->values();

            if ($keys->isEmpty() && $hintKey !== '') {
                $keys->push($hintKey);
            }

            if ($keys->count() !== 1
                || ! $canonicalByKey->has($keys->first())
                || ($hintKey !== '' && $hintKey !== $keys->first())
            ) {
                return $this->unsafePlan("Wariant #{$variationId} nie mapuje się 1:1 na globalny rozmiar.");
            }

            $optionKey = (string) $keys->first();
            $variationOptions[$variationId] = $canonicalByKey->get($optionKey);
            $variationOptionKeys[$variationId] = $optionKey;

            if ($sku !== '') {
                if (isset($skuOptionKeys[$sku]) && $skuOptionKeys[$sku] !== $optionKey) {
                    return $this->unsafePlan("SKU {$sku} występuje przy kilku rozmiarach.");
                }

                $skuOptionKeys[$sku] = $optionKey;
            }
            $currentVariationAttributes[$variationId] = $rows->all();
        }

        $variationKeys = collect($variationOptions)
            ->map(fn (string $option): string => $this->optionKey($option));

        if ($variationKeys->count() !== $canonicalByKey->count()
            || $variationKeys->unique()->count() !== $variationKeys->count()
            || $variationKeys->sort()->values()->all() !== $canonicalByKey->keys()->sort()->values()->all()
        ) {
            return $this->unsafePlan('Warianty nie pokrywają dokładnie i jednokrotnie wartości globalnego rozmiaru.');
        }

        $targetDefaultOptions = collect();
        $nonTargetDefaults = [];

        foreach ((array) ($parent['default_attributes'] ?? []) as $default) {
            if (! is_array($default)) {
                continue;
            }

            $isTarget = $this->isTargetAxisAttribute(
                $default,
                $sourceSize,
                $resolvedGlobalSize,
            );

            if (! $isTarget) {
                $nonTargetDefaults[] = $this->serializeDefaultAttribute($default);

                continue;
            }

            $key = $this->optionKey((string) ($default['option'] ?? ''));

            if ($key === '' || ! $canonicalByKey->has($key)) {
                return $this->unsafePlan('Domyślny wariant nie mapuje się jednoznacznie na rozmiar.');
            }

            $targetDefaultOptions->push((string) $canonicalByKey->get($key));
        }

        if ($targetDefaultOptions->map(fn (string $option): string => $this->optionKey($option))->unique()->count() > 1) {
            return $this->unsafePlan('Tekstowy wariant i Rozmiar mają sprzeczne wartości domyślne.');
        }

        if ($sourceSizeId <= 0 && $resolvedGlobalSize === null) {
            return [
                'status' => 'requires_global',
                'reason' => '',
                'option_keys' => $canonicalByKey->keys()->sort()->values()->all(),
                'ordered_options' => $orderedOptions,
                'sku_option_keys' => $skuOptionKeys,
                'variation_option_keys' => $variationOptionKeys,
                'size_id' => 0,
                'parent_payload' => null,
                'variation_payloads' => [],
            ];
        }

        if ($sizeId <= 0) {
            return $this->unsafePlan('Naprawa nie rozwiązała istniejącego globalnego atrybutu Rozmiar/Size.');
        }

        $finalAttributes = $attributes
            ->reject(fn (array $attribute): bool => is_array($generic)
                && $this->sameAttribute($attribute, $generic))
            ->map(function (array $attribute) use ($sourceSize, $size, $orderedOptions): array {
                $serialized = $this->serializeParentAttribute($attribute);

                if ($this->sameAttribute($attribute, $sourceSize)) {
                    $serialized = $this->serializeParentAttribute($size);
                    $serialized['variation'] = true;
                    $serialized['options'] = $orderedOptions;
                }

                return $serialized;
            })
            ->values()
            ->all();
        $finalDefaults = $nonTargetDefaults;

        if ($targetDefaultOptions->isNotEmpty()) {
            $finalDefaults[] = [
                'id' => $sizeId,
                'option' => $targetDefaultOptions->first(),
            ];
        }
        $currentSerialized = $attributes
            ->map(fn (array $attribute): array => $this->serializeParentAttribute($attribute))
            ->values()
            ->all();
        $currentDefaults = collect((array) ($parent['default_attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): array => $this->serializeDefaultAttribute($attribute))
            ->values()
            ->all();
        $parentPayload = ($currentSerialized === $finalAttributes && $currentDefaults === $finalDefaults)
            ? null
            : [
                'attributes' => $finalAttributes,
                'default_attributes' => $finalDefaults,
            ];
        $variationPayloads = [];

        foreach ($variations as $variation) {
            $variationId = (string) $variation['id'];
            $option = $variationOptions[$variationId];
            $finalVariationAttributes = [[
                'id' => $sizeId,
                'option' => $option,
            ]];
            $menuOrder = (array_search($option, $orderedOptions, true) + 1) * 10;

            if ($this->serializeVariationAttributes($currentVariationAttributes[$variationId])
                    !== $finalVariationAttributes
                || (int) ($variation['menu_order'] ?? 0) !== $menuOrder
            ) {
                $variationPayloads[$variationId] = [
                    'attributes' => $finalVariationAttributes,
                    'menu_order' => $menuOrder,
                ];
            }
        }

        return [
            'status' => $parentPayload === null && $variationPayloads === [] ? 'canonical' : 'repair',
            'reason' => '',
            'option_keys' => $canonicalByKey->keys()->sort()->values()->all(),
            'ordered_options' => $orderedOptions,
            'sku_option_keys' => $skuOptionKeys,
            'variation_option_keys' => $variationOptionKeys,
            'size_id' => $sizeId,
            'parent_payload' => $parentPayload,
            'variation_payloads' => $variationPayloads,
        ];
    }

    /**
     * @return array{status:'unsafe',reason:string,option_keys:list<string>,ordered_options:list<string>,sku_option_keys:array{},variation_option_keys:array{},size_id:int,parent_payload:null,variation_payloads:array{}}
     */
    private function unsafePlan(string $reason): array
    {
        return [
            'status' => 'unsafe',
            'reason' => $reason,
            'option_keys' => [],
            'ordered_options' => [],
            'sku_option_keys' => [],
            'variation_option_keys' => [],
            'size_id' => 0,
            'parent_payload' => null,
            'variation_payloads' => [],
        ];
    }

    /** @param array<string, mixed> $attribute */
    private function isGenericAttribute(array $attribute): bool
    {
        return collect([
            $attribute['name'] ?? null,
            $attribute['slug'] ?? null,
        ])->filter(fn (mixed $value): bool => is_scalar($value))
            ->contains(fn (mixed $value): bool => $this->legacySizeAxis->isLegacyGeneric(
                (string) $value,
            ));
    }

    /** @param array<string, mixed> $attribute */
    private function isSizeAttribute(array $attribute): bool
    {
        return collect([
            $attribute['name'] ?? null,
            $attribute['slug'] ?? null,
        ])->filter(fn (mixed $value): bool => is_scalar($value))
            ->contains(fn (mixed $value): bool => $this->variantOptions->isSizeAttribute(
                (string) $value,
            ));
    }

    /**
     * During the first read of a custom-text Size, an already repaired child
     * can expose only the positive global attribute ID. Accept that row only
     * provisionally; the second read with $resolvedGlobalSize then requires
     * the exact resolved ID before any remote write is made.
     *
     * @param  array<string, mixed>  $attribute
     * @param  array<string, mixed>  $sourceSize
     * @param  array<string, mixed>|null  $resolvedGlobalSize
     */
    private function isTargetAxisAttribute(
        array $attribute,
        array $sourceSize,
        ?array $resolvedGlobalSize,
    ): bool {
        if ($this->isGenericAttribute($attribute)) {
            return true;
        }

        $sourceId = (int) ($sourceSize['id'] ?? 0);
        $attributeId = (int) ($attribute['id'] ?? 0);

        // Once the custom-text axis has been resolved, a positive ID is
        // authoritative. Do not let a stale/different global taxonomy pass
        // merely because WooCommerce returned the same display name.
        if ($sourceId <= 0 && is_array($resolvedGlobalSize) && $attributeId > 0) {
            return $attributeId === (int) ($resolvedGlobalSize['id'] ?? 0);
        }

        if ($this->sameAttribute($attribute, $sourceSize)) {
            return true;
        }

        if ($sourceId > 0) {
            return false;
        }

        if (is_array($resolvedGlobalSize)) {
            return $attributeId > 0
                && $attributeId === (int) ($resolvedGlobalSize['id'] ?? 0);
        }

        return $attributeId > 0 || $this->isSizeAttribute($attribute);
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function sameAttribute(array $left, array $right): bool
    {
        $leftId = (int) ($left['id'] ?? 0);
        $rightId = (int) ($right['id'] ?? 0);

        if ($leftId > 0 && $rightId > 0) {
            return $leftId === $rightId;
        }

        $leftNames = collect([$left['name'] ?? null, $left['slug'] ?? null])
            ->map(fn (mixed $value): string => $this->attributeKey((string) $value))
            ->filter();
        $rightNames = collect([$right['name'] ?? null, $right['slug'] ?? null])
            ->map(fn (mixed $value): string => $this->attributeKey((string) $value))
            ->filter();

        return $leftNames->intersect($rightNames)->isNotEmpty();
    }

    private function attributeKey(string $value): string
    {
        $slug = Str::slug(trim($value));

        return str_starts_with($slug, 'pa-') ? substr($slug, 3) : $slug;
    }

    /** @param array<string, mixed> $attribute */
    private function attributeName(array $attribute): string
    {
        return trim((string) ($attribute['name'] ?? $attribute['slug'] ?? ''));
    }

    private function optionKey(string $value): string
    {
        $value = preg_replace(
            '/\s*(?:\/|-|–|—)\s*/u',
            '-',
            trim($value),
        ) ?? trim($value);

        return Str::slug($value);
    }

    /**
     * @param  list<mixed>  $options
     * @return list<string>
     */
    private function orderedSizeOptions(string $attribute, array $options): array
    {
        $dictionaryOrder = $this->sizeDictionaryOrder($attribute);

        return collect($options)
            ->map(fn (mixed $option): string => $this->canonicalSizeOption(
                $attribute,
                (string) $option,
            ))
            ->filter()
            ->unique(fn (string $option): string => $this->optionKey($option))
            ->values()
            ->map(function (string $option, int $index) use ($dictionaryOrder): array {
                $key = $this->optionKey($option);

                return [
                    'value' => $option,
                    'rank' => $dictionaryOrder[$key]
                        ?? $this->canonicalSizeRank($option),
                    'index' => $index,
                ];
            })
            ->sort(function (array $left, array $right): int {
                if ($left['rank'] === null && $right['rank'] === null) {
                    return $left['index'] <=> $right['index'];
                }

                if ($left['rank'] === null) {
                    return 1;
                }

                if ($right['rank'] === null) {
                    return -1;
                }

                return $left['rank'] <=> $right['rank']
                    ?: $left['index'] <=> $right['index'];
            })
            ->pluck('value')
            ->values()
            ->all();
    }

    private function canonicalSizeOption(string $attribute, string $option): string
    {
        $key = $this->optionKey($option);
        $matches = ($this->sizeDefinitions ??= ProductParameterDefinition::query()
            ->orderBy('id')
            ->get())
            ->flatMap(fn (ProductParameterDefinition $definition): Collection => collect([
                ...(array) $definition->values,
                ...(array) $definition->values_en,
            ]))
            ->map(fn (mixed $candidate): string => trim((string) $candidate))
            ->filter(fn (string $candidate): bool => $candidate !== ''
                && $this->optionKey($candidate) === $key)
            ->unique(fn (string $candidate): string => $this->optionKey($candidate))
            ->values();

        if ($matches->count() === 1) {
            return $this->variantOptions->normalize($attribute, $matches->first());
        }

        $candidate = trim($option);

        if (preg_match('/^(?:[2-9]xl|x{1,6}[sl]|[sml])(?:\s*-\s*(?:[2-9]xl|x{1,6}[sl]|[sml]))+$/iu', $candidate) === 1) {
            $candidate = (string) preg_replace('/\s*-\s*/u', '/', $candidate);
        }

        return $this->variantOptions->normalize($attribute, $candidate);
    }

    /** @return array<string, int> */
    private function sizeDictionaryOrder(string $attribute): array
    {
        $definitions = $this->sizeDefinitions ??= ProductParameterDefinition::query()
            ->orderBy('id')
            ->get();
        $definition = $definitions->first(function (ProductParameterDefinition $candidate) use ($attribute): bool {
            return collect([$candidate->name, $candidate->name_en, $candidate->slug])
                ->filter()
                ->contains(fn (mixed $name): bool => $this->attributeKey((string) $name) === $this->attributeKey($attribute));
        }) ?? $definitions->first(fn (ProductParameterDefinition $candidate): bool => collect([
            $candidate->name,
            $candidate->name_en,
            $candidate->slug,
        ])->filter()->contains(
            fn (mixed $name): bool => $this->variantOptions->isSizeAttribute((string) $name),
        ));

        if (! $definition instanceof ProductParameterDefinition) {
            return [];
        }

        return collect((array) $definition->values)
            ->map(fn (mixed $value): string => $this->optionKey((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->mapWithKeys(fn (string $key, int $index): array => [$key => ($index + 1) * 10])
            ->all();
    }

    private function canonicalSizeRank(string $value): ?int
    {
        $value = mb_strtoupper(trim((string) preg_replace('/\s+/u', '', $value)));
        $value = str_replace(['–', '—', '-'], '/', $value);
        $aliases = [
            'ONESIZE' => 0,
            'ONE/SIZE' => 0,
            'UNIWERSALNY' => 0,
            'XXXXS' => 100,
            'XXXS' => 200,
            'XXS' => 300,
            'XXS/XS' => 350,
            'XS' => 400,
            'XS/S' => 450,
            'S' => 500,
            'S/M' => 550,
            'M' => 600,
            'M/L' => 650,
            'L' => 700,
            'L/XL' => 750,
            'XL' => 800,
            'XL/XXL' => 850,
            'XXL' => 900,
            '2XL' => 900,
            'XXXL' => 1000,
            '3XL' => 1000,
            '4XL' => 1100,
            '5XL' => 1200,
            '6XL' => 1300,
        ];

        if (array_key_exists($value, $aliases)) {
            return $aliases[$value];
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)(?:\/(\d+(?:[.,]\d+)?))?$/', $value, $matches) === 1) {
            $from = (float) str_replace(',', '.', $matches[1]);
            $to = isset($matches[2]) ? (float) str_replace(',', '.', $matches[2]) : $from;

            return 10_000 + (int) round($from * 100) + (int) round($to);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @return array<string, mixed>
     */
    private function serializeParentAttribute(array $attribute): array
    {
        $serialized = [];
        $id = (int) ($attribute['id'] ?? 0);

        if ($id > 0) {
            $serialized['id'] = $id;
        } else {
            $serialized['name'] = $this->attributeName($attribute);
        }

        $serialized['position'] = max(0, (int) ($attribute['position'] ?? 0));
        $serialized['visible'] = (bool) ($attribute['visible'] ?? false);
        $serialized['variation'] = (bool) ($attribute['variation'] ?? false);
        $serialized['options'] = collect((array) ($attribute['options'] ?? []))
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->values()
            ->all();

        return $serialized;
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @return array<string, mixed>
     */
    private function serializeDefaultAttribute(array $attribute): array
    {
        $serialized = [];
        $id = (int) ($attribute['id'] ?? 0);

        if ($id > 0) {
            $serialized['id'] = $id;
        } else {
            $serialized['name'] = $this->attributeName($attribute);
        }

        $serialized['option'] = trim((string) ($attribute['option'] ?? ''));

        return $serialized;
    }

    /**
     * @param  list<array<string, mixed>>  $defaults
     * @param  array<string, mixed>|null  $generic
     * @param  array<string, mixed>  $size
     * @param  Collection<string, string>  $canonicalByKey
     * @return list<array<string, mixed>>
     */
    private function finalDefaultAttributes(
        array $defaults,
        ?array $generic,
        array $size,
        Collection $canonicalByKey,
    ): array {
        return collect($defaults)
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(function (array $attribute) use ($generic, $size, $canonicalByKey): array {
                if (is_array($generic) && $this->sameAttribute($attribute, $generic)) {
                    $key = $this->optionKey((string) ($attribute['option'] ?? ''));
                    $attribute = [
                        'id' => (int) ($size['id'] ?? 0),
                        'name' => $this->attributeName($size),
                        'option' => $canonicalByKey->get($key, ''),
                    ];
                } elseif ($this->sameAttribute($attribute, $size)) {
                    $key = $this->optionKey((string) ($attribute['option'] ?? ''));
                    $attribute['option'] = $canonicalByKey->get(
                        $key,
                        trim((string) ($attribute['option'] ?? '')),
                    );
                }

                return $this->serializeDefaultAttribute($attribute);
            })
            ->unique(fn (array $attribute): string => isset($attribute['id'])
                ? 'id:'.$attribute['id']
                : 'name:'.$this->attributeKey((string) ($attribute['name'] ?? '')))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     * @return list<array<string, mixed>>
     */
    private function serializeVariationAttributes(array $attributes): array
    {
        return collect($attributes)
            ->map(function (array $attribute): array {
                $serialized = [];
                $id = (int) ($attribute['id'] ?? 0);

                if ($id > 0) {
                    $serialized['id'] = $id;
                } else {
                    $serialized['name'] = $this->attributeName($attribute);
                }

                $serialized['option'] = trim((string) ($attribute['option'] ?? ''));

                return $serialized;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $parent
     * @param  list<array<string, mixed>>  $variations
     */
    private function protectedSnapshot(array $parent, array $variations): string
    {
        $select = fn (array $payload): array => collect(self::PROTECTED_PRODUCT_FIELDS)
            ->mapWithKeys(fn (string $field): array => [
                $field => array_key_exists($field, $payload) ? $payload[$field] : null,
            ])
            ->all();
        $targetAttributeIds = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && ($this->isGenericAttribute($attribute) || $this->isSizeAttribute($attribute)))
            ->map(fn (array $attribute): int => (int) ($attribute['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
        $isTargetAttribute = fn (array $attribute): bool => $this->isGenericAttribute($attribute)
            || $this->isSizeAttribute($attribute)
            || in_array((int) ($attribute['id'] ?? 0), $targetAttributeIds, true);
        $snapshot = [
            'parent' => $select($parent),
            'non_target_attributes' => collect((array) ($parent['attributes'] ?? []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->reject($isTargetAttribute)
                ->map(fn (array $attribute): array => collect([
                    'id', 'name', 'slug', 'position', 'visible', 'variation', 'options',
                ])->mapWithKeys(fn (string $field): array => [
                    $field => array_key_exists($field, $attribute) ? $attribute[$field] : null,
                ])->all())
                ->values()
                ->all(),
            'non_target_defaults' => collect((array) ($parent['default_attributes'] ?? []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->reject($isTargetAttribute)
                ->map(fn (array $attribute): array => collect([
                    'id', 'name', 'slug', 'option',
                ])->mapWithKeys(fn (string $field): array => [
                    $field => array_key_exists($field, $attribute) ? $attribute[$field] : null,
                ])->all())
                ->values()
                ->all(),
            'variations' => collect($variations)
                ->mapWithKeys(fn (array $variation): array => [
                    (string) ($variation['id'] ?? '') => $select($variation),
                ])
                ->sortKeys()
                ->all(),
        ];

        return hash('sha256', (string) json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function language(mixed $language): string
    {
        $language = mb_strtolower(trim((string) $language));

        return in_array($language, ['', 'default'], true) ? 'pl' : $language;
    }

    private function apiLanguage(string $language): ?string
    {
        $language = $this->language($language);

        return $language === 'pl' ? null : $language;
    }
}
