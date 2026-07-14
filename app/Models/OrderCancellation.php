<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderCancellation extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'external_order_id',
        'requested_by',
        'status',
        'reason',
        'refund_status',
        'refund_amount',
        'currency',
        'payment_method',
        'woo_refund_id',
        'last_error',
        'started_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(OrderCancellationStep::class);
    }
}
