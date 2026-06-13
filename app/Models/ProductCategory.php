<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_channel_id',
        'external_id',
        'parent_external_id',
        'name',
        'slug',
        'path',
        'count',
        'metadata',
    ];

    protected $casts = [
        'count' => 'integer',
        'metadata' => 'array',
    ];

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class);
    }
}
