<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalOrderLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_order_id',
        'product_id',
        'external_line_id',
        'canonical_external_line_id',
        'sku',
        'name',
        'quantity',
        'unit_net_price',
        'unit_gross_price',
        'vat_rate',
        'raw_payload',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_net_price' => 'decimal:4',
        'unit_gross_price' => 'decimal:4',
        'vat_rate' => 'decimal:2',
        'raw_payload' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id')->withTrashed();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function packingTasks(): HasMany
    {
        return $this->hasMany(PackingTask::class);
    }
}
