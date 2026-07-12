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
        'fulfillment_status',
        'label_generation_attempts',
        'label_generation_next_at',
        'label_generation_last_error',
        'woo_shipped_sync_status',
        'woo_shipped_sync_attempts',
        'woo_shipped_sync_next_at',
        'woo_shipped_sync_error',
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
        'label_generation_attempts' => 'integer',
        'label_generation_next_at' => 'datetime',
        'woo_shipped_sync_attempts' => 'integer',
        'woo_shipped_sync_next_at' => 'datetime',
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

    public function shipmentLabels(): HasMany
    {
        return $this->hasMany(ShippingLabel::class)
            ->shipments()
            ->latest('generated_at')
            ->latest('id');
    }

    public function customerMessages(): HasMany
    {
        return $this->hasMany(CustomerMessage::class)->latest();
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(InternalNote::class)->latest();
    }

    public function customerPayments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class)->latest('booked_at')->latest();
    }
}
