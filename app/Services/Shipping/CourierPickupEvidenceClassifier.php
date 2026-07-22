<?php

declare(strict_types=1);

namespace App\Services\Shipping;

final class CourierPickupEvidenceClassifier
{
    /**
     * ShipX statuses that prove the parcel entered the carrier network. This
     * includes delivery failures, collection-point expiry and returns because
     * each of them can only happen after the parcel left the sender.
     *
     * @var list<string>
     */
    private const INPOST_PICKUP_STATUSES = [
        'picked_up',
        'collected',
        'collected_from_sender',
        'taken_by_courier',
        'taken_by_courier_from_pok',
        'taken_by_courier_from_customer_service_point',
        'dispatched',
        'dispatched_by_sender',
        'dispatched_by_sender_to_pok',
        'adopted_at_source_branch',
        'sent_from_source_branch',
        'adopted_at_sorting_center',
        'sent_from_sorting_center',
        'adopted_at_target_branch',
        'sent_from_target_branch',
        'adopted_at_destination_branch',
        'sent_from_destination_branch',
        'in_transit',
        'readdressed',
        'out_for_delivery',
        'out_for_delivery_to_address',
        'ready_to_pickup',
        'ready_to_pickup_from_pok',
        'ready_to_pickup_from_pok_registered',
        'ready_to_pickup_from_branch',
        'pickup_reminder_sent',
        'pickup_reminder_sent_address',
        'pickup_time_expired',
        'avizo',
        'rejected_by_receiver',
        'undelivered',
        'undelivered_wrong_address',
        'undelivered_incomplete_address',
        'undelivered_unknown_receiver',
        'undelivered_cod_cash_receiver',
        'undelivered_no_mailbox',
        'undelivered_lack_of_access_letterbox',
        'undelivered_not_live_address',
        'delay_in_delivery',
        'oversized',
        'claimed',
        'returned_to_sender',
        'return_pickup_confirmation_to_sender',
        'stack_in_customer_service_point',
        'stack_parcel_pickup_time_expired',
        'unstack_from_customer_service_point',
        'courier_avizo_in_customer_service_point',
        'stack_in_box_machine',
        'stack_parcel_in_box_machine_pickup_time_expired',
        'unstack_from_box_machine',
        'redirect_to_box',
        'canceled_redirect_to_box',
        'delivered',
    ];

    /** @var list<string> */
    private const INPOST_SAFE_PRE_PICKUP_STATUSES = [
        '',
        'created',
        'offers_prepared',
        'offer_selected',
        'unconfirmed',
        'confirmed',
        'not_found',
        'no_events',
        'canceled',
        'cancelled',
        'error',
        'unsupported_provider',
    ];

    /** @var list<string> */
    private const INPOST_PICKUP_EVENT_CODES = [
        'FMD.1002',
        'FMD.1003',
        'FMD.1004',
        'FMD.1005',
        'FMD.1006',
        'FMD.1015',
        'FMD.9001',
        'FMD.9002',
        'FUL.1003',
        'INF.1001',
    ];

    /** @var list<string> */
    private const INPOST_SAFE_PRE_PICKUP_EVENT_CODES = [
        'CRE.1001',
        'CRE.1002',
        'FMD.1001',
        'FMD.1007',
        'FMD.1008',
        'FMD.1009',
        'FMD.1010',
        'FMD.1011',
        'FMD.1012',
        'FMD.1013',
        'FMD.1014',
        'FMD.1016',
        'FMD.1017',
        'FUL.1001',
        'FUL.1002',
        'EOL.9004',
        'EOL.9005',
    ];

    /** @var list<string> */
    private const BLPACZKA_PRE_PICKUP_PHRASES = [
        'zarejestrowano dane',
        'dane przesyłki otrzymane',
        'utworzono przesyłkę',
        'przyjęto zlecenie',
        'nadano numer',
        'oczekuje na nadanie',
        'oczekuje na odbiór przez kuriera',
        'gotowa do nadania',
        'odbiór zaplanowany',
        'label created',
        'shipment information received',
        'pickup scheduled',
        'ready for courier collection',
    ];

    /** @var list<string> */
    private const BLPACZKA_PICKUP_PHRASES = [
        'odebran',
        'nadana',
        'nadano',
        'przekazana kurierowi',
        'pobrana przez kuriera',
        'przyjęto przesyłkę',
        'przyjęta przez przewoźnika',
        'przyjęta w oddziale',
        'przyjęta w sortowni',
        'w drodze',
        'w transporcie',
        'sortow',
        'w oddziale',
        'w magazynie',
        'w terminalu',
        'wydano do doręczenia',
        'wydana do doręczenia',
        'doręcz',
        'dostarcz',
        'awizo',
        'zwrot do nadawcy',
        'zwrócona do nadawcy',
        'collected',
        'picked up',
        'in transit',
        'sorting',
        'at depot',
        'out for delivery',
        'delivery attempted',
        'returned to sender',
        'delivered',
    ];

    public static function inPostStatusProvesPickup(string $status, bool $unknownIsEvidence = false): bool
    {
        $status = mb_strtolower(trim($status));

        if (in_array($status, self::INPOST_PICKUP_STATUSES, true)) {
            return true;
        }

        if (self::inPostEventCodeProvesPickup($status)) {
            return true;
        }

        if (! $unknownIsEvidence) {
            return false;
        }

        return ! in_array($status, self::INPOST_SAFE_PRE_PICKUP_STATUSES, true)
            && ! self::isSafePrePickupEventCode($status);
    }

    public static function inPostEventCodeProvesPickup(string $eventCode): bool
    {
        $eventCode = mb_strtoupper(trim($eventCode));

        if (in_array($eventCode, self::INPOST_PICKUP_EVENT_CODES, true)) {
            return true;
        }

        if (str_starts_with($eventCode, 'MMD.')
            || str_starts_with($eventCode, 'LMD.')
            || str_starts_with($eventCode, 'RTS.')
            || str_starts_with($eventCode, 'HAN.')) {
            return true;
        }

        return str_starts_with($eventCode, 'EOL.')
            && ! in_array($eventCode, ['EOL.9004', 'EOL.9005'], true);
    }

    public static function blpaczkaStatusProvesPickup(string $status, bool $unknownIsEvidence = false): bool
    {
        $status = mb_strtolower(trim($status));

        if ($status === '') {
            return false;
        }

        [$statusWithoutPrePickupPhrases, $hasPrePickupPhrase] = self::withoutBLPaczkaPrePickupPhrases($status);

        foreach (self::BLPACZKA_PICKUP_PHRASES as $phrase) {
            if (str_contains($statusWithoutPrePickupPhrases, $phrase)) {
                return true;
            }
        }

        return $unknownIsEvidence && ! $hasPrePickupPhrase;
    }

    /**
     * Carrier adapters can start returning a new state before this application
     * is deployed. Reversal must then stop unless the state is explicitly
     * known to occur before the parcel leaves the sender.
     */
    public static function unknownStatusProvesPickup(string $status): bool
    {
        $status = mb_strtolower(trim($status));

        if ($status === ''
            || in_array($status, self::INPOST_SAFE_PRE_PICKUP_STATUSES, true)
            || self::isSafePrePickupEventCode($status)) {
            return false;
        }

        [, $hasBLPaczkaPrePickupPhrase] = self::withoutBLPaczkaPrePickupPhrases($status);

        return ! $hasBLPaczkaPrePickupPhrase;
    }

    private static function isSafePrePickupEventCode(string $eventCode): bool
    {
        return in_array(
            mb_strtoupper(trim($eventCode)),
            self::INPOST_SAFE_PRE_PICKUP_EVENT_CODES,
            true,
        );
    }

    /** @return array{0:string,1:bool} */
    private static function withoutBLPaczkaPrePickupPhrases(string $status): array
    {
        $withoutPrePickupPhrases = $status;
        $hasPrePickupPhrase = false;

        foreach (self::BLPACZKA_PRE_PICKUP_PHRASES as $phrase) {
            if (! str_contains($withoutPrePickupPhrases, $phrase)) {
                continue;
            }

            $hasPrePickupPhrase = true;
            $withoutPrePickupPhrases = str_replace($phrase, '', $withoutPrePickupPhrases);
        }

        return [$withoutPrePickupPhrases, $hasPrePickupPhrase];
    }
}
