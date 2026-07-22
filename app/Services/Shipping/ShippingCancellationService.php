<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\PrintJob;
use App\Models\ShippingLabel;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ShippingCancellationService
{
    private const LOCK_SECONDS = 900;

    private const WAIT_SECONDS = 15;

    /** @var list<string> */
    private const REMOTELY_CANCELLABLE_INPOST_STATUSES = ['created', 'offers_prepared'];

    /** @var list<string> */
    private const PRINT_JOB_STATUSES_TO_CANCEL = ['pending', 'reserved', 'failed'];

    public function __construct(
        private readonly InPostShipmentService $inpost,
        private readonly BLPaczkaShipmentService $blpaczka,
        private readonly ShippingProviderResolver $providers,
    ) {}

    /**
     * Anuluje aktywne etykiety wysyłkowe dla całej rodziny splitów zamówienia.
     * Nie obejmuje etykiet zwrotu ani wymiany.
     *
     * @return array{
     *     cancelled_label_ids:list<int>,
     *     cancelled_print_job_ids:list<int>,
     *     manual_required:list<array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}>
     * }
     */
    public function cancelForOrder(
        ExternalOrder $order,
        ?string $operationUuid = null,
        ?string $reason = null,
    ): array {
        $familyOrderIds = $this->familyOrderIds($order);
        $operationUuid = $this->nullableTrimmed($operationUuid, 100);
        $reason = $this->nullableTrimmed($reason, 1000);

        try {
            return $this->withShippingLocks(
                $familyOrderIds,
                0,
                fn (): array => $this->cancelWhileLocked(
                    $familyOrderIds,
                    $operationUuid,
                    $reason,
                    null,
                ),
            );
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Etykieta dla tego zamówienia jest właśnie generowana lub anulowana. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * Wariant dla operacji, która już trzyma blokady
     * shipping-label-order-{id} całej rodziny w rosnącej kolejności ID.
     * Pozwala utrzymać te same blokady od anulowania przesyłek aż do
     * atomowego scalenia zamówień, bez ponownego (niereentrantnego) locka.
     *
     * @param  list<int>  $familyOrderIds
     * @return array{
     *     cancelled_label_ids:list<int>,
     *     cancelled_print_job_ids:list<int>,
     *     manual_required:list<array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}>
     * }
     */
    public function cancelForOrderIdsWhileLocked(
        array $familyOrderIds,
        ?string $operationUuid = null,
        ?string $reason = null,
        ?CarbonInterface $createdAfter = null,
    ): array {
        $familyOrderIds = collect($familyOrderIds)
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->filter(fn (int $orderId): bool => $orderId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($familyOrderIds === []) {
            throw new RuntimeException('Nie wskazano rodziny zamówień do anulowania przesyłek.');
        }

        return $this->cancelWhileLocked(
            $familyOrderIds,
            $this->nullableTrimmed($operationUuid, 100),
            $this->nullableTrimmed($reason, 1000),
            $createdAfter,
        );
    }

    /**
     * Anuluje pojedynczą przesyłkę i usuwa jej lokalny rekord oraz plik.
     * Gdy przewoźnik wymaga ręcznej interwencji, etykieta pozostaje zapisana
     * jako anulowana, aby nie utracić informacji potrzebnych do wyjaśnienia.
     *
     * @return array{deleted:bool,manual_required:list<array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}>}
     */
    public function deleteLabel(ShippingLabel $label): array
    {
        if ($label->purpose !== 'shipment' || ! $label->external_order_id) {
            throw new RuntimeException('Można usunąć wyłącznie etykietę przesyłki powiązanej z zamówieniem.');
        }

        try {
            return Cache::lock('shipping-label-order-'.$label->external_order_id, self::LOCK_SECONDS)
                ->block(self::WAIT_SECONDS, function () use ($label): array {
                    $label = ShippingLabel::query()->with('courierAccount')->findOrFail($label->id);
                    $this->assertNotDispatched($label);
                    $printResult = $this->cancelPrintJobs(
                        new Collection([$label]),
                        null,
                        'Usunięcie etykiety z zamówienia',
                    );
                    $remoteResult = $label->status === 'cancelled'
                        ? $this->alreadyCancelledResult($label)
                        : $this->cancelRemoteShipment($label);
                    $manualRequired = $printResult['manual_required'];

                    if (($remoteResult['warning'] ?? null) !== null) {
                        $manualRequired[] = $remoteResult['warning'];
                    }

                    $this->persistLocalCancellation(
                        $label,
                        (array) $remoteResult['audit'],
                        null,
                        'Usunięcie etykiety z zamówienia',
                    );

                    if ($manualRequired !== []) {
                        return ['deleted' => false, 'manual_required' => $manualRequired];
                    }

                    $disk = filled($label->disk) ? (string) $label->disk : 'local';
                    $path = (string) $label->path;
                    $label->delete();

                    if ($path !== '') {
                        Storage::disk($disk)->delete($path);
                    }

                    return ['deleted' => true, 'manual_required' => []];
                });
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Etykieta jest właśnie generowana lub anulowana. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<int>  $familyOrderIds
     * @return array{
     *     cancelled_label_ids:list<int>,
     *     cancelled_print_job_ids:list<int>,
     *     manual_required:list<array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}>
     * }
     */
    private function cancelWhileLocked(
        array $familyOrderIds,
        ?string $operationUuid,
        ?string $reason,
        ?CarbonInterface $createdAfter,
    ): array {
        /** @var Collection<int, ShippingLabel> $labels */
        $labels = ShippingLabel::query()
            ->with('courierAccount')
            ->shipments()
            ->whereIn('external_order_id', $familyOrderIds)
            ->when(
                $createdAfter instanceof CarbonInterface,
                fn ($query) => $query->where('created_at', '>=', $createdAfter),
            )
            ->orderBy('id')
            ->get();

        // Preflight całej rodziny musi zakończyć się przed pierwszym wywołaniem
        // API lub zapisem lokalnym. Nie wolno anulować paczki już odebranej.
        foreach ($labels as $label) {
            $this->assertNotDispatched($label);
        }

        // Najpierw wyłączamy całą kolejkę. Wywołanie API przewoźnika może trwać
        // kilkanaście sekund i w tym czasie listener nie może pobrać etykiety.
        $printResult = $this->cancelPrintJobs($labels, $operationUuid, $reason);
        $cancelledLabelIds = [];
        $cancelledPrintJobIds = $printResult['cancelled_print_job_ids'];
        $manualRequired = [];

        foreach ($labels as $label) {
            $remoteResult = $label->status === 'cancelled'
                ? $this->alreadyCancelledResult($label)
                : $this->cancelRemoteShipment($label);

            if (($remoteResult['warning'] ?? null) !== null) {
                $manualRequired[] = $remoteResult['warning'];
            }

            $labelCancelled = $this->persistLocalCancellation(
                $label,
                (array) $remoteResult['audit'],
                $operationUuid,
                $reason,
            );

            if ($labelCancelled) {
                $cancelledLabelIds[] = (int) $label->id;
            }
        }

        // Carrier uncertainty is the primary warning shown to the operator.
        // Print warnings follow it and are deduplicated per physical label.
        $manualRequired = collect([
            ...$manualRequired,
            ...$printResult['manual_required'],
        ])->unique(fn (array $warning): string => implode(':', [
            (string) ($warning['label_id'] ?? ''),
            (string) ($warning['code'] ?? ''),
        ]))->values()->all();

        return [
            'cancelled_label_ids' => array_values(array_unique($cancelledLabelIds)),
            'cancelled_print_job_ids' => array_values(array_unique($cancelledPrintJobIds)),
            'manual_required' => $manualRequired,
        ];
    }

    /**
     * @return array{
     *     audit:array<string,mixed>,
     *     warning:?array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}
     * }
     */
    private function cancelRemoteShipment(ShippingLabel $label): array
    {
        $provider = $this->providers->providerKey($label);
        $shipmentId = $this->nullableTrimmed((string) $label->label_number, 120);
        $account = $label->courierAccount;

        if ($provider === 'inpost') {
            $remoteStatus = $this->inPostRemoteStatus($label);

            if (in_array($remoteStatus, ['cancelled', 'canceled'], true)) {
                return [
                    'audit' => [
                        'status' => 'already_cancelled',
                        'provider' => 'inpost',
                        'shipment_id' => $shipmentId,
                        'remote_status' => $remoteStatus,
                    ],
                    'warning' => null,
                ];
            }

            if ($remoteStatus !== null
                && ! in_array($remoteStatus, self::REMOTELY_CANCELLABLE_INPOST_STATUSES, true)) {
                return $this->manualResult(
                    $label,
                    'inpost',
                    $shipmentId,
                    'remote_not_cancellable',
                    "Przesyłka InPost {$shipmentId} ma status {$remoteStatus} i nie może już zostać anulowana przez ShipX. Unieważniono etykietę lokalnie; sprawdź przesyłkę ręcznie w InPost.",
                    ['remote_status' => $remoteStatus],
                );
            }

            if (! $account instanceof CourierAccount || $account->provider !== 'inpost') {
                return $this->manualResult(
                    $label,
                    'inpost',
                    $shipmentId,
                    'missing_courier_account',
                    'Etykieta InPost nie ma powiązanego konta ShipX. Unieważniono ją lokalnie, ale przesyłkę trzeba sprawdzić i anulować ręcznie.',
                );
            }

            if ($shipmentId === null) {
                return $this->manualResult(
                    $label,
                    'inpost',
                    null,
                    'missing_remote_id',
                    'Etykieta InPost nie ma identyfikatora przesyłki ShipX. Unieważniono ją lokalnie, ale przesyłkę trzeba sprawdzić ręcznie.',
                );
            }

            try {
                $result = $this->inpost->cancelShipment($shipmentId, $account);
            } catch (Throwable $exception) {
                return $this->manualResult(
                    $label,
                    'inpost',
                    $shipmentId,
                    'remote_outcome_unknown',
                    'Nie udało się jednoznacznie potwierdzić anulowania przesyłki InPost. '
                        .'Unieważniono etykietę lokalnie; sprawdź przesyłkę w InPost przed wznowieniem zwrotu pieniędzy.',
                    ['remote_error' => $exception->getMessage()],
                );
            }

            if ($result['status'] === 'remote_not_cancellable') {
                return $this->manualResult(
                    $label,
                    'inpost',
                    $shipmentId,
                    'remote_not_cancellable',
                    (string) ($result['message'] ?: 'InPost nie pozwala już anulować tej przesyłki zdalnie. Unieważniono etykietę lokalnie.'),
                    ['remote_response' => $result],
                );
            }

            return [
                'audit' => [
                    'status' => 'cancelled',
                    'provider' => 'inpost',
                    'shipment_id' => $shipmentId,
                    'remote_response' => $result,
                ],
                'warning' => null,
            ];
        }

        if ($provider === 'blpaczka') {
            if (! $account instanceof CourierAccount || $account->provider !== 'blpaczka') {
                return $this->manualResult(
                    $label,
                    'blpaczka',
                    $shipmentId,
                    'missing_courier_account',
                    'Etykieta BLPaczka nie ma powiązanego konta API. Unieważniono ją lokalnie, ale przesyłkę trzeba anulować ręcznie.',
                );
            }

            if ($shipmentId === null) {
                return $this->manualResult(
                    $label,
                    'blpaczka',
                    null,
                    'missing_remote_id',
                    'Etykieta BLPaczka nie ma identyfikatora przesyłki. Unieważniono ją lokalnie, ale przesyłkę trzeba sprawdzić ręcznie.',
                );
            }

            try {
                $result = $this->blpaczka->cancelShipment($shipmentId, $account);
            } catch (Throwable $exception) {
                return $this->manualResult(
                    $label,
                    'blpaczka',
                    $shipmentId,
                    'remote_outcome_unknown',
                    'Nie udało się jednoznacznie potwierdzić anulowania przesyłki BLPaczka. '
                        .'Unieważniono etykietę lokalnie; sprawdź przesyłkę u przewoźnika przed wznowieniem zwrotu pieniędzy.',
                    ['remote_error' => $exception->getMessage()],
                );
            }

            return [
                'audit' => [
                    'status' => 'cancelled',
                    'provider' => 'blpaczka',
                    'shipment_id' => $shipmentId,
                    'remote_response' => $result,
                ],
                'warning' => null,
            ];
        }

        return $this->manualResult(
            $label,
            $provider,
            $shipmentId,
            'unsupported_provider',
            'Dla tej etykiety nie skonfigurowano zdalnego anulowania przesyłki. Etykietę unieważniono lokalnie; przesyłkę trzeba sprawdzić u przewoźnika.',
        );
    }

    /**
     * @param  array<string, mixed>  $remoteAudit
     */
    private function persistLocalCancellation(
        ShippingLabel $label,
        array $remoteAudit,
        ?string $operationUuid,
        ?string $reason,
    ): bool {
        return DB::transaction(function () use ($label, $remoteAudit, $operationUuid, $reason): bool {
            $lockedLabel = ShippingLabel::query()->lockForUpdate()->findOrFail($label->id);
            $this->assertNotDispatched($lockedLabel);

            if ($lockedLabel->status === 'cancelled') {
                if ($lockedLabel->next_tracking_check_at !== null) {
                    $lockedLabel->forceFill(['next_tracking_check_at' => null])->save();
                }

                return false;
            }

            $cancelledAt = now()->toISOString();
            $audit = array_filter([
                'operation_uuid' => $operationUuid,
                'reason' => $reason,
                'cancelled_at' => $cancelledAt,
                'remote' => $remoteAudit,
            ], static fn (mixed $value): bool => $value !== null);
            $responsePayload = (array) $lockedLabel->response_payload;
            $responsePayload['cancellation'] = $audit;
            $lockedLabel->forceFill([
                'status' => 'cancelled',
                'next_tracking_check_at' => null,
                'response_payload' => $responsePayload,
            ])->save();

            return true;
        });
    }

    /**
     * @param  Collection<int, ShippingLabel>  $labels
     * @return array{
     *     cancelled_print_job_ids:list<int>,
     *     manual_required:list<array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}>
     * }
     */
    private function cancelPrintJobs(
        Collection $labels,
        ?string $operationUuid,
        ?string $reason,
    ): array {
        return DB::transaction(function () use ($labels, $operationUuid, $reason): array {
            $labelIds = $labels->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            /** @var Collection<int, ShippingLabel> $lockedLabels */
            $lockedLabels = ShippingLabel::query()
                ->whereIn('id', $labelIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lockedLabels as $lockedLabel) {
                $this->assertNotDispatched($lockedLabel);
            }

            /** @var Collection<int, PrintJob> $printJobs */
            $printJobs = PrintJob::query()
                ->whereIn('shipping_label_id', $labelIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $cancelledPrintJobIds = [];
            $manualRequired = [];
            $cancelledAt = now()->toISOString();

            foreach ($printJobs as $printJob) {
                if ($printJob->status === 'cancelled') {
                    $previousStatus = (string) data_get(
                        $printJob->metadata,
                        'shipping_label_cancellation.previous_status',
                        '',
                    );
                    $label = $lockedLabels->get($printJob->shipping_label_id);

                    if ($label instanceof ShippingLabel
                        && in_array($previousStatus, ['printing', 'printed'], true)) {
                        $manualRequired[] = $this->warning(
                            $label,
                            $this->providers->providerKey($label),
                            $this->nullableTrimmed((string) $label->label_number, 120),
                            'label_already_printed',
                            'Etykieta została już pobrana przez drukarkę albo wydrukowana. Zniszcz fizyczny wydruk, aby nie został omyłkowo użyty.',
                        );
                    }

                    continue;
                }

                $label = $lockedLabels->get($printJob->shipping_label_id);

                if (! $label instanceof ShippingLabel) {
                    continue;
                }

                $audit = array_filter([
                    'operation_uuid' => $operationUuid,
                    'reason' => $reason,
                    'cancelled_at' => $cancelledAt,
                    'previous_status' => $printJob->status,
                ], static fn (mixed $value): bool => $value !== null);
                $metadata = (array) $printJob->metadata;
                $metadata['shipping_label_cancellation'] = $audit;

                if (in_array($printJob->status, ['printing', 'printed'], true)) {
                    if (data_get($printJob->metadata, 'shipping_label_cancellation') === null) {
                        $updates = ['metadata' => $metadata];

                        if ($printJob->status === 'printing') {
                            $updates += [
                                'status' => 'cancelled',
                                'next_attempt_at' => null,
                                'reserved_by' => null,
                                'reserved_station' => null,
                                'reserved_at' => null,
                                'lease_token' => null,
                            ];
                            $cancelledPrintJobIds[] = (int) $printJob->id;
                        }

                        $printJob->forceFill($updates)->save();
                    }
                    $manualRequired[] = $this->warning(
                        $label,
                        $this->providers->providerKey($label),
                        $this->nullableTrimmed((string) $label->label_number, 120),
                        'label_already_printed',
                        'Etykieta została już pobrana przez drukarkę albo wydrukowana. Zniszcz fizyczny wydruk, aby nie został omyłkowo użyty.',
                    );

                    continue;
                }

                if (! in_array($printJob->status, self::PRINT_JOB_STATUSES_TO_CANCEL, true)) {
                    continue;
                }

                $printJob->forceFill([
                    'status' => 'cancelled',
                    'next_attempt_at' => null,
                    'reserved_by' => null,
                    'reserved_station' => null,
                    'reserved_at' => null,
                    'lease_token' => null,
                    'metadata' => $metadata,
                ])->save();
                $cancelledPrintJobIds[] = (int) $printJob->id;
            }

            return [
                'cancelled_print_job_ids' => $cancelledPrintJobIds,
                'manual_required' => $manualRequired,
            ];
        });
    }

    private function assertNotDispatched(ShippingLabel $label): void
    {
        if ($label->hasCourierPickupEvidence()) {
            $number = $label->trackingIdentifier() ?: '#'.$label->id;

            throw new RuntimeException(
                "Nie można anulować zamówienia: przesyłka {$number} została już odebrana przez kuriera lub doręczona. Użyj procesu zwrotu.",
            );
        }
    }

    /**
     * @return array{
     *     audit:array<string,mixed>,
     *     warning:?array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}
     * }
     */
    private function alreadyCancelledResult(ShippingLabel $label): array
    {
        $previousRemote = (array) data_get(
            $label->response_payload,
            'cancellation.remote',
            [],
        );
        $previousStatus = mb_strtolower(trim((string) ($previousRemote['status'] ?? '')));
        $manualWarning = null;

        if (in_array($previousStatus, ['cancelled', 'already_cancelled'], true)) {
            return [
                'audit' => [
                    'status' => 'already_cancelled_locally',
                    'provider' => $this->providers->providerKey($label),
                    'shipment_id' => $this->nullableTrimmed((string) $label->label_number, 120),
                ],
                'warning' => null,
            ];
        }

        // A worker may stop after the local label has been voided but before the
        // cancellation step stores its response. Reconstruct the unresolved
        // carrier warning so a retry cannot accidentally pass the manual gate.
        if ($previousStatus === 'manual_required') {
            $provider = $this->nullableTrimmed(
                (string) ($previousRemote['provider'] ?? $this->providers->providerKey($label)),
                80,
            );
            $shipmentId = $this->nullableTrimmed(
                (string) ($previousRemote['shipment_id'] ?? $label->label_number),
                120,
            );
            $code = trim((string) ($previousRemote['code'] ?? '')) ?: 'remote_cancellation_unresolved';
            $message = trim((string) ($previousRemote['message'] ?? ''))
                ?: 'Etykieta została unieważniona lokalnie, ale anulowanie przesyłki u przewoźnika nadal wymaga ręcznego potwierdzenia.';
            $manualWarning = $this->warning(
                $label,
                $provider,
                $shipmentId,
                $code,
                $message,
            );
        } else {
            $provider = $this->nullableTrimmed(
                (string) ($previousRemote['provider'] ?? $this->providers->providerKey($label)),
                80,
            );
            $shipmentId = $this->nullableTrimmed(
                (string) ($previousRemote['shipment_id'] ?? $label->label_number),
                120,
            );
            $manualWarning = $this->warning(
                $label,
                $provider,
                $shipmentId,
                'remote_cancellation_unverified',
                'Etykieta jest anulowana tylko lokalnie, ale brak potwierdzenia anulowania przesyłki u przewoźnika. Sprawdź ją ręcznie i potwierdź anulowanie przed scaleniem.',
            );
        }

        return [
            'audit' => [
                'status' => 'already_cancelled_locally',
                'provider' => $this->providers->providerKey($label),
                'shipment_id' => $this->nullableTrimmed((string) $label->label_number, 120),
            ],
            'warning' => $manualWarning,
        ];
    }

    /**
     * @param  array<string, mixed>  $extraAudit
     * @return array{
     *     audit:array<string,mixed>,
     *     warning:array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}
     * }
     */
    private function manualResult(
        ShippingLabel $label,
        ?string $provider,
        ?string $shipmentId,
        string $code,
        string $message,
        array $extraAudit = [],
    ): array {
        return [
            'audit' => [
                'status' => 'manual_required',
                'provider' => $provider,
                'shipment_id' => $shipmentId,
                'code' => $code,
                'message' => $message,
                ...$extraAudit,
            ],
            'warning' => $this->warning($label, $provider, $shipmentId, $code, $message),
        ];
    }

    /**
     * @return array{label_id:int,order_id:int,provider:?string,shipment_id:?string,code:string,message:string}
     */
    private function warning(
        ShippingLabel $label,
        ?string $provider,
        ?string $shipmentId,
        string $code,
        string $message,
    ): array {
        return [
            'label_id' => (int) $label->id,
            'order_id' => (int) $label->external_order_id,
            'provider' => $provider,
            'shipment_id' => $shipmentId,
            'code' => $code,
            'message' => $message,
        ];
    }

    private function inPostRemoteStatus(ShippingLabel $label): ?string
    {
        $status = trim((string) data_get($label->response_payload, 'shipment.status', ''));

        return $status !== '' ? mb_strtolower($status) : null;
    }

    /** @return list<int> */
    private function familyOrderIds(ExternalOrder $order): array
    {
        $rootOrderId = (int) ($order->split_root_order_id ?: $order->id);

        return ExternalOrder::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->where(function ($query) use ($rootOrderId, $order): void {
                $query
                    ->whereKey($rootOrderId)
                    ->orWhere('split_root_order_id', $rootOrderId)
                    ->orWhere('id', $order->id);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $orderId): int => (int) $orderId)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $orderIds
     */
    private function withShippingLocks(array $orderIds, int $offset, callable $operation): mixed
    {
        if (! isset($orderIds[$offset])) {
            return $operation();
        }

        return Cache::lock('shipping-label-order-'.$orderIds[$offset], self::LOCK_SECONDS)
            ->block(
                self::WAIT_SECONDS,
                fn (): mixed => $this->withShippingLocks($orderIds, $offset + 1, $operation),
            );
    }

    private function nullableTrimmed(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }
}
