<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\Product;
use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductRelation;
use App\Models\WordpressIntegration;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Rebind ERP → WooCommerce variation mappings onto the IDs that WooCommerce
 * currently owns. An operator who deletes and recreates variations (or the
 * whole variable product) in Woo admin leaves the ERP holding stale
 * `external_variation_id` / `external_product_id` values, so every export and
 * stock push then targets a dead ID. Matching is done exclusively on the
 * ERP-stable SKU (with an attribute-signature fallback), never on position, so
 * a rebind can never silently point a mapping at the wrong product. The service
 * never deletes variations and never touches stock quantities.
 */
final class WooVariationMappingRelinker
{
    public function __construct(private readonly WooCommerceClient $client) {}

    /**
     * Recover a single mapped variation during a full product export, where the
     * caller has already built the canonical variation payload. Adopts an
     * existing live SKU/attribute match, otherwise recreates the confirmed-
     * missing child, then atomically replaces only the stale local mapping.
     *
     * @param  array<string, mixed>  $payload
     * @return array{mapping: ProductChannelMapping, response: array<string, mixed>, operation: string}
     */
    public function recoverByPayload(
        Product $parent,
        Product $variant,
        WordpressIntegration $integration,
        ProductChannelMapping $mapping,
        array $payload,
    ): array {
        $externalProductId = trim((string) $mapping->external_product_id);
        $staleVariationId = trim((string) $mapping->external_variation_id);
        $liveVariations = $this->liveVariations($integration, $externalProductId);
        $match = $this->matchLiveVariation($liveVariations, $variant, (array) ($payload['attributes'] ?? []), $externalProductId, $staleVariationId);

        $operation = 'recover_product_variation_mapping';

        if (is_array($match)) {
            $response = $this->client->updateProductDataByIds(
                $integration,
                $externalProductId,
                (string) $match['id'],
                $payload,
            );
            $resolvedVariationId = trim((string) $match['id']);
        } else {
            $created = $this->createVariationResolvingSkuConflict(
                $integration,
                (int) $mapping->sales_channel_id,
                $externalProductId,
                $payload,
            );
            $response = $created['response'];
            $resolvedVariationId = trim((string) ($response['id'] ?? ''));
            $operation = 'recreate_product_variation';
        }

        if (! ctype_digit($resolvedVariationId) || (int) $resolvedVariationId <= 0) {
            throw new RuntimeException(
                "WooCommerce nie zwrócił nowego ID dla usuniętego wariantu {$variant->sku} produktu #{$externalProductId}.",
            );
        }

        $this->rebindMappingVariation(
            mapping: $mapping,
            variant: $variant,
            externalProductId: $externalProductId,
            staleVariationId: $staleVariationId,
            resolvedVariationId: $resolvedVariationId,
            resolvedSku: trim((string) ($response['sku'] ?? '')) ?: $variant->sku,
            mode: $operation === 'recreate_product_variation' ? 'recreated' : 'adopted_existing',
        );

        $mapping->refresh();

        return [
            'mapping' => $mapping,
            'response' => $response,
            'operation' => $operation,
        ];
    }

    /**
     * Create a primary-language variation, resolving a duplicate-SKU rejection
     * instead of failing the whole export. After an operator deletes the PL
     * variations by hand, the family's translated siblings (created via the
     * temporary-SKU flow in createProductVariationForLanguage and later given
     * the canonical SKU once Polylang linked the posts) may still own that SKU.
     * A fresh, not-yet-linked PL post is then rejected as a duplicate.
     *
     * Resolution, strictly narrowed by productBySku ownership:
     * - owner is a live variation under THIS parent → adopt it (PUT payload),
     * - owner is one of the family's own translated posts (a channel alias) →
     *   create without `sku`; the canonical SKU returns on the next full
     *   export's PUT once Polylang links the new PL post to that sibling,
     * - anything else → rethrow, the SKU genuinely belongs to a foreign post.
     *
     * @param  array<string, mixed>  $payload
     * @return array{response: array<string, mixed>, resolution: string}
     */
    public function createVariationResolvingSkuConflict(
        WordpressIntegration $integration,
        int $salesChannelId,
        string $externalProductId,
        array $payload,
    ): array {
        try {
            return [
                'response' => $this->client->createProductVariation($integration, $externalProductId, $payload),
                'resolution' => 'created',
            ];
        } catch (WooCommerceProductVariationCreateException $exception) {
            $sku = trim((string) ($payload['sku'] ?? ''));

            if (! $exception->indicatesDuplicateSku() || $sku === '') {
                throw $exception;
            }

            $owner = $this->client->productBySku($integration, $sku);
            $ownerId = trim((string) data_get($owner, 'id', ''));
            $ownerParentId = trim((string) data_get($owner, 'parent_id', ''));
            $ownerIsVariation = (string) data_get($owner, 'type', '') === 'variation';

            if ($owner === null || $ownerId === '') {
                throw $exception;
            }

            if ($ownerIsVariation && $ownerParentId === $externalProductId) {
                $response = $this->client->updateProductDataByIds(
                    $integration,
                    $externalProductId,
                    $ownerId,
                    $payload,
                );

                return [
                    'response' => array_merge($response, ['id' => (int) $ownerId]),
                    'resolution' => 'adopted_same_parent',
                ];
            }

            $ownedByFamilyTranslation = $ownerIsVariation
                && $ownerParentId !== ''
                && ProductChannelAlias::query()
                    ->where('sales_channel_id', $salesChannelId)
                    ->where('external_product_id', $ownerParentId)
                    ->exists();

            if ($ownedByFamilyTranslation) {
                unset($payload['sku']);

                return [
                    'response' => $this->client->createProductVariation($integration, $externalProductId, $payload),
                    'resolution' => 'created_without_sku',
                ];
            }

            throw new RuntimeException(
                $exception->getMessage()
                    ." SKU {$sku} należy do WooCommerce #{$ownerId}"
                    .($ownerParentId !== '' ? " (rodzic #{$ownerParentId})" : '')
                    .' spoza tej rodziny — rozwiąż konflikt ręcznie.',
                previous: $exception,
            );
        }
    }

    /**
     * Adopt-only relink of an entire variant family. Reads the live parent and
     * its variations from WooCommerce, then repoints each ERP variant mapping
     * whose stored ID no longer matches the live SKU. Never pushes commercial
     * data (a subsequent export does that) and never recreates missing children
     * — those are reported so the caller can run a full export to recreate them.
     *
     * @return array{
     *     parent: array<string, mixed>,
     *     variants: list<array<string, mixed>>,
     *     changed: int,
     * }
     */
    public function relinkFamily(
        Product $parent,
        WordpressIntegration $integration,
        int $salesChannelId,
        bool $dryRun = false,
    ): array {
        $parentMapping = ProductChannelMapping::query()
            ->where('product_id', $parent->id)
            ->where('sales_channel_id', $salesChannelId)
            ->whereNull('external_variation_id')
            ->first();

        if (! $parentMapping instanceof ProductChannelMapping
            || trim((string) $parentMapping->external_product_id) === ''
        ) {
            throw new RuntimeException(
                "Produkt {$parent->sku} nie ma mapowania rodzica do kanału #{$salesChannelId}.",
            );
        }

        $changed = 0;
        $storedParentId = trim((string) $parentMapping->external_product_id);
        $effectiveParentId = $storedParentId;
        $parentReport = [
            'sku' => $parent->sku,
            'stored_external_product_id' => $storedParentId,
            'live_external_product_id' => $storedParentId,
            'status' => 'ok',
        ];

        if (! $this->productExists($integration, $storedParentId)) {
            $liveParent = $this->client->productBySku($integration, (string) $parent->sku);
            $liveParentId = $liveParent !== null ? trim((string) ($liveParent['id'] ?? '')) : '';

            if ($liveParentId === '' || ! ctype_digit($liveParentId)) {
                $parentReport['status'] = 'parent_missing';
                $parentReport['live_external_product_id'] = '';

                return ['parent' => $parentReport, 'variants' => [], 'changed' => 0];
            }

            $effectiveParentId = $liveParentId;
            $parentReport['live_external_product_id'] = $liveParentId;
            $parentReport['status'] = 'relink';

            if (! $dryRun) {
                $this->rebindParentMapping($parentMapping, $liveParentId);
                $changed++;
            }
        }

        $liveVariations = $this->liveVariations($integration, $effectiveParentId);
        $variantReports = [];

        foreach ($this->familyVariantMappings($parent, $salesChannelId) as $entry) {
            /** @var Product $variant */
            $variant = $entry['variant'];
            /** @var ProductChannelMapping $mapping */
            $mapping = $entry['mapping'];
            $storedVariationId = trim((string) $mapping->external_variation_id);
            $match = $this->matchLiveVariationBySku($liveVariations, $variant);
            $liveVariationId = is_array($match) ? trim((string) ($match['id'] ?? '')) : '';

            $report = [
                'sku' => $variant->sku,
                'stored_external_variation_id' => $storedVariationId,
                'live_external_variation_id' => $liveVariationId,
                'status' => 'ok',
            ];

            if ($liveVariationId === '' || ! ctype_digit($liveVariationId)) {
                $report['status'] = 'missing_in_woo';
            } elseif ($liveVariationId !== $storedVariationId
                || trim((string) $mapping->external_product_id) !== $effectiveParentId
            ) {
                $report['status'] = 'relink';

                if (! $dryRun) {
                    $this->rebindMappingVariation(
                        mapping: $mapping,
                        variant: $variant,
                        externalProductId: $effectiveParentId,
                        staleVariationId: $storedVariationId,
                        resolvedVariationId: $liveVariationId,
                        resolvedSku: trim((string) ($match['sku'] ?? '')) ?: $variant->sku,
                        mode: 'relinked_by_command',
                    );
                    $changed++;
                }
            }

            $variantReports[] = $report;
        }

        return [
            'parent' => $parentReport,
            'variants' => $variantReports,
            'changed' => $changed,
        ];
    }

    /**
     * A deleted-and-recreated variation returns this exact Woo error code on a
     * 404. Callers on the stock and metadata paths use it to trigger a relink
     * instead of failing the export permanently.
     */
    public static function isDeletedVariationResponse(RequestException $exception): bool
    {
        return $exception->response?->status() === 404
            && trim((string) data_get($exception->response?->json(), 'code'))
                === 'woocommerce_rest_product_variation_invalid_id';
    }

    /**
     * Adopt-only relink of a single persisted variant mapping onto the live
     * SKU-matched variation. Returns the refreshed mapping when it now points
     * at the current live ID (or was already correct), or null when no
     * unambiguous live match exists — parent gone, SKU missing, or several
     * matches — so the caller can fail safely without losing the quantity.
     */
    public function relinkVariationBySku(
        ProductChannelMapping $mapping,
        WordpressIntegration $integration,
    ): ?ProductChannelMapping {
        $variant = $mapping->product;
        $storedVariationId = trim((string) $mapping->external_variation_id);
        $externalProductId = trim((string) $mapping->external_product_id);

        if (! $variant instanceof Product
            || $storedVariationId === ''
            || $externalProductId === ''
        ) {
            return null;
        }

        try {
            $liveVariations = $this->liveVariations($integration, $externalProductId);
            $match = $this->matchLiveVariationBySku($liveVariations, $variant);
        } catch (RequestException) {
            // Parent likely deleted too; a single-variation relink cannot
            // resolve it. Leave it for the family relink / manual button.
            return null;
        } catch (RuntimeException) {
            // Ambiguous live SKU match — never rebind arbitrarily on the hot
            // stock path; the caller keeps the original failure.
            return null;
        }

        $liveVariationId = is_array($match) ? trim((string) ($match['id'] ?? '')) : '';

        if ($liveVariationId === '' || ! ctype_digit($liveVariationId)) {
            return null;
        }

        if ($liveVariationId === $storedVariationId) {
            return $mapping;
        }

        $this->rebindMappingVariation(
            mapping: $mapping,
            variant: $variant,
            externalProductId: $externalProductId,
            staleVariationId: $storedVariationId,
            resolvedVariationId: $liveVariationId,
            resolvedSku: trim((string) ($match['sku'] ?? '')) ?: $variant->sku,
            mode: 'relinked_by_stock_sync',
        );

        return $mapping;
    }

    /**
     * @param  list<array<string, mixed>>  $liveVariations
     * @param  list<array<string, mixed>>  $payloadAttributes
     * @return array<string, mixed>|null
     */
    private function matchLiveVariation(
        Collection $liveVariations,
        Product $variant,
        array $payloadAttributes,
        string $externalProductId,
        string $staleVariationId,
    ): ?array {
        $localSku = mb_strtoupper(trim((string) $variant->sku));
        $skuMatches = $localSku === ''
            ? collect()
            : $liveVariations->filter(fn (array $remote): bool => mb_strtoupper(
                trim((string) ($remote['sku'] ?? '')),
            ) === $localSku)->values();

        if ($skuMatches->count() > 1) {
            throw new RuntimeException(
                "WooCommerce zawiera kilka wariantów SKU {$variant->sku} pod produktem #{$externalProductId}; stare mapowanie #{$staleVariationId} nie zostanie zastąpione arbitralnie.",
            );
        }

        $match = $skuMatches->first();

        if (is_array($match)) {
            return $match;
        }

        $payloadSignature = $this->variationAttributeSignature($payloadAttributes);
        $attributeMatches = $payloadSignature === ''
            ? collect()
            : $liveVariations->filter(fn (array $remote): bool => $this->variationAttributeSignature(
                (array) ($remote['attributes'] ?? []),
            ) === $payloadSignature)->values();

        if ($attributeMatches->count() > 1) {
            throw new RuntimeException(
                "WooCommerce zawiera kilka wariantów z tym samym rozmiarem pod produktem #{$externalProductId}; stare mapowanie #{$staleVariationId} wymaga ręcznej weryfikacji.",
            );
        }

        return $attributeMatches->first();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $liveVariations
     * @return array<string, mixed>|null
     */
    private function matchLiveVariationBySku(Collection $liveVariations, Product $variant): ?array
    {
        $localSku = mb_strtoupper(trim((string) $variant->sku));

        if ($localSku === '') {
            return null;
        }

        $matches = $liveVariations->filter(fn (array $remote): bool => mb_strtoupper(
            trim((string) ($remote['sku'] ?? '')),
        ) === $localSku)->values();

        if ($matches->count() > 1) {
            throw new RuntimeException(
                "WooCommerce zawiera kilka wariantów SKU {$variant->sku}; relink nie zostanie wykonany arbitralnie.",
            );
        }

        return $matches->first();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function liveVariations(WordpressIntegration $integration, string $externalProductId): Collection
    {
        return collect($this->client->productVariationsByParent($integration, $externalProductId))
            ->filter(fn (mixed $remote): bool => is_array($remote)
                && ctype_digit(trim((string) ($remote['id'] ?? '')))
                && (int) $remote['id'] > 0)
            ->unique(fn (array $remote): string => trim((string) $remote['id']))
            ->values();
    }

    /**
     * @return list<array{variant: Product, mapping: ProductChannelMapping}>
     */
    private function familyVariantMappings(Product $parent, int $salesChannelId): array
    {
        $childIds = ProductRelation::query()
            ->where('parent_product_id', $parent->id)
            ->where('relation_type', 'variant')
            ->orderBy('sort_order')
            ->pluck('child_product_id');

        if ($childIds->isEmpty()) {
            return [];
        }

        $variants = Product::query()
            ->whereIn('id', $childIds)
            ->get()
            ->keyBy('id');
        $entries = [];

        foreach ($childIds as $childId) {
            $variant = $variants->get($childId);

            if (! $variant instanceof Product) {
                continue;
            }

            $mapping = ProductChannelMapping::query()
                ->where('product_id', $variant->id)
                ->where('sales_channel_id', $salesChannelId)
                ->whereNotNull('external_variation_id')
                ->first();

            if ($mapping instanceof ProductChannelMapping) {
                $entries[] = ['variant' => $variant, 'mapping' => $mapping];
            }
        }

        return $entries;
    }

    private function productExists(WordpressIntegration $integration, string $externalProductId): bool
    {
        if (trim($externalProductId) === '') {
            return false;
        }

        try {
            $this->client->productById($integration, $externalProductId);

            return true;
        } catch (RequestException $exception) {
            // Only a genuine 404 means the parent was deleted. Any other status
            // (auth error, 5xx) must propagate so a transient fault is never
            // mistaken for a missing parent and used to rebind the mapping.
            if ($exception->response?->status() === 404) {
                return false;
            }

            throw $exception;
        }
    }

    private function rebindParentMapping(ProductChannelMapping $mapping, string $newExternalProductId): void
    {
        DB::transaction(function () use ($mapping, $newExternalProductId): void {
            $locked = ProductChannelMapping::query()->lockForUpdate()->findOrFail($mapping->id);
            $metadata = (array) $locked->metadata;
            data_set($metadata, 'deleted_parent_recovery', [
                'old_product_id' => trim((string) $locked->external_product_id),
                'new_product_id' => $newExternalProductId,
                'mode' => 'relinked_by_command',
                'recovered_at' => now()->toISOString(),
            ]);
            $locked->forceFill([
                'external_product_id' => $newExternalProductId,
                'metadata' => $metadata,
            ])->save();
        }, 3);

        $mapping->refresh();
    }

    private function rebindMappingVariation(
        ProductChannelMapping $mapping,
        Product $variant,
        string $externalProductId,
        string $staleVariationId,
        string $resolvedVariationId,
        string $resolvedSku,
        string $mode,
    ): void {
        DB::transaction(function () use (
            $mapping,
            $variant,
            $externalProductId,
            $staleVariationId,
            $resolvedVariationId,
            $resolvedSku,
            $mode,
        ): void {
            $locked = ProductChannelMapping::query()
                ->lockForUpdate()
                ->findOrFail($mapping->id);
            $currentVariationId = trim((string) $locked->external_variation_id);

            if ($currentVariationId !== $staleVariationId
                && $currentVariationId !== $resolvedVariationId
            ) {
                throw new RuntimeException(
                    "Mapowanie wariantu {$variant->sku} zmieniło się równolegle; nowe ID WooCommerce nie zostanie nadpisane.",
                );
            }

            $mappingOwner = ProductChannelMapping::query()
                ->where('sales_channel_id', $locked->sales_channel_id)
                ->where('external_product_id', $externalProductId)
                ->where('external_variation_id', $resolvedVariationId)
                ->whereKeyNot($locked->id)
                ->lockForUpdate()
                ->value('product_id');

            if ($mappingOwner !== null && (int) $mappingOwner !== (int) $variant->id) {
                throw new RuntimeException(
                    "Nowe ID wariantu WooCommerce #{$externalProductId}/{$resolvedVariationId} jest już mapowaniem innego produktu ERP.",
                );
            }

            $alias = ProductChannelAlias::query()
                ->forExternalIdentity(
                    (int) $locked->sales_channel_id,
                    $externalProductId,
                    $resolvedVariationId,
                )
                ->lockForUpdate()
                ->first();

            if ($alias instanceof ProductChannelAlias
                && (int) $alias->product_id !== (int) $variant->id
            ) {
                throw new RuntimeException(
                    "Nowe ID wariantu WooCommerce #{$externalProductId}/{$resolvedVariationId} jest już aliasem innego produktu ERP.",
                );
            }

            $alias?->delete();
            $metadata = (array) $locked->metadata;
            data_set($metadata, 'deleted_variation_recovery', [
                'old_variation_id' => $staleVariationId,
                'new_variation_id' => $resolvedVariationId,
                'mode' => $mode,
                'recovered_at' => now()->toISOString(),
            ]);
            $locked->forceFill([
                'external_product_id' => $externalProductId,
                'external_variation_id' => $resolvedVariationId,
                'external_sku' => $resolvedSku,
                'metadata' => $metadata,
            ])->save();
        }, 3);

        $mapping->refresh();
    }

    /** @param list<array<string, mixed>> $attributes */
    private function variationAttributeSignature(array $attributes): string
    {
        $normalized = collect($attributes)
            ->filter(fn (mixed $attribute): bool => is_array($attribute))
            ->mapWithKeys(function (array $attribute): array {
                $attributeId = (int) ($attribute['id'] ?? 0);
                $key = $attributeId > 0
                    ? 'id:'.$attributeId
                    : 'name:'.Str::slug((string) ($attribute['name'] ?? ''));
                $option = Str::slug((string) ($attribute['option'] ?? ''));

                return $key !== 'name:' && $option !== '' ? [$key => $option] : [];
            })
            ->sortKeys()
            ->all();

        return $normalized === []
            ? ''
            : hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}
