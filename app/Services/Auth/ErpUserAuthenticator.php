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
                'email' => $login . '@sempre-erp.local',
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

    private function matchesEnvironmentFallback(string $login, string $password): bool
    {
        $fallbackLogin = (string) env('ERP_BASIC_USER', '');
        $fallbackPassword = (string) env('ERP_BASIC_PASSWORD', '');

        return $fallbackLogin !== ''
            && $fallbackPassword !== ''
            && hash_equals($fallbackLogin, $login)
            && hash_equals($fallbackPassword, $password);
    }
}
