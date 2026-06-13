<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'external_id',
        'external_number',
        'status',
        'currency',
        'total_gross',
        'billing_data',
        'shipping_data',
        'raw_payload',
        'external_created_at',
        'external_updated_at',
    ];

    protected $casts = [
        'total_gross' => 'decimal:2',
        'billing_data' => 'array',
        'shipping_data' => 'array',
        'raw_payload' => 'array',
        'external_created_at' => 'datetime',
        'external_updated_at' => 'datetime',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExternalOrderLine::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function packingTasks(): HasMany
    {
        return $this->hasMany(PackingTask::class);
    }

    public function shippingLabels(): HasMany
    {
        return $this->hasMany(ShippingLabel::class)->latest('generated_at');
    }
}
