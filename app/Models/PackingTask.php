<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackingTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'external_order_id',
        'external_order_line_id',
        'product_id',
        'external_line_id',
        'order_number',
        'customer_name',
        'sku',
        'product_name',
        'quantity_required',
        'quantity_picked',
        'status',
        'courier',
        'size_label',
        'order_date',
        'picked_at',
        'packed_at',
        'metadata',
    ];

    protected $casts = [
        'quantity_required' => 'decimal:4',
        'quantity_picked' => 'decimal:4',
        'order_date' => 'datetime',
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id');
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(ExternalOrderLine::class, 'external_order_line_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remainingQuantity(): float
    {
        return max(0, (float) $this->quantity_required - (float) $this->quantity_picked);
    }
}
