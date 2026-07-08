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
                'printer_type' => (string) data_get($account->metadata, 'printer_type', 'A4'),
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
     * @return array{status:string,picked_up:bool,picked_up_at:?string,events:list<array<string,mixed>>}
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

        $latest = $events->last();

        return [
            'status' => is_array($latest)
                ? (string) ($latest['status'] ?? $latest['name'] ?? $latest['description'] ?? 'unknown')
                : 'no_events',
            'picked_up' => $pickedUpEvent !== null,
            'picked_up_at' => is_array($pickedUpEvent)
                ? (string) ($pickedUpEvent['date'] ?? $pickedUpEvent['datetime'] ?? $pickedUpEvent['created'] ?? '') ?: null
                : null,
            'events' => $events->all(),
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
     * @param array<string, mixed> $params
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
