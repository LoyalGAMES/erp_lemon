<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExternalOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'split_parent_order_id',
        'split_root_order_id',
        'sales_channel_id',
        'customer_id',
        'customer_external_account_id',
        'wordpress_integration_id',
        'customer_match_method',
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

    public function splitParent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'split_parent_order_id');
    }

    public function splitRoot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'split_root_order_id');
    }

    public function splitChildren(): HasMany
    {
        return $this->hasMany(self::class, 'split_parent_order_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerExternalAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerExternalAccount::class);
    }

    public function wordpressIntegration(): BelongsTo
    {
        return $this->belongsTo(WordpressIntegration::class);
    }

    public function customerAccountClaim(): HasOne
    {
        return $this->hasOne(CustomerAccountClaim::class);
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

    public function cancellation(): HasOne
    {
        return $this->hasOne(OrderCancellation::class);
    }

    public function cancellationOperation(): ?OrderCancellation
    {
        $rootId = (int) ($this->split_root_order_id ?: $this->id);

        return OrderCancellation::query()
            ->where('external_order_id', $rootId)
            ->where('status', '!=', 'rejected')
            ->first();
    }

    public function hasCancellationOperation(): bool
    {
        return $this->cancellationOperation() instanceof OrderCancellation;
    }
}
