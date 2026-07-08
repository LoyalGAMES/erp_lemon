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
        'direction',
        'method',
        'status',
        'amount',
        'currency',
        'reference',
        'description',
        'booked_at',
        'paid_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'booked_at' => 'datetime',
        'paid_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class, 'external_order_id');
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }
}
