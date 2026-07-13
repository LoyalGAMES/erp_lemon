<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'email_normalized',
        'first_name',
        'last_name',
        'display_name',
        'phone',
        'account_status',
        'billing_data',
        'shipping_data',
        'orders_count',
        'total_spent',
        'loyalty_points_balance',
        'loyalty_points_source',
        'first_order_at',
        'last_order_at',
        'account_created_at',
        'last_synced_at',
        'metadata',
    ];

    protected $casts = [
        'billing_data' => 'array',
        'shipping_data' => 'array',
        'orders_count' => 'integer',
        'total_spent' => 'decimal:2',
        'loyalty_points_balance' => 'decimal:2',
        'first_order_at' => 'datetime',
        'last_order_at' => 'datetime',
        'account_created_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function externalAccounts(): HasMany
    {
        return $this->hasMany(CustomerExternalAccount::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ExternalOrder::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CustomerMessage::class);
    }

    public function accountClaims(): HasMany
    {
        return $this->hasMany(CustomerAccountClaim::class);
    }
}
