@php
    $returnDocuments = $returnCase->lines
        ->map(fn ($line) => $line->warehouseDocument)
        ->filter()
        ->push($returnCase->warehouseDocument)
        ->filter()
        ->unique('id')
        ->values();
    $allReturnDocumentsPosted = $returnDocuments->isNotEmpty()
        && $returnDocuments->every(fn ($document) => $document->status === 'posted');
    $returnLabels = $returnCase->relationLoaded('shippingLabels')
        ? $returnCase->shippingLabels->where('status', 'generated')
        : collect();
    $returnShippingLabel = $returnLabels->firstWhere('purpose', 'return')
        ?: $returnLabels->first(fn ($label) => data_get($label->response_payload, 'direction') === 'return');
    $returnLabelAccounts = ($courierAccounts ?? collect());
    $returnMessages = $returnCase->relationLoaded('customerMessages')
        ? $returnCase->customerMessages
        : collect();
    $returnNotes = $returnCase->relationLoaded('internalNotes')
        ? $returnCase->internalNotes
        : collect();
    $returnPayments = $returnCase->relationLoaded('customerPayments')
        ? $returnCase->customerPayments
        : collect();
    $returnIncomingPayments = (float) $returnPayments->where('direction', 'incoming')->sum(fn ($payment) => (float) $payment->amount);
    $returnOutgoingPayments = (float) $returnPayments->where('direction', 'outgoing')->sum(fn ($payment) => (float) $payment->amount);
    $returnPaymentBalance = $returnIncomingPayments - $returnOutgoingPayments;
    $returnRecipientEmail = $returnCase->customer_email
        ?: data_get($returnCase->externalOrder?->billing_data, 'email');
    $returnEmailTemplates = $emailTemplates ?? collect();
    $returnTemplateContext = [
        'return_number' => $returnCase->number,
        'order_number' => $returnCase->externalOrder?->external_number ?: $returnCase->externalOrder?->external_id,
        'customer_email' => $returnRecipientEmail,
        'amount' => '',
        'currency' => $returnCase->externalOrder?->currency ?? 'PLN',
        'payment_url' => '',
        'from_name' => config('mail.from.name', config('app.name', 'Sempre ERP')),
    ];
@endphp

@if ($returnLabels->isNotEmpty())
    <div class="return-label-list">
        @foreach ($returnLabels as $label)
            @php
                $labelText = match ($label->purpose) {
                    'return' => 'Etykieta zwrotna',
                    'exchange' => 'Etykieta wymiany',
                    default => 'Etykieta',
                };
            @endphp
            <a class="button secondary" href="{{ route('returns.labels.download', $label) }}">{{ $labelText }} {{ $label->label_number ?: $label->id }}</a>
        @endforeach
    </div>
@endif

@if (! $returnShippingLabel && $returnLabelAccounts->isNotEmpty() && in_array($returnCase->status, ['pending', 'opened'], true))
    <form class="return-label-form" method="POST" action="{{ route('returns.shipping-label.create', $returnCase) }}">
        @csrf
        <input type="hidden" name="purpose" value="return">
        <select name="courier_account_id" aria-label="Konto nadawcze InPost">
            @foreach ($returnLabelAccounts as $labelAccount)
                <option value="{{ $labelAccount->id }}" @selected($labelAccount->is_default)>InPost: {{ $labelAccount->name }}</option>
            @endforeach
        </select>
        <button class="button secondary" type="submit">Generuj przesyłkę zwrotną</button>
    </form>
@endif

@if ($returnLabelAccounts->isNotEmpty() && $returnCase->external_order_id)
    <form class="return-label-form" method="POST" action="{{ route('returns.shipping-label.create', $returnCase) }}">
        @csrf
        <input type="hidden" name="purpose" value="exchange">
        <select name="courier_account_id" aria-label="Konto nadawcze InPost dla wymiany">
            @foreach ($returnLabelAccounts as $labelAccount)
                <option value="{{ $labelAccount->id }}" @selected($labelAccount->is_default)>InPost: {{ $labelAccount->name }}</option>
            @endforeach
        </select>
        <button class="button secondary" type="submit">Generuj etykietę wymiany</button>
    </form>
@endif

<details class="return-message-panel">
    <summary>Mail do klienta ({{ $returnMessages->count() }})</summary>
    <form class="return-message-form" method="POST" action="{{ route('returns.message.send', $returnCase) }}" data-email-template-form data-email-template-context="{{ e(json_encode($returnTemplateContext, JSON_UNESCAPED_UNICODE)) }}">
        @csrf
        <div class="muted">Adres: {{ $returnRecipientEmail ?: 'brak adresu e-mail' }}</div>
        @if ($returnEmailTemplates->isNotEmpty())
            <select data-email-template-select aria-label="Szablon wiadomości">
                <option value="">Własna wiadomość</option>
                @foreach ($returnEmailTemplates as $template)
                    <option value="{{ $template->id }}" data-subject="{{ $template->subject }}" data-body="{{ $template->body }}">{{ $template->name }}</option>
                @endforeach
            </select>
        @endif
        <input name="subject" maxlength="160" placeholder="Temat wiadomości" required>
        <textarea name="body" rows="4" maxlength="5000" placeholder="Treść wiadomości" required></textarea>
        <button class="button secondary" type="submit">Wyślij maila</button>
    </form>
    @if ($returnMessages->isNotEmpty())
        <div class="return-message-history">
            @foreach ($returnMessages->take(3) as $message)
                <div>
                    <span @class(['status', 'blue' => $message->status === 'pending', 'red' => $message->status === 'failed', 'orange' => $message->status === 'skipped'])>{{ $message->status }}</span>
                    <span class="muted">{{ $message->renderedSubject() }} · {{ $message->created_at?->format('Y-m-d H:i') }}</span>
                </div>
            @endforeach
        </div>
    @endif
</details>

<details class="return-message-panel">
    <summary>Rozliczenia ({{ number_format($returnPaymentBalance, 2, ',', ' ') }} PLN)</summary>
    <form class="return-message-form" method="POST" action="{{ route('returns.payments.store', $returnCase) }}">
        @csrf
        <input name="amount" type="number" min="0.01" step="0.01" placeholder="Kwota" required>
        <input name="currency" maxlength="3" value="{{ $returnCase->externalOrder?->currency ?? 'PLN' }}" placeholder="Waluta">
        <select name="method" required>
            <option value="blik">BLIK</option>
            <option value="bank_transfer">Przelew</option>
            <option value="cash">Gotówka</option>
            <option value="card">Karta</option>
            <option value="payu">PayU</option>
            <option value="other">Inna</option>
        </select>
        <input name="reference" maxlength="160" placeholder="Referencja">
        <input name="payment_url" type="url" maxlength="1000" placeholder="Link do płatności dla klienta">
        <textarea name="description" rows="3" maxlength="1000" placeholder="Opis dopłaty lub księgowania"></textarea>
        <label class="inline-flag">
            <input type="checkbox" name="send_payment_request" value="1">
            Wyślij prośbę o dopłatę do wymiany
        </label>
        <button class="button secondary" type="submit">Zaksięguj wpłatę</button>
    </form>
    @if ($returnCase->correctionInvoice)
        <form class="return-message-form" method="POST" action="{{ route('returns.payu-refund', $returnCase) }}" onsubmit="return confirm('Wysłać refund PayU dla zwrotu {{ $returnCase->number }}?');">
            @csrf
            <button class="button secondary" type="submit">Wyślij refund PayU</button>
        </form>
    @endif
    @if ($returnPayments->isNotEmpty())
        <div class="return-message-history">
            @foreach ($returnPayments->take(4) as $payment)
                <div>
                    <span @class(['status', 'blue' => $payment->status === 'pending', 'red' => $payment->status === 'failed'])>{{ $payment->status }}</span>
                    <span class="muted">{{ $payment->direction === 'outgoing' ? '-' : '+' }}{{ number_format((float) $payment->amount, 2, ',', ' ') }} {{ $payment->currency }} · {{ $payment->method }}</span>
                </div>
            @endforeach
        </div>
    @endif
</details>

<details class="return-message-panel">
    <summary>Notatki wewnętrzne ({{ $returnNotes->count() }})</summary>
    <form class="return-message-form" method="POST" action="{{ route('returns.notes.store', $returnCase) }}">
        @csrf
        <textarea name="body" rows="4" maxlength="3000" placeholder="Notatka dla BOK/magazynu" required></textarea>
        <button class="button secondary" type="submit">Dodaj notatkę</button>
    </form>
    @if ($returnNotes->isNotEmpty())
        <div class="return-message-history">
            @foreach ($returnNotes->take(4) as $note)
                <div>
                    <span class="muted">{{ $note->author_name ?: 'ERP' }} · {{ $note->created_at?->format('Y-m-d H:i') }}</span>
                    <div>{{ \Illuminate\Support\Str::limit($note->body, 120) }}</div>
                </div>
            @endforeach
        </div>
    @endif
</details>

@if ($returnCase->status === 'pending')
    <form method="POST" action="{{ route('returns.approve', $returnCase) }}" onsubmit="return confirm('Zatwierdzić zwrot {{ $returnCase->number }}? Sklep utworzy zwrot w zamówieniu WooCommerce.');">
        @csrf
        <button class="button" type="submit">Zatwierdź</button>
    </form>
    <form method="POST" action="{{ route('returns.reject', $returnCase) }}" onsubmit="return confirm('Odrzucić zwrot {{ $returnCase->number }}?');">
        @csrf
        <button class="button danger" type="submit">Odrzuć</button>
    </form>
    <a class="button secondary" href="{{ route('returns.edit', $returnCase) }}">Edytuj</a>
@elseif ($allReturnDocumentsPosted)
    <span class="status">Przyjęty</span>
    @if ($returnCase->correctionInvoice)
        <a class="button secondary" href="{{ route('invoices.preview', $returnCase->correctionInvoice) }}" target="_blank" rel="noopener">
            Korekta {{ $returnCase->correctionInvoice->number }}
        </a>
    @elseif ($returnCase->external_order_id)
        <form method="POST" action="{{ route('returns.correction.create', $returnCase) }}">
            @csrf
            <button class="button secondary" type="submit">Wystaw korektę</button>
        </form>
    @endif
@elseif ($returnDocuments->isNotEmpty())
    <span class="status blue">{{ $returnDocuments->pluck('number')->implode(', ') }}</span>
@else
    <a class="button secondary" href="{{ route('returns.edit', $returnCase) }}">Edytuj</a>
    <form method="POST" action="{{ route('returns.document.create', $returnCase) }}">
        @csrf
        <button class="button" type="submit">Utwórz RX</button>
    </form>
    <form method="POST" action="{{ route('returns.destroy', $returnCase) }}" onsubmit="return confirm('Usunąć zwrot {{ $returnCase->number }}?');">
        @csrf
        @method('DELETE')
        <button class="button danger" type="submit">Usuń</button>
    </form>
@endif
