<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\ShippingLabel;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Packing\PackingFulfillmentService;
use Throwable;

/**
 * Odpytuje API kurierów o status paczek spakowanych zamówień i oznacza
 * zamówienia jako wysłane, gdy paczka została fizycznie odebrana z magazynu.
 */
final class CourierPickupTrackingService
{
    public function __construct(
        private readonly InPostTrackingService $tracking,
        private readonly BLPaczkaShipmentService $blpaczka,
        private readonly ShippingProviderResolver $providers,
        private readonly PackingFulfillmentService $fulfillment,
        private readonly CustomerCommunicationService $communication,
    ) {}

    /**
     * @return array{checked:int,picked_up:int,delivered:int,orders:int,warnings:list<string>}
     */
    public function trackPackedOrders(int $limit = 50, bool $force = false): array
    {
        $orderIds = ExternalOrder::query()
            ->whereHas('packingTasks', fn ($query) => $query->whereIn('status', ['packed', 'shipped']))
            ->whereDoesntHave('packingTasks', fn ($query) => $query->whereNotIn('status', ['packed', 'shipped', 'cancelled']))
            ->pluck('id');

        $labelsQuery = ShippingLabel::query()
            ->with(['order', 'courierAccount'])
            ->shipments()
            ->whereIn('external_order_id', $orderIds)
            ->whereIn('status', ['generated', 'picked_up']);

        if (! $force) {
            $labelsQuery->where(function ($query): void {
                $query->whereNull('next_tracking_check_at')
                    ->orWhere('next_tracking_check_at', '<=', now());
            });
        }

        $labelsQuery->where(function ($query): void {
            $query
                ->where(function ($query): void {
                    $query->whereNotNull('tracking_number')->where('tracking_number', '!=', '');
                })
                ->orWhere(function ($query): void {
                    $query->whereNotNull('label_number')
                        ->where('label_number', '!=', '');
                });
        });

        if (! $force) {
            $labelsQuery
                ->orderByRaw('case when next_tracking_check_at is null then 0 else 1 end')
                ->orderBy('next_tracking_check_at')
                ->orderBy('tracking_checked_at')
                ->orderBy('generated_at');
        } else {
            $labelsQuery
                ->orderByRaw('case when tracking_checked_at is null then 0 else 1 end')
                ->orderBy('tracking_checked_at')
                ->orderBy('generated_at');
        }

        $labels = $labelsQuery
            ->limit($limit)
            ->get()
            ->values();

        $checked = 0;
        $pickedUp = 0;
        $shippedOrders = 0;
        $delivered = 0;
        $warnings = [];

        foreach ($labels as $label) {
            $claim = ShippingLabel::query()
                ->whereKey($label->id)
                ->whereIn('status', ['generated', 'picked_up']);

            if (! $force) {
                $claim->where(function ($query): void {
                    $query->whereNull('next_tracking_check_at')
                        ->orWhere('next_tracking_check_at', '<=', now());
                });
            }

            $claimed = $claim
                ->update(['next_tracking_check_at' => now()->addMinutes(10)]);

            if ($claimed !== 1) {
                continue;
            }

            $label->refresh();

            if (! in_array($label->status, ['generated', 'picked_up'], true)) {
                continue;
            }

            $order = $label->order;

            if (! $order instanceof ExternalOrder) {
                continue;
            }

            $provider = $this->providers->providerKey($label);

            if (! in_array($provider, ['inpost', 'blpaczka'], true)) {
                $message = sprintf(
                    'Pominięto śledzenie przesyłki %s: przewoźnik %s nie ma skonfigurowanego adaptera.',
                    $label->trackingIdentifier() ?? '#'.$label->id,
                    $provider ?: 'nieustalony',
                );
                $label->update([
                    'tracking_status' => 'unsupported_provider',
                    'tracking_checked_at' => now(),
                    'next_tracking_check_at' => now()->addDay(),
                    'tracking_last_error' => $message,
                ]);
                $warnings[] = $message;

                continue;
            }

            try {
                $status = $this->statusForLabel($label);
            } catch (Throwable $exception) {
                $warnings[] = $exception->getMessage();
                $attempts = max(0, (int) $label->tracking_attempts) + 1;
                $label->update([
                    'tracking_checked_at' => now(),
                    'next_tracking_check_at' => now()->addMinutes($this->retryDelayMinutes($attempts)),
                    'tracking_attempts' => $attempts,
                    'tracking_last_error' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($status === null) {
                $label->update([
                    'tracking_checked_at' => now(),
                    'next_tracking_check_at' => now()->addMinutes(30),
                    'tracking_last_error' => 'Brak danych konta lub numeru potrzebnego do śledzenia przesyłki.',
                ]);

                continue;
            }

            $checked++;
            $wasPickedUp = $label->status === 'picked_up';
            $isDelivered = (bool) ($status['delivered'] ?? false);

            $label->update([
                'tracking_status' => $status['status'],
                'tracking_checked_at' => now(),
                'next_tracking_check_at' => $isDelivered
                    ? null
                    : ($status['picked_up'] ? now()->addHours(2) : now()->addMinutes(5)),
                'tracking_attempts' => 0,
                'tracking_last_error' => null,
                'response_payload' => array_merge((array) $label->response_payload, [
                    'tracking' => [
                        'status' => $status['status'],
                        'checked_at' => now()->toISOString(),
                        'delivered_at' => $status['delivered_at'] ?? null,
                    ],
                ]),
            ]);

            if ($wasPickedUp) {
                if ($isDelivered) {
                    $label->update(['status' => 'delivered', 'next_tracking_check_at' => null]);
                    $this->communication->sendOrderStatus($order, 'order_delivered', [
                        'tracking_number' => $label->trackingIdentifier(),
                        'tracking_url' => $this->providers->trackingUrl($label),
                        'courier' => $this->providers->courierName($label),
                        'delivered_at' => $status['delivered_at'] ?? now()->toISOString(),
                    ]);
                    $delivered++;
                }

                continue;
            }

            if (! $status['picked_up']) {
                continue;
            }

            $pickedUp++;

            $pickedUpAt = $status['picked_up_at'] ?? now()->toISOString();
            $result = $this->fulfillment->markOrderPickedUpByCourier($order, [
                'source' => $provider === 'blpaczka' ? 'blpaczka_tracking' : 'inpost_tracking',
                'tracking_number' => $label->tracking_number ?: $label->label_number,
                'tracking_status' => $status['status'],
                'picked_up_at' => $pickedUpAt,
            ]);

            $stillWaitingForCourier = $result['tasks'] === 0
                && PackingTask::query()
                    ->where('external_order_id', $order->id)
                    ->where('status', 'packed')
                    ->exists();

            if ($stillWaitingForCourier) {
                $label->update([
                    'status' => 'generated',
                    'next_tracking_check_at' => now()->addMinutes(5),
                    'tracking_last_error' => 'Status odbioru został potwierdzony, ale aktualizacja zamówienia jest jeszcze przetwarzana. Próba zostanie ponowiona.',
                ]);
                $warnings = array_merge($warnings, $result['warnings']);

                continue;
            }

            $label->update([
                'status' => $isDelivered ? 'delivered' : 'picked_up',
                'picked_up_at' => $pickedUpAt,
                'next_tracking_check_at' => $isDelivered ? null : now()->addHours(2),
            ]);

            ShippingLabel::query()
                ->shipments()
                ->where('external_order_id', $order->id)
                ->where('status', 'generated')
                ->whereKeyNot($label->id)
                ->update([
                    'status' => 'superseded',
                    'next_tracking_check_at' => null,
                    'tracking_last_error' => 'Zduplikowana etykieta zastąpiona przesyłką odebraną przez kuriera.',
                ]);

            if ($result['tasks'] > 0) {
                $shippedOrders++;
            }

            if ($isDelivered) {
                $this->communication->sendOrderStatus($order, 'order_delivered', [
                    'tracking_number' => $label->trackingIdentifier(),
                    'tracking_url' => $this->providers->trackingUrl($label),
                    'courier' => $this->providers->courierName($label),
                    'delivered_at' => $status['delivered_at'] ?? now()->toISOString(),
                ]);
                $delivered++;
            }

            $warnings = array_merge($warnings, $result['warnings']);
        }

        return [
            'checked' => $checked,
            'picked_up' => $pickedUp,
            'delivered' => $delivered,
            'orders' => $shippedOrders,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{status:string,picked_up:bool,picked_up_at:?string,delivered?:bool,delivered_at?:?string}|null
     */
    private function statusForLabel(ShippingLabel $label): ?array
    {
        $provider = $this->providers->providerKey($label);

        if ($provider === 'blpaczka') {
            $account = $label->courierAccount ?? CourierAccount::defaultFor('blpaczka');

            if ($account === null || filled($label->label_number) === false) {
                return null;
            }

            return $this->blpaczka->trackingStatus((string) $label->label_number, $account);
        }

        if ($provider === 'inpost') {
            return $this->tracking->trackingStatus((string) $label->trackingIdentifier());
        }

        return null;
    }

    private function retryDelayMinutes(int $attempts): int
    {
        return min(360, 5 * (2 ** min(6, max(0, $attempts - 1))));
    }
}
