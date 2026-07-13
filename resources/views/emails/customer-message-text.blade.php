@php
    $layout = $mailLayout ?? [];
    $metadata = (array) ($customerMessage->metadata ?? []);
    $signature = trim((string) ($layout['signature'] ?? ''));
    $footerText = trim((string) ($layout['footer_text'] ?? ''));
    $supportEmail = trim((string) ($layout['support_email'] ?? ''));
    $supportPhone = trim((string) ($layout['support_phone'] ?? ''));
    $subjectText = trim((string) ($messageSubject ?? $customerMessage->renderedSubject()));
    $bodyText = trim((string) $messageBody);
    $items = array_values(array_filter((array) ($metadata['items'] ?? []), 'is_array'));
    $totals = is_array($metadata['totals'] ?? null) ? $metadata['totals'] : [];
    $address = is_array($metadata['shipping_address'] ?? null) ? $metadata['shipping_address'] : [];
    $billingAddress = is_array($metadata['billing_address'] ?? null) ? $metadata['billing_address'] : [];
    $returnAddress = is_array($metadata['return_address'] ?? null) ? $metadata['return_address'] : [];
    $showBillingAddress = $billingAddress !== [] && $billingAddress !== $address;
    $actionUrl = trim((string) ($metadata['action_url'] ?? ''));
    if ($actionUrl === '' && !array_key_exists('action_url', $metadata)) {
        $actionUrl = trim((string) ($metadata['payment_url'] ?? ''));
    }
    $paymentInstruction = trim((string) ($metadata['payment_instruction'] ?? ''));
    $showPaymentInstruction = ($metadata['entity_type'] ?? null) === 'order'
        && $paymentInstruction !== ''
        && !str_contains(trim((string) $messageBody), $paymentInstruction);
@endphp
{{ $subjectText !== '' ? $subjectText : 'Wiadomość' }}

@if (filled($metadata['customer_name'] ?? null))Dzień dobry, {{ $metadata['customer_name'] }}!

@endif
{{ $bodyText }}
@if (($metadata['entity_type'] ?? null) === 'return' && $returnAddress !== [] && in_array((string) ($metadata['trigger'] ?? ''), ['return_waiting_for_package', 'return_approved', 'return_label_ready', 'manual_return_message'], true))

ADRES DO ODESŁANIA PACZKI
@foreach (['name', 'line1', 'line2', 'country', 'phone'] as $part)@if (filled($returnAddress[$part] ?? null)){{ $returnAddress[$part] }}
@endif
@endforeach
@endif
@if ($actionUrl !== '')

{{ $metadata['action_label'] ?? 'Sprawdź szczegóły' }}:
{{ $actionUrl }}
@endif
@if (filled($metadata['tracking_number'] ?? null))

PRZESYŁKA
Kurier: {{ $metadata['courier_name'] ?? '-' }}
Numer śledzenia: {{ $metadata['tracking_number'] }}
@if (filled($metadata['tracking_url'] ?? null))Śledzenie: {{ $metadata['tracking_url'] }}
@endif
@endif
@if ($items !== [])

{{ ($metadata['entity_type'] ?? null) === 'return' ? 'PRODUKTY W ZGŁOSZENIU' : 'TWOJE PRODUKTY' }}
@foreach ($items as $item)
- {{ $item['name'] ?? 'Produkt' }}@if (filled($item['sku'] ?? null)) (SKU: {{ $item['sku'] }})@endif — ilość: {{ $item['quantity'] ?? 1 }}@if (($metadata['entity_type'] ?? null) === 'order' && filled($item['line_total_formatted'] ?? null)), {{ $item['line_total_formatted'] }} {{ $metadata['currency'] ?? 'PLN' }}@endif
@if (filled($item['product_url'] ?? null))  {{ $item['product_url'] }}
@endif
@endforeach
@endif
@if (($metadata['entity_type'] ?? null) === 'order' && $totals !== [])

PODSUMOWANIE
Produkty: {{ $totals['subtotal_formatted'] ?? '0,00' }} {{ $metadata['currency'] ?? 'PLN' }}
@if ((float) ($totals['discount'] ?? 0) > 0)Rabat: -{{ $totals['discount_formatted'] }} {{ $metadata['currency'] ?? 'PLN' }}
@endif
Dostawa: {{ $totals['shipping_formatted'] ?? '0,00' }} {{ $metadata['currency'] ?? 'PLN' }}
Razem: {{ $totals['grand_total_formatted'] ?? $metadata['amount'] ?? '0,00' }} {{ $metadata['currency'] ?? 'PLN' }}
@endif
@if ($address !== [])

DOSTAWA
@if (filled($metadata['shipping_method'] ?? null)){{ $metadata['shipping_method'] }}
@endif
@foreach (['name', 'company', 'line1', 'line2', 'country', 'phone'] as $part)@if (filled($address[$part] ?? null)){{ $address[$part] }}
@endif
@endforeach
@endif
@if ($showBillingAddress)

DANE DO DOKUMENTU
@foreach (['name', 'company', 'line1', 'line2', 'country'] as $part)@if (filled($billingAddress[$part] ?? null)){{ $billingAddress[$part] }}
@endif
@endforeach
@endif
@if (filled($metadata['payment_method'] ?? null) || filled($metadata['invoice_number'] ?? null))

PŁATNOŚĆ I DOKUMENTY
@if (filled($metadata['payment_method'] ?? null))Metoda płatności: {{ $metadata['payment_method'] }}
@endif
@if ($showPaymentInstruction){{ $paymentInstruction }}
@endif
@if (filled($metadata['invoice_number'] ?? null))Dokument: {{ $metadata['invoice_number'] }}
@endif
@endif
@if ($signature !== '')

{{ $signature }}
@endif

---
@if ($supportEmail !== '' || $supportPhone !== '')Kontakt: {{ trim($supportEmail.($supportEmail !== '' && $supportPhone !== '' ? ' · ' : '').$supportPhone) }}
@endif
@if ($footerText !== ''){{ $footerText }}
@endif
