<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\WarehouseDocument;
use Illuminate\Database\Eloquent\Builder;

final class OrderFulfillmentStatusService
{
    public function latestWz(ExternalOrder $order): ?WarehouseDocument
    {
        return $this->wzDocumentsForOrder($order)
            ->orderByRaw("case when status = 'posted' then 0 else 1 end")
            ->latest()
            ->first();
    }

    public function hasPostedWz(ExternalOrder $order): bool
    {
        return $this->wzDocumentsForOrder($order)
            ->where('status', 'posted')
            ->exists();
    }

    public function wzDocumentsForOrder(ExternalOrder $order): Builder
    {
        $canUseUnscopedLegacyDocuments = $this->canAssociateUnscopedLegacyDocuments($order);

        return WarehouseDocument::query()
            ->where('type', 'WZ')
            ->where(function (Builder $documents) use ($order, $canUseUnscopedLegacyDocuments): void {
                $documents->where(function (Builder $scoped) use ($order): void {
                    $scoped->where('metadata->sales_channel_id', $order->sales_channel_id);
                    $this->constrainOrderIdentity($scoped, $order);
                });

                if ($canUseUnscopedLegacyDocuments) {
                    $documents->orWhere(function (Builder $legacy) use ($order): void {
                        $this->constrainUnscopedLegacy($legacy);
                        $this->constrainOrderIdentity($legacy, $order);
                    });
                }
            });
    }

    /**
     * A document without a channel can only be associated when neither the WooCommerce
     * id nor the visible order number can point at another locally imported order.
     */
    public function canAssociateUnscopedLegacyDocuments(ExternalOrder $order): bool
    {
        $identities = collect([
            trim((string) $order->external_id),
            trim((string) $order->external_number),
        ])->filter()->unique()->values()->all();

        if ($identities === []) {
            return false;
        }

        return ! ExternalOrder::query()
            ->whereKeyNot($order->getKey())
            ->where(function (Builder $candidate) use ($identities): void {
                $candidate
                    ->whereIn('external_id', $identities)
                    ->orWhereIn('external_number', $identities);
            })
            ->exists();
    }

    /**
     * Raw legacy candidates are intentionally exposed separately. Callers must verify
     * canAssociateUnscopedLegacyDocuments() and candidate uniqueness before adoption.
     */
    public function unscopedLegacyWzCandidatesForOrder(ExternalOrder $order): Builder
    {
        $query = WarehouseDocument::query()->where('type', 'WZ');
        $this->constrainUnscopedLegacy($query);
        $this->constrainOrderIdentity($query, $order);

        return $query;
    }

    private function constrainUnscopedLegacy(Builder $query): void
    {
        $query
            ->where(function (Builder $key): void {
                $key
                    ->whereNull('order_fulfillment_key')
                    ->orWhere('order_fulfillment_key', '');
            })
            ->where(function (Builder $channel): void {
                $channel
                    ->whereNull('metadata->sales_channel_id')
                    ->orWhere('metadata->sales_channel_id', '');
            });
    }

    private function constrainOrderIdentity(Builder $query, ExternalOrder $order): void
    {
        $externalId = trim((string) $order->external_id);
        $externalNumber = trim((string) $order->external_number);
        $externalReference = $externalNumber !== '' ? $externalNumber : $externalId;

        $query->where(function (Builder $identity) use ($externalId, $externalNumber, $externalReference): void {
            if ($externalId !== '') {
                $identity->where('metadata->external_order_id', $externalId);
            }

            if ($externalNumber !== '') {
                $identity->orWhere(function (Builder $number) use ($externalNumber): void {
                    $this->constrainMissingMetadataValue($number, 'metadata->external_order_id');
                    $number->where('metadata->external_order_number', $externalNumber);
                });
            }

            if ($externalReference !== '') {
                $identity->orWhere(function (Builder $reference) use ($externalReference): void {
                    $this->constrainMissingMetadataValue($reference, 'metadata->external_order_id');
                    $this->constrainMissingMetadataValue($reference, 'metadata->external_order_number');
                    $reference->where('external_reference', $externalReference);
                });
            }
        });
    }

    private function constrainMissingMetadataValue(Builder $query, string $path): void
    {
        $query->where(function (Builder $missing) use ($path): void {
            $missing
                ->whereNull($path)
                ->orWhere($path, '');
        });
    }
}
