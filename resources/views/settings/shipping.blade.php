@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@section('content')
    @php
        $templateLabels = ['small' => 'Mała (A)', 'medium' => 'Średnia (B)', 'large' => 'Duża (C)'];
        $sendingLabels = [
            'dispatch_order' => 'Odbiór kuriera z magazynu',
            'parcel_locker' => 'Nadanie w Paczkomacie',
            'pok' => 'Punkt obsługi klienta',
            'branch' => 'Oddział InPost',
        ];
    @endphp

    <article class="card settings-panel">
        <div class="panel-header">
            <span>Konta kurierskie InPost</span>
            <span>{{ $accounts->count() }} kont</span>
        </div>

        <div class="shipping-accounts">
            @forelse ($accounts as $account)
                <form class="shipping-account-card" method="POST" action="{{ route('settings.shipping.accounts.update', $account) }}">
                    @csrf
                    @method('PUT')
                    <div class="shipping-account-header">
                        <div>
                            <strong>{{ $account->name }}</strong>
                            <span class="muted">kod: {{ $account->code }}</span>
                        </div>
                        <div class="shipping-account-badges">
                            @if ($account->is_default)
                                <span class="status">Domyślne</span>
                            @endif
                            <span class="status {{ $account->is_active ? 'blue' : 'red' }}">{{ $account->is_active ? 'Aktywne' : 'Wyłączone' }}</span>
                        </div>
                    </div>
                    <div class="shipping-account-grid">
                        <label>Nazwa konta
                            <input name="name" value="{{ old('name', $account->name) }}" required maxlength="120">
                        </label>
                        <label>ID organizacji ShipX
                            <input name="organization_id" value="{{ old('organization_id', $account->organization_id) }}" required maxlength="40">
                        </label>
                        <label>Nowy token API (zostaw puste, żeby nie zmieniać)
                            <input name="api_token" type="password" autocomplete="new-password" placeholder="•••••••">
                        </label>
                        <label>Domyślny gabaryt paczki
                            <select name="default_parcel_template">
                                @foreach ($templateLabels as $value => $label)
                                    <option value="{{ $value }}" @selected($account->default_parcel_template === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label>Sposób nadania
                            <select name="sending_method">
                                @foreach ($sendingLabels as $value => $label)
                                    <option value="{{ $value }}" @selected($account->sending_method === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="shipping-account-flags">
                            <label class="inline-flag"><input type="checkbox" name="is_default" value="1" @checked($account->is_default)> Konto domyślne</label>
                            <label class="inline-flag"><input type="checkbox" name="is_active" value="1" @checked($account->is_active)> Konto aktywne</label>
                        </div>
                    </div>
                    @php $returnConfig = (array) data_get($account->metadata, 'return', []); @endphp
                    <details class="shipping-return-config" @if (filled($returnConfig['name'] ?? null)) open @endif>
                        <summary>Adres zwrotów (etykiety zwrotne klient → magazyn)</summary>
                        <div class="shipping-account-grid">
                            <label>Nazwa odbiorcy zwrotów
                                <input name="return_name" value="{{ $returnConfig['name'] ?? '' }}" maxlength="120" placeholder="np. Sempre — Magazyn zwrotów">
                            </label>
                            <label>Telefon
                                <input name="return_phone" value="{{ $returnConfig['phone'] ?? '' }}" maxlength="32">
                            </label>
                            <label>E-mail
                                <input name="return_email" type="email" value="{{ $returnConfig['email'] ?? '' }}" maxlength="255">
                            </label>
                            <label>Paczkomat zwrotów (opcjonalnie)
                                <input name="return_target_point" value="{{ $returnConfig['target_point'] ?? '' }}" maxlength="20" placeholder="np. KRA010">
                            </label>
                            <label>Ulica (gdy brak Paczkomatu)
                                <input name="return_street" value="{{ $returnConfig['street'] ?? '' }}" maxlength="160">
                            </label>
                            <label>Nr budynku
                                <input name="return_building_number" value="{{ $returnConfig['building_number'] ?? '' }}" maxlength="20">
                            </label>
                            <label>Kod pocztowy
                                <input name="return_post_code" value="{{ $returnConfig['post_code'] ?? '' }}" maxlength="12">
                            </label>
                            <label>Miasto
                                <input name="return_city" value="{{ $returnConfig['city'] ?? '' }}" maxlength="80">
                            </label>
                        </div>
                    </details>
                    <div class="shipping-account-actions">
                        <button class="button" type="submit">Zapisz konto</button>
                        <button class="button danger" type="submit" form="delete-account-{{ $account->id }}" onclick="return confirm('Usunąć konto {{ $account->name }}?');">Usuń</button>
                    </div>
                </form>
                <form id="delete-account-{{ $account->id }}" method="POST" action="{{ route('settings.shipping.accounts.destroy', $account) }}">
                    @csrf
                    @method('DELETE')
                </form>
            @empty
                <div class="packing-empty">Nie ma jeszcze kont InPost. Dodaj pierwsze konto poniżej — token API i ID organizacji znajdziesz w Menedżerze Paczek InPost (ShipX).</div>
            @endforelse
        </div>

        <div class="panel-header"><span>Dodaj konto</span><span></span></div>
        <form class="shipping-account-card" method="POST" action="{{ route('settings.shipping.accounts.store') }}">
            @csrf
            <div class="shipping-account-grid">
                <label>Nazwa konta
                    <input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="np. Konto główne">
                </label>
                <label>Kod (unikalny)
                    <input name="code" value="{{ old('code') }}" required maxlength="40" pattern="[A-Za-z0-9_-]+" placeholder="np. glowne">
                </label>
                <label>ID organizacji ShipX
                    <input name="organization_id" value="{{ old('organization_id') }}" required maxlength="40">
                </label>
                <label>Token API ShipX
                    <input name="api_token" type="password" autocomplete="new-password" required>
                </label>
                <label>Domyślny gabaryt paczki
                    <select name="default_parcel_template">
                        @foreach ($templateLabels as $value => $label)
                            <option value="{{ $value }}" @selected(old('default_parcel_template', 'small') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Sposób nadania
                    <select name="sending_method">
                        @foreach ($sendingLabels as $value => $label)
                            <option value="{{ $value }}" @selected(old('sending_method', 'dispatch_order') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="shipping-account-flags">
                    <label class="inline-flag"><input type="checkbox" name="is_default" value="1" @checked(old('is_default'))> Konto domyślne</label>
                </div>
            </div>
            <div class="shipping-account-actions">
                <button class="button" type="submit">Dodaj konto</button>
            </div>
        </form>
    </article>
@endsection

@push('styles')
    <style>
        .settings-panel { max-width: 980px; }
        .shipping-accounts { display: grid; gap: 14px; padding: 16px; }
        .shipping-account-card { display: grid; gap: 12px; border: 1px solid var(--border); border-radius: 8px; padding: 14px; background: #fffdfb; margin: 0 16px 16px; }
        .shipping-accounts .shipping-account-card { margin: 0; }
        .shipping-account-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .shipping-account-header span.muted { display: block; font-size: 12px; }
        .shipping-account-badges { display: flex; gap: 6px; }
        .shipping-account-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .shipping-account-flags { display: flex; gap: 18px; align-items: end; }
        .inline-flag { display: inline-flex; align-items: center; gap: 7px; font-weight: 720; }
        .inline-flag input { width: 17px; height: 17px; }
        .shipping-account-actions { display: flex; gap: 10px; }
        .shipping-return-config summary { cursor: pointer; font-weight: 740; color: var(--green-dark); }
        .shipping-return-config .shipping-account-grid { margin-top: 10px; }
        .packing-empty { padding: 14px; color: var(--muted); border: 1px dashed var(--border); border-radius: 8px; }
        @media (max-width: 760px) {
            .shipping-account-grid { grid-template-columns: 1fr; }
        }
    </style>
@endpush
