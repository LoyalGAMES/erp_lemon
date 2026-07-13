<?php

declare(strict_types=1);

namespace App\Services\Communication;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Models\ShippingLabel;
use App\Models\WordpressIntegration;
use App\Services\Orders\OrderPaymentLinkService;
use App\Services\Shipping\ShippingProviderResolver;

final class CustomerMailContextService
{
    public function __construct(
        private readonly MailSettingsService $mailSettings,
        private readonly OrderPaymentLinkService $paymentLinks,
        private readonly ShippingProviderResolver $shippingProviders,
    ) {}

    /**
     * @param  array{email:?string,name:?string}  $recipient
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function forOrder(ExternalOrder $order, array $recipient, string $trigger, array $context = []): array
    {
        $order->loadMissing([
            'lines.product.channelMappings',
            'shipmentLabels.courierAccount',
            'invoices.files',
        ]);

        $trackingLabel = $this->trackingLabel($order);
        $paymentUrl = $this->httpUrl($context['payment_url'] ?? null)
            ?? $this->paymentLinks->resolve($order);
        $trackingUrl = $this->httpUrl($context['tracking_url'] ?? null)
            ?? ($trackingLabel instanceof ShippingLabel ? $this->shippingProviders->trackingUrl($trackingLabel) : null);
        $orderUrl = $this->orderUrl($order);
        [$actionLabel, $actionUrl] = $this->orderAction($trigger, $paymentUrl, $trackingUrl, $orderUrl);
        $lines = $order->lines
            ->filter(fn (ExternalOrderLine $line): bool => (float) $line->quantity > 0)
            ->map(fn (ExternalOrderLine $line): array => $this->orderItem($order, $line))
            ->values()
            ->all();
        $totals = $this->orderTotals($order, $lines);
        $progress = $this->orderProgress($trigger);
        $trackingNumber = trim((string) (
            $context['tracking_number']
            ?? $trackingLabel?->trackingIdentifier()
            ?? ''
        ));
        $invoice = $order->invoices
            ->reject(fn ($invoice): bool => $invoice->type === 'proforma')
            ->sortByDesc('id')
            ->first();
        $attachmentInvoiceFileIds = in_array($trigger, ['order_packed', 'order_courier_picked_up'], true) && $invoice !== null
            ? $invoice->files->where('type', 'pdf')->pluck('id')->values()->all()
            : [];

        return array_merge($this->baseContext(), $context, [
            'payload_version' => 2,
            'entity_type' => 'order',
            'trigger' => $trigger,
            'order_number' => $this->orderNumber($order),
            'order_status' => (string) $order->status,
            'fulfillment_status' => (string) ($order->fulfillment_status ?? ''),
            'order_date' => ($order->external_created_at ?? $order->created_at)?->format('d.m.Y'),
            'customer_email' => $recipient['email'],
            'customer_name' => $recipient['name'],
            'currency' => $order->currency ?: ($context['currency'] ?? 'PLN'),
            'amount' => $totals['grand_total_formatted'],
            'items' => $lines,
            'items_count' => count($lines),
            'totals' => $totals,
            'billing_address' => $this->address((array) $order->billing_data),
            'shipping_address' => $this->address($this->deliveryAddress($order)),
            'shipping_method' => $this->shippingMethod($order),
            'payment_method' => trim((string) (
                data_get($order->raw_payload, 'payment_method_title')
                ?: data_get($order->raw_payload, 'payment_method')
            )),
            'tracking_number' => $trackingNumber,
            'tracking_url' => $trackingUrl,
            'courier_name' => $trackingLabel instanceof ShippingLabel
                ? $this->shippingProviders->courierName($trackingLabel, $context['courier'] ?? null)
                : trim((string) ($context['courier'] ?? '')),
            'payment_url' => $paymentUrl,
            'order_url' => $orderUrl,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'progress' => $progress,
            'invoice_number' => trim((string) ($context['invoice_number'] ?? $invoice?->number ?? '')),
            'attachment_invoice_file_ids' => (array) ($context['attachment_invoice_file_ids'] ?? $attachmentInvoiceFileIds),
        ]);
    }

    /**
     * @param  array{email:?string,name:?string}  $recipient
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function forReturn(ReturnCase $returnCase, array $recipient, string $trigger, array $context = []): array
    {
        $returnCase->loadMissing([
            'externalOrder',
            'lines.product.channelMappings',
            'lines.externalOrderLine.product.channelMappings',
            'shippingLabels.courierAccount',
            'correctionInvoice.files',
        ]);

        $order = $returnCase->externalOrder;
        $paymentUrl = $this->httpUrl($context['payment_url'] ?? null);
        $trackingLabel = $returnCase->shippingLabels
            ->first(fn (ShippingLabel $label): bool => filled($label->trackingIdentifier()));
        $trackingUrl = $this->httpUrl($context['tracking_url'] ?? null)
            ?? ($trackingLabel instanceof ShippingLabel ? $this->shippingProviders->trackingUrl($trackingLabel) : null);
        $orderUrl = $order instanceof ExternalOrder ? $this->orderUrl($order) : null;
        [$actionLabel, $actionUrl] = $this->returnAction($trigger, $paymentUrl, $trackingUrl, $orderUrl);
        $items = $returnCase->lines
            ->map(fn (ReturnCaseLine $line): array => $this->returnItem($returnCase, $line))
            ->values()
            ->all();
        $amount = trim((string) ($context['amount'] ?? ''));
        $currency = (string) ($context['currency'] ?? $order?->currency ?? 'PLN');
        $attachmentInvoiceFileIds = in_array($trigger, ['return_correction_issued', 'return_payout_queued', 'return_refunded'], true)
            ? $returnCase->correctionInvoice?->files->where('type', 'pdf')->pluck('id')->values()->all() ?? []
            : [];

        return array_merge($this->baseContext(), $context, [
            'payload_version' => 2,
            'entity_type' => 'return',
            'trigger' => $trigger,
            'return_number' => $returnCase->number,
            'return_status' => (string) $returnCase->status,
            'order_number' => $order instanceof ExternalOrder ? $this->orderNumber($order) : ($context['order_number'] ?? ''),
            'customer_email' => $recipient['email'],
            'customer_name' => $recipient['name'],
            'currency' => $currency,
            'amount' => $amount,
            'items' => $items,
            'items_count' => count($items),
            'return_reason' => (string) ($returnCase->reason ?? ''),
            'shipping_address' => $order instanceof ExternalOrder ? $this->address($this->deliveryAddress($order)) : [],
            'tracking_number' => trim((string) (
                $context['tracking_number']
                ?? $trackingLabel?->trackingIdentifier()
                ?? ''
            )),
            'tracking_url' => $trackingUrl,
            'courier_name' => $trackingLabel instanceof ShippingLabel
                ? $this->shippingProviders->courierName($trackingLabel)
                : '',
            'payment_url' => $paymentUrl,
            'order_url' => $orderUrl,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'progress' => $this->returnProgress($trigger),
            'invoice_number' => trim((string) (
                $context['invoice_number']
                ?? $returnCase->correctionInvoice?->number
                ?? ''
            )),
            'attachment_invoice_file_ids' => (array) ($context['attachment_invoice_file_ids'] ?? $attachmentInvoiceFileIds),
        ]);
    }

    /** @return array<string, mixed> */
    public function previewContext(string $scenario, string $trigger): array
    {
        $base = array_merge($this->baseContext(), [
            'payload_version' => 2,
            'trigger' => $trigger,
            'customer_name' => 'Anna Kowalska',
            'customer_email' => 'anna.kowalska@example.com',
            'currency' => 'PLN',
            'order_number' => 'SL/2026/10482',
            'order_date' => '13.07.2026',
            'order_url' => 'https://example.com/moje-konto/zamowienia/10482',
            'shipping_method' => 'Kurier InPost',
            'payment_method' => 'Płatność online',
            'billing_address' => $this->sampleBillingAddress(),
            'shipping_address' => $this->sampleAddress(),
            'items' => $this->sampleItems(),
            'items_count' => 3,
            'totals' => [
                'subtotal' => 429.70,
                'subtotal_formatted' => '429,70',
                'discount' => 30.00,
                'discount_formatted' => '30,00',
                'shipping' => 14.90,
                'shipping_formatted' => '14,90',
                'tax' => 77.55,
                'tax_formatted' => '77,55',
                'grand_total' => 414.60,
                'grand_total_formatted' => '414,60',
                'currency' => 'PLN',
            ],
            'amount' => '414,60',
        ]);

        if ($scenario === 'return') {
            return array_merge($base, [
                'entity_type' => 'return',
                'return_number' => 'ZW/2026/0041',
                'return_reason' => 'Zmiana rozmiaru',
                'invoice_number' => 'KOR/2026/000127',
                'amount' => '249,00',
                'progress' => $this->returnProgress($trigger),
                'action_label' => 'Sprawdź zamówienie',
                'action_url' => $base['order_url'],
                'items' => [$this->sampleItems()[0]],
                'items_count' => 1,
            ]);
        }

        $paymentUrl = 'https://example.com/platnosc/testowa';
        $trackingUrl = 'https://inpost.pl/sledzenie-przesylek?number=620012345678901234567890';
        [$actionLabel, $actionUrl] = $this->orderAction(
            $trigger,
            in_array($scenario, ['payment', 'order'], true) ? $paymentUrl : null,
            $scenario === 'shipment' ? $trackingUrl : null,
            $base['order_url'],
        );

        return array_merge($base, [
            'entity_type' => 'order',
            'payment_url' => in_array($scenario, ['payment', 'order'], true) ? $paymentUrl : null,
            'tracking_number' => $scenario === 'shipment' ? '620012345678901234567890' : '',
            'tracking_url' => $scenario === 'shipment' ? $trackingUrl : null,
            'courier_name' => $scenario === 'shipment' ? 'InPost' : '',
            'child_order_number' => $scenario === 'split' ? 'SL/2026/10482/S1' : '',
            'invoice_number' => $scenario === 'invoice' ? 'FV/2026/001284' : '',
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
            'progress' => $this->orderProgress($trigger),
        ]);
    }

    /** @return array<string, mixed> */
    private function baseContext(): array
    {
        $settings = $this->mailSettings->data();

        return [
            'from_name' => $settings['from_name'],
            'brand_name' => $settings['brand_name'],
            'support_email' => $settings['support_email'],
            'support_phone' => $settings['support_phone'],
        ];
    }

    /** @return array<string, mixed> */
    private function orderItem(ExternalOrder $order, ExternalOrderLine $line): array
    {
        $quantity = (float) $line->quantity;
        $sourceQuantity = $this->decimal(data_get($line->raw_payload, 'sempre_erp_source_quantity'))
            ?? $this->decimal(data_get($line->raw_payload, 'quantity'))
            ?? $quantity;
        $rawTotal = $this->numeric(data_get($line->raw_payload, 'total'));
        $rawTotalTax = $this->numeric(data_get($line->raw_payload, 'total_tax')) ?? 0.0;
        $rawSubtotal = $this->numeric(data_get($line->raw_payload, 'subtotal'));
        $rawSubtotalTax = $this->numeric(data_get($line->raw_payload, 'subtotal_tax')) ?? 0.0;
        $unitPrice = $rawTotal !== null && $sourceQuantity > 0
            ? ($rawTotal + $rawTotalTax) / $sourceQuantity
            : (float) ($line->unit_gross_price ?? 0);
        $unitSubtotal = $rawSubtotal !== null && $sourceQuantity > 0
            ? ($rawSubtotal + $rawSubtotalTax) / $sourceQuantity
            : $unitPrice;
        $lineTotal = round($unitPrice * $quantity, 2);
        $lineSubtotal = round($unitSubtotal * $quantity, 2);
        $product = $line->product;

        return [
            'name' => (string) $line->name,
            'sku' => (string) ($line->sku ?? ''),
            'quantity' => $this->quantity($quantity),
            'unit_price' => $unitPrice,
            'unit_price_formatted' => $this->money($unitPrice),
            'line_total' => $lineTotal,
            'line_total_formatted' => $this->money($lineTotal),
            'line_subtotal' => $lineSubtotal,
            'line_discount' => max(0, round($lineSubtotal - $lineTotal, 2)),
            'image_url' => $this->imageUrl($line, $product),
            'product_url' => $this->productUrl($order, $line, $product),
        ];
    }

    /** @return array<string, mixed> */
    private function returnItem(ReturnCase $returnCase, ReturnCaseLine $line): array
    {
        $orderLine = $line->externalOrderLine;
        $product = $line->product ?? $orderLine?->product;
        $name = trim((string) (
            $orderLine?->name
            ?: data_get($line->metadata, 'store_item_name')
            ?: $product?->name
            ?: 'Produkt ze zwrotu'
        ));
        $sku = trim((string) (
            $orderLine?->sku
            ?: data_get($line->metadata, 'store_item_sku')
            ?: $product?->sku
            ?: ''
        ));
        $quantity = (float) ($line->quantity_accepted ?: $line->quantity_expected);
        $order = $returnCase->externalOrder;

        return [
            'name' => $name,
            'sku' => $sku,
            'quantity' => $this->quantity($quantity),
            'unit_price' => (float) ($orderLine?->unit_gross_price ?? 0),
            'unit_price_formatted' => $this->money((float) ($orderLine?->unit_gross_price ?? 0)),
            'line_total' => round((float) ($orderLine?->unit_gross_price ?? 0) * $quantity, 2),
            'line_total_formatted' => $this->money((float) ($orderLine?->unit_gross_price ?? 0) * $quantity),
            'image_url' => $orderLine instanceof ExternalOrderLine ? $this->imageUrl($orderLine, $product) : $this->httpUrl($product?->imageUrl()),
            'product_url' => $order instanceof ExternalOrder && $orderLine instanceof ExternalOrderLine
                ? $this->productUrl($order, $orderLine, $product)
                : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function orderTotals(ExternalOrder $order, array $items): array
    {
        $lineTotal = round((float) collect($items)->sum('line_total'), 2);
        $subtotal = round((float) collect($items)->sum(
            fn (array $item): float => (float) ($item['line_subtotal'] ?? $item['line_total'] ?? 0),
        ), 2);
        $discount = round((float) collect($items)->sum('line_discount'), 2);
        $orderDiscount = round(
            ($this->numeric(data_get($order->raw_payload, 'discount_total')) ?? 0.0)
            + ($this->numeric(data_get($order->raw_payload, 'discount_tax')) ?? 0.0),
            2,
        );

        if ($discount <= 0 && $orderDiscount > 0) {
            $discount = $orderDiscount;
            $subtotal = round($lineTotal + $discount, 2);
        }

        $shippingNet = $this->numeric(data_get($order->raw_payload, 'shipping_total'))
            ?? round((float) collect((array) data_get($order->raw_payload, 'shipping_lines', []))->sum(
                fn ($line): float => is_array($line) ? (float) ($line['total'] ?? 0) : 0,
            ), 2);
        $shippingTax = $this->numeric(data_get($order->raw_payload, 'shipping_tax'))
            ?? round((float) collect((array) data_get($order->raw_payload, 'shipping_lines', []))->sum(
                fn ($line): float => is_array($line) ? (float) ($line['total_tax'] ?? 0) : 0,
            ), 2);
        $shipping = round($shippingNet + $shippingTax, 2);
        $tax = $this->numeric(data_get($order->raw_payload, 'total_tax')) ?? 0.0;
        $grandTotal = (float) $order->total_gross;

        return [
            'subtotal' => $subtotal,
            'subtotal_formatted' => $this->money($subtotal),
            'discount' => $discount,
            'discount_formatted' => $this->money($discount),
            'shipping' => $shipping,
            'shipping_formatted' => $this->money($shipping),
            'tax' => $tax,
            'tax_formatted' => $this->money($tax),
            'grand_total' => $grandTotal,
            'grand_total_formatted' => $this->money($grandTotal),
            'currency' => (string) ($order->currency ?: 'PLN'),
        ];
    }

    /** @return array<string, mixed> */
    private function address(array $data): array
    {
        $name = trim(implode(' ', array_filter([
            $data['first_name'] ?? null,
            $data['last_name'] ?? null,
        ])));

        return array_filter([
            'name' => $name,
            'company' => trim((string) ($data['company'] ?? '')),
            'line1' => trim(implode(' ', array_filter([$data['address_1'] ?? null, $data['address_2'] ?? null]))),
            'line2' => trim(implode(' ', array_filter([$data['postcode'] ?? null, $data['city'] ?? null]))),
            'country' => trim((string) ($data['country'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
        ], fn ($value): bool => $value !== '');
    }

    private function orderNumber(ExternalOrder $order): string
    {
        return (string) ($order->external_number ?: $order->external_id ?: $order->id);
    }

    private function trackingLabel(ExternalOrder $order): ?ShippingLabel
    {
        return $order->shipmentLabels
            ->first(fn (ShippingLabel $label): bool => filled($label->trackingIdentifier()));
    }

    /** @return array<string, mixed> */
    private function deliveryAddress(ExternalOrder $order): array
    {
        $shipping = (array) $order->shipping_data;

        return filled($shipping['address_1'] ?? null) ? $shipping : (array) $order->billing_data;
    }

    private function shippingMethod(ExternalOrder $order): string
    {
        return collect((array) data_get($order->raw_payload, 'shipping_lines', []))
            ->filter(fn ($line): bool => is_array($line))
            ->map(fn (array $line): string => trim((string) ($line['method_title'] ?? $line['method_id'] ?? '')))
            ->filter()
            ->implode(', ');
    }

    private function imageUrl(ExternalOrderLine $line, ?Product $product): ?string
    {
        foreach ([
            $product?->imageUrl(),
            data_get($line->raw_payload, 'image.src'),
            data_get($line->raw_payload, 'image.url'),
            data_get($line->raw_payload, 'images.0.src'),
        ] as $candidate) {
            if (($url = $this->httpUrl($candidate)) !== null) {
                return $url;
            }
        }

        return null;
    }

    private function productUrl(ExternalOrder $order, ExternalOrderLine $line, ?Product $product): ?string
    {
        if ($product instanceof Product) {
            $mapping = $product->channelMappings
                ->firstWhere('sales_channel_id', $order->sales_channel_id);
            $mapped = $this->httpUrl(data_get($mapping?->metadata, 'woocommerce_permalink'));

            if ($mapped !== null) {
                return $mapped;
            }
        }

        foreach ([
            data_get($line->raw_payload, 'permalink'),
            data_get($line->raw_payload, 'parent_permalink'),
        ] as $candidate) {
            if (($url = $this->httpUrl($candidate)) !== null) {
                return $url;
            }
        }

        return null;
    }

    private function orderUrl(ExternalOrder $order): ?string
    {
        $integration = WordpressIntegration::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->first(['base_url']);

        if (! $integration instanceof WordpressIntegration || blank($order->external_id)) {
            return null;
        }

        return rtrim($integration->base_url, '/')
            .'/my-account/view-order/'.rawurlencode((string) $order->external_id).'/';
    }

    /** @return array{0:?string,1:?string} */
    private function orderAction(string $trigger, ?string $paymentUrl, ?string $trackingUrl, ?string $orderUrl): array
    {
        if (in_array($trigger, ['order_created', 'order_on_hold', 'order_payment_failed', 'manual_payment_reminder'], true)
            && $paymentUrl !== null) {
            return ['Przejdź do płatności', $paymentUrl];
        }

        if (in_array($trigger, ['order_courier_picked_up', 'order_delivered'], true) && $trackingUrl !== null) {
            return ['Śledź przesyłkę', $trackingUrl];
        }

        return $orderUrl !== null ? ['Sprawdź szczegóły zamówienia', $orderUrl] : [null, null];
    }

    /** @return array{0:?string,1:?string} */
    private function returnAction(string $trigger, ?string $paymentUrl, ?string $trackingUrl, ?string $orderUrl): array
    {
        if ($trigger === 'exchange_payment_requested' && $paymentUrl !== null) {
            return ['Opłać dopłatę', $paymentUrl];
        }

        if (in_array($trigger, ['return_label_ready', 'exchange_label_ready'], true) && $trackingUrl !== null) {
            return ['Śledź przesyłkę', $trackingUrl];
        }

        return $orderUrl !== null ? ['Sprawdź zamówienie', $orderUrl] : [null, null];
    }

    /** @return array{current:int,total:int,labels:list<string>,cancelled:bool} */
    private function orderProgress(string $trigger): array
    {
        $current = match ($trigger) {
            'order_received', 'order_payment_received', 'order_updated', 'order_partial_created', 'order_packing_rollback' => 2,
            'order_packed', 'order_invoice_ready' => 3,
            'order_courier_picked_up' => 4,
            'order_delivered' => 5,
            default => 1,
        };

        return [
            'current' => $current,
            'total' => 5,
            'labels' => ['Złożone', 'W realizacji', 'Spakowane', 'W drodze', 'Dostarczone'],
            'cancelled' => in_array($trigger, ['order_cancelled', 'order_payment_failed', 'order_refunded'], true),
        ];
    }

    /** @return array{current:int,total:int,labels:list<string>,cancelled:bool} */
    private function returnProgress(string $trigger): array
    {
        $current = match ($trigger) {
            'return_approved', 'return_label_ready' => 2,
            'return_received_warehouse', 'return_correction_issued', 'exchange_payment_received' => 3,
            'return_payout_queued', 'return_refunded', 'exchange_label_ready' => 4,
            default => 1,
        };

        return [
            'current' => $current,
            'total' => 4,
            'labels' => ['Zgłoszenie', 'Przesyłka', 'Weryfikacja', 'Rozliczenie'],
            'cancelled' => $trigger === 'return_rejected',
        ];
    }

    private function httpUrl(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $url = trim((string) $value);
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true)
                ? $url
                : null;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }

    private function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function numeric(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function decimal(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /** @return array<string, string> */
    private function sampleAddress(): array
    {
        return [
            'name' => 'Anna Kowalska',
            'line1' => 'ul. Kwiatowa 12/4',
            'line2' => '62-070 Poznań',
            'country' => 'PL',
            'phone' => '+48 500 600 700',
        ];
    }

    /** @return array<string, string> */
    private function sampleBillingAddress(): array
    {
        return [
            'name' => 'Anna Kowalska',
            'company' => 'Kwiat Studio Anna Kowalska',
            'line1' => 'ul. Różana 8',
            'line2' => '62-070 Poznań',
            'country' => 'PL',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sampleItems(): array
    {
        return [
            [
                'name' => 'Lniana sukienka Luna – szałwiowa',
                'sku' => 'SL-LUNA-SAGE-M',
                'quantity' => '1',
                'unit_price' => 249.90,
                'unit_price_formatted' => '249,90',
                'line_total' => 249.90,
                'line_total_formatted' => '249,90',
                'image_url' => null,
                'product_url' => 'https://example.com/produkt/sukienka-luna',
            ],
            [
                'name' => 'Pasek skórzany Noa – karmelowy',
                'sku' => 'SL-NOA-CARAMEL',
                'quantity' => '1',
                'unit_price' => 129.90,
                'unit_price_formatted' => '129,90',
                'line_total' => 129.90,
                'line_total_formatted' => '129,90',
                'image_url' => null,
                'product_url' => 'https://example.com/produkt/pasek-noa',
            ],
            [
                'name' => 'Pudełko prezentowe Sempre',
                'sku' => 'SL-GIFT-BOX',
                'quantity' => '1',
                'unit_price' => 49.90,
                'unit_price_formatted' => '49,90',
                'line_total' => 49.90,
                'line_total_formatted' => '49,90',
                'image_url' => null,
                'product_url' => null,
            ],
        ];
    }
}
