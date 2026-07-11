<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'external_id',
        'parent_external_id',
        'name',
        'slug',
        'path',
        'description',
        'gs1_gpc_code',
        'gs1_gpc_label',
        'count',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'count' => 'integer',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function channelAliases(): HasMany
    {
        return $this->hasMany(ProductCategoryChannelAlias::class);
    }
}
