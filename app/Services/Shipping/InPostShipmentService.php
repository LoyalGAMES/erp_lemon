<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Klient API InPost ShipX: tworzenie przesyłek i pobieranie etykiet
 * na wskazanym koncie nadawczym.
 */
final class InPostShipmentService
{
    private const LABEL_READY_STATUSES = ['confirmed', 'ready_to_pickup', 'oversized'];
    private const LABEL_POLL_ATTEMPTS = 10;
    private const LABEL_POLL_DELAY_MS = 800;

    /**
     * Tworzy przesyłkę i pobiera etykietę PDF.
     *
     * @return array{shipment_id:string,tracking_number:?string,contents:string,mime_type:string,response_payload:array<string,mixed>}
     */
    public function createShipmentWithLabel(ExternalOrder $order, CourierAccount $account): array
    {
        $shipment = $this->createShipment($order, $account);
        $shipmentId = (string) $shipment['id'];
        $shipment = $this->waitForConfirmation($account, $shipmentId);

        return [
            'shipment_id' => $shipmentId,
            'tracking_number' => filled($shipment['tracking_number'] ?? null) ? (string) $shipment['tracking_number'] : null,
            'contents' => $this->fetchLabel($account, $shipmentId),
            'mime_type' => 'application/pdf',
            'response_payload' => $shipment,
        ];
    }

    /**
     * Tworzy przesyłkę zwrotną (klient → magazyn) i pobiera etykietę PDF,
     * którą klient nakleja na paczkę. Odbiorcą jest adres zwrotów
     * skonfigurowany na koncie kurierskim.
     *
     * @return array{shipment_id:string,tracking_number:?string,contents:string,mime_type:string,response_payload:array<string,mixed>}
     */
    public function createReturnShipmentWithLabel(ReturnCase $returnCase, CourierAccount $account): array
    {
        $payload = $this->returnShipmentPayload($returnCase, $account);
        $shipment = $this->postShipment($account, $payload);
        $shipmentId = (string) $shipment['id'];
        $shipment = $this->waitForConfirmation($account, $shipmentId);

        return [
            'shipment_id' => $shipmentId,
            'tracking_number' => filled($shipment['tracking_number'] ?? null) ? (string) $shipment['tracking_number'] : null,
            'contents' => $this->fetchLabel($account, $shipmentId),
            'mime_type' => 'application/pdf',
            'response_payload' => $shipment,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createShipment(ExternalOrder $order, CourierAccount $account): array
    {
        return $this->postShipment($account, $this->shipmentPayload($order, $account));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postShipment(CourierAccount $account, array $payload): array
    {
        $response = $this->request($account)->post(
            "/v1/organizations/{$account->organization_id}/shipments",
            $payload,
        );

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response->json(), 'Nie udało się utworzyć przesyłki InPost (HTTP '.$response->status().').'));
        }

        $data = (array) $response->json();

        if (! filled($data['id'] ?? null)) {
            throw new RuntimeException('InPost nie zwrócił identyfikatora przesyłki.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function waitForConfirmation(CourierAccount $account, string $shipmentId): array
    {
        $shipment = [];

        for ($attempt = 0; $attempt < self::LABEL_POLL_ATTEMPTS; $attempt++) {
            $response = $this->request($account)->get("/v1/shipments/{$shipmentId}");

            if ($response->failed()) {
                throw new RuntimeException('Nie udało się odczytać statusu przesyłki InPost (HTTP '.$response->status().').');
            }

            $shipment = (array) $response->json();
            $status = (string) ($shipment['status'] ?? '');

            if (in_array($status, self::LABEL_READY_STATUSES, true)) {
                return $shipment;
            }

            if ($status === 'error') {
                throw new RuntimeException($this->errorMessage($shipment, 'InPost odrzucił przesyłkę.'));
            }

            usleep(self::LABEL_POLL_DELAY_MS * 1000);
        }

        throw new RuntimeException('Przesyłka InPost nie została potwierdzona na czas. Spróbuj pobrać etykietę ponownie za chwilę.');
    }

    private function fetchLabel(CourierAccount $account, string $shipmentId): string
    {
        $response = $this->request($account)
            ->withHeaders(['Accept' => 'application/pdf'])
            ->get("/v1/shipments/{$shipmentId}/label", ['format' => 'pdf']);

        if ($response->failed()) {
            throw new RuntimeException('Nie udało się pobrać etykiety InPost (HTTP '.$response->status().').');
        }

        return $response->body();
    }

    /**
     * @return array<string, mixed>
     */
    private function shipmentPayload(ExternalOrder $order, CourierAccount $account): array
    {
        $shipping = (array) ($order->shipping_data ?? []);
        $billing = (array) ($order->billing_data ?? []);
        $phone = preg_replace('/\D+/', '', (string) (data_get($shipping, 'phone') ?: data_get($billing, 'phone', ''))) ?? '';
        $phone = mb_substr($phone, -9);
        $email = (string) (data_get($billing, 'email') ?: data_get($shipping, 'email', ''));
        $targetPoint = $this->lockerTargetPoint($order);

        $receiver = [
            'first_name' => (string) (data_get($shipping, 'first_name') ?: data_get($billing, 'first_name', '')),
            'last_name' => (string) (data_get($shipping, 'last_name') ?: data_get($billing, 'last_name', '')),
            'email' => $email,
            'phone' => $phone,
        ];

        if (filled(data_get($shipping, 'company'))) {
            $receiver['company_name'] = (string) data_get($shipping, 'company');
        }

        $payload = [
            'receiver' => $receiver,
            'parcels' => [
                ['template' => $account->default_parcel_template ?: 'small'],
            ],
            'service' => $targetPoint !== null ? 'inpost_locker_standard' : 'inpost_courier_standard',
            'reference' => (string) ($order->external_number ?: $order->external_id),
            'comments' => 'Zamówienie ' . ($order->external_number ?: $order->external_id),
        ];

        if ($targetPoint !== null) {
            $payload['custom_attributes'] = [
                'target_point' => $targetPoint,
                'sending_method' => $account->sending_method ?: 'dispatch_order',
            ];
        } else {
            $payload['receiver']['address'] = [
                'street' => (string) (data_get($shipping, 'address_1') ?: data_get($billing, 'address_1', '')),
                'building_number' => (string) (data_get($shipping, 'address_2') ?: '1'),
                'city' => (string) (data_get($shipping, 'city') ?: data_get($billing, 'city', '')),
                'post_code' => (string) (data_get($shipping, 'postcode') ?: data_get($billing, 'postcode', '')),
                'country_code' => (string) (data_get($shipping, 'country') ?: data_get($billing, 'country', 'PL')),
            ];
        }

        return $payload;
    }

    /**
     * Payload przesyłki zwrotnej: odbiorcą jest magazyn (adres zwrotów konta),
     * klient nadaje paczkę w dowolnym Paczkomacie albo punkcie.
     *
     * @return array<string, mixed>
     */
    private function returnShipmentPayload(ReturnCase $returnCase, CourierAccount $account): array
    {
        $returnAddress = (array) data_get($account->metadata, 'return', []);
        $name = trim((string) ($returnAddress['name'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($returnAddress['phone'] ?? '')) ?? '';
        $email = trim((string) ($returnAddress['email'] ?? ''));

        if ($name === '' || $phone === '' || $email === '') {
            throw new RuntimeException("Konto {$account->name} nie ma skonfigurowanego adresu zwrotów (nazwa, telefon, e-mail). Uzupełnij go w Ustawienia → Wysyłki.");
        }

        $receiver = [
            'company_name' => $name,
            'email' => $email,
            'phone' => mb_substr($phone, -9),
        ];

        $targetPoint = strtoupper(trim((string) ($returnAddress['target_point'] ?? '')));

        $payload = [
            'receiver' => $receiver,
            'parcels' => [
                ['template' => $account->default_parcel_template ?: 'small'],
            ],
            'service' => $targetPoint !== '' ? 'inpost_locker_standard' : 'inpost_courier_standard',
            'reference' => 'ZWROT ' . $returnCase->number,
            'comments' => 'Zwrot ' . $returnCase->number,
            'custom_attributes' => [
                'sending_method' => 'parcel_locker',
            ],
        ];

        if ($targetPoint !== '') {
            $payload['custom_attributes']['target_point'] = $targetPoint;
        } else {
            $street = trim((string) ($returnAddress['street'] ?? ''));
            $city = trim((string) ($returnAddress['city'] ?? ''));
            $postCode = trim((string) ($returnAddress['post_code'] ?? ''));

            if ($street === '' || $city === '' || $postCode === '') {
                throw new RuntimeException("Konto {$account->name} nie ma Paczkomatu zwrotów ani pełnego adresu magazynu. Uzupełnij konfigurację w Ustawienia → Wysyłki.");
            }

            $payload['receiver']['address'] = [
                'street' => $street,
                'building_number' => trim((string) ($returnAddress['building_number'] ?? '')) ?: '1',
                'city' => $city,
                'post_code' => $postCode,
                'country_code' => trim((string) ($returnAddress['country_code'] ?? '')) ?: 'PL',
            ];
        }

        return $payload;
    }

    /**
     * Wyszukuje identyfikator Paczkomatu w danych zamówienia WooCommerce.
     */
    private function lockerTargetPoint(ExternalOrder $order): ?string
    {
        $candidates = [
            data_get($order->raw_payload, 'sempre_erp_target_point'),
            data_get($order->shipping_data, 'paczkomat'),
            data_get($order->shipping_data, 'target_point'),
        ];

        foreach ((array) data_get($order->raw_payload, 'meta_data', []) as $meta) {
            $key = mb_strtolower((string) ($meta['key'] ?? ''));

            if (str_contains($key, 'paczkomat') || str_contains($key, 'target_point') || str_contains($key, 'parcel_machine')) {
                $candidates[] = $meta['value'] ?? null;
            }
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Z]{2,4}[0-9]{2,5}[A-Z0-9]*$/i', trim($candidate)) === 1) {
                return strtoupper(trim($candidate));
            }
        }

        return null;
    }

    private function request(CourierAccount $account): PendingRequest
    {
        return Http::baseUrl((string) config('services.inpost.base_url'))
            ->withToken($account->apiToken())
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function errorMessage(?array $body, string $fallback): string
    {
        $details = collect((array) data_get($body, 'details', []))
            ->map(fn ($messages, $field): string => $field . ': ' . json_encode($messages, JSON_UNESCAPED_UNICODE))
            ->implode('; ');

        $message = trim(implode(' ', array_filter([
            (string) data_get($body, 'message', ''),
            (string) data_get($body, 'error', ''),
            $details,
        ])));

        return $message !== '' ? $message : $fallback;
    }
}
