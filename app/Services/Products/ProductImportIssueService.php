<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use Illuminate\Support\Collection;

final class ProductImportIssueService
{
    /**
     * Builds a stable diagnostic view for one concrete product import log.
     *
     * New logs provide `duplicate_sku_groups`. For logs created before that
     * contract existed we reconstruct the current duplicate SKU groups from
     * channel mappings, keeping products and variations in separate groups.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(mixed $requestedLogId): ?array
    {
        $requestedLogId = $this->nullableString($requestedLogId);

        if ($requestedLogId === null) {
            return null;
        }

        abort_unless(ctype_digit($requestedLogId), 404);

        $log = IntegrationSyncLog::query()
            ->with('salesChannel:id,code,name')
            ->where('operation', 'import_products')
            ->findOrFail((int) $requestedLogId);
        $payload = (array) $log->response_payload;
        $hasDiagnosticSnapshot = array_key_exists('duplicate_sku_groups', $payload);
        $groups = $this->normalizeDuplicateSkuGroups((array) ($payload['duplicate_sku_groups'] ?? []));
        $usesCurrentMappings = ! $hasDiagnosticSnapshot;

        if ($usesCurrentMappings) {
            $groups = $this->currentDuplicateSkuGroups((int) $log->sales_channel_id);
        }

        $reportedProductIds = collect($groups)
            ->flatMap(fn (array $group): array => collect($group['items'])
                ->pluck('erp_product_id')
                ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
                ->all())
            ->unique()
            ->values();
        $existingProductIds = Product::query()
            ->whereIn('id', $reportedProductIds->all())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $existingProductIdLookup = array_fill_keys($existingProductIds, true);

        $groups = collect($groups)
            ->map(function (array $group) use ($existingProductIdLookup): array {
                $group['items'] = collect($group['items'])
                    ->map(function (array $item) use ($existingProductIdLookup): array {
                        $productId = $item['erp_product_id'] ?? null;
                        $item['erp_product_id'] = is_int($productId) && isset($existingProductIdLookup[$productId])
                            ? $productId
                            : null;

                        return $item;
                    })
                    ->values()
                    ->all();

                return $group;
            })
            ->values()
            ->all();

        $targetIds = collect($groups)
            ->flatMap(fn (array $group): array => collect($group['items'])->pluck('erp_product_id')->filter()->all())
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $targetIdLookup = array_fill_keys($targetIds, true);
        $fallbackSkus = ['product' => [], 'variation' => []];

        foreach ($groups as $group) {
            $hasMappedProduct = collect($group['items'])
                ->contains(fn (array $item): bool => isset($targetIdLookup[(int) ($item['erp_product_id'] ?? 0)]));

            if (! $hasMappedProduct) {
                $fallbackSkus[$group['entity_kind']][] = mb_strtolower($group['sku']);
            }
        }

        return [
            'log_id' => (int) $log->id,
            'created_at' => $log->created_at,
            'channel_code' => $log->salesChannel?->code,
            'reported_duplicate_items' => (int) ($payload['duplicate_sku_items'] ?? 0),
            'groups' => $groups,
            'uses_current_mappings' => $usesCurrentMappings,
            'targets' => [
                'ids' => $targetIds,
                'product_skus' => collect($fallbackSkus['product'])->unique()->values()->all(),
                'variation_skus' => collect($fallbackSkus['variation'])->unique()->values()->all(),
            ],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $groups
     * @return list<array{sku:string,entity_kind:string,occurrences:int,items:list<array<string,mixed>>}>
     */
    private function normalizeDuplicateSkuGroups(array $groups): array
    {
        return collect($groups)
            ->filter(fn (mixed $group): bool => is_array($group))
            ->map(function (array $group): ?array {
                $sku = trim((string) ($group['sku'] ?? ''));

                if ($sku === '') {
                    return null;
                }

                $entityKind = in_array($group['entity_kind'] ?? null, ['product', 'variation'], true)
                    ? (string) $group['entity_kind']
                    : 'product';
                $items = collect((array) ($group['items'] ?? []))
                    ->filter(fn (mixed $item): bool => is_array($item))
                    ->map(function (array $item): array {
                        $permalink = trim((string) ($item['permalink'] ?? ''));
                        $scheme = mb_strtolower((string) parse_url($permalink, PHP_URL_SCHEME));

                        return [
                            'woo_product_id' => $this->nullableString($item['woo_product_id'] ?? null),
                            'woo_variation_id' => $this->nullableString($item['woo_variation_id'] ?? null),
                            'erp_product_id' => filter_var($item['erp_product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null,
                            'name' => $this->nullableString($item['name'] ?? null),
                            'language' => $this->nullableString($item['language'] ?? null),
                            'permalink' => in_array($scheme, ['http', 'https'], true) ? $permalink : null,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    'sku' => $sku,
                    'entity_kind' => $entityKind,
                    'occurrences' => max((int) ($group['occurrences'] ?? 0), count($items)),
                    'items' => $items,
                ];
            })
            ->filter()
            ->unique(fn (array $group): string => $group['entity_kind'].'|'.mb_strtolower($group['sku']))
            ->values()
            ->all();
    }

    /**
     * @return list<array{sku:string,entity_kind:string,occurrences:int,items:list<array<string,mixed>>}>
     */
    private function currentDuplicateSkuGroups(int $salesChannelId): array
    {
        return ProductChannelMapping::query()
            ->with('product:id,sku,name')
            ->where('sales_channel_id', $salesChannelId)
            ->whereNotNull('external_sku')
            ->where('external_sku', '!=', '')
            ->orderBy('id')
            ->get([
                'id',
                'product_id',
                'sales_channel_id',
                'external_product_id',
                'external_variation_id',
                'external_sku',
                'metadata',
            ])
            ->filter(fn (ProductChannelMapping $mapping): bool => trim((string) $mapping->external_sku) !== '')
            ->groupBy(function (ProductChannelMapping $mapping): string {
                $entityKind = filled($mapping->external_variation_id) ? 'variation' : 'product';

                return $entityKind.'|'.mb_strtolower(trim((string) $mapping->external_sku));
            })
            ->map(fn (Collection $mappings): Collection => $mappings
                ->unique(fn (ProductChannelMapping $mapping): string => (string) $mapping->external_product_id.'|'.(filled($mapping->external_variation_id) ? (string) $mapping->external_variation_id : 'parent'))
                ->values())
            ->filter(fn (Collection $mappings): bool => $mappings->count() > 1)
            ->map(function (Collection $mappings): array {
                /** @var ProductChannelMapping $first */
                $first = $mappings->first();
                $entityKind = filled($first->external_variation_id) ? 'variation' : 'product';

                return [
                    'sku' => trim((string) $first->external_sku),
                    'entity_kind' => $entityKind,
                    'occurrences' => $mappings->count(),
                    'items' => $mappings
                        ->map(fn (ProductChannelMapping $mapping): array => [
                            'woo_product_id' => (string) $mapping->external_product_id,
                            'woo_variation_id' => $mapping->external_variation_id !== null
                                ? (string) $mapping->external_variation_id
                                : null,
                            'erp_product_id' => (int) $mapping->product_id,
                            'name' => $mapping->product?->name,
                            'language' => $this->nullableString(data_get($mapping->metadata, 'language')),
                            'permalink' => null,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $group): string => mb_strtolower($group['sku']))
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
