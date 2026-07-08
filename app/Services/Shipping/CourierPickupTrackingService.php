<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\ShippingLabel;
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
        private readonly PackingFulfillmentService $fulfillment,
    ) {
    }

    /**
     * @return array{checked:int,picked_up:int,orders:int,warnings:list<string>}
     */
    public function trackPackedOrders(int $limit = 50): array
    {
        $orderIds = PackingTask::query()
            ->where('status', 'packed')
            ->distinct()
            ->pluck('external_order_id');

        $labels = ShippingLabel::query()
            ->with(['order', 'courierAccount'])
            ->whereIn('external_order_id', $orderIds)
            ->where('status', 'generated')
            ->where(function ($query): void {
                $query
                    ->where(function ($query): void {
                        $query->whereNotNull('tracking_number')->where('tracking_number', '!=', '');
                    })
                    ->orWhere(function ($query): void {
                        $query->where('provider', 'blpaczka')
                            ->whereNotNull('label_number')
                            ->where('label_number', '!=', '');
                    });
            })
            ->orderBy('generated_at')
            ->limit($limit)
            ->get()
            ->unique('external_order_id')
            ->values();

        $checked = 0;
        $pickedUp = 0;
        $shippedOrders = 0;
        $warnings = [];

        foreach ($labels as $label) {
            $order = $label->order;

            if (! $order instanceof ExternalOrder) {
                continue;
            }

            try {
                $status = $this->statusForLabel($label);
            } catch (Throwable $exception) {
                $warnings[] = $exception->getMessage();

                continue;
            }

            if ($status === null) {
                continue;
            }

            $checked++;

            $label->update([
                'response_payload' => array_merge((array) $label->response_payload, [
                    'tracking' => [
                        'status' => $status['status'],
                        'checked_at' => now()->toISOString(),
                    ],
                ]),
            ]);

            if (! $status['picked_up']) {
                continue;
            }

            $pickedUp++;
            $label->update(['status' => 'picked_up']);

            $result = $this->fulfillment->markOrderPickedUpByCourier($order, [
                'source' => $label->provider === 'blpaczka' ? 'blpaczka_tracking' : 'inpost_tracking',
                'tracking_number' => $label->tracking_number ?: $label->label_number,
                'tracking_status' => $status['status'],
                'picked_up_at' => $status['picked_up_at'] ?? now()->toISOString(),
            ]);

            if ($result['tasks'] > 0) {
                $shippedOrders++;
            }

            $warnings = array_merge($warnings, $result['warnings']);
        }

        return [
            'checked' => $checked,
            'picked_up' => $pickedUp,
            'orders' => $shippedOrders,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{status:string,picked_up:bool,picked_up_at:?string}|null
     */
    private function statusForLabel(ShippingLabel $label): ?array
    {
        if ($label->provider === 'blpaczka') {
            $account = $label->courierAccount ?? \App\Models\CourierAccount::defaultFor('blpaczka');

            if ($account === null || filled($label->label_number) === false) {
                return null;
            }

            return $this->blpaczka->trackingStatus((string) $label->label_number, $account);
        }

        return $this->tracking->trackingStatus((string) $label->tracking_number);
    }
}
