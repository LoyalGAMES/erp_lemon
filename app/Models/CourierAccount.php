<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class CourierAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'code',
        'name',
        'api_token_encrypted',
        'organization_id',
        'sending_method',
        'default_parcel_template',
        'is_default',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function shippingLabels(): HasMany
    {
        return $this->hasMany(ShippingLabel::class);
    }

    public function apiToken(): string
    {
        return Crypt::decryptString($this->api_token_encrypted);
    }

    public function setApiToken(string $token): void
    {
        $this->api_token_encrypted = Crypt::encryptString($token);
    }

    public static function defaultFor(string $provider): ?self
    {
        return self::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }
}
