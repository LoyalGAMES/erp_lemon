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
use App\Services\Products\ProductVariantAxisNameResolver;
use App\Services\Products\ProductVariantOptionNormalizer;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Surgically replaces a historical `wariant`/`BLVariant`/plural Size axis
 * with the existing global Size taxonomy. Woo-imported and ERP-owned roots
 * use the same remote-first path: the live family is preflighted in every
 * configured language before the first PUT. No commercial or editorial
 * product field is ever submitted.
 */
final class WooOwnedVariantAxisRepairService
{
    public const REVISION = 'woo_erp_size_variant_axis_2026_07_18_000055';

    public const PREVIOUS_STALE_TRANSLATED_VARIATION_ALIAS_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000054';

    public const PREVIOUS_TRANSLATION_ID_DIAGNOSTIC_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000053';

    public const PREVIOUS_EXACT_TRANSLATION_HANDOFF_ALIAS_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000052';

    public const PREVIOUS_SYNCHRONOUS_TRANSLATION_VARIATION_REBUILD_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000051';

    public const PREVIOUS_PRIORITIZED_TRANSLATION_VARIATION_REBUILD_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000050';

    public const PREVIOUS_INTERRUPTED_TRANSLATION_VARIATION_REBUILD_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000049';

    public const PREVIOUS_POST_SIMPLE_TRANSLATION_EXPORT_VERIFICATION_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000048';

    public const PREVIOUS_SIMPLE_TRANSLATION_REBUILD_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000047';

    public const PREVIOUS_TRANSITION_ID_ONLY_OPTIONS_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000046';

    public const PREVIOUS_TRANSITION_STRUCTURE_DIAGNOSTIC_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000045';

    public const PREVIOUS_TRANSITION_CONFIRMATION_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000044';

    public const PREVIOUS_LANGUAGE_SUFFIX_AND_MAPPING_ID_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000043';

    public const PREVIOUS_NUMERIC_SIZE_KEY_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000042';

    public const PREVIOUS_VARIATION_COVERAGE_DIAGNOSTIC_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000041';

    public const PREVIOUS_PARENT_AXIS_IDENTITY_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000040';

    public const PREVIOUS_ACTIVE_CHILD_AXIS_REVISION = 'woo_erp_size_variant_axis_2026_07_18_000039';

    public const PREVIOUS_ERP_SIZE_CONFIGURATION_ORDER_REVISION = 'woo_erp_size_variant_axis_2026_07_17_000038';

    public const PREVIOUS_MULTIPLE_LEGACY_SIZE_AXES_REVISION = 'woo_erp_size_variant_axis_2026_07_17_000037';

    public const PREVIOUS_EXACT_CHILD_ASSIGNMENT_AUDIT_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000033';

    public const PREVIOUS_BLANK_CHILD_ASSIGNMENT_AUDIT_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000032';

    public const PREVIOUS_CHILD_ASSIGNMENT_AUDIT_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000031';

    public const PREVIOUS_EXACT_DEFAULT_REPAIR_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000030';

    public const PREVIOUS_EXACT_LEGACY_DEFAULT_SLUG_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000029';

    public const PREVIOUS_CANONICAL_SIZE_TAXONOMY_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000028';

    public const PREVIOUS_LEGACY_DEFAULT_TERM_LANGUAGE_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000027';

    public const PREVIOUS_DEFAULT_TERM_SLUG_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000026';

    public const PREVIOUS_COMPLEMENTARY_LANGUAGE_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000025';

    public const PREVIOUS_ERP_SYNCHRONIZED_REVISION = 'woo_erp_size_variant_axis_2026_07_16_000024';

    public const PREVIOUS_SYNCHRONIZED_REVISION = 'woo_owned_size_variant_axis_2026_07_15_000017';

    public const STATE_PATH = 'maintenance.woo_owned_variant_axis_repair';

    public const REPAIR_QUEUE = 'woocommerce-repair';

    public static function isSynchronizedRevision(mixed $revision): bool
    {
        return is_string($revision) && in_array($revision, [
            self::REVISION,
            self::PREVIOUS_STALE_TRANSLATED_VARIATION_ALIAS_REVISION,
            self::PREVIOUS_TRANSLATION_ID_DIAGNOSTIC_REVISION,
            self::PREVIOUS_EXACT_TRANSLATION_HANDOFF_ALIAS_REVISION,
            self::PREVIOUS_SYNCHRONOUS_TRANSLATION_VARIATION_REBUILD_REVISION,
            self::PREVIOUS_PRIORITIZED_TRANSLATION_VARIATION_REBUILD_REVISION,
            self::PREVIOUS_INTERRUPTED_TRANSLATION_VARIATION_REBUILD_REVISION,
            self::PREVIOUS_POST_SIMPLE_TRANSLATION_EXPORT_VERIFICATION_REVISION,
            self::PREVIOUS_SIMPLE_TRANSLATION_REBUILD_REVISION,
            self::PREVIOUS_TRANSITION_ID_ONLY_OPTIONS_REVISION,
            self::PREVIOUS_TRANSITION_STRUCTURE_DIAGNOSTIC_REVISION,
            self::PREVIOUS_TRANSITION_CONFIRMATION_REVISION,
            self::PREVIOUS_LANGUAGE_SUFFIX_AND_MAPPING_ID_REVISION,
            self::PREVIOUS_NUMERIC_SIZE_KEY_REVISION,
            self::PREVIOUS_VARIATION_COVERAGE_DIAGNOSTIC_REVISION,
            self::PREVIOUS_PARENT_AXIS_IDENTITY_REVISION,
            self::PREVIOUS_ACTIVE_CHILD_AXIS_REVISION,
            self::PREVIOUS_ERP_SIZE_CONFIGURATION_ORDER_REVISION,
            self::PREVIOUS_MULTIPLE_LEGACY_SIZE_AXES_REVISION,
            self::PREVIOUS_EXACT_CHILD_ASSIGNMENT_AUDIT_REVISION,
            self::PREVIOUS_BLANK_CHILD_ASSIGNMENT_AUDIT_REVISION,
            self::PREVIOUS_CHILD_ASSIGNMENT_AUDIT_REVISION,
            self::PREVIOUS_EXACT_DEFAULT_REPAIR_REVISION,
            self::PREVIOUS_EXACT_LEGACY_DEFAULT_SLUG_REVISION,
            self::PREVIOUS_CANONICAL_SIZE_TAXONOMY_REVISION,
            self::PREVIOUS_LEGACY_DEFAULT_TERM_LANGUAGE_REVISION,
            self::PREVIOUS_DEFAULT_TERM_SLUG_REVISION,
            self::PREVIOUS_COMPLEMENTARY_LANGUAGE_REVISION,
            self::PREVIOUS_ERP_SYNCHRONIZED_REVISION,
            self::PREVIOUS_SYNCHRONIZED_REVISION,
        ], true);
    }

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

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly ProductVariantOptionNormalizer $variantOptions,
        private readonly LegacySizeVariantAxisResolver $legacySizeAxis,
        private readonly WooCommerceSizeDictionaryOrder $sizeOrder,
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
                )
                    ->onConnection('database')
                    ->onQueue(self::REPAIR_QUEUE);
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
            ->contains(fn (ProductChannelMapping $mapping): bool => $this->ownsCurrentReservation(
                (array) $mapping->metadata,
                $token,
            ));
    }

    /**
     * Claim every current-revision mapping for one family while deployment
     * maintenance has stopped all queue workers. This deliberately supersedes
     * a queued token and retry backoff left by the previous release: that old
     * database job becomes a harmless no-op because it no longer owns the
     * reservation. Ordinary runtime dispatch must continue to use reserve().
     *
     * @return array{status:string,product_id?:int,token?:string}
     */
    public function reserveForIsolatedSynchronousRepair(int $productId): array
    {
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException(
                'An isolated synchronous variant-axis repair requires maintenance mode.',
            );
        }

        return DB::transaction(function () use ($productId): array {
            $mappings = $this->parentMappingsQuery($productId)
                ->lockForUpdate()
                ->get();
            $repairableMappings = $mappings->filter(
                fn (ProductChannelMapping $mapping): bool => data_get(
                    $mapping->metadata,
                    self::STATE_PATH.'.revision',
                ) === self::REVISION
                    && in_array(data_get(
                        $mapping->metadata,
                        self::STATE_PATH.'.status',
                    ), ['pending', 'queued'], true),
            );

            if ($repairableMappings->isEmpty()) {
                return ['status' => 'missing'];
            }

            $token = (string) Str::uuid();

            foreach ($repairableMappings as $mapping) {
                $metadata = (array) $mapping->metadata;
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

        if (! $this->isRepairableRoot($product)) {
            return [
                'status' => 'manual_review',
                'targets' => 0,
                'mutations' => 0,
                'reason' => 'Produkt nie jest obsługiwaną główną rodziną wariantową ERP/WooCommerce.',
            ];
        }

        if (! $this->hasLocalSizeAxisEvidence($product)) {
            return [
                'status' => 'manual_review',
                'targets' => 0,
                'mutations' => 0,
                'reason' => 'Lokalna rodzina nie dowodzi jednoznacznie osi rozmiaru; zdalna oś nie zostanie zmieniona.',
            ];
        }

        $variationOptionHints = $this->localVariationOptionHints($product);

        if ($variationOptionHints === []
            && $this->hasParentDuplicatedGenericAndSizeAxisEvidence($product)
            && ! $this->hasOnlyBlankLocalChildAxisEvidence($product)
        ) {
            return [
                'status' => 'manual_review',
                'targets' => 0,
                'mutations' => 0,
                'reason' => 'Lokalne warianty zawierają niepuste lub obce wartości, których zdalna wersja językowa nie może nadpisać.',
            ];
        }

        $targetResolution = $this->remoteTargets($product);

        if ($targetResolution['error'] !== null) {
            return [
                'status' => ($targetResolution['retryable'] ?? false) ? 'deferred' : 'manual_review',
                'targets' => count($targetResolution['targets']),
                'mutations' => 0,
                'reason' => $targetResolution['error'],
                'allow_full_export' => (bool) ($targetResolution['allow_full_export'] ?? false),
            ];
        }

        $remoteFamilies = [];

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
            $inertParentAttributeIds = $this->inertTranslatedGlobalAttributePlaceholderIds(
                $target,
                $parent,
                $variations,
            );
            $parent = $this->withoutProvenInertGlobalAttributePlaceholders(
                $parent,
                $variations,
                $inertParentAttributeIds,
            );
            try {
                $planningParent = $this->normalizeExistingParentSizeAxisForPlan(
                    $target,
                    $parent,
                    $variations,
                    $variationOptionHints,
                );
            } catch (DomainException $exception) {
                return [
                    'status' => 'manual_review',
                    'targets' => count($targetResolution['targets']),
                    'mutations' => 0,
                    'reason' => sprintf(
                        'WooCommerce %s #%s: %s',
                        mb_strtoupper($target['language']),
                        $target['external_product_id'],
                        $exception->getMessage(),
                    ),
                    'languages' => array_values(array_unique(array_column(
                        $targetResolution['targets'],
                        'language',
                    ))),
                ];
            }

            $remoteFamilies[] = [
                'target' => $target,
                'parent' => $parent,
                'planning_parent' => $planningParent,
                'variations' => $variations,
                'protected' => $this->protectedSnapshot($parent, $variations),
                'inert_parent_attribute_ids' => $inertParentAttributeIds,
            ];
        }

        $planRemoteFamily = function (array $entry, array $optionHints): array {
            $target = $entry['target'];
            $parent = $entry['planning_parent'] ?? $entry['parent'];
            $variations = $entry['variations'];

            $childOnlyAxisOptions = [];
            $plan = $this->familyPlan(
                $parent,
                $variations,
                null,
                $optionHints,
                $target['language'],
                $childOnlyAxisOptions,
            );

            if ($this->requiresChildOnlyAxisResolution($plan)) {
                try {
                    $childOnlyAxisOptions = $this->resolveChildOnlyGlobalAxisOptions(
                        $target,
                        $parent,
                        $variations,
                    );
                    $plan = $this->familyPlan(
                        $parent,
                        $variations,
                        null,
                        $optionHints,
                        $target['language'],
                        $childOnlyAxisOptions,
                    );
                } catch (Throwable $exception) {
                    $plan = $this->unsafePlan($exception->getMessage());
                }
            }

            return [
                'plan' => $plan,
                'child_only_axis_options' => $childOnlyAxisOptions,
            ];
        };

        $plans = collect($remoteFamilies)
            ->map(function (array $entry) use ($planRemoteFamily, $variationOptionHints): array {
                return array_merge(
                    $entry,
                    $planRemoteFamily($entry, $variationOptionHints),
                );
            })
            ->all();

        // Some historical multilingual families have complementary damage:
        // the PL child options were erased while EN still maps every SKU to
        // one Size term (or vice versa). Read every language before giving up.
        // A complete remote SKU bijection may fill only blank child options;
        // any disagreement with local/live non-empty evidence remains unsafe.
        $remoteHintResolution = $this->completeRemoteVariationOptionHints($plans);

        if ($remoteHintResolution['error'] !== null) {
            return [
                'status' => 'manual_review',
                'targets' => count($targetResolution['targets']),
                'mutations' => 0,
                'reason' => $remoteHintResolution['error'],
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
            ];
        }

        foreach ($remoteHintResolution['hints'] as $sku => $optionKey) {
            if (isset($variationOptionHints[$sku])
                && $variationOptionHints[$sku] !== $optionKey
            ) {
                return [
                    'status' => 'manual_review',
                    'targets' => count($targetResolution['targets']),
                    'mutations' => 0,
                    'reason' => "Lokalna i zdalna rodzina przypisują SKU {$sku} do różnych rozmiarów.",
                    'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                ];
            }

            $variationOptionHints[$sku] = $optionKey;
        }

        if ($remoteHintResolution['hints'] !== []) {
            $plans = collect($remoteFamilies)
                ->map(function (array $entry) use ($planRemoteFamily, $variationOptionHints): array {
                    return array_merge(
                        $entry,
                        $planRemoteFamily($entry, $variationOptionHints),
                    );
                })
                ->all();
        }

        $emptyTranslationTargets = $this->recoverableEmptyTranslationTargets(
            $product,
            $plans,
        );

        if ($emptyTranslationTargets !== []) {
            // A full product export is the only operation allowed to convert
            // an existing translated `simple` product to `variable` and to
            // create its missing translated children with inherited prices
            // and stock. The exact parent contract, primary child bijection
            // and local Size evidence have already been verified. Persist a
            // narrow hand-off marker so that this one explicitly allowed
            // export emits canonical Size instead of preserving the mapped
            // legacy axis; the pending repair state still blocks every other
            // broad export until the rebuilt family passes remote verification.
            $this->markCanonicalFullExportHandoff($product, $emptyTranslationTargets);

            return [
                'status' => 'deferred',
                'targets' => count($targetResolution['targets']),
                'mutations' => 0,
                'reason' => sprintf(
                    'Zweryfikowane tłumaczenie %s jest produktem prostym bez wariantów; pełny eksport odbuduje je jako rodzinę wariantową opartą o Rozmiar.',
                    collect($emptyTranslationTargets)
                        ->map(fn (array $target): string => mb_strtoupper($target['language']).' #'.$target['external_product_id'])
                        ->implode(', '),
                ),
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                'allow_full_export' => true,
                'rebuild_simple_translations' => $emptyTranslationTargets,
            ];
        }

        foreach ($plans as $entry) {
            if ($entry['plan']['status'] !== 'unsafe') {
                continue;
            }

            return [
                'status' => 'manual_review',
                'targets' => count($targetResolution['targets']),
                'mutations' => 0,
                'reason' => sprintf(
                    'WooCommerce %s #%s: %s',
                    mb_strtoupper($entry['target']['language']),
                    $entry['target']['external_product_id'],
                    $entry['plan']['reason'],
                ),
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
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

        if (! $identity['contract']) {
            $mappingError = $this->legacyMappedIdentityError($product, $plans);

            if ($mappingError !== null) {
                return [
                    'status' => 'manual_review',
                    'targets' => count($plans),
                    'mutations' => 0,
                    'reason' => $mappingError,
                    'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                ];
            }
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
                $entry['planning_parent'] ?? $entry['parent'],
                $entry['variations'],
                $globalSize,
                $variationOptionHints,
                $entry['target']['language'],
                $entry['child_only_axis_options'],
            );

            if ($this->requiresChildOnlyAxisResolution($resolvedPlan)) {
                try {
                    $childOnlyAxisOptions = $this->resolveChildOnlyGlobalAxisOptions(
                        $entry['target'],
                        $entry['parent'],
                        $entry['variations'],
                    );
                    $resolvedPlan = $this->familyPlan(
                        $entry['planning_parent'] ?? $entry['parent'],
                        $entry['variations'],
                        $globalSize,
                        $variationOptionHints,
                        $entry['target']['language'],
                        $childOnlyAxisOptions,
                    );
                    $plans[$index]['child_only_axis_options'] = $childOnlyAxisOptions;
                } catch (Throwable $exception) {
                    $resolvedPlan = $this->unsafePlan($exception->getMessage());
                }
            }

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

        // A missing translated term must never be created before WordPress
        // proves that it can atomically link that term to its Polish source.
        // Run every capability check up front so a later integration cannot
        // fail after an earlier plan has already performed its first POST.
        $supplementalTranslationTargets = collect($plans)
            ->filter(fn (array $entry): bool => (array) data_get(
                $entry,
                'plan.supplemental_canonical_options',
                [],
            ) !== [] && $this->language((string) $entry['target']['language']) !== 'pl')
            ->unique(fn (array $entry): string => (string) $entry['target']['integration']->id
                .'|'.$this->language((string) $entry['target']['language']))
            ->values();

        foreach ($supplementalTranslationTargets as $entry) {
            $targetLanguage = $this->language((string) $entry['target']['language']);

            if ($this->client->productTranslationLinkingAvailable(
                $entry['target']['integration'],
                ['pl', $targetLanguage],
            )) {
                continue;
            }

            return [
                'status' => 'deferred',
                'targets' => count($plans),
                'mutations' => 0,
                'reason' => sprintf(
                    'WooCommerce %s #%s: WordPress nie potwierdził bezpiecznego powiązania tłumaczeń wartości globalnego atrybutu; brakująca wartość nie została utworzona.',
                    mb_strtoupper($targetLanguage),
                    $entry['target']['external_product_id'],
                ),
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
            ];
        }

        try {
            $discoveredAliases = $identity['contract']
                ? $this->preflightDiscoveredAliases($product, $plans)
                : [];
        } catch (RuntimeException $exception) {
            return [
                'status' => 'manual_review',
                'targets' => count($plans),
                'mutations' => 0,
                'reason' => $exception->getMessage(),
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
            ];
        }

        // Persist only identities proven by the reciprocal catalog contract.
        // This transaction repeats the ownership checks immediately before
        // any WooCommerce PUT, so a concurrent import cannot make us mutate a
        // family after claiming one of its translation identities elsewhere.
        try {
            $this->persistDiscoveredAliases($discoveredAliases);
        } catch (RuntimeException $exception) {
            return [
                'status' => 'manual_review',
                'targets' => count($plans),
                'mutations' => 0,
                'reason' => $exception->getMessage(),
                'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
            ];
        }

        // The English side of two historical families retained S/M on the
        // informational global Size attribute while its existing children and
        // Polish sibling still proved S/M/L. Every product, language, identity
        // and alias preflight above has now passed. Create and link only the
        // missing translated term on the already selected taxonomy before the
        // first product/variation PUT.
        foreach ($plans as $entry) {
            $supplemental = array_values((array) data_get(
                $entry,
                'plan.supplemental_canonical_options',
                [],
            ));

            if ($supplemental === []) {
                continue;
            }

            $sizeName = ProductVariantAxisNameResolver::SIZE;
            $targetLanguage = $this->language((string) $entry['target']['language']);
            $localized = collect($supplemental)
                ->map(fn (mixed $option): string => $this->localizedSizeOption(
                    $sizeName,
                    (string) $option,
                    $targetLanguage,
                ))
                ->all();
            $dictionaryOrder = $this->sizeDictionaryOrder($sizeName);
            $menuOrders = collect($supplemental)
                ->map(fn (mixed $option): ?int => $dictionaryOrder[$this->optionKey((string) $option)]
                    ?? null)
                ->all();

            if (collect($menuOrders)->contains(fn (?int $order): bool => $order === null)) {
                return [
                    'status' => 'manual_review',
                    'targets' => count($plans),
                    'mutations' => 0,
                    'reason' => sprintf(
                        'WooCommerce %s #%s: Brakująca wartość rozmiaru nie istnieje w żadnym słowniku rozmiarów ERP.',
                        mb_strtoupper($targetLanguage),
                        $entry['target']['external_product_id'],
                    ),
                    'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                ];
            }

            try {
                $this->client->ensureExistingGlobalProductAttributeOptions(
                    $entry['target']['integration'],
                    (int) $entry['plan']['size_id'],
                    $sizeName,
                    $localized,
                    $targetLanguage,
                    $supplemental,
                    $menuOrders,
                );
            } catch (Throwable $exception) {
                return [
                    'status' => 'manual_review',
                    'targets' => count($plans),
                    'mutations' => 0,
                    'reason' => sprintf(
                        'WooCommerce %s #%s: %s',
                        mb_strtoupper($targetLanguage),
                        $entry['target']['external_product_id'],
                        $exception->getMessage(),
                    ),
                    'languages' => array_values(array_unique(array_column($targetResolution['targets'], 'language'))),
                ];
            }
        }

        // Woo persists a global default's submitted term name as a slug. Read
        // the exact target-language term pair before the first product PUT;
        // final verification may then accept only that proven name<->slug
        // representation, never a canonical-looking term from another
        // Polylang language.
        foreach ($plans as $index => $entry) {
            try {
                $expectedParentPayload = $entry['plan']['parent_payload'] ?? [
                    'attributes' => array_values((array) data_get(
                        $entry,
                        'planning_parent.attributes',
                        [],
                    )),
                    'default_attributes' => array_values((array) data_get(
                        $entry,
                        'planning_parent.default_attributes',
                        [],
                    )),
                ];
                $plans[$index]['expected_parent_payload'] = $expectedParentPayload;
                $plans[$index]['size_default_term_aliases'] = $this->resolveSizeDefaultTermAliases(
                    $entry['target'],
                    $expectedParentPayload,
                    (int) $entry['plan']['size_id'],
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
                || $entry['plan']['parent_payload'] !== null
                || $entry['plan']['transitional_parent_payload'] !== null;

            try {
                $transition = $entry['plan']['transitional_parent_payload'];

                if ($transition !== null) {
                    $transitionalParent = $this->client->updateProductVariantAxisByIds(
                        $target['integration'],
                        $target['external_product_id'],
                        null,
                        $transition,
                        $this->apiLanguage($target['language']),
                    );
                    $transitionalParent = $this->withoutProvenInertGlobalAttributePlaceholders(
                        $transitionalParent,
                        $entry['variations'],
                        (array) ($entry['inert_parent_attribute_ids'] ?? []),
                    );

                    $transitionMismatch = $this->parentAxisPayloadMismatch(
                        $transitionalParent,
                        $transition,
                    );

                    if ($transitionMismatch !== null) {
                        throw new RuntimeException(
                            "WooCommerce nie potwierdził przejściowej osi rozmiaru produktu #{$target['external_product_id']}: {$transitionMismatch}.",
                        );
                    }

                    $mutations++;
                }

                foreach ($this->orderedVariationPayloads(
                    $entry['plan']['variation_payloads'],
                ) as $variationId => $payload) {
                    $updatedVariation = $this->client->updateProductVariantAxisByIds(
                        $target['integration'],
                        $target['external_product_id'],
                        (string) $variationId,
                        $payload,
                        $this->apiLanguage($target['language']),
                    );

                    if (! $this->variationAxisPayloadMatches($updatedVariation, $payload)) {
                        throw new RuntimeException(
                            "WooCommerce nie potwierdził osi rozmiaru wariantu #{$variationId}.",
                        );
                    }

                    $mutations++;
                }

                $payload = $entry['plan']['parent_payload'];

                if ($payload !== null) {
                    $updatedParent = $this->client->updateProductVariantAxisByIds(
                        $target['integration'],
                        $target['external_product_id'],
                        null,
                        $payload,
                        $this->apiLanguage($target['language']),
                    );
                    $updatedParent = $this->withoutProvenInertGlobalAttributePlaceholders(
                        $updatedParent,
                        $entry['variations'],
                        (array) ($entry['inert_parent_attribute_ids'] ?? []),
                    );

                    $parentMismatch = $this->parentAxisPayloadMismatch($updatedParent, $payload);

                    if ($parentMismatch !== null) {
                        throw new RuntimeException(
                            "WooCommerce nie potwierdził docelowej osi rozmiaru produktu #{$target['external_product_id']}: {$parentMismatch}.",
                        );
                    }

                    $mutations++;
                }

                $verified = $this->unsafePlan('Nie wykonano końcowego odczytu WooCommerce.');
                $parent = [];
                $variations = [];
                $verificationConfirmed = false;
                $expectedParentPayload = $entry['expected_parent_payload'];

                // A proxy/object-cache layer can briefly serve the pre-PUT
                // representation. Every read carries a unique cache buster;
                // retry only the idempotent GET before deciding to roll back.
                for ($verificationAttempt = 1; $verificationAttempt <= 3; $verificationAttempt++) {
                    $parent = $this->client->productById(
                        $target['integration'],
                        $target['external_product_id'],
                    );
                    $variations = $this->client->productVariationsByParent(
                        $target['integration'],
                        $target['external_product_id'],
                        $this->apiLanguage($target['language']),
                    );
                    $parent = $this->withoutProvenInertGlobalAttributePlaceholders(
                        $parent,
                        $variations,
                        (array) ($entry['inert_parent_attribute_ids'] ?? []),
                    );
                    $planningParent = $this->normalizeVerifiedSizeDefaultAliasesForPlan(
                        $parent,
                        $expectedParentPayload,
                        (int) $entry['plan']['size_id'],
                        (array) ($entry['size_default_term_aliases'] ?? []),
                    );
                    $verified = $this->familyPlan(
                        $planningParent,
                        $variations,
                        null,
                        [],
                        $target['language'],
                    );
                    $verificationConfirmed = $this->finalAxisStateMatches(
                        $parent,
                        $verified,
                        $expectedParentPayload,
                        (array) ($entry['size_default_term_aliases'] ?? []),
                    );

                    if ($verificationConfirmed) {
                        break;
                    }

                    if ($verificationAttempt < 3) {
                        usleep($verificationAttempt * 200_000);
                    }
                }

                if (! $verificationConfirmed) {
                    throw new RuntimeException(
                        sprintf(
                            'WooCommerce nie potwierdził kanonicznej osi rozmiaru produktu #%s po naprawie (%s, parent_delta=%s).',
                            $target['external_product_id'],
                            $this->verificationResidual($verified),
                            $this->finalParentAxisPayloadDelta(
                                $parent,
                                $expectedParentPayload,
                                (int) ($verified['size_id'] ?? 0),
                                (array) ($entry['size_default_term_aliases'] ?? []),
                            ),
                        ),
                    );
                }

                if (! hash_equals(
                    $entry['protected'],
                    $this->protectedSnapshot($parent, $variations),
                )) {
                    throw new RuntimeException(
                        sprintf(
                            'Naprawa osi produktu #%s zmieniła chronione dane handlowe lub treści (protected_delta=%s).',
                            $target['external_product_id'],
                            $this->protectedSnapshotDelta(
                                $entry['parent'],
                                $entry['variations'],
                                $parent,
                                $variations,
                            ),
                        ),
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

    /**
     * Build every parent and child alias from the verified Polylang contract
     * and reject local identity conflicts before the first WooCommerce PUT.
     *
     * @param  list<array<string,mixed>>  $plans
     * @return list<array{product_id:int,sales_channel_id:int,external_product_id:string,external_variation_id:?string,external_sku:?string,language:string,translation_group:string}>
     */
    private function preflightDiscoveredAliases(Product $product, array $plans): array
    {
        $records = collect();

        foreach (collect($plans)->groupBy(fn (array $entry): int => (int) $entry['target']['integration']->id) as $integrationPlans) {
            $primary = $integrationPlans->first(
                fn (array $entry): bool => (bool) data_get($entry, 'target.is_primary', false),
            );

            if (! is_array($primary)) {
                continue;
            }

            $primaryLanguage = $this->language(data_get($primary, 'target.language', 'pl'));
            $primaryParentId = trim((string) data_get($primary, 'target.external_product_id', ''));
            $salesChannelId = (int) data_get($primary, 'target.sales_channel_id', 0);
            $primaryVariationsById = collect((array) ($primary['variations'] ?? []))
                ->filter(fn (mixed $variation): bool => is_array($variation))
                ->keyBy(fn (array $variation): string => trim((string) ($variation['id'] ?? '')));
            $localByPrimaryVariationId = $product->variantChildren
                ->flatMap(function (Product $variant) use ($salesChannelId, $primaryParentId): Collection {
                    return $variant->channelMappings
                        ->filter(fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === $salesChannelId
                            && trim((string) $mapping->external_product_id) === $primaryParentId
                            && filled($mapping->external_variation_id))
                        ->map(fn (ProductChannelMapping $mapping): array => [
                            'external_variation_id' => trim((string) $mapping->external_variation_id),
                            'product' => $variant,
                        ]);
                })
                ->keyBy('external_variation_id')
                ->map(fn (array $entry): Product => $entry['product']);

            foreach ($integrationPlans->reject(
                fn (array $entry): bool => (bool) data_get($entry, 'target.is_primary', false),
            ) as $entry) {
                $target = $entry['target'];
                $language = $this->language($target['language']);
                $externalProductId = trim((string) $target['external_product_id']);
                $translationGroup = trim((string) ($entry['parent']['lemon_erp_translation_group'] ?? ''));
                $records->push([
                    'product_id' => (int) $product->id,
                    'sales_channel_id' => (int) $target['sales_channel_id'],
                    'external_product_id' => $externalProductId,
                    'external_variation_id' => null,
                    'external_sku' => filled($entry['parent']['sku'] ?? null)
                        ? trim((string) $entry['parent']['sku'])
                        : null,
                    'language' => $language,
                    'translation_group' => $translationGroup,
                ]);

                foreach ($entry['variations'] as $variation) {
                    $translationMap = $this->translationIdMap(
                        $variation['lemon_erp_translations'] ?? [],
                    );
                    $primaryVariationId = (string) ($translationMap[$primaryLanguage] ?? '');
                    $localVariant = $localByPrimaryVariationId->get($primaryVariationId);
                    $primaryVariation = $primaryVariationsById->get($primaryVariationId);
                    $externalVariationId = trim((string) ($variation['id'] ?? ''));
                    $localSku = mb_strtoupper(trim((string) $localVariant?->sku));
                    $primaryRemoteSku = mb_strtoupper(trim((string) data_get(
                        $primaryVariation,
                        'sku',
                        '',
                    )));
                    $skuIdentity = $localSku !== ''
                        && $primaryRemoteSku !== ''
                        && $localSku === $primaryRemoteSku;
                    $existingAliasIdentity = $localVariant instanceof Product
                        && $localVariant->channelAliases->contains(
                            fn (ProductChannelAlias $alias): bool =>
                                (int) $alias->sales_channel_id === (int) $target['sales_channel_id']
                                && trim((string) $alias->external_product_id) === $externalProductId
                                && trim((string) $alias->external_variation_id) === $externalVariationId
                                && $this->language($alias->language ?? 'en') === $language
                                && $alias->isOutboundSyncEnabled(),
                        );

                    if (! $localVariant instanceof Product
                        || ! is_array($primaryVariation)
                        || (! $skuIdentity && ! $existingAliasIdentity)
                        || ! ctype_digit($externalVariationId)
                        || (int) $externalVariationId <= 0
                    ) {
                        throw new RuntimeException(
                            "Nie można jednoznacznie przypisać wariantu {$language} #{$externalVariationId} do lokalnej rodziny ERP.",
                        );
                    }

                    $records->push([
                        'product_id' => (int) $localVariant->id,
                        'sales_channel_id' => (int) $target['sales_channel_id'],
                        'external_product_id' => $externalProductId,
                        'external_variation_id' => $externalVariationId,
                        'external_sku' => filled($variation['sku'] ?? null)
                            ? trim((string) $variation['sku'])
                            : $localVariant->sku,
                        'language' => $language,
                        'translation_group' => trim((string) ($variation['lemon_erp_translation_group'] ?? '')),
                    ]);
                }
            }
        }

        $records = $records
            ->unique(fn (array $record): string => $record['sales_channel_id'].'|'.ProductChannelAlias::externalKey(
                $record['external_product_id'],
                $record['external_variation_id'],
            ))
            ->values();

        foreach ($records as $record) {
            $externalKey = ProductChannelAlias::externalKey(
                $record['external_product_id'],
                $record['external_variation_id'],
            );
            $aliasOwner = ProductChannelAlias::query()
                ->where('sales_channel_id', $record['sales_channel_id'])
                ->where('external_key', $externalKey)
                ->value('product_id');

            if ($aliasOwner !== null && (int) $aliasOwner !== $record['product_id']) {
                throw new RuntimeException(
                    "Identyfikator WooCommerce {$externalKey} jest już aliasem innego produktu ERP.",
                );
            }

            $mappingConflict = ProductChannelMapping::query()
                ->where('sales_channel_id', $record['sales_channel_id'])
                ->where('external_product_id', $record['external_product_id'])
                ->when(
                    filled($record['external_variation_id']),
                    fn ($query) => $query->where('external_variation_id', $record['external_variation_id']),
                    fn ($query) => $query->where(function ($nested): void {
                        $nested
                            ->whereNull('external_variation_id')
                            ->orWhereIn('external_variation_id', ['', '0'])
                            ->orWhereRaw("TRIM(external_variation_id) = ''");
                    }),
                )
                ->where('product_id', '!=', $record['product_id'])
                ->exists();

            if ($mappingConflict) {
                throw new RuntimeException(
                    "Identyfikator WooCommerce {$externalKey} ma już mapowanie do innego produktu ERP; wymagane jest bezpieczne scalenie tłumaczenia.",
                );
            }
        }

        return $records->all();
    }

    /**
     * @param  list<array{product_id:int,sales_channel_id:int,external_product_id:string,external_variation_id:?string,external_sku:?string,language:string,translation_group:string}>  $records
     */
    private function persistDiscoveredAliases(array $records): void
    {
        if ($records === []) {
            return;
        }

        DB::transaction(function () use ($records): void {
            foreach ($records as $record) {
                $externalKey = ProductChannelAlias::externalKey(
                    $record['external_product_id'],
                    $record['external_variation_id'],
                );
                $alias = ProductChannelAlias::query()
                    ->where('sales_channel_id', $record['sales_channel_id'])
                    ->where('external_key', $externalKey)
                    ->lockForUpdate()
                    ->first();

                if ($alias instanceof ProductChannelAlias
                    && (int) $alias->product_id !== $record['product_id']
                ) {
                    throw new RuntimeException(
                        "Identyfikator WooCommerce {$externalKey} został w międzyczasie przypisany innemu produktowi ERP.",
                    );
                }

                $mappingOwner = ProductChannelMapping::query()
                    ->where('sales_channel_id', $record['sales_channel_id'])
                    ->where('external_product_id', $record['external_product_id'])
                    ->when(
                        filled($record['external_variation_id']),
                        fn ($query) => $query->where('external_variation_id', $record['external_variation_id']),
                        fn ($query) => $query->where(function ($nested): void {
                            $nested
                                ->whereNull('external_variation_id')
                                ->orWhereIn('external_variation_id', ['', '0'])
                                ->orWhereRaw("TRIM(external_variation_id) = ''");
                        }),
                    )
                    ->where('product_id', '!=', $record['product_id'])
                    ->lockForUpdate()
                    ->value('product_id');

                if ($mappingOwner !== null) {
                    throw new RuntimeException(
                        "Identyfikator WooCommerce {$externalKey} został w międzyczasie zmapowany do innego produktu ERP.",
                    );
                }

                if ($alias instanceof ProductChannelAlias) {
                    $sameIdentity = trim((string) $alias->external_product_id)
                            === $record['external_product_id']
                        && trim((string) $alias->external_variation_id)
                            === trim((string) $record['external_variation_id'])
                        && $this->language($alias->language ?? 'en') === $record['language'];

                    if (! $sameIdentity) {
                        throw new RuntimeException(
                            "Alias WooCommerce {$externalKey} ma sprzeczne lokalne języki aliasu i kontraktu albo niespójną tożsamość.",
                        );
                    }

                    $metadata = (array) $alias->metadata;
                    $changed = false;

                    if ($alias->language === null && $record['language'] === 'en') {
                        $alias->language = 'en';
                        $changed = true;
                    }

                    if (! $alias->isOutboundSyncEnabled()) {
                        data_forget($metadata, self::STATE_PATH.'.routing_only');
                        data_set($metadata, self::STATE_PATH.'.reactivated_at', now()->toISOString());
                        $alias->metadata = $metadata;
                        $changed = true;
                    }

                    if ($changed) {
                        $alias->save();
                    }

                    continue;
                }

                $alias = new ProductChannelAlias([
                    'sales_channel_id' => $record['sales_channel_id'],
                    'external_key' => $externalKey,
                ]);
                $alias->fill([
                    'product_id' => $record['product_id'],
                    'external_product_id' => $record['external_product_id'],
                    'external_variation_id' => $record['external_variation_id'],
                    'external_sku' => $record['external_sku'],
                    'language' => $record['language'],
                    'metadata' => array_replace_recursive((array) $alias->metadata, [
                        'source' => 'woo_axis_repair_contract_discovery',
                        'translation_group' => $record['translation_group'],
                        'discovered_at' => now()->toISOString(),
                    ]),
                ])->save();
            }

            collect($records)
                ->groupBy(fn (array $record): string => implode('|', [
                    $record['product_id'],
                    $record['sales_channel_id'],
                ]))
                ->each(function (Collection $identityRecords): void {
                    $first = $identityRecords->first();

                    if (! is_array($first)) {
                        return;
                    }

                    $expectedKeys = $identityRecords
                        ->map(fn (array $record): string => ProductChannelAlias::externalKey(
                            $record['external_product_id'],
                            $record['external_variation_id'],
                        ))
                        ->unique();

                    ProductChannelAlias::query()
                        ->where('product_id', $first['product_id'])
                        ->where('sales_channel_id', $first['sales_channel_id'])
                        ->lockForUpdate()
                        ->get()
                        ->reject(fn (ProductChannelAlias $alias): bool => $expectedKeys->contains(
                            $alias->external_key,
                        ))
                        ->each(function (ProductChannelAlias $alias) use ($first): void {
                            $metadata = (array) $alias->metadata;
                            data_set($metadata, self::STATE_PATH.'.routing_only', true);
                            data_set(
                                $metadata,
                                self::STATE_PATH.'.superseded_at',
                                data_get($metadata, self::STATE_PATH.'.superseded_at')
                                    ?? now()->toISOString(),
                            );
                            data_set(
                                $metadata,
                                self::STATE_PATH.'.reason',
                                'outside_verified_translation_contract',
                            );
                            data_set(
                                $metadata,
                                self::STATE_PATH.'.translation_group',
                                $first['translation_group'],
                            );
                            $alias->forceFill(['metadata' => $metadata])->save();
                        });
                });
        }, 3);
    }

    /** @param array<string,mixed> $entry */
    private function rollbackLanguageAxis(array $entry): void
    {
        $target = $entry['target'];
        $expected = $this->axisSnapshot($entry['parent'], $entry['variations']);
        $rollbackErrors = [];
        $transition = $entry['plan']['transitional_parent_payload'] ?? null;
        $applySafeTransition = function () use (
            $entry,
            $target,
            $transition,
            &$rollbackErrors,
        ): bool {
            if (! is_array($transition)) {
                return true;
            }

            $attemptErrors = [];

            // The client already performs its bounded HTTP retry. One extra
            // service-level attempt covers a response timeout after Woo may
            // have applied the first PUT, without opening an unbounded loop.
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $transitionalParent = $this->client->updateProductVariantAxisByIds(
                        $target['integration'],
                        $target['external_product_id'],
                        null,
                        $transition,
                        $this->apiLanguage($target['language']),
                    );
                    $transitionalParent = $this->withoutProvenInertGlobalAttributePlaceholders(
                        $transitionalParent,
                        $entry['variations'],
                        (array) ($entry['inert_parent_attribute_ids'] ?? []),
                    );

                    $transitionMismatch = $this->parentAxisPayloadMismatch(
                        $transitionalParent,
                        $transition,
                    );

                    if ($transitionMismatch === null) {
                        return true;
                    }

                    $attemptErrors[] = "rodzic przejściowy (próba {$attempt}): {$transitionMismatch}";
                } catch (Throwable $exception) {
                    $attemptErrors[] = "rodzic przejściowy (próba {$attempt}): ".$exception->getMessage();
                }
            }

            array_push($rollbackErrors, ...$attemptErrors);

            return false;
        };
        $readLiveState = function () use ($entry, $target): array {
            $parent = $this->client->productById(
                $target['integration'],
                $target['external_product_id'],
            );
            $variations = $this->client->productVariationsByParent(
                $target['integration'],
                $target['external_product_id'],
                $this->apiLanguage($target['language']),
            );
            $parent = $this->withoutProvenInertGlobalAttributePlaceholders(
                $parent,
                $variations,
                (array) ($entry['inert_parent_attribute_ids'] ?? []),
            );

            return [$parent, $variations];
        };

        if (! $applySafeTransition()) {
            [$parent, $variations] = $readLiveState();

            if (hash_equals($expected, $this->axisSnapshot($parent, $variations))
                && hash_equals(
                    (string) $entry['protected'],
                    $this->protectedSnapshot($parent, $variations),
                )
            ) {
                return;
            }

            $safe = $this->parentEnablesEveryChildAxis($parent, $variations)
                && hash_equals(
                    (string) $entry['protected'],
                    $this->protectedSnapshot($parent, $variations),
                );

            throw new RuntimeException(
                $safe
                    ? "Rollback produktu #{$target['external_product_id']} zatrzymał bezpieczny stan przejściowy po niepotwierdzonej osi rodzica: ".implode(' | ', $rollbackErrors)
                    : "WooCommerce nie potwierdził bezpiecznego stanu przejściowego rollbacku produktu #{$target['external_product_id']}: ".implode(' | ', $rollbackErrors),
            );
        }

        foreach ($entry['variations'] as $variation) {
            $payload = [
                'attributes' => $this->serializeRollbackVariationAttributes(
                    array_values((array) ($variation['attributes'] ?? [])),
                ),
                'menu_order' => (int) ($variation['menu_order'] ?? 0),
            ];

            try {
                $restoredVariation = $this->client->updateProductVariantAxisByIds(
                    $target['integration'],
                    $target['external_product_id'],
                    (string) $variation['id'],
                    $payload,
                    $this->apiLanguage($target['language']),
                );

                if (! $this->variationAxisPayloadMatches($restoredVariation, $payload)) {
                    $rollbackErrors[] = 'wariant #'.($variation['id'] ?? '?')
                        .': WooCommerce nie potwierdził przywróconej osi';
                }
            } catch (Throwable $exception) {
                // Continue restoring the remaining children, but never remove
                // the target Size parent axis until a live GET confirms every
                // original child. This keeps a mixed family non-orphaned.
                $rollbackErrors[] = 'wariant #'.($variation['id'] ?? '?').': '.$exception->getMessage();
            }
        }

        if ($rollbackErrors !== []) {
            // Reassert both axes after any uncertain child response. This is
            // idempotent and safe whether that response was ignored or merely
            // timed out after applying the restore.
            $applySafeTransition();
            [$parent, $variations] = $readLiveState();
            $protected = hash_equals(
                (string) $entry['protected'],
                $this->protectedSnapshot($parent, $variations),
            );
            $childrenConfirmed = hash_equals(
                $this->variationAxisSnapshot($entry['variations']),
                $this->variationAxisSnapshot($variations),
            );

            if (! $protected) {
                throw new RuntimeException(
                    "Niepewny rollback produktu #{$target['external_product_id']} zmienił chronione dane handlowe lub treści.",
                );
            }

            if (! $childrenConfirmed) {
                if ($this->parentEnablesEveryChildAxis($parent, $variations)) {
                    throw new RuntimeException(
                        "Rollback produktu #{$target['external_product_id']} zatrzymał bezpieczny stan przejściowy, ponieważ nie potwierdzono wszystkich dzieci: ".implode(' | ', $rollbackErrors),
                    );
                }

                throw new RuntimeException(
                    "WooCommerce nie potwierdził ani dzieci, ani bezpiecznej osi przejściowej produktu #{$target['external_product_id']}: ".implode(' | ', $rollbackErrors),
                );
            }

            if (! $this->parentEnablesEveryChildAxis($parent, $entry['variations'])) {
                throw new RuntimeException(
                    "WooCommerce potwierdził dzieci produktu #{$target['external_product_id']}, ale nie zachował ich bezpiecznej osi przejściowej.",
                );
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
                $this->apiLanguage($target['language']),
            );
        } catch (Throwable $exception) {
            $rollbackErrors[] = 'rodzic: '.$exception->getMessage();
        }

        [$parent, $variations] = $readLiveState();

        if (! hash_equals($expected, $this->axisSnapshot($parent, $variations))) {
            throw new RuntimeException(
                "WooCommerce nie przywrócił dokładnego snapshotu osi produktu #{$target['external_product_id']}"
                    .($rollbackErrors === [] ? '.' : ': '.implode(' | ', $rollbackErrors)),
            );
        }

        if (! hash_equals(
            (string) $entry['protected'],
            $this->protectedSnapshot($parent, $variations),
        )) {
            throw new RuntimeException(
                "Rollback osi produktu #{$target['external_product_id']} zmienił chronione dane handlowe lub treści.",
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

    /** @param list<array<string,mixed>> $variations */
    private function variationAxisSnapshot(array $variations): string
    {
        $snapshot = collect($variations)
            ->mapWithKeys(fn (array $variation): array => [
                (string) ($variation['id'] ?? '') => [
                    'attributes' => $this->serializeRollbackVariationAttributes(
                        array_values((array) ($variation['attributes'] ?? [])),
                    ),
                    'menu_order' => (int) ($variation['menu_order'] ?? 0),
                ],
            ])
            ->sortKeys()
            ->all();

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
        $sizeName = ProductVariantAxisNameResolver::SIZE;
        $orderedOptions = $this->orderedSizeOptions(
            $sizeName,
            (array) ($size['options'] ?? []),
        );
        $primaryLanguage = $this->language(data_get($primary, 'target.language', 'pl'));
        $localizedOrderedOptions = collect($orderedOptions)
            ->map(fn (string $option): string => $this->localizedSizeOption(
                $sizeName,
                $option,
                $primaryLanguage,
            ))
            ->all();
        $verifiedParent['attributes'] = collect((array) ($verifiedParent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(function (array $attribute) use ($size, $localizedOrderedOptions): array {
                if ($this->sameAttribute($attribute, $size)) {
                    $attribute['options'] = $localizedOrderedOptions;
                }

                return $attribute;
            })
            ->values()
            ->all();
        $expectedPrimaryPayload = $primary['expected_parent_payload'] ?? null;

        if (is_array($expectedPrimaryPayload)) {
            // The live GET legitimately contains term slugs. The already
            // verified request payload is the canonical ERP snapshot and also
            // handles localized slugs such as `m-l-en` without guessing.
            $verifiedParent['default_attributes'] = collect((array) ($expectedPrimaryPayload['default_attributes'] ?? []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->values()
                ->all();
        }
        $remoteBySku = collect($verifiedVariations)
            ->mapWithKeys(fn (array $variation): array => [
                mb_strtoupper(trim((string) ($variation['sku'] ?? ''))) => $variation,
            ])
            ->filter(fn (array $variation, string $sku): bool => $sku !== '');
        $remoteById = collect($verifiedVariations)
            ->mapWithKeys(fn (array $variation): array => [
                trim((string) ($variation['id'] ?? '')) => $variation,
            ])
            ->filter(fn (array $variation, mixed $id): bool => trim((string) $id) !== '');
        $primarySalesChannelId = (int) data_get($primary, 'target.integration.sales_channel_id', 0);
        $primaryExternalProductId = trim((string) data_get(
            $primary,
            'target.external_product_id',
            '',
        ));

        DB::transaction(function () use (
            $product,
            $verifiedParent,
            $sizeName,
            $orderedOptions,
            $remoteBySku,
            $remoteById,
            $primarySalesChannelId,
            $primaryExternalProductId,
            $primaryLanguage,
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
                $mappedVariationIds = ProductChannelMapping::query()
                    ->where('product_id', $relation->child_product_id)
                    ->where('sales_channel_id', $primarySalesChannelId)
                    ->where('external_product_id', $primaryExternalProductId)
                    ->whereNotNull('external_variation_id')
                    ->lockForUpdate()
                    ->pluck('external_variation_id')
                    ->map(fn (mixed $id): string => trim((string) $id))
                    ->filter()
                    ->unique()
                    ->values();
                $remote = $mappedVariationIds->count() === 1
                    ? $remoteById->get((string) $mappedVariationIds->first())
                    : null;

                if (! is_array($remote)) {
                    $remote = $remoteBySku->get($sku);
                }

                if (! $variant instanceof Product || ! is_array($remote)) {
                    throw new RuntimeException("Nie znaleziono zweryfikowanego wariantu Woo dla SKU {$sku}.");
                }

                $rows = array_values((array) ($remote['attributes'] ?? []));
                $option = $this->canonicalSizeOption(
                    $sizeName,
                    trim((string) data_get($rows, '0.option', '')),
                );
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
            ], $localVariationPayloads, null, [], $primaryLanguage);

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
            && $this->isLocalSizeParameter($parameter, $sizeName, $options));
        $template = (array) ($targets->first(fn (array $parameter): bool => $this->variantOptions->isSizeAttribute(
            (string) ($parameter['name'] ?? ''),
        )) ?? $targets->first() ?? []);
        $value = implode(' | ', $options);

        $template['name'] = $sizeName;
        $template['name_en'] = 'Size';
        $template['value'] = $value;
        $template['variation'] = true;
        $this->synchronizeLocalizedParameter(
            $template,
            $value,
            implode(' | ', collect($options)
                ->map(fn (string $option): string => $this->localizedSizeOption(
                    $sizeName,
                    $option,
                    'en',
                ))
                ->all()),
            $sizeName,
        );

        return $parameters
            ->reject(fn (mixed $parameter): bool => is_array($parameter)
                && $this->isLocalSizeParameter($parameter, $sizeName, $options))
            ->values()
            ->push($template)
            ->all();
    }

    /** @return list<mixed> */
    private function canonicalVariantParameters(array $master, string $sizeName, string $option): array
    {
        $parameters = collect((array) data_get($master, 'parameters', []));
        $targets = $parameters->filter(fn (mixed $parameter): bool => is_array($parameter)
            && $this->isLocalSizeParameter($parameter, $sizeName, [$option]));
        $template = (array) ($targets->first(fn (array $parameter): bool => $this->variantOptions->isSizeAttribute(
            (string) ($parameter['name'] ?? ''),
        )) ?? $targets->first() ?? []);

        $template['name'] = $sizeName;
        $template['name_en'] = 'Size';
        $template['value'] = $option;
        $template['variation'] = true;
        $this->synchronizeLocalizedParameter(
            $template,
            $option,
            $this->localizedSizeOption($sizeName, $option, 'en'),
            $sizeName,
        );

        return $parameters
            ->reject(fn (mixed $parameter): bool => is_array($parameter)
                && $this->isLocalSizeParameter($parameter, $sizeName, [$option]))
            ->values()
            ->push($template)
            ->all();
    }

    /**
     * Direct Size aliases always describe the repaired axis. Historical
     * generic labels do not: BLVariant/wariant were also used for colour.
     * Consume a generic row only when every populated localized value list is
     * an exact match for the size options being synchronized.
     *
     * @param  array<string,mixed>  $parameter
     * @param  list<string>  $options
     */
    private function isLocalSizeParameter(
        array $parameter,
        string $sizeName,
        array $options,
    ): bool {
        $name = trim((string) ($parameter['name'] ?? $parameter['slug'] ?? ''));

        if ($this->variantOptions->isSizeAttribute($name)) {
            return true;
        }

        if (! $this->legacySizeAxis->isLegacyGeneric($name)) {
            return false;
        }

        $expected = collect($options)
            ->map(fn (mixed $option): string => $this->canonicalSizeOptionKey(
                $sizeName,
                (string) $option,
            ))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($expected === []) {
            return false;
        }

        $storedLists = collect([
            $parameter['value'] ?? null,
            $parameter['value_pl'] ?? null,
            data_get($parameter, 'translations.pl.value'),
            $parameter['value_en'] ?? null,
            data_get($parameter, 'translations.en.value'),
        ])
            ->map(fn (mixed $value): array => $this->localOptionValues($value)
                ->map(fn (string $option): string => $this->canonicalSizeOptionKey(
                    $sizeName,
                    $option,
                ))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all())
            ->filter(fn (array $keys): bool => $keys !== [])
            ->values();

        return $storedLists->isNotEmpty()
            && $storedLists->every(fn (array $keys): bool => $keys === $expected);
    }

    /** @param array<string,mixed> $parameter */
    private function synchronizeLocalizedParameter(
        array &$parameter,
        string $polishValue,
        string $englishValue,
        string $sizeName,
    ): void {
        if (array_key_exists('value_pl', $parameter)) {
            $parameter['value_pl'] = $polishValue;
        }

        if (array_key_exists('value_en', $parameter)) {
            $parameter['value_en'] = $englishValue;
        }

        foreach ((array) ($parameter['translations'] ?? []) as $language => $translation) {
            if (! is_array($translation)) {
                continue;
            }

            if (array_key_exists('value', $translation)) {
                data_set(
                    $parameter,
                    "translations.{$language}.value",
                    $this->language((string) $language) === 'en'
                        ? $englishValue
                        : $polishValue,
                );
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
     * ERP-owned families are queued separately from the historical
     * Woo-import migration, but deliberately run through the exact same
     * remote-first repair implementation.
     */
    public function isErpOwnedVariantRootCandidate(Product $product): bool
    {
        $product->loadMissing(['variantChildren.parentRelations', 'parentRelations']);

        return $this->isErpOwnedRoot($product)
            && $product->variantChildren->isNotEmpty()
            && $product->variantChildren->every(function (Product $variant) use ($product): bool {
                $variantParents = $variant->parentRelations
                    ->filter(fn (ProductRelation $relation): bool => $relation->relation_type === 'variant')
                    ->values();

                return $variant->masterSource() === 'erp'
                    && data_get($variant->masterData(), 'product_type') === 'variation'
                    && $variantParents->count() === 1
                    && (int) $variantParents->first()->parent_product_id === (int) $product->id;
            })
            && $this->parentMappingsQuery((int) $product->id)->exists()
            && $this->hasLocalSizeAxisEvidence($product);
    }

    /** Public entry point for new migrations that may include either owner. */
    public function isSizeVariantRootCandidate(Product $product): bool
    {
        return $this->isWooOwnedVariantRootCandidate($product)
            || $this->isErpOwnedVariantRootCandidate($product);
    }

    /**
     * Re-audit only families that locally expose at least two distinct legacy
     * generic aliases (for example `wariant` and `BLVariant`) next to Size.
     * Every populated representation must prove the exact same dictionary-
     * backed option set before the family is allowed into the remote repair.
     */
    public function isMultipleLegacySizeAxisCandidate(Product $product): bool
    {
        $product->loadMissing(['variantChildren', 'parentRelations']);

        if (! $this->isSizeVariantRootCandidate($product)
            || ! $this->hasParentDuplicatedGenericAndSizeAxisEvidence($product)
        ) {
            return false;
        }

        $aliases = collect([
            ...(array) data_get($product->masterData(), 'parameters', []),
            ...(array) data_get($product->attributes, 'woocommerce_attributes', []),
        ])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row): string {
                foreach ([$row['name'] ?? null, $row['slug'] ?? null] as $name) {
                    $name = trim((string) $name);

                    if ($name !== '' && $this->legacySizeAxis->isLegacyGeneric($name)) {
                        return $this->attributeKey($name);
                    }
                }

                return '';
            })
            ->filter()
            ->unique()
            ->values();

        return $aliases->count() > 1;
    }

    /**
     * Historical editor saves can promote the parent to ERP ownership while
     * its already mapped children retain their Woo-import ownership marker.
     * That mixed provenance is not sufficient for the broad legacy migration,
     * but it is safe for a child-assignment audit when every local SKU has one
     * concrete Size option and every active parent mapping has one exact child
     * variation mapping. The live remote preflight remains authoritative.
     */
    public function isChildSizeAssignmentAuditCandidate(Product $product): bool
    {
        $product->loadMissing([
            'parentRelations',
            'variantChildren.parentRelations',
            'variantChildren.channelMappings',
        ]);

        if (! $this->isRepairableRoot($product)
            || $product->variantChildren->isEmpty()
            || ! $this->hasLocalSizeAxisEvidence($product)
        ) {
            return false;
        }

        try {
            $optionHints = $this->localVariationOptionHints($product);
        } catch (DomainException) {
            return false;
        }

        if ($optionHints === []
            && ! ($this->hasParentDuplicatedGenericAndSizeAxisEvidence($product)
                && $this->hasOnlyBlankLocalChildAxisEvidence($product))
        ) {
            return false;
        }

        $parentMappings = $this->parentMappingsQuery((int) $product->id)->get();

        if ($parentMappings->isEmpty()
            || $parentMappings->contains(fn (ProductChannelMapping $mapping): bool => ! ctype_digit(
                trim((string) $mapping->external_product_id),
            ) || (int) $mapping->external_product_id <= 0)
        ) {
            return false;
        }

        return $product->variantChildren->every(function (Product $variant) use (
            $product,
            $parentMappings,
        ): bool {
            $variantParents = $variant->parentRelations
                ->filter(fn (ProductRelation $relation): bool => $relation->relation_type === 'variant')
                ->values();

            if (! in_array($variant->masterSource(), [
                'erp',
                'woocommerce',
                'woocommerce_import',
            ], true)
                || data_get($variant->masterData(), 'product_type') !== 'variation'
                || $variantParents->count() !== 1
                || (int) $variantParents->first()->parent_product_id !== (int) $product->id
            ) {
                return false;
            }

            return $parentMappings->every(function (ProductChannelMapping $parentMapping) use (
                $variant,
            ): bool {
                $matches = $variant->channelMappings->filter(
                    fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id
                            === (int) $parentMapping->sales_channel_id
                        && trim((string) $mapping->external_product_id)
                            === trim((string) $parentMapping->external_product_id)
                        && ctype_digit(trim((string) $mapping->external_variation_id))
                        && (int) $mapping->external_variation_id > 0,
                );

                return $matches->count() === 1;
            });
        });
    }

    /**
     * Follow-up candidate missed by the original 000024 scan: the parent is
     * an exact generic/direct Size duplicate, but every trustworthy local
     * child option is blank. A multilingual remote preflight must supply the
     * missing SKU bijection before repair can write anything.
     */
    public function isComplementaryLanguageSizeRootCandidate(Product $product): bool
    {
        if (! $this->isSizeVariantRootCandidate($product)
            || ! $this->hasParentDuplicatedGenericAndSizeAxisEvidence($product)
        ) {
            return false;
        }

        return $this->localVariationOptionHints($product) !== []
            || $this->hasOnlyBlankLocalChildAxisEvidence($product);
    }

    /**
     * Queue only families that locally identify Size (or its known legacy
     * generic alias). A valid Color-only Woo family must never be converted
     * into permanent manual_review merely because it has variants.
     */
    private function hasLocalSizeAxisEvidence(Product $product): bool
    {
        $product->loadMissing('variantChildren');
        $declared = trim((string) data_get($product->masterData(), 'variant_attribute', ''));

        if ($declared !== ''
            && ! $this->variantOptions->isSizeAttribute($declared)
            && ! $this->legacySizeAxis->isLegacyGeneric($declared)
        ) {
            return false;
        }

        foreach ((array) data_get($product->masterData(), 'parameters', []) as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $kind = $this->localizedLocalTargetAxisKind($parameter);

            if ($kind === false
                || ($kind === null && (bool) ($parameter['variation'] ?? false))
            ) {
                return false;
            }
        }

        foreach ((array) data_get($product->attributes, 'woocommerce_attributes', []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $kind = $this->localizedLocalTargetAxisKind($attribute);

            if ($kind === false
                || ($kind === null && (bool) ($attribute['variation'] ?? false))
            ) {
                return false;
            }
        }

        $masterVariationAxes = collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && (bool) ($parameter['variation'] ?? false))
            ->map(fn (array $parameter): string => trim((string) (
                $parameter['name'] ?? $parameter['slug'] ?? ''
            )))
            ->filter()
            ->values();
        $wooVariationAxes = collect((array) data_get($product->attributes, 'woocommerce_attributes', []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (bool) ($attribute['variation'] ?? false))
            ->map(fn (array $attribute): string => trim((string) (
                $attribute['name'] ?? $attribute['slug'] ?? ''
            )))
            ->filter()
            ->values();
        $declaresConcreteSizeAxis = $this->variantOptions->isSizeAttribute($declared)
            || $masterVariationAxes->contains(fn (string $name): bool => $this->variantOptions->isSizeAttribute($name))
            || $wooVariationAxes->contains(fn (string $name): bool => $this->variantOptions->isSizeAttribute($name));
        $declaresGenericVariationAxis = $this->legacySizeAxis->isLegacyGeneric($declared)
            || $masterVariationAxes->contains(fn (string $name): bool => $this->legacySizeAxis->isLegacyGeneric($name))
            || $wooVariationAxes->contains(fn (string $name): bool => $this->legacySizeAxis->isLegacyGeneric($name));
        $hasUnrelatedVariationAxis = collect([$masterVariationAxes, $wooVariationAxes])
            ->flatten()
            ->contains(fn (mixed $name): bool => ! $this->variantOptions->isSizeAttribute((string) $name)
                && ! $this->legacySizeAxis->isLegacyGeneric((string) $name));

        if ($declaresConcreteSizeAxis) {
            if ($hasUnrelatedVariationAxis) {
                return false;
            }

            return ! $declaresGenericVariationAxis
                || $this->hasDuplicatedGenericAndSizeAxisEvidence($product)
                || $this->hasParentDuplicatedGenericAndSizeAxisEvidence($product)
                || $this->legacySizeAxis->recover($product, $product->variantChildren) !== null
                || $this->hasDictionaryBackedGenericSizeAxis($product);
        }

        // A generic `wariant` name alone is not proof of Size: old catalogs
        // also used it for Color. Accept it only when the existing resolver
        // proves one concrete Size axis from the parent/children options.
        return $this->legacySizeAxis->recover(
            $product,
            $product->variantChildren,
        ) !== null
            || $this->hasDictionaryBackedGenericSizeAxis($product)
            || $this->hasParentDuplicatedGenericAndSizeAxisEvidence($product);
    }

    /**
     * A damaged Woo family may have lost every local child option even though
     * the parent still stores the legacy variation axis and an informational
     * Size axis with exactly the same dictionary-backed values. This is safe
     * evidence for queueing only; repair still performs a full multilingual
     * remote preflight and requires a SKU/Size bijection before any PUT.
     */
    private function hasParentDuplicatedGenericAndSizeAxisEvidence(Product $product): bool
    {
        $declared = trim((string) data_get($product->masterData(), 'variant_attribute', ''));

        if ($declared !== '' && $this->localTargetAxisKind($declared) === null) {
            return false;
        }

        $sources = collect();

        foreach ((array) data_get($product->masterData(), 'parameters', []) as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $names = collect([
                $parameter['name'] ?? null,
                $parameter['name_pl'] ?? null,
                $parameter['name_en'] ?? null,
                $parameter['slug'] ?? null,
            ]);

            foreach ((array) ($parameter['translations'] ?? []) as $translation) {
                if (is_array($translation)) {
                    $names->push($translation['name'] ?? null);
                }
            }

            $names = $names
                ->map(fn (mixed $name): string => trim((string) $name))
                ->filter()
                ->values();
            $kinds = $names
                ->map(fn (string $name): ?string => $this->localTargetAxisKind($name));

            if ($kinds->filter()->isEmpty()) {
                if ((bool) ($parameter['variation'] ?? false)) {
                    return false;
                }

                continue;
            }

            if ($kinds->contains(fn (?string $kind): bool => $kind === null)
                || $kinds->unique()->count() !== 1
            ) {
                return false;
            }

            $kind = (string) $kinds->first();
            $values = collect([
                $parameter['value'] ?? null,
                $parameter['value_pl'] ?? null,
                $parameter['value_en'] ?? null,
            ]);

            foreach ((array) ($parameter['translations'] ?? []) as $translation) {
                if (is_array($translation)) {
                    $values->push($translation['value'] ?? null);
                }
            }

            $keySets = $values
                ->filter(fn (mixed $value): bool => $this->localOptionValues($value)
                    ->contains(fn (string $option): bool => trim($option) !== ''))
                ->map(fn (mixed $value): ?array => $this->exactLocalOptionKeys($value))
                ->values();

            if ($keySets->isEmpty()
                || $keySets->contains(fn (?array $keys): bool => $keys === null)
            ) {
                return false;
            }

            foreach ($keySets as $keys) {
                $sources->push([
                    'kind' => $kind,
                    'keys' => $keys,
                    'variation' => (bool) ($parameter['variation'] ?? false),
                ]);
            }
        }

        foreach ((array) data_get($product->attributes, 'woocommerce_attributes', []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $names = collect([
                $attribute['name'] ?? null,
                $attribute['name_pl'] ?? null,
                $attribute['name_en'] ?? null,
                $attribute['slug'] ?? null,
            ]);

            foreach ((array) ($attribute['translations'] ?? []) as $translation) {
                if (is_array($translation)) {
                    $names->push($translation['name'] ?? null);
                }
            }

            $names = $names
                ->map(fn (mixed $name): string => trim((string) $name))
                ->filter()
                ->values();
            $kinds = $names
                ->map(fn (string $name): ?string => $this->localTargetAxisKind($name));

            if ($kinds->filter()->isEmpty()) {
                if ((bool) ($attribute['variation'] ?? false)) {
                    return false;
                }

                continue;
            }

            if ($kinds->contains(fn (?string $kind): bool => $kind === null)
                || $kinds->unique()->count() !== 1
            ) {
                return false;
            }

            $kind = (string) $kinds->first();
            $values = collect([
                $attribute['options'] ?? null,
                $attribute['options_pl'] ?? null,
                $attribute['options_en'] ?? null,
            ]);

            foreach ((array) ($attribute['translations'] ?? []) as $translation) {
                if (is_array($translation)) {
                    $values->push(
                        $translation['options']
                            ?? $translation['option']
                            ?? $translation['value']
                            ?? null,
                    );
                }
            }

            $keySets = $values
                ->filter(fn (mixed $value): bool => $this->localOptionValues($value)
                    ->contains(fn (string $option): bool => trim($option) !== ''))
                ->map(fn (mixed $value): ?array => $this->exactLocalOptionKeys($value))
                ->values();

            if ($keySets->isEmpty()
                || $keySets->contains(fn (?array $keys): bool => $keys === null)
            ) {
                return false;
            }

            foreach ($keySets as $keys) {
                $sources->push([
                    'kind' => $kind,
                    'keys' => $keys,
                    'variation' => (bool) ($attribute['variation'] ?? false),
                ]);
            }
        }

        $generic = $sources->where('kind', 'generic')->values();
        $size = $sources->where('kind', 'size')->values();

        if ($generic->isEmpty()
            || ! $generic->contains(fn (array $source): bool => $source['variation'])
            || $size->isEmpty()
        ) {
            return false;
        }

        $genericSignatures = $generic
            ->map(fn (array $source): string => implode('|', $source['keys']))
            ->unique()
            ->values();
        $sizeSignatures = $size
            ->map(fn (array $source): string => implode('|', $source['keys']))
            ->unique()
            ->values();

        if ($genericSignatures->count() !== 1
            || $sizeSignatures->count() !== 1
            || $genericSignatures->first() !== $sizeSignatures->first()
        ) {
            return false;
        }

        $dictionaryKeys = $this->sizeOrder
            ->definitions()
            ->flatMap(fn (ProductParameterDefinition $definition): Collection => collect([
                ...(array) $definition->values,
                ...(array) $definition->values_en,
            ]))
            ->map(fn (mixed $option): string => $this->canonicalSizeOptionKey(
                ProductVariantAxisNameResolver::SIZE,
                (string) $option,
            ))
            ->filter()
            ->unique();

        $keys = explode('|', (string) $sizeSignatures->first());

        return $dictionaryKeys->isNotEmpty()
            && collect($keys)->diff($dictionaryKeys)->isEmpty();
    }

    /**
     * Distinguish genuinely erased child options from non-empty conflicts.
     * Only the former may be completed from another language's SKU bijection.
     */
    private function hasOnlyBlankLocalChildAxisEvidence(Product $product): bool
    {
        $product->loadMissing('variantChildren');

        if ($product->variantChildren->isEmpty()) {
            return false;
        }

        foreach ($product->variantChildren as $variant) {
            $sawTargetAxis = false;
            $declared = trim((string) data_get($variant->masterData(), 'variant_attribute', ''));

            if ($declared !== '') {
                if ($this->localTargetAxisKind($declared) === null) {
                    return false;
                }

                $sawTargetAxis = true;
            }

            $relationAxis = trim((string) data_get($variant->pivot?->metadata, 'variant_attribute', ''));
            $relationOption = trim((string) data_get($variant->pivot?->metadata, 'variant_option', ''));

            if ($relationAxis !== '') {
                if ($this->localTargetAxisKind($relationAxis) === null) {
                    return false;
                }

                $sawTargetAxis = true;
            }

            if ($relationOption !== '') {
                return false;
            }

            foreach ((array) data_get($variant->masterData(), 'parameters', []) as $parameter) {
                if (! is_array($parameter)) {
                    continue;
                }

                $names = collect([
                    $parameter['name'] ?? null,
                    $parameter['name_pl'] ?? null,
                    $parameter['name_en'] ?? null,
                    $parameter['slug'] ?? null,
                ]);

                foreach ((array) ($parameter['translations'] ?? []) as $translation) {
                    if (is_array($translation)) {
                        $names->push($translation['name'] ?? null);
                    }
                }

                $kinds = $names
                    ->map(fn (mixed $name): string => trim((string) $name))
                    ->filter()
                    ->map(fn (string $name): ?string => $this->localTargetAxisKind($name))
                    ->values();

                if ($kinds->filter()->isEmpty()) {
                    if ((bool) ($parameter['variation'] ?? false)) {
                        return false;
                    }

                    continue;
                }

                if ($kinds->contains(fn (?string $kind): bool => $kind === null)
                    || $kinds->unique()->count() !== 1
                ) {
                    return false;
                }

                $sawTargetAxis = true;
                $localizedValues = collect([
                    $parameter['value'] ?? null,
                    $parameter['value_pl'] ?? null,
                    $parameter['value_en'] ?? null,
                ]);

                foreach ((array) ($parameter['translations'] ?? []) as $translation) {
                    if (is_array($translation)) {
                        $localizedValues->push($translation['value'] ?? null);
                    }
                }

                if ($localizedValues
                    ->flatMap(fn (mixed $value): Collection => $this->localOptionValues($value))
                    ->contains(fn (string $value): bool => trim($value) !== '')
                ) {
                    return false;
                }
            }

            $wooAttributes = collect();

            foreach (['woocommerce_variation_attributes', 'woocommerce_attributes'] as $snapshot) {
                $wooAttributes = $wooAttributes->merge((array) data_get(
                    $variant->attributes,
                    $snapshot,
                    [],
                ));
            }

            foreach ($wooAttributes as $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }

                $names = collect([
                    $attribute['name'] ?? null,
                    $attribute['name_pl'] ?? null,
                    $attribute['name_en'] ?? null,
                    $attribute['slug'] ?? null,
                ]);

                foreach ((array) ($attribute['translations'] ?? []) as $translation) {
                    if (is_array($translation)) {
                        $names->push($translation['name'] ?? null);
                    }
                }

                $kinds = $names
                    ->map(fn (mixed $name): string => trim((string) $name))
                    ->filter()
                    ->map(fn (string $name): ?string => $this->localTargetAxisKind($name))
                    ->values();

                if ($kinds->isEmpty()
                    || $kinds->contains(fn (?string $kind): bool => $kind === null)
                    || $kinds->unique()->count() !== 1
                ) {
                    return false;
                }

                $sawTargetAxis = true;

                if (collect([
                    $attribute['option'] ?? null,
                    $attribute['options'] ?? null,
                ])->flatMap(fn (mixed $value): Collection => $this->localOptionValues($value))
                    ->contains(fn (string $value): bool => trim($value) !== '')
                ) {
                    return false;
                }
            }

            if (! $sawTargetAxis) {
                return false;
            }
        }

        return true;
    }

    /**
     * Two target-looking variation axes are safe only when every parent and
     * child snapshot proves that the generic axis is an exact duplicate of
     * Size. Merely naming the second axis `wariant`/`BLVariant` is not enough:
     * those historical names were also used for Color.
     */
    private function hasDuplicatedGenericAndSizeAxisEvidence(Product $product): bool
    {
        $parentSources = collect();

        foreach (collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && (bool) ($parameter['variation'] ?? false)) as $parameter) {
            $kind = $this->localTargetAxisKind((string) (
                $parameter['name'] ?? $parameter['slug'] ?? ''
            ));
            $keys = $this->exactLocalOptionKeys($parameter['value'] ?? null);

            if ($kind === null || $keys === null) {
                return false;
            }

            $parentSources->push(['kind' => $kind, 'keys' => $keys]);
        }

        foreach (collect((array) data_get($product->attributes, 'woocommerce_attributes', []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (bool) ($attribute['variation'] ?? false)) as $attribute) {
            $kind = $this->localTargetAxisKind((string) (
                $attribute['name'] ?? $attribute['slug'] ?? ''
            ));
            $keys = $this->exactLocalOptionKeys($attribute['options'] ?? null);

            if ($kind === null || $keys === null) {
                return false;
            }

            $parentSources->push(['kind' => $kind, 'keys' => $keys]);
        }

        $parentSignatures = [];

        foreach (['generic', 'size'] as $kind) {
            $signatures = $parentSources
                ->where('kind', $kind)
                ->map(fn (array $source): string => implode('|', $source['keys']))
                ->unique()
                ->values();

            if ($signatures->count() !== 1) {
                return false;
            }

            $parentSignatures[$kind] = (string) $signatures->first();
        }

        if ($parentSignatures['generic'] !== $parentSignatures['size']) {
            return false;
        }

        $parentKeys = explode('|', $parentSignatures['size']);
        $childKeys = [];

        foreach ($product->variantChildren as $variant) {
            $sources = collect();

            foreach (collect((array) data_get($variant->masterData(), 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter)) as $parameter) {
                $kind = $this->localTargetAxisKind((string) (
                    $parameter['name'] ?? $parameter['slug'] ?? ''
                ));

                if ($kind === null) {
                    if ((bool) ($parameter['variation'] ?? false)) {
                        return false;
                    }

                    continue;
                }

                $keys = $this->exactLocalOptionKeys($parameter['value'] ?? null);

                if ($keys === null || count($keys) !== 1) {
                    return false;
                }

                $sources->push(['kind' => $kind, 'key' => $keys[0]]);
            }

            foreach (collect((array) data_get(
                $variant->attributes,
                'woocommerce_variation_attributes',
                data_get($variant->attributes, 'woocommerce_attributes', []),
            ))->filter(fn (mixed $attribute): bool => is_array($attribute)) as $attribute) {
                $kind = $this->localTargetAxisKind((string) (
                    $attribute['name'] ?? $attribute['slug'] ?? ''
                ));
                $keys = $this->exactLocalOptionKeys($attribute['option'] ?? null);

                if ($kind === null || $keys === null || count($keys) !== 1) {
                    return false;
                }

                $sources->push(['kind' => $kind, 'key' => $keys[0]]);
            }

            $resolved = [];

            foreach (['generic', 'size'] as $kind) {
                $keys = $sources->where('kind', $kind)->pluck('key')->unique()->values();

                if ($keys->count() !== 1) {
                    return false;
                }

                $resolved[$kind] = (string) $keys->first();
            }

            if ($resolved['generic'] !== $resolved['size']) {
                return false;
            }

            $relationAxis = trim((string) data_get($variant->pivot?->metadata, 'variant_attribute', ''));
            $relationOption = trim((string) data_get($variant->pivot?->metadata, 'variant_option', ''));

            if (($relationAxis !== '' && $this->localTargetAxisKind($relationAxis) === null)
                || ($relationOption !== '' && $this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    $relationOption,
                ) !== $resolved['size'])
                || in_array($resolved['size'], $childKeys, true)
            ) {
                return false;
            }

            $childKeys[] = $resolved['size'];
        }

        sort($childKeys);
        sort($parentKeys);

        return $childKeys === $parentKeys;
    }

    private function localTargetAxisKind(string $name): ?string
    {
        if ($this->legacySizeAxis->isLegacyGeneric($name)) {
            return 'generic';
        }

        return $this->variantOptions->isSizeAttribute($name) ? 'size' : null;
    }

    /**
     * @return 'generic'|'size'|false|null False means localized names conflict;
     *                                     null means the row is not a target axis.
     */
    private function localizedLocalTargetAxisKind(array $row): string|false|null
    {
        $names = collect([
            $row['name'] ?? null,
            $row['name_pl'] ?? null,
            $row['name_en'] ?? null,
            $row['slug'] ?? null,
        ]);

        foreach ((array) ($row['translations'] ?? []) as $translation) {
            if (is_array($translation)) {
                $names->push($translation['name'] ?? null);
            }
        }

        $kinds = $names
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter()
            ->map(fn (string $name): ?string => $this->localTargetAxisKind($name))
            ->values();

        if ($kinds->filter()->isEmpty()) {
            return null;
        }

        if ($kinds->contains(fn (?string $kind): bool => $kind === null)
            || $kinds->unique()->count() !== 1
        ) {
            return false;
        }

        return (string) $kinds->first();
    }

    /**
     * A generic axis is accepted without a parallel Size parameter only when
     * every local representation independently proves the same 1:1 subset of
     * the configured Size dictionary. This deliberately rejects a historical
     * Color family stored under `wariant` or `BLVariant`.
     */
    private function hasDictionaryBackedGenericSizeAxis(Product $product): bool
    {
        return $this->dictionaryBackedGenericSizeOptionHints($product) !== null;
    }

    /** @return array<string,string>|null Canonical option key keyed by uppercase SKU. */
    private function dictionaryBackedGenericSizeOptionHints(Product $product): ?array
    {
        $declared = trim((string) data_get($product->masterData(), 'variant_attribute', ''));

        if ($declared !== '' && ! $this->legacySizeAxis->isLegacyGeneric($declared)) {
            return null;
        }

        if ($declared === '' && ! $this->hasParentDuplicatedGenericAndSizeAxisEvidence($product)) {
            return null;
        }

        $dictionaryKeys = $this->sizeOrder
            ->definitions()
            ->flatMap(fn (ProductParameterDefinition $definition): Collection => collect([
                ...(array) $definition->values,
                ...(array) $definition->values_en,
            ]))
            ->map(fn (mixed $option): string => $this->canonicalSizeOptionKey(
                ProductVariantAxisNameResolver::SIZE,
                (string) $option,
            ))
            ->filter()
            ->unique()
            ->values();

        if ($dictionaryKeys->isEmpty()) {
            return null;
        }

        $parentSets = [];
        $parentParameters = collect((array) data_get($product->masterData(), 'parameters', []))
            ->filter(fn (mixed $parameter): bool => is_array($parameter)
                && (bool) ($parameter['variation'] ?? false))
            ->values();

        if ($parentParameters->contains(fn (array $parameter): bool => ! $this->legacySizeAxis->isLegacyGeneric(
            (string) ($parameter['name'] ?? $parameter['slug'] ?? ''),
        )) || $parentParameters->count() > 1) {
            return null;
        }

        if ($parentParameters->count() === 1) {
            $keys = $this->exactLocalOptionKeys($parentParameters->first()['value'] ?? null);

            if ($keys === null) {
                return null;
            }

            $parentSets[] = $keys;
        }

        $parentWooAxes = collect((array) data_get($product->attributes, 'woocommerce_attributes', []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (bool) ($attribute['variation'] ?? false))
            ->values();

        if ($parentWooAxes->contains(fn (array $attribute): bool => ! $this->isGenericAttribute($attribute))
            || $parentWooAxes->count() > 1
        ) {
            return null;
        }

        if ($parentWooAxes->count() === 1) {
            $keys = $this->exactLocalOptionKeys($parentWooAxes->first()['options'] ?? null);

            if ($keys === null) {
                return null;
            }

            $parentSets[] = $keys;
        }

        if ($parentSets === []
            || collect($parentSets)->map(fn (array $keys): string => implode('|', $keys))->unique()->count() !== 1
        ) {
            return null;
        }

        $parentKeys = $parentSets[0];

        if (collect($parentKeys)->diff($dictionaryKeys)->isNotEmpty()) {
            return null;
        }

        $hints = [];
        $usedOptionKeys = [];

        foreach ($product->variantChildren as $variant) {
            $childDeclared = trim((string) data_get($variant->masterData(), 'variant_attribute', ''));
            $relationDeclared = trim((string) data_get($variant->pivot?->metadata, 'variant_attribute', ''));

            if (($childDeclared !== '' && ! $this->legacySizeAxis->isLegacyGeneric($childDeclared))
                || ($relationDeclared !== '' && ! $this->legacySizeAxis->isLegacyGeneric($relationDeclared))
            ) {
                return null;
            }

            $sets = [];
            $parameters = collect((array) data_get($variant->masterData(), 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter)
                    && (bool) ($parameter['variation'] ?? false))
                ->values();

            if ($parameters->contains(fn (array $parameter): bool => ! $this->legacySizeAxis->isLegacyGeneric(
                (string) ($parameter['name'] ?? $parameter['slug'] ?? ''),
            )) || $parameters->count() > 1) {
                return null;
            }

            if ($parameters->count() === 1) {
                $keys = $this->exactLocalOptionKeys($parameters->first()['value'] ?? null);

                if ($keys === null || count($keys) !== 1) {
                    return null;
                }

                $sets[] = $keys;
            }

            $wooRows = collect((array) data_get(
                $variant->attributes,
                'woocommerce_variation_attributes',
                data_get($variant->attributes, 'woocommerce_attributes', []),
            ))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->values();

            if ($wooRows->contains(fn (array $attribute): bool => ! $this->isGenericAttribute($attribute))
                || $wooRows->count() > 1
            ) {
                return null;
            }

            if ($wooRows->count() === 1) {
                $keys = $this->exactLocalOptionKeys($wooRows->first()['option'] ?? null);

                if ($keys === null || count($keys) !== 1) {
                    return null;
                }

                $sets[] = $keys;
            }

            if ($sets === []
                || collect($sets)->map(fn (array $keys): string => implode('|', $keys))->unique()->count() !== 1
            ) {
                return null;
            }

            $key = $sets[0][0];
            $sku = mb_strtoupper(trim((string) $variant->sku));

            if ($sku === ''
                || isset($hints[$sku])
                || ! $dictionaryKeys->contains($key)
                || isset($usedOptionKeys[$key])
            ) {
                return null;
            }

            $hints[$sku] = $key;
            $usedOptionKeys[$key] = true;
        }

        $childKeys = array_values($hints);
        sort($childKeys);

        return $childKeys === $parentKeys ? $hints : null;
    }

    /** @return list<string>|null */
    private function exactLocalOptionKeys(mixed $value): ?array
    {
        $keys = $this->localOptionValues($value)
            ->map(fn (string $option): string => $this->canonicalSizeOptionKey(
                ProductVariantAxisNameResolver::SIZE,
                $option,
            ))
            ->filter()
            ->values();

        if ($keys->isEmpty() || $keys->unique()->count() !== $keys->count()) {
            return null;
        }

        return $keys->sort()->values()->all();
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
        $children = $product->variantChildren;

        if ($children->isEmpty()) {
            return [];
        }

        $declared = trim((string) data_get($product->masterData(), 'variant_attribute', ''));
        $sizeAttribute = $this->variantOptions->isSizeAttribute($declared)
            ? $declared
            : $this->legacySizeAxis->recover($product, $children);

        if ($sizeAttribute === null || ! $this->variantOptions->isSizeAttribute($sizeAttribute)) {
            return $this->dictionaryBackedGenericSizeOptionHints($product) ?? [];
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

            $childDeclared = trim((string) data_get($child->masterData(), 'variant_attribute', ''));
            $relationDeclared = trim((string) data_get($child->pivot?->metadata, 'variant_attribute', ''));

            if (($childDeclared !== '' && $this->localTargetAxisKind($childDeclared) === null)
                || ($relationDeclared !== '' && $this->localTargetAxisKind($relationDeclared) === null)
            ) {
                return [];
            }

            $parameters = collect((array) data_get($child->masterData(), 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter))
                ->values();

            if ($parameters->contains(function (array $parameter): bool {
                $kind = $this->localizedLocalTargetAxisKind($parameter);

                return $kind === false
                    || ($kind === null && (bool) ($parameter['variation'] ?? false));
            })) {
                return [];
            }

            $rawOptions = $parameters
                ->filter(fn (array $parameter): bool => is_string(
                    $this->localizedLocalTargetAxisKind($parameter),
                ))
                ->flatMap(function (array $parameter): Collection {
                    $values = collect([
                        $parameter['value'] ?? null,
                        $parameter['value_pl'] ?? null,
                        $parameter['value_en'] ?? null,
                    ]);

                    foreach ((array) ($parameter['translations'] ?? []) as $translation) {
                        if (is_array($translation)) {
                            $values->push($translation['value'] ?? null);
                        }
                    }

                    return $values->flatMap(
                        fn (mixed $value): Collection => $this->localOptionValues($value),
                    );
                });

            $relationOption = trim((string) data_get($child->pivot?->metadata, 'variant_option', ''));

            if ($relationOption !== '') {
                $rawOptions->push($relationOption);
            }

            $wooRows = collect([
                ...(array) data_get($child->attributes, 'woocommerce_variation_attributes', []),
                ...(array) data_get($child->attributes, 'woocommerce_attributes', []),
            ])
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->values();

            if ($wooRows->contains(fn (array $attribute): bool => ! is_string(
                $this->localizedLocalTargetAxisKind($attribute),
            ))) {
                return [];
            }

            $wooRows
                ->each(function (array $attribute) use ($rawOptions): void {
                    collect([
                        $attribute['option'] ?? null,
                        $attribute['options'] ?? null,
                    ])->flatMap(fn (mixed $value): Collection => $this->localOptionValues($value))
                        ->filter(fn (string $option): bool => trim($option) !== '')
                        ->each(fn (string $option) => $rawOptions->push($option));
                });

            $keys = $rawOptions
                ->map(fn (mixed $option): string => $this->canonicalSizeOptionKey(
                    $sizeAttribute,
                    (string) $option,
                ))
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

    /**
     * Use only a complete, internally verified remote language as evidence for
     * another language whose child options are blank. The source language must
     * map every distinct non-empty SKU to every parent Size option exactly
     * once. Multiple complete languages must agree byte-for-byte after option
     * canonicalization or the whole repair stops before a write.
     *
     * @param  list<array{plan:array<string,mixed>,variations:list<array<string,mixed>>}>  $plans
     * @return array{hints:array<string,string>,error:?string}
     */
    private function completeRemoteVariationOptionHints(array $plans): array
    {
        $complete = collect();

        foreach ($plans as $entry) {
            if (($entry['plan']['status'] ?? 'unsafe') === 'unsafe') {
                continue;
            }

            $variations = collect((array) ($entry['variations'] ?? []))
                ->filter(fn (mixed $variation): bool => is_array($variation))
                ->values();
            $skus = $variations
                ->map(fn (array $variation): string => mb_strtoupper(trim((string) (
                    $variation['sku'] ?? ''
                ))))
                ->filter()
                ->values();
            $hints = collect((array) data_get($entry, 'plan.sku_option_keys', []))
                ->mapWithKeys(fn (mixed $option, mixed $sku): array => [
                    mb_strtoupper(trim((string) $sku)) => trim((string) $option),
                ])
                ->filter(fn (string $option, string $sku): bool => $sku !== '' && $option !== '')
                ->sortKeys();

            if ($variations->isEmpty()
                || $skus->count() !== $variations->count()
                || $skus->unique()->count() !== $skus->count()
                || $hints->count() !== $variations->count()
                || $hints->keys()->sort()->values()->all() !== $skus->sort()->values()->all()
                || $hints->values()->unique()->count() !== $hints->count()
            ) {
                continue;
            }

            $complete->push($hints->all());
        }

        if ($complete->isEmpty()) {
            return ['hints' => [], 'error' => null];
        }

        $expected = $complete->first();

        if ($complete->contains(fn (array $hints): bool => $hints !== $expected)) {
            return [
                'hints' => [],
                'error' => 'Wersje językowe przypisują te same SKU do różnych rozmiarów.',
            ];
        }

        return ['hints' => $expected, 'error' => null];
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

        // Imported aggregate parameters historically used several list
        // separators. A comma is a delimiter unless it directly joins two
        // digits, in which case it is a decimal comma (for example `38,5`).
        return collect(preg_split(
            '/\s*(?:\||;|\R|(?<!\d),|,(?!\d))\s*/u',
            trim((string) $value),
        ) ?: [])
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

                    if (! $this->ownsCurrentReservation($metadata, $token)) {
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

                    if (! $this->ownsCurrentReservation($metadata, $token)) {
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

    /** @param array<string,mixed> $metadata */
    private function ownsCurrentReservation(array $metadata, string $token): bool
    {
        return data_get($metadata, self::STATE_PATH.'.revision') === self::REVISION
            && data_get($metadata, self::STATE_PATH.'.status') === 'queued'
            && data_get($metadata, self::STATE_PATH.'.pending_token') === $token;
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

    private function isErpOwnedRoot(Product $product): bool
    {
        return ! $product->is_translation
            && $product->masterSource() === 'erp'
            && data_get($product->masterData(), 'product_type') !== 'variation'
            && ! $product->parentRelations->contains(
                fn ($relation): bool => $relation->relation_type === 'variant',
            );
    }

    private function isRepairableRoot(Product $product): bool
    {
        return $this->isWooOwnedRoot($product) || $this->isErpOwnedRoot($product);
    }

    /**
     * @return array{
     *   targets:list<array{integration:WordpressIntegration,sales_channel_id:int,external_product_id:string,language:string,is_primary:bool,discovered?:bool,contract?:bool}>,
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
            $requiredLanguages = collect([
                ...$integration->productImportLanguages(),
                ...$integration->productExportLanguages(),
            ])
                ->map(fn (mixed $language): string => $this->language($language))
                ->unique()
                ->values();
            $discovery = $this->discoverContractTranslationTargets(
                $integration,
                (int) $mapping->sales_channel_id,
                $externalProductId,
                $primaryLanguage,
                $requiredLanguages->all(),
                $requiredLanguages
                    ->reject(fn (string $language): bool => $language === $primaryLanguage)
                    ->values()
                    ->all(),
            );

            if ($discovery['error'] !== null) {
                return [
                    'targets' => $targets->all(),
                    'error' => $discovery['error'],
                    'retryable' => false,
                    'allow_full_export' => false,
                ];
            }

            $targets->push([
                'integration' => $integration,
                'sales_channel_id' => (int) $mapping->sales_channel_id,
                'external_product_id' => $externalProductId,
                'language' => $primaryLanguage,
                'is_primary' => true,
                'discovered' => false,
                'contract' => $discovery['contract'],
            ]);

            if ($discovery['contract']) {
                // The plugin contract is the only outbound translation
                // authority. Historical aliases outside that exact family
                // remain available for order routing but are not repair
                // targets and are demoted after full preflight.
                $targets->push(...$discovery['targets']);

                continue;
            }

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
                ->filter(fn (ProductChannelAlias $alias): bool => $alias->isOutboundSyncEnabled())
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
                        'discovered' => false,
                        'contract' => false,
                    ]);
                });
        }

        $conflictingTarget = $targets
            ->groupBy(fn (array $target): string => $target['integration']->id.'|'.$target['external_product_id'])
            ->first(fn (Collection $sameIdentity): bool => $sameIdentity
                ->pluck('language')
                ->map(fn (mixed $language): string => $this->language($language))
                ->unique()
                ->count() > 1);

        if ($conflictingTarget instanceof Collection) {
            return [
                'targets' => $targets->all(),
                'error' => 'Ten sam produkt WooCommerce ma sprzeczne lokalne języki aliasu i kontraktu; szeroki eksport nie zostanie uruchomiony.',
                'retryable' => false,
                'allow_full_export' => false,
            ];
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
                // A complete plugin contract with no ID for a configured
                // language proves that the translated Woo product simply does
                // not exist yet. Repairing the existing contract family is
                // safe; the normal follow-up export will create any missing
                // language from the now-canonical local Size axis.
                if ($integrationTargets->every(
                    fn (array $target): bool => (bool) ($target['contract'] ?? false),
                )) {
                    continue;
                }

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
     * Discover an already existing Polylang family only from the immutable
     * catalog identity that plugin 0.5.3 appends to the exact primary product
     * GET. Names and shared SKUs are deliberately not discovery authorities.
     * Every discovered counterpart is fetched and fully verified later by
     * remoteIdentity() before the first remote write.
     *
     * @param  list<string>  $requiredLanguages
     * @param  list<string>  $missingLanguages
     * @return array{
     *   contract:bool,
     *   targets:list<array{integration:WordpressIntegration,sales_channel_id:int,external_product_id:string,language:string,is_primary:bool,discovered:bool,contract:bool}>,
     *   error:?string
     * }
     */
    private function discoverContractTranslationTargets(
        WordpressIntegration $integration,
        int $salesChannelId,
        string $primaryExternalId,
        string $primaryLanguage,
        array $requiredLanguages,
        array $missingLanguages,
    ): array {
        $primary = $this->client->productById($integration, $primaryExternalId);
        $hasContract = array_key_exists('lemon_erp_catalog_contract', $primary)
            || array_key_exists('lemon_erp_language', $primary)
            || array_key_exists('lemon_erp_translations', $primary)
            || array_key_exists('lemon_erp_translation_group', $primary);

        if (! $hasContract) {
            return ['contract' => false, 'targets' => [], 'error' => null];
        }

        foreach ([
            'lemon_erp_catalog_contract',
            'lemon_erp_language',
            'lemon_erp_translations',
            'lemon_erp_translation_group',
        ] as $key) {
            if (! array_key_exists($key, $primary)) {
                return [
                    'contract' => true,
                    'targets' => [],
                    'error' => 'Główny produkt WooCommerce ma niepełny kontrakt katalogowy Lemon ERP; istniejące tłumaczenie nie zostanie zgadnięte.',
                ];
            }
        }

        if ((int) $primary['lemon_erp_catalog_contract'] !== 1) {
            return [
                'contract' => true,
                'targets' => [],
                'error' => 'Główny produkt WooCommerce ma nieobsługiwaną wersję kontraktu katalogowego Lemon ERP.',
            ];
        }

        $primaryLanguage = $this->language($primaryLanguage);
        $actualLanguage = $this->language($primary['lemon_erp_language']);
        $map = $this->translationIdMap($primary['lemon_erp_translations']);
        $requiredLanguages = collect($requiredLanguages)
            ->map(fn (mixed $language): string => $this->language($language))
            ->unique()
            ->sort()
            ->values();
        $missingLanguages = collect($missingLanguages)
            ->map(fn (mixed $language): string => $this->language($language))
            ->unique()
            ->values();

        $mapLanguages = collect(array_keys($map))->sort()->values();

        if ($actualLanguage !== $primaryLanguage
            || ($map[$primaryLanguage] ?? 0) !== (int) $primaryExternalId
            || count(array_unique(array_values($map))) !== count($map)
            || $mapLanguages->diff($requiredLanguages)->isNotEmpty()
            || trim((string) $primary['lemon_erp_translation_group']) !== $this->translationGroup('product', $map)
        ) {
            return [
                'contract' => true,
                'targets' => [],
                'error' => 'Kontrakt tłumaczeń głównego produktu WooCommerce nie odpowiada dokładnie skonfigurowanej rodzinie językowej.',
            ];
        }

        $targets = [];

        foreach ($missingLanguages->filter(
            fn (string $language): bool => array_key_exists($language, $map),
        ) as $language) {
            $externalId = (string) ($map[$language] ?? '');

            if (! ctype_digit($externalId) || (int) $externalId <= 0 || $externalId === $primaryExternalId) {
                return [
                    'contract' => true,
                    'targets' => [],
                    'error' => 'Kontrakt tłumaczeń WooCommerce nie zawiera jednoznacznego ID wersji '.mb_strtoupper($language).'.',
                ];
            }

            $targets[] = [
                'integration' => $integration,
                'sales_channel_id' => $salesChannelId,
                'external_product_id' => $externalId,
                'language' => $language,
                'is_primary' => false,
                'discovered' => true,
                'contract' => true,
            ];
        }

        return ['contract' => true, 'targets' => $targets, 'error' => null];
    }

    /**
     * Prove the one recoverable shape in which the translated parent exists
     * but was historically created as `simple`: all parent Polylang contract
     * identities are reciprocal, the primary variable family is a complete
     * local/remote bijection, and the simple translation has no children that
     * could be overwritten or orphaned.
     *
     * @param  list<array<string,mixed>>  $plans
     * @return list<array{language:string,external_product_id:string}>
     */
    private function recoverableEmptyTranslationTargets(Product $product, array $plans): array
    {
        $entries = collect($plans);
        $unsafe = $entries->filter(fn (array $entry): bool => data_get(
            $entry,
            'plan.status',
        ) === 'unsafe');
        $emptyTranslations = $unsafe->filter(fn (array $entry): bool => in_array(
            data_get($entry, 'plan.reason'),
            [
                'Produkt nie jest produktem wariantowym.',
                'Rodzina nie ma wariantów do jednoznacznego przypisania.',
            ],
            true,
        ));

        if ($emptyTranslations->isEmpty() || $emptyTranslations->count() !== $unsafe->count()) {
            return [];
        }

        $localHandoff = (array) data_get(
            $product->masterData(),
            self::STATE_PATH,
            [],
        );

        foreach ($entries->groupBy(fn (array $entry): int => (int) $entry['target']['integration']->id) as $integrationEntries) {
            $integrationEmpty = $integrationEntries->filter(
                fn (array $entry): bool => $emptyTranslations->contains($entry),
            );

            if ($integrationEmpty->isEmpty()) {
                continue;
            }

            $primary = $integrationEntries->first(
                fn (array $entry): bool => (bool) data_get($entry, 'target.is_primary', false),
            );
            $expectedParents = $integrationEntries
                ->mapWithKeys(fn (array $entry): array => [
                    $this->language($entry['target']['language']) => (int) ($entry['parent']['id'] ?? 0),
                ])
                ->sortKeys()
                ->all();

            if (! is_array($primary)
                || data_get($primary, 'plan.status') === 'unsafe'
                || (string) data_get($primary, 'parent.type') !== 'variable'
                || (array) ($primary['variations'] ?? []) === []
                || $integrationEntries->contains(fn (array $entry): bool => ! (bool) data_get(
                    $entry,
                    'target.contract',
                    false,
                ))
            ) {
                return [];
            }

            $parentGroups = collect();

            foreach ($integrationEntries as $entry) {
                $language = $this->language($entry['target']['language']);

                if ($this->contractItemError(
                    $entry['parent'],
                    $language,
                    'product',
                    $expectedParents,
                ) !== null) {
                    return [];
                }

                $parentGroups->push(trim((string) data_get(
                    $entry,
                    'parent.lemon_erp_translation_group',
                    '',
                )));
            }

            if ($parentGroups->contains('') || $parentGroups->unique()->count() !== 1) {
                return [];
            }

            if ($integrationEmpty->contains(fn (array $entry): bool =>
                (bool) data_get($entry, 'target.is_primary', false)
                || ! in_array((string) data_get($entry, 'parent.type'), ['simple', 'variable'], true)
                || (array) ($entry['variations'] ?? []) !== []
            )) {
                return [];
            }

            // A variable translated parent without children is recoverable
            // only when this service itself previously authorized and marked
            // the canonical simple->variable export hand-off. This recognizes
            // an interrupted rebuild without widening the exception to an
            // arbitrary damaged variable family.
            if ($integrationEmpty->contains(fn (array $entry): bool =>
                (string) data_get($entry, 'parent.type') === 'variable'
            ) && (! self::isSynchronizedRevision($localHandoff['revision'] ?? null)
                || blank($localHandoff['canonical_full_export_handoff_at'] ?? null)
            )) {
                return [];
            }

            $primaryExternalId = trim((string) data_get(
                $primary,
                'target.external_product_id',
                '',
            ));
            $localParentSku = mb_strtoupper(trim((string) $product->sku));
            $remoteParentSku = mb_strtoupper(trim((string) data_get(
                $primary,
                'parent.sku',
                '',
            )));
            $syntheticParentSku = $primaryExternalId !== ''
                && $localParentSku === 'WC-B2C-PARENT-'.mb_strtoupper($primaryExternalId);
            $localSkus = $product->variantChildren
                ->pluck('sku')
                ->map(fn (mixed $sku): string => mb_strtoupper(trim((string) $sku)))
                ->filter()
                ->sort()
                ->values()
                ->all();
            $primarySkus = collect((array) ($primary['variations'] ?? []))
                ->pluck('sku')
                ->map(fn (mixed $sku): string => mb_strtoupper(trim((string) $sku)))
                ->filter()
                ->sort()
                ->values()
                ->all();
            $remoteVariationIds = collect((array) ($primary['variations'] ?? []))
                ->pluck('id')
                ->map(fn (mixed $id): string => trim((string) $id))
                ->filter()
                ->unique()
                ->sort(SORT_NATURAL)
                ->values()
                ->all();
            $mappedVariationIds = $this->primaryMappedVariationIds($product, $primary);

            if (($remoteParentSku !== $localParentSku && ! $syntheticParentSku)
                || $localSkus === []
                || ($primarySkus !== $localSkus
                    && ($mappedVariationIds === [] || $mappedVariationIds !== $remoteVariationIds))
            ) {
                return [];
            }
        }

        return $emptyTranslations
            ->map(fn (array $entry): array => [
                'language' => $this->language($entry['target']['language']),
                'external_product_id' => trim((string) $entry['target']['external_product_id']),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{language:string,external_product_id:string}>  $targets
     */
    private function markCanonicalFullExportHandoff(Product $product, array $targets): void
    {
        DB::transaction(function () use ($product, $targets): void {
            $root = Product::query()->lockForUpdate()->find($product->id);

            if (! $root instanceof Product) {
                throw new RuntimeException('Rodzina ERP zniknęła przed przekazaniem odbudowy tłumaczenia.');
            }

            $attributes = (array) $root->attributes;
            $master = $root->masterData();
            data_set($master, self::STATE_PATH, [
                'revision' => self::REVISION,
                'canonical_full_export_handoff_at' => now()->toISOString(),
                'rebuild_simple_translations' => $targets,
            ]);
            $attributes['master'] = $master;
            $root->forceFill(['attributes' => $attributes])->save();
        }, 3);
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
            $primaryExternalId = trim((string) data_get($primary, 'target.external_product_id', ''));
            $localParentSku = mb_strtoupper(trim((string) $product->sku));
            $remoteParentSku = mb_strtoupper(trim((string) data_get($primary, 'parent.sku', '')));
            $syntheticParentSku = $primaryExternalId !== ''
                && $localParentSku === 'WC-B2C-PARENT-'.mb_strtoupper($primaryExternalId);

            if (! is_array($primary)
                || ($remoteParentSku !== $localParentSku && ! $syntheticParentSku)
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
            $remoteVariationIds = collect($primary['variations'])
                ->pluck('id')
                ->map(fn (mixed $id): string => trim((string) $id))
                ->filter()
                ->unique()
                ->sort(SORT_NATURAL)
                ->values()
                ->all();
            $mappedVariationIds = $this->primaryMappedVariationIds($product, $primary);
            $mappedIdentity = $mappedVariationIds !== []
                && $mappedVariationIds === $remoteVariationIds;

            if (($localSkus === [] || $primarySkus !== $localSkus) && ! $mappedIdentity) {
                return ['contract' => true, 'error' => 'Polskie warianty nie odpowiadają dokładnie wariantom rodziny ERP.'];
            }

            $variationRecords = collect();

            foreach ($integrationPlans as $entry) {
                $language = $this->language($entry['target']['language']);

                foreach ($entry['variations'] as $variation) {
                    foreach ([
                        'lemon_erp_catalog_contract',
                        'lemon_erp_language',
                        'lemon_erp_translations',
                        'lemon_erp_translation_group',
                        'lemon_erp_parent_translations',
                        'lemon_erp_parent_translation_group',
                    ] as $key) {
                        if (! array_key_exists($key, $variation)) {
                            return ['contract' => true, 'error' => 'Wariant #'.($variation['id'] ?? '?').' ma niepełny kontrakt tłumaczeń.'];
                        }
                    }

                    if ((int) $variation['lemon_erp_catalog_contract'] !== 1) {
                        return ['contract' => true, 'error' => 'Wariant #'.($variation['id'] ?? '?').' ma nieobsługiwaną wersję kontraktu katalogowego.'];
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

                    $parentMap = $this->translationIdMap($variation['lemon_erp_parent_translations']);
                    $expectedParentMap = $expectedParents;
                    ksort($expectedParentMap);

                    if ($parentMap !== $expectedParentMap) {
                        return ['contract' => true, 'error' => "Wariant #{$id} ma niespójną mapę tłumaczeń rodziców."];
                    }

                    if (trim((string) $variation['lemon_erp_parent_translation_group']) !== $parentGroup) {
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

    /**
     * Return the exact primary Woo variation IDs already persisted for every
     * ERP child. A missing, duplicate or ambiguous mapping invalidates the
     * whole proof; callers must not use a partial set.
     *
     * @param  array<string,mixed>  $primary
     * @return list<string>
     */
    private function primaryMappedVariationIds(Product $product, array $primary): array
    {
        $salesChannelId = (int) data_get($primary, 'target.integration.sales_channel_id', 0);
        $externalProductId = trim((string) data_get(
            $primary,
            'target.external_product_id',
            '',
        ));
        $children = $product->variantChildren;

        if ($salesChannelId <= 0 || $externalProductId === '' || $children->isEmpty()) {
            return [];
        }

        $ids = $children->map(function (Product $child) use (
            $salesChannelId,
            $externalProductId,
        ): ?string {
            $matches = $child->channelMappings
                ->filter(fn (ProductChannelMapping $mapping): bool =>
                    (int) $mapping->sales_channel_id === $salesChannelId
                    && trim((string) $mapping->external_product_id) === $externalProductId
                    && trim((string) $mapping->external_variation_id) !== '')
                ->values();

            if ($matches->count() !== 1) {
                return null;
            }

            return trim((string) $matches->first()->external_variation_id);
        });

        if ($ids->contains(null)
            || $ids->count() !== $children->count()
            || $ids->unique()->count() !== $ids->count()
        ) {
            return [];
        }

        return $ids
            ->map(fn (mixed $id): string => (string) $id)
            ->sort(SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * A legacy catalog without the reciprocal plugin contract may be matched
     * by SKU, but it may never be repaired through identities that the next
     * full export would not reuse. Require an exact local mapping/alias for
     * every live variation ID before the first PUT.
     *
     * @param  list<array<string,mixed>>  $plans
     */
    private function legacyMappedIdentityError(Product $product, array $plans): ?string
    {
        foreach ($plans as $entry) {
            $target = $entry['target'];
            $language = $this->language($target['language']);
            $parentId = trim((string) $target['external_product_id']);
            $channelId = (int) $target['sales_channel_id'];
            $remoteById = collect($entry['variations'])
                ->filter(fn (mixed $variation): bool => is_array($variation))
                ->keyBy(fn (array $variation): string => trim((string) ($variation['id'] ?? '')));
            $claimedIds = collect();

            foreach ($product->variantChildren as $variant) {
                if ((bool) $target['is_primary']) {
                    $identities = $variant->channelMappings
                        ->filter(fn (ProductChannelMapping $mapping): bool => (int) $mapping->sales_channel_id === $channelId
                            && trim((string) $mapping->external_product_id) === $parentId
                            && ctype_digit(trim((string) $mapping->external_variation_id))
                            && (int) $mapping->external_variation_id > 0)
                        ->map(fn (ProductChannelMapping $mapping): string => trim((string) $mapping->external_variation_id));
                } else {
                    $identities = $variant->channelAliases
                        ->filter(fn (ProductChannelAlias $alias): bool => (int) $alias->sales_channel_id === $channelId
                            && trim((string) $alias->external_product_id) === $parentId
                            && $this->language($alias->language) === $language
                            && $alias->isOutboundSyncEnabled()
                            && ctype_digit(trim((string) $alias->external_variation_id))
                            && (int) $alias->external_variation_id > 0)
                        ->map(fn (ProductChannelAlias $alias): string => trim((string) $alias->external_variation_id));
                }

                $identities = $identities->unique()->values();

                if ($identities->count() !== 1) {
                    return sprintf(
                        'WooCommerce %s #%s: SKU %s nie ma dokładnie jednego zachowywanego ID wariacji w mapowaniu ERP.',
                        mb_strtoupper($language),
                        $parentId,
                        $variant->sku,
                    );
                }

                $variationId = (string) $identities->first();
                $remote = $remoteById->get($variationId);
                $localSku = mb_strtoupper(trim((string) $variant->sku));
                $remoteSku = mb_strtoupper(trim((string) data_get($remote, 'sku', '')));

                if (! is_array($remote) || $localSku === '' || $remoteSku !== $localSku) {
                    return sprintf(
                        'WooCommerce %s #%s: mapowanie wariacji #%s nie wskazuje dokładnie SKU %s.',
                        mb_strtoupper($language),
                        $parentId,
                        $variationId,
                        $variant->sku,
                    );
                }

                $claimedIds->push($variationId);
            }

            if ($claimedIds->count() !== $remoteById->count()
                || $claimedIds->unique()->count() !== $claimedIds->count()
                || $claimedIds->sort()->values()->all() !== $remoteById->keys()
                    ->map(fn (mixed $id): string => (string) $id)
                    ->sort()
                    ->values()
                    ->all()
            ) {
                return sprintf(
                    'WooCommerce %s #%s: lokalne ID wariacji nie tworzą bijekcji z rodziną zdalną.',
                    mb_strtoupper($language),
                    $parentId,
                );
            }
        }

        return null;
    }

    /** @param array<string,mixed> $item @param array<string,int> $expectedMap */
    private function contractItemError(
        array $item,
        string $language,
        string $kind,
        array $expectedMap,
    ): ?string {
        foreach ([
            'lemon_erp_catalog_contract',
            'lemon_erp_language',
            'lemon_erp_translations',
            'lemon_erp_translation_group',
        ] as $key) {
            if (! array_key_exists($key, $item)) {
                return 'Rodzic ma niepełny kontrakt tłumaczeń Lemon ERP.';
            }
        }

        if ((int) $item['lemon_erp_catalog_contract'] !== 1) {
            return 'Rodzic ma nieobsługiwaną wersję kontraktu katalogowego Lemon ERP.';
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
     * A partially repaired parent can already contain only Size while some
     * children still reference the old positive global attribute ID. Readding
     * that source axis with the child's raw option is unsafe: Woo may expose a
     * term slug (`s-m`) on the child while the parent API requires its name
     * (`S/M`). Resolve every such option against the existing taxonomy first;
     * missing or ambiguous terms stop the repair before any mutation.
     *
     * @param  array{integration:WordpressIntegration,language:string}  $target
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     * @return array<int,array<string,string>> Attribute ID => raw option key => exact term name.
     */
    private function resolveChildOnlyGlobalAxisOptions(
        array $target,
        array $parent,
        array $variations,
    ): array {
        $parentIds = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): int => (int) ($attribute['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->unique();
        $childOptions = collect($variations)
            ->flatMap(fn (array $variation): Collection => collect((array) ($variation['attributes'] ?? [])))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (int) ($attribute['id'] ?? 0) > 0
                && ! $parentIds->contains((int) ($attribute['id'] ?? 0)))
            ->groupBy(fn (array $attribute): int => (int) $attribute['id'])
            ->map(fn (Collection $attributes): Collection => $attributes
                ->map(fn (array $attribute): string => trim((string) ($attribute['option'] ?? '')))
                ->filter()
                ->unique(fn (string $option): string => $this->optionKey($option))
                ->values());

        if ($childOptions->isEmpty()) {
            return [];
        }

        /** @var WordpressIntegration $integration */
        $integration = $target['integration'];
        $language = $this->language($target['language']);
        $resolved = [];

        foreach ($childOptions as $attributeId => $options) {
            $terms = collect($this->client->globalProductAttributeTermsById(
                $integration,
                (int) $attributeId,
                $language,
            ));

            if ($terms->isEmpty()) {
                $terms = collect($this->client->globalProductAttributeTermsById(
                    $integration,
                    (int) $attributeId,
                    null,
                ));
            }

            $usedTermIds = [];

            foreach ($options as $option) {
                $rawKey = $this->optionKey((string) $option);
                $matches = $terms
                    ->filter(fn (mixed $term): bool => is_array($term)
                        && (int) ($term['id'] ?? 0) > 0)
                    ->filter(fn (array $term): bool => $this->optionKey(
                        (string) ($term['name'] ?? ''),
                    ) === $rawKey || $this->optionKey(
                        (string) ($term['slug'] ?? ''),
                    ) === $rawKey)
                    ->unique(fn (array $term): int => (int) $term['id'])
                    ->values();

                if ($rawKey === '' || $matches->count() !== 1) {
                    throw new RuntimeException(
                        "Stara globalna oś #{$attributeId} nie ma jednoznacznego istniejącego termu dla wartości {$option}.",
                    );
                }

                $termId = (int) ($matches->first()['id'] ?? 0);

                if ($termId <= 0 || isset($usedTermIds[$termId])) {
                    throw new RuntimeException(
                        "Stara globalna oś #{$attributeId} nie mapuje wartości dzieci 1:1 na istniejące termy.",
                    );
                }

                $usedTermIds[$termId] = true;

                $termName = trim((string) ($matches->first()['name'] ?? ''));

                if ($termName === '') {
                    throw new RuntimeException(
                        "Stara globalna oś #{$attributeId} zwróciła pustą nazwę termu dla wartości {$option}.",
                    );
                }

                $resolved[(int) $attributeId][$rawKey] = $termName;
            }
        }

        return $resolved;
    }

    /** @param array<string,mixed> $plan */
    private function requiresChildOnlyAxisResolution(array $plan): bool
    {
        return ($plan['status'] ?? null) === 'unsafe'
            && str_contains(
                (string) ($plan['reason'] ?? ''),
                'istnieje tylko na dzieciach',
            );
    }

    /**
     * Resolve only an existing global Size taxonomy and existing terms. This
     * is deliberately read-only: an ambiguous/missing taxonomy goes to manual
     * review instead of creating another WooCommerce attribute.
     *
     * @param  array{integration:WordpressIntegration,language:string}  $target
     * @param  array{ordered_options:list<string>,canonical_options:list<string>}  $plan
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

        $canonicalOptions = (array) ($plan['canonical_options'] ?? $plan['ordered_options']);
        $resolvedOptions = collect($canonicalOptions)
            ->map(function (string $canonicalOption, int $index) use ($terms, $plan): string {
                $canonicalKey = $this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    $canonicalOption,
                );
                $localizedOption = trim((string) ($plan['ordered_options'][$index] ?? $canonicalOption));
                $candidateKeys = collect([$canonicalOption, $localizedOption])
                    ->map(fn (string $option): string => $this->optionKey($option))
                    ->filter()
                    ->unique();
                $matches = $terms
                    ->filter(fn (mixed $term): bool => is_array($term) && (int) ($term['id'] ?? 0) > 0)
                    ->filter(function (array $term) use ($candidateKeys, $canonicalKey): bool {
                        $name = (string) ($term['name'] ?? '');
                        $slug = (string) ($term['slug'] ?? '');

                        return $candidateKeys->contains($this->optionKey($name))
                            || $candidateKeys->contains($this->optionKey($slug))
                            || $this->canonicalSizeOptionKey(
                                ProductVariantAxisNameResolver::SIZE,
                                $name,
                            ) === $canonicalKey;
                    })
                    ->unique(fn (array $term): int => (int) $term['id'])
                    ->values();

                if ($matches->count() !== 1) {
                    throw new RuntimeException(
                        "Istniejąca globalna taksonomia nie zawiera jednoznacznej wartości {$localizedOption}.",
                    );
                }

                return trim((string) ($matches->first()['name'] ?? $localizedOption)) ?: $localizedOption;
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
     * Normalize only response representations already proven by the existing
     * target-language taxonomy. This makes a completed repair idempotent while
     * preserving the untouched remote parent for rollback/protected hashing.
     *
     * @param  array{integration:WordpressIntegration,language:string}  $target
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     * @param  array<string,string>  $variationOptionHints
     * @return array<string,mixed>
     */
    private function normalizeExistingParentSizeAxisForPlan(
        array $target,
        array $parent,
        array $variations,
        array $variationOptionHints,
    ): array {
        $attributes = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->values();
        $sizeAttributes = $attributes
            ->filter(fn (array $attribute): bool => $this->isCanonicalGlobalSizeAttribute($attribute))
            ->values();

        if ($sizeAttributes->count() === 1) {
            $size = (array) $sizeAttributes->first();
            $sizeIndex = $attributes->search(fn (array $attribute): bool => $this->sameAttribute(
                $attribute,
                $size,
            ));

            if ((int) ($size['id'] ?? 0) > 0 && $sizeIndex !== false) {
                $size['options'] = $this->orderedExistingSizeOptionNames(
                    (array) ($size['options'] ?? []),
                );
                $attributes->put($sizeIndex, $size);
            }
        }

        $parent['attributes'] = $attributes->values()->all();
        $targetAxesById = $attributes
            ->filter(fn (array $attribute): bool => (int) ($attribute['id'] ?? 0) > 0
                && ($this->isGenericAttribute($attribute) || $this->isSizeAttribute($attribute)))
            ->groupBy(fn (array $attribute): int => (int) $attribute['id'])
            ->filter(fn (Collection $axes): bool => $axes->count() === 1)
            ->map(fn (Collection $axes): array => (array) $axes->first());
        $childTargetAxisIds = collect($variations)
            ->flatMap(fn (array $variation): Collection => collect((array) ($variation['attributes'] ?? [])))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (int) ($attribute['id'] ?? 0) > 0)
            ->map(fn (array $attribute): int => (int) $attribute['id'])
            ->filter(fn (int $id): bool => $targetAxesById->has($id))
            ->unique()
            ->values();

        /** @var WordpressIntegration $integration */
        $integration = $target['integration'];
        $language = $this->language($target['language']);
        $originalDefaults = collect((array) ($parent['default_attributes'] ?? []))
            ->filter(fn (mixed $default): bool => is_array($default))
            ->values();
        $targetDefaultCount = $originalDefaults
            ->filter(fn (array $default): bool => $targetAxesById->has(
                (int) ($default['id'] ?? 0),
            ))
            ->count();
        $ignoredLegacyDefault = false;
        $defaults = $originalDefaults
            ->map(function (array $default) use (
                $integration,
                $language,
                $targetAxesById,
                $childTargetAxisIds,
                $targetDefaultCount,
                $variations,
                $variationOptionHints,
                &$ignoredLegacyDefault,
            ): array {
                $attributeId = (int) ($default['id'] ?? 0);
                $axis = $targetAxesById->get($attributeId);

                if ($attributeId <= 0 || ! is_array($axis)) {
                    return $default;
                }

                if ($childTargetAxisIds->isNotEmpty()
                    && ! $childTargetAxisIds->contains($attributeId)
                ) {
                    $ignoredLegacyDefault = true;

                    return [];
                }

                $rawOption = trim((string) ($default['option'] ?? ''));

                if ($rawOption === '') {
                    return $default;
                }

                try {
                    $default['option'] = $this->resolveExistingTargetAxisDefaultTermName(
                        $integration,
                        $language,
                        $axis,
                        $rawOption,
                    );
                } catch (DomainException $exception) {
                    // This default belongs to the legacy generic Size axis
                    // that a successful plan removes. If its historical term
                    // identity can no longer be proven, dropping only this
                    // default from the planning copy is safer than blocking an
                    // otherwise exact SKU -> Size child repair. The untouched
                    // remote parent remains available for rollback, and every
                    // child/target taxonomy still has to pass the full
                    // multilingual preflight before the first PUT. Canonical
                    // Size defaults continue to fail closed.
                    $rawKey = $this->canonicalSizeOptionKey(
                        ProductVariantAxisNameResolver::SIZE,
                        $rawOption,
                    );
                    $matchingAxisOptions = collect((array) ($axis['options'] ?? []))
                        ->filter(fn (mixed $option): bool => $rawKey !== ''
                            && $this->canonicalSizeOptionKey(
                                ProductVariantAxisNameResolver::SIZE,
                                (string) $option,
                            ) === $rawKey)
                        ->values();
                    $axisHasNoConcreteOptions = collect((array) ($axis['options'] ?? []))
                        ->map(fn (mixed $option): string => trim((string) $option))
                        ->filter()
                        ->isEmpty();
                    $exactChildAssignmentProof = $axisHasNoConcreteOptions
                        && $this->legacyDefaultMatchesExactChildAssignments(
                            $axis,
                            $rawKey,
                            $variations,
                            $variationOptionHints,
                        );

                    if ($this->isGenericAttribute($axis)
                        && $attributeId > 0
                        && ! $this->isCanonicalGlobalSizeAttribute($axis)
                        && (bool) ($axis['variation'] ?? false)
                        && $targetDefaultCount === 1
                        && $rawKey !== ''
                        && ($matchingAxisOptions->count() === 1 || $exactChildAssignmentProof)
                    ) {
                        $ignoredLegacyDefault = true;

                        return [];
                    }

                    throw $exception;
                }

                return $default;
            })
            ->filter(fn (array $default): bool => $default !== [])
            ->all();
        $parent['default_attributes'] = $defaults;

        if ($ignoredLegacyDefault) {
            // Phase one keeps the exact preflight defaults while both the old
            // and target axes are enabled. Only the final parent PUT removes
            // the unresolvable default together with its legacy generic axis.
            $parent['_lemon_transitional_default_attributes'] = $originalDefaults->all();
        }

        return $parent;
    }

    /**
     * Woo/Polylang removes an empty global informational attribute when a
     * translated parent is saved. Such a row carries no selected term and is
     * not visible through the Store API, but keeping it in the preflight
     * snapshot makes both final verification and rollback impossible. Remove
     * only placeholders that are provably inert before planning: a unique
     * positive global ID, no options, not a variation/Size axis and no parent
     * default or child reference. Primary-language rows remain byte-for-byte
     * protected because Woo preserves them differently.
     *
     * @param  array{language:string}  $target
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     * @return array<string,mixed>
     */
    private function withoutInertTranslatedGlobalAttributePlaceholders(
        array $target,
        array $parent,
        array $variations,
    ): array {
        return $this->withoutProvenInertGlobalAttributePlaceholders(
            $parent,
            $variations,
            $this->inertTranslatedGlobalAttributePlaceholderIds(
                $target,
                $parent,
                $variations,
            ),
        );
    }

    /**
     * @param  array{language:string}  $target
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     * @return list<int>
     */
    private function inertTranslatedGlobalAttributePlaceholderIds(
        array $target,
        array $parent,
        array $variations,
    ): array {
        return $this->language($target['language']) === 'pl'
            ? []
            : $this->inertGlobalAttributePlaceholderIds($parent, $variations);
    }

    /**
     * Recheck the preflight allowlist against each live response. This accepts
     * an eligible row whether Woo retained it or removed it, but never masks a
     * nonempty/reference change or a newly empty attribute that was not inert
     * in the original preflight.
     *
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     * @param  list<int>  $provenIds
     * @return array<string,mixed>
     */
    private function withoutProvenInertGlobalAttributePlaceholders(
        array $parent,
        array $variations,
        array $provenIds,
    ): array {
        $provenIds = collect($provenIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($provenIds === []) {
            return $parent;
        }

        $stillInertIds = array_values(array_intersect(
            $provenIds,
            $this->inertGlobalAttributePlaceholderIds($parent, $variations),
        ));

        if ($stillInertIds === []) {
            return $parent;
        }

        $attributes = collect((array) ($parent['attributes'] ?? []))->values();

        if ($attributes->contains(fn (mixed $attribute): bool => ! is_array($attribute))) {
            return $parent;
        }

        $parent['attributes'] = $attributes
            ->reject(fn (mixed $attribute): bool => is_array($attribute)
                && in_array(
                    (int) ($attribute['id'] ?? 0),
                    $stillInertIds,
                    true,
                ))
            ->values()
            ->all();

        return $parent;
    }

    /**
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     * @return list<int>
     */
    private function inertGlobalAttributePlaceholderIds(array $parent, array $variations): array
    {
        if (! array_key_exists('attributes', $parent)
            || ! is_array($parent['attributes'])
            || ! array_is_list($parent['attributes'])
            || ! array_key_exists('default_attributes', $parent)
            || ! is_array($parent['default_attributes'])
            || ! array_is_list($parent['default_attributes'])
            || ! array_is_list($variations)
        ) {
            return [];
        }

        $attributes = collect($parent['attributes'])->values();
        $defaults = collect($parent['default_attributes'])->values();
        $variationRows = collect($variations)->values();

        if (! $attributes->every(fn (mixed $attribute): bool => is_array($attribute)
                && $this->isStrictWooParentAttributeRow($attribute))
            || ! $defaults->every(fn (mixed $default): bool => is_array($default)
                && $this->isStrictWooAttributeReferenceRow($default))
            || ! $variationRows->every(fn (mixed $variation): bool => is_array($variation)
                && array_key_exists('attributes', $variation)
                && is_array($variation['attributes'])
                && array_is_list($variation['attributes']))
        ) {
            return [];
        }

        $childAttributes = $variationRows
            ->flatMap(fn (array $variation): Collection => collect(
                (array) ($variation['attributes'] ?? []),
            )->values())
            ->values();

        if (! $childAttributes->every(fn (mixed $attribute): bool => is_array($attribute)
            && $this->isStrictWooAttributeReferenceRow($attribute))) {
            return [];
        }

        $idCounts = $attributes
            ->map(fn (array $attribute): int => (int) ($attribute['id'] ?? 0))
            ->filter(fn (int $id): bool => $id > 0)
            ->countBy();
        $inertIds = $attributes
            ->filter(function (array $attribute) use (
                $idCounts,
                $defaults,
                $childAttributes,
            ): bool {
                $id = (int) ($attribute['id'] ?? 0);
                $knownKeys = [
                    'id', 'name', 'slug', 'position', 'visible', 'variation', 'options',
                ];

                if (array_diff(array_keys($attribute), $knownKeys) !== []
                    || ! is_string($attribute['name'] ?? null)
                    || trim($attribute['name']) === ''
                    || ! is_string($attribute['slug'] ?? null)
                    || trim($attribute['slug']) === ''
                    || ! is_int($attribute['position'] ?? null)
                    || ! is_bool($attribute['visible'] ?? null)
                    || $attribute['variation'] !== false
                ) {
                    return false;
                }

                $options = collect($attribute['options'])
                    ->map(fn (mixed $option): string => trim((string) $option))
                    ->filter(fn (string $option): bool => $option !== '');

                return $id > 0
                    && (int) $idCounts->get($id, 0) === 1
                    && ! $this->isGenericAttribute($attribute)
                    && ! $this->isSizeAttribute($attribute)
                    && $options->isEmpty()
                    && ! $defaults->contains(fn (array $default): bool => $this->sameAttribute(
                        $attribute,
                        $default,
                    ))
                    && ! $childAttributes->contains(fn (array $child): bool => $this->sameAttribute(
                        $attribute,
                        $child,
                    ));
            })
            ->map(fn (array $attribute): int => (int) $attribute['id'])
            ->all();

        return array_values($inertIds);
    }

    /** @param array<string,mixed> $attribute */
    private function isStrictWooParentAttributeRow(array $attribute): bool
    {
        return $this->hasStrictWooAttributeIdentity($attribute)
            && array_key_exists('variation', $attribute)
            && is_bool($attribute['variation'])
            && array_key_exists('options', $attribute)
            && is_array($attribute['options'])
            && array_is_list($attribute['options'])
            && collect($attribute['options'])
                ->every(fn (mixed $option): bool => is_string($option));
    }

    /** @param array<string,mixed> $attribute */
    private function isStrictWooAttributeReferenceRow(array $attribute): bool
    {
        return $this->hasStrictWooAttributeIdentity($attribute)
            && array_key_exists('option', $attribute)
            && is_string($attribute['option']);
    }

    /** @param array<string,mixed> $attribute */
    private function hasStrictWooAttributeIdentity(array $attribute): bool
    {
        if (! array_key_exists('id', $attribute)
            || ! is_int($attribute['id'])
            || $attribute['id'] < 0
        ) {
            return false;
        }

        foreach (['name', 'slug'] as $key) {
            if (array_key_exists($key, $attribute) && ! is_string($attribute[$key])) {
                return false;
            }
        }

        return $attribute['id'] > 0
            || collect([$attribute['name'] ?? null, $attribute['slug'] ?? null])
                ->filter(fn (mixed $identity): bool => is_string($identity))
                ->contains(fn (string $identity): bool => trim($identity) !== '');
    }

    /**
     * Some historical translated parents lost their legacy axis option list
     * while every translated child kept one exact SKU/term assignment. That
     * complete child bijection may prove the sole default on the axis being
     * removed; a partial, blank, duplicate or locally conflicting family may
     * never use this fallback.
     *
     * @param  array<string,mixed>  $axis
     * @param  list<array<string,mixed>>  $variations
     * @param  array<string,string>  $variationOptionHints
     */
    private function legacyDefaultMatchesExactChildAssignments(
        array $axis,
        string $rawDefaultKey,
        array $variations,
        array $variationOptionHints,
    ): bool {
        $attributeId = (int) ($axis['id'] ?? 0);

        if ($attributeId <= 0
            || $rawDefaultKey === ''
            || $variations === []
            || $variationOptionHints === []
            || count($variations) !== count($variationOptionHints)
        ) {
            return false;
        }

        $seenSkus = [];
        $seenOptions = [];

        foreach ($variations as $variation) {
            $sku = mb_strtoupper(trim((string) ($variation['sku'] ?? '')));
            $expectedKey = $variationOptionHints[$sku] ?? null;
            $axisRows = collect((array) ($variation['attributes'] ?? []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute)
                    && (int) ($attribute['id'] ?? 0) === $attributeId)
                ->values();

            if ($sku === ''
                || ! is_string($expectedKey)
                || trim($expectedKey) === ''
                || isset($seenSkus[$sku])
                || $axisRows->count() !== 1
            ) {
                return false;
            }

            $optionKey = $this->canonicalSizeOptionKey(
                ProductVariantAxisNameResolver::SIZE,
                (string) ($axisRows->first()['option'] ?? ''),
            );

            if ($optionKey === ''
                || $optionKey !== trim($expectedKey)
                || isset($seenOptions[$optionKey])
            ) {
                return false;
            }

            $seenSkus[$sku] = true;
            $seenOptions[$optionKey] = true;
        }

        return count($seenSkus) === count($variationOptionHints)
            && isset($seenOptions[$rawDefaultKey]);
    }

    /**
     * Resolve a global-axis default only through one exact term in the target
     * language. Historical Woo responses can store the term name (`m-l`) or
     * its collision slug (`m-l-2-en`). Neither representation is accepted by
     * shape alone: the fresh taxonomy read, language-aware lookup, exact term
     * identity and the parent's own option set must all agree before planning.
     *
     * @param  array<string,mixed>  $axis
     */
    private function resolveExistingTargetAxisDefaultTermName(
        WordpressIntegration $integration,
        string $language,
        array $axis,
        string $rawOption,
    ): string {
        $attributeId = (int) ($axis['id'] ?? 0);
        $axisOptions = collect((array) ($axis['options'] ?? []))
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->values();
        $rawCanonicalKey = $this->canonicalSizeOptionKey(
            ProductVariantAxisNameResolver::SIZE,
            $rawOption,
        );
        $canonicalAxisOptions = $axisOptions
            ->filter(fn (string $option): bool => $rawCanonicalKey !== ''
                && $this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    $option,
                ) === $rawCanonicalKey)
            ->values();

        if ($canonicalAxisOptions->count() > 1) {
            throw new DomainException(
                "Domyślny wariant starej globalnej osi #{$attributeId} pasuje do kilku wartości tej osi.",
            );
        }

        // A legacy default can use Woo's display spelling (`M/L`) while the
        // old taxonomy stores the same selected option as `m-l`. Both values
        // come from this exact target parent, so the unique axis option is a
        // safe additional lookup alias. It is never submitted directly.
        $lookupOptions = collect([$rawOption])
            ->merge($canonicalAxisOptions)
            ->filter()
            ->unique()
            ->values();
        $terms = collect($this->client->globalProductAttributeTermsById(
            $integration,
            $attributeId,
            $language,
        ))
            ->filter(fn (mixed $term): bool => is_array($term)
                && (int) ($term['id'] ?? 0) > 0)
            ->values();
        $rawMatches = $terms
            ->filter(fn (array $term): bool => $lookupOptions->contains(
                trim((string) ($term['name'] ?? '')),
            ) || $lookupOptions->contains(
                trim((string) ($term['slug'] ?? '')),
            ))
            ->values();

        if ($rawMatches
            ->unique(fn (array $term): int => (int) $term['id'])
            ->groupBy(fn (array $term): string => trim((string) ($term['slug'] ?? '')))
            ->contains(fn (Collection $sameSlug, string $slug): bool => $slug !== ''
                && $sameSlug->count() > 1)
        ) {
            throw new DomainException(
                "Domyślny wariant starej globalnej osi #{$attributeId} wskazuje kilka termów o tym samym slugu.",
            );
        }

        $proven = $rawMatches
            ->map(function (array $candidate) use (
                $integration,
                $attributeId,
                $language,
                $axisOptions,
            ): ?array {
                $name = trim((string) ($candidate['name'] ?? ''));
                $slug = trim((string) ($candidate['slug'] ?? ''));

                if ($name === '' || $slug === '') {
                    return null;
                }

                $languageSlugs = collect($this->sizeDefaultTermLanguageSlugs($name, $language));

                if ($this->isTargetLanguageCollisionTermSlug($name, $slug, $language)) {
                    $languageSlugs->push($slug);
                }

                $term = $this->client->globalProductAttributeTermByNameAndLanguage(
                    $integration,
                    $attributeId,
                    $name,
                    $language,
                    $languageSlugs->filter()->unique()->values()->all(),
                );

                if (! is_array($term)
                    || (int) ($term['id'] ?? 0) !== (int) ($candidate['id'] ?? 0)
                    || trim((string) ($term['name'] ?? '')) !== $name
                    || trim((string) ($term['slug'] ?? '')) !== $slug
                ) {
                    return null;
                }

                $canonicalKey = $this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    $name,
                );
                $matchingOptions = $axisOptions
                    ->filter(fn (string $option): bool => $this->canonicalSizeOptionKey(
                        ProductVariantAxisNameResolver::SIZE,
                        $option,
                    ) === $canonicalKey)
                    ->values();

                return $canonicalKey !== ''
                    && ($axisOptions->isEmpty() || $matchingOptions->count() === 1)
                    ? $term
                    : null;
            })
            ->filter(fn (mixed $term): bool => is_array($term))
            ->unique(fn (array $term): int => (int) $term['id'])
            ->values();

        // Some WooCommerce/Polylang installations hide collision-suffixed
        // legacy terms from `?lang=en`, even though the English product still
        // references them. Retry one fresh, unfiltered read only when the
        // language-aware proof found nothing. The fallback accepts either the
        // one exact term slug already stored in this removable generic-axis
        // default, an explicit target-language identity, or the strict
        // historical collision slug (`m-l-2-en`).
        if ($proven->isEmpty()) {
            $unfilteredMatches = collect($this->client->globalProductAttributeTermsById(
                $integration,
                $attributeId,
                null,
            ))
                ->filter(fn (mixed $term): bool => is_array($term)
                    && (int) ($term['id'] ?? 0) > 0)
                ->filter(fn (array $term): bool => $lookupOptions->contains(
                    trim((string) ($term['name'] ?? '')),
                ) || $lookupOptions->contains(
                    trim((string) ($term['slug'] ?? '')),
                ))
                ->unique(fn (array $term): int => (int) $term['id'])
                ->values();

            if ($unfilteredMatches
                ->groupBy(fn (array $term): string => trim((string) ($term['slug'] ?? '')))
                ->contains(fn (Collection $sameSlug, string $slug): bool => $slug !== ''
                    && $sameSlug->count() > 1)
            ) {
                throw new DomainException(
                    "Domyślny wariant starej globalnej osi #{$attributeId} wskazuje kilka termów o tym samym slugu.",
                );
            }

            $semanticMatches = $unfilteredMatches
                ->filter(function (array $term) use ($axisOptions): bool {
                    $name = trim((string) ($term['name'] ?? ''));
                    $slug = trim((string) ($term['slug'] ?? ''));
                    $canonicalKey = $this->canonicalSizeOptionKey(
                        ProductVariantAxisNameResolver::SIZE,
                        $name,
                    );
                    $matchingOptions = $axisOptions
                        ->filter(fn (string $option): bool => $this->canonicalSizeOptionKey(
                            ProductVariantAxisNameResolver::SIZE,
                            $option,
                        ) === $canonicalKey)
                        ->values();

                    return $name !== ''
                        && $slug !== ''
                        && $canonicalKey !== ''
                        && ($axisOptions->isEmpty() || $matchingOptions->count() === 1);
                })
                ->values();

            // Woo stores a global default as a term slug. A few historical EN
            // suits kept the exact Polish slug (`m-l` or `m-l-2`) on the old
            // generic axis while their EN children correctly referenced the
            // `*-en` terms. The one exact slug on the axis being removed is
            // authoritative only for the selected size's semantics. It is
            // never reused as the target term: the canonical Size taxonomy,
            // target-language term and every child SKU mapping are still
            // proved independently before the first PUT.
            $exactRawLegacyTerms = $this->isGenericAttribute($axis)
                ? $unfilteredMatches
                    ->filter(fn (array $term): bool => trim((string) ($term['slug'] ?? ''))
                        === trim($rawOption))
                    ->values()
                : collect();
            $exactReferencedLegacyTerms = $semanticMatches
                ->filter(fn (array $term): bool => trim((string) ($term['slug'] ?? ''))
                    === trim($rawOption))
                ->filter(function (array $term) use ($axisOptions): bool {
                    $canonicalKey = $this->canonicalSizeOptionKey(
                        ProductVariantAxisNameResolver::SIZE,
                        (string) ($term['name'] ?? ''),
                    );

                    return $canonicalKey !== '' && $axisOptions
                        ->filter(fn (string $option): bool => $this->canonicalSizeOptionKey(
                            ProductVariantAxisNameResolver::SIZE,
                            $option,
                        ) === $canonicalKey)
                        ->count() === 1;
                })
                ->filter(fn (array $term): bool => ! $this->termHasLanguageIdentity($term)
                    || $this->termMatchesLanguage($term, $language)
                    || $this->termMatchesLanguage($term, 'pl'))
                ->values();

            if ($exactRawLegacyTerms->isNotEmpty()) {
                // An explicitly referenced but malformed, ambiguous or
                // foreign-language term must fail closed; do not replace it
                // with a looser same-name candidate.
                $proven = $exactReferencedLegacyTerms->count() === 1
                    ? $exactReferencedLegacyTerms
                    : collect();
            } else {
                $proven = $semanticMatches
                    ->filter(function (array $term) use ($axis, $language): bool {
                        $legacyPolishLanguageCollision = $this->isGenericAttribute($axis)
                            && $this->isTargetLanguageCollisionTermSlug(
                                (string) ($term['name'] ?? ''),
                                (string) ($term['slug'] ?? ''),
                                $language,
                            )
                            && $this->termHasLanguageIdentity($term)
                            && $this->termMatchesLanguage($term, 'pl');

                        // This taxonomy is the legacy source axis being removed,
                        // not the target Size taxonomy. Historical Polylang data
                        // can consistently label its `*-2-en` term as PL even
                        // while the verified EN product and its children use it.
                        // The deterministic EN collision slug may therefore prove
                        // only the source option's semantics. The exact target EN
                        // term on canonical Size is still proved separately before
                        // the first PUT, and contradictory identities stay blocked.
                        if ($legacyPolishLanguageCollision) {
                            return true;
                        }

                        if ($this->termHasLanguageIdentity($term)) {
                            return $this->termMatchesLanguage($term, $language);
                        }

                        return $this->isTargetLanguageCollisionTermSlug(
                            (string) ($term['name'] ?? ''),
                            (string) ($term['slug'] ?? ''),
                            $language,
                        );
                    })
                    ->unique(fn (array $term): int => (int) $term['id'])
                    ->values();
            }
        }

        if ($proven->count() !== 1) {
            throw new DomainException(
                "Domyślny wariant starej globalnej osi #{$attributeId} nie wskazuje jednego terminu rozmiaru we właściwym języku.",
            );
        }

        return trim((string) ($proven->first()['name'] ?? ''));
    }

    /** @param array<string,mixed> $term */
    private function termHasLanguageIdentity(array $term): bool
    {
        return collect([$term['lang'] ?? null, $term['language'] ?? null])->contains(
            fn (mixed $language): bool => filled($language),
        )
            || array_key_exists('translations', $term);
    }

    /** @param array<string,mixed> $term */
    private function termMatchesLanguage(array $term, string $language): bool
    {
        $language = $this->language($language);
        $termId = (int) ($term['id'] ?? 0);
        $explicitLanguages = collect([$term['lang'] ?? null, $term['language'] ?? null])
            ->map(fn (mixed $candidate): string => mb_strtolower(trim((string) $candidate)))
            ->filter()
            ->unique()
            ->values();

        if ($explicitLanguages->isNotEmpty()
            && ($explicitLanguages->count() !== 1 || $explicitLanguages->first() !== $language)
        ) {
            return false;
        }

        if (array_key_exists('translations', $term)) {
            $selfTranslationLanguages = collect((array) $term['translations'])
                ->filter(fn (mixed $translatedId): bool => $termId > 0
                    && (int) $translatedId === $termId)
                ->keys()
                ->map(fn (mixed $candidate): string => mb_strtolower(trim((string) $candidate)))
                ->filter()
                ->unique()
                ->values();

            if ($selfTranslationLanguages->count() !== 1
                || $selfTranslationLanguages->first() !== $language
            ) {
                return false;
            }
        }

        return $explicitLanguages->isNotEmpty() || array_key_exists('translations', $term);
    }

    private function isTargetLanguageCollisionTermSlug(
        string $name,
        string $slug,
        string $language,
    ): bool {
        $slug = Str::slug($slug);
        $language = Str::slug($this->language($language));

        return collect([$this->optionKey($name), Str::slug($name)])
            ->filter()
            ->unique()
            ->contains(function (string $base) use ($slug, $language): bool {
                $quotedBase = preg_quote($base, '/');

                if ($language === 'pl') {
                    return preg_match("/^{$quotedBase}-[2-9][0-9]*(?:-pl)?$/", $slug) === 1;
                }

                $quotedLanguage = preg_quote($language, '/');

                return preg_match(
                    "/^{$quotedBase}(?:-[2-9][0-9]*)?-{$quotedLanguage}$/",
                    $slug,
                ) === 1;
            });
    }

    /**
     * Preserve the exact target-language names while applying only backend
     * dictionary order.
     *
     * @param  list<mixed>  $options
     * @return list<string>
     */
    private function orderedExistingSizeOptionNames(array $options): array
    {
        $dictionaryOrder = $this->sizeDictionaryOrder(ProductVariantAxisNameResolver::SIZE);
        $ranked = $this->localOptionValues($options)
            ->map(fn (mixed $option, int $index): array => [
                'name' => trim((string) $option),
                'index' => $index,
                'rank' => $dictionaryOrder[$this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    (string) $option,
                )] ?? null,
            ])
            ->filter(fn (array $option): bool => $option['name'] !== '');
        $unknown = $ranked->first(fn (array $option): bool => $option['rank'] === null);

        if (is_array($unknown)) {
            throw new DomainException(
                "Wartość rozmiaru `{$unknown['name']}` nie istnieje w żadnym słowniku rozmiarów ERP.",
            );
        }

        return $ranked
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
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Resolve exact name/slug pairs for target-language global Size defaults.
     * A missing or duplicate term is unsafe: accepting a merely canonical
     * value could otherwise cross Polylang term identities.
     *
     * @param  array{integration:WordpressIntegration,language:string}  $target
     * @param  array{default_attributes?:list<array<string,mixed>>}|null  $parentPayload
     * @return array<string,list<string>> Canonical option key => exact term aliases.
     */
    private function resolveSizeDefaultTermAliases(
        array $target,
        ?array $parentPayload,
        int $sizeId,
    ): array {
        if ($parentPayload === null || $sizeId <= 0) {
            return [];
        }

        $defaults = collect((array) ($parentPayload['default_attributes'] ?? []))
            ->filter(fn (mixed $default): bool => is_array($default)
                && (int) ($default['id'] ?? 0) === $sizeId)
            ->values();

        if ($defaults->isEmpty()) {
            return [];
        }

        /** @var WordpressIntegration $integration */
        $integration = $target['integration'];
        $language = $this->language($target['language']);
        $aliases = [];

        foreach ($defaults as $default) {
            $expectedName = trim((string) ($default['option'] ?? ''));
            $canonicalKey = $this->canonicalSizeOptionKey(
                ProductVariantAxisNameResolver::SIZE,
                $expectedName,
            );
            $term = $expectedName === ''
                ? null
                : $this->client->globalProductAttributeTermByNameAndLanguage(
                    $integration,
                    $sizeId,
                    $expectedName,
                    $language,
                    $this->sizeDefaultTermLanguageSlugs($expectedName, $language),
                );

            if ($canonicalKey === '' || ! is_array($term)) {
                throw new RuntimeException(
                    'Nie znaleziono jednego terminu domyślnego rozmiaru we właściwym języku.',
                );
            }

            $termName = trim((string) ($term['name'] ?? ''));
            $termSlug = trim((string) ($term['slug'] ?? ''));

            if ($termName !== $expectedName || $termSlug === '') {
                throw new RuntimeException(
                    'Termin domyślnego rozmiaru nie ma pełnej tożsamości nazwa/slug.',
                );
            }

            $aliases[$canonicalKey] = collect([$termName, $termSlug])
                ->unique()
                ->values()
                ->all();
        }

        return $aliases;
    }

    /** @return list<string> */
    private function sizeDefaultTermLanguageSlugs(string $option, string $language): array
    {
        $language = $this->language($language);
        $localizedSlug = $this->optionKey($option);
        $canonicalSlug = $this->canonicalSizeOptionKey(
            ProductVariantAxisNameResolver::SIZE,
            $option,
        );
        $baseSlugs = collect([$localizedSlug, Str::slug($option)])
            ->filter()
            ->unique()
            ->values();
        $slugs = $baseSlugs->map(
            fn (string $slug): string => $slug.'-'.Str::slug($language),
        );

        // A visibly translated name (Large vs Duży) is itself language-
        // specific and may legitimately keep an unsuffixed legacy slug. A
        // shared label (M/L in PL and EN) requires the target-language suffix
        // or explicit Polylang identity to avoid crossing term families.
        if ($language === 'pl' || $localizedSlug !== $canonicalSlug) {
            $slugs->push(...$baseSlugs);
        }

        return $slugs->filter()->unique()->values()->all();
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
     *   canonical_options:list<string>,
     *   supplemental_canonical_options:list<string>,
     *   sku_option_keys:array<string,string>,
     *   variation_option_keys:array<string,string>,
     *   size_id:int,
     *   parent_payload:?array<string,mixed>,
     *   transitional_parent_payload:?array<string,mixed>,
     *   variation_payloads:array<string,array<string,mixed>>
     * }
     */
    private function familyPlan(
        array $parent,
        array $variations,
        ?array $resolvedGlobalSize = null,
        array $variationOptionHints = [],
        string $language = 'pl',
        array $childOnlyAxisOptions = [],
    ): array {
        $language = $this->language($language);
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

        if ($sizeAttributes->count() > 1) {
            return $this->unsafePlan('Rodzic zawiera kilka atrybutów Rozmiar/Size.');
        }

        if ($genericAttributes->count() > 1 && $sizeAttributes->isEmpty()) {
            return $this->unsafePlan('Rodzic zawiera kilka tekstowych osi wariantu bez globalnego Rozmiaru/Size.');
        }

        if ($genericAttributes->isEmpty() && $sizeAttributes->isEmpty()) {
            return $this->unsafePlan('Rodzic nie zawiera osi wariant/wariant rozmiarowy.');
        }

        $generic = $genericAttributes->first();
        $sourceSize = $sizeAttributes->first();
        $variationGeneric = $genericAttributes->first(
            fn (array $attribute): bool => (bool) ($attribute['variation'] ?? false),
        );
        $sourceSizeKeys = is_array($sourceSize)
            ? $this->localOptionValues($sourceSize['options'] ?? [])
                ->map(fn (string $option): string => $this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    $option,
                ))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all()
            : [];
        $variationGenericKeys = is_array($variationGeneric)
            ? $this->localOptionValues($variationGeneric['options'] ?? [])
                ->map(fn (string $option): string => $this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    $option,
                ))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all()
            : [];
        $parentTargetAxes = $attributes
            ->filter(fn (array $attribute): bool => $this->isGenericAttribute($attribute)
                || $this->isSizeAttribute($attribute))
            ->values();
        $parentTargetAxisKeys = $parentTargetAxes
            ->map(fn (array $attribute): string => $this->axisIdentityKey($attribute))
            ->filter()
            ->unique()
            ->values();
        $childTargetAxisKeys = collect($variations)
            ->flatMap(fn (array $variation): Collection => collect((array) ($variation['attributes'] ?? [])))
            // Woo variation responses can contain only the stable positive ID
            // and option. Match every child row to an already classified
            // parent target axis by identity instead of requiring the child
            // response to repeat its display name.
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): string => $this->axisIdentityKey($attribute))
            ->filter(fn (string $axis): bool => $parentTargetAxisKeys->contains($axis))
            ->unique()
            ->values();
        $activeParentTargetAxes = $parentTargetAxes
            ->filter(fn (array $attribute): bool => $childTargetAxisKeys->contains(
                $this->axisIdentityKey($attribute),
            ))
            ->values();
        $activeParentAxisKeys = $activeParentTargetAxes
            ->map(fn (array $attribute): string => $this->axisIdentityKey($attribute))
            ->unique()
            ->values();
        $completeActiveChildAxisEvidence = $activeParentTargetAxes->isNotEmpty()
            && collect($variations)->every(function (array $variation) use ($activeParentAxisKeys): bool {
                return collect((array) ($variation['attributes'] ?? []))
                    ->filter(fn (mixed $attribute): bool => is_array($attribute)
                        && $activeParentAxisKeys->contains($this->axisIdentityKey($attribute)))
                    ->contains(fn (array $attribute): bool => $this->localOptionValues(
                        $attribute['option'] ?? null,
                    )->isNotEmpty());
            });

        // The live variation axis is the authoritative source of child
        // assignments. Historical imports often left an informational global
        // Size attribute beside `wariant`/`BLVariant`, sometimes with one
        // aggregate option such as `M/L, S/M`. Treat that Size attribute as
        // the destination, not as evidence capable of overriding the axis
        // that actually owns the existing variations.
        $sourceAxis = is_array($sourceSize) ? $sourceSize : $generic;

        if (is_array($variationGeneric)
            && is_array($sourceSize)
            && ! (bool) ($sourceSize['variation'] ?? false)
            && $variationGenericKeys !== []
            && $variationGenericKeys !== $sourceSizeKeys
        ) {
            $sourceAxis = $variationGeneric;
        }

        // Parent flags and option lists are historical metadata; the axes
        // actually stored on every existing child are authoritative for the
        // live variation assignment. This covers two production shapes:
        // Size was also left marked as a variation while children still use
        // `wariant`, or children already use Size while stale generic axes
        // remain marked on the parent. Conflicting child assignments still
        // fail the 1:1 checks below before any PUT.
        if ($completeActiveChildAxisEvidence) {
            $sourceAxis = $activeParentTargetAxes->first(
                fn (array $attribute): bool => is_array($sourceSize)
                    && $this->sameAttribute($attribute, $sourceSize),
            ) ?? $activeParentTargetAxes->first();
            $activeChildOptions = collect($variations)
                ->flatMap(fn (array $variation): Collection => collect((array) ($variation['attributes'] ?? [])))
                ->filter(fn (mixed $attribute): bool => is_array($attribute)
                    && $activeParentAxisKeys->contains($this->axisIdentityKey($attribute)))
                ->flatMap(fn (array $attribute): Collection => $this->localOptionValues(
                    $attribute['option'] ?? null,
                ))
                ->filter(fn (string $option): bool => trim($option) !== '')
                ->unique(fn (string $option): string => $this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    $option,
                ))
                ->values()
                ->all();

            if (is_array($sourceAxis) && $activeChildOptions !== []) {
                $sourceAxis['options'] = $activeChildOptions;
            }
        }

        if (! is_array($sourceAxis)) {
            return $this->unsafePlan('Nie można jednoznacznie wskazać źródłowej osi rozmiaru.');
        }

        $requiresGlobalSize = ! is_array($sourceSize)
            || ! $this->isCanonicalGlobalSizeAttribute($sourceSize);
        $size = is_array($sourceSize) ? $sourceSize : [
            'id' => 0,
            'name' => 'Rozmiar',
            'slug' => 'pa_rozmiar',
            'position' => (int) ($sourceAxis['position'] ?? 0),
            'visible' => (bool) ($sourceAxis['visible'] ?? true),
            'variation' => true,
            'options' => (array) ($sourceAxis['options'] ?? []),
        ];

        if ($requiresGlobalSize && is_array($resolvedGlobalSize)) {
            $resolvedId = (int) ($resolvedGlobalSize['id'] ?? 0);

            if ($resolvedId <= 0) {
                return $this->unsafePlan('Istniejący globalny atrybut Rozmiar/Size ma nieprawidłowe ID.');
            }

            $size['id'] = $resolvedId;
            $size['name'] = trim((string) ($resolvedGlobalSize['name'] ?? 'Rozmiar')) ?: 'Rozmiar';
            $size['slug'] = (string) ($resolvedGlobalSize['slug'] ?? 'pa_rozmiar');
            $size['position'] = (int) ($sourceAxis['position'] ?? 0);
            $size['visible'] = (bool) ($sourceAxis['visible'] ?? true);
            $size['variation'] = true;
        }

        $sizeId = (int) ($size['id'] ?? 0);

        $otherVariationAxis = $attributes->contains(function (array $attribute) use ($sourceSize): bool {
            if (! (bool) ($attribute['variation'] ?? false)) {
                return false;
            }

            return (! is_array($sourceSize) || ! $this->sameAttribute($attribute, $sourceSize))
                && ! $this->isGenericAttribute($attribute);
        });

        if ($otherVariationAxis) {
            return $this->unsafePlan('Rodzina ma drugą oś wariantową poza rozmiarem.');
        }

        $sizeName = is_array($sourceSize)
            ? ($this->attributeName($sourceSize) ?: 'Rozmiar')
            : 'Rozmiar';
        $supplementalCanonicalOptions = $this->supplementalCanonicalOptionsFromCompleteHints(
            $sourceSize,
            $generic,
            $variations,
            $variationOptionHints,
            $sizeName,
            $language,
        );
        try {
            $canonicalOptions = $this->orderedSizeOptions(
                $sizeName,
                [
                    ...$this->localOptionValues($sourceAxis['options'] ?? [])->all(),
                    ...$supplementalCanonicalOptions,
                ],
            );
        } catch (DomainException $exception) {
            return $this->unsafePlan($exception->getMessage());
        }
        $canonicalByKey = collect($canonicalOptions)
            ->mapWithKeys(fn (string $option): array => [$this->optionKey($option) => $option]);
        $targetByKey = $canonicalByKey->map(fn (string $option): string => $this->localizedSizeOption(
            $sizeName,
            $option,
            $language,
        ));
        $orderedOptions = collect($canonicalOptions)
            ->map(fn (string $option): string => (string) $targetByKey->get($this->optionKey($option), $option))
            ->all();

        if ($canonicalByKey->isEmpty()) {
            return $this->unsafePlan('Rozmiar/Size nie zawiera żadnej jednoznacznej wartości.');
        }

        if ($requiresGlobalSize && is_array($resolvedGlobalSize)) {
            $resolvedByKey = $this->localOptionValues($resolvedGlobalSize['options'] ?? [])
                ->map(fn (mixed $option): string => trim((string) $option))
                ->filter()
                ->mapWithKeys(fn (string $option): array => [
                    $this->canonicalSizeOptionKey($sizeName, $option) => $option,
                ]);

            if ($resolvedByKey->count() !== $canonicalByKey->count()
                || $resolvedByKey->keys()->sort()->values()->all()
                    !== $canonicalByKey->keys()->sort()->values()->all()
            ) {
                return $this->unsafePlan('Istniejąca globalna taksonomia nie ma dokładnie tych samych wartości rozmiaru.');
            }

            $orderedOptions = collect($canonicalOptions)
                ->map(fn (string $option): string => (string) $resolvedByKey->get($this->optionKey($option)))
                ->all();
            $targetByKey = collect($canonicalOptions)
                ->mapWithKeys(fn (string $option, int $index): array => [
                    $this->optionKey($option) => (string) ($orderedOptions[$index] ?? $option),
                ]);
        }

        if ($genericAttributes->isNotEmpty()) {
            $canonicalKeys = $canonicalByKey->keys()->sort()->values()->all();

            foreach ($genericAttributes as $genericAttribute) {
                if ($completeActiveChildAxisEvidence) {
                    continue;
                }

                if ($activeParentAxisKeys->isNotEmpty()
                    && ! $activeParentAxisKeys->contains($this->axisIdentityKey($genericAttribute))
                ) {
                    continue;
                }

                $genericKeys = $this->localOptionValues($genericAttribute['options'] ?? [])
                    ->map(fn (mixed $option): string => $this->canonicalSizeOptionKey(
                        $sizeName,
                        (string) $option,
                    ))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values();

                if (($genericAttributes->count() > 1 && $genericKeys->isEmpty())
                    || ($genericKeys->isNotEmpty() && $genericKeys->all() !== $canonicalKeys)
                ) {
                    return $this->unsafePlan('Tekstowe osie wariantu i globalny rozmiar mają inne wartości.');
                }
            }

            if (! $genericAttributes->contains(
                fn (array $attribute): bool => (bool) ($attribute['variation'] ?? false),
            )
                && (! is_array($sourceSize) || ! (bool) ($size['variation'] ?? false))
            ) {
                return $this->unsafePlan('Ani tekstowy wariant, ani globalny rozmiar nie jest osią wariantową.');
            }
        } elseif (! (bool) ($sourceAxis['variation'] ?? false)) {
            // A partially repaired family may already have a canonical parent
            // while its children still point at the old generic axis. The
            // parent must nevertheless identify Size as its sole variation.
            return $this->unsafePlan('Źródłowy rozmiar nie jest osią wariantową.');
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
                $sourceAxis,
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
                ->map(fn (array $attribute): string => $this->canonicalSizeOptionKey(
                    $sizeName,
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
            $variationOptions[$variationId] = $targetByKey->get($optionKey);
            $variationOptionKeys[$variationId] = $optionKey;

            if ($sku !== '') {
                if (isset($skuOptionKeys[$sku]) && $skuOptionKeys[$sku] !== $optionKey) {
                    return $this->unsafePlan("SKU {$sku} występuje przy kilku rozmiarach.");
                }

                $skuOptionKeys[$sku] = $optionKey;
            }
            $currentVariationAttributes[$variationId] = $rows->all();
        }

        $variationKeys = collect($variationOptionKeys);

        $expectedVariationKeys = $canonicalByKey->keys()
            // PHP converts numeric-looking array keys such as `36` to ints,
            // while WooCommerce variation options remain strings. Compare
            // the canonical value identity, not that internal key type.
            ->map(fn (mixed $key): string => (string) $key)
            ->sort()
            ->values();

        if ($variationKeys->count() !== $canonicalByKey->count()
            || $variationKeys->unique()->count() !== $variationKeys->count()
            || $variationKeys->sort()->values()->all() !== $expectedVariationKeys->all()
        ) {
            $assignments = $variationKeys
                ->countBy()
                ->sortKeys()
                ->map(fn (int $count, string $key): string => $key.'x'.$count)
                ->values()
                ->implode(',');
            $expected = $expectedVariationKeys->implode(',');

            return $this->unsafePlan(sprintf(
                'Warianty nie pokrywają dokładnie i jednokrotnie wartości globalnego rozmiaru '
                    .'(warianty=%d, rozmiary=%d, przypisania=%s, oczekiwane=%s).',
                $variationKeys->count(),
                $canonicalByKey->count(),
                $assignments !== '' ? $assignments : '-',
                $expected !== '' ? $expected : '-',
            ));
        }

        $targetDefaultOptions = collect();
        $nonTargetDefaults = [];

        foreach ((array) ($parent['default_attributes'] ?? []) as $default) {
            if (! is_array($default)) {
                continue;
            }

            $isTargetLooking = $this->isGenericAttribute($default)
                || $this->isSizeAttribute($default)
                || $parentTargetAxes->contains(fn (array $attribute): bool => $this->sameAttribute(
                    $default,
                    $attribute,
                ));
            $defaultAxisKey = $this->axisIdentityKey($default);

            // A default for an axis no child uses cannot select an existing
            // variation. Drop it together with that inert legacy axis instead
            // of letting stale colour/aggregate values override live children.
            if ($isTargetLooking
                && $activeParentAxisKeys->isNotEmpty()
                && ! $activeParentAxisKeys->contains($defaultAxisKey)
            ) {
                continue;
            }

            $isTarget = $this->isTargetAxisAttribute(
                $default,
                $sourceAxis,
                $resolvedGlobalSize,
            );

            if (! $isTarget) {
                $nonTargetDefaults[] = $this->serializeDefaultAttribute($default);

                continue;
            }

            $key = $this->canonicalSizeOptionKey(
                $sizeName,
                (string) ($default['option'] ?? ''),
            );

            if ($key === '' || ! $canonicalByKey->has($key)) {
                return $this->unsafePlan('Domyślny wariant nie mapuje się jednoznacznie na rozmiar.');
            }

            $targetDefaultOptions->push((string) $targetByKey->get($key));
        }

        if ($targetDefaultOptions->map(fn (string $option): string => $this->canonicalSizeOptionKey(
            $sizeName,
            $option,
        ))->unique()->count() > 1) {
            return $this->unsafePlan('Tekstowy wariant i Rozmiar mają sprzeczne wartości domyślne.');
        }

        if ($requiresGlobalSize && $resolvedGlobalSize === null) {
            return [
                'status' => 'requires_global',
                'reason' => '',
                'option_keys' => $canonicalByKey->keys()->sort()->values()->all(),
                'ordered_options' => $orderedOptions,
                'canonical_options' => $canonicalOptions,
                'supplemental_canonical_options' => $supplementalCanonicalOptions,
                'sku_option_keys' => $skuOptionKeys,
                'variation_option_keys' => $variationOptionKeys,
                'size_id' => 0,
                'parent_payload' => null,
                'transitional_parent_payload' => null,
                'variation_payloads' => [],
            ];
        }

        if ($sizeId <= 0) {
            return $this->unsafePlan('Naprawa nie rozwiązała istniejącego globalnego atrybutu Rozmiar/Size.');
        }

        $insertedSize = false;
        $finalAttributes = $attributes
            ->map(function (array $attribute) use (
                $sourceSize,
                $size,
                $orderedOptions,
                &$insertedSize,
            ): ?array {
                $isGeneric = $this->isGenericAttribute($attribute);
                $isSourceSize = is_array($sourceSize) && $this->sameAttribute($attribute, $sourceSize);

                if ($isGeneric && is_array($sourceSize)) {
                    return null;
                }

                if ($isGeneric || $isSourceSize) {
                    if ($insertedSize) {
                        return null;
                    }

                    $insertedSize = true;
                    $serialized = $this->serializeParentAttribute($size);
                    $serialized['variation'] = true;
                    $serialized['options'] = $orderedOptions;

                    return $serialized;
                }

                return $this->serializeParentAttribute($attribute);
            })
            ->filter(fn (?array $attribute): bool => is_array($attribute))
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
        $variationAttributesChange = false;

        foreach ($variations as $variation) {
            $variationId = (string) $variation['id'];
            $option = $variationOptions[$variationId];
            $finalVariationAttributes = [[
                'id' => $sizeId,
                'option' => $option,
            ]];
            $menuOrder = (array_search($option, $orderedOptions, true) + 1) * 10;
            $serializedCurrentAttributes = $this->serializeVariationAttributes(
                $currentVariationAttributes[$variationId],
            );
            $attributesChange = $serializedCurrentAttributes !== $finalVariationAttributes;

            if ($attributesChange || (int) ($variation['menu_order'] ?? 0) !== $menuOrder) {
                $variationPayloads[$variationId] = [
                    'attributes' => $finalVariationAttributes,
                    'menu_order' => $menuOrder,
                ];
            }

            $variationAttributesChange = $variationAttributesChange || $attributesChange;
        }

        $parentDoesNotEnableAnOriginalChildAxis = ! $this->parentEnablesEveryChildAxis(
            $parent,
            $variations,
        );
        $requiresParentTransition = $variationAttributesChange
            || $parentDoesNotEnableAnOriginalChildAxis;

        if ($requiresParentTransition) {
            $parentAttributeIds = $attributes
                ->map(fn (array $attribute): int => (int) ($attribute['id'] ?? 0))
                ->filter(fn (int $id): bool => $id > 0)
                ->unique();

            foreach ($currentVariationAttributes as $rows) {
                foreach ($rows as $row) {
                    $sourceId = (int) ($row['id'] ?? 0);

                    if ($sourceId <= 0 || $parentAttributeIds->contains($sourceId)) {
                        continue;
                    }

                    $rawOption = trim((string) ($row['option'] ?? ''));
                    $rawKey = $this->optionKey($rawOption);

                    if ($rawKey === '' || ! isset($childOnlyAxisOptions[$sourceId][$rawKey])) {
                        return $this->unsafePlan(
                            "Stara globalna oś #{$sourceId} istnieje tylko na dzieciach i nie ma jednoznacznie rozwiązanego termu {$rawOption}.",
                        );
                    }
                }
            }
        }

        $transitionalParentPayload = $requiresParentTransition
            ? $this->transitionalParentPayload(
                $parent,
                $variations,
                $size,
                $orderedOptions,
                $childOnlyAxisOptions,
            )
            : null;

        // Even when the original parent already matched the final target,
        // phase one may have temporarily re-attached a child-only legacy axis
        // so rollback remained possible. Phase three must remove it again.
        if ($transitionalParentPayload !== null && $parentPayload === null) {
            $parentPayload = [
                'attributes' => $finalAttributes,
                'default_attributes' => $finalDefaults,
            ];
        }

        return [
            'status' => $parentPayload === null
                && $transitionalParentPayload === null
                && $variationPayloads === []
                    ? 'canonical'
                    : 'repair',
            'reason' => '',
            'option_keys' => $canonicalByKey->keys()->sort()->values()->all(),
            'ordered_options' => $orderedOptions,
            'canonical_options' => $canonicalOptions,
            'supplemental_canonical_options' => $supplementalCanonicalOptions,
            'sku_option_keys' => $skuOptionKeys,
            'variation_option_keys' => $variationOptionKeys,
            'size_id' => $sizeId,
            'parent_payload' => $parentPayload,
            'transitional_parent_payload' => $transitionalParentPayload,
            'variation_payloads' => $variationPayloads,
        ];
    }

    /**
     * A damaged translated parent can retain only a subset of its informational
     * global Size terms while every existing generic child and the complete
     * Polish sibling still prove the full SKU bijection. Supplement only that
     * exact English shape; a non-empty conflict or incomplete hint set remains
     * a hard stop in the normal family-plan validation below.
     *
     * @param  array<string,mixed>|null  $sourceSize
     * @param  array<string,mixed>|null  $generic
     * @param  list<array<string,mixed>>  $variations
     * @param  array<string,string>  $variationOptionHints
     * @return list<string>
     */
    private function supplementalCanonicalOptionsFromCompleteHints(
        ?array $sourceSize,
        ?array $generic,
        array $variations,
        array $variationOptionHints,
        string $sizeName,
        string $language,
    ): array {
        if ($this->language($language) !== 'en'
            || ! is_array($sourceSize)
            || ! is_array($generic)
            || ! $this->isCanonicalGlobalSizeAttribute($sourceSize)
            || (bool) ($sourceSize['variation'] ?? false)
            || ! (bool) ($generic['variation'] ?? false)
            || $variations === []
        ) {
            return [];
        }

        $skus = collect($variations)
            ->map(fn (array $variation): string => mb_strtoupper(trim((string) ($variation['sku'] ?? ''))))
            ->values();

        if ($skus->contains('') || $skus->unique()->count() !== $skus->count()) {
            return [];
        }

        $hintKeys = $skus
            ->map(fn (string $sku): string => trim((string) ($variationOptionHints[$sku] ?? '')))
            ->filter()
            ->values();

        if ($hintKeys->count() !== count($variations)
            || $hintKeys->unique()->count() !== $hintKeys->count()
        ) {
            return [];
        }

        $existingKeys = $this->localOptionValues($sourceSize['options'] ?? [])
            ->map(fn (mixed $option): string => $this->canonicalSizeOptionKey($sizeName, (string) $option))
            ->filter()
            ->unique()
            ->values();
        $genericKeys = $this->localOptionValues($generic['options'] ?? [])
            ->map(fn (mixed $option): string => $this->canonicalSizeOptionKey($sizeName, (string) $option))
            ->filter()
            ->unique()
            ->values();

        if ($existingKeys->diff($hintKeys)->isNotEmpty()
            || $genericKeys->diff($hintKeys)->isNotEmpty()
        ) {
            return [];
        }

        $supplemental = $hintKeys
            ->diff($existingKeys)
            ->map(fn (string $key): string => $this->canonicalSizeOption($sizeName, $key))
            ->filter()
            ->unique(fn (string $option): string => $this->optionKey($option))
            ->values();

        if ($supplemental->contains(
            fn (string $option): bool => ! $hintKeys->contains($this->optionKey($option)),
        )) {
            return [];
        }

        return $this->orderedSizeOptions($sizeName, $supplemental->all());
    }

    /**
     * Woo accepts a child attribute only while the same axis is enabled for
     * variations on the parent. This is checked against the preflight state,
     * including parent-only repairs where children already use target Size
     * and therefore do not otherwise need their own payload.
     *
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     */
    private function parentEnablesEveryChildAxis(array $parent, array $variations): bool
    {
        $enabled = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (bool) ($attribute['variation'] ?? false))
            ->mapWithKeys(fn (array $attribute): array => [
                $this->axisIdentityKey($attribute) => collect((array) ($attribute['options'] ?? []))
                    ->map(fn (mixed $option): string => $this->optionKey((string) $option))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all(),
            ]);

        return collect($variations)
            ->flatMap(fn (array $variation): Collection => collect((array) ($variation['attributes'] ?? [])))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->every(function (array $attribute) use ($enabled): bool {
                $axis = $this->axisIdentityKey($attribute);
                $option = $this->optionKey((string) ($attribute['option'] ?? ''));

                return $enabled->has($axis)
                    && ($option === '' || in_array($option, (array) $enabled->get($axis), true));
            });
    }

    /**
     * Build the only state in which both directions are valid in WooCommerce:
     * the original child axes remain enabled while the target global Size is
     * enabled too. Children can then move to Size, or be restored to their
     * exact original axes during rollback, without Woo silently ignoring the
     * PUT because the referenced parent variation attribute is absent.
     *
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     * @param  array<string,mixed>  $size
     * @param  list<string>  $orderedOptions
     * @param  array<int,array<string,string>>  $childOnlyAxisOptions
     * @return array{attributes:list<array<string,mixed>>,default_attributes:list<array<string,mixed>>}
     */
    private function transitionalParentPayload(
        array $parent,
        array $variations,
        array $size,
        array $orderedOptions,
        array $childOnlyAxisOptions,
    ): array {
        $attributes = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): array => $this->serializeParentAttribute($attribute))
            ->values();
        $positions = $attributes->pluck('position')->map(fn (mixed $position): int => (int) $position);
        $nextPosition = ($positions->max() ?? -1) + 1;

        foreach ($variations as $variation) {
            foreach ((array) ($variation['attributes'] ?? []) as $childAttribute) {
                if (! is_array($childAttribute)) {
                    continue;
                }

                $key = $this->axisIdentityKey($childAttribute);
                $option = trim((string) ($childAttribute['option'] ?? ''));

                if ($key === '' || $option === '') {
                    continue;
                }

                $id = (int) ($childAttribute['id'] ?? 0);
                $rawKey = $this->optionKey($option);

                if ($id > 0 && isset($childOnlyAxisOptions[$id][$rawKey])) {
                    $option = trim((string) $childOnlyAxisOptions[$id][$rawKey]);
                }

                $index = $attributes->search(fn (array $attribute): bool => $this->axisIdentityKey(
                    $attribute,
                ) === $key);

                if ($index === false) {
                    if ($id > 0 && $option === '') {
                        throw new RuntimeException(
                            "Brak bezpiecznej nazwy termu starej osi #{$id} dla {$rawKey}.",
                        );
                    }

                    $attributes->push([
                        ...($id > 0
                            ? ['id' => $id]
                            : ['name' => $this->attributeName($childAttribute)]),
                        'position' => $nextPosition++,
                        'visible' => true,
                        'variation' => true,
                        'options' => [$option],
                    ]);

                    continue;
                }

                $existing = (array) $attributes->get($index);
                $existing['variation'] = true;
                $existing['options'] = collect((array) ($existing['options'] ?? []))
                    ->push($option)
                    ->map(fn (mixed $value): string => trim((string) $value))
                    ->filter()
                    ->unique(fn (string $value): string => $this->optionKey($value))
                    ->values()
                    ->all();
                $attributes->put($index, $existing);
            }
        }

        $target = $this->serializeParentAttribute($size);
        $target['variation'] = true;
        $target['options'] = array_values($orderedOptions);
        $targetKey = $this->axisIdentityKey($target);
        $targetIndex = $attributes->search(fn (array $attribute): bool => $this->axisIdentityKey(
            $attribute,
        ) === $targetKey);

        if ($targetIndex === false) {
            $attributes->push($target);
        } else {
            $attributes->put($targetIndex, $target);
        }

        return [
            'attributes' => $attributes->values()->all(),
            'default_attributes' => collect((array) (
                $parent['_lemon_transitional_default_attributes']
                    ?? $parent['default_attributes']
                    ?? []
            ))
                ->filter(fn (mixed $attribute): bool => is_array($attribute))
                ->map(fn (array $attribute): array => $this->serializeDefaultAttribute($attribute))
                ->values()
                ->all(),
        ];
    }

    /** @param array<string,mixed> $attribute */
    private function axisIdentityKey(array $attribute): string
    {
        $id = (int) ($attribute['id'] ?? 0);

        return $id > 0
            ? 'id:'.$id
            : 'name:'.$this->attributeKey($this->attributeName($attribute));
    }

    /**
     * @param  array<string,mixed>  $parent
     * @param  array{attributes:list<array<string,mixed>>,default_attributes?:list<array<string,mixed>>}  $payload
     */
    private function parentAxisPayloadMatches(array $parent, array $payload): bool
    {
        return $this->parentAxisPayloadMismatch($parent, $payload) === null;
    }

    /**
     * @param  array<string,mixed>  $parent
     * @param  array{attributes:list<array<string,mixed>>,default_attributes?:list<array<string,mixed>>}  $payload
     */
    private function parentAxisPayloadMismatch(array $parent, array $payload): ?string
    {
        $attributes = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->values()
            ->all();
        $mismatches = collect();

        collect($payload['attributes'])
            // The transition contract depends only on axes Woo will accept
            // for children. Position, visibility, response-only names and
            // array order may legitimately be normalized by WooCommerce.
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (bool) ($attribute['variation'] ?? false))
            ->each(function (array $expected) use ($attributes, $mismatches): void {
                $axis = $this->axisIdentityKey($expected);
                $matching = collect($attributes)->first(fn (array $actual): bool => $this->axisIdentityKey(
                    $actual,
                ) === $axis);

                if (! is_array($matching)) {
                    $mismatches->push("{$axis}=brak");

                    return;
                }

                if (! (bool) ($matching['variation'] ?? false)) {
                    $mismatches->push("{$axis}=nie jest osią wariantową");

                    return;
                }

                // The family planner rejects every variation axis outside the
                // size migration. Serialized global attributes intentionally
                // contain only an ID, so name-based `wariant`/Size detection
                // is unavailable here. Compare every enabled transition axis
                // through the canonical ERP Size identity; this treats a
                // Polylang response pair such as `36` + `36-en` as the one
                // exact option WooCommerce persists after its own dedupe.
                $identity = fn (mixed $option): string => $this->canonicalSizeOptionKey(
                    ProductVariantAxisNameResolver::SIZE,
                    (string) $option,
                );

                $expectedOptions = collect((array) ($expected['options'] ?? []))
                    ->map($identity)
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $actualOptions = collect((array) ($matching['options'] ?? []))
                    ->map($identity)
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                if ($expectedOptions !== $actualOptions) {
                    $mismatches->push(sprintf(
                        '%s opcje oczekiwane=[%s], faktyczne=[%s]',
                        $axis,
                        implode(',', $expectedOptions),
                        implode(',', $actualOptions),
                    ));
                }
            });

        return $mismatches->isEmpty() ? null : $mismatches->implode('; ');
    }

    /**
     * Family planning works with canonical term names. Replace a Woo-returned
     * default slug only after the target-language term lookup proved that it
     * is the exact alias of the expected name. The untouched parent is still
     * used by the final comparator and protected-data snapshot.
     *
     * @param  array<string,mixed>  $parent
     * @param  array{attributes:list<array<string,mixed>>,default_attributes?:list<array<string,mixed>>}  $expectedParentPayload
     * @param  array<string,list<string>>  $sizeDefaultTermAliases
     * @return array<string,mixed>
     */
    private function normalizeVerifiedSizeDefaultAliasesForPlan(
        array $parent,
        array $expectedParentPayload,
        int $sizeId,
        array $sizeDefaultTermAliases,
    ): array {
        $actualDefaults = collect((array) ($parent['default_attributes'] ?? []))
            ->filter(fn (mixed $default): bool => is_array($default))
            ->values()
            ->all();
        $expectedDefaults = collect((array) ($expectedParentPayload['default_attributes'] ?? []))
            ->filter(fn (mixed $default): bool => is_array($default))
            ->values()
            ->all();

        foreach ($expectedDefaults as $index => $expectedDefault) {
            $actualDefault = (array) ($actualDefaults[$index] ?? []);
            $actualSerialized = $this->serializeDefaultAttribute($actualDefault);
            $expectedSerialized = $this->serializeDefaultAttribute($expectedDefault);
            $actualOption = (string) ($actualSerialized['option'] ?? '');
            $expectedOption = (string) ($expectedSerialized['option'] ?? '');
            unset($actualSerialized['option'], $expectedSerialized['option']);

            if ($actualSerialized === $expectedSerialized
                && $actualOption !== $expectedOption
                && $this->provenSizeDefaultTermAliasMatches(
                    $actualSerialized,
                    $actualOption,
                    $expectedOption,
                    $sizeId,
                    $sizeDefaultTermAliases,
                )
            ) {
                $actualDefaults[$index]['option'] = $expectedOption;
            }
        }

        $parent['default_attributes'] = $actualDefaults;

        return $parent;
    }

    /**
     * Woo can return global taxonomy options in its own term-query order even
     * after accepting the requested product payload. Final verification may
     * ignore only that response-array order: every attribute identity,
     * position, visibility, variation flag, option set and default must still
     * match the intended final parent, and every child must already require no
     * further repair. An old `wariant` axis or any commercial-data drift still
     * fails and triggers the exact rollback below.
     *
     * @param  array<string,mixed>  $parent
     * @param  array<string,mixed>  $verified
     * @param  array{attributes:list<array<string,mixed>>,default_attributes?:list<array<string,mixed>>}  $expectedParentPayload
     * @param  array<string,list<string>>  $sizeDefaultTermAliases
     */
    private function finalAxisStateMatches(
        array $parent,
        array $verified,
        array $expectedParentPayload,
        array $sizeDefaultTermAliases,
    ): bool {
        if (! in_array((string) ($verified['status'] ?? ''), ['canonical', 'repair'], true)
            || ($verified['transitional_parent_payload'] ?? null) !== null
            || (array) ($verified['variation_payloads'] ?? []) !== []
        ) {
            return false;
        }

        return $this->finalParentAxisPayloadMatches(
            $parent,
            $expectedParentPayload,
            (int) ($verified['size_id'] ?? 0),
            $sizeDefaultTermAliases,
        );
    }

    /**
     * Compare the final parent while treating only the order of attribute and
     * option response arrays as non-semantic. `position` remains exact and is
     * the backend-owned storefront ordering contract for attributes.
     *
     * @param  array<string,mixed>  $parent
     * @param  array{attributes:list<array<string,mixed>>,default_attributes?:list<array<string,mixed>>}  $payload
     * @param  array<string,list<string>>  $sizeDefaultTermAliases
     */
    private function finalParentAxisPayloadMatches(
        array $parent,
        array $payload,
        int $sizeId,
        array $sizeDefaultTermAliases,
    ): bool {
        $actualAttributes = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(function (array $attribute): array {
                $serialized = $this->serializeParentAttribute($attribute);
                $serialized['options'] = collect((array) ($attribute['options'] ?? []))
                    ->map(fn (mixed $option): string => trim((string) $option))
                    ->all();

                return $serialized;
            })
            ->values();
        $expectedAttributes = collect((array) ($payload['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(function (array $attribute): array {
                $serialized = $this->serializeParentAttribute($attribute);
                $serialized['options'] = collect((array) ($attribute['options'] ?? []))
                    ->map(fn (mixed $option): string => trim((string) $option))
                    ->all();

                return $serialized;
            })
            ->values();

        if ($actualAttributes->count() !== $expectedAttributes->count()) {
            return false;
        }

        $actualByAxis = $actualAttributes->keyBy(
            fn (array $attribute): string => $this->axisIdentityKey($attribute),
        );
        $expectedByAxis = $expectedAttributes->keyBy(
            fn (array $attribute): string => $this->axisIdentityKey($attribute),
        );

        if ($actualByAxis->count() !== $actualAttributes->count()
            || $expectedByAxis->count() !== $expectedAttributes->count()
            || $actualByAxis->keys()->sort()->values()->all()
                !== $expectedByAxis->keys()->sort()->values()->all()
        ) {
            return false;
        }

        foreach ($expectedByAxis as $axis => $expected) {
            $actual = (array) $actualByAxis->get($axis, []);
            $actualOptions = collect((array) ($actual['options'] ?? []))
                ->map(fn (mixed $option): string => trim((string) $option))
                ->sort()
                ->values()
                ->all();
            $expectedOptions = collect((array) ($expected['options'] ?? []))
                ->map(fn (mixed $option): string => trim((string) $option))
                ->sort()
                ->values()
                ->all();
            unset($actual['options'], $expected['options']);

            if ($actual !== $expected || $actualOptions !== $expectedOptions) {
                return false;
            }
        }

        return $this->parentDefaultAxisPayloadMatches(
            (array) ($parent['default_attributes'] ?? []),
            (array) ($payload['default_attributes'] ?? []),
            $sizeId,
            $sizeDefaultTermAliases,
        );
    }

    /**
     * Woo stores the submitted name of a global default term as its slug (for
     * example `M/L` becomes `m-l`) and returns that slug on GET. Treat those
     * two representations as equal only when a fresh target-language term
     * lookup proved that exact pair. Count, order, axis identity and every
     * non-Size default stay exact.
     *
     * @param  list<array<string,mixed>>  $actualDefaults
     * @param  list<array<string,mixed>>  $expectedDefaults
     * @param  array<string,list<string>>  $sizeDefaultTermAliases
     */
    private function parentDefaultAxisPayloadMatches(
        array $actualDefaults,
        array $expectedDefaults,
        int $sizeId,
        array $sizeDefaultTermAliases,
    ): bool {
        $actualDefaults = collect($actualDefaults)
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): array => $this->serializeDefaultAttribute($attribute))
            ->values()
            ->all();
        $expectedDefaults = collect($expectedDefaults)
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): array => $this->serializeDefaultAttribute($attribute))
            ->values()
            ->all();

        if (count($actualDefaults) !== count($expectedDefaults)) {
            return false;
        }

        foreach ($expectedDefaults as $index => $expectedDefault) {
            $actualDefault = (array) ($actualDefaults[$index] ?? []);
            $actualOption = (string) ($actualDefault['option'] ?? '');
            $expectedOption = (string) ($expectedDefault['option'] ?? '');
            unset($actualDefault['option'], $expectedDefault['option']);

            if ($actualDefault !== $expectedDefault) {
                return false;
            }

            if ($actualOption === $expectedOption) {
                continue;
            }

            if (! $this->provenSizeDefaultTermAliasMatches(
                $actualDefault,
                $actualOption,
                $expectedOption,
                $sizeId,
                $sizeDefaultTermAliases,
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $defaultIdentity
     * @param  array<string,list<string>>  $sizeDefaultTermAliases
     */
    private function provenSizeDefaultTermAliasMatches(
        array $defaultIdentity,
        string $actualOption,
        string $expectedOption,
        int $sizeId,
        array $sizeDefaultTermAliases,
    ): bool {
        $canonicalKey = $this->canonicalSizeOptionKey(
            ProductVariantAxisNameResolver::SIZE,
            $expectedOption,
        );
        $aliases = array_values((array) ($sizeDefaultTermAliases[$canonicalKey] ?? []));

        return $sizeId > 0
            && (int) ($defaultIdentity['id'] ?? 0) === $sizeId
            && $canonicalKey !== ''
            && in_array($expectedOption, $aliases, true)
            && in_array($actualOption, $aliases, true);
    }

    /**
     * Return axis-only diagnostics. Never include product content, stock,
     * prices, SKUs or raw attribute/default values in deployment logs.
     *
     * @param  array<string,mixed>  $parent
     * @param  array{attributes:list<array<string,mixed>>,default_attributes?:list<array<string,mixed>>}  $payload
     * @param  array<string,list<string>>  $sizeDefaultTermAliases
     */
    private function finalParentAxisPayloadDelta(
        array $parent,
        array $payload,
        int $sizeId,
        array $sizeDefaultTermAliases,
    ): string {
        $differences = collect();
        $actualAttributes = collect((array) ($parent['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): array => $this->serializeParentAttribute($attribute))
            ->values();
        $expectedAttributes = collect((array) ($payload['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): array => $this->serializeParentAttribute($attribute))
            ->values();

        if ($actualAttributes->count() !== $expectedAttributes->count()) {
            $differences->push(sprintf(
                'attributes.count:%d/%d',
                $actualAttributes->count(),
                $expectedAttributes->count(),
            ));
        }

        $actualByAxis = $actualAttributes->keyBy(
            fn (array $attribute): string => $this->axisIdentityKey($attribute),
        );
        $expectedByAxis = $expectedAttributes->keyBy(
            fn (array $attribute): string => $this->axisIdentityKey($attribute),
        );

        foreach ($expectedByAxis as $axis => $expected) {
            $actual = $actualByAxis->get($axis);
            $safeAxis = $this->safeAxisDiagnosticKey($expected);

            if (! is_array($actual)) {
                $differences->push("axis({$safeAxis}).missing");

                continue;
            }

            foreach (['position', 'visible', 'variation'] as $field) {
                if (($actual[$field] ?? null) !== ($expected[$field] ?? null)) {
                    $differences->push("axis({$safeAxis}).{$field}");
                }
            }

            $actualOptions = collect((array) ($actual['options'] ?? []))
                ->map(fn (mixed $option): string => trim((string) $option))
                ->sort()
                ->values()
                ->all();
            $expectedOptions = collect((array) ($expected['options'] ?? []))
                ->map(fn (mixed $option): string => trim((string) $option))
                ->sort()
                ->values()
                ->all();

            if ($actualOptions !== $expectedOptions) {
                $differences->push("axis({$safeAxis}).options");
            }
        }

        foreach ($actualByAxis->keys()->diff($expectedByAxis->keys()) as $axis) {
            $actual = (array) $actualByAxis->get($axis, []);
            $differences->push(sprintf(
                'axis(%s).extra',
                $this->safeAxisDiagnosticKey($actual),
            ));
        }

        $actualDefaults = collect((array) ($parent['default_attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): array => $this->serializeDefaultAttribute($attribute))
            ->values();
        $expectedDefaults = collect((array) ($payload['default_attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->map(fn (array $attribute): array => $this->serializeDefaultAttribute($attribute))
            ->values();

        if ($actualDefaults->count() !== $expectedDefaults->count()) {
            $differences->push(sprintf(
                'defaults.count:%d/%d',
                $actualDefaults->count(),
                $expectedDefaults->count(),
            ));
        }

        foreach ($expectedDefaults as $index => $expectedDefault) {
            $actualDefault = $actualDefaults->get($index);

            if (! is_array($actualDefault)) {
                $differences->push("default[{$index}].missing");

                continue;
            }

            $actualOption = (string) ($actualDefault['option'] ?? '');
            $expectedOption = (string) ($expectedDefault['option'] ?? '');
            unset($actualDefault['option'], $expectedDefault['option']);

            if ($actualDefault !== $expectedDefault) {
                $differences->push("default[{$index}].axis");
            } elseif ($actualOption !== $expectedOption) {
                $aliasProven = $this->provenSizeDefaultTermAliasMatches(
                    $actualDefault,
                    $actualOption,
                    $expectedOption,
                    $sizeId,
                    $sizeDefaultTermAliases,
                );
                $differences->push(sprintf(
                    'default[%d].option:%s',
                    $index,
                    $aliasProven ? 'term-alias-proven' : 'different',
                ));
            }
        }

        return Str::limit(
            $differences->take(8)->implode('|') ?: 'none',
            512,
            '...',
        );
    }

    /** @param array<string,mixed> $attribute */
    private function safeAxisDiagnosticKey(array $attribute): string
    {
        $id = (int) ($attribute['id'] ?? 0);

        if ($id > 0) {
            return 'id:'.$id;
        }

        return 'custom:'.substr(hash(
            'sha256',
            $this->attributeKey($this->attributeName($attribute)),
        ), 0, 8);
    }

    /**
     * @param  array<string,mixed>  $variation
     * @param  array{attributes:list<array<string,mixed>>,menu_order?:int}  $payload
     */
    private function variationAxisPayloadMatches(array $variation, array $payload): bool
    {
        $actual = collect((array) ($variation['attributes'] ?? []))
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->mapWithKeys(fn (array $attribute): array => [
                $this->axisIdentityKey($attribute) => $this->optionKey(
                    (string) ($attribute['option'] ?? ''),
                ),
            ])
            ->sortKeys()
            ->all();
        $expected = collect((array) $payload['attributes'])
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->mapWithKeys(fn (array $attribute): array => [
                $this->axisIdentityKey($attribute) => $this->optionKey(
                    (string) ($attribute['option'] ?? ''),
                ),
            ])
            ->sortKeys()
            ->all();

        return $actual === $expected
            && (! array_key_exists('menu_order', $payload)
                || (int) ($variation['menu_order'] ?? 0) === (int) $payload['menu_order']);
    }

    /**
     * @return array{status:'unsafe',reason:string,option_keys:list<string>,ordered_options:list<string>,canonical_options:list<string>,supplemental_canonical_options:list<string>,sku_option_keys:array{},variation_option_keys:array{},size_id:int,parent_payload:null,transitional_parent_payload:null,variation_payloads:array{}}
     */
    private function unsafePlan(string $reason): array
    {
        return [
            'status' => 'unsafe',
            'reason' => $reason,
            'option_keys' => [],
            'ordered_options' => [],
            'canonical_options' => [],
            'supplemental_canonical_options' => [],
            'sku_option_keys' => [],
            'variation_option_keys' => [],
            'size_id' => 0,
            'parent_payload' => null,
            'transitional_parent_payload' => null,
            'variation_payloads' => [],
        ];
    }

    /** @param array<string,mixed> $plan */
    private function verificationResidual(array $plan): string
    {
        $variationIds = collect(array_keys((array) ($plan['variation_payloads'] ?? [])))
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values()
            ->implode(',');

        return sprintf(
            'status=%s, reason=%s, parent=%s, transition=%s, variations=%s',
            trim((string) ($plan['status'] ?? 'unknown')) ?: 'unknown',
            trim((string) ($plan['reason'] ?? '')) ?: '-',
            ($plan['parent_payload'] ?? null) === null ? 'ok' : 'pending',
            ($plan['transitional_parent_payload'] ?? null) === null ? 'ok' : 'pending',
            $variationIds !== '' ? $variationIds : '-',
        );
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

    /** @param array<string, mixed> $attribute */
    private function isCanonicalGlobalSizeAttribute(array $attribute): bool
    {
        if ((int) ($attribute['id'] ?? 0) <= 0) {
            return false;
        }

        $keys = collect([
            $attribute['name'] ?? null,
            $attribute['slug'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => $this->attributeKey((string) $value));

        return ! $keys->contains(fn (string $key): bool => in_array($key, ['rozmiary', 'sizes'], true))
            && $keys->contains(fn (string $key): bool => in_array($key, ['rozmiar', 'size'], true));
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
        $resolvedId = (int) ($resolvedGlobalSize['id'] ?? 0);

        // A known partial state has a canonical Size parent while children
        // still reference an older positive Rozmiary/Sizes taxonomy. Permit
        // only that explicit plural alias provisionally; the caller must then
        // resolve every child option 1:1 against the existing taxonomy terms
        // before the transition plan can be built.
        if ($sourceId > 0
            && $attributeId > 0
            && $attributeId !== $sourceId
            && $this->isCanonicalGlobalSizeAttribute($sourceSize)
            && $this->isDirectPluralSizeAttribute($attribute)
        ) {
            return true;
        }

        if (is_array($resolvedGlobalSize) && $resolvedId > 0 && $attributeId > 0) {
            return $attributeId === $resolvedId
                || ($sourceId > 0 && $attributeId === $sourceId);
        }

        // Once the custom-text axis has been resolved, a positive ID is
        // authoritative. Do not let a stale/different global taxonomy pass
        // merely because WooCommerce returned the same display name.
        if ($sourceId <= 0 && is_array($resolvedGlobalSize) && $attributeId > 0) {
            return $attributeId === $resolvedId;
        }

        if ($this->sameAttribute($attribute, $sourceSize)) {
            return true;
        }

        if ($sourceId > 0) {
            return false;
        }

        if (is_array($resolvedGlobalSize)) {
            return $attributeId > 0
                && $attributeId === $resolvedId;
        }

        return $attributeId > 0 || $this->isSizeAttribute($attribute);
    }

    /** @param array<string,mixed> $attribute */
    private function isDirectPluralSizeAttribute(array $attribute): bool
    {
        return collect([
            $attribute['name'] ?? null,
            $attribute['slug'] ?? null,
        ])
            ->filter(fn (mixed $value): bool => is_scalar($value))
            ->map(fn (mixed $value): string => $this->attributeKey((string) $value))
            ->contains(fn (string $key): bool => in_array($key, ['rozmiary', 'sizes'], true));
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
        $ranked = $this->localOptionValues($options)
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
                        ?? null,
                    'index' => $index,
                ];
            });
        $unknown = $ranked->first(fn (array $option): bool => $option['rank'] === null);

        if (is_array($unknown)) {
            throw new DomainException(
                "Wartość rozmiaru `{$unknown['value']}` nie istnieje w żadnym słowniku rozmiarów ERP.",
            );
        }

        return $ranked
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

    /**
     * Submit child assignments in the exact Size order configured in ERP.
     * This keeps the write sequence deterministic without claiming that
     * Woo's later DISTINCT attribute query guarantees any physical database
     * row order.
     *
     * @param  array<int|string,array<string,mixed>>  $payloads
     * @return array<int|string,array<string,mixed>>
     */
    private function orderedVariationPayloads(array $payloads): array
    {
        return collect($payloads)
            ->map(fn (array $payload, int|string $variationId): array => [
                'variation_id' => $variationId,
                'payload' => $payload,
            ])
            ->sort(function (array $left, array $right): int {
                $menuOrder = (int) data_get($left, 'payload.menu_order', PHP_INT_MAX)
                    <=> (int) data_get($right, 'payload.menu_order', PHP_INT_MAX);

                if ($menuOrder !== 0) {
                    return $menuOrder;
                }

                return strnatcmp(
                    (string) $left['variation_id'],
                    (string) $right['variation_id'],
                );
            })
            ->mapWithKeys(fn (array $entry): array => [
                $entry['variation_id'] => $entry['payload'],
            ])
            ->all();
    }

    private function canonicalSizeOption(string $attribute, string $option): string
    {
        $key = $this->optionKey($option);

        $dictionaryOption = $this->dictionaryCanonicalSizeOption($attribute, $key);

        if ($dictionaryOption !== null) {
            return $dictionaryOption;
        }

        // Historical Polylang terms can leak their language-qualified slug
        // (`40-en`, `s-m-en`) into a Woo option response. Accept it only when
        // removing exactly one supported language suffix produces an exact
        // option in the ERP Size dictionary. Arbitrary unknown suffixes stay
        // blocked by the normal unknown-value validation.
        if (preg_match('/^(.+)-(?:pl|en)$/u', $key, $matches) === 1) {
            $dictionaryOption = $this->dictionaryCanonicalSizeOption(
                $attribute,
                (string) $matches[1],
            );

            if ($dictionaryOption !== null) {
                return $dictionaryOption;
            }
        }

        $candidate = trim($option);

        if (preg_match('/^(?:[2-9]xl|x{1,6}[sl]|[sml])(?:\s*-\s*(?:[2-9]xl|x{1,6}[sl]|[sml]))+$/iu', $candidate) === 1) {
            $candidate = (string) preg_replace('/\s*-\s*/u', '/', $candidate);
        }

        return $this->variantOptions->normalize($attribute, $candidate);
    }

    private function dictionaryCanonicalSizeOption(string $attribute, string $key): ?string
    {

        foreach ($this->sizeDefinitionsByCanonicalPriority($attribute) as $definition) {
            $polishValues = collect((array) $definition->values)
                ->map(fn (mixed $candidate): string => trim((string) $candidate))
                ->values();
            $polishMatch = $polishValues->first(
                fn (string $candidate): bool => $candidate !== ''
                    && $this->optionKey($candidate) === $key,
            );

            if (is_string($polishMatch) && $polishMatch !== '') {
                return $this->variantOptions->normalize($attribute, $polishMatch);
            }

            // English values can recognize a translated option, but they may
            // only select the Polish value at the same dictionary position.
            // They are never emitted as the canonical ERP spelling.
            foreach (array_values((array) $definition->values_en) as $index => $englishValue) {
                $englishValue = trim((string) $englishValue);
                $polishValue = trim((string) $polishValues->get($index, ''));

                if ($polishValue !== ''
                    && $englishValue !== ''
                    && $this->optionKey($englishValue) === $key
                ) {
                    return $this->variantOptions->normalize($attribute, $polishValue);
                }
            }
        }

        return null;
    }

    private function canonicalSizeOptionKey(string $attribute, string $option): string
    {
        return $this->optionKey($this->canonicalSizeOption($attribute, $option));
    }

    /**
     * Translate only after resolving the shared Polish dictionary identity.
     * Missing translations deliberately fall back to Polish, preserving the
     * same option count and order in every Woo language.
     */
    private function localizedSizeOption(
        string $attribute,
        string $canonicalOption,
        string $language,
    ): string {
        $canonicalOption = $this->canonicalSizeOption($attribute, $canonicalOption);
        $localized = $this->sizeOrder->localizedOption(
            $canonicalOption,
            $this->language($language),
        );

        return is_string($localized) && $localized !== ''
            ? $localized
            : $canonicalOption;
    }

    /** @return array<string, int> */
    private function sizeDictionaryOrder(string $attribute): array
    {
        return $this->sizeOrder
            ->entries()
            ->mapWithKeys(fn (array $entry): array => [
                $this->optionKey($entry['source']) => $entry['menu_order'],
            ])
            ->all();
    }

    /** @return Collection<int, ProductParameterDefinition> */
    private function sizeDefinitionsByCanonicalPriority(string $attribute): Collection
    {
        $attributeKey = $this->attributeKey($attribute);

        return $this->sizeOrder
            ->definitions()
            ->sortBy(function (ProductParameterDefinition $definition) use ($attributeKey): string {
                $nameKey = $this->attributeKey((string) $definition->name);
                $slugKey = $this->attributeKey((string) $definition->slug);
                $keys = collect([
                    $nameKey,
                    $this->attributeKey((string) $definition->name_en),
                    $slugKey,
                ])->filter();
                $priority = match (true) {
                    $nameKey === 'rozmiar' && $slugKey === 'rozmiar' => 0,
                    $nameKey === 'rozmiar' || $slugKey === 'rozmiar' => 1,
                    $keys->contains($attributeKey) => 2,
                    $keys->contains(fn (string $key): bool => in_array($key, ['size', 'rozmiar'], true)) => 3,
                    default => 4,
                };

                return sprintf('%02d-%010d', $priority, (int) $definition->id);
            })
            ->values();
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
        return hash('sha256', (string) json_encode(
            $this->protectedSnapshotData($parent, $variations),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /**
     * @param  array<string,mixed>  $parent
     * @param  list<array<string,mixed>>  $variations
     * @return array<string,mixed>
     */
    private function protectedSnapshotData(array $parent, array $variations): array
    {
        $select = fn (array $payload, array $fields = self::PROTECTED_PRODUCT_FIELDS): array => collect($fields)
            ->mapWithKeys(fn (string $field): array => [
                $field => array_key_exists($field, $payload) ? $payload[$field] : null,
            ])
            ->all();
        // WooCommerce derives a variation's display name from the parent and
        // its attribute options. Replacing the legacy axis therefore refreshes
        // that response-only value even though no name is submitted. Keep the
        // parent name protected and continue protecting every editable and
        // commercial variation field.
        $variationProtectedFields = collect(self::PROTECTED_PRODUCT_FIELDS)
            ->reject(fn (string $field): bool => $field === 'name')
            ->values()
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

        return [
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
                    (string) ($variation['id'] ?? '') => $select(
                        $variation,
                        $variationProtectedFields,
                    ),
                ])
                ->sortKeys()
                ->all(),
        ];
    }

    /**
     * Report paths only; values can contain commercial data or editorial
     * content and must never enter deployment logs.
     *
     * @param  array<string,mixed>  $beforeParent
     * @param  list<array<string,mixed>>  $beforeVariations
     * @param  array<string,mixed>  $afterParent
     * @param  list<array<string,mixed>>  $afterVariations
     */
    private function protectedSnapshotDelta(
        array $beforeParent,
        array $beforeVariations,
        array $afterParent,
        array $afterVariations,
    ): string {
        $before = $this->protectedSnapshotData($beforeParent, $beforeVariations);
        $after = $this->protectedSnapshotData($afterParent, $afterVariations);
        $paths = collect();

        $compare = function (mixed $left, mixed $right, string $path) use (&$compare, $paths): void {
            if (is_array($left) && is_array($right)) {
                $keys = collect([...array_keys($left), ...array_keys($right)])->unique();

                foreach ($keys as $key) {
                    $childPath = $path === '' ? (string) $key : $path.'.'.$key;

                    if (! array_key_exists($key, $left) || ! array_key_exists($key, $right)) {
                        $paths->push($childPath);

                        continue;
                    }

                    $compare($left[$key], $right[$key], $childPath);
                }

                return;
            }

            if ($left !== $right) {
                $paths->push($path !== '' ? $path : 'root');
            }
        };
        $compare($before, $after, '');

        return Str::limit(
            $paths->filter()->unique()->take(12)->implode('|') ?: 'unknown',
            512,
            '...',
        );
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
