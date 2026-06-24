<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductParameterDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'input_type',
        'values',
        'is_variant',
        'is_required',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'values' => 'array',
        'is_variant' => 'boolean',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];
}
