<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockSyncState extends Model
{
    protected $fillable = [
        'product_id',
        'sales_channel_id',
        'desired_version',
        'desired_quantity',
        'exported_version',
        'queue_item_id',
    ];

    protected $casts = [
        'desired_version' => 'integer',
        'desired_quantity' => 'decimal:4',
        'exported_version' => 'integer',
        'queue_item_id' => 'integer',
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
