<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationSyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'wordpress_integration_id',
        'direction',
        'operation',
        'status',
        'external_resource',
        'external_id',
        'request_payload',
        'response_payload',
        'error_message',
        'attempts',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }

    public function wordpressIntegration(): BelongsTo
    {
        return $this->belongsTo(WordpressIntegration::class);
    }
}
