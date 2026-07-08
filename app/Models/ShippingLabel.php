<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingLabel extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'external_order_id',
        'wordpress_integration_id',
        'courier_account_id',
        'status',
        'provider',
        'label_number',
        'tracking_number',
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

    public function filename(): string
    {
        return basename($this->path);
    }
}
