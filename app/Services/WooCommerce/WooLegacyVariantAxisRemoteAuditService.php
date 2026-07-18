<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class WooLegacyVariantAxisRemoteAuditService
{
    /** @var list<string> */
    private const LEGACY_ATTRIBUTE_NAMES = [
        'wariant',
        'variant',
        'blvariant',
        'bl-variant',
        'bl-wariant',
    ];

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly WooOwnedVariantAxisRepairService $repair,
        private readonly WooCommerceSizeDictionaryOrder $sizeOrder,
    ) {}

    /**
     * @return array{
     *   integrations:int,
     *   attributes:int,
     *   remote_products:int,
     *   unique_local_roots:int,
     *   mapped_products:int,
     *   unmapped_products:int,
     *   ambiguous_products:int,
     *   current_candidates:int,
     *   missed_products:int,
     *   exact_remote_products:int,
     *   migration_safe_remote_products:int,
     *   conflicting_remote_products:int,
     *   exact_remote_roots:int,
     *   migration_safe_remote_roots:int,
     *   rows:list<array<string,mixed>>
     * }
     */
    public function audit(?string $externalProductId = null): array
    {
        $externalProductId = trim((string) $externalProductId);
        $rows = collect();
        $integrationCount = 0;
        $attributeCount = 0;

        WordpressIntegration::query()
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->with('salesChannel')
            ->orderBy('id')
            ->each(function (WordpressIntegration $integration) use (
                &$integrationCount,
                &$attributeCount,
                $externalProductId,
                $rows,
            ): void {
                $integrationCount++;
                $attributes = collect($this->client->globalProductAttributesByNames(
                    $integration,
                    self::LEGACY_ATTRIBUTE_NAMES,
                ))->filter(fn (array $attribute): bool => $this->isLegacyAttribute($attribute));
                $attributeCount += $attributes->count();

                foreach ($attributes as $attribute) {
                    $attributeId = (int) ($attribute['id'] ?? 0);
                    $taxonomy = trim((string) ($attribute['slug'] ?? ''));
                    $termIds = collect($this->client->globalProductAttributeTermsById(
                        $integration,
                        $attributeId,
                    ))
                        ->filter(fn (array $term): bool => (int) ($term['count'] ?? 0) > 0)
                        ->pluck('id')
                        ->all();

                    foreach ($this->client->productsByGlobalAttributeTerms(
                        $integration,
                        $taxonomy,
                        $termIds,
                    ) as $remoteProduct) {
                        $remoteId = trim((string) ($remoteProduct['id'] ?? ''));

                        if ($externalProductId !== '' && $remoteId !== $externalProductId) {
                            continue;
                        }

                        $legacyAxis = collect((array) ($remoteProduct['attributes'] ?? []))
                            ->filter(fn (mixed $candidate): bool => is_array($candidate))
                            ->first(fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === $attributeId
                                && ($candidate['variation'] ?? null) === true);

                        if (! is_array($legacyAxis)) {
                            continue;
                        }

                        $ownerRootIds = $this->ownerRootIds(
                            (int) $integration->sales_channel_id,
                            $remoteId,
                        );
                        $candidateRootIds = $ownerRootIds
                            ->filter(function (int $rootId): bool {
                                $root = Product::query()->find($rootId);

                                return $root instanceof Product
                                    && ($this->repair->isSizeVariantRootCandidate($root)
                                        || $this->repair->isChildSizeAssignmentAuditCandidate($root)
                                        || $this->repair->isComplementaryLanguageSizeRootCandidate($root));
                            })
                            ->values();
                        $sizeAxes = collect((array) ($remoteProduct['attributes'] ?? []))
                            ->filter(fn (mixed $candidate): bool => is_array($candidate)
                                && $this->isSizeAttribute($candidate))
                            ->values();
                        $remoteEvidence = $this->remoteSizeEvidence($legacyAxis, $sizeAxes);

                        $rows->push([
                            'sales_channel_id' => (int) $integration->sales_channel_id,
                            'channel' => (string) ($integration->salesChannel?->code ?? $integration->id),
                            'attribute_id' => $attributeId,
                            'attribute' => $taxonomy,
                            'external_product_id' => $remoteId,
                            'sku' => trim((string) ($remoteProduct['sku'] ?? '')),
                            'language' => trim((string) (
                                $remoteProduct['lang']
                                ?? $remoteProduct['language']
                                ?? $remoteProduct['erp_import_language']
                                ?? 'unknown'
                            )),
                            'legacy_options' => collect((array) ($legacyAxis['options'] ?? []))
                                ->map(fn (mixed $option): string => trim((string) $option))
                                ->filter()
                                ->values()
                                ->all(),
                            'size_axes' => $sizeAxes->map(fn (array $axis): array => [
                                'id' => (int) ($axis['id'] ?? 0),
                                'variation' => (bool) ($axis['variation'] ?? false),
                                'options' => collect((array) ($axis['options'] ?? []))
                                    ->map(fn (mixed $option): string => trim((string) $option))
                                    ->filter()
                                    ->values()
                                    ->all(),
                            ])->all(),
                            'owner_root_ids' => $ownerRootIds->all(),
                            'candidate_root_ids' => $candidateRootIds->all(),
                            'remote_evidence' => $remoteEvidence,
                            'owner_statuses' => $ownerRootIds
                                ->mapWithKeys(fn (int $rootId): array => [
                                    $rootId => $this->ownerStatus(
                                        $rootId,
                                        (int) $integration->sales_channel_id,
                                    ),
                                ])
                                ->all(),
                            'permalink' => trim((string) ($remoteProduct['permalink'] ?? '')),
                        ]);
                    }
                }
            });

        $rows = $rows
            ->unique(fn (array $row): string => $row['sales_channel_id'].'|'.$row['external_product_id'])
            ->sortBy(fn (array $row): string => sprintf(
                '%010d|%012d',
                $row['sales_channel_id'],
                $row['external_product_id'],
            ))
            ->values();
        $mapped = $rows->filter(fn (array $row): bool => count($row['owner_root_ids']) === 1);
        $unmapped = $rows->filter(fn (array $row): bool => $row['owner_root_ids'] === []);
        $ambiguous = $rows->filter(fn (array $row): bool => count($row['owner_root_ids']) > 1);
        $currentCandidates = $rows->filter(fn (array $row): bool => count($row['candidate_root_ids']) === 1);
        $exactRemote = $rows->filter(fn (array $row): bool => data_get(
            $row,
            'remote_evidence.mode',
        ) === 'parallel_exact' && count($row['owner_root_ids']) === 1);
        $migrationSafeRemote = $rows->filter(fn (array $row): bool => data_get(
            $row,
            'remote_evidence.verified',
        ) === true && count($row['owner_root_ids']) === 1);
        $conflictingRemote = $rows->reject(fn (array $row): bool => data_get(
            $row,
            'remote_evidence.verified',
        ) === true);

        return [
            'integrations' => $integrationCount,
            'attributes' => $attributeCount,
            'remote_products' => $rows->count(),
            'unique_local_roots' => $mapped
                ->flatMap(fn (array $row): array => $row['owner_root_ids'])
                ->unique()
                ->count(),
            'mapped_products' => $mapped->count(),
            'unmapped_products' => $unmapped->count(),
            'ambiguous_products' => $ambiguous->count(),
            'current_candidates' => $currentCandidates->count(),
            'missed_products' => $rows->count() - $currentCandidates->count(),
            'exact_remote_products' => $exactRemote->count(),
            'migration_safe_remote_products' => $migrationSafeRemote->count(),
            'conflicting_remote_products' => $conflictingRemote->count(),
            'exact_remote_roots' => $exactRemote
                ->flatMap(fn (array $row): array => $row['owner_root_ids'])
                ->unique()
                ->count(),
            'migration_safe_remote_roots' => $migrationSafeRemote
                ->flatMap(fn (array $row): array => $row['owner_root_ids'])
                ->unique()
                ->count(),
            'rows' => $rows->all(),
        ];
    }

    /**
     * Persist a fail-closed remote evidence envelope and queue only roots for
     * which every still-legacy language parent has a dictionary-backed active
     * legacy Size axis. The active child-owning axis is authoritative over a
     * stale informational Size row. `repair()` independently refetches every
     * parent and child before its first PUT.
     *
     * @param  array{rows:list<array<string,mixed>>}  $audit
     * @return array{eligible_roots:int,marked_roots:int,marked_mappings:int,skipped_roots:int}
     */
    public function markSafeRemoteRepairCandidates(array $audit): array
    {
        $grouped = collect($audit['rows'])
            ->filter(fn (array $row): bool => count($row['owner_root_ids']) === 1)
            ->groupBy(fn (array $row): int => (int) $row['owner_root_ids'][0]);
        $eligibleRoots = 0;
        $markedRoots = 0;
        $markedMappings = 0;

        foreach ($grouped as $rootId => $rows) {
            if ($rows->isEmpty()
                || $rows->contains(fn (array $row): bool => data_get(
                    $row,
                    'remote_evidence.verified',
                ) !== true)
            ) {
                continue;
            }

            $eligibleRoots++;
            $targets = $rows
                ->map(fn (array $row): array => [
                    'sales_channel_id' => (int) $row['sales_channel_id'],
                    'external_product_id' => (string) $row['external_product_id'],
                    'attribute_id' => (int) $row['attribute_id'],
                    'size_attribute_id' => (int) data_get($row, 'remote_evidence.size_attribute_id'),
                    'evidence_mode' => (string) data_get($row, 'remote_evidence.mode'),
                    'option_keys' => (array) data_get($row, 'remote_evidence.option_keys', []),
                ])
                ->sortBy(fn (array $target): string => $target['sales_channel_id'].'|'.$target['external_product_id'])
                ->values()
                ->all();
            $written = DB::transaction(function () use ($rootId, $targets): int {
                $mappings = ProductChannelMapping::query()
                    ->where('product_id', $rootId)
                    ->whereHas('salesChannel', fn ($query) => $query
                        ->where('type', 'woocommerce')
                        ->where('is_active', true))
                    ->where(function ($query): void {
                        $query
                            ->whereNull('external_variation_id')
                            ->orWhereIn('external_variation_id', ['', '0'])
                            ->orWhereRaw("TRIM(external_variation_id) = ''");
                    })
                    ->lockForUpdate()
                    ->get();

                foreach ($mappings as $mapping) {
                    $metadata = (array) $mapping->metadata;
                    data_set($metadata, WooOwnedVariantAxisRepairService::REMOTE_EVIDENCE_PATH, [
                        'revision' => WooOwnedVariantAxisRepairService::REVISION,
                        'verified' => true,
                        'verified_at' => now()->toISOString(),
                        'targets' => array_values(array_filter(
                            $targets,
                            fn (array $target): bool => (int) $target['sales_channel_id']
                                === (int) $mapping->sales_channel_id,
                        )),
                    ]);
                    $mapping->forceFill(['metadata' => $metadata])->save();
                }

                return $mappings->count();
            });

            if ($written === 0) {
                continue;
            }

            $root = Product::query()->find((int) $rootId);

            if (! $root instanceof Product) {
                continue;
            }

            $markedMappings += $this->repair->markPending($root);
            $markedRoots++;
        }

        return [
            'eligible_roots' => $eligibleRoots,
            'marked_roots' => $markedRoots,
            'marked_mappings' => $markedMappings,
            'skipped_roots' => $grouped->count() - $eligibleRoots,
        ];
    }

    /** @return Collection<int,int> */
    private function ownerRootIds(int $salesChannelId, string $externalProductId): Collection
    {
        $mappingOwnerIds = ProductChannelMapping::query()
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_product_id', $externalProductId)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->pluck('product_id');
        $aliasOwnerIds = ProductChannelAlias::query()
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_product_id', $externalProductId)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->get()
            ->filter(fn (ProductChannelAlias $alias): bool => $alias->isOutboundSyncEnabled())
            ->pluck('product_id');

        return $mappingOwnerIds
            ->merge($aliasOwnerIds)
            ->map(fn (mixed $productId): int => $this->repair->familyRootId((int) $productId))
            ->unique()
            ->sort()
            ->values();
    }

    private function ownerStatus(int $rootId, int $salesChannelId): string
    {
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $rootId)
            ->where('sales_channel_id', $salesChannelId)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->first();

        return implode(':', array_filter([
            (string) data_get($mapping?->metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.revision', 'unmarked'),
            (string) data_get($mapping?->metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.status', 'unmarked'),
        ]));
    }

    /**
     * @param  array<string,mixed>  $legacyAxis
     * @param  Collection<int,array<string,mixed>>  $sizeAxes
     * @return array{verified:bool,mode:?string,reason:?string,size_attribute_id:?int,option_keys:list<string>}
     */
    private function remoteSizeEvidence(array $legacyAxis, Collection $sizeAxes): array
    {
        if ($sizeAxes->count() > 1) {
            return [
                'verified' => false,
                'mode' => null,
                'reason' => 'Produkt ma kilka globalnych atrybutów Rozmiar/Size.',
                'size_attribute_id' => null,
                'option_keys' => [],
            ];
        }

        $sizeAxis = $sizeAxes->first();
        $legacyOptions = collect((array) ($legacyAxis['options'] ?? []))
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->unique(fn (string $option): string => $this->sizeOrder->key($option))
            ->values();
        if ($legacyOptions->isEmpty()) {
            return [
                'verified' => false,
                'mode' => null,
                'reason' => 'Aktywna historyczna oś wariantowa nie zawiera wartości.',
                'size_attribute_id' => is_array($sizeAxis) ? (int) ($sizeAxis['id'] ?? 0) : null,
                'option_keys' => [],
            ];
        }

        try {
            $this->sizeOrder->menuOrders($legacyOptions->all());
        } catch (Throwable $exception) {
            return [
                'verified' => false,
                'mode' => null,
                'reason' => $exception->getMessage(),
                'size_attribute_id' => is_array($sizeAxis) ? (int) ($sizeAxis['id'] ?? 0) : null,
                'option_keys' => [],
            ];
        }

        $legacyKeys = $legacyOptions
            ->map(fn (string $option): string => $this->sizeOrder->key($option))
            ->sortBy(fn (string $key): int => $this->sizeOrder->menuOrders([$key])[0])
            ->values();
        $safe = [
            'verified' => true,
            'mode' => 'legacy_only',
            'reason' => null,
            'size_attribute_id' => is_array($sizeAxis) ? (int) ($sizeAxis['id'] ?? 0) : null,
            'option_keys' => $legacyKeys->all(),
        ];

        if (! is_array($sizeAxis)) {
            return $safe;
        }

        $sizeOptions = collect((array) ($sizeAxis['options'] ?? []))
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->unique(fn (string $option): string => $this->sizeOrder->key($option))
            ->values();

        if ($sizeOptions->isEmpty()) {
            return [...$safe, 'mode' => 'legacy_with_empty_size'];
        }

        try {
            $this->sizeOrder->menuOrders($sizeOptions->all());
        } catch (Throwable) {
            if (($sizeAxis['variation'] ?? null) === true) {
                return [
                    'verified' => false,
                    'mode' => null,
                    'reason' => 'Aktywny Rozmiar zawiera wartości spoza słownika ERP.',
                    'size_attribute_id' => (int) ($sizeAxis['id'] ?? 0),
                    'option_keys' => [],
                ];
            }

            return [...$safe, 'mode' => 'legacy_over_informational_size'];
        }

        $sizeKeys = $sizeOptions
            ->map(fn (string $option): string => $this->sizeOrder->key($option))
            ->sort()
            ->values();

        if ($legacyKeys->sort()->values()->all() !== $sizeKeys->all()) {
            if (($sizeAxis['variation'] ?? null) !== true) {
                return [...$safe, 'mode' => 'legacy_over_informational_size'];
            }

            return [
                'verified' => false,
                'mode' => null,
                'reason' => 'Wariant i aktywny Rozmiar mają różne zbiory wartości.',
                'size_attribute_id' => (int) ($sizeAxis['id'] ?? 0),
                'option_keys' => [],
            ];
        }

        return [
            'verified' => true,
            'mode' => 'parallel_exact',
            'reason' => null,
            'size_attribute_id' => (int) ($sizeAxis['id'] ?? 0),
            'option_keys' => $legacyKeys->all(),
        ];
    }

    /** @param array<string,mixed> $attribute */
    private function isLegacyAttribute(array $attribute): bool
    {
        return collect([$attribute['name'] ?? null, $attribute['slug'] ?? null])
            ->map(fn (mixed $name): string => $this->attributeKey((string) $name))
            ->contains(fn (string $name): bool => in_array($name, self::LEGACY_ATTRIBUTE_NAMES, true));
    }

    /** @param array<string,mixed> $attribute */
    private function isSizeAttribute(array $attribute): bool
    {
        return collect([$attribute['name'] ?? null, $attribute['slug'] ?? null])
            ->map(fn (mixed $name): string => $this->attributeKey((string) $name))
            ->contains(fn (string $name): bool => in_array($name, ['rozmiar', 'size'], true));
    }

    private function attributeKey(string $name): string
    {
        $name = Str::slug($name);

        return str_starts_with($name, 'pa-') ? substr($name, 3) : $name;
    }
}
