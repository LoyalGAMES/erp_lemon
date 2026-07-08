@extends('layouts.app', ['title' => $title, 'subtitle' => $subtitle, 'module' => $module])

@php
    $qty = fn ($value): string => number_format((float) $value, floor((float) $value) === (float) $value ? 0 : 2, ',', ' ');
    $money = fn ($value, ?string $currency = null): string => number_format((float) $value, 2, ',', ' ') . ($currency ? ' ' . $currency : '');
    $person = function (?array $data): string {
        $data ??= [];

        return trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
            $data['company'] ?? null,
        ]))) ?: '-';
    };
    $address = function (?array $data): string {
        $data ??= [];
        $parts = array_filter([
            $data['address_1'] ?? null,
            $data['address_2'] ?? null,
            trim(implode(' ', array_filter([
                $data['postcode'] ?? null,
                $data['city'] ?? null,
            ]))),
            $data['country'] ?? null,
        ]);

        return implode(', ', $parts) ?: '-';
    };
@endphp

@push('styles')
    <style>
        .order-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
        .order-summary { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
        .order-summary-card { padding: 14px 16px; }
        .order-summary-card span { display: block; color: var(--muted); font-size: 12px; font-weight: 720; margin-bottom: 4px; }
        .order-summary-card strong { display: block; font-size: 20px; line-height: 1.15; }
        .order-grid { display: grid; grid-template-columns: minmax(320px, .9fr) minmax(0, 1.4fr); gap: 16px; margin-bottom: 16px; }
        .order-section { margin-bottom: 16px; }
        .order-section-body { padding: 16px; }
        .order-label-form { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .order-label-form select { min-height: 44px; min-width: 260px; }
        .address-grid { display: grid; gap: 14px; }
        .address-box { border: 1px solid var(--border); border-radius: 8px; padding: 13px; }
        .address-box strong { display: block; margin-bottom: 6px; }
        .note-list { display: grid; gap: 10px; }
        .note-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .note-card-header { display: flex; justify-content: space-between; gap: 10px; color: var(--muted); font-size: 12px; margin-bottom: 6px; }
        .customer-message-form { display: grid; gap: 10px; margin-bottom: 16px; }
        .customer-message-form-actions { display: flex; justify-content: flex-end; }
        .customer-message-history { display: grid; gap: 10px; }
        .customer-message-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .customer-message-card header { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 7px; }
        .customer-message-card strong { display: block; }
        .customer-message-meta { color: var(--muted); font-size: 12px; }
        .customer-message-preview { margin-top: 7px; white-space: pre-wrap; }
        .compact-form { display: grid; gap: 10px; }
        .compact-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .payment-balance { font-size: 22px; font-weight: 780; }
        .payment-history { display: grid; gap: 9px; margin-top: 14px; }
        .payment-row { border-top: 1px solid var(--border); padding-top: 9px; display: flex; justify-content: space-between; gap: 12px; }
        .payment-row strong { display: block; }
        .wrap-cell { white-space: normal; min-width: 220px; }
        .split-line-grid { display: grid; grid-template-columns: minmax(0, 1fr) 120px 160px; gap: 10px; align-items: end; padding: 10px 0; border-bottom: 1px solid var(--border); }
        .split-line-grid:last-of-type { border-bottom: 0; }
        .split-line-grid label { margin: 0; }
        @media (max-width: 1000px) {
            .order-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .order-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) {
            .order-summary { grid-template-columns: 1fr; }
            .split-line-grid { grid-template-columns: 1fr; }
            .compact-form-grid { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@section('content')
    <section class="order-toolbar">
        <a class="button secondary" href="{{ route('modules.show', 'orders') }}">Wróć do zamówień</a>
        @include('partials.order-actions', [
            'order' => $order,
            'wzDocument' => $latestWz,
            'invoice' => $latestInvoice,
            'activeReservations' => $activeReservations,
            'showDetailsLink' => false,
        ])
    </section>

    <section class="order-summary" aria-label="Podsumowanie zamówienia">
        <article class="card order-summary-card">
            <span>Kanał</span>
            <strong>{{ $order->salesChannel?->code ?? '-' }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Status WooCommerce</span>
            <strong>{{ $order->status }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Kwota brutto</span>
            <strong>{{ $money($order->total_gross, $order->currency) }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Aktywne rezerwacje</span>
            <strong>{{ $qty($activeReservations) }}</strong>
        </article>
        <article class="card order-summary-card">
            <span>Oczekuje na towar</span>
            <strong>{{ $qty($waitingReservations) }}</strong>
        </article>
    </section>

    <section class="order-grid">
        <article class="card order-section">
            <div class="panel-header">Dane klienta i dostawy</div>
            <div class="order-section-body address-grid">
                <div class="address-box">
                    <strong>Rozliczenie</strong>
                    <div>{{ $person($order->billing_data) }}</div>
                    <div class="muted">{{ $address($order->billing_data) }}</div>
                    <div class="muted">{{ data_get($order->billing_data, 'email', '-') }} · {{ data_get($order->billing_data, 'phone', '-') }}</div>
                </div>
                <div class="address-box">
                    <strong>Wysyłka</strong>
                    <div>{{ $person($order->shipping_data) }}</div>
                    <div class="muted">{{ $address($order->shipping_data) }}</div>
                    <div class="muted">Metoda: {{ data_get($order->raw_payload, 'shipping_lines.0.method_title', '-') }}</div>
                </div>
                <div class="address-box">
                    <strong>Płatność i daty</strong>
                    <div>{{ data_get($order->raw_payload, 'payment_method_title', '-') }}</div>
                    <div class="muted">Utworzone: {{ $order->external_created_at?->format('Y-m-d H:i') ?? '-' }}</div>
                    <div class="muted">Aktualizacja Woo: {{ $order->external_updated_at?->format('Y-m-d H:i') ?? '-' }}</div>
                </div>
            </div>
        </article>

        <article class="card order-section">
            <div class="panel-header">
                <span>Pozycje zamówienia</span>
                <span>{{ $order->lines->count() }} pozycji</span>
            </div>
            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Nazwa</th>
                            <th>Ilość</th>
                            <th>Cena netto</th>
                            <th>Cena brutto</th>
                            <th>Produkt ERP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->lines as $line)
                            <tr>
                                <td>{{ $line->sku ?? '-' }}</td>
                                <td class="wrap-cell">{{ $line->name }}</td>
                                <td>{{ $qty($line->quantity) }}</td>
                                <td>{{ $line->unit_net_price !== null ? $money($line->unit_net_price, $order->currency) : '-' }}</td>
                                <td>{{ $line->unit_gross_price !== null ? $money($line->unit_gross_price, $order->currency) : '-' }}</td>
                                <td>
                                    @if ($line->product)
                                        <a class="status" href="{{ route('products.show', $line->product) }}">{{ $line->product->sku }}</a>
                                    @else
                                        <span class="status orange">Brak mapowania</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">Brak pozycji zamówienia.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <article class="card order-section">
        <div class="panel-header">
            <span>Rezerwacje magazynowe</span>
            <span>{{ $reservations->count() }} rekordów</span>
        </div>
        <div class="table-scroll">
            <table class="dense-table">
                <thead>
                    <tr>
                        <th>Magazyn</th>
                        <th>SKU</th>
                        <th>Ilość</th>
                        <th>Status</th>
                        <th>Rezerwacja</th>
                        <th>Zwolnienie</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reservations as $reservation)
                        <tr>
                            <td>{{ $reservation->warehouse?->code ?? '-' }}</td>
                            <td>{{ $reservation->product?->sku ?? '-' }}</td>
                            <td>{{ $qty($reservation->quantity) }}</td>
                            <td><span @class(['status', 'blue' => $reservation->status === 'waiting', 'orange' => $reservation->status === 'released'])>{{ $reservation->status }}</span></td>
                            <td>{{ $reservation->reserved_at?->format('Y-m-d H:i') ?? '-' }}</td>
                            <td>{{ $reservation->released_at?->format('Y-m-d H:i') ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6">Brak rezerwacji dla zamówienia.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>

    @if (count($orderSegments) > 1)
        <article class="card order-section shipping-decision-card">
            <div class="panel-header">
                <span>Wysyłka częściowa — obuwie i odzież</span>
                <span>To zamówienie łączy obuwie z odzieżą</span>
            </div>
            <div class="order-section-body">
                @if (is_array($shippingDecision))
                    <p class="shipping-decision-current">
                        Decyzja: <strong>{{ ($shippingDecision['decision'] ?? '') === 'ship_footwear_now' ? 'Wyślij buty od razu' : 'Czekaj na resztę zamówienia' }}</strong>
                        <span class="muted">
                            ({{ $shippingDecision['decided_by'] ?? 'ERP' }}, {{ \Illuminate\Support\Carbon::parse($shippingDecision['decided_at'] ?? now())->format('Y-m-d H:i') }})
                        </span>
                    </p>
                @else
                    <p class="muted" style="margin-top: 0;">Zdecyduj, czy buty mają jechać do klienta osobno, nie czekając na skompletowanie odzieży. Wydzielone buty trafią do osobnego zamówienia z własną kompletacją, pakowaniem i etykietą.</p>
                @endif
                <div class="inline-actions">
                    <form method="POST" action="{{ route('orders.shipping-decision', $order) }}" onsubmit="return confirm('Wydzielić buty do osobnego zamówienia i wysłać od razu?');">
                        @csrf
                        <input type="hidden" name="decision" value="ship_footwear_now">
                        <button class="button" type="submit">Wyślij buty od razu</button>
                    </form>
                    <form method="POST" action="{{ route('orders.shipping-decision', $order) }}">
                        @csrf
                        <input type="hidden" name="decision" value="wait_for_all">
                        <button class="button secondary" type="submit">Czekaj na resztę zamówienia</button>
                    </form>
                </div>
            </div>
        </article>
    @endif

    <article class="card order-section">
        <div class="panel-header">
            <span>Podziel zamówienie</span>
            <span>Wydziel brakujące pozycje do osobnego zamówienia ERP</span>
        </div>
        <form class="order-section-body" method="POST" action="{{ route('orders.split', $order) }}">
            @csrf
            <p class="muted" style="margin-top: 0;">Wpisz ilość, którą chcesz wysłać później. Nowe zamówienie dostanie własne rezerwacje, więc po pojawieniu się stanu na magazynie będzie traktowane jako oczekujące do realizacji.</p>
            @foreach ($order->lines as $line)
                <div class="split-line-grid">
                    <div>
                        <strong>{{ $line->name }}</strong><br>
                        <span class="muted">{{ $line->sku ?? '-' }} · dostępne w zamówieniu: {{ $qty($line->quantity) }}</span>
                    </div>
                    <label>Ilość później
                        <input name="split_lines[{{ $line->id }}][quantity]" type="number" min="0" max="{{ (float) $line->quantity }}" step="1" value="0">
                    </label>
                    <span class="status">{{ $line->product ? 'Produkt ERP' : 'Brak mapowania' }}</span>
                </div>
            @endforeach
            <label style="margin-top: 12px;">Notatka do splitu
                <textarea name="note" rows="2" placeholder="np. buty zejdą z produkcji później"></textarea>
            </label>
            <div class="inline-actions" style="margin-top: 12px;">
                <button class="button" type="submit">Utwórz zamówienie częściowe</button>
            </div>
        </form>
    </article>

    <section class="order-grid">
        <article class="card order-section">
            <div class="panel-header">
                <span>Dokumenty WZ</span>
                <span>{{ $wzDocuments->count() }} rekordów</span>
            </div>
            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>Numer</th>
                            <th>Status</th>
                            <th>Magazyn</th>
                            <th>Pozycje</th>
                            <th>Data</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($wzDocuments as $document)
                            <tr>
                                <td><a class="status" href="{{ route('documents.show', $document) }}">{{ $document->number }}</a></td>
                                <td><span class="status {{ $document->status === 'draft' ? 'blue' : '' }}">{{ $document->status }}</span></td>
                                <td>{{ $document->sourceWarehouse?->code ?? '-' }}</td>
                                <td>{{ $document->lines->map(fn ($line) => ($line->product?->sku ?? '-') . ' x ' . $qty($line->quantity))->implode(', ') }}</td>
                                <td>{{ $document->document_date?->format('Y-m-d H:i') ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Brak dokumentów WZ dla tego zamówienia.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="card order-section">
            <div class="panel-header">
                <span>Faktury</span>
                <span>{{ $order->invoices->count() }} rekordów</span>
            </div>
            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>Numer</th>
                            <th>Status</th>
                            <th>Brutto</th>
                            <th>WooCommerce</th>
                            <th>KSeF</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->invoices->sortByDesc('id') as $invoice)
                            <tr>
                                <td><a class="status" href="{{ route('invoices.edit', $invoice) }}">{{ $invoice->number }}</a></td>
                                <td>{{ $invoice->status }}</td>
                                <td>{{ $money($invoice->gross_total, $invoice->currency) }}</td>
                                <td>{{ data_get($invoice->metadata, 'woocommerce_upload.status') === 'success' ? 'Wysłana' : '-' }}</td>
                                <td>{{ $invoice->ksef_number ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Brak faktury dla tego zamówienia.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <section class="order-grid">
        <article class="card order-section">
            <div class="panel-header">
                <span>Pakowanie i etykiety</span>
                <span>{{ $order->packingTasks->count() }} zadań</span>
            </div>
            <div class="table-scroll">
                <table class="dense-table">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Status</th>
                            <th>Kurier</th>
                            <th>Rozmiar</th>
                            <th>Zebrano</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($order->packingTasks as $task)
                            <tr>
                                <td>{{ $task->sku ?? '-' }}</td>
                                <td><span class="status {{ $task->status === 'picked' ? '' : 'orange' }}">{{ $task->status }}</span></td>
                                <td>{{ $task->courier ?: '-' }}</td>
                                <td>{{ $task->size_label ?: '-' }}</td>
                                <td>{{ $qty($task->quantity_picked) }} / {{ $qty($task->quantity_required) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5">Brak zadań pakowania dla tego zamówienia.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="order-section-body">
                @forelse ($order->shippingLabels as $label)
                    <a class="button secondary" href="{{ route('packing.labels.download', $label) }}">Pobierz etykietę {{ $label->label_number ?: $label->id }}</a>
                @empty
                    <span class="muted">Brak wygenerowanej etykiety kurierskiej.</span>
                @endforelse

                @if ($order->shippingLabels->where('status', 'generated')->isEmpty())
                    <form class="order-label-form" method="POST" action="{{ route('orders.label.generate', $order) }}">
                        @csrf
                        <select name="courier_account_id" aria-label="Konto nadawcze">
                            <option value="">Etykieta ze sklepu (WooCommerce)</option>
                            @foreach ($courierAccounts as $courierAccount)
                                <option value="{{ $courierAccount->id }}" @selected($courierAccount->is_default && $courierAccount->provider === 'inpost')>{{ $courierAccount->provider === 'blpaczka' ? 'BLPaczka' : 'InPost' }}: {{ $courierAccount->name }}</option>
                            @endforeach
                        </select>
                        <button class="button" type="submit">Generuj przesyłkę</button>
                    </form>
                @endif
            </div>
        </article>

        <article class="card order-section">
            <div class="panel-header">
                <span>Komunikacja z klientem</span>
                <span>{{ $order->customerMessages->count() }} wiadomości</span>
            </div>
            <div class="order-section-body">
                @php
                    $orderEmailTemplates = $emailTemplates ?? collect();
                    $orderTemplateContext = [
                        'order_number' => $order->external_number ?: $order->external_id,
                        'customer_name' => $person($order->billing_data),
                        'customer_email' => data_get($order->billing_data, 'email', ''),
                        'from_name' => config('mail.from.name', config('app.name', 'Sempre ERP')),
                    ];
                @endphp
                <form class="customer-message-form" method="POST" action="{{ route('orders.message.send', $order) }}" data-email-template-form data-email-template-context="{{ e(json_encode($orderTemplateContext, JSON_UNESCAPED_UNICODE)) }}">
                    @csrf
                    @if ($orderEmailTemplates->isNotEmpty())
                        <label>Szablon
                            <select data-email-template-select>
                                <option value="">Własna wiadomość</option>
                                @foreach ($orderEmailTemplates as $template)
                                    <option value="{{ $template->id }}" data-subject="{{ $template->subject }}" data-body="{{ $template->body }}">{{ $template->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endif
                    <label>Temat
                        <input name="subject" maxlength="160" value="{{ old('subject') }}" placeholder="Np. Informacja o zamówieniu {{ $order->external_number }}" required>
                    </label>
                    <label>Wiadomość
                        <textarea name="body" rows="5" maxlength="5000" required>{{ old('body') }}</textarea>
                    </label>
                    <div class="customer-message-form-actions">
                        <button class="button" type="submit">Wyślij maila</button>
                    </div>
                </form>

                <div class="customer-message-history">
                    @forelse ($order->customerMessages->take(8) as $message)
                        <article class="customer-message-card">
                            <header>
                                <div>
                                    <strong>{{ $message->renderedSubject() }}</strong>
                                    <span class="customer-message-meta">
                                        {{ $message->recipient_email }} · {{ $message->type === 'automated' ? 'automat' : 'ręcznie' }}{{ $message->trigger ? ' · '.$message->trigger : '' }}
                                    </span>
                                </div>
                                <span @class(['status', 'blue' => $message->status === 'pending', 'red' => $message->status === 'failed', 'orange' => $message->status === 'skipped'])>{{ $message->status }}</span>
                            </header>
                            <div class="customer-message-meta">
                                {{ $message->sent_at?->format('Y-m-d H:i') ?? $message->failed_at?->format('Y-m-d H:i') ?? $message->created_at?->format('Y-m-d H:i') }}
                                @if ($message->error_message)
                                    · {{ $message->error_message }}
                                @endif
                            </div>
                            <div class="customer-message-preview">{{ \Illuminate\Support\Str::limit($message->renderedBody(), 220) }}</div>
                        </article>
                    @empty
                        <span class="muted">Brak wiadomości wysłanych do klienta tego zamówienia.</span>
                    @endforelse
                </div>
            </div>
        </article>
    </section>

    <section class="order-grid">
        <article class="card order-section">
            <div class="panel-header">
                <span>Rozliczenia klienta</span>
                @php
                    $incomingPayments = (float) $order->customerPayments->where('direction', 'incoming')->sum(fn ($payment) => (float) $payment->amount);
                    $outgoingPayments = (float) $order->customerPayments->where('direction', 'outgoing')->sum(fn ($payment) => (float) $payment->amount);
                    $paymentBalance = $incomingPayments - $outgoingPayments;
                @endphp
                <span>{{ $money($paymentBalance, $order->currency) }}</span>
            </div>
            <div class="order-section-body">
                <div class="payment-balance">{{ $money($paymentBalance, $order->currency) }}</div>
                <div class="muted">Suma ręcznie zaksięgowanych dopłat pomniejszona o wypłaty/refundy zarejestrowane w ERP.</div>

                <form class="compact-form" method="POST" action="{{ route('orders.payments.store', $order) }}" style="margin-top: 14px;">
                    @csrf
                    <div class="compact-form-grid">
                        <label>Kwota
                            <input name="amount" type="number" min="0.01" step="0.01" required>
                        </label>
                        <label>Metoda
                            <select name="method" required>
                                <option value="blik">BLIK</option>
                                <option value="bank_transfer">Przelew</option>
                                <option value="cash">Gotówka</option>
                                <option value="card">Karta</option>
                                <option value="payu">PayU</option>
                                <option value="other">Inna</option>
                            </select>
                        </label>
                        <label>Data księgowania
                            <input name="booked_at" type="datetime-local">
                        </label>
                        <label>Referencja
                            <input name="reference" maxlength="160" placeholder="np. BLIK, ID transakcji">
                        </label>
                    </div>
                    <label>Opis
                        <textarea name="description" rows="2" maxlength="1000" placeholder="np. dopłata BLIK za przesyłkę wymienną"></textarea>
                    </label>
                    <button class="button secondary" type="submit">Zaksięguj wpłatę</button>
                </form>

                <div class="payment-history">
                    @forelse ($order->customerPayments->take(8) as $payment)
                        <div class="payment-row">
                            <div>
                                <strong>{{ $payment->direction === 'outgoing' ? '-' : '+' }}{{ $money($payment->amount, $payment->currency) }}</strong>
                                <span class="muted">{{ $payment->method }} · {{ $payment->reference ?: 'bez referencji' }}</span>
                                @if ($payment->description)
                                    <div class="muted">{{ $payment->description }}</div>
                                @endif
                            </div>
                            <span @class(['status', 'blue' => $payment->status === 'pending', 'red' => $payment->status === 'failed'])>{{ $payment->status }}</span>
                        </div>
                    @empty
                        <span class="muted">Brak ręcznych księgowań dla tego zamówienia.</span>
                    @endforelse
                </div>
            </div>
        </article>

        <article class="card order-section">
            <div class="panel-header">
                <span>Notatki wewnętrzne ERP</span>
                <span>{{ $order->internalNotes->count() }} wpisów</span>
            </div>
            <div class="order-section-body note-list">
                <form class="compact-form" method="POST" action="{{ route('orders.notes.store', $order) }}">
                    @csrf
                    <label>Nowa notatka
                        <textarea name="body" rows="4" maxlength="3000" required></textarea>
                    </label>
                    <button class="button secondary" type="submit">Dodaj notatkę</button>
                </form>
                @forelse ($order->internalNotes->take(8) as $note)
                    <div class="note-card">
                        <div class="note-card-header">
                            <span>{{ $note->author_name ?: 'ERP' }}</span>
                            <span>{{ $note->created_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div>{{ $note->body }}</div>
                    </div>
                @empty
                    <span class="muted">Brak notatek wewnętrznych ERP.</span>
                @endforelse
            </div>
        </article>
    </section>

    <section class="order-grid">
        <article class="card order-section">
            <div class="panel-header">
                <span>Notatki WooCommerce</span>
                <span>{{ $orderNotes->count() }} rekordów</span>
            </div>
            <div class="order-section-body note-list">
                @forelse ($orderNotes as $note)
                    @php
                        $noteText = trim(strip_tags((string) data_get($note, 'note', data_get($note, 'content', ''))));
                    @endphp
                    <div class="note-card">
                        <div class="note-card-header">
                            <span>{{ data_get($note, 'date_created', data_get($note, 'date_created_gmt', '-')) }}</span>
                            <span>{{ data_get($note, 'customer_note') ? 'Notatka klienta' : 'Notatka wewnętrzna' }}</span>
                        </div>
                        <div>{{ $noteText !== '' ? $noteText : '-' }}</div>
                    </div>
                @empty
                    <span class="muted">Brak zaimportowanych notatek z WooCommerce.</span>
                @endforelse
            </div>
        </article>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const renderTemplate = (value, context) => String(value || '').replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (match, key) => {
                return Object.prototype.hasOwnProperty.call(context, key) ? String(context[key] ?? '') : match;
            });

            document.querySelectorAll('[data-email-template-select]').forEach((select) => {
                select.addEventListener('change', () => {
                    const form = select.closest('[data-email-template-form]');

                    if (!form) {
                        return;
                    }

                    let context = {};
                    try {
                        context = JSON.parse(form.dataset.emailTemplateContext || '{}');
                    } catch (error) {
                        context = {};
                    }

                    const option = select.selectedOptions[0];
                    const subject = form.querySelector('input[name="subject"]');
                    const body = form.querySelector('textarea[name="body"]');

                    if (!option || option.value === '') {
                        return;
                    }

                    if (subject) {
                        subject.value = renderTemplate(option.dataset.subject, context);
                    }
                    if (body) {
                        body.value = renderTemplate(option.dataset.body, context);
                    }
                });
            });
        })();
    </script>
@endpush
