<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingLabel extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'external_order_id',
        'wordpress_integration_id',
        'courier_account_id',
        'return_case_id',
        'purpose',
        'idempotency_key',
        'status',
        'provider',
        'label_number',
        'tracking_number',
        'tracking_status',
        'tracking_checked_at',
        'next_tracking_check_at',
        'tracking_attempts',
        'tracking_last_error',
        'picked_up_at',
        'disk',
        'path',
        'mime_type',
        'size',
        'sha256',
        'source_url',
        'response_payload',
        'generated_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'generated_at' => 'datetime',
        'tracking_checked_at' => 'datetime',
        'next_tracking_check_at' => 'datetime',
        'tracking_attempts' => 'integer',
        'picked_up_at' => 'datetime',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WordpressIntegration::class, 'wordpress_integration_id');
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }

    public function courierAccount(): BelongsTo
    {
        return $this->belongsTo(CourierAccount::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function scopeShipments(Builder $query): Builder
    {
        return $query->where('purpose', 'shipment');
    }

    public function trackingIdentifier(): ?string
    {
        $number = trim((string) ($this->tracking_number ?: $this->label_number));

        return $number !== '' ? $number : null;
    }

    public function filename(): string
    {
        return basename($this->path);
    }
}
