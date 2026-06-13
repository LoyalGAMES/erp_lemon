<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductChannelMapping extends Model
{
    use HasFactory;

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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }
}

