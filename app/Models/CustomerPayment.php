<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_order_id',
        'return_case_id',
        'idempotency_key',
        'order_cancellation_id',
        'direction',
        'source',
        'purpose',
        'method',
        'status',
        'amount',
        'currency',
        'reference',
        'external_transaction_id',
        'description',
        'booked_at',
        'requested_at',
        'paid_at',
        'failed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'booked_at' => 'datetime',
        'requested_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id')->withTrashed();
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }

    public function orderCancellation(): BelongsTo
    {
        return $this->belongsTo(OrderCancellation::class);
    }
}
