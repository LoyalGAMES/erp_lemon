<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('product_parameter_definitions')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $this->normalizeCanonicalSizeDictionary();

        $repair = app(WooOwnedVariantAxisRepairService::class);
        $visited = [];

        ProductChannelMapping::query()
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with([
                'product.parentRelations',
                'product.variantChildren.parentRelations',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($repair, &$visited): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                    ) {
                        continue;
                    }

                    $visited[$product->id] = true;

                    if (! $repair->isComplementaryLanguageSizeRootCandidate($product)) {
                        continue;
                    }

                    $repair->markPending($product);
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A queued remote-first repair may already have
        // completed and must never be reverted to the damaged legacy axis.
    }

    /**
     * Reapply the canonical backend order after 000024 deliberately retained
     * the historical operator sequence. PL/EN values move as one pair, and
     * unknown values retain their relative order behind known sizes.
     */
    private function normalizeCanonicalSizeDictionary(): void
    {
        $candidates = ProductParameterDefinition::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductParameterDefinition $definition): bool => mb_strtolower(
                trim((string) $definition->name),
            ) === 'rozmiar' && mb_strtolower(
                trim((string) $definition->slug),
            ) === 'rozmiar')
            ->values();

        if ($candidates->count() !== 1) {
            return;
        }

        $definition = $candidates->first();
        $rawValues = $definition->values;
        $rawValuesEn = $definition->values_en;

        if (! is_array($rawValues)) {
            return;
        }

        $values = array_values($rawValues);

        if ($values === []
            || collect($values)->contains(fn (mixed $value): bool => trim((string) $value) === '')
            || ($rawValuesEn !== null
                && (! is_array($rawValuesEn) || count($rawValuesEn) !== count($values)))
        ) {
            // A blank PL row or malformed EN tail cannot be moved without
            // risking loss of an operator-managed PL/EN pair.
            return;
        }

        $valuesEn = $rawValuesEn === null ? null : array_values($rawValuesEn);
        $pairs = collect($values)
            ->map(fn (mixed $value, int $index): array => [
                'value' => trim((string) $value),
                'value_en' => $valuesEn === null
                    ? null
                    : trim((string) ($valuesEn[$index] ?? '')),
                'index' => $index,
                'rank' => $this->canonicalSizeRank((string) $value),
            ])
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
            ->values();
        $targetValues = $pairs->pluck('value')->all();
        $targetValuesEn = $valuesEn === null
            ? null
            : $pairs->pluck('value_en')->all();

        if ($targetValues === $values && $targetValuesEn === $valuesEn) {
            return;
        }

        $definition->forceFill([
            'values' => $targetValues,
            'values_en' => $targetValuesEn,
            'metadata' => array_replace_recursive((array) $definition->metadata, [
                'storefront_value_order' => [
                    'normalized_by' => WooOwnedVariantAxisRepairService::REVISION,
                    'normalized_at' => now()->toISOString(),
                ],
            ]),
        ])->saveQuietly();
    }

    private function canonicalSizeRank(string $value): ?int
    {
        $value = mb_strtoupper(trim((string) preg_replace('/\s+/u', '', $value)));
        $value = str_replace(['–', '—', '-'], '/', $value);

        if (in_array($value, [
            'ONESIZE',
            'ONE/SIZE',
            'UNI',
            'UNIWERSALNY',
            'UNIWERSALNA',
        ], true)) {
            return 0;
        }

        $tokens = explode('/', $value);
        $tokenRanks = collect($tokens)
            ->map(fn (string $token): ?int => $this->canonicalSizeTokenRank($token));

        if ($tokenRanks->isNotEmpty()
            && ! $tokenRanks->contains(fn (?int $rank): bool => $rank === null)
        ) {
            return (int) round($tokenRanks->average());
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)(?:\/(\d+(?:[.,]\d+)?))?$/', $value, $matches) === 1) {
            $from = (float) str_replace(',', '.', $matches[1]);
            $to = isset($matches[2]) ? (float) str_replace(',', '.', $matches[2]) : $from;

            return 10_000 + (int) round($from * 100) + (int) round($to);
        }

        return null;
    }

    private function canonicalSizeTokenRank(string $token): ?int
    {
        if ($token === 'S') {
            return 500;
        }

        if ($token === 'M') {
            return 600;
        }

        if ($token === 'L') {
            return 700;
        }

        if ($token === 'XS') {
            return 400;
        }

        if (preg_match('/^X{2,6}S$/', $token) === 1) {
            return 400 - ((substr_count($token, 'X') - 1) * 100);
        }

        if (preg_match('/^([2-6])XS$/', $token, $matches) === 1) {
            return 400 - (((int) $matches[1] - 1) * 100);
        }

        if ($token === 'XL') {
            return 800;
        }

        if (preg_match('/^X{2,6}L$/', $token) === 1) {
            return 800 + ((substr_count($token, 'X') - 1) * 100);
        }

        if (preg_match('/^([2-9])XL$/', $token, $matches) === 1) {
            return 800 + (((int) $matches[1] - 1) * 100);
        }

        return null;
    }
};
