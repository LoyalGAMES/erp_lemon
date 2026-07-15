<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductChannelAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'sales_channel_id',
        'source_product_id',
        'external_product_id',
        'external_variation_id',
        'external_key',
        'external_sku',
        'language',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $alias): void {
            $alias->external_product_id = trim((string) $alias->external_product_id);
            $alias->external_variation_id = self::normalizeVariationId($alias->external_variation_id);
            $alias->external_key = self::externalKey(
                $alias->external_product_id,
                $alias->external_variation_id,
            );
        });
    }

    public static function externalKey(string $productId, ?string $variationId): string
    {
        $productId = trim($productId);
        $variationId = self::normalizeVariationId($variationId);

        return 'product:'.$productId.'|variation:'.($variationId ?? 'parent');
    }

    public function scopeForExternalIdentity(
        Builder $query,
        int $salesChannelId,
        string $externalProductId,
        ?string $externalVariationId = null,
    ): Builder {
        return $query
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_key', self::externalKey($externalProductId, $externalVariationId));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'source_product_id');
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function isOutboundSyncEnabled(): bool
    {
        return data_get(
            $this->metadata,
            'maintenance.woo_owned_variant_axis_repair.routing_only',
        ) !== true;
    }

    private static function normalizeVariationId(mixed $variationId): ?string
    {
        if ($variationId === null) {
            return null;
        }

        $variationId = trim((string) $variationId);

        return $variationId === '' || $variationId === '0' ? null : $variationId;
    }
}
