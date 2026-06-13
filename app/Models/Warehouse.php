<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'allow_negative_stock',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'allow_negative_stock' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(WarehouseChannelRoute::class);
    }
}

