@extends('layouts.app', ['title' => 'Integracje', 'subtitle' => 'Połączenia sklepów, KSeF, GS1 i kolejka synchronizacji w jednym uporządkowanym miejscu.', 'module' => 'integrations'])

@php
    $syncOperationLabels = [
        'import_products' => 'Import produktów',
        'import_orders' => 'Import zamówień',
        'import_customers' => 'Import klientów',
    ];
    $syncResultLabels = [
        'source_items' => 'Pozycje z WooCommerce',
        'source_products' => 'Produkty z WooCommerce',
        'source_variations' => 'Warianty z WooCommerce',
        'source_variable_parents' => 'Produkty główne wariantowe',
        'source_simple_products' => 'Produkty proste',
        'unique_skus_seen' => 'Unikalne SKU w imporcie',
        'synthetic_sku_items' => 'Pozycje z nadanym SKU ERP',
        'duplicate_sku_items' => 'Dodatkowe wystąpienia powielonego SKU',
        'duplicate_sku_groups_count' => 'Grupy zduplikowanych SKU',
        'duplicate_sku_resolved' => 'Duplikaty SKU rozdzielone na osobne mapowania',
        'duplicate_ean_items' => 'Duplikaty EAN w imporcie',
        'translation_eans_reclaimed' => 'EAN odzyskane z tłumaczeń',
        'translation_products_reclassified' => 'Istniejące tłumaczenia oznaczone poza katalogiem głównym',
        'translation_aliases_mapped' => 'Aliasów Polylang przypisanych do produktu',
        'translation_products_merged' => 'Duplikatów tłumaczeń scalonych z produktem głównym',
        'parameter_definitions_localized' => 'Parametrów uzupełnionych o tłumaczenie EN',
        'parameter_definitions_merged' => 'Osobnych parametrów PL/EN scalonych w jeden',
        'mapping_overwrites' => 'Nadpisane mapowania kanału',
        'created' => 'Utworzone w ERP',
        'updated' => 'Zaktualizowane w ERP',
        'mapped' => 'Zmapowane pozycje',
        'stock_updated' => 'Zaktualizowane stany',
        'stock_skipped_ambiguous_routes' => 'Pominięte stany przy wielu magazynach lub buforze',
        'stock_skipped_pending_export' => 'Pominięte stany z oczekującym lub nieudanym eksportem ERP → Woo',
        'stock_skipped_waiting_reservations' => 'Pominięte stany z oczekującymi rezerwacjami',
        'skipped' => 'Pominięte',
        'skipped_missing_identifier' => 'Pominięte bez identyfikatora',
        'products_total_before' => 'Wszystkie rekordy ERP przed importem',
        'products_primary_before' => 'Produkty główne ERP przed importem',
        'categories_total_before' => 'Kategorie ERP przed importem',
        'category_aliases_total_before' => 'Mapowania językowe kategorii przed importem',
        'products_total_after' => 'Wszystkie rekordy ERP po imporcie',
        'products_primary_after' => 'Produkty główne ERP po imporcie',
        'products_historical_aliases_after' => 'Scalone aliasy historyczne',
        'channel_mappings_total_after' => 'Mapowania kanału po imporcie',
        'categories_total_after' => 'Kategorie ERP po scaleniu PL/EN',
        'category_aliases_total_after' => 'Mapowania językowe kategorii po imporcie',
        'lines' => 'Pozycje zamówień',
        'reserved' => 'Zarezerwowane',
        'released' => 'Zwolnione rezerwacje',
        'reservation_skipped' => 'Pominięte rezerwacje',
        'orders_linked' => 'Powiązane zamówienia historyczne',
        'notification_baseline' => 'Import bazowy bez maili',
        'notification_cutoff' => 'Powiadomienia od daty',
        'notification_previous_success_at' => 'Poprzedni udany import klientów',
        'notification_baseline_at' => 'Granica historycznych kont bez maili',
        'notification_overlap_minutes' => 'Bufor powiadomień (min)',
        'created_customer_ids_count' => 'Nowi klienci w imporcie',
        'created_external_account_ids_count' => 'Nowe konta Woo w imporcie',
        'notifications_eligible' => 'Nowe konta kwalifikujące się do powiadomienia',
        'notifications_created' => 'Utworzone powiadomienia o koncie',
        'notifications_sent' => 'Wysłane powiadomienia o koncie',
        'notifications_held' => 'Powiadomienia oczekujące na wysyłkę',
        'notifications_failed' => 'Nieudane powiadomienia o koncie',
        'notifications_skipped' => 'Pominięte powiadomienia o koncie',
        'notification_errors' => 'Błędy powiadomień o koncie',
    ];
    $syncWarningKeys = [
        'duplicate_sku_items',
        'mapping_overwrites',
        'stock_skipped_ambiguous_routes',
        'stock_skipped_pending_export',
        'stock_skipped_waiting_reservations',
    ];
    $syncHiddenResultKeys = [
        'duplicate_sku_groups',
        'created_customer_ids',
        'created_external_account_ids',
    ];
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
    $activeStoresCount = $integrations->count();
    $storesWithLabels = $integrations->filter(fn ($integration): bool => $integration->shippingLabelsEnabled())->count();
    $activeJobsCount = $logs->whereIn('status', ['queued', 'running'])->count();
@endphp

@section('content')
    <input id="integration-drawer" class="drawer-toggle" type="checkbox">

    <div class="page-toolbar">
        <div class="integration-overview" aria-label="Podsumowanie integracji">
            <span><strong>{{ $activeStoresCount }}</strong> sklepy WooCommerce</span>
            <span><strong>{{ $storesWithLabels }}</strong> z etykietami</span>
            <span><strong>{{ $activeJobsCount }}</strong> w kolejce</span>
            <span @class(['has-error' => $failedLogsCount > 0])><strong>{{ $failedLogsCount }}</strong> błędy</span>
        </div>
        <label class="button" for="integration-drawer">Dodaj sklep WooCommerce</label>
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
            <label class="check-row"><input type="checkbox" name="order_import_enabled" value="1" checked> Import zamówień</label>
            <label class="check-row"><input type="checkbox" name="stock_export_enabled" value="1" checked> Eksport stanów</label>
            <label class="check-row"><input type="checkbox" name="invoice_upload_enabled" value="1" checked> Upload faktur do zamówień</label>
            <label>Tryb faktur w WooCommerce
                <select name="invoice_delivery_mode">
                    <option value="lemon_plugin" @selected(old('invoice_delivery_mode', 'lemon_plugin') === 'lemon_plugin')>Wtyczka Lemon ERP bez Media Library</option>
                    <option value="media_library" @selected(old('invoice_delivery_mode') === 'media_library')>WordPress Media Library</option>
                </select>
            </label>
            <button class="button" type="submit">Zapisz integrację</button>
        </form>
    </aside>

    <nav class="integration-tabs" aria-label="Sekcje integracji">
        <a class="active" href="#woocommerce" data-integration-tab="woocommerce">
            <span>WooCommerce</span>
            <strong>{{ $activeStoresCount }}</strong>
        </a>
        <a href="#ksef" data-integration-tab="ksef">
            <span>KSeF</span>
            <strong>{{ $ksefConfiguration['direct_online_send_ready'] ? 'OK' : '!' }}</strong>
        </a>
        <a href="#gs1" data-integration-tab="gs1">
            <span>GS1</span>
            <strong>{{ $gs1Settings['ready'] ? 'OK' : '!' }}</strong>
        </a>
        <a href="#logs" data-integration-tab="logs">
            <span>Logi sync</span>
            <strong>{{ $logs->count() }}</strong>
        </a>
    </nav>

    <section class="integration-tab-panel active" id="woocommerce" data-integration-panel="woocommerce">
        <div class="integration-section-grid">
            <article class="card integration-plugin-card" id="woocommerce-plugin">
                <div class="panel-header">
                    <span>Wtyczka Lemon ERP for WooCommerce</span>
                    <span>v{{ $woocommercePluginVersion }}</span>
                </div>
                <div class="integration-panel-body">
                    <p class="muted">
                        Pobierz paczkę ZIP, wgraj ją w WordPress przez Wtyczki -> Dodaj nową -> Wyślij wtyczkę, a potem aktywuj plugin.
                        Wtyczka dodaje pola NIP/typ klienta, endpoint faktur, natychmiastowe webhooki klientów oraz pewną identyfikację grup tłumaczeń Polylang.
                        Wersja 0.4.1 lub nowsza obsługuje konfigurację webhooków bez dodatkowych danych; wersja 0.4.0 działa przy skonfigurowanym loginie i haśle aplikacji WordPress REST.
                    </p>
                    <div class="inline-actions">
                        <a class="button" href="{{ route('integrations.woocommerce-plugin.download') }}">Pobierz plugin ZIP</a>
                        <span class="muted">Plik jest generowany z aktualnej wersji repo ERP.</span>
                    </div>
                </div>
            </article>

            <article class="card integration-help-card">
                <div class="panel-header">
                    <span>Szybka kontrola</span>
                    <span>WooCommerce</span>
                </div>
                <div class="integration-health-list">
                    <div><span>Aktywne sklepy</span><strong>{{ $activeStoresCount }}</strong></div>
                    <div><span>Import zamówień</span><strong>{{ $integrations->where('order_import_enabled', true)->count() }}</strong></div>
                    <div><span>Eksport stanów</span><strong>{{ $integrations->where('stock_export_enabled', true)->count() }}</strong></div>
                    <div><span>Upload faktur</span><strong>{{ $integrations->where('invoice_upload_enabled', true)->count() }}</strong></div>
                </div>
            </article>

            <article class="card integration-help-card" id="english-translation-report">
                <div class="panel-header">
                    <span>Tłumaczenia EN</span>
                    <span>{{ $englishTranslationReport['healthy'] }} / {{ $englishTranslationReport['total'] }} rodzin</span>
                </div>
                <div class="integration-health-list">
                    <div><span>Bez tłumaczenia EN</span><strong>{{ $englishTranslationReport['missing'] }}</strong></div>
                    <div><span>Jednojęzyczne (celowo, brak treści EN)</span><strong>{{ $englishTranslationReport['monolingual'] }}</strong></div>
                    <div><span>W trakcie naprawy (zakolejkowane)</span><strong>{{ $englishTranslationReport['queued'] }}</strong></div>
                    <div><span>Czeka na pierwszy przebieg automatu</span><strong>{{ $englishTranslationReport['unprocessed'] }}</strong></div>
                    <div><span>Niespięty post EN — decyzja operatora</span><strong>{{ $englishTranslationReport['live_ref'] }}</strong></div>
                    <div><span>Wspólne warianty — do rozplątania</span><strong>{{ $englishTranslationReport['shared_children'] }}</strong></div>
                    <div><span>Po 3 porażkach — wymaga człowieka</span><strong>{{ $englishTranslationReport['failed_manual'] }}</strong></div>
                    <div><span>Błędy weryfikacji (ponawiane co dobę)</span><strong>{{ $englishTranslationReport['check_failed'] }}</strong></div>
                </div>
                @foreach ([
                    'Niespięte posty EN (spnij w Polylang albo usuń trwale post)' => ['rows' => $englishTranslationReport['live_ref_rows'], 'detail' => 'post EN #'],
                    'Rodziny po 3 nieudanych eksportach' => ['rows' => $englishTranslationReport['failed_manual_rows'], 'detail' => ''],
                    'Wspólne warianty (bliźniaki do rozplątania)' => ['rows' => $englishTranslationReport['shared_children_rows'], 'detail' => ''],
                    'Błędy weryfikacji' => ['rows' => $englishTranslationReport['check_failed_rows'], 'detail' => ''],
                ] as $sectionTitle => $section)
                    @if ($section['rows'] !== [])
                        <div class="toolbar-note" style="margin-top: 10px; font-weight: 760;">{{ $sectionTitle }} (max 20):</div>
                        <ul style="margin: 4px 0 0 18px; font-size: 12px;">
                            @foreach ($section['rows'] as $row)
                                <li>
                                    <strong>{{ $row['sku'] }}</strong> — {{ \Illuminate\Support\Str::limit($row['name'], 40) }}
                                    @if ($row['detail'] !== '')
                                        <em>({{ $section['detail'] }}{{ \Illuminate\Support\Str::limit($row['detail'], 90) }})</em>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            </article>
        </div>

        <section class="integration-store-list" aria-label="Aktywne integracje WooCommerce">
            <div class="integration-section-title">
                <div>
                    <h2>Aktywne integracje WooCommerce</h2>
                    <p>Każdy sklep ma osobną konfigurację API, WP REST, etykiet i statusów pakowania.</p>
                </div>
                <label class="button secondary" for="integration-drawer">Dodaj sklep</label>
            </div>

            @forelse ($integrations as $integration)
                @php
                    $labelSettings = $integration->shippingLabelSettings();
                    $orderStatusSettings = $integration->orderStatusSettings();
                    $invoiceDelivery = $integration->invoiceDeliverySettings();
                    $customerWebhookConfigured = (bool) data_get($integration->settings, 'customer_webhook.configured', false);
                @endphp
                <article class="card integration-store-card">
                    <div class="integration-store-head">
                        <div>
                            <div class="integration-store-title">
                                <strong>{{ $integration->salesChannel->code }}</strong>
                                <span>{{ $integration->name }}</span>
                            </div>
                            <a class="integration-url" href="{{ $integration->base_url }}" target="_blank" rel="noopener">{{ $integration->base_url }}</a>
                        </div>
                        <div class="integration-status-strip">
                            <span @class(['status' => true, 'orange' => ! $integration->order_import_enabled])>Zamówienia {{ $integration->order_import_enabled ? 'ON' : 'OFF' }}</span>
                            <span @class(['status' => true, 'orange' => ! $integration->stock_export_enabled])>Stany {{ $integration->stock_export_enabled ? 'ON' : 'OFF' }}</span>
                            <span @class(['status' => true, 'orange' => ! $integration->invoice_upload_enabled])>Faktury {{ $integration->invoice_upload_enabled ? 'ON' : 'OFF' }}</span>
                            <span @class(['status' => true, 'orange' => ! $customerWebhookConfigured])>Klienci live {{ $customerWebhookConfigured ? 'OK' : 'brak' }}</span>
                            <span @class(['status' => true, 'orange' => ! $integration->shippingLabelsEnabled()])>Etykiety {{ $integration->shippingLabelsEnabled() ? 'OK' : 'brak' }}</span>
                        </div>
                    </div>

                    <div class="integration-store-body">
                        <div class="integration-facts">
                            <div><span>Klucz REST</span><strong>{{ $integration->maskedConsumerKey() }}</strong></div>
                            <div><span>WP REST</span><strong>{{ $integration->hasWordpressMediaCredentials() ? 'Gotowe' : 'Brak danych' }}</strong></div>
                            <div><span>Tryb faktur</span><strong>{{ $invoiceDelivery['mode'] === 'lemon_plugin' ? 'Wtyczka Lemon ERP' : 'Media Library' }}</strong></div>
                            <div><span>Ostatni sync</span><strong>{{ $integration->last_successful_sync_at?->format('Y-m-d H:i') ?? '-' }}</strong></div>
                        </div>

                        <div class="integration-action-rail">
                            <form method="POST" action="{{ route('integrations.test', $integration) }}">
                                @csrf
                                <button class="button secondary" type="submit">Test API</button>
                            </form>
                            <form method="POST" action="{{ route('integrations.customer-webhook.configure', $integration) }}">
                                @csrf
                                <button class="button secondary" type="submit">Włącz webhook klientów</button>
                            </form>
                            <form method="POST" action="{{ route('integrations.import-products', $integration) }}">
                                @csrf
                                <button class="button" type="submit">Kolejkuj produkty</button>
                            </form>
                            <form method="POST" action="{{ route('integrations.import-orders', $integration) }}">
                                @csrf
                                <button class="button" type="submit">Kolejkuj zamówienia</button>
                            </form>
                            <form method="POST" action="{{ route('integrations.import-customers', $integration) }}">
                                @csrf
                                <button class="button" type="submit">Kolejkuj klientów</button>
                            </form>
                            <form method="POST" action="{{ route('integrations.destroy', $integration) }}" onsubmit="return confirm('Usunąć integrację?');">
                                @csrf
                                @method('DELETE')
                                <button class="button danger" type="submit">Usuń</button>
                            </form>
                        </div>

                        <div class="integration-config-grid">
                            <details class="integration-config-panel" open>
                                <summary>Edytuj konfigurację sklepu</summary>
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
                                    <label>Tryb faktur
                                        <select name="invoice_delivery_mode">
                                            <option value="lemon_plugin" @selected($invoiceDelivery['mode'] === 'lemon_plugin')>Wtyczka Lemon ERP bez Media Library</option>
                                            <option value="media_library" @selected($invoiceDelivery['mode'] === 'media_library')>WordPress Media Library</option>
                                        </select>
                                    </label>
                                    <button class="button secondary" type="submit">Zapisz integrację</button>
                                </form>
                            </details>

                            <details class="integration-config-panel">
                                <summary>WP REST</summary>
                                <form method="POST" action="{{ route('integrations.wordpress-credentials.update', $integration) }}" class="integration-edit-form">
                                    @csrf
                                    @method('PUT')
                                    <label>Login WordPress
                                        <input name="wp_api_username" placeholder="login WordPress" value="{{ $integration->wp_api_username }}" autocomplete="off" required>
                                    </label>
                                    <label>Hasło aplikacji
                                        <input name="wp_api_application_password" placeholder="hasło aplikacji" autocomplete="off" required>
                                    </label>
                                    <button class="button secondary" type="submit">Zapisz WP REST</button>
                                </form>
                            </details>

                            <details class="integration-config-panel">
                                <summary>Etykiety kurierskie</summary>
                                <form method="POST" action="{{ route('integrations.shipping-labels.update', $integration) }}" class="integration-edit-form">
                                    @csrf
                                    @method('PUT')
                                    <label class="check-row wide"><input type="checkbox" name="shipping_label_enabled" value="1" @checked($labelSettings['enabled'])> Włącz generowanie etykiet</label>
                                    <label class="wide">Endpoint etykiet
                                        <input name="shipping_label_endpoint" placeholder="/wp-json/plugin/v1/orders/{order_id}/label" value="{{ $labelSettings['endpoint'] }}" autocomplete="off">
                                    </label>
                                    <label>Metoda
                                        <select name="shipping_label_method">
                                            @foreach (['POST', 'GET', 'PUT'] as $method)
                                                <option value="{{ $method }}" @selected($labelSettings['method'] === $method)>{{ $method }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Autoryzacja
                                        <select name="shipping_label_auth">
                                            <option value="woocommerce" @selected($labelSettings['auth'] === 'woocommerce')>WooCommerce REST</option>
                                            <option value="wordpress" @selected($labelSettings['auth'] === 'wordpress')>WordPress REST</option>
                                            <option value="none" @selected($labelSettings['auth'] === 'none')>Bez auth</option>
                                        </select>
                                    </label>
                                    <label>JSON URL
                                        <input name="shipping_label_url_key" placeholder="np. label_url" value="{{ $labelSettings['url_key'] }}" autocomplete="off">
                                    </label>
                                    <label>JSON base64
                                        <input name="shipping_label_base64_key" placeholder="np. label_base64" value="{{ $labelSettings['base64_key'] }}" autocomplete="off">
                                    </label>
                                    <label>JSON filename
                                        <input name="shipping_label_filename_key" placeholder="np. filename" value="{{ $labelSettings['filename_key'] }}" autocomplete="off">
                                    </label>
                                    <p class="muted wide">Endpoint może zawierać {order_id} albo {order_number}. ERP zapisze PDF/PNG/ZPL zwrócony bezpośrednio lub pobrany z URL w JSON.</p>
                                    <button class="button secondary" type="submit">Zapisz etykiety</button>
                                </form>
                            </details>

                            <details class="integration-config-panel">
                                <summary>Statusy WooCommerce</summary>
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
                        </div>
                    </div>
                </article>
            @empty
                <article class="card integration-empty">
                    <strong>Brak integracji.</strong>
                    <span>Dodaj aktualny sklep WooCommerce, aby zacząć test API, import produktów i import zamówień.</span>
                    <label class="button" for="integration-drawer">Dodaj sklep WooCommerce</label>
                </article>
            @endforelse
        </section>
    </section>

    <section class="integration-tab-panel" id="ksef" data-integration-panel="ksef" hidden>
        <article class="card">
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
                    <label>Adres bramki KSeF (opcjonalnie)
                        <input name="gateway_url" value="{{ old('gateway_url', $ksefSettings['gateway_url']) }}" placeholder="Puste = natywna sesja online KSeF 2.0">
                    </label>
                    <label>Adres statusu KSeF (opcjonalnie)
                        <input name="status_url" value="{{ old('status_url', $ksefSettings['status_url']) }}" placeholder="Puste = natywny status z API MF">
                    </label>
                    <label>Preferowany publicKeyId KSeF (opcjonalnie)
                        <input name="public_key_id" value="{{ old('public_key_id', $ksefSettings['public_key_id']) }}" placeholder="Puste = wybierz aktualny klucz MF automatycznie">
                    </label>
                    <label>SHA256 klucza KSeF (opcjonalnie)
                        <input name="public_key_sha256" value="{{ old('public_key_sha256', $ksefSettings['public_key_sha256']) }}" maxlength="64" placeholder="64 znaki hex">
                    </label>
                </div>
                <label>Token KSeF
                    <input name="access_token" type="password" autocomplete="new-password" placeholder="{{ $ksefSettings['has_access_token'] ? 'Token jest zapisany; wpisz nowy tylko jeśli chcesz go zmienić' : 'Wklej pełny token KSeF, nie ID/SHA certyfikatu' }}">
                </label>
                @if ($ksefSettings['has_access_token'])
                    <label class="check-row">
                        <input name="clear_access_token" type="checkbox" value="1">
                        Usuń zapisany token KSeF {{ $ksefSettings['access_token_hint'] ? '(' . $ksefSettings['access_token_hint'] . ')' : '' }}
                    </label>
                @endif
                <div class="toolbar-note">
                    Aktualnie: KSeF API {{ $ksefConfiguration['api_version'] }} | środowisko: {{ $ksefConfiguration['environment'] }} | {{ $ksefConfiguration['base_url'] }}.
                    Tryb: {{ $ksefConfiguration['delivery_mode'] === 'gateway' ? 'bramka zewnętrzna' : 'natywna sesja online MF' }}.
                    Status: {{ $ksefConfiguration['status_url'] ?: 'natywnie z API MF' }}.
                    Preferowany klucz: {{ $ksefConfiguration['public_key_id'] ?: 'automatyczny wybór z MF' }} / SHA256: {{ $ksefConfiguration['public_key_sha256'] ?: 'brak pinu' }}.
                    Pola publicKeyId/SHA zostaw puste, chyba że świadomie pinujesz klucz szyfrujący MF. ID/SHA certyfikatu nie wystarczają do logowania tokenem.
                    Token jest przechowywany zaszyfrowany. Bez bramki ERP sam pobiera certyfikaty MF, inicjuje sesję online, szyfruje XML i sprawdza statusy.
                </div>
                <div class="inline-actions">
                    <button class="button" type="submit">Zapisz konfigurację KSeF</button>
                    <a class="button secondary" href="{{ route('ksef.index') }}">Przejdź do faktur KSeF</a>
                </div>
            </form>
        </article>
    </section>

    <section class="integration-tab-panel" id="gs1" data-integration-panel="gs1" hidden>
        <article class="card">
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
                    <label class="check-row">
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
    </section>

    <section class="integration-tab-panel" id="logs" data-integration-panel="logs" hidden>
        <article class="card">
            <div class="panel-header">
                <span>Ostatnie logi synchronizacji</span>
                <div class="inline-actions integration-log-actions">
                    <span>{{ $logs->count() }} ostatnich wpisów</span>
                    @if ($failedLogsCount > 0)
                        <form
                            method="POST"
                            action="{{ route('integrations.logs.failed.destroy') }}"
                            onsubmit="return confirm('Usunąć historyczne logi nieudanych synchronizacji ({{ $failedLogsCount }})? Bieżące problemy eksportu stanów pozostaną widoczne w Kolejce sync, dopóki nie zostaną rozwiązane lub ponowione.');"
                        >
                            @csrf
                            @method('DELETE')
                            <button class="button danger" type="submit">Wyczyść historię błędów ({{ $failedLogsCount }})</button>
                        </form>
                    @endif
                </div>
            </div>
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
                                <td>{{ $syncOperationLabels[$log->operation] ?? $log->operation }}</td>
                                <td><span class="status {{ $statusClass }}">{{ $log->status }}</span></td>
                                <td>
                                    @if (is_array($log->response_payload))
                                        @php
                                            $payload = $log->response_payload;
                                            $duplicateSkuItems = (int) ($payload['duplicate_sku_items'] ?? 0);
                                            $duplicateSkuResolved = (int) ($payload['duplicate_sku_resolved'] ?? 0);
                                            $mappingOverwrites = (int) ($payload['mapping_overwrites'] ?? 0);
                                            $stockSkipped = (int) ($payload['stock_skipped_ambiguous_routes'] ?? 0)
                                                + (int) ($payload['stock_skipped_pending_export'] ?? 0)
                                                + (int) ($payload['stock_skipped_waiting_reservations'] ?? 0);
                                        @endphp
                                        @if ($log->operation === 'import_products' && ($duplicateSkuItems > 0 || $mappingOverwrites > 0 || $stockSkipped > 0))
                                            <div class="sync-warning">
                                                Import produktów wymaga sprawdzenia:
                                                @if ($duplicateSkuItems > 0)
                                                    Wykryto powielone SKU (dodatkowe wystąpienia: {{ $duplicateSkuItems }}).
                                                    @if ($duplicateSkuResolved > 0)
                                                        {{ $duplicateSkuResolved }} z nich otrzymało automatycznie osobne mapowanie ERP.
                                                    @endif
                                                    <a href="{{ route('products.index', ['import_issue' => $log->id]) }}">Pokaż produkty z powtórzonym SKU</a>
                                                @endif
                                                @if ($mappingOverwrites > 0)
                                                    {{ $mappingOverwrites }} mapowań zostało nadpisanych innym ID WooCommerce.
                                                @endif
                                                @if ($stockSkipped > 0)
                                                    {{ $stockSkipped }} stanów nie nadpisano dla bezpieczeństwa. Szczegóły są widoczne poniżej.
                                                @endif
                                            </div>
                                        @endif
                                        <ul class="sync-result-list">
                                            @foreach ($payload as $key => $value)
                                                @continue(in_array($key, $syncHiddenResultKeys, true))
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
                                    @if ($log->status === 'failed' && in_array($log->operation, ['import_products', 'import_orders', 'import_customers'], true) && $log->wordpressIntegration)
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
    </section>
@endsection

@push('styles')
    <style>
        .integration-overview { display: flex; gap: 8px; flex-wrap: wrap; color: var(--muted); }
        .integration-overview span { min-height: 38px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--border); border-radius: 8px; padding: 7px 10px; background: var(--surface); font-size: 13px; }
        .integration-overview strong { color: var(--text); font-size: 15px; }
        .integration-overview .has-error strong { color: var(--red); }
        .integration-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
        .integration-tabs a { min-height: 42px; display: inline-flex; align-items: center; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 12px; color: var(--text); background: var(--surface); text-decoration: none; font-weight: 760; }
        .integration-tabs a.active { color: var(--green-dark); background: var(--green-soft); border-color: rgba(134, 115, 100, .36); }
        .integration-tabs strong { min-width: 28px; min-height: 24px; display: inline-grid; place-items: center; border-radius: 7px; padding: 1px 7px; background: rgba(134, 115, 100, .12); color: var(--green-dark); font-size: 12px; }
        .integration-tab-panel { display: grid; gap: 14px; }
        .integration-tab-panel[hidden] { display: none; }
        .integration-section-grid { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(280px, .75fr); gap: 14px; align-items: stretch; margin-bottom: 14px; }
        .integration-panel-body { padding: 16px; display: grid; gap: 12px; }
        .integration-help-card { min-width: 0; }
        .integration-health-list { padding: 16px; display: grid; gap: 8px; }
        .integration-health-list div { display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--border); padding-bottom: 8px; color: var(--muted); }
        .integration-health-list div:last-child { border-bottom: 0; padding-bottom: 0; }
        .integration-health-list strong { color: var(--text); font-variant-numeric: tabular-nums; }
        .integration-section-title { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; margin: 4px 0 10px; flex-wrap: wrap; }
        .integration-section-title h2 { margin: 0; font-size: 18px; line-height: 1.2; letter-spacing: 0; }
        .integration-section-title p { margin: 4px 0 0; color: var(--muted); }
        .integration-store-list { display: grid; gap: 12px; }
        .integration-store-card { overflow: hidden; }
        .integration-store-head { min-height: 66px; display: flex; justify-content: space-between; gap: 14px; align-items: center; padding: 14px 16px; border-bottom: 1px solid var(--border); background: #fffdfb; }
        .integration-store-title { display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
        .integration-store-title strong { font-size: 18px; }
        .integration-store-title span { color: var(--muted); font-weight: 720; }
        .integration-url { color: var(--green-dark); text-decoration: none; font-size: 13px; font-weight: 720; overflow-wrap: anywhere; }
        .integration-status-strip { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .integration-store-body { padding: 16px; display: grid; gap: 14px; }
        .integration-facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .integration-facts div { min-height: 70px; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: var(--surface); display: grid; align-content: center; gap: 3px; min-width: 0; }
        .integration-facts span { color: var(--muted); font-size: 12px; font-weight: 720; }
        .integration-facts strong { color: var(--text); overflow-wrap: anywhere; }
        .integration-action-rail { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 10px; border: 1px solid var(--border); border-radius: 8px; background: rgba(134, 115, 100, .06); }
        .button.danger { background: var(--red); color: #fff; }
        .integration-config-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .integration-config-panel { border: 1px solid var(--border); border-radius: 8px; background: var(--surface); overflow: hidden; }
        .integration-config-panel summary { min-height: 42px; display: flex; align-items: center; padding: 10px 12px; cursor: pointer; color: var(--green-dark); font-weight: 820; border-bottom: 1px solid transparent; }
        .integration-config-panel[open] summary { border-bottom-color: var(--border); background: rgba(134, 115, 100, .06); }
        .integration-edit-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; padding: 12px; align-items: end; }
        .integration-edit-form .wide { grid-column: 1 / -1; }
        .integration-edit-form .button { min-height: 40px; justify-self: start; }
        .check-row { display: flex; grid-template-columns: auto 1fr; align-items: center; gap: 8px; color: var(--text); }
        .integration-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .integration-form-grid .wide { grid-column: 1 / -1; }
        .integration-empty { padding: 18px; display: grid; gap: 8px; justify-items: start; color: var(--muted); }
        .integration-empty strong { color: var(--text); font-size: 18px; }
        .integration-log-actions { justify-content: flex-end; font-weight: 650; }
        .sync-result-list { display: grid; gap: 4px; min-width: 280px; margin: 0; padding: 0; list-style: none; }
        .sync-result-list li { display: grid; grid-template-columns: minmax(170px, 1fr) auto; gap: 12px; align-items: baseline; color: var(--muted); font-size: 12px; }
        .sync-result-list strong { color: var(--text); font-variant-numeric: tabular-nums; }
        .sync-result-list li.is-warning span,
        .sync-result-list li.is-warning strong { color: var(--red); }
        .sync-warning { max-width: 520px; margin-bottom: 8px; padding: 8px 10px; border: 1px solid rgba(220, 38, 38, .28); border-radius: 8px; background: rgba(220, 38, 38, .08); color: var(--red); font-size: 12px; font-weight: 800; line-height: 1.35; }
        .sync-warning a { display: inline-block; margin-left: 5px; color: var(--red); text-decoration: underline; text-underline-offset: 2px; }
        @media (max-width: 1120px) {
            .integration-section-grid, .integration-facts, .integration-config-grid { grid-template-columns: 1fr; }
            .integration-store-head { align-items: flex-start; flex-direction: column; }
            .integration-status-strip { justify-content: flex-start; }
        }
        @media (max-width: 760px) {
            .integration-tabs a { flex: 1 1 150px; justify-content: space-between; }
            .integration-edit-form, .integration-form-grid { grid-template-columns: 1fr; }
            .integration-action-rail { align-items: stretch; flex-direction: column; }
            .integration-action-rail form, .integration-action-rail .button { width: 100%; }
            #logs .panel-header { align-items: flex-start; flex-direction: column; gap: 8px; padding: 12px 16px; }
            .integration-log-actions { width: 100%; justify-content: flex-start; }
            .sync-result-list li { grid-template-columns: 1fr; gap: 1px; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const tabs = Array.from(document.querySelectorAll('[data-integration-tab]'));
            const panels = Array.from(document.querySelectorAll('[data-integration-panel]'));
            const hashToTab = {
                'woocommerce-plugin': 'woocommerce',
                'woocommerce': 'woocommerce',
                'ksef': 'ksef',
                'gs1': 'gs1',
                'logs': 'logs',
            };

            const activate = (tabName) => {
                const nextTab = hashToTab[tabName] || 'woocommerce';

                tabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.integrationTab === nextTab));
                panels.forEach((panel) => {
                    const active = panel.dataset.integrationPanel === nextTab;
                    panel.hidden = !active;
                    panel.classList.toggle('active', active);
                });
            };

            tabs.forEach((tab) => {
                tab.addEventListener('click', () => {
                    activate(tab.dataset.integrationTab);
                });
            });

            window.addEventListener('hashchange', () => activate(window.location.hash.replace('#', '')));
            activate(window.location.hash.replace('#', ''));
        })();
    </script>
@endpush
