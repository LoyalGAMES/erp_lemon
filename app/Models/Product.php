<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Products\ProductImageThumbnailService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'name',
        'ean',
        'unit',
        'quantity_precision',
        'vat_rate',
        'weight_kg',
        'attributes',
        'is_active',
        'is_favorite',
        'is_translation',
    ];

    protected $casts = [
        'attributes' => 'array',
        'is_active' => 'boolean',
        'is_favorite' => 'boolean',
        'is_translation' => 'boolean',
        'vat_rate' => 'decimal:2',
        'weight_kg' => 'decimal:4',
    ];

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function channelMappings(): HasMany
    {
        return $this->hasMany(ProductChannelMapping::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }

    public function childRelations(): HasMany
    {
        return $this->hasMany(ProductRelation::class, 'parent_product_id');
    }

    public function parentRelations(): HasMany
    {
        return $this->hasMany(ProductRelation::class, 'child_product_id');
    }

    public function variantChildren(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'product_relations',
            'parent_product_id',
            'child_product_id',
        )
            ->wherePivot('relation_type', 'variant')
            ->withPivot(['id', 'sort_order', 'metadata'])
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('name');
    }

    public function variantParents(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'product_relations',
            'child_product_id',
            'parent_product_id',
        )
            ->wherePivot('relation_type', 'variant')
            ->withPivot(['id', 'sort_order', 'metadata'])
            ->withTimestamps();
    }

    public function imageUrl(): ?string
    {
        $attributes = (array) $this->getAttribute('attributes');

        foreach ([
            'master.media.0.src',
            'master.media.0.url',
            'woocommerce_image.src',
            'woocommerce_image.url',
            'woocommerce_images.0.src',
            'woocommerce_images.0.url',
            'woocommerce_parent_image.src',
            'woocommerce_parent_image.url',
            'woocommerce_parent_images.0.src',
            'woocommerce_parent_images.0.url',
        ] as $path) {
            $value = trim((string) data_get($attributes, $path, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function thumbnailUrl(int $width = 116, int $height = 144): ?string
    {
        return app(ProductImageThumbnailService::class)->thumbnailUrl($this->imageUrl(), $width, $height);
    }

    /**
     * @return array<string, mixed>
     */
    public function masterData(): array
    {
        $master = data_get((array) $this->getAttribute('attributes'), 'master', []);

        return is_array($master) ? $master : [];
    }

    public function masterValue(string $path, mixed $default = null): mixed
    {
        return data_get($this->masterData(), $path, $default);
    }

    public function masterSource(): ?string
    {
        $attributes = (array) $this->getAttribute('attributes');

        return data_get($attributes, 'master.source')
            ?? data_get($attributes, 'master_source');
    }

    public function isErpMaster(): bool
    {
        return $this->masterSource() === 'erp';
    }

    public function isSyntheticWooSku(): bool
    {
        return (bool) preg_match('/^WC-[A-Z0-9-]+-(PARENT|VARIANT)-[0-9]+$/', $this->sku);
    }

    public function displaySku(): ?string
    {
        return $this->isSyntheticWooSku() ? null : $this->sku;
    }

    public function externalDisplayId(): ?string
    {
        if ($this->relationLoaded('channelMappings')) {
            if ($this->channelMappings->isEmpty()) {
                return null;
            }

            $mapping = $this->channelMappings->first(fn ($mapping): bool => filled($mapping->external_variation_id))
                ?? $this->channelMappings->first(fn ($mapping): bool => filled($mapping->external_product_id));

            if ($mapping !== null) {
                return filled($mapping->external_variation_id)
                    ? (string) $mapping->external_variation_id
                    : (string) $mapping->external_product_id;
            }
        } elseif (! $this->channelMappings()->exists()) {
            return null;
        }

        $attributes = (array) $this->getAttribute('attributes');

        return data_get($attributes, 'woocommerce_variation_id')
            ?? data_get($attributes, 'woocommerce_product_id');
    }

    /**
     * @return list<array{src:string,alt:?string,name:?string}>
     */
    public function mediaImages(): array
    {
        $attributes = (array) $this->getAttribute('attributes');
        $images = [];

        foreach ([
            data_get($attributes, 'master.media', []),
            data_get($attributes, 'woocommerce_images', []),
            data_get($attributes, 'woocommerce_parent_images', []),
        ] as $list) {
            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $image) {
                if (! is_array($image)) {
                    continue;
                }

                $src = trim((string) ($image['src'] ?? $image['url'] ?? ''));

                if ($src === '') {
                    continue;
                }

                $images[$src] = [
                    'src' => $src,
                    'alt' => isset($image['alt']) ? (string) $image['alt'] : null,
                    'name' => isset($image['name']) ? (string) $image['name'] : null,
                ];
            }
        }

        foreach ([
            data_get($attributes, 'woocommerce_image'),
            data_get($attributes, 'woocommerce_parent_image'),
        ] as $image) {
            if (! is_array($image)) {
                continue;
            }

            $src = trim((string) ($image['src'] ?? $image['url'] ?? ''));

            if ($src === '' || isset($images[$src])) {
                continue;
            }

            $images[$src] = [
                'src' => $src,
                'alt' => isset($image['alt']) ? (string) $image['alt'] : null,
                'name' => isset($image['name']) ? (string) $image['name'] : null,
            ];
        }

        return array_values($images);
    }

    public function externalProductUrl(): ?string
    {
        if ($this->relationLoaded('channelMappings')) {
            if ($this->channelMappings->isEmpty()) {
                return null;
            }

            foreach ($this->channelMappings as $mapping) {
                $value = trim((string) data_get($mapping->metadata, 'woocommerce_permalink', ''));

                if ($value !== '') {
                    return $value;
                }
            }
        } elseif (! $this->channelMappings()->exists()) {
            return null;
        }

        $attributes = (array) $this->getAttribute('attributes');

        foreach (['woocommerce_permalink', 'woocommerce_parent_permalink'] as $path) {
            $value = trim((string) data_get($attributes, $path, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function wooVariationAttributes(): array
    {
        return collect(data_get((array) $this->getAttribute('attributes'), 'woocommerce_variation_attributes', []))
            ->filter(fn ($attribute): bool => is_array($attribute))
            ->values()
            ->all();
    }
}
