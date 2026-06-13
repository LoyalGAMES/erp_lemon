<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'quantity_on_hand',
        'quantity_reserved',
        'quantity_available',
        'recalculated_at',
    ];

    protected $casts = [
        'quantity_on_hand' => 'decimal:4',
        'quantity_reserved' => 'decimal:4',
        'quantity_available' => 'decimal:4',
        'recalculated_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

