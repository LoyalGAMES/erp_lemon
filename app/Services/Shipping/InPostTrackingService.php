<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Publiczne API śledzenia InPost — sprawdza, czy paczka została
 * fizycznie odebrana od nadawcy (kurier / nadanie w Paczkomacie).
 */
final class InPostTrackingService
{
    /**
     * Statusy ShipX oznaczające, że paczka opuściła magazyn nadawcy.
     */
    private const PICKED_UP_STATUSES = [
        'collected_from_sender',
        'taken_by_courier',
        'dispatched_by_sender',
        'adopted_at_source_branch',
        'sent_from_source_branch',
        'adopted_at_sorting_center',
        'out_for_delivery',
        'ready_to_pickup',
        'delivered',
    ];

    /**
     * Kod zdarzenia w nowszym Tracking API InPost: przesyłka odebrana przez kuriera.
     */
    private const PICKED_UP_EVENT_CODES = [
        'FMD.1002',
        'FMD.1003',
        'FMD.1004',
        'FMD.1005',
        'FMD.1006',
    ];

    /**
     * @return array{status:string,picked_up:bool,picked_up_at:?string,delivered:bool,delivered_at:?string}
     */
    public function trackingStatus(string $trackingNumber): array
    {
        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '') {
            throw new RuntimeException('Brak numeru śledzenia.');
        }

        $response = Http::baseUrl((string) config('services.inpost.base_url'))
            ->acceptJson()
            ->timeout(15)
            ->get("/v1/tracking/{$trackingNumber}");

        if ($response->status() === 404) {
            return ['status' => 'not_found', 'picked_up' => false, 'picked_up_at' => null, 'delivered' => false, 'delivered_at' => null];
        }

        if ($response->failed()) {
            throw new RuntimeException("Śledzenie InPost {$trackingNumber} nie powiodło się (HTTP {$response->status()}).");
        }

        $data = (array) $response->json();
        $status = (string) ($data['status'] ?? '');
        $pickedUpAt = null;
        $deliveredAt = null;
        $rootEventCode = (string) ($data['eventCode'] ?? $data['event_code'] ?? '');

        if (in_array($rootEventCode, self::PICKED_UP_EVENT_CODES, true)) {
            $pickedUpAt = (string) ($data['timestamp'] ?? $data['datetime'] ?? '') ?: null;
            $status = $status !== '' ? $status : $rootEventCode;
        }

        foreach ((array) ($data['tracking_details'] ?? []) as $event) {
            if ((string) ($event['status'] ?? '') === 'delivered') {
                $deliveredAt = (string) ($event['datetime'] ?? '') ?: null;
            }

            if (in_array((string) ($event['status'] ?? ''), self::PICKED_UP_STATUSES, true)) {
                $pickedUpAt ??= (string) ($event['datetime'] ?? '') ?: null;
            }
        }

        foreach ((array) ($data['events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }

            $eventCode = (string) (
                $event['event_code']
                ?? $event['eventCode']
                ?? $event['code']
                ?? ''
            );

            if (in_array($eventCode, self::PICKED_UP_EVENT_CODES, true)) {
                $pickedUpAt = (string) (
                    $event['datetime']
                    ?? $event['timestamp']
                    ?? $event['event_timestamp']
                    ?? ''
                ) ?: $pickedUpAt;
                break;
            }
        }

        return [
            'status' => $status,
            'picked_up' => in_array($status, self::PICKED_UP_STATUSES, true)
                || in_array($status, self::PICKED_UP_EVENT_CODES, true)
                || $pickedUpAt !== null,
            'picked_up_at' => $pickedUpAt,
            'delivered' => $status === 'delivered' || $deliveredAt !== null,
            'delivered_at' => $deliveredAt,
        ];
    }
}
