@php
    $layout = $mailLayout ?? [];
    $brandName = trim((string) ($layout['brand_name'] ?? config('app.name', 'Sempre ERP'))) ?: config('app.name', 'Sempre ERP');
    $logoUrl = trim((string) ($layout['logo_url'] ?? ''));
    $accentColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($layout['accent_color'] ?? '')) === 1
        ? (string) $layout['accent_color']
        : '#2f6f4f';
    $headerText = trim((string) ($layout['header_text'] ?? 'Informacja o zamówieniu')) ?: 'Informacja o zamówieniu';
    $signature = trim((string) ($layout['signature'] ?? ''));
    $footerText = trim((string) ($layout['footer_text'] ?? ''));
    $supportEmail = trim((string) ($layout['support_email'] ?? ''));
    $supportPhone = trim((string) ($layout['support_phone'] ?? ''));
    $bodyText = trim((string) $messageBody);
    $preheader = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $bodyText) ?: (string) $customerMessage->subject, 140);
    $paymentUrl = trim((string) data_get($customerMessage->metadata, 'payment_url', ''));
    $hasPaymentUrl = filter_var($paymentUrl, FILTER_VALIDATE_URL) !== false;
    $referenceNumber = trim((string) (
        data_get($customerMessage->metadata, 'order_number')
        ?: data_get($customerMessage->metadata, 'return_number')
        ?: ''
    ));
@endphp
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $customerMessage->subject ?? 'Wiadomość' }}</title>
</head>
<body style="margin:0; padding:0; background:#f3f5f4; font-family:Arial, Helvetica, sans-serif; color:#1f2933;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">
        {{ $preheader }}
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; background:#f3f5f4; margin:0; padding:0;">
        <tr>
            <td align="center" style="padding:28px 14px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="width:100%; max-width:640px; background:#ffffff; border:1px solid #dfe6e2; border-radius:12px; overflow:hidden;">
                    <tr>
                        <td style="background:{{ $accentColor }}; padding:22px 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="left" style="vertical-align:middle;">
                                        @if ($logoUrl !== '')
                                            <img src="{{ $logoUrl }}" alt="{{ $brandName }}" width="150" style="display:block; max-width:150px; max-height:54px; border:0; outline:none; text-decoration:none;">
                                        @else
                                            <div style="font-size:22px; line-height:1.2; font-weight:700; color:#ffffff;">{{ $brandName }}</div>
                                        @endif
                                    </td>
                                    <td align="right" style="vertical-align:middle; color:#ffffff; font-size:13px; line-height:1.4;">
                                        {{ now()->format('Y-m-d') }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:30px 28px 8px;">
                            <div style="font-size:13px; line-height:1.4; color:{{ $accentColor }}; font-weight:700; text-transform:uppercase;">
                                {{ $headerText }}
                            </div>
                            <h1 style="margin:8px 0 0; font-size:24px; line-height:1.25; color:#17211b; font-weight:700;">
                                {{ $customerMessage->subject }}
                            </h1>
                            @if ($referenceNumber !== '')
                                <div style="display:inline-block; margin-top:14px; padding:7px 10px; border-radius:999px; background:#eef5f1; color:#2e5b42; font-size:13px; font-weight:700;">
                                    Numer: {{ $referenceNumber }}
                                </div>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:18px 28px 8px;">
                            <div style="font-size:15px; line-height:1.7; color:#26352d;">
                                {!! nl2br(e($bodyText)) !!}
                            </div>

                            @if ($hasPaymentUrl)
                                <table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0 8px;">
                                    <tr>
                                        <td style="border-radius:8px; background:{{ $accentColor }};">
                                            <a href="{{ $paymentUrl }}" style="display:inline-block; padding:13px 20px; color:#ffffff; text-decoration:none; font-size:15px; font-weight:700;">
                                                Przejdź do płatności
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>

                    @if ($signature !== '')
                        <tr>
                            <td style="padding:8px 28px 28px;">
                                <div style="border-top:1px solid #e6ece8; padding-top:18px; font-size:15px; line-height:1.7; color:#26352d;">
                                    {!! nl2br(e($signature)) !!}
                                </div>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td style="background:#f8faf9; padding:20px 28px; border-top:1px solid #e6ece8;">
                            <div style="font-size:13px; line-height:1.6; color:#6a756f;">
                                @if ($footerText !== '')
                                    <div>{{ $footerText }}</div>
                                @endif
                                @if ($supportEmail !== '' || $supportPhone !== '')
                                    <div style="margin-top:8px;">
                                        @if ($supportEmail !== '')
                                            Kontakt: <a href="mailto:{{ $supportEmail }}" style="color:{{ $accentColor }}; text-decoration:none;">{{ $supportEmail }}</a>
                                        @endif
                                        @if ($supportEmail !== '' && $supportPhone !== '')
                                            ·
                                        @endif
                                        @if ($supportPhone !== '')
                                            {{ $supportPhone }}
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
