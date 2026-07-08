<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMessage extends Model
{
    protected $fillable = [
        'external_order_id',
        'return_case_id',
        'direction',
        'type',
        'trigger',
        'status',
        'recipient_email',
        'recipient_name',
        'subject',
        'body',
        'sent_at',
        'failed_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function externalOrder(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class);
    }

    public function returnCase(): BelongsTo
    {
        return $this->belongsTo(ReturnCase::class);
    }
}
