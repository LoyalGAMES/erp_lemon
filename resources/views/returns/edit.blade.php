@extends('layouts.app', ['title' => "Edycja zwrotu {$returnCase->number}", 'subtitle' => 'Zwrot można edytować dopóki nie utworzono dokumentu RX.', 'module' => 'returns'])

@section('content')
    @php
        $returnReasons = $returnSettings['return_reasons'] ?? [];
        $returnConditions = $returnSettings['conditions'] ?? [];
        $returnDispositions = $returnSettings['dispositions'] ?? [];
        $quantityLabel = static function (float $quantity): string {
            $formatted = number_format($quantity, 4, ',', ' ');

            return rtrim(rtrim($formatted, '0'), ',');
        };
    @endphp

    <div class="page-toolbar">
        <a class="button secondary" href="{{ route('returns.index') }}">Wróć do zwrotów</a>
    </div>

    <article class="card return-edit-card">
        <div class="panel-header">
            <span>{{ $returnCase->number }}</span>
            <span>{{ $returnCase->externalOrder?->external_number ?? 'bez zamówienia' }}</span>
        </div>
        <form class="form-grid return-edit-form" method="POST" action="{{ route('returns.update', $returnCase) }}" data-return-edit-form autocomplete="off">
            @csrf
            @method('PUT')
            <label>Magazyn domyślny zwrotu
                <select name="target_warehouse_id" required>
                    <option value="">Wybierz magazyn</option>
                    @foreach ($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected((string) old('target_warehouse_id', $returnCase->target_warehouse_id) === (string) $warehouse->id)>
                            {{ $warehouse->code }} - {{ $warehouse->name }}
                        </option>
                    @endforeach
                </select>
            </label>
            <label>Powód
                <select name="reason">
                    <option value="">Wybierz powód</option>
                    @foreach ($returnReasons as $reason)
                        <option value="{{ $reason }}" @selected(old('reason', $returnCase->reason) === $reason)>{{ $reason }}</option>
                    @endforeach
                </select>
            </label>
            <label>Email klienta
                <input name="customer_email" type="email" value="{{ old('customer_email', $returnCase->customer_email) }}">
            </label>
            <div class="return-line-grid">
                <label>Odbiorca zwrotu środków
                    <input name="refund_recipient_name" value="{{ old('refund_recipient_name', data_get($returnCase->metadata, 'refund_recipient_name')) }}" maxlength="143">
                </label>
                <label>Rachunek klienta do zwrotu
                    <input name="refund_bank_account" value="{{ old('refund_bank_account', data_get($returnCase->metadata, 'refund_bank_account')) }}" maxlength="34" placeholder="26 cyfr albo PL...">
                </label>
            </div>
            <label>Notatka
                <textarea name="notes" rows="3">{{ old('notes', $returnCase->notes) }}</textarea>
            </label>

            <section class="return-lines-editor">
                <div class="section-header">
                    <strong>Pozycje zwrotu</strong>
                    <span class="muted">Zmiany zapisują się na zwrocie, ale nie tworzą RX automatycznie.</span>
                </div>
                <div class="return-lines-list">
                    @foreach ($returnCase->lines as $lineIndex => $line)
                        @php
                            $oldLine = old("lines.{$lineIndex}", []);
                            $orderLine = $line->externalOrderLine;
                            $orderedQuantity = $orderLine ? (float) $orderLine->quantity : (float) $line->quantity_expected;
                        @endphp
                        <div class="return-line-card">
                            <input name="lines[{{ $lineIndex }}][external_order_line_id]" type="hidden" value="{{ $oldLine['external_order_line_id'] ?? $line->external_order_line_id }}">
                            <input name="lines[{{ $lineIndex }}][product_id]" type="hidden" value="{{ $oldLine['product_id'] ?? $line->product_id }}">
                            <div class="return-line-head">
                                <div>
                                    <strong>{{ $line->product?->name ?? $orderLine?->name ?? 'Produkt' }}</strong>
                                    <span class="muted">{{ $line->product?->sku ?? $orderLine?->sku ?? 'brak SKU' }} · zamówiono {{ $quantityLabel($orderedQuantity) }} szt.</span>
                                </div>
                            </div>
                            <div class="return-line-grid">
                                <label>Ilość
                                    <input name="lines[{{ $lineIndex }}][quantity]" type="number" step="1" min="1" value="{{ $oldLine['quantity'] ?? $line->quantity_accepted }}" required>
                                </label>
                                <label>Stan towaru
                                    <select name="lines[{{ $lineIndex }}][condition]" required>
                                        @foreach ($returnConditions as $condition)
                                            <option value="{{ $condition['code'] }}" @selected(($oldLine['condition'] ?? $line->condition) === $condition['code'])>{{ $condition['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Dyspozycja
                                    <select name="lines[{{ $lineIndex }}][disposition]" required>
                                        @foreach ($returnDispositions as $disposition)
                                            <option value="{{ $disposition['code'] }}" @selected(($oldLine['disposition'] ?? $line->disposition) === $disposition['code'])>{{ $disposition['label'] }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <label>Notatka do pozycji
                                <input name="lines[{{ $lineIndex }}][notes]" value="{{ $oldLine['notes'] ?? $line->notes }}" placeholder="np. brak metki, plama, uszkodzone opakowanie">
                            </label>
                        </div>
                    @endforeach
                </div>
            </section>

            <button class="button" type="submit">Zapisz zmiany</button>
        </form>
    </article>
@endsection

@push('styles')
    <style>
        .return-edit-card { max-width: 980px; }
        .return-edit-form { padding: 16px; }
        .return-lines-editor { display: grid; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: #fffdfb; }
        .section-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
        .return-lines-list { display: grid; gap: 10px; }
        .return-line-card { display: grid; gap: 10px; border: 1px solid var(--border); border-radius: 8px; padding: 12px; background: var(--surface); }
        .return-line-head div { display: grid; gap: 3px; }
        .return-line-grid { display: grid; grid-template-columns: minmax(110px, .4fr) minmax(180px, .8fr) minmax(180px, .8fr); gap: 10px; }
        @media (max-width: 760px) {
            .return-line-grid,
            .section-header { grid-template-columns: 1fr; }
        }
    </style>
@endpush

@push('scripts')
    <script>
        document.querySelector('[data-return-edit-form]')?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && event.target.tagName !== 'TEXTAREA') {
                event.preventDefault();
            }
        });
    </script>
@endpush
