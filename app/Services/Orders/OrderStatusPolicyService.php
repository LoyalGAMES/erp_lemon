<?php

declare(strict_types=1);

namespace App\Services\Orders;

final class OrderStatusPolicyService
{
    /**
     * Statusy WooCommerce, które w ERP oznaczają realną realizację magazynową.
     *
     * Pending/on-hold rezerwują stan, ale nie uruchamiają WZ ani pakowania.
     *
     * @return list<string>
     */
    public function fulfillmentStatuses(): array
    {
        return ['processing'];
    }

    /**
     * @return list<string>
     */
    public function packingReadyStatuses(): array
    {
        return $this->fulfillmentStatuses();
    }

    /**
     * @return list<string>
     */
    public function reservationStatuses(): array
    {
        return ['pending', 'processing', 'on-hold'];
    }

    public function isFulfillmentStatus(?string $status): bool
    {
        return in_array($this->normalize($status), $this->fulfillmentStatuses(), true);
    }

    public function shouldReserve(?string $status): bool
    {
        return in_array($this->normalize($status), $this->reservationStatuses(), true);
    }

    public function shouldCreateImportDocuments(?string $status): bool
    {
        return $this->isFulfillmentStatus($status);
    }

    private function normalize(?string $status): string
    {
        return mb_strtolower(trim((string) $status));
    }
}
