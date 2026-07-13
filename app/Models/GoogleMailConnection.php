<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

final class GoogleMailConnection extends Model
{
    public const PURPOSE_TRANSACTIONAL_MAIL = 'transactional_mail';

    protected $fillable = [
        'purpose',
        'client_id',
        'client_secret_encrypted',
        'google_subject',
        'email',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'access_token_expires_at',
        'granted_scopes',
        'connected_by_user_id',
        'connected_at',
        'refreshed_at',
        'reauthorization_required_at',
    ];

    protected $hidden = [
        'client_secret_encrypted',
        'access_token_encrypted',
        'refresh_token_encrypted',
    ];

    protected $casts = [
        'access_token_expires_at' => 'datetime',
        'granted_scopes' => 'array',
        'connected_at' => 'datetime',
        'refreshed_at' => 'datetime',
        'reauthorization_required_at' => 'datetime',
    ];

    public function accessToken(): ?string
    {
        return $this->decrypt($this->access_token_encrypted);
    }

    public function clientSecret(): ?string
    {
        return $this->decrypt($this->client_secret_encrypted);
    }

    public function refreshToken(): ?string
    {
        return $this->decrypt($this->refresh_token_encrypted);
    }

    public function isUsable(): bool
    {
        return $this->reauthorization_required_at === null
            && $this->refreshToken() !== null;
    }

    private function decrypt(mixed $encrypted): ?string
    {
        if (! filled($encrypted)) {
            return null;
        }

        try {
            $value = Crypt::decryptString((string) $encrypted);
        } catch (DecryptException) {
            return null;
        }

        return $value !== '' ? $value : null;
    }
}
