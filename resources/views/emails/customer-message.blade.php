@php
    $layout = $mailLayout ?? [];
    $metadata = (array) ($customerMessage->metadata ?? []);
    $brandName = trim((string) ($layout['brand_name'] ?? config('app.name', 'Sempre'))) ?: config('app.name', 'Sempre');
    $logoUrl = trim((string) ($layout['logo_url'] ?? ''));
    $accentColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($layout['accent_color'] ?? '')) === 1
        ? (string) $layout['accent_color']
        : '#2f6f4f';
    $accentText = $accentForeground ?? '#ffffff';
    $accentLink = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($accentLinkColor ?? '')) === 1
        ? (string) $accentLinkColor
        : '#2f6f4f';
    $headerText = trim((string) ($layout['header_text'] ?? 'Aktualizacja zamówienia')) ?: 'Aktualizacja zamówienia';
    $signature = trim((string) ($layout['signature'] ?? ''));
    $footerText = trim((string) ($layout['footer_text'] ?? ''));
    $supportEmail = trim((string) ($layout['support_email'] ?? ''));
    $supportPhone = trim((string) ($layout['support_phone'] ?? ''));
    $subjectText = trim((string) ($messageSubject ?? $customerMessage->renderedSubject()));
    $bodyText = trim((string) $messageBody);
    $preheader = \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $bodyText) ?: $subjectText, 150);
    $entityType = (string) ($metadata['entity_type'] ?? 'message');
    $referenceNumber = trim((string) ($entityType === 'return'
        ? ($metadata['return_number'] ?? $metadata['order_number'] ?? '')
        : ($metadata['order_number'] ?? $metadata['return_number'] ?? '')));
    $customerName = trim((string) ($metadata['customer_name'] ?? ''));
    $items = array_values(array_filter((array) ($metadata['items'] ?? []), 'is_array'));
    $visibleItems = array_slice($items, 0, 8);
    $totals = is_array($metadata['totals'] ?? null) ? $metadata['totals'] : [];
    $billingAddress = is_array($metadata['billing_address'] ?? null) ? $metadata['billing_address'] : [];
    $shippingAddress = is_array($metadata['shipping_address'] ?? null) ? $metadata['shipping_address'] : [];
    $returnAddress = is_array($metadata['return_address'] ?? null) ? $metadata['return_address'] : [];
    $progress = is_array($metadata['progress'] ?? null) ? $metadata['progress'] : [];
    $progressLabels = array_values((array) ($progress['labels'] ?? []));
    $progressCurrent = max(0, (int) ($progress['current'] ?? 0));
    $progressCancelled = (bool) ($progress['cancelled'] ?? false);
    $currency = trim((string) ($metadata['currency'] ?? data_get($totals, 'currency', 'PLN'))) ?: 'PLN';
    $httpUrl = static function (mixed $candidate): ?string {
        if (! is_scalar($candidate)) return null;
        $url = trim((string) $candidate);
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true) ? $url : null;
    };
    $logoUrl = $httpUrl($logoUrl) ?? '';
    $actionUrl = $httpUrl($metadata['action_url'] ?? null);
    if ($actionUrl === null && !array_key_exists('action_url', $metadata)) {
        $actionUrl = $httpUrl($metadata['payment_url'] ?? null);
    }
    $actionLabel = trim((string) ($metadata['action_label'] ?? ''))
        ?: ($actionUrl ? (filled($metadata['payment_url'] ?? null) ? 'Przejdź do płatności' : 'Sprawdź szczegóły') : '');
    $trackingUrl = $httpUrl($metadata['tracking_url'] ?? null);
    $phoneHref = preg_replace('/[^0-9+]/', '', $supportPhone) ?: '';
    $entityLabel = $entityType === 'return' ? 'Zwrot' : ($entityType === 'order' ? 'Zamówienie' : ($entityType === 'customer' ? 'Konto' : 'Wiadomość'));
    $showBillingAddress = $billingAddress !== [] && $billingAddress !== $shippingAddress;
    $paymentInstruction = trim((string) ($metadata['payment_instruction'] ?? ''));
    $showPaymentInstruction = $entityType === 'order'
        && $paymentInstruction !== ''
        && !str_contains($bodyText, $paymentInstruction);
@endphp
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>{{ $subjectText !== '' ? $subjectText : 'Wiadomość' }}</title>
    <style>
        table { border-spacing: 0; }
        a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
        @media only screen and (max-width: 680px) {
            .mail-outer-pad { padding: 16px 6px 28px !important; }
            .mail-shell { width: 100% !important; }
            .mail-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .mail-title { font-size: 29px !important; line-height: 1.14 !important; letter-spacing: -.7px !important; overflow-wrap: anywhere !important; }
            .mail-action-table { width: 100% !important; }
            .mail-action-link { display: block !important; min-width: 0 !important; padding-left: 18px !important; padding-right: 18px !important; }
            .mail-two-col, .mail-two-col > tbody, .mail-two-col > tbody > tr, .mail-two-col > tbody > tr > td { display: block !important; width: 100% !important; }
            .mail-two-col > tbody > tr > td { padding-left: 0 !important; padding-right: 0 !important; }
            .mail-progress-label { font-size: 9px !important; }
            .mail-product-image-cell { width: 82px !important; padding-right: 10px !important; }
            .mail-product-image { width: 68px !important; height: 82px !important; line-height: 82px !important; }
            .mail-product-price-desktop { display: none !important; mso-hide: all !important; }
            .mail-product-price-mobile { display: block !important; max-height: none !important; overflow: visible !important; margin-top: 8px !important; color: #191919 !important; font-size: 14px !important; line-height: 1.4 !important; font-weight: 800 !important; }
            .mail-contact-link { word-break: break-word !important; overflow-wrap: anywhere !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background:#f2f1ee; font-family:Arial, Helvetica, sans-serif; color:#191919; -webkit-text-size-adjust:100%;">
    <div style="display:none !important; visibility:hidden; opacity:0; color:transparent; height:0; width:0; max-height:0; max-width:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px;">
        {{ $preheader }}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;
    </div>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f2f1ee" style="width:100%; margin:0; padding:0; background:#f2f1ee;">
        <tr>
            <td class="mail-outer-pad" align="center" style="padding:28px 10px 44px;">
                <!--[if mso]><table role="presentation" width="680" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
                <table role="presentation" class="mail-shell" width="680" cellspacing="0" cellpadding="0" border="0" style="width:100%; max-width:680px; border-collapse:separate;">
                    <tr>
                        <td bgcolor="#ffffff" style="background:#ffffff; border-radius:16px 16px 0 0; border-top:6px solid {{ $accentColor }}; padding:26px 34px 22px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td align="left" valign="middle">
                                        @if ($logoUrl !== '')
                                            <img src="{{ $logoUrl }}" alt="{{ $brandName }}" width="170" style="display:block; width:auto; max-width:170px; max-height:58px; height:auto; border:0; outline:none; text-decoration:none;">
                                        @else
                                            <div style="font-size:25px; line-height:1.15; font-weight:800; letter-spacing:-0.5px; color:#191919;">{{ $brandName }}</div>
                                        @endif
                                    </td>
                                    <td align="right" valign="middle" style="font-size:12px; line-height:1.4; color:#6b6b6b;">
                                        {{ $entityLabel }}@if ($referenceNumber !== '')<br><strong style="color:#191919;">#{{ $referenceNumber }}</strong>@endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    @if ($progressLabels !== [])
                        <tr>
                            <td class="mail-pad" bgcolor="#ffffff" style="background:#ffffff; padding:6px 34px 26px;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; table-layout:fixed; border-collapse:collapse;">
                                    <tr>
                                        @foreach ($progressLabels as $index => $label)
                                            @php
                                                $step = $index + 1;
                                                $active = !$progressCancelled && $step <= $progressCurrent;
                                                $cancelledStep = $progressCancelled && $step === 1;
                                                $lineBefore = $active ? $accentColor : '#dededb';
                                                $lineAfter = $active ? $accentColor : '#dededb';
                                                $circleBackground = $cancelledStep ? '#b42318' : ($active ? $accentColor : '#ffffff');
                                                $circleBorder = $cancelledStep ? '#b42318' : ($active ? $accentColor : '#c8c8c4');
                                                $circleText = $cancelledStep ? '#ffffff' : ($active ? $accentText : '#777777');
                                            @endphp
                                            <td align="center" valign="top" style="padding:0;">
                                                <table class="mail-progress-axis" role="presentation" width="100%" height="26" cellspacing="0" cellpadding="0" border="0" style="width:100%; height:26px; border-collapse:collapse;">
                                                    <tr height="26">
                                                        <td width="50%" height="26" valign="middle" style="width:50%; height:26px; vertical-align:middle;">
                                                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; border-collapse:collapse;">
                                                                <tr><td height="3" bgcolor="{{ $lineBefore }}" style="height:3px; max-height:3px; background:{{ $lineBefore }}; font-size:0; line-height:0; mso-line-height-rule:exactly;">&nbsp;</td></tr>
                                                            </table>
                                                        </td>
                                                        <td width="26" height="26" align="center" valign="middle" style="width:26px; height:26px; vertical-align:middle;">
                                                            <div style="box-sizing:border-box; display:block; width:26px; height:26px; border:2px solid {{ $circleBorder }}; border-radius:50%; background:{{ $circleBackground }}; color:{{ $circleText }}; font-size:11px; line-height:22px; mso-line-height-rule:exactly; font-weight:800; text-align:center;">
                                                                {{ $cancelledStep ? '×' : $step }}
                                                            </div>
                                                        </td>
                                                        <td width="50%" height="26" valign="middle" style="width:50%; height:26px; vertical-align:middle;">
                                                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; border-collapse:collapse;">
                                                                <tr><td height="3" bgcolor="{{ $lineAfter }}" style="height:3px; max-height:3px; background:{{ $lineAfter }}; font-size:0; line-height:0; mso-line-height-rule:exactly;">&nbsp;</td></tr>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                </table>
                                                <div class="mail-progress-label" style="margin-top:7px; padding:0 2px; font-size:10px; line-height:1.25; font-weight:{{ $active ? '700' : '400' }}; color:{{ $active ? '#191919' : '#777777' }};">{{ $label }}</div>
                                            </td>
                                        @endforeach
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="mail-pad" bgcolor="#ffffff" style="background:#ffffff; padding:30px 42px 16px; border-top:1px solid #ecece8;">
                            <div style="font-size:12px; line-height:1.4; letter-spacing:1.1px; text-transform:uppercase; color:{{ $accentLink }}; font-weight:800;">{{ $headerText }}</div>
                            <h1 class="mail-title" style="margin:12px 0 0; font-size:38px; line-height:1.12; letter-spacing:-1.25px; color:#111111; font-weight:800;">{{ $subjectText !== '' ? $subjectText : 'Wiadomość od '.$brandName }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td class="mail-pad" bgcolor="#ffffff" style="background:#ffffff; padding:12px 42px 34px;">
                            @if ($customerName !== '')
                                <div style="margin-bottom:14px; font-size:16px; line-height:1.55; font-weight:700; color:#191919;">Dzień dobry, {{ $customerName }}!</div>
                            @endif
                            <div style="font-size:16px; line-height:1.65; color:#353535;">{!! nl2br(e($bodyText)) !!}</div>

                            @if ($entityType === 'return' && $returnAddress !== [] && in_array((string) ($metadata['trigger'] ?? ''), ['return_waiting_for_package', 'return_approved', 'return_label_ready', 'manual_return_message'], true))
                                <div style="margin-top:24px; padding:18px 20px; border-left:4px solid {{ $accentColor }}; background:#f6f6f3; color:#3b3b3b; font-size:14px; line-height:1.55;">
                                    <strong style="display:block; margin-bottom:7px; color:#191919; font-size:15px;">Adres do odesłania paczki</strong>
                                    @foreach (['name', 'line1', 'line2', 'country', 'phone'] as $part)
                                        @if (filled($returnAddress[$part] ?? null)){{ $returnAddress[$part] }}<br>@endif
                                    @endforeach
                                </div>
                            @endif

                            @if ($actionUrl !== null)
                                <table role="presentation" class="mail-action-table" cellspacing="0" cellpadding="0" border="0" style="margin:26px 0 0;">
                                    <tr>
                                        <td align="center" bgcolor="{{ $accentColor }}" style="background:{{ $accentColor }}; border-radius:999px; mso-padding-alt:15px 25px;">
                                            <a class="mail-action-link" href="{{ $actionUrl }}" style="display:inline-block; min-width:220px; padding:15px 25px; color:{{ $accentText }}; text-decoration:none; text-align:center; font-size:15px; line-height:1.2; font-weight:800;">{{ $actionLabel }} &nbsp;→</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if (filled($metadata['tracking_number'] ?? null))
                                <div style="margin-top:24px; padding:16px 18px; border-left:4px solid {{ $accentColor }}; background:#f6f6f3; font-size:14px; line-height:1.55; color:#3b3b3b;">
                                    <strong style="display:block; color:#191919;">Przesyłka {{ filled($metadata['courier_name'] ?? null) ? '· '.$metadata['courier_name'] : '' }}</strong>
                                    <span style="display:block; margin-top:3px; color:#565653;">Numer śledzenia</span>
                                    @if ($trackingUrl)
                                        <a href="{{ $trackingUrl }}" style="display:inline-block; margin-top:1px; color:{{ $accentLink }} !important; font-size:15px; line-height:1.45; font-weight:800; text-decoration:underline; overflow-wrap:anywhere; word-break:break-word;">{{ $metadata['tracking_number'] }}</a>
                                    @else
                                        <strong style="display:block; margin-top:1px; color:#191919; font-size:15px; line-height:1.45; overflow-wrap:anywhere; word-break:break-word;">{{ $metadata['tracking_number'] }}</strong>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>

                    @if ($visibleItems !== [])
                        <tr>
                            <td class="mail-pad" bgcolor="#f8f8f5" style="background:#f8f8f5; padding:32px 42px 10px; border-top:1px solid #e5e5e1;">
                                <h2 style="margin:0; font-size:23px; line-height:1.25; letter-spacing:-0.4px; color:#191919;">{{ $entityType === 'return' ? 'Produkty w zgłoszeniu' : 'Twoje produkty' }}</h2>
                                <div style="margin-top:6px; color:#70706c; font-size:13px; line-height:1.5;">{{ count($items) }} {{ count($items) === 1 ? 'pozycja' : 'pozycje' }} w tej wiadomości</div>
                            </td>
                        </tr>
                        @foreach ($visibleItems as $item)
                            @php
                                $imageUrl = $httpUrl($item['image_url'] ?? null);
                                $productUrl = $httpUrl($item['product_url'] ?? null);
                                $productName = trim((string) ($item['name'] ?? 'Produkt')) ?: 'Produkt';
                            @endphp
                            <tr>
                                <td class="mail-pad" bgcolor="#f8f8f5" style="background:#f8f8f5; padding:0 42px;">
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-top:1px solid #deded9;">
                                        <tr>
                                            <td class="mail-product-image-cell" width="104" valign="middle" style="width:104px; padding:18px 14px 18px 0;">
                                                @if ($imageUrl)
                                                    @if ($productUrl)<a href="{{ $productUrl }}" style="text-decoration:none;">@endif
                                                    <img class="mail-product-image" src="{{ $imageUrl }}" alt="{{ $productName }}" width="88" height="106" style="display:block; width:88px; height:106px; object-fit:contain; background:#ffffff; border:0; border-radius:8px;">
                                                    @if ($productUrl)</a>@endif
                                                @else
                                                    <div class="mail-product-image" style="width:88px; height:106px; border-radius:8px; background:#ebeae5; color:{{ $accentLink }}; font-size:26px; line-height:106px; font-weight:800; text-align:center;">{{ mb_strtoupper(mb_substr($productName, 0, 1)) }}</div>
                                                @endif
                                            </td>
                                            <td valign="middle" style="padding:18px 8px;">
                                                @if ($productUrl)
                                                    <a href="{{ $productUrl }}" style="color:#191919; text-decoration:none; font-size:15px; line-height:1.42; font-weight:800;">{{ $productName }}</a>
                                                @else
                                                    <div style="color:#191919; font-size:15px; line-height:1.42; font-weight:800;">{{ $productName }}</div>
                                                @endif
                                                @if (filled($item['sku'] ?? null))
                                                    <div style="margin-top:5px; color:#777773; font-size:12px; line-height:1.4;">SKU: {{ $item['sku'] }}</div>
                                                @endif
                                                <div style="margin-top:5px; color:#4b4b49; font-size:13px; line-height:1.4;">Ilość: {{ $item['quantity'] ?? 1 }}</div>
                                                @if ($entityType === 'order' && filled($item['line_total_formatted'] ?? null))
                                                    <div class="mail-product-price-mobile" style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ $item['line_total_formatted'] }} {{ $currency }}</div>
                                                @endif
                                            </td>
                                            @if ($entityType === 'order' && filled($item['line_total_formatted'] ?? null))
                                                <td class="mail-product-price-desktop" width="105" align="right" valign="middle" style="width:105px; padding:18px 0 18px 10px; color:#191919; font-size:15px; line-height:1.4; font-weight:800; white-space:nowrap;">{{ $item['line_total_formatted'] }} {{ $currency }}</td>
                                            @endif
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        @endforeach
                        @if (count($items) > count($visibleItems))
                            <tr>
                                <td class="mail-pad" bgcolor="#f8f8f5" style="background:#f8f8f5; padding:14px 42px 24px; color:#666662; font-size:13px;">oraz {{ count($items) - count($visibleItems) }} kolejnych pozycji</td>
                            </tr>
                        @endif
                    @endif

                    @if ($entityType === 'order' && $totals !== [])
                        <tr>
                            <td class="mail-pad" bgcolor="#ffffff" style="background:#ffffff; padding:30px 42px; border-top:1px solid #e5e5e1;">
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr><td style="padding:5px 0; color:#666662; font-size:14px;">Produkty</td><td align="right" style="padding:5px 0; color:#191919; font-size:14px;">{{ data_get($totals, 'subtotal_formatted', '0,00') }} {{ $currency }}</td></tr>
                                    @if ((float) data_get($totals, 'discount', 0) > 0)
                                        <tr><td style="padding:5px 0; color:#666662; font-size:14px;">Rabat</td><td align="right" style="padding:5px 0; color:#2f6f4f; font-size:14px;">−{{ data_get($totals, 'discount_formatted') }} {{ $currency }}</td></tr>
                                    @endif
                                    <tr><td style="padding:5px 0; color:#666662; font-size:14px;">Dostawa</td><td align="right" style="padding:5px 0; color:#191919; font-size:14px;">{{ data_get($totals, 'shipping_formatted', '0,00') }} {{ $currency }}</td></tr>
                                    <tr><td style="padding:13px 0 0; border-top:1px solid #deded9; color:#191919; font-size:17px; font-weight:800;">Razem</td><td align="right" style="padding:13px 0 0; border-top:1px solid #deded9; color:#191919; font-size:20px; font-weight:800;">{{ data_get($totals, 'grand_total_formatted', $metadata['amount'] ?? '0,00') }} {{ $currency }}</td></tr>
                                </table>
                            </td>
                        </tr>
                    @endif

                    @if ($shippingAddress !== [] || $showBillingAddress || filled($metadata['shipping_method'] ?? null) || filled($metadata['payment_method'] ?? null) || filled($metadata['invoice_number'] ?? null))
                        <tr>
                            <td class="mail-pad" bgcolor="#ffffff" style="background:#ffffff; padding:8px 42px 34px;">
                                <table role="presentation" class="mail-two-col" width="100%" cellspacing="0" cellpadding="0" border="0">
                                    <tr>
                                        @if ($shippingAddress !== [])
                                            <td width="50%" valign="top" style="width:50%; padding:18px 18px 18px 0; border-top:1px solid #deded9;">
                                                <div style="font-size:12px; line-height:1.4; color:#70706c; text-transform:uppercase; letter-spacing:.7px; font-weight:800;">Dostawa</div>
                                                @if (filled($metadata['shipping_method'] ?? null))<div style="margin-top:9px; font-size:14px; line-height:1.5; color:#191919; font-weight:800;">{{ $metadata['shipping_method'] }}</div>@endif
                                                <div style="margin-top:8px; font-size:13px; line-height:1.55; color:#4a4a47;">
                                                    @foreach (['name', 'company', 'line1', 'line2', 'country'] as $part)
                                                        @if (filled($shippingAddress[$part] ?? null)){{ $shippingAddress[$part] }}<br>@endif
                                                    @endforeach
                                                    @if (filled($shippingAddress['phone'] ?? null)){{ $shippingAddress['phone'] }}@endif
                                                </div>
                                            </td>
                                        @endif
                                        <td width="50%" valign="top" style="width:50%; padding:18px 0 18px 18px; border-top:1px solid #deded9;">
                                            <div style="font-size:12px; line-height:1.4; color:#70706c; text-transform:uppercase; letter-spacing:.7px; font-weight:800;">Płatność i dokumenty</div>
                                            @if (filled($metadata['payment_method'] ?? null))<div style="margin-top:9px; font-size:14px; line-height:1.5; color:#191919; font-weight:800;">{{ $metadata['payment_method'] }}</div>@endif
                                            @if ($showPaymentInstruction)<div style="margin-top:8px; font-size:13px; line-height:1.55; color:#4a4a47;">{{ $paymentInstruction }}</div>@endif
                                            @if (filled($metadata['invoice_number'] ?? null))<div style="margin-top:8px; font-size:13px; line-height:1.5; color:#4a4a47;">Dokument: <strong>{{ $metadata['invoice_number'] }}</strong></div>@endif
                                            @if (filled($metadata['order_date'] ?? null))<div style="margin-top:8px; font-size:13px; line-height:1.5; color:#4a4a47;">Data zamówienia: {{ $metadata['order_date'] }}</div>@endif
                                        </td>
                                    </tr>
                                </table>
                                @if ($showBillingAddress)
                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                        <tr>
                                            <td valign="top" style="padding:18px 0; border-top:1px solid #deded9;">
                                                <div style="font-size:12px; line-height:1.4; color:#70706c; text-transform:uppercase; letter-spacing:.7px; font-weight:800;">Dane do dokumentu</div>
                                                <div style="margin-top:9px; font-size:13px; line-height:1.55; color:#4a4a47;">
                                                    @foreach (['name', 'company', 'line1', 'line2', 'country'] as $part)
                                                        @if (filled($billingAddress[$part] ?? null)){{ $billingAddress[$part] }}<br>@endif
                                                    @endforeach
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                @endif
                            </td>
                        </tr>
                    @endif

                    <tr>
                        <td class="mail-pad" bgcolor="#e8efeb" style="background:#e8efeb; padding:30px 42px; border-top:1px solid #d8e2dc;">
                            <h2 style="margin:0; font-size:22px; line-height:1.25; letter-spacing:-.35px; color:#17221c;">Masz pytanie? Jesteśmy dla Ciebie.</h2>
                            <div style="margin-top:9px; font-size:14px; line-height:1.55; color:#4a5b51;">
                                {{ $supportEmail !== '' ? 'Odpowiedz na tę wiadomość lub skontaktuj się z naszym zespołem obsługi klienta.' : 'Skontaktuj się z naszym zespołem obsługi klienta — chętnie pomożemy.' }}
                            </div>
                            @if ($supportEmail !== '' || $supportPhone !== '')
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%; margin-top:16px; border-collapse:separate;">
                                    @if ($supportEmail !== '')
                                        <tr>
                                            <td bgcolor="#ffffff" style="background:#ffffff; padding:12px 16px; border-left:4px solid {{ $accentColor }}; border-radius:7px;">
                                                <div style="color:#657168; font-size:10px; line-height:1.35; letter-spacing:.7px; text-transform:uppercase; font-weight:800;">Napisz do nas</div>
                                                <a class="mail-contact-link" href="mailto:{{ $supportEmail }}" style="display:inline-block; margin-top:3px; color:{{ $accentLink }} !important; font-size:15px; line-height:1.45; font-weight:800; text-decoration:underline; overflow-wrap:anywhere; word-break:break-word;">{{ $supportEmail }}</a>
                                            </td>
                                        </tr>
                                    @endif
                                    @if ($supportEmail !== '' && $supportPhone !== '')
                                        <tr><td height="8" style="height:8px; font-size:0; line-height:0;">&nbsp;</td></tr>
                                    @endif
                                    @if ($supportPhone !== '')
                                        <tr>
                                            <td bgcolor="#ffffff" style="background:#ffffff; padding:12px 16px; border-left:4px solid {{ $accentColor }}; border-radius:7px;">
                                                <div style="color:#657168; font-size:10px; line-height:1.35; letter-spacing:.7px; text-transform:uppercase; font-weight:800;">Zadzwoń do nas</div>
                                                <a class="mail-contact-link" href="tel:{{ $phoneHref }}" style="display:inline-block; margin-top:3px; color:{{ $accentLink }} !important; font-size:15px; line-height:1.45; font-weight:800; text-decoration:underline;">{{ $supportPhone }}</a>
                                            </td>
                                        </tr>
                                    @endif
                                </table>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td class="mail-pad" bgcolor="#ffffff" style="background:#ffffff; padding:28px 42px 32px; border-radius:0 0 16px 16px;">
                            @if ($signature !== '')
                                <div style="font-size:14px; line-height:1.6; color:#353535;">{!! nl2br(e($signature)) !!}</div>
                            @endif
                            <div style="margin-top:20px; padding-top:18px; border-top:1px solid #e4e4df; font-size:11px; line-height:1.6; color:#858581;">
                                @if ($footerText !== ''){{ $footerText }}@endif
                                @if ($referenceNumber !== '')<br>Identyfikator wiadomości: {{ $entityLabel }} #{{ $referenceNumber }}@endif
                            </div>
                        </td>
                    </tr>
                </table>
                <!--[if mso]></td></tr></table><![endif]-->
            </td>
        </tr>
    </table>
</body>
</html>
