<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCategoryChannelAlias extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_category_id',
        'sales_channel_id',
        'external_id',
        'language',
        'translation_group',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $alias): void {
            $alias->external_id = trim((string) $alias->external_id);
            $language = mb_strtolower(trim((string) ($alias->language ?? '')));
            $alias->language = $language !== '' ? $language : null;
            $group = trim((string) ($alias->translation_group ?? ''));
            $alias->translation_group = $group !== '' ? $group : null;
        });
    }

    public function scopeForExternalId(Builder $query, int $salesChannelId, string $externalId): Builder
    {
        return $query
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_id', trim($externalId));
    }

    public function productCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }
}
