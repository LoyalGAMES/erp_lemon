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
        $eventPickupEvidence = CourierPickupEvidenceClassifier::inPostEventCodeProvesPickup($rootEventCode);

        if ($eventPickupEvidence) {
            $pickedUpAt = (string) ($data['timestamp'] ?? $data['datetime'] ?? '') ?: null;
            $status = $status !== '' ? $status : $rootEventCode;
        }

        foreach ((array) ($data['tracking_details'] ?? []) as $event) {
            if ((string) ($event['status'] ?? '') === 'delivered') {
                $deliveredAt = (string) ($event['datetime'] ?? '') ?: null;
            }

            if (CourierPickupEvidenceClassifier::inPostStatusProvesPickup((string) ($event['status'] ?? ''))) {
                $pickedUpAt ??= (string) ($event['datetime'] ?? '') ?: null;
                $eventPickupEvidence = true;
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

            if (CourierPickupEvidenceClassifier::inPostEventCodeProvesPickup($eventCode)) {
                $eventPickupEvidence = true;
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
            'picked_up' => CourierPickupEvidenceClassifier::inPostStatusProvesPickup($status)
                || $eventPickupEvidence
                || $pickedUpAt !== null,
            'picked_up_at' => $pickedUpAt,
            'delivered' => $status === 'delivered' || $deliveredAt !== null,
            'delivered_at' => $deliveredAt,
        ];
    }
}
