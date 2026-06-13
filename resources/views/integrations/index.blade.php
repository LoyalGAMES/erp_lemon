@extends('layouts.app', ['title' => 'Integracje', 'subtitle' => 'Dodaj realne połączenia z WooCommerce i KSeF. Klucze API oraz tokeny są zapisywane w bazie w postaci zaszyfrowanej.', 'module' => 'integrations'])

@php
    $syncResultLabels = [
        'source_items' => 'Pozycje z WooCommerce',
        'source_products' => 'Produkty z WooCommerce',
        'source_variations' => 'Warianty z WooCommerce',
        'source_variable_parents' => 'Produkty główne wariantowe',
        'source_simple_products' => 'Produkty proste',
        'unique_skus_seen' => 'Unikalne SKU w imporcie',
        'synthetic_sku_items' => 'Pozycje z nadanym SKU ERP',
        'duplicate_sku_items' => 'Duplikaty SKU w WooCommerce',
        'mapping_overwrites' => 'Nadpisane mapowania kanału',
        'created' => 'Utworzone w ERP',
        'updated' => 'Zaktualizowane w ERP',
        'mapped' => 'Zmapowane pozycje',
        'stock_updated' => 'Zaktualizowane stany',
        'skipped' => 'Pominięte',
        'skipped_missing_identifier' => 'Pominięte bez identyfikatora',
        'products_total_before' => 'Produkty ERP przed importem',
        'products_total_after' => 'Produkty ERP po imporcie',
        'channel_mappings_total_after' => 'Mapowania kanału po imporcie',
        'lines' => 'Pozycje zamówień',
        'reserved' => 'Zarezerwowane',
        'released' => 'Zwolnione rezerwacje',
        'reservation_skipped' => 'Pominięte rezerwacje',
    ];
    $syncWarningKeys = ['duplicate_sku_items', 'mapping_overwrites'];
    $formatSyncResultValue = function (mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'tak' : 'nie';
        }

        if ($value === null || $value === '') {
            return '-';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
        }

        return (string) $value;
    };
@endphp

@section('content')
    <input id="integration-drawer" class="drawer-toggle" type="checkbox">

    <div class="page-toolbar">
        <div class="toolbar-note">WooCommerce obsługuje importy/eksporty w kolejce, a KSeF konfigurujesz w tej samej zakładce.</div>
        <label class="button" for="integration-drawer">Dodaj integrację</label>
    </div>

    <label class="drawer-backdrop" for="integration-drawer"></label>
    <aside class="drawer-panel" aria-label="Dodaj integrację">
        <div class="drawer-header">
            <div class="drawer-title">Dodaj sklep WooCommerce</div>
            <label class="drawer-close" for="integration-drawer">&times;</label>
        </div>
        <form method="POST" action="{{ route('integrations.store') }}" class="form-grid">
            @csrf
            <label>Kod kanału <input name="channel_code" value="{{ old('channel_code', 'B2C') }}" required maxlength="40"></label>
            <label>Nazwa kanału <input name="channel_name" value="{{ old('channel_name', 'Sklep B2C') }}" required></label>
            <label>Nazwa integracji <input name="name" value="{{ old('name', 'Sempre WooCommerce') }}" required></label>
            <label>URL sklepu <input name="base_url" value="{{ old('base_url', 'https://') }}" required></label>
            <label>Consumer key <input name="consumer_key" value="{{ old('consumer_key') }}" required autocomplete="off"></label>
            <label>Consumer secret <input name="consumer_secret" value="{{ old('consumer_secret') }}" required autocomplete="off"></label>
            <label>Użytkownik WordPress REST <input name="wp_api_username" value="{{ old('wp_api_username') }}" autocomplete="off"></label>
            <label>Hasło aplikacji WordPress <input name="wp_api_application_password" value="{{ old('wp_api_application_password') }}" autocomplete="off"></label>
            <label><input type="checkbox" name="order_import_enabled" value="1" checked> Import zamówień</label>
            <label><input type="checkbox" name="stock_export_enabled" value="1" checked> Eksport stanów</label>
            <label><input type="checkbox" name="invoice_upload_enabled" value="1" checked> Upload faktur do zamówień</label>
            <button class="button" type="submit">Zapisz integrację</button>
        </form>
    </aside>

    <article class="card" id="ksef" style="margin-bottom: 18px;">
        <div class="panel-header">
            <span>Integracja KSeF</span>
            <span>{{ $ksefConfiguration['direct_online_send_ready'] ? 'Gotowe do wysyłki' : 'Wymaga konfiguracji' }}</span>
        </div>
        <form class="form-grid" method="POST" action="{{ route('integrations.ksef.configuration.update') }}">
            @csrf
            @method('PUT')
            <div class="integration-form-grid">
                <label>Środowisko
                    <select name="environment" required>
                        <option value="test" @selected(old('environment', $ksefSettings['environment']) === 'test')>test</option>
                        <option value="demo" @selected(old('environment', $ksefSettings['environment']) === 'demo')>demo</option>
                        <option value="production" @selected(old('environment', $ksefSettings['environment']) === 'production')>production</option>
                    </select>
                </label>
                <label>Wersja API
                    <input name="api_version" value="{{ old('api_version', $ksefSettings['api_version']) }}" required>
                </label>
                <label>Adres bazowy API
                    <input name="base_url" value="{{ old('base_url', $ksefSettings['base_url']) }}" placeholder="Puste = domyślne URL MF dla środowiska">
                </label>
                <label>Adres bramki KSeF
                    <input name="gateway_url" value="{{ old('gateway_url', $ksefSettings['gateway_url']) }}" placeholder="np. https://.../submit">
                </label>
                <label>Adres statusu KSeF
                    <input name="status_url" value="{{ old('status_url', $ksefSettings['status_url']) }}" placeholder="np. https://.../status">
                </label>
            </div>
            <label>Token dostępu
                <input name="access_token" type="password" autocomplete="new-password" placeholder="{{ $ksefSettings['has_access_token'] ? 'Token jest zapisany; wpisz nowy tylko jeśli chcesz go zmienić' : 'Wpisz token KSeF' }}">
            </label>
            @if ($ksefSettings['has_access_token'])
                <label style="display: flex; grid-template-columns: auto 1fr; align-items: center; gap: 8px;">
                    <input name="clear_access_token" type="checkbox" value="1">
                    Usuń zapisany token KSeF {{ $ksefSettings['access_token_hint'] ? '(' . $ksefSettings['access_token_hint'] . ')' : '' }}
                </label>
            @endif
            <div class="toolbar-note">
                Aktualnie: KSeF API {{ $ksefConfiguration['api_version'] }} | środowisko: {{ $ksefConfiguration['environment'] }} | {{ $ksefConfiguration['base_url'] }}.
                Status: {{ $ksefConfiguration['status_url'] ?: 'automatycznie z adresu bramki' }}.
                Token jest przechowywany zaszyfrowany. Realna wysyłka wymaga skonfigurowanej bramki/sesji szyfrującej.
            </div>
            <div class="inline-actions">
                <button class="button" type="submit">Zapisz konfigurację KSeF</button>
                <a class="button secondary" href="{{ route('ksef.index') }}">Przejdź do faktur KSeF</a>
            </div>
        </form>
    </article>

    <article class="card" id="gs1" style="margin-bottom: 18px;">
        <div class="panel-header">
            <span>Konto GS1</span>
            <span>{{ $gs1Settings['ready'] ? 'Gotowe do generowania EAN' : 'Wymaga konfiguracji' }}</span>
        </div>
        <form class="form-grid" method="POST" action="{{ route('integrations.gs1.configuration.update') }}">
            @csrf
            @method('PUT')
            <div class="integration-form-grid">
                <label>Adres bazowy MojeGS1 API
                    <input name="base_url" value="{{ old('base_url', $gs1Settings['base_url']) }}" required placeholder="https://mojegs1.pl/api/v2">
                </label>
                <label>Login API GS1
                    <input name="username" value="{{ old('username', $gs1Settings['username']) }}" required autocomplete="off">
                </label>
                <label>Hasło API GS1
                    <input name="password" type="password" autocomplete="new-password" placeholder="{{ $gs1Settings['has_password'] ? 'Hasło jest zapisane; wpisz nowe tylko przy zmianie' : 'Wpisz hasło API GS1' }}">
                </label>
                <label>Prefiks GS1 firmy
                    <input name="company_prefix" value="{{ old('company_prefix', $gs1Settings['company_prefix']) }}" required inputmode="numeric" placeholder="np. 5901234">
                </label>
                <label>Następny numer referencyjny
                    <input name="next_item_reference" type="number" min="0" step="1" value="{{ old('next_item_reference', $gs1Settings['next_item_reference']) }}" required>
                </label>
                <label>Domyślny kod GPC
                    <input name="default_gpc_code" value="{{ old('default_gpc_code', $gs1Settings['default_gpc_code']) }}" inputmode="numeric" placeholder="opcjonalnie, np. 10000002">
                </label>
                <label>Rynek docelowy
                    <input name="target_market" value="{{ old('target_market', $gs1Settings['target_market']) }}" required maxlength="2">
                </label>
                <label class="check-row">
                    <input name="register_products" type="checkbox" value="1" @checked(old('register_products', $gs1Settings['register_products']))>
                    Rejestruj produkt w MojeGS1 po wygenerowaniu EAN
                </label>
                <label class="wide">Lista kodów GPC do wyboru przy produkcie
                    <textarea name="gpc_options" rows="12" placeholder="10008067 | Strój kąpielowy - jednoczęściowy">{{ old('gpc_options', $gs1Settings['gpc_options_text']) }}</textarea>
                </label>
            </div>
            @if ($gs1Settings['has_password'])
                <label style="display: flex; grid-template-columns: auto 1fr; align-items: center; gap: 8px;">
                    <input name="clear_password" type="checkbox" value="1">
                    Usuń zapisane hasło API GS1 {{ $gs1Settings['password_hint'] ? '(' . $gs1Settings['password_hint'] . ')' : '' }}
                </label>
            @endif
            <div class="toolbar-note">
                MojeGS1 API działa przez Basic Auth i ma limit 60 zapytań/minutę. Hasło zapisujemy w bazie wyłącznie w formie zaszyfrowanej przez klucz aplikacji Laravel.
                Prefiks podaj bez cyfry kontrolnej. ERP sam wyliczy cyfrę kontrolną GTIN-13.
                Dane API pobierzesz w MojeGS1: Moje dane -> Profile użytkowników -> Menu -> Zmień dane api. To nie jest zwykłe hasło do panelu.
                Możesz wpisać host lub pełny adres API, np. https://mojegs1.pl/api/v2. Każdy kod GPC wpisz w osobnej linii: kod | nazwa | opcjonalny opis.
            </div>
            <div class="inline-actions">
                <button class="button" type="submit">Zapisz konto GS1</button>
                <button class="button secondary" type="submit" form="gs1-test-connection-form">Testuj połączenie GS1</button>
            </div>
        </form>
        <form id="gs1-test-connection-form" method="POST" action="{{ route('integrations.gs1.test') }}">
            @csrf
        </form>
    </article>

    <article class="card">
        <div class="panel-header">Aktywne integracje WooCommerce</div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Kanał</th>
                        <th>URL</th>
                        <th>Klucz</th>
                        <th>WP REST</th>
                        <th>Etykiety</th>
                        <th>Ostatni sync</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($integrations as $integration)
                        @php
                            $labelSettings = $integration->shippingLabelSettings();
                            $orderStatusSettings = $integration->orderStatusSettings();
                        @endphp
                        <tr>
                            <td>{{ $integration->salesChannel->code }}</td>
                            <td>{{ $integration->base_url }}</td>
                            <td>{{ $integration->maskedConsumerKey() }}</td>
                            <td>{{ $integration->hasWordpressMediaCredentials() ? 'Gotowe' : 'Brak danych' }}</td>
                            <td>{{ $integration->shippingLabelsEnabled() ? 'Gotowe' : 'Brak konfiguracji' }}</td>
                            <td>{{ $integration->last_successful_sync_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>
                                <div class="inline-actions">
                                    <details class="compact-editor integration-editor">
                                        <summary>Edytuj</summary>
                                        <form method="POST" action="{{ route('integrations.update', $integration) }}" class="integration-edit-form">
                                            @csrf
                                            @method('PUT')
                                            <label>Kod kanału
                                                <input name="channel_code" value="{{ $integration->salesChannel->code }}" required maxlength="40">
                                            </label>
                                            <label>Nazwa kanału
                                                <input name="channel_name" value="{{ $integration->salesChannel->name }}" required>
                                            </label>
                                            <label>Nazwa integracji
                                                <input name="name" value="{{ $integration->name }}" required>
                                            </label>
                                            <label>URL sklepu
                                                <input name="base_url" value="{{ $integration->base_url }}" required>
                                            </label>
                                            <label>Nowy consumer key
                                                <input name="consumer_key" placeholder="Puste = bez zmian" autocomplete="off">
                                            </label>
                                            <label>Nowy consumer secret
                                                <input name="consumer_secret" placeholder="Puste = bez zmian" autocomplete="off">
                                            </label>
                                            <label class="check-row"><input type="checkbox" name="order_import_enabled" value="1" @checked($integration->order_import_enabled)> Import zamówień</label>
                                            <label class="check-row"><input type="checkbox" name="stock_export_enabled" value="1" @checked($integration->stock_export_enabled)> Eksport stanów</label>
                                            <label class="check-row"><input type="checkbox" name="invoice_upload_enabled" value="1" @checked($integration->invoice_upload_enabled)> Upload faktur</label>
                                            <button class="button secondary" type="submit">Zapisz integrację</button>
                                        </form>
                                    </details>
                                    <details class="compact-editor">
                                        <summary>WP REST</summary>
                                        <form method="POST" action="{{ route('integrations.wordpress-credentials.update', $integration) }}">
                                            @csrf
                                            @method('PUT')
                                            <input name="wp_api_username" placeholder="login WordPress" value="{{ $integration->wp_api_username }}" autocomplete="off" required>
                                            <input name="wp_api_application_password" placeholder="hasło aplikacji" autocomplete="off" required>
                                            <button class="button secondary" type="submit">Zapisz WP REST</button>
                                        </form>
                                    </details>
                                    <details class="compact-editor">
                                        <summary>Etykiety</summary>
                                        <form method="POST" action="{{ route('integrations.shipping-labels.update', $integration) }}">
                                            @csrf
                                            @method('PUT')
                                            <label><input type="checkbox" name="shipping_label_enabled" value="1" @checked($labelSettings['enabled'])> Włącz generowanie etykiet</label>
                                            <input name="shipping_label_endpoint" placeholder="/wp-json/plugin/v1/orders/{order_id}/label" value="{{ $labelSettings['endpoint'] }}" autocomplete="off">
                                            <select name="shipping_label_method">
                                                @foreach (['POST', 'GET', 'PUT'] as $method)
                                                    <option value="{{ $method }}" @selected($labelSettings['method'] === $method)>{{ $method }}</option>
                                                @endforeach
                                            </select>
                                            <select name="shipping_label_auth">
                                                <option value="woocommerce" @selected($labelSettings['auth'] === 'woocommerce')>WooCommerce REST</option>
                                                <option value="wordpress" @selected($labelSettings['auth'] === 'wordpress')>WordPress REST</option>
                                                <option value="none" @selected($labelSettings['auth'] === 'none')>Bez auth</option>
                                            </select>
                                            <input name="shipping_label_url_key" placeholder="JSON URL, np. label_url" value="{{ $labelSettings['url_key'] }}" autocomplete="off">
                                            <input name="shipping_label_base64_key" placeholder="JSON base64, np. label_base64" value="{{ $labelSettings['base64_key'] }}" autocomplete="off">
                                            <input name="shipping_label_filename_key" placeholder="JSON filename, np. filename" value="{{ $labelSettings['filename_key'] }}" autocomplete="off">
                                            <p class="muted">Endpoint może zawierać {order_id} albo {order_number}. ERP zapisze PDF/PNG/ZPL zwrócony bezpośrednio lub pobrany z URL w JSON.</p>
                                            <button class="button secondary" type="submit">Zapisz etykiety</button>
                                        </form>
                                    </details>
                                    <details class="compact-editor integration-editor">
                                        <summary>Statusy Woo</summary>
                                        <form method="POST" action="{{ route('integrations.order-statuses.update', $integration) }}" class="integration-edit-form">
                                            @csrf
                                            @method('PUT')
                                            <label>Po spakowaniu
                                                <input name="ready_to_ship_status" value="{{ $orderStatusSettings['ready_to_ship'] }}" required>
                                            </label>
                                            <label>Po odbiorze przez kuriera
                                                <input name="shipped_status" value="{{ $orderStatusSettings['shipped'] }}" required>
                                            </label>
                                            <label>Po cofnięciu pakowania
                                                <input name="packing_rollback_status" value="{{ $orderStatusSettings['packing_rollback'] }}">
                                            </label>
                                            <button class="button secondary" type="submit">Zapisz statusy</button>
                                        </form>
                                    </details>
                                    <form method="POST" action="{{ route('integrations.test', $integration) }}">
                                        @csrf
                                        <button class="button secondary" type="submit">Test API</button>
                                    </form>
                                    <form method="POST" action="{{ route('integrations.import-products', $integration) }}">
                                        @csrf
                                        <button class="button" type="submit">Kolejkuj produkty</button>
                                    </form>
                                    <form method="POST" action="{{ route('integrations.import-orders', $integration) }}">
                                        @csrf
                                        <button class="button" type="submit">Kolejkuj zamówienia</button>
                                    </form>
                                    <form method="POST" action="{{ route('integrations.destroy', $integration) }}" onsubmit="return confirm('Usunąć integrację?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button" style="background: var(--red);" type="submit">Usuń</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Brak integracji. Dodaj aktualny sklep WooCommerce, aby zacząć test API, import produktów i import zamówień.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    <article class="card" style="margin-top: 18px;">
        <div class="panel-header">Ostatnie logi synchronizacji</div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Czas</th>
                        <th>Kanał</th>
                        <th>Operacja</th>
                        <th>Status</th>
                        <th>Wynik</th>
                        <th>Błąd</th>
                        <th>Akcja</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        @php
                            $statusClass = match ($log->status) {
                                'success' => '',
                                'failed' => 'red',
                                default => 'blue',
                            };
                        @endphp
                        <tr>
                            <td>{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td>{{ $log->salesChannel?->code ?? '-' }}</td>
                            <td>{{ $log->operation }}</td>
                            <td><span class="status {{ $statusClass }}">{{ $log->status }}</span></td>
                            <td>
                                @if (is_array($log->response_payload))
                                    @php
                                        $payload = $log->response_payload;
                                        $duplicateSkuItems = (int) ($payload['duplicate_sku_items'] ?? 0);
                                        $mappingOverwrites = (int) ($payload['mapping_overwrites'] ?? 0);
                                    @endphp
                                    @if ($log->operation === 'import_products' && ($duplicateSkuItems > 0 || $mappingOverwrites > 0))
                                        <div class="sync-warning">
                                            Import produktów wymaga sprawdzenia:
                                            @if ($duplicateSkuItems > 0)
                                                {{ $duplicateSkuItems }} pozycji ma SKU użyte więcej niż raz.
                                            @endif
                                            @if ($mappingOverwrites > 0)
                                                {{ $mappingOverwrites }} mapowań zostało nadpisanych innym ID WooCommerce.
                                            @endif
                                        </div>
                                    @endif
                                    <ul class="sync-result-list">
                                        @foreach ($payload as $key => $value)
                                            @php
                                                $isWarningValue = in_array($key, $syncWarningKeys, true) && (int) $value > 0;
                                            @endphp
                                            <li @class(['is-warning' => $isWarningValue])>
                                                <span>{{ $syncResultLabels[$key] ?? str_replace('_', ' ', (string) $key) }}</span>
                                                <strong>{{ $formatSyncResultValue($value) }}</strong>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $log->error_message ?? '-' }}</td>
                            <td>
                                @if ($log->status === 'failed' && in_array($log->operation, ['import_products', 'import_orders'], true) && $log->wordpressIntegration)
                                    <form method="POST" action="{{ route('integrations.logs.retry', $log) }}">
                                        @csrf
                                        <button class="button secondary" type="submit">Ponów import</button>
                                    </form>
                                @elseif ($log->status === 'queued')
                                    <span class="status blue">W kolejce</span>
                                @elseif ($log->status === 'running')
                                    <span class="status blue">W toku</span>
                                @else
                                    <span class="muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">Brak logów. Pierwszy wpis pojawi się po teście API albo imporcie.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
@endsection

@push('styles')
    <style>
        .integration-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .integration-form-grid .wide { grid-column: 1 / -1; }
        .integration-editor { min-width: 220px; }
        .integration-edit-form { min-width: min(720px, calc(100vw - 80px)); display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px !important; align-items: end !important; padding: 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); box-shadow: var(--shadow); }
        .integration-edit-form .check-row { display: flex; grid-template-columns: auto 1fr; align-items: center; gap: 8px; }
        .integration-edit-form .button { min-height: 42px; }
        .sync-result-list { display: grid; gap: 4px; min-width: 280px; margin: 0; padding: 0; list-style: none; }
        .sync-result-list li { display: grid; grid-template-columns: minmax(170px, 1fr) auto; gap: 12px; align-items: baseline; color: var(--muted); font-size: 12px; }
        .sync-result-list strong { color: var(--text); font-variant-numeric: tabular-nums; }
        .sync-result-list li.is-warning span,
        .sync-result-list li.is-warning strong { color: var(--red); }
        .sync-warning { max-width: 520px; margin-bottom: 8px; padding: 8px 10px; border: 1px solid rgba(220, 38, 38, .28); border-radius: 8px; background: rgba(220, 38, 38, .08); color: var(--red); font-size: 12px; font-weight: 800; line-height: 1.35; }
        @media (max-width: 760px) {
            .integration-form-grid, .integration-edit-form { grid-template-columns: 1fr; }
            .sync-result-list li { grid-template-columns: 1fr; gap: 1px; }
        }
    </style>
@endpush
