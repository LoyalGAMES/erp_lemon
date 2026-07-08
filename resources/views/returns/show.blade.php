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
    $statusLabels = [
        'pending' => ['label' => 'Oczekujący', 'class' => 'orange'],
        'opened' => ['label' => 'Otwarty', 'class' => 'blue'],
        'document_created' => ['label' => 'Dokument RX', 'class' => 'blue'],
        'completed' => ['label' => 'Zrealizowany', 'class' => ''],
        'corrected' => ['label' => 'Korekta', 'class' => ''],
        'rejected' => ['label' => 'Odrzucony', 'class' => 'red'],
        'cancelled' => ['label' => 'Anulowany', 'class' => 'red'],
    ];
    $statusMeta = $statusLabels[$returnCase->status] ?? ['label' => $returnCase->status, 'class' => 'blue'];
    $conditionLabels = collect($returnSettings['conditions'] ?? [])->pluck('label', 'code');
    $dispositionLabels = collect($returnSettings['dispositions'] ?? [])->pluck('label', 'code');
    $order = $returnCase->externalOrder;
    $currency = $order?->currency ?? 'PLN';
    $billing = (array) ($order?->billing_data ?? []);
    $shipping = (array) ($order?->shipping_data ?? []);
    $returnDocuments = $returnCase->lines
        ->map(fn ($line) => $line->warehouseDocument)
        ->filter()
        ->push($returnCase->warehouseDocument)
        ->filter()
        ->unique('id')
        ->values();
    $returnLabels = $returnCase->shippingLabels->where('status', 'generated')->values();
    $incomingPayments = (float) $returnCase->customerPayments->where('direction', 'incoming')->sum(fn ($payment) => (float) $payment->amount);
    $outgoingPayments = (float) $returnCase->customerPayments->where('direction', 'outgoing')->sum(fn ($payment) => (float) $payment->amount);
    $paymentBalance = $incomingPayments - $outgoingPayments;
    $expectedQuantity = (float) $returnCase->lines->sum(fn ($line) => (float) $line->quantity_expected);
    $acceptedQuantity = (float) $returnCase->lines->sum(fn ($line) => (float) $line->quantity_accepted);
    $customerEmail = $returnCase->customer_email ?: data_get($billing, 'email');
    $events = collect();
    $pushEvent = function ($date, string $title, string $meta = '') use (&$events): void {
        if ($date) {
            $events->push(['date' => $date, 'title' => $title, 'meta' => $meta]);
        }
    };
    $pushEvent($returnCase->created_at, 'Utworzono zwrot', $returnCase->number);
    $pushEvent($returnCase->updated_at, 'Ostatnia aktualizacja', $statusMeta['label']);
    foreach ($returnDocuments as $document) {
        $pushEvent($document->created_at, 'Utworzono dokument '.$document->number, $document->status);
        $pushEvent($document->posted_at, 'Zaksięgowano dokument '.$document->number, $document->destinationWarehouse?->code ?? '');
    }
    foreach ($returnLabels as $label) {
        $labelTitle = $label->purpose === 'exchange' ? 'Wygenerowano etykietę wymiany' : 'Wygenerowano etykietę zwrotną';
        $pushEvent($label->generated_at ?? $label->created_at, $labelTitle, $label->tracking_number ?: ($label->label_number ?: ''));
    }
    if ($returnCase->correctionInvoice) {
        $pushEvent($returnCase->correctionInvoice->issued_at ?? $returnCase->correctionInvoice->created_at, 'Wystawiono korektę '.$returnCase->correctionInvoice->number, $money($returnCase->correctionInvoice->gross_total, $returnCase->correctionInvoice->currency));
    }
    foreach ($returnCase->customerPayments as $payment) {
        $pushEvent($payment->booked_at ?? $payment->created_at, $payment->direction === 'outgoing' ? 'Zarejestrowano wypłatę/refund' : 'Zaksięgowano dopłatę', $money($payment->amount, $payment->currency).' · '.$payment->method);
    }
    foreach ($returnCase->customerMessages as $message) {
        $pushEvent($message->sent_at ?? $message->created_at, 'Wiadomość do klienta', $message->renderedSubject());
    }
    foreach ($returnCase->internalNotes as $note) {
        $pushEvent($note->created_at, 'Notatka wewnętrzna', \Illuminate\Support\Str::limit($note->body, 90));
    }
    $events = $events
        ->sortByDesc(fn ($event): int => $event['date']?->timestamp ?? 0)
        ->values();
@endphp

@push('styles')
    <style>
        .return-detail-page { max-width: 1500px; margin: 0 auto; }
        .return-detail-page *,
        .return-detail-page *::before,
        .return-detail-page *::after { min-width: 0; }
        .return-detail-page .card { overflow: hidden; }
        .return-detail-page .panel-header { gap: 12px; }
        .return-detail-page .panel-header > span { overflow-wrap: anywhere; }
        .return-detail-page .panel-header > span:last-child { text-align: right; }
        .return-toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .return-summary { display: grid; grid-template-columns: repeat(5, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
        .return-summary-card { padding: 14px 16px; min-height: 82px; }
        .return-summary-card > span { display: block; color: var(--muted); font-size: 12px; font-weight: 720; margin-bottom: 5px; }
        .return-summary-card strong { display: block; font-size: 19px; line-height: 1.2; overflow-wrap: anywhere; }
        .return-layout { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 18px; align-items: start; }
        .return-stack,
        .return-action-rail { display: grid; gap: 16px; min-width: 0; }
        .return-action-rail { position: sticky; top: 82px; align-self: start; }
        .return-section-body { padding: 16px; }
        .return-info-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .return-document-grid,
        .return-support-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .return-info-box { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .return-info-box strong { display: block; margin-bottom: 5px; }
        .return-info-box .muted { line-height: 1.45; }
        .return-service-actions { display: grid; gap: 10px; }
        .return-service-actions > form,
        .return-service-actions > a,
        .return-service-actions > span { width: 100%; }
        .return-service-actions > form .button,
        .return-service-actions > a.button { width: 100%; min-height: 38px; }
        .return-service-actions .return-label-list { display: flex; flex-wrap: wrap; gap: 8px; max-width: none; }
        .return-service-actions .return-label-form,
        .return-service-actions .return-message-form { display: grid; gap: 8px; margin-top: 8px; }
        .return-service-actions .return-label-form select,
        .return-service-actions .return-message-form input,
        .return-service-actions .return-message-form select,
        .return-service-actions .return-message-form textarea { max-width: none; width: 100%; }
        .return-service-actions .return-message-panel { min-width: 0; margin-top: 0; white-space: normal; border: 1px solid var(--border); border-radius: 8px; padding: 10px; background: #fffdfb; }
        .return-service-actions .return-message-panel summary { cursor: pointer; color: var(--brand-dark); font-weight: 760; list-style-position: outside; }
        .return-service-actions .inline-flag { display: inline-flex; align-items: center; gap: 7px; font-weight: 720; }
        .return-service-actions .inline-flag input { width: 17px; height: 17px; }
        .return-message-history { display: grid; gap: 8px; margin-top: 10px; }
        .return-timeline { display: grid; gap: 10px; }
        .return-timeline-item { display: grid; grid-template-columns: 145px minmax(0, 1fr); gap: 12px; border-top: 1px solid var(--border); padding-top: 10px; }
        .return-timeline-item:first-child { border-top: 0; padding-top: 0; }
        .return-timeline-date { color: var(--muted); font-size: 12px; font-weight: 720; }
        .return-record-list { display: grid; gap: 10px; }
        .return-record-card { border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .return-record-card header { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 7px; }
        .return-record-card strong { display: block; }
        .return-record-meta { color: var(--muted); font-size: 12px; line-height: 1.45; }
        .return-record-preview { margin-top: 7px; white-space: pre-wrap; }
        .return-payment-row { border-top: 1px solid var(--border); padding-top: 10px; display: flex; justify-content: space-between; gap: 12px; }
        .return-payment-row:first-child { border-top: 0; padding-top: 0; }
        .return-payment-row strong { display: block; }
        .return-payout-box { border: 1px solid var(--border); border-radius: 8px; padding: 12px; margin-bottom: 12px; background: #fffdfb; }
        .return-detail-page .table-scroll { width: 100%; max-width: 100%; overflow-x: auto; }
        .return-detail-page .table-scroll table { min-width: 760px; }
        .return-detail-page .products-table table { min-width: 940px; }
        .wrap-cell { white-space: normal; min-width: 180px; }
        @media (max-width: 1280px) {
            .return-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .return-info-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 1120px) {
            .return-layout { grid-template-columns: 1fr; }
            .return-action-rail { position: static; }
        }
        @media (max-width: 760px) {
            .return-summary,
            .return-info-grid,
            .return-document-grid,
            .return-support-grid,
            .return-timeline-item { grid-template-columns: 1fr; }
            .return-toolbar .inline-actions,
            .return-toolbar .button { width: 100%; }
        }
    </style>
@endpush

@section('content')
    <div class="return-detail-page">
        <section class="return-toolbar">
            <a class="button secondary" href="{{ route('returns.index') }}">Wróć do listy zwrotów</a>
            <div class="inline-actions">
                @if ($order)
                    <a class="button secondary" href="{{ route('orders.show', $order) }}">Zamówienie {{ $order->external_number ?: $order->external_id }}</a>
                @endif
                @if ($returnCase->status !== 'completed' && $returnDocuments->isEmpty())
                    <a class="button secondary" href="{{ route('returns.edit', $returnCase) }}">Edytuj zwrot</a>
                @endif
                <a class="button secondary" href="{{ route('returns.payouts.mbank') }}">mBank wypłaty</a>
            </div>
        </section>

        <section class="return-summary" aria-label="Podsumowanie zwrotu">
            <article class="card return-summary-card">
                <span>Numer zwrotu</span>
                <strong>{{ $returnCase->number }}</strong>
            </article>
            <article class="card return-summary-card">
                <span>Status</span>
                <strong><span class="status {{ $statusMeta['class'] }}">{{ $statusMeta['label'] }}</span></strong>
            </article>
            <article class="card return-summary-card">
                <span>Zamówienie</span>
                <strong>{{ $order?->external_number ?: ($order?->external_id ?: '-') }}</strong>
            </article>
            <article class="card return-summary-card">
                <span>Pozycje</span>
                <strong>{{ $qty($acceptedQuantity) }} / {{ $qty($expectedQuantity) }}</strong>
            </article>
            <article class="card return-summary-card">
                <span>Saldo zwrotu</span>
                <strong>{{ $money($paymentBalance, $currency) }}</strong>
            </article>
        </section>

        <section class="return-layout">
            <div class="return-stack">
                <article class="card">
                    <div class="panel-header">
                        <span>Informacje z zamówienia i zgłoszenia</span>
                        <span>{{ $customerEmail ?: 'brak e-maila' }}</span>
                    </div>
                    <div class="return-section-body return-info-grid">
                        <div class="return-info-box">
                            <strong>Klient</strong>
                            <div>{{ $person($billing) }}</div>
                            <div class="muted">{{ $customerEmail ?: '-' }} · {{ data_get($billing, 'phone', '-') }}</div>
                            <div class="muted">{{ $address($billing) }}</div>
                        </div>
                        <div class="return-info-box">
                            <strong>Dostawa z zamówienia</strong>
                            <div>{{ $person($shipping) }}</div>
                            <div class="muted">{{ $address($shipping) }}</div>
                            <div class="muted">Metoda: {{ data_get($order?->raw_payload, 'shipping_lines.0.method_title', '-') }}</div>
                        </div>
                        <div class="return-info-box">
                            <strong>Zgłoszenie zwrotu</strong>
                            <div>{{ $returnCase->reason ?: 'Brak powodu' }}</div>
                            <div class="muted">Źródło: {{ data_get($returnCase->metadata, 'source', 'ERP') }}</div>
                            <div class="muted">Referencja: {{ data_get($returnCase->metadata, 'return_reference', '-') }}</div>
                        </div>
                        <div class="return-info-box">
                            <strong>Zwrot środków</strong>
                            <div>{{ data_get($returnCase->metadata, 'refund_recipient_name', $person($billing)) ?: '-' }}</div>
                            <div class="muted">{{ data_get($returnCase->metadata, 'refund_bank_account', '-') }}</div>
                            <div class="muted">Metoda płatności: {{ data_get($order?->raw_payload, 'payment_method_title', '-') }}</div>
                        </div>
                    </div>
                </article>

                <article class="card">
                    <div class="panel-header">
                        <span>Produkty w zwrocie</span>
                        <span>{{ $returnCase->lines->count() }} pozycji</span>
                    </div>
                    <div class="table-scroll products-table">
                        <table class="dense-table">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Nazwa</th>
                                    <th>Ilość</th>
                                    <th>Stan</th>
                                    <th>Dyspozycja</th>
                                    <th>Magazyn</th>
                                    <th>Wartość z zamówienia</th>
                                    <th>Uwagi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($returnCase->lines as $line)
                                    <tr>
                                        <td>
                                            @if ($line->product)
                                                <a class="status" href="{{ route('products.show', $line->product) }}">{{ $line->product->sku }}</a>
                                            @else
                                                {{ $line->externalOrderLine?->sku ?? '-' }}
                                            @endif
                                        </td>
                                        <td class="wrap-cell">{{ $line->product?->name ?? $line->externalOrderLine?->name ?? '-' }}</td>
                                        <td>{{ $qty($line->quantity_accepted) }} / {{ $qty($line->quantity_expected) }}</td>
                                        <td>{{ $conditionLabels[$line->condition] ?? $line->condition }}</td>
                                        <td>{{ $dispositionLabels[$line->disposition] ?? $line->disposition }}</td>
                                        <td>{{ $line->targetWarehouse?->code ?? $returnCase->targetWarehouse?->code ?? '-' }}</td>
                                        <td>{{ $line->externalOrderLine?->unit_gross_price !== null ? $money((float) $line->quantity_accepted * (float) $line->externalOrderLine->unit_gross_price, $currency) : '-' }}</td>
                                        <td class="wrap-cell">{{ $line->notes ?: '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8">Brak pozycji w zwrocie.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                <section class="return-document-grid">
                    <article class="card">
                        <div class="panel-header">
                            <span>Dokumenty RX</span>
                            <span>{{ $returnDocuments->count() }} rekordów</span>
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
                                    @forelse ($returnDocuments as $document)
                                        <tr>
                                            <td><a class="status" href="{{ route('documents.show', $document) }}">{{ $document->number }}</a></td>
                                            <td><span class="status {{ $document->status === 'draft' ? 'blue' : '' }}">{{ $document->status }}</span></td>
                                            <td>{{ $document->destinationWarehouse?->code ?? '-' }}</td>
                                            <td class="wrap-cell">{{ $document->lines->map(fn ($line) => ($line->product?->sku ?? '-') . ' x ' . $qty($line->quantity))->implode(', ') }}</td>
                                            <td>{{ $document->document_date?->format('Y-m-d H:i') ?? '-' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5">Brak dokumentu RX dla tego zwrotu.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>

                    <article class="card">
                        <div class="panel-header">
                            <span>Etykiety wymiany i zwrotu</span>
                            <span>{{ $returnLabels->count() }} etykiet</span>
                        </div>
                        <div class="table-scroll">
                            <table class="dense-table">
                                <thead>
                                    <tr>
                                        <th>Typ</th>
                                        <th>Numer</th>
                                        <th>Tracking</th>
                                        <th>Konto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($returnLabels as $label)
                                        <tr>
                                            <td>{{ $label->purpose === 'exchange' ? 'Wymiana' : 'Zwrot' }}</td>
                                            <td><a class="status" href="{{ route('returns.labels.download', $label) }}">{{ $label->label_number ?: $label->id }}</a></td>
                                            <td>{{ $label->tracking_number ?: '-' }}</td>
                                            <td>{{ $label->courierAccount?->name ?? $label->provider }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="4">Brak etykiet dla tego zwrotu.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </article>
                </section>

                <article class="card">
                    <div class="panel-header">
                        <span>Korekty i faktury zamówienia</span>
                        <span>{{ $order?->invoices?->count() ?? 0 }} dokumentów</span>
                    </div>
                    <div class="table-scroll">
                        <table class="dense-table">
                            <thead>
                                <tr>
                                    <th>Numer</th>
                                    <th>Typ</th>
                                    <th>Status</th>
                                    <th>Brutto</th>
                                    <th>KSeF</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse (($order?->invoices ?? collect())->sortByDesc('id') as $invoice)
                                    <tr>
                                        <td>
                                            @if ($returnCase->correctionInvoice?->id === $invoice->id)
                                                <a class="status" href="{{ route('invoices.preview', $invoice) }}" target="_blank" rel="noopener">Korekta {{ $invoice->number }}</a>
                                            @else
                                                <a class="status" href="{{ route('invoices.edit', $invoice) }}">{{ $invoice->number }}</a>
                                            @endif
                                        </td>
                                        <td>{{ $invoice->type }}</td>
                                        <td>{{ $invoice->status }}</td>
                                        <td>{{ $money($invoice->gross_total, $invoice->currency) }}</td>
                                        <td>{{ $invoice->ksef_number ?? '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5">Brak faktur powiązanych z zamówieniem.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </article>

                <section class="return-support-grid">
                    <article class="card">
                        <div class="panel-header">
                            <span>Wypłaty i rozliczenia</span>
                            <span>{{ $money($paymentBalance, $currency) }}</span>
                        </div>
                        <div class="return-section-body">
                            <div class="return-payout-box">
                                <strong>{{ $mbankPayoutEligible ? 'Gotowe do koszyka mBank' : 'Poza koszykiem mBank' }}</strong>
                                <div class="muted">{{ $money($mbankPayoutAmount, $currency) }} · {{ $mbankPayoutRecipient }}</div>
                                <div class="muted">{{ $mbankPayoutAccount ?: 'brak poprawnego rachunku do przelewu' }}</div>
                            </div>
                            <div class="return-record-list">
                                @forelse ($returnCase->customerPayments->take(8) as $payment)
                                    <div class="return-payment-row">
                                        <div>
                                            <strong>{{ $payment->direction === 'outgoing' ? '-' : '+' }}{{ $money($payment->amount, $payment->currency) }}</strong>
                                            <span class="return-record-meta">{{ $payment->method }} · {{ $payment->reference ?: 'bez referencji' }}</span>
                                            @if ($payment->description)
                                                <div class="return-record-meta">{{ $payment->description }}</div>
                                            @endif
                                        </div>
                                        <span @class(['status', 'blue' => $payment->status === 'pending', 'red' => $payment->status === 'failed'])>{{ $payment->status }}</span>
                                    </div>
                                @empty
                                    <span class="muted">Brak ręcznych księgowań, dopłat lub refundów dla tego zwrotu.</span>
                                @endforelse
                            </div>
                        </div>
                    </article>

                    <article class="card">
                        <div class="panel-header">
                            <span>Notatki wewnętrzne</span>
                            <span>{{ $returnCase->internalNotes->count() }} wpisów</span>
                        </div>
                        <div class="return-section-body return-record-list">
                            @if ($returnCase->notes)
                                <div class="return-record-card">
                                    <header>
                                        <strong>Notatka ze zgłoszenia</strong>
                                        <span class="return-record-meta">{{ $returnCase->created_at?->format('Y-m-d H:i') }}</span>
                                    </header>
                                    <div>{{ $returnCase->notes }}</div>
                                </div>
                            @endif
                            @forelse ($returnCase->internalNotes->take(8) as $note)
                                <div class="return-record-card">
                                    <header>
                                        <strong>{{ $note->author_name ?: 'ERP' }}</strong>
                                        <span class="return-record-meta">{{ $note->created_at?->format('Y-m-d H:i') }}</span>
                                    </header>
                                    <div>{{ $note->body }}</div>
                                </div>
                            @empty
                                @unless ($returnCase->notes)
                                    <span class="muted">Brak notatek wewnętrznych ERP.</span>
                                @endunless
                            @endforelse
                        </div>
                    </article>
                </section>

                <article class="card">
                    <div class="panel-header">
                        <span>Komunikacja z klientem</span>
                        <span>{{ $returnCase->customerMessages->count() }} wiadomości</span>
                    </div>
                    <div class="return-section-body return-record-list">
                        @forelse ($returnCase->customerMessages->take(8) as $message)
                            <article class="return-record-card">
                                <header>
                                    <div>
                                        <strong>{{ $message->renderedSubject() }}</strong>
                                        <span class="return-record-meta">
                                            {{ $message->recipient_email }} · {{ $message->type === 'automated' ? 'automat' : 'ręcznie' }}{{ $message->trigger ? ' · '.$message->trigger : '' }}
                                        </span>
                                    </div>
                                    <span @class(['status', 'blue' => $message->status === 'pending', 'red' => $message->status === 'failed', 'orange' => $message->status === 'skipped'])>{{ $message->status }}</span>
                                </header>
                                <div class="return-record-meta">
                                    {{ $message->sent_at?->format('Y-m-d H:i') ?? $message->failed_at?->format('Y-m-d H:i') ?? $message->created_at?->format('Y-m-d H:i') }}
                                    @if ($message->error_message)
                                        · {{ $message->error_message }}
                                    @endif
                                </div>
                                <div class="return-record-preview">{{ \Illuminate\Support\Str::limit($message->renderedBody(), 220) }}</div>
                            </article>
                        @empty
                            <span class="muted">Brak wiadomości wysłanych do klienta w ramach tego zwrotu.</span>
                        @endforelse
                    </div>
                </article>

                <article class="card">
                    <div class="panel-header">
                        <span>Historia zwrotu</span>
                        <span>{{ $events->count() }} zdarzeń</span>
                    </div>
                    <div class="return-section-body return-timeline">
                        @forelse ($events as $event)
                            <div class="return-timeline-item">
                                <div class="return-timeline-date">{{ $event['date']?->format('Y-m-d H:i') }}</div>
                                <div>
                                    <strong>{{ $event['title'] }}</strong>
                                    @if ($event['meta'] !== '')
                                        <div class="muted">{{ $event['meta'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <span class="muted">Brak historii dla tego zwrotu.</span>
                        @endforelse
                    </div>
                </article>
            </div>

            <aside class="return-action-rail">
                <article class="card">
                    <div class="panel-header">
                        <span>Panel obsługi zwrotu</span>
                        <span>{{ $statusMeta['label'] }}</span>
                    </div>
                    <div class="return-section-body return-service-actions">
                        @include('partials.return-actions', ['returnCase' => $returnCase])
                    </div>
                </article>
            </aside>
        </section>
    </div>
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

                    if (!option || option.value === '') {
                        return;
                    }

                    const subject = form.querySelector('input[name="subject"]');
                    const body = form.querySelector('textarea[name="body"]');

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
