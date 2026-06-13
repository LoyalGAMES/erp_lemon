<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function integrations(): HasMany
    {
        return $this->hasMany(WordpressIntegration::class);
    }

    public function warehouseRoutes(): HasMany
    {
        return $this->hasMany(WarehouseChannelRoute::class);
    }
}

