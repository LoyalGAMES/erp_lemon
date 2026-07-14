<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Klient API BLPaczka (base.courier). Kontrakt odtworzony z oficjalnej
 * wtyczki open-source "BLPaczka" dla WooCommerce:
 * POST https://api.blpaczka.com/api/{endpoint} z JSON-em zawierającym
 * blok auth {login, api_key}. Konto ERP: organization_id = login,
 * api_token = klucz API z panelu BLPaczki.
 *
 * ERP pobiera etykiety przesyłek utworzonych we wtyczce sklepu
 * (getWaybill.json) i śledzi ich status (getWaybillTracking.json).
 */
final class BLPaczkaShipmentService
{
    /** Format etykiety 100 x 150 mm przeznaczony dla drukarek termicznych. */
    private const WAYBILL_PRINTER_TYPE = 'LBL';

    /**
     * Frazy w statusach śledzenia oznaczające, że paczka fizycznie
     * opuściła magazyn nadawcy.
     */
    private const PICKED_UP_KEYWORDS = [
        'odebran',
        'nadan',
        'przyję',
        'w drodze',
        'w transporcie',
        'sortow',
        'doręcz',
        'wydano do',
        'collected',
        'in transit',
        'delivered',
    ];

    private const DELIVERED_KEYWORDS = [
        'doręcz',
        'dostarcz',
        'wydano odbiorcy',
        'odebrana przez odbiorc',
        'delivered',
    ];

    public function __construct(
        private readonly ShippingAddressParser $addressParser,
    ) {}

    /**
     * Tworzy nową przesyłkę BLPaczka dla zamówienia: wycena → automatyczny
     * wybór oferty kuriera wg metody wysyłki z koszyka (fallback: najtańsza)
     * → utworzenie przesyłki → pobranie etykiety.
     *
     * @return array{shipment_id:string,tracking_number:?string,contents:string,mime_type:string,response_payload:array<string,mixed>}
     */
    public function createShipmentWithLabel(ExternalOrder $order, CourierAccount $account): array
    {
        $sender = $this->senderFromAccount($account);
        $parcel = $this->parcelFromAccount($account);
        $takerPoint = $this->pickupPointFromMeta($order);

        $courierSearch = [
            'type' => 'package',
            'weight' => $parcel['weight'],
            'side_x' => $parcel['side_x'],
            'side_y' => $parcel['side_y'],
            'side_z' => $parcel['side_z'],
            'country_code' => 'PL',
            'origin' => 'woocommerce',
        ];

        $valuation = $this->post($account, 'getValuation.json', [
            'CourierSearch' => $courierSearch,
        ]);

        $offers = collect((array) data_get($valuation, 'data.results', []))
            ->filter(fn ($offer): bool => is_array($offer))
            ->values();

        if ($offers->isEmpty()) {
            throw new RuntimeException('BLPaczka nie zwróciła żadnych ofert kurierów dla podanych wymiarów paczki.');
        }

        $offer = $this->pickOffer($offers->all(), $order, $takerPoint !== null);
        $courierCode = (string) data_get($offer, 'Courier.courier_code', '');

        if ($courierCode === '') {
            throw new RuntimeException('Nie udało się dopasować oferty kuriera BLPaczka do metody wysyłki zamówienia.');
        }

        $orderBlock = $sender + $this->takerFromOrder($order) + [
            'package_content' => 'Zamówienie '.($order->external_number ?: $order->external_id),
            'pickup_date' => now()->addWeekday()->format('Y-m-d'),
        ];

        if ($takerPoint !== null) {
            $orderBlock['taker_point'] = $takerPoint;
        }

        $created = $this->post($account, 'createOrderV2.json', [
            'Cart' => [
                ['Order' => $orderBlock],
            ],
            'CourierSearch' => $courierSearch + ['courier_code' => $courierCode],
            'CartOrder' => [
                'payment' => (string) data_get($account->metadata, 'payment', 'bank'),
            ],
        ]);

        $shipmentId = (string) (
            data_get($created, 'data.blpaczka_order_id')
            ?: data_get($created, 'data.CartOrder.0.id')
            ?: data_get($created, 'data.Order.0.id', '')
        );

        if ($shipmentId === '' || $shipmentId === '0') {
            throw new RuntimeException('BLPaczka nie zwróciła identyfikatora utworzonej przesyłki.');
        }

        $label = $this->fetchLabelForShipment($shipmentId, $account);
        $label['response_payload'] = array_merge($label['response_payload'], [
            'courier_code' => $courierCode,
            'courier_name' => (string) data_get($offer, 'Courier.name', ''),
            'price' => data_get($offer, 'Price.value'),
        ]);

        return $label;
    }

    /**
     * Dopasowuje ofertę z wyceny do metody wysyłki wybranej przez klienta.
     * Gdy nic nie pasuje — wybiera najtańszą ofertę.
     *
     * @param  list<array<string, mixed>>  $offers
     * @return array<string, mixed>
     */
    private function pickOffer(array $offers, ExternalOrder $order, bool $needsPoint): array
    {
        $offers = collect($offers)
            ->filter(fn (array $offer): bool => $needsPoint
                ? filled(data_get($offer, 'Courier.taker_point_required'))
                : blank(data_get($offer, 'Courier.taker_point_required')))
            ->whenEmpty(fn () => collect($offers))
            ->values();

        $methods = collect((array) data_get($order->raw_payload, 'shipping_lines', []))
            ->map(fn (array $line): string => mb_strtolower((string) ($line['method_title'] ?? '')))
            ->filter()
            ->implode(' ');

        $brands = ['dpd', 'dhl', 'gls', 'ups', 'fedex', 'tnt', 'pocztex', 'poczta', 'orlen', 'inpost', 'geis', 'ambro', 'raben'];

        $matched = $offers->first(function (array $offer) use ($methods, $brands): bool {
            $courier = mb_strtolower(trim(
                (string) data_get($offer, 'Courier.name', '').' '.(string) data_get($offer, 'Courier.courier_code', ''),
            ));

            foreach ($brands as $brand) {
                if (str_contains($courier, $brand) && str_contains($methods, $brand)) {
                    return true;
                }
            }

            return false;
        });

        return $matched ?? $offers
            ->sortBy(fn (array $offer): float => (float) data_get($offer, 'Price.value', PHP_FLOAT_MAX))
            ->first();
    }

    /**
     * @return array<string, string>
     */
    private function senderFromAccount(CourierAccount $account): array
    {
        $sender = (array) data_get($account->metadata, 'sender', []);
        $required = ['name', 'street', 'house_no', 'postal', 'city', 'phone', 'email'];
        $missing = array_filter($required, fn (string $key): bool => blank($sender[$key] ?? null));

        if ($missing !== []) {
            throw new RuntimeException(
                "Konto {$account->name} nie ma kompletnych danych nadawcy BLPaczka (brakuje: ".implode(', ', $missing).'). Uzupełnij je w Ustawienia → Wysyłki.',
            );
        }

        $block = [];

        foreach (['name', 'street', 'house_no', 'locum_no', 'postal', 'city', 'phone', 'email'] as $key) {
            if (filled($sender[$key] ?? null)) {
                $block[$key] = (string) $sender[$key];
            }
        }

        return $block;
    }

    /**
     * @return array{weight:float,side_x:int,side_y:int,side_z:int}
     */
    private function parcelFromAccount(CourierAccount $account): array
    {
        $parcel = (array) data_get($account->metadata, 'parcel', []);

        foreach (['weight', 'side_x', 'side_y', 'side_z'] as $key) {
            if (blank($parcel[$key] ?? null) || (float) $parcel[$key] <= 0) {
                throw new RuntimeException(
                    "Konto {$account->name} nie ma zdefiniowanych wymiarów domyślnej paczki BLPaczka (waga i boki w cm). Uzupełnij je w Ustawienia → Wysyłki.",
                );
            }
        }

        return [
            'weight' => (float) $parcel['weight'],
            'side_x' => (int) $parcel['side_x'],
            'side_y' => (int) $parcel['side_y'],
            'side_z' => (int) $parcel['side_z'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function takerFromOrder(ExternalOrder $order): array
    {
        $shipping = (array) ($order->shipping_data ?? []);
        $billing = (array) ($order->billing_data ?? []);
        $value = fn (string $key): string => trim(
            (string) (data_get($shipping, $key) ?: data_get($billing, $key, '')),
        );

        $name = trim($value('first_name').' '.$value('last_name'));
        $company = $value('company');
        $usesShippingAddress = filled(data_get($shipping, 'address_1'));
        $addressSource = $usesShippingAddress ? $shipping : $billing;
        $address = $this->addressParser->parse(
            (string) data_get($addressSource, 'address_1', ''),
            (string) data_get($addressSource, 'address_2', ''),
        );

        return array_filter([
            'taker_name' => $company !== '' ? $company : $name,
            'taker_street' => $address['street'],
            'taker_house_no' => $address['building_number'],
            'taker_locum_no' => $address['apartment_number'] ?? '',
            'taker_postal' => $value('postcode'),
            'taker_city' => $value('city'),
            'taker_phone' => mb_substr(preg_replace('/\D+/', '', $value('phone')) ?? '', -9),
            'taker_email' => (string) (data_get($billing, 'email') ?: data_get($shipping, 'email', '')),
        ], fn (string $field): bool => $field !== '');
    }

    private function pickupPointFromMeta(ExternalOrder $order): ?string
    {
        $metaSources = [(array) data_get($order->raw_payload, 'meta_data', [])];

        foreach ((array) data_get($order->raw_payload, 'shipping_lines', []) as $shippingLine) {
            $metaSources[] = (array) ($shippingLine['meta_data'] ?? []);
        }

        foreach ($metaSources as $metaData) {
            foreach ($metaData as $meta) {
                if (! is_array($meta)) {
                    continue;
                }

                $key = mb_strtolower((string) ($meta['key'] ?? ''));

                if (str_contains($key, 'blpaczka') && str_contains($key, 'point')) {
                    $value = trim((string) ($meta['value'] ?? ''));

                    if ($value !== '' && mb_strlen($value) <= 40) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Znajduje w meta zamówienia identyfikator przesyłki BLPaczka
     * zapisany przez wtyczkę sklepu (BLPACZKA_blpaczka_order_id).
     */
    public function orderIdFromMeta(ExternalOrder $order): ?string
    {
        $metaSources = [(array) data_get($order->raw_payload, 'meta_data', [])];

        foreach ((array) data_get($order->raw_payload, 'shipping_lines', []) as $shippingLine) {
            $metaSources[] = (array) ($shippingLine['meta_data'] ?? []);
        }

        foreach ($metaSources as $metaData) {
            foreach ($metaData as $meta) {
                if (! is_array($meta)) {
                    continue;
                }

                $key = mb_strtolower((string) ($meta['key'] ?? ''));

                if (! str_contains($key, 'blpaczka_order_id')) {
                    continue;
                }

                $value = trim((string) ($meta['value'] ?? ''));

                if (preg_match('/^\d{1,12}$/', $value) === 1) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Pobiera etykietę istniejącej przesyłki BLPaczka.
     *
     * @return array{shipment_id:string,tracking_number:?string,contents:string,mime_type:string,response_payload:array<string,mixed>}
     */
    public function fetchLabelForShipment(string $blpaczkaOrderId, CourierAccount $account): array
    {
        $response = $this->post($account, 'getWaybill.json', [
            'Order' => [
                'id' => (int) $blpaczkaOrderId,
                'printer_type' => self::WAYBILL_PRINTER_TYPE,
            ],
        ]);

        $file = (array) data_get($response, 'data.0', []);
        $content = (string) ($file['content'] ?? '');
        $decoded = $content !== '' ? base64_decode($content, true) : false;

        if ($decoded === false || $decoded === '') {
            throw new RuntimeException('BLPaczka nie zwróciła pliku etykiety dla przesyłki '.$blpaczkaOrderId.'.');
        }

        return [
            'shipment_id' => $blpaczkaOrderId,
            'tracking_number' => $this->waybillNumber($blpaczkaOrderId, $account),
            'contents' => $decoded,
            'mime_type' => (string) ($file['mime'] ?? 'application/pdf'),
            'response_payload' => [
                'filename' => $file['filename'] ?? null,
                'message' => $response['message'] ?? null,
            ],
        ];
    }

    /**
     * @return array{status:string,picked_up:bool,picked_up_at:?string,delivered:bool,delivered_at:?string,events:list<array<string,mixed>>}
     */
    public function trackingStatus(string $blpaczkaOrderId, CourierAccount $account): array
    {
        $response = $this->post($account, 'getWaybillTracking.json', [
            'Order' => [
                'id' => (int) $blpaczkaOrderId,
            ],
        ]);

        $events = collect((array) data_get($response, 'data.Tracking', []))
            ->filter(fn ($event): bool => is_array($event))
            ->values();

        $pickedUpEvent = $events->first(function (array $event): bool {
            $haystack = mb_strtolower(implode(' ', array_map(
                fn ($value): string => is_scalar($value) ? (string) $value : '',
                $event,
            )));

            foreach (self::PICKED_UP_KEYWORDS as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return true;
                }
            }

            return false;
        });
        $deliveredEvent = $events->first(function (array $event): bool {
            $haystack = mb_strtolower(implode(' ', array_map(
                fn ($value): string => is_scalar($value) ? (string) $value : '',
                $event,
            )));

            return collect(self::DELIVERED_KEYWORDS)->contains(
                fn (string $keyword): bool => str_contains($haystack, $keyword),
            );
        });

        $latest = $events->last();

        return [
            'status' => is_array($latest)
                ? (string) ($latest['status'] ?? $latest['name'] ?? $latest['description'] ?? 'unknown')
                : 'no_events',
            'picked_up' => $pickedUpEvent !== null,
            'picked_up_at' => is_array($pickedUpEvent)
                ? (string) ($pickedUpEvent['date'] ?? $pickedUpEvent['datetime'] ?? $pickedUpEvent['created'] ?? '') ?: null
                : null,
            'delivered' => $deliveredEvent !== null,
            'delivered_at' => is_array($deliveredEvent)
                ? (string) ($deliveredEvent['date'] ?? $deliveredEvent['datetime'] ?? $deliveredEvent['created'] ?? '') ?: null
                : null,
            'events' => $events->all(),
        ];
    }

    /**
     * @return array{status:string,shipment_id:string,message:?string,response_payload:array<string,mixed>}
     */
    public function cancelShipment(string $blpaczkaOrderId, CourierAccount $account): array
    {
        $blpaczkaOrderId = trim($blpaczkaOrderId);

        if (preg_match('/^\d{1,12}$/', $blpaczkaOrderId) !== 1) {
            throw new RuntimeException('Nieprawidłowy identyfikator przesyłki BLPaczka.');
        }

        $response = $this->post($account, 'cancelOrder.json', [
            'Order' => [
                'id' => (int) $blpaczkaOrderId,
            ],
        ]);

        return [
            'status' => 'cancelled',
            'shipment_id' => $blpaczkaOrderId,
            'message' => filled($response['message'] ?? null) ? (string) $response['message'] : null,
            'response_payload' => $response,
        ];
    }

    private function waybillNumber(string $blpaczkaOrderId, CourierAccount $account): ?string
    {
        try {
            $response = $this->post($account, 'getOrderDetails.json', [
                'Order' => [
                    'id' => (int) $blpaczkaOrderId,
                ],
            ]);
        } catch (RuntimeException) {
            return null;
        }

        foreach (['waybill_number', 'waybill_no', 'waybill', 'tracking_number'] as $key) {
            $value = trim((string) data_get($response, "data.Order.{$key}", ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function post(CourierAccount $account, string $endpoint, array $params): array
    {
        $response = Http::baseUrl((string) config('services.blpaczka.base_url'))
            ->acceptJson()
            ->timeout(20)
            ->post('/api/'.$endpoint, array_merge([
                'auth' => [
                    'login' => (string) $account->organization_id,
                    'api_key' => $account->apiToken(),
                ],
            ], $params));

        if ($response->failed()) {
            throw new RuntimeException("BLPaczka zwróciła błąd HTTP {$response->status()} dla {$endpoint}.");
        }

        $data = (array) $response->json();

        if (($data['success'] ?? false) !== true) {
            $message = trim((string) ($data['message'] ?? ''));

            throw new RuntimeException($message !== '' ? "BLPaczka: {$message}" : "BLPaczka odrzuciła żądanie {$endpoint}.");
        }

        return $data;
    }
}
