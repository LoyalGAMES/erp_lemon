<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrintJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipping_label_id',
        'deduplication_key',
        'status',
        'source',
        'station_code',
        'printer_name',
        'format',
        'attempts',
        'next_attempt_at',
        'reserved_by',
        'reserved_station',
        'reserved_at',
        'lease_token',
        'printed_at',
        'failed_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'reserved_at' => 'datetime',
        'printed_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function shippingLabel(): BelongsTo
    {
        return $this->belongsTo(ShippingLabel::class);
    }
}
