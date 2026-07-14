<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCancellationStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_cancellation_id',
        'step',
        'status',
        'attempts',
        'idempotency_key',
        'external_reference',
        'request_payload',
        'response_payload',
        'last_error',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'request_payload' => 'array',
        'response_payload' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(OrderCancellation::class, 'order_cancellation_id');
    }
}
