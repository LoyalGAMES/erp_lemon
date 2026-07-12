<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'product_channel_external_identity_unique';

    public function up(): void
    {
        $collisions = $this->existingCollisions();

        if ($collisions !== []) {
            throw new RuntimeException(
                'Nie można włączyć unikalności mapowań WooCommerce. '
                .'Te same zewnętrzne tożsamości są przypisane do wielu produktów: '
                .json_encode($collisions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            );
        }

        Schema::table('product_channel_mappings', function (Blueprint $table): void {
            $table->char('external_identity_key', 64)
                ->nullable()
                ->after('external_variation_id');
        });

        DB::table('product_channel_mappings')
            ->select(['id', 'external_product_id', 'external_variation_id'])
            ->orderBy('id')
            ->chunkById(500, function ($mappings): void {
                foreach ($mappings as $mapping) {
                    DB::table('product_channel_mappings')
                        ->where('id', $mapping->id)
                        ->update([
                            'external_product_id' => $this->normalizeProductId(
                                (string) $mapping->external_product_id,
                            ),
                            'external_variation_id' => $this->normalizeVariationId(
                                $mapping->external_variation_id,
                            ),
                            'external_identity_key' => $this->identityKey(
                                (string) $mapping->external_product_id,
                                $mapping->external_variation_id,
                            ),
                        ]);
                }
            });

        Schema::table('product_channel_mappings', function (Blueprint $table): void {
            $table->char('external_identity_key', 64)
                ->nullable(false)
                ->change();
            $table->unique(
                ['sales_channel_id', 'external_identity_key'],
                self::UNIQUE_INDEX,
            );
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('external_identity_key');
        });
    }

    /**
     * @return list<array{
     *     sales_channel_id:int,
     *     external_product_id:string,
     *     external_variation_id:?string,
     *     first_mapping_id:int,
     *     first_product_id:int,
     *     conflicting_mapping_id:int,
     *     conflicting_product_id:int
     * }>
     */
    private function existingCollisions(): array
    {
        $seen = [];
        $collisions = [];

        DB::table('product_channel_mappings')
            ->select([
                'id',
                'product_id',
                'sales_channel_id',
                'external_product_id',
                'external_variation_id',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($mappings) use (&$seen, &$collisions): ?bool {
                foreach ($mappings as $mapping) {
                    $identityKey = $this->identityKey(
                        (string) $mapping->external_product_id,
                        $mapping->external_variation_id,
                    );
                    $channelIdentity = (int) $mapping->sales_channel_id.':'.$identityKey;

                    if (! isset($seen[$channelIdentity])) {
                        $seen[$channelIdentity] = $mapping;

                        continue;
                    }

                    $first = $seen[$channelIdentity];
                    $collisions[] = [
                        'sales_channel_id' => (int) $mapping->sales_channel_id,
                        'external_product_id' => $this->normalizeProductId((string) $mapping->external_product_id),
                        'external_variation_id' => $this->normalizeVariationId($mapping->external_variation_id),
                        'first_mapping_id' => (int) $first->id,
                        'first_product_id' => (int) $first->product_id,
                        'conflicting_mapping_id' => (int) $mapping->id,
                        'conflicting_product_id' => (int) $mapping->product_id,
                    ];

                    if (count($collisions) >= 20) {
                        return false;
                    }
                }

                return null;
            });

        return $collisions;
    }

    private function identityKey(string $productId, mixed $variationId): string
    {
        return hash('sha256', implode("\0", [
            'product',
            $this->normalizeProductId($productId),
            'variation',
            $this->normalizeVariationId($variationId) ?? 'parent',
        ]));
    }

    private function normalizeProductId(string $productId): string
    {
        return trim($productId);
    }

    private function normalizeVariationId(mixed $variationId): ?string
    {
        if ($variationId === null) {
            return null;
        }

        $variationId = trim((string) $variationId);

        return $variationId === '' || $variationId === '0' ? null : $variationId;
    }
};
