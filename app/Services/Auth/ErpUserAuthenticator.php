<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

final class ErpUserAuthenticator
{
    public function authenticate(string $login, string $password): ?User
    {
        $login = trim($login);

        if ($login === '' || $password === '') {
            return null;
        }

        $user = $this->databaseUser($login, $password);

        if ($user instanceof User) {
            return $user;
        }

        if ($this->matchesEnvironmentFallback($login, $password)) {
            return new User([
                'name' => $login,
                'email' => $this->fallbackEmail($login),
                'role' => User::ROLE_ADMINISTRATOR,
                'is_active' => true,
            ]);
        }

        return null;
    }

    private function databaseUser(string $login, string $password): ?User
    {
        if (! Schema::hasTable('users')) {
            return null;
        }

        $query = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($login): void {
                $query->where('email', $login)
                    ->orWhere('name', $login);
            });

        $user = $query->first();

        if (! $user instanceof User || ! Hash::check($password, (string) $user->password)) {
            return null;
        }

        if ($user->last_login_at === null || $user->last_login_at->lt(now()->subMinutes(15))) {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        return $user->refresh();
    }

    public function hasDatabaseUsers(): bool
    {
        if (! Schema::hasTable('users')) {
            return false;
        }

        return User::query()->where('is_active', true)->exists();
    }

    private function matchesEnvironmentFallback(string $login, string $password): bool
    {
        $fallbackLogin = (string) config('erp.basic_user', '');
        $fallbackPassword = (string) config('erp.basic_password', '');

        return $fallbackLogin !== ''
            && $fallbackPassword !== ''
            && hash_equals($fallbackLogin, $login)
            && hash_equals($fallbackPassword, $password);
    }

    private function fallbackEmail(string $login): string
    {
        $configured = trim((string) config('erp.fallback_email', ''));

        return $configured !== '' ? $configured : $login;
    }
}
