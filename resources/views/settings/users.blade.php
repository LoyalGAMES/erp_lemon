@extends('layouts.app', [
    'title' => $title,
    'subtitle' => $subtitle,
    'module' => $module,
    'headerBackUrl' => route('settings.index'),
])

@section('content')
    <section class="card" style="margin-bottom: 18px;">
        <div class="panel-header">
            <span>Dodaj użytkownika</span>
            <span>{{ $users->count() }} kont</span>
        </div>
        <form class="form-grid user-form" method="POST" action="{{ route('settings.users.store') }}">
            @csrf
            <label>Imię lub nazwa użytkownika
                <input name="name" value="{{ old('name') }}" required autocomplete="name">
            </label>
            <label>Login lub e-mail
                <input name="email" value="{{ old('email') }}" required autocomplete="username">
            </label>
            <label>Rola
                <select name="role" required>
                    @foreach ($roleLabels as $role => $label)
                        <option value="{{ $role }}" @selected(old('role', \App\Models\User::ROLE_ADMINISTRATOR) === $role)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label>Nowe hasło
                <input name="password" type="password" required minlength="10" autocomplete="new-password">
            </label>
            <label>Powtórz hasło
                <input name="password_confirmation" type="password" required minlength="10" autocomplete="new-password">
            </label>
            <label class="checkbox-label">
                <input name="is_active" type="checkbox" value="1" @checked(old('is_active', '1'))>
                Konto aktywne
            </label>
            <div class="form-footer">
                <button class="button" type="submit">Dodaj użytkownika</button>
                <span class="toolbar-note">Pierwsze konto w bazie musi być aktywnym administratorem.</span>
            </div>
        </form>
    </section>

    <section class="card">
        <div class="panel-header">
            <span>Użytkownicy ERP</span>
            <span>Hasła są zapisywane wyłącznie jako hash</span>
        </div>
        @if ($users->isEmpty())
            <div class="empty-users">
                Brak kont w bazie. Pierwszy administrator może zostać utworzony z ekranu logowania.
            </div>
        @else
            <div class="user-list">
                @foreach ($users as $user)
                    <form class="user-row" method="POST" action="{{ route('settings.users.update', $user) }}">
                        @csrf
                        @method('PUT')
                        <div class="user-row-head">
                            <div>
                                <strong>{{ $user->name }}</strong>
                                <span>{{ $user->email }}</span>
                            </div>
                            <div class="inline-actions">
                                <span @class(['status', 'red' => ! $user->is_active])>{{ $user->is_active ? 'Aktywne' : 'Nieaktywne' }}</span>
                                <span class="status blue">{{ $user->roleLabel() }}</span>
                            </div>
                        </div>

                        <div class="user-edit-grid">
                            <label>Nazwa
                                <input name="name" value="{{ old('users.' . $user->id . '.name', $user->name) }}" required>
                            </label>
                            <label>Login lub e-mail
                                <input name="email" value="{{ old('users.' . $user->id . '.email', $user->email) }}" required autocomplete="username">
                            </label>
                            <label>Rola
                                <select name="role" required>
                                    @foreach ($roleLabels as $role => $label)
                                        <option value="{{ $role }}" @selected(old('users.' . $user->id . '.role', $user->role) === $role)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Nowe hasło
                                <input name="password" type="password" minlength="10" autocomplete="new-password" placeholder="Zostaw puste bez zmiany">
                            </label>
                            <label>Powtórz hasło
                                <input name="password_confirmation" type="password" minlength="10" autocomplete="new-password">
                            </label>
                            <label class="checkbox-label">
                                <input name="is_active" type="checkbox" value="1" @checked(old('users.' . $user->id . '.is_active', $user->is_active))>
                                Konto aktywne
                            </label>
                        </div>

                        <div class="user-meta">
                            <span>Ostatnie logowanie: {{ $user->last_login_at?->format('Y-m-d H:i') ?? 'brak' }}</span>
                            <span>Aktualizacja: {{ $user->updated_at?->format('Y-m-d H:i') ?? '-' }}</span>
                        </div>
                        <div class="form-footer">
                            <button class="button secondary" type="submit">Zapisz użytkownika</button>
                        </div>
                    </form>
                @endforeach
            </div>
        @endif
    </section>
@endsection

@push('styles')
    <style>
        .user-form { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .checkbox-label { align-content: end; grid-template-columns: auto 1fr; align-items: center; color: var(--text); }
        .form-footer { grid-column: 1 / -1; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .user-list { display: grid; gap: 12px; padding: 16px; }
        .user-row { border: 1px solid var(--border); border-radius: 8px; padding: 14px; background: #fffdfb; display: grid; gap: 14px; }
        .user-row-head { display: flex; justify-content: space-between; gap: 12px; align-items: start; }
        .user-row-head strong { display: block; font-size: 17px; }
        .user-row-head span { color: var(--muted); }
        .user-edit-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .user-meta { display: flex; gap: 16px; flex-wrap: wrap; color: var(--muted); font-size: 12px; }
        .empty-users { padding: 20px; color: var(--muted); }
        @media (max-width: 980px) {
            .user-form, .user-edit-grid { grid-template-columns: 1fr; }
            .user-row-head { display: grid; }
        }
    </style>
@endpush
