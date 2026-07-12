<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductChannelMapping extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $mapping): void {
            $mapping->external_product_id = trim((string) $mapping->external_product_id);
            $mapping->external_variation_id = self::normalizeVariationId($mapping->external_variation_id);
            $mapping->external_identity_key = self::externalIdentityKey(
                $mapping->external_product_id,
                $mapping->external_variation_id,
            );
        });
    }

    protected $fillable = [
        'product_id',
        'sales_channel_id',
        'external_product_id',
        'external_variation_id',
        'external_sku',
        'stock_sync_enabled',
        'metadata',
    ];

    protected $casts = [
        'stock_sync_enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public static function externalIdentityKey(string $productId, mixed $variationId = null): string
    {
        return hash('sha256', implode("\0", [
            'product',
            trim($productId),
            'variation',
            self::normalizeVariationId($variationId) ?? 'parent',
        ]));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
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
