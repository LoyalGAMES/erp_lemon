<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login', [
            'hasUsers' => User::query()->exists(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = mb_strtolower(trim((string) $credentials['email']));
        $remember = $request->boolean('remember');
        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user instanceof User || ! $user->is_active || ! Hash::check((string) $credentials['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Nieprawidłowy login lub hasło.',
            ]);
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $this->markLogin();

        return redirect()
            ->intended(route('dashboard'))
            ->with('status', 'Zalogowano do ERP.');
    }

    public function setupFirstAdmin(Request $request): RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()
                ->route('login')
                ->with('error', 'Pierwszy administrator został już utworzony. Zaloguj się istniejącym kontem.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

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
