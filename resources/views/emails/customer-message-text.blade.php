@php
    $layout = $mailLayout ?? [];
    $signature = trim((string) ($layout['signature'] ?? ''));
    $footerText = trim((string) ($layout['footer_text'] ?? ''));
    $supportEmail = trim((string) ($layout['support_email'] ?? ''));
    $supportPhone = trim((string) ($layout['support_phone'] ?? ''));
    $bodyText = trim((string) $messageBody);
    $paymentUrl = trim((string) data_get($customerMessage->metadata, 'payment_url', ''));
@endphp
{{ $customerMessage->subject }}

{{ $bodyText }}
@if (filter_var($paymentUrl, FILTER_VALIDATE_URL) !== false)

Link do płatności:
{{ $paymentUrl }}
@endif
@if ($signature !== '')

{{ $signature }}
@endif
@if ($footerText !== '' || $supportEmail !== '' || $supportPhone !== '')

---
@if ($footerText !== '')
{{ $footerText }}
@endif
@if ($supportEmail !== '' || $supportPhone !== '')
Kontakt: {{ trim($supportEmail.($supportEmail !== '' && $supportPhone !== '' ? ' · ' : '').$supportPhone) }}
@endif
@endif
