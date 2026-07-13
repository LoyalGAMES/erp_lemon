<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerExternalAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'wordpress_integration_id',
        'external_customer_id',
        'email',
        'email_normalized',
        'username',
        'first_name',
        'last_name',
        'display_name',
        'phone',
        'is_registered',
        'role',
        'billing_data',
        'shipping_data',
        'orders_count',
        'total_spent',
        'account_created_at',
        'last_synced_at',
        'raw_payload',
    ];

    protected $casts = [
        'is_registered' => 'boolean',
        'billing_data' => 'array',
        'shipping_data' => 'array',
        'orders_count' => 'integer',
        'total_spent' => 'decimal:2',
        'account_created_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WordpressIntegration::class, 'wordpress_integration_id');
    }

    public function wordpressIntegration(): BelongsTo
    {
        return $this->integration();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(ExternalOrder::class);
    }

    public function accountClaims(): HasMany
    {
        return $this->hasMany(CustomerAccountClaim::class);
    }
}
