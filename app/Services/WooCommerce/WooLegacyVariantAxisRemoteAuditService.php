<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
            'rows' => $rows->all(),
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
