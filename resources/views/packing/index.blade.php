@extends('layouts.app', [
    'title' => match ($packingView ?? 'home') {
        'collect' => 'Kompletacja',
        'history' => 'Historia pakowania',
        default => 'Pakowanie',
    },
    'module' => 'packing',
    'hideTopActions' => true,
    'compactHeader' => in_array(($packingView ?? 'home'), ['collect', 'pack', 'history'], true),
    'headerBackUrl' => in_array(($packingView ?? 'home'), ['collect', 'pack', 'history'], true) ? route('packing.index', ['view' => 'home']) : null,
])

@push('styles')
    <style>
        .packing-home-toolbar { display: flex; justify-content: flex-end; align-items: center; margin-bottom: 14px; }
        .packing-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .packing-stat { padding: 14px 16px; }
        .packing-stat strong { display: block; font-size: 25px; line-height: 1; margin-top: 3px; }
        .workflow-picker { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .workflow-card { min-height: 148px; display: grid; align-content: center; gap: 7px; border: 1px solid var(--border); border-radius: 8px; padding: 22px; background: var(--surface); color: var(--text); text-decoration: none; box-shadow: var(--shadow); }
        .workflow-card span { color: var(--muted); font-weight: 780; text-transform: uppercase; letter-spacing: .04em; font-size: 12px; }
        .workflow-card strong { font-size: clamp(32px, 4vw, 50px); line-height: 1; letter-spacing: -.03em; }
        .workflow-card small { color: var(--muted); font-weight: 650; }
        .packing-home-links { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
        .packing-home-links .button { min-height: 46px; }
        .settings-body { padding: 16px; display: grid; gap: 14px; }
        .mode-copy { color: var(--muted); }
        .mode-copy strong { display: block; color: var(--text); font-size: 16px; margin-bottom: 3px; }
        .mode-actions { display: grid; gap: 8px; }
        .mode-button { width: 100%; min-height: 46px; border: 1px solid var(--border); border-radius: 8px; padding: 8px 11px; background: #fff; color: var(--text); font: inherit; font-weight: 760; cursor: pointer; text-align: left; }
        .mode-button.active { background: var(--green); border-color: var(--green); color: #fff; }
        .collection-workspace { max-width: 1040px; margin: 0 auto; }
        .queue-list { display: grid; gap: 12px; }
        .pick-card, .order-card, .courier-card, .history-card { border: 1px solid var(--border); border-radius: 8px; background: var(--surface); box-shadow: var(--shadow); }
        .collect-card { padding: 14px; display: grid; gap: 11px; }
        .collect-main { display: grid; grid-template-columns: 78px minmax(0, 1fr) auto; gap: 14px; align-items: center; }
        .product-thumb { width: 58px; height: 72px; border: 1px solid var(--border); border-radius: 7px; overflow: hidden; background: #f4f1ef; display: grid; place-items: center; color: var(--muted); font-size: 11px; font-weight: 780; }
        .product-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .collect-card .product-thumb { width: 78px; height: 98px; }
        .pick-name { font-size: 17px; font-weight: 840; line-height: 1.25; }
        .pick-sku { color: var(--muted); font-size: 12px; font-weight: 760; letter-spacing: .02em; margin-top: 2px; }
        .collect-size { margin-top: 8px; display: inline-flex; align-items: baseline; gap: 8px; color: var(--muted); font-weight: 760; }
        .collect-size strong { color: var(--green-dark); font-size: clamp(38px, 8vw, 66px); line-height: .85; letter-spacing: -.04em; }
        .qty-pill { min-width: 82px; text-align: center; border-radius: 8px; padding: 10px 12px; background: var(--green-soft); color: var(--green-dark); font-weight: 850; font-size: 17px; }
        .pick-badges, .order-badges, .history-badges { display: flex; flex-wrap: wrap; gap: 6px; }
        .pick-badge { display: inline-flex; align-items: center; min-height: 26px; border-radius: 7px; padding: 2px 8px; background: rgba(134, 115, 100, .08); color: var(--muted); font-size: 12px; font-weight: 720; }
        .collect-note input { min-height: 48px; }
        .collect-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .collect-actions form { min-width: 0; }
        .collect-actions .button { width: 100%; min-height: 64px; font-size: 19px; border-radius: 8px; }
        .button.danger { background: #ffecec; color: var(--red); border: 1px solid #f0c3c3; }
        .packing-empty { padding: 18px 16px; color: var(--muted); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; }
        .history-panel { margin-top: 16px; }
        .history-list { display: grid; gap: 8px; padding: 12px; }
        .history-card { padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; box-shadow: none; }
        .pack-workspace { display: grid; gap: 16px; }
        .order-card { padding: 18px; display: grid; gap: 13px; }
        .order-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .order-title { font-size: 24px; line-height: 1.1; font-weight: 880; letter-spacing: -.02em; }
        .order-meta { color: var(--muted); margin-top: 4px; font-size: 15px; }
        .order-items { display: grid; gap: 8px; }
        .order-item { display: grid; grid-template-columns: 52px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 10px; border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; }
        .order-item .product-thumb { width: 52px; height: 64px; font-size: 10px; }
        .order-item-name { font-weight: 820; font-size: 16px; line-height: 1.25; }
        .order-item-meta { color: var(--muted); font-size: 13px; margin-top: 2px; }
        .order-details, .order-notes { color: var(--muted); }
        .order-details summary, .order-notes summary { cursor: pointer; color: var(--green-dark); font-weight: 760; }
        .order-details-grid { display: grid; gap: 5px; margin-top: 8px; }
        .order-details-grid strong { color: var(--text); }
        .order-actions { display: grid; grid-template-columns: minmax(160px, .55fr) minmax(260px, 1fr) minmax(190px, .65fr); gap: 10px; align-items: stretch; }
        .order-actions .button { min-height: 58px; width: 100%; font-size: 17px; border-radius: 8px; }
        .order-problem-form { display: grid; grid-template-columns: minmax(120px, 1fr) auto; gap: 8px; }
        .order-problem-form input { min-height: 58px; }
        .courier-panel { margin-top: 2px; }
        .courier-list { display: grid; gap: 10px; padding: 12px; }
        .courier-card { padding: 14px; display: grid; gap: 12px; }
        .courier-card-header { display: flex; justify-content: space-between; align-items: center; gap: 14px; }
        .courier-title { font-size: 18px; font-weight: 850; }
        .courier-meta { color: var(--muted); margin-top: 3px; }
        .courier-card .button { min-height: 52px; min-width: 140px; border-radius: 8px; font-size: 16px; }
        .courier-orders { display: grid; gap: 8px; }
        .courier-order-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; align-items: center; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fffdfb; }
        .order-rollback-form { display: flex; gap: 8px; align-items: center; }
        .order-rollback-form input { min-height: 46px; min-width: 210px; }
        .order-rollback-form .button { min-height: 46px; min-width: 104px; font-size: 15px; }
        .history-toolbar { display: flex; flex-wrap: wrap; align-items: end; gap: 10px; margin-bottom: 14px; }
        .history-toolbar label { display: grid; gap: 5px; font-weight: 780; color: var(--muted); }
        .history-toolbar input { min-height: 46px; }
        .packing-history-order .order-card-header { align-items: center; }
        .history-order-meta { color: var(--muted); margin-top: 5px; }
        .history-order-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 8px; }
        .history-order-actions .order-rollback-form input { min-width: min(280px, 55vw); }
        .problem-panel { margin-top: 2px; }
        .problem-list { display: grid; gap: 10px; padding: 12px; }
        .problem-card { border: 1px solid #f0c3c3; border-radius: 8px; padding: 12px; background: #fffafa; display: grid; gap: 8px; }
        .problem-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
        .problem-reason { color: var(--red); font-weight: 780; }
        @media (max-width: 1100px) {
            .packing-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .order-actions { grid-template-columns: 1fr; }
            .order-problem-form { grid-template-columns: 1fr; }
        }
        @media (max-width: 760px) {
            .packing-home-toolbar { margin-top: -4px; }
            .packing-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .workflow-picker { grid-template-columns: 1fr; }
            .workflow-card { min-height: 118px; padding: 18px; }
            .collect-main { grid-template-columns: 72px minmax(0, 1fr); }
            .collect-card .product-thumb { width: 72px; height: 92px; }
            .qty-pill { grid-column: 1 / -1; width: max-content; }
            .history-card, .courier-card-header, .order-card-header { display: grid; justify-content: stretch; }
            .courier-order-row { grid-template-columns: 1fr; }
            .order-rollback-form { display: grid; grid-template-columns: 1fr auto; }
            .order-rollback-form input { min-width: 0; }
            .order-title { font-size: 28px; }
            .order-item { grid-template-columns: 50px minmax(0, 1fr); }
            .order-item strong { grid-column: 2; }
        }
    </style>
@endpush

@section('content')
    @php
        $qty = fn ($value) => floor((float) $value) === (float) $value
            ? number_format((float) $value, 0, ',', ' ')
            : number_format((float) $value, 4, ',', ' ');
        $money = fn ($value, $currency = 'PLN') => number_format((float) $value, 2, ',', ' ') . ' ' . ($currency ?: 'PLN');
        $person = function (array $data): string {
            $name = trim(implode(' ', array_filter([
                $data['first_name'] ?? null,
                $data['last_name'] ?? null,
            ])));
            $company = trim((string) ($data['company'] ?? ''));

            return trim(implode(' / ', array_filter([$name, $company]))) ?: '-';
        };
        $address = function (array $data): string {
            $street = trim(implode(' ', array_filter([
                $data['address_1'] ?? null,
                $data['address_2'] ?? null,
            ])));
            $city = trim(implode(' ', array_filter([
                $data['postcode'] ?? null,
                $data['city'] ?? null,
            ])));

            return trim(implode(', ', array_filter([
                $street,
                $city,
                $data['country'] ?? null,
            ]))) ?: '-';
        };
        $modeLabels = [
            'manual' => 'Bez skanera',
            'hybrid' => 'Hybrydowy',
            'scanner' => 'Skaner',
        ];
        $historyStatusLabels = [
            'picked' => 'Zebrane',
            'packed' => 'Spakowane',
            'shipped' => 'Wysłane',
        ];
        $waitingCourierOrders = $waitingCourierGroups->sum('orders_count');
    @endphp

    @if ($packingView === 'home')
        <div class="packing-home-toolbar">
            <label class="button secondary" for="packing-settings-drawer">Ustawienia</label>
        </div>

        <input id="packing-settings-drawer" class="drawer-toggle" type="checkbox">
        <label class="drawer-backdrop" for="packing-settings-drawer" aria-label="Zamknij ustawienia"></label>
        <aside class="drawer-panel" aria-label="Ustawienia pakowania">
            <div class="drawer-header">
                <div class="drawer-title">Ustawienia pakowania</div>
                <label class="drawer-close" for="packing-settings-drawer" aria-label="Zamknij">&times;</label>
            </div>
            <div class="settings-body">
                <div class="mode-copy">
                    <strong>Sposób pracy</strong>
                    Bez skanera system sortuje kompletację po lokalizacji magazynowej. Tryb skanera zostaje jako ustawienie procesu, kiedy magazyn będzie gotowy na skanowanie.
                </div>
                <div class="mode-actions" aria-label="Tryb pakowania">
                    @foreach ($modeLabels as $mode => $label)
                        <form method="POST" action="{{ route('packing.mode') }}">
                            @csrf
                            <input type="hidden" name="mode" value="{{ $mode }}">
                            <button @class(['mode-button', 'active' => $packingMode === $mode]) type="submit">{{ $label }}</button>
                        </form>
                    @endforeach
                </div>
            </div>
        </aside>

        <section class="packing-stats" aria-label="Status wysyłki">
            <article class="card packing-stat">
                <span class="muted">Do zebrania</span>
                <strong>{{ $pickGroups->count() }}</strong>
            </article>
            <article class="card packing-stat">
                <span class="muted">Do pakowania</span>
                <strong>{{ $readyOrders->count() }}</strong>
            </article>
            <article class="card packing-stat">
                <span class="muted">Oczekuje na kuriera</span>
                <strong>{{ $waitingCourierOrders }}</strong>
            </article>
            <article class="card packing-stat">
                <span class="muted">Spakowane dzisiaj</span>
                <strong>{{ $packedToday }}</strong>
            </article>
            <article class="card packing-stat">
                <span class="muted">Problemy</span>
                <strong>{{ $problemTasks->count() }}</strong>
            </article>
        </section>

        <section class="workflow-picker" aria-label="Wybierz etap wysyłki">
            <a href="{{ route('packing.index', ['view' => 'collect']) }}" class="workflow-card">
                <span>Etap 1</span>
                <strong>Kompletacja</strong>
                <small>{{ $pickGroups->count() }} grup produktów do zebrania</small>
            </a>
            <a href="{{ route('packing.index', ['view' => 'pack']) }}" class="workflow-card">
                <span>Etap 2</span>
                <strong>Pakowanie</strong>
                <small>{{ $readyOrders->count() }} zamówień do spakowania, {{ $waitingCourierOrders }} czeka na kuriera</small>
            </a>
        </section>
        <div class="packing-home-links">
            <a class="button secondary" href="{{ route('packing.index', ['view' => 'history', 'date' => now()->toDateString()]) }}">Historia pakowania</a>
        </div>
    @endif

    @if ($packingView === 'collect')
        <div class="collection-workspace">
            <div class="queue-list">
                @forelse ($pickGroups as $group)
                    @php
                        $problemFormId = 'problem-group-' . md5(implode('-', $group['task_ids']));
                    @endphp
                    <article class="pick-card collect-card">
                        <div class="collect-main">
                            <div class="product-thumb">
                                @if ($group['image_url'])
                                    <img src="{{ $group['image_url'] }}" alt="{{ $group['product_name'] }}" loading="lazy" referrerpolicy="no-referrer">
                                @else
                                    Brak zdjęcia
                                @endif
                            </div>
                            <div>
                                <div class="pick-name">{{ $group['product_name'] }}</div>
                                <div class="pick-sku">{{ $group['sku'] ?: 'brak SKU' }} · zam. {{ $group['order_numbers'] ?: '-' }}</div>
                                <div class="collect-size">Rozmiar <strong>{{ $group['size_label'] ?: '-' }}</strong></div>
                            </div>
                            <div class="qty-pill">{{ $qty($group['quantity']) }} szt.</div>
                        </div>
                        <div class="pick-badges">
                            <span class="pick-badge">Lok. {{ $group['location'] ?: '-' }}</span>
                            <span class="pick-badge">{{ $group['courier'] }}</span>
                            <span class="pick-badge">{{ $group['orders_count'] }} zam.</span>
                            <span class="pick-badge">Najstarsze: {{ $group['oldest_order_at']?->format('Y-m-d') ?? '-' }}</span>
                        </div>
                        <label class="collect-note">
                            Notatka problemu
                            <input form="{{ $problemFormId }}" name="reason" placeholder="Np. brak na półce, uszkodzone, niezgodny rozmiar">
                        </label>
                        <div class="collect-actions">
                            <form id="{{ $problemFormId }}" method="POST" action="{{ route('packing.groups.problem') }}">
                                @csrf
                                @foreach ($group['task_ids'] as $taskId)
                                    <input type="hidden" name="task_ids[]" value="{{ $taskId }}">
                                @endforeach
                                <button class="button danger" type="submit">Problem</button>
                            </form>
                            <form method="POST" action="{{ route('packing.groups.pick') }}">
                                @csrf
                                @foreach ($group['task_ids'] as $taskId)
                                    <input type="hidden" name="task_ids[]" value="{{ $taskId }}">
                                @endforeach
                                <button class="button" type="submit">Zebrane</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="packing-empty">Brak produktów do zebrania. Nowe opłacone zamówienia pojawią się tutaj automatycznie.</div>
                @endforelse
            </div>

            <section class="card history-panel">
                <div class="panel-header">
                    <span>Historia kompletacji</span>
                    <span>{{ $recentPickedTasks->count() }} ostatnich pozycji</span>
                </div>
                <div class="history-list">
                    @forelse ($recentPickedTasks as $task)
                        <article class="history-card">
                            <div>
                                <strong>{{ $task->product_name }}</strong><br>
                                <span class="muted">{{ $task->sku ?: 'brak SKU' }} · rozmiar {{ $task->size_label ?: '-' }} · zam. {{ $task->order_number }}</span>
                            </div>
                            <div class="history-badges">
                                <span @class(['status', 'blue' => $task->status === 'picked', 'orange' => $task->status === 'packed'])>{{ $historyStatusLabels[$task->status] ?? $task->status }}</span>
                                <span class="pick-badge">{{ $task->picked_at?->format('Y-m-d H:i') ?? '-' }}</span>
                            </div>
                        </article>
                    @empty
                        <div class="packing-empty">Nie ma jeszcze historii kompletacji.</div>
                    @endforelse
                </div>
            </section>
        </div>
    @endif

    @if ($packingView === 'pack')
        <div class="pack-workspace">
            <section class="queue-list" aria-label="Lista do pakowania">
                @forelse ($readyOrders as $order)
                    @php
                        $tasksForOrder = $order->packingTasks;
                        $firstTask = $tasksForOrder->first();
                        $shippingLabel = $order->shippingLabels?->firstWhere('status', 'generated');
                        $customerNote = trim((string) data_get($firstTask?->metadata, 'customer_note', ''));
                        $orderNotes = collect(data_get($firstTask?->metadata, 'order_notes', []))
                            ->pluck('note')
                            ->filter()
                            ->implode(' | ');
                        $notes = trim(implode(' | ', array_filter([$customerNote, $orderNotes])));
                        $shipping = (array) data_get($firstTask?->metadata, 'shipping', []);
                        $billing = (array) data_get($firstTask?->metadata, 'billing', []);
                        $phone = data_get($shipping, 'phone') ?: data_get($billing, 'phone') ?: '-';
                        $email = data_get($billing, 'email') ?: '-';
                        $payment = data_get($firstTask?->metadata, 'payment_method') ?: '-';
                    @endphp
                    <article class="order-card">
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">Zamówienie {{ $order->external_number }}</div>
                                <div class="order-meta">{{ $order->salesChannel?->code ?? '-' }} · {{ $firstTask?->customer_name ?: '-' }}</div>
                            </div>
                            <div class="order-badges">
                                <span class="status blue">{{ $firstTask?->courier ?: 'Kurier' }}</span>
                                <span class="status">{{ $tasksForOrder->count() }} poz.</span>
                            </div>
                        </div>

                        <div class="order-items">
                            @foreach ($tasksForOrder as $task)
                                @php
                                    $taskLocation = data_get($task->metadata, 'warehouse_location')
                                        ?: data_get($task->product?->attributes, 'master.stock.location')
                                        ?: data_get($task->product?->attributes, 'warehouse_location')
                                        ?: '-';
                                @endphp
                                <div class="order-item">
                                    <div class="product-thumb">
                                        @if ($task->product?->imageUrl())
                                            <img src="{{ $task->product->imageUrl() }}" alt="{{ $task->product_name }}" loading="lazy" referrerpolicy="no-referrer">
                                        @else
                                            Foto
                                        @endif
                                    </div>
                                    <div>
                                        <div class="order-item-name">{{ $task->product_name }}</div>
                                        <div class="order-item-meta">{{ $task->sku ?: 'brak SKU' }} · rozmiar {{ $task->size_label ?: '-' }} · lok. {{ $taskLocation }}</div>
                                    </div>
                                    <strong>{{ $qty($task->quantity_required) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>

                        @if ($notes !== '')
                            <details class="order-notes">
                                <summary>Uwagi z WooCommerce</summary>
                                <div>{{ $notes }}</div>
                            </details>
                        @endif

                        <details class="order-details">
                            <summary>Dane wysyłki i płatności</summary>
                            <div class="order-details-grid">
                                <div><strong>Status Woo:</strong> {{ $order->status ?? '-' }}</div>
                                <div><strong>Wartość:</strong> {{ $money($order->total_gross ?? 0, $order->currency ?? 'PLN') }}</div>
                                <div><strong>Płatność:</strong> {{ $payment }}</div>
                                <div><strong>Kontakt:</strong> {{ $email }} · {{ $phone }}</div>
                                <div><strong>Wysyłka:</strong> {{ $person($shipping) }} · {{ $address($shipping) }}</div>
                                <div><strong>Billing:</strong> {{ $person($billing) }} · {{ $address($billing) }}</div>
                            </div>
                        </details>

                        <div class="order-actions">
                            @if ($shippingLabel)
                                <a class="button secondary" href="{{ route('packing.labels.download', $shippingLabel) }}">Pobierz etykietę</a>
                            @else
                                <span class="button secondary" aria-disabled="true">Etykieta automatycznie</span>
                            @endif
                            <form class="order-problem-form" method="POST" action="{{ route('packing.orders.problem', $order) }}">
                                @csrf
                                <input name="reason" placeholder="Notatka problemu">
                                <button class="button danger" type="submit">Problem</button>
                            </form>
                            <form method="POST" action="{{ route('packing.orders.pack', $order) }}">
                                @csrf
                                <button class="button" type="submit">Spakuj</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <div class="packing-empty">Brak zamówień gotowych do pakowania. Po kompletacji zamówienia pojawią się tutaj automatycznie.</div>
                @endforelse
            </section>

            <section class="card courier-panel">
                <div class="panel-header">
                    <span>Oczekuje na kuriera</span>
                    <span>{{ $waitingCourierOrders }} paczek</span>
                </div>
                <div class="courier-list">
                    @forelse ($waitingCourierGroups as $group)
                        @php
                            $oldestPacked = $group['oldest_packed_at'] ? \Illuminate\Support\Carbon::parse($group['oldest_packed_at']) : null;
                        @endphp
                        <article class="courier-card">
                            <div class="courier-card-header">
                                <div>
                                    <div class="courier-title">{{ $group['courier'] }}</div>
                                    <div class="courier-meta">
                                        {{ $group['orders_count'] }} paczek, {{ $group['tasks_count'] }} pozycji
                                        @if ($oldestPacked)
                                            · najstarsze {{ $oldestPacked->format('Y-m-d H:i') }}
                                        @endif
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('packing.couriers.pickup') }}">
                                    @csrf
                                    <input type="hidden" name="courier" value="{{ $group['courier'] }}">
                                    <button class="button" type="submit">Odebrano</button>
                                </form>
                            </div>
                            <div class="courier-orders">
                                @foreach ($group['orders'] as $queuedOrder)
                                    @php
                                        $queuedPackedAt = $queuedOrder['packed_at'] ? \Illuminate\Support\Carbon::parse($queuedOrder['packed_at']) : null;
                                    @endphp
                                    <div class="courier-order-row">
                                        <div>
                                            <strong>Zamówienie {{ $queuedOrder['external_number'] }}</strong><br>
                                            <span class="muted">
                                                {{ $queuedOrder['customer_name'] }} · {{ $queuedOrder['tasks_count'] }} poz.
                                                @if ($queuedPackedAt)
                                                    · spakowane {{ $queuedPackedAt->format('Y-m-d H:i') }}
                                                @endif
                                            </span>
                                        </div>
                                        <form class="order-rollback-form" method="POST" action="{{ route('packing.orders.unpack', $queuedOrder['id']) }}">
                                            @csrf
                                            <input name="reason" placeholder="Powód cofnięcia">
                                            <button class="button secondary" type="submit">Cofnij</button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @empty
                        <div class="packing-empty">Nie ma paczek oczekujących na kuriera.</div>
                    @endforelse
                </div>
            </section>

            @if ($problemTasks->isNotEmpty())
                <section class="card problem-panel">
                    <div class="panel-header">
                        <span>Do wyjaśnienia</span>
                        <span>{{ $problemTasks->count() }} pozycji</span>
                    </div>
                    <div class="problem-list">
                        @foreach ($problemTasks as $task)
                            @php
                                $problemReason = data_get($task->metadata, 'packing_problem.reason', 'Do wyjaśnienia');
                                $problemAt = data_get($task->metadata, 'packing_problem.reported_at');
                                $problemLocation = data_get($task->metadata, 'warehouse_location')
                                    ?: data_get($task->product?->attributes, 'master.stock.location')
                                    ?: data_get($task->product?->attributes, 'warehouse_location')
                                    ?: '-';
                            @endphp
                            <article class="problem-card">
                                <div class="problem-card-header">
                                    <div>
                                        <strong>{{ $task->product_name }}</strong><br>
                                        <span class="muted">{{ $task->sku ?: 'brak SKU' }} · lok. {{ $problemLocation }} · zam. {{ $task->order_number }} · {{ $task->courier ?: 'Kurier' }}</span>
                                    </div>
                                    <span class="status red">Problem</span>
                                </div>
                                <div class="problem-reason">{{ $problemReason }}</div>
                                <div class="muted">Zgłoszono: {{ $problemAt ? \Illuminate\Support\Carbon::parse($problemAt)->format('Y-m-d H:i') : $task->updated_at?->format('Y-m-d H:i') }}</div>
                                <form method="POST" action="{{ route('packing.tasks.reopen', $task) }}">
                                    @csrf
                                    <button class="button secondary" type="submit">Przywróć do kolejki</button>
                                </form>
                            </article>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>
    @endif

    @if ($packingView === 'history')
        <div class="pack-workspace">
            <form class="history-toolbar" method="GET" action="{{ route('packing.index') }}">
                <input type="hidden" name="view" value="history">
                <label>
                    Data pakowania
                    <input type="date" name="date" value="{{ $packingHistoryDate }}">
                </label>
                <button class="button secondary" type="submit">Pokaż historię</button>
            </form>

            <section class="queue-list" aria-label="Historia pakowania według daty">
                @forelse ($packingHistoryOrders as $historyOrder)
                    <article class="order-card packing-history-order">
                        <div class="order-card-header">
                            <div>
                                <div class="order-title">Zamówienie {{ $historyOrder['order_number'] }}</div>
                                <div class="history-order-meta">
                                    {{ $historyOrder['sales_channel'] }} · {{ $historyOrder['customer_name'] }} · {{ $historyOrder['courier'] }} · {{ $historyOrder['tasks_count'] }} poz.
                                </div>
                            </div>
                            <div class="order-badges">
                                <span @class(['status', 'orange' => $historyOrder['status'] === 'packed', 'green' => $historyOrder['status'] === 'shipped'])>{{ $historyStatusLabels[$historyOrder['status']] ?? $historyOrder['status'] }}</span>
                                <span class="status">{{ $historyOrder['packed_at']?->format('H:i') ?? '-' }}</span>
                            </div>
                        </div>

                        <div class="history-order-meta">
                            Spakowane: {{ $historyOrder['packed_at']?->format('Y-m-d H:i') ?? '-' }}
                            @if ($historyOrder['pickup_at'])
                                · odebrane przez kuriera: {{ $historyOrder['pickup_at']->format('Y-m-d H:i') }}
                            @endif
                        </div>

                        <div class="order-items">
                            @foreach ($historyOrder['items'] as $item)
                                <div class="order-item">
                                    <div class="product-thumb">
                                        @if ($item['image_url'])
                                            <img src="{{ $item['image_url'] }}" alt="{{ $item['name'] }}" loading="lazy" referrerpolicy="no-referrer">
                                        @else
                                            Foto
                                        @endif
                                    </div>
                                    <div>
                                        <div class="order-item-name">{{ $item['name'] }}</div>
                                        <div class="order-item-meta">{{ $item['sku'] ?: 'brak SKU' }} · rozmiar {{ $item['size_label'] ?: '-' }}</div>
                                    </div>
                                    <strong>{{ $qty($item['quantity']) }} szt.</strong>
                                </div>
                            @endforeach
                        </div>

                        @if ($historyOrder['status'] === 'packed' && $historyOrder['order_id'])
                            <div class="history-order-actions">
                                <form class="order-rollback-form" method="POST" action="{{ route('packing.orders.unpack', $historyOrder['order_id']) }}">
                                    @csrf
                                    <input name="reason" placeholder="Powód cofnięcia">
                                    <button class="button secondary" type="submit">Cofnij pakowanie</button>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="packing-empty">Brak historii pakowania dla wybranej daty.</div>
                @endforelse
            </section>
        </div>
    @endif
@endsection
