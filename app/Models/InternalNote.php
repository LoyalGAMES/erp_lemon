<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_order_id',
        'return_case_id',
        'user_id',
        'author_name',
        'body',
        'metadata',
    ];

    protected $casts = [
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
