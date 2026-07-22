<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAccountClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'customer_id',
        'customer_external_account_id',
        'external_order_id',
        'wordpress_integration_id',
        'email_hash',
        'status',
        'expires_at',
        'claimed_at',
        'external_customer_id',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerExternalAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerExternalAccount::class);
    }

    public function externalOrder(): BelongsTo
    {
        return $this->belongsTo(ExternalOrder::class)->withTrashed();
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(WordpressIntegration::class, 'wordpress_integration_id');
    }
}
