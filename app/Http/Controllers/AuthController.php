<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    private const FAILED_LOGIN_TIMEBOX_MICROSECONDS = 200_000;

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'hasUsers' => User::query()->exists(),
        ]);
    }

    public function login(Request $request, Timebox $timebox): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = mb_strtolower(trim((string) $credentials['email']));
        $remember = $request->boolean('remember');
        $user = User::query()
            ->where(function ($query) use ($identifier): void {
                $query
                    ->whereRaw('LOWER(email) = ?', [$identifier])
                    ->orWhereRaw('LOWER(name) = ?', [$identifier]);
            })
            ->first();

        return $timebox->call(function (Timebox $timebox) use ($user, $credentials, $remember, $request): RedirectResponse {
            if (! $user instanceof User || ! $user->is_active || ! Hash::check((string) $credentials['password'], (string) $user->password)) {
                throw ValidationException::withMessages([
                    'email' => 'Nieprawidłowy login lub hasło.',
                ]);
            }

            Auth::login($user, $remember);
            $request->session()->regenerate();
            $this->markLogin();
            $timebox->returnEarly();

            return redirect()
                ->intended(route('dashboard'))
                ->with('status', 'Zalogowano do ERP.');
        }, self::FAILED_LOGIN_TIMEBOX_MICROSECONDS);
    }

    public function setupFirstAdmin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        try {
            return Cache::lock('erp:first-administrator-setup', 15)->block(5, function () use ($data, $request): RedirectResponse {
                if (User::query()->exists()) {
                    return redirect()
                        ->route('login')
                        ->with('error', 'Pierwszy administrator został już utworzony. Zaloguj się istniejącym kontem.');
                }

                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => mb_strtolower(trim((string) $data['email'])),
                    'password' => $data['password'],
                    'role' => User::ROLE_ADMINISTRATOR,
                    'is_active' => true,
                    'last_login_at' => now(),
                ]);

                Auth::login($user);
                $request->session()->regenerate();

                return redirect()
                    ->route('dashboard')
                    ->with('status', 'Utworzono pierwszego administratora i zalogowano do ERP.');
            });
        } catch (LockTimeoutException) {
            return redirect()
                ->route('login')
                ->with('error', 'Tworzenie pierwszego administratora już trwa. Spróbuj ponownie za chwilę.');
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'Wylogowano z ERP.');
    }

    private function markLogin(): void
    {
        $user = Auth::user();

        if ($user instanceof User) {
            $user->forceFill(['last_login_at' => now()])->save();
        }
    }
}
