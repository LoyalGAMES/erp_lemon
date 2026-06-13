<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseChannelRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'sales_channel_id',
        'push_stock',
        'allocation_strategy',
        'stock_buffer',
        'priority',
        'settings',
    ];

    protected $casts = [
        'push_stock' => 'boolean',
        'stock_buffer' => 'decimal:4',
        'settings' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }
}

