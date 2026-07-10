<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('settings.users', [
            'title' => 'Użytkownicy ERP',
            'subtitle' => 'Konta dostępu do aplikacji, role robocze i aktywność logowania.',
            'module' => 'users',
            'users' => User::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'roleLabels' => User::roleLabels(),
        ]);
    }

    public function store(Request $request, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(array_keys(User::roleLabels()))],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $isActive = $request->boolean('is_active');
        if ($this->wouldCreateWithoutActiveAdministrator((string) $data['role'], $isActive)) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with('error', 'Pierwsze konto w bazie musi być aktywnym administratorem.');
        }

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $data['password'],
            'is_active' => $isActive,
        ]);

        $audit->record('user.created', $user, null, $this->auditPayload($user));

        return redirect()
            ->route('settings.users')
            ->with('status', 'Użytkownik ERP został dodany.');
    }

    public function update(Request $request, User $user, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(array_keys(User::roleLabels()))],
            'password' => ['nullable', 'string', 'min:10', 'confirmed'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $isActive = $request->boolean('is_active');
        if ($this->wouldRemoveLastActiveAdministrator($user, (string) $data['role'], $isActive)) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with('error', 'Nie można wyłączyć albo odebrać roli ostatniemu aktywnemu administratorowi.');
        }

        $before = $this->auditPayload($user);

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'is_active' => $isActive,
        ];

        if (filled($data['password'] ?? null)) {
            $payload['password'] = $data['password'];
        }

        $user->update($payload);
        $user->refresh();

        $audit->record('user.updated', $user, $before, $this->auditPayload($user), [
            'password_changed' => filled($data['password'] ?? null),
        ]);

        return redirect()
            ->route('settings.users')
            ->with('status', 'Użytkownik ERP został zapisany.');
    }

    private function wouldCreateWithoutActiveAdministrator(string $role, bool $isActive): bool
    {
        $hasActiveAdministrator = User::query()
            ->where('role', User::ROLE_ADMINISTRATOR)
            ->where('is_active', true)
            ->exists();

        return ! $hasActiveAdministrator
            && ($role !== User::ROLE_ADMINISTRATOR || ! $isActive);
    }

    private function wouldRemoveLastActiveAdministrator(User $user, string $role, bool $isActive): bool
    {
        if (! $user->isAdministrator() || ! $user->is_active) {
            return false;
        }

        if ($role === User::ROLE_ADMINISTRATOR && $isActive) {
            return false;
        }

        return ! User::query()
            ->whereKeyNot($user->id)
            ->where('role', User::ROLE_ADMINISTRATOR)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at?->toISOString(),
        ];
    }
}
