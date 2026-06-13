<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRelation extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_product_id',
        'child_product_id',
        'relation_type',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function parentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    public function childProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'child_product_id');
    }
}
