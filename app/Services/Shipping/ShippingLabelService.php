<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\CourierAccount;
use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\ReturnCase;
use App\Models\ShippingLabel;
use App\Models\WordpressIntegration;
use App\Services\Audit\AuditLogService;
use App\Services\Payments\PaymentMethodClassifier;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ShippingLabelService
{
    private const GENERATION_LOCK_SECONDS = 900;

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly InPostShipmentService $inpost,
        private readonly BLPaczkaShipmentService $blpaczka,
        private readonly AuditLogService $audit,
        private readonly PaymentMethodClassifier $paymentMethods,
    ) {}

    public function generateForOrder(
        ExternalOrder $order,
        ?CourierAccount $courierAccount = null,
        ?string $parcelTemplate = null,
        bool $forceNew = false,
    ): ShippingLabel {
        if ($parcelTemplate !== null && ! in_array($parcelTemplate, ['small', 'medium', 'large'], true)) {
            throw new RuntimeException('Nieprawidłowy gabaryt paczki. Wybierz A, B albo C.');
        }

        try {
            return Cache::lock('shipping-label-order-'.$order->id, self::GENERATION_LOCK_SECONDS)
                ->block(15, fn (): ShippingLabel => $this->generateForOrderWhileLocked($order, $courierAccount, $parcelTemplate, $forceNew));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Generowanie etykiety dla tego zamówienia już trwa. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    public function registerManualShipment(ExternalOrder $order, string $provider, string $trackingNumber): ShippingLabel
    {
        try {
            return Cache::lock('shipping-label-order-'.$order->id, self::GENERATION_LOCK_SECONDS)
                ->block(15, fn (): ShippingLabel => $this->registerManualShipmentWhileLocked(
                    $order,
                    $provider,
                    $trackingNumber,
                ));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Dla tego zamówienia trwa właśnie zmiana podziału albo obsługa etykiety. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    public function assertPreservedPickingResetLabelCanBeReused(
        ExternalOrder $order,
        ShippingLabel $label,
    ): void {
        $order = ExternalOrder::query()->findOrFail($order->id);

        if (! $this->hasCompletedPickingReset($order)) {
            return;
        }

        $preservedLabel = $this->preservedPickingResetLabel($order);

        if (! $preservedLabel instanceof ShippingLabel || (int) $preservedLabel->id !== (int) $label->id) {
            throw new RuntimeException(
                'Zapisana etykieta nie odpowiada etykiecie zachowanej podczas cofnięcia do kompletacji. Wymagana jest ręczna weryfikacja.',
            );
        }

        $this->assertPreservedPickingResetFinancialState($order);
    }

    private function registerManualShipmentWhileLocked(
        ExternalOrder $order,
        string $provider,
        string $trackingNumber,
    ): ShippingLabel {
        $order = ExternalOrder::query()->find($order->id);

        if (! $order instanceof ExternalOrder) {
            throw new RuntimeException('Zamówienie zostało zarchiwizowane podczas zapisywania numeru przesyłki. Odśwież widok.');
        }

        $this->ensureSplitReversalNotInProgress($order);

        if ($this->hasCompletedPickingReset($order)) {
            $preservedLabel = $this->preservedPickingResetLabel($order);
            $tracking = $preservedLabel?->trackingIdentifier();

            throw new RuntimeException(
                $tracking
                    ? "Dla tego zamówienia zachowano etykietę {$tracking}. Nie można dopisać drugiej przesyłki."
                    : 'Zamówienie ma zapisane cofnięcie do kompletacji z zachowaniem etykiety, ale etykiety nie udało się odnaleźć. Wymagana jest ręczna weryfikacja.',
            );
        }

        $provider = mb_strtolower(trim($provider));
        $trackingNumber = trim($trackingNumber);
        $duplicate = ShippingLabel::query()
            ->where(fn ($query) => $query->where('tracking_number', $trackingNumber)->orWhere('label_number', $trackingNumber))
            ->first();

        if ($duplicate instanceof ShippingLabel && (int) $duplicate->external_order_id !== (int) $order->id) {
            throw new RuntimeException('Ten numer przesyłki jest już przypisany do innego zamówienia.');
        }

        $manualIdempotencyKey = 'manual:shipment:order:'.$order->id;
        $cancelledManual = ShippingLabel::query()
            ->where('idempotency_key', $manualIdempotencyKey)
            ->where('status', 'cancelled')
            ->exists();

        if ($cancelledManual) {
            throw new RuntimeException('Poprzednia etykieta tego zamówienia została anulowana. Najpierw dokończ trwające cofnięcie podziału i odśwież zamówienie.');
        }

        $label = ShippingLabel::query()
            ->where('external_order_id', $order->id)
            ->where('idempotency_key', 'like', 'manual:%')
            ->where('status', 'generated')
            ->first() ?? new ShippingLabel;
        $label->fill([
            'idempotency_key' => $manualIdempotencyKey,
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'purpose' => 'shipment',
            'status' => 'generated',
            'provider' => $provider,
            'label_number' => $trackingNumber,
            'tracking_number' => $trackingNumber,
            'tracking_status' => null,
            'tracking_checked_at' => null,
            'next_tracking_check_at' => now(),
            'tracking_attempts' => 0,
            'tracking_last_error' => null,
            'disk' => 'local',
            'path' => '',
            'response_payload' => ['source' => 'manual_tracking_number', 'provider' => $provider],
            'generated_at' => now(),
        ]);
        $label->save();

        $this->audit->record('shipping_label.manual_added', $label, null, [
            'external_order_id' => $order->id,
            'provider' => $provider,
            'tracking_number' => $trackingNumber,
        ]);

        return $label;
    }

    private function generateForOrderWhileLocked(
        ExternalOrder $order,
        ?CourierAccount $courierAccount = null,
        ?string $parcelTemplate = null,
        bool $forceNew = false,
    ): ShippingLabel {
        $order = ExternalOrder::query()->findOrFail($order->id);
        $isSplitOrder = $this->isActiveSplitOrder($order);

        $this->ensureSplitReversalNotInProgress($order);

        if ($order->hasCancellationOperation()
            || in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
            throw new RuntimeException('Nie można wygenerować etykiety dla anulowanego zamówienia ani podczas trwającej anulacji.');
        }

        if ($this->hasCompletedPickingReset($order)) {
            $preservedLabel = $this->preservedPickingResetLabel($order);

            if (! $preservedLabel instanceof ShippingLabel) {
                throw new RuntimeException(
                    'Zamówienie ma zapisane cofnięcie do kompletacji z zachowaniem etykiety, ale etykiety nie udało się odnaleźć. Wymagana jest ręczna weryfikacja.',
                );
            }

            $this->assertPreservedPickingResetLabelCanBeReused($order, $preservedLabel);

            if ($forceNew) {
                throw new RuntimeException(
                    'Dla tego zamówienia zachowano etykietę '.$preservedLabel->trackingIdentifier().'. Nie można wygenerować drugiej przesyłki; użyj istniejącej etykiety.',
                );
            }

            return $preservedLabel;
        }

        $pendingAttempt = $this->pendingDirectGenerationAttempt($order);

        if ($pendingAttempt instanceof ShippingLabel) {
            return $this->resumeDirectGenerationAttempt($order, $pendingAttempt);
        }

        $idempotencyKey = 'shipment:order:'.$order->id;
        $existing = ShippingLabel::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $forceNew && $existing instanceof ShippingLabel && $existing->status === 'generated') {
            return $existing;
        }

        if (! $forceNew && $existing instanceof ShippingLabel) {
            throw new RuntimeException('Poprzednia etykieta tego zamówienia została anulowana. Najpierw dokończ trwające cofnięcie podziału i odśwież zamówienie.');
        }

        $existing = ShippingLabel::query()
            ->shipments()
            ->where('external_order_id', $order->id)
            ->where('status', 'generated')
            ->where(fn ($query) => $query->whereNull('idempotency_key')->orWhere('idempotency_key', 'not like', 'manual:%'))
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if (! $forceNew && $existing instanceof ShippingLabel) {
            return $existing;
        }

        if ($courierAccount instanceof CourierAccount) {
            return $courierAccount->provider === 'blpaczka'
                ? $this->generateViaBLPaczka(
                    $order,
                    $courierAccount,
                    remoteForceNew: $forceNew || $isSplitOrder,
                    localForceNew: $forceNew,
                )
                : $this->generateViaInPost(
                    $order,
                    $courierAccount,
                    $parcelTemplate,
                    remoteForceNew: $forceNew || $isSplitOrder,
                    localForceNew: $forceNew,
                );
        }

        $order = ExternalOrder::query()
            ->with('salesChannel')
            ->findOrFail($order->id);

        $blpaczkaLabel = ($forceNew || $isSplitOrder) ? null : $this->fetchBLPaczkaLabelIfAvailable($order);

        if ($blpaczkaLabel instanceof ShippingLabel) {
            return $blpaczkaLabel;
        }

        $isSplitCod = $isSplitOrder && $this->paymentMethods->isCashOnDelivery($order);

        if ($isSplitCod) {
            if ($this->looksLikeInPostShipping($order)) {
                $inpostAccount = CourierAccount::defaultFor('inpost');

                if ($inpostAccount instanceof CourierAccount) {
                    return $this->generateViaInPost(
                        $order,
                        $inpostAccount,
                        $parcelTemplate,
                        remoteForceNew: true,
                        localForceNew: $forceNew,
                    );
                }
            } else {
                $blpaczkaAccount = CourierAccount::defaultFor('blpaczka');

                if ($blpaczkaAccount instanceof CourierAccount) {
                    return $this->generateViaBLPaczka(
                        $order,
                        $blpaczkaAccount,
                        remoteForceNew: true,
                        localForceNew: $forceNew,
                    );
                }
            }
        }

        $integration = $this->integrationWithLabelsForOrder($order);

        if (! $integration instanceof WordpressIntegration) {
            if ($this->looksLikeInPostShipping($order)) {
                $inpostAccount = CourierAccount::defaultFor('inpost');

                if ($inpostAccount instanceof CourierAccount) {
                    return $this->generateViaInPost(
                        $order,
                        $inpostAccount,
                        $parcelTemplate,
                        remoteForceNew: $forceNew || $isSplitOrder,
                        localForceNew: $forceNew,
                    );
                }
            } else {
                $blpaczkaAccount = CourierAccount::defaultFor('blpaczka');

                if ($blpaczkaAccount instanceof CourierAccount) {
                    return $this->generateViaBLPaczka(
                        $order,
                        $blpaczkaAccount,
                        remoteForceNew: $forceNew || $isSplitOrder,
                        localForceNew: $forceNew,
                    );
                }
            }

            throw new RuntimeException(
                'Brak konfiguracji etykiet dla kanału tego zamówienia. Włącz etykiety kurierskie w Integracjach (endpoint wtyczki sklepu), dodaj konto InPost/BLPaczka w Ustawienia → Wysyłki albo wygeneruj etykietę ręcznie i wybierz konto przy zamówieniu.',
            );
        }

        if ($isSplitCod) {
            throw new RuntimeException(
                'Etykieta pobraniowa dla rozdzielonego zamówienia wymaga bezpośredniego konta InPost lub BLPaczka. Endpoint wtyczki WooCommerce nie potwierdza kwoty COD dla tej części zamówienia.',
            );
        }

        $startedAt = now();

        try {
            $labelData = $this->client->generateShippingLabel(
                $integration,
                (string) $order->external_id,
                (string) $order->external_number,
                $parcelTemplate,
            );

            $contents = (string) $labelData['contents'];
            $mimeType = (string) ($labelData['mime_type'] ?? 'application/pdf');
            $filename = $this->filename($order, $labelData, $mimeType);
            $path = 'shipping-labels/'.now()->format('Y/m').'/'.$filename;
            $orderShipmentData = $this->shipmentDataFromOrder($order);
            $responsePayload = (array) ($labelData['response_payload'] ?? []);

            if ($parcelTemplate !== null) {
                $responsePayload['parcel_template'] = $parcelTemplate;
            }

            $responsePayload['financial'] = $this->financialSnapshot($order);

            Storage::disk('local')->put($path, $contents);

            $label = $this->createShipmentLabel([
                'sales_channel_id' => $order->sales_channel_id,
                'external_order_id' => $order->id,
                'wordpress_integration_id' => $integration->id,
                'purpose' => 'shipment',
                'idempotency_key' => $this->shipmentIdempotencyKey($order, $forceNew),
                'status' => 'generated',
                'provider' => $this->stringFromPayload($labelData, ['provider', 'carrier', 'shipping_provider'])
                    ?: $orderShipmentData['provider'],
                'label_number' => $this->stringFromPayload($labelData, ['label_number', 'label_id', 'id'])
                    ?: $orderShipmentData['label_number'],
                'tracking_number' => $this->stringFromPayload($labelData, ['tracking_number', 'tracking', 'tracking_code'])
                    ?: $orderShipmentData['tracking_number']
                    ?: $this->trackingNumberFromFilename((string) ($labelData['filename'] ?? '')),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $mimeType,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'source_url' => (string) ($labelData['source_url'] ?? ''),
                'response_payload' => $responsePayload,
                'generated_at' => now(),
            ]);

            $this->syncLog($integration, $order, 'success', $startedAt, responsePayload: [
                'label_id' => $label->id,
                'path' => $label->path,
                'source_url' => $label->source_url,
                'tracking_number' => $label->tracking_number,
                'parcel_template' => $parcelTemplate,
            ]);

            $this->audit->record('shipping_label.generated', $label, null, [
                'order_number' => $order->external_number,
                'label_id' => $label->id,
                'tracking_number' => $label->tracking_number,
                'parcel_template' => $parcelTemplate,
            ], [
                'sales_channel' => $order->salesChannel?->code,
                'integration_id' => $integration->id,
            ]);

            return $label;
        } catch (Throwable $exception) {
            $this->syncLog($integration, $order, 'failed', $startedAt, error: $exception->getMessage());
            $this->audit->record('shipping_label.failed', $order, null, null, [
                'sales_channel' => $order->salesChannel?->code,
                'integration_id' => $integration->id,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Generuje etykietę zwrotną InPost (klient → magazyn) dla zgłoszenia zwrotu.
     */
    public function generateReturnLabel(ReturnCase $returnCase, CourierAccount $account): ShippingLabel
    {
        $returnCase->loadMissing('externalOrder');

        try {
            $labelData = $this->inpost->createReturnShipmentWithLabel($returnCase, $account);

            $contents = $labelData['contents'];
            $filename = 'zwrot-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $returnCase->number).'-'.now()->format('YmdHis').'.pdf';
            $path = 'shipping-labels/returns/'.now()->format('Y/m').'/'.$filename;

            Storage::disk('local')->put($path, $contents);

            $label = ShippingLabel::query()->create([
                'sales_channel_id' => $returnCase->externalOrder?->sales_channel_id,
                'external_order_id' => $returnCase->external_order_id,
                'return_case_id' => $returnCase->id,
                'courier_account_id' => $account->id,
                'purpose' => 'return',
                'status' => 'generated',
                'provider' => 'inpost',
                'label_number' => $labelData['shipment_id'],
                'tracking_number' => $labelData['tracking_number'],
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $labelData['mime_type'],
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'response_payload' => [
                    'courier_account' => $account->code,
                    'direction' => 'return',
                    'shipment' => $labelData['response_payload'],
                ],
                'generated_at' => now(),
            ]);

            $this->audit->record('shipping_label.return_generated', $label, null, [
                'return_number' => $returnCase->number,
                'label_id' => $label->id,
                'tracking_number' => $label->tracking_number,
                'courier_account' => $account->code,
            ]);

            return $label;
        } catch (Throwable $exception) {
            $this->audit->record('shipping_label.return_failed', $returnCase, null, null, [
                'return_number' => $returnCase->number,
                'courier_account' => $account->code,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Generuje etykietę wymiany (magazyn → klient) dla zwrotu.
     */
    public function generateExchangeLabel(ReturnCase $returnCase, CourierAccount $account): ShippingLabel
    {
        $returnCase->loadMissing('externalOrder');

        if ($account->provider !== 'inpost') {
            throw new RuntimeException('Etykiety wymiany dla zwrotów są obecnie obsługiwane przez konta InPost.');
        }

        try {
            $labelData = $this->inpost->createExchangeShipmentWithLabel($returnCase, $account);

            $contents = $labelData['contents'];
            $filename = 'wymiana-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', $returnCase->number).'-'.now()->format('YmdHis').'.pdf';
            $path = 'shipping-labels/returns/'.now()->format('Y/m').'/'.$filename;

            Storage::disk('local')->put($path, $contents);

            $label = ShippingLabel::query()->create([
                'sales_channel_id' => $returnCase->externalOrder?->sales_channel_id,
                'external_order_id' => $returnCase->external_order_id,
                'return_case_id' => $returnCase->id,
                'courier_account_id' => $account->id,
                'purpose' => 'exchange',
                'status' => 'generated',
                'provider' => 'inpost',
                'label_number' => $labelData['shipment_id'],
                'tracking_number' => $labelData['tracking_number'],
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $labelData['mime_type'],
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'response_payload' => [
                    'courier_account' => $account->code,
                    'direction' => 'exchange_to_customer',
                    'shipment' => $labelData['response_payload'],
                ],
                'generated_at' => now(),
            ]);

            $this->audit->record('shipping_label.exchange_generated', $label, null, [
                'return_number' => $returnCase->number,
                'label_id' => $label->id,
                'tracking_number' => $label->tracking_number,
                'courier_account' => $account->code,
            ]);

            return $label;
        } catch (Throwable $exception) {
            $this->audit->record('shipping_label.exchange_failed', $returnCase, null, null, [
                'return_number' => $returnCase->number,
                'courier_account' => $account->code,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    private function generateViaInPost(
        ExternalOrder $order,
        CourierAccount $account,
        ?string $parcelTemplate = null,
        bool $remoteForceNew = false,
        bool $localForceNew = false,
    ): ShippingLabel {
        $order = ExternalOrder::query()
            ->with('salesChannel')
            ->findOrFail($order->id);

        $attempt = $this->startDirectGenerationAttempt(
            $order,
            $account,
            provider: 'inpost',
            remoteForceNew: $remoteForceNew,
            localForceNew: $localForceNew,
            parcelTemplate: $parcelTemplate,
        );

        return $this->continueInPostGeneration($order, $account, $attempt);
    }

    private function continueInPostGeneration(
        ExternalOrder $order,
        CourierAccount $account,
        ShippingLabel $attempt,
    ): ShippingLabel {
        $generation = (array) data_get($attempt->response_payload, 'generation', []);
        $parcelTemplate = filled($generation['parcel_template'] ?? null)
            ? (string) $generation['parcel_template']
            : null;
        $remoteForceNew = (bool) ($generation['remote_force_new'] ?? false);

        try {
            $labelData = $this->inpost->createShipmentWithLabel(
                $order,
                $account,
                $parcelTemplate,
                $remoteForceNew,
                $this->directGenerationCheckpoint($attempt),
            );

            return $this->storeInPostGenerationAttempt($order, $account, $attempt, $labelData);
        } catch (Throwable $exception) {
            $this->failDirectGeneration($order, $account, $attempt, $exception);
        }
    }

    /**
     * @param  array{shipment_id:string,tracking_number:?string,contents:string,mime_type:string,response_payload:array<string,mixed>}  $labelData
     */
    private function storeInPostGenerationAttempt(
        ExternalOrder $order,
        CourierAccount $account,
        ShippingLabel $attempt,
        array $labelData,
    ): ShippingLabel {
        $contents = (string) $labelData['contents'];
        $shipmentPayload = (array) $labelData['response_payload'];
        $reportedParcelTemplate = (string) (
            data_get($shipmentPayload, 'parcels.0.template')
            ?: data_get($shipmentPayload, 'parcel.template')
        );
        $reusedExistingShipment = (bool) data_get($shipmentPayload, 'reused_existing_shipment', false);
        $attemptPayload = (array) ($attempt->fresh()?->response_payload ?? []);
        $requestedParcelTemplate = filled(data_get($attemptPayload, 'generation.parcel_template'))
            ? (string) data_get($attemptPayload, 'generation.parcel_template')
            : null;
        $recordedParcelTemplate = in_array($reportedParcelTemplate, ['small', 'medium', 'large'], true)
            ? $reportedParcelTemplate
            : ($reusedExistingShipment ? null : ($requestedParcelTemplate ?: $account->default_parcel_template ?: 'small'));
        $filename = 'inpost-'.($order->external_number ?: $order->external_id ?: $order->id);
        $extension = str_contains(mb_strtolower((string) $labelData['mime_type']), 'zpl') ? 'zpl' : 'pdf';
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename)
            .'-order-'.$order->id.'-'.now()->format('YmdHis').'-'.Str::lower((string) Str::ulid()).'.'.$extension;
        $path = 'shipping-labels/'.now()->format('Y/m').'/'.$filename;

        if (! Storage::disk('local')->put($path, $contents)) {
            throw new RuntimeException('Nie udało się trwale zapisać pliku etykiety InPost.');
        }

        $label = $this->completeDirectGenerationAttempt($attempt, [
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
            'purpose' => 'shipment',
            'idempotency_key' => $attempt->idempotency_key,
            'status' => 'generated',
            'provider' => 'inpost',
            'label_number' => $labelData['shipment_id'],
            'tracking_number' => $labelData['tracking_number'],
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $labelData['mime_type'],
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'response_payload' => [
                'courier_account' => $account->code,
                'parcel_template' => $recordedParcelTemplate,
                'shipment' => $shipmentPayload,
                'financial' => (array) data_get($attemptPayload, 'financial', []),
                'generation' => (array) data_get($attemptPayload, 'generation', []),
            ],
            'generated_at' => now(),
        ], $path);

        $this->audit->record('shipping_label.generated', $label, null, [
            'order_number' => $order->external_number,
            'label_id' => $label->id,
            'tracking_number' => $label->tracking_number,
            'provider' => 'inpost',
            'courier_account' => $account->code,
            'parcel_template' => $recordedParcelTemplate,
        ], [
            'sales_channel' => $order->salesChannel?->code,
        ]);

        return $label;
    }

    /**
     * Tworzy nową przesyłkę BLPaczka (wycena + automatyczny dobór kuriera)
     * i zapisuje jej etykietę.
     */
    private function generateViaBLPaczka(
        ExternalOrder $order,
        CourierAccount $account,
        bool $remoteForceNew = false,
        bool $localForceNew = false,
    ): ShippingLabel {
        $order = ExternalOrder::query()
            ->with('salesChannel')
            ->findOrFail($order->id);

        $existing = $remoteForceNew ? null : $this->fetchBLPaczkaLabelIfAvailable($order);

        if ($existing instanceof ShippingLabel) {
            return $existing;
        }

        $attempt = $this->startDirectGenerationAttempt(
            $order,
            $account,
            provider: 'blpaczka',
            remoteForceNew: $remoteForceNew,
            localForceNew: $localForceNew,
        );

        return $this->continueBLPaczkaGeneration($order, $account, $attempt);
    }

    private function continueBLPaczkaGeneration(
        ExternalOrder $order,
        CourierAccount $account,
        ShippingLabel $attempt,
    ): ShippingLabel {
        try {
            $labelData = $this->blpaczka->createShipmentWithLabel(
                $order,
                $account,
                $this->directGenerationCheckpoint($attempt),
            );

            return $this->storeBLPaczkaLabel($order, $account, $labelData, reused: false, attempt: $attempt);
        } catch (Throwable $exception) {
            $this->failDirectGeneration($order, $account, $attempt, $exception);
        }
    }

    /**
     * @param  array{shipment_id:string,tracking_number:?string,contents:string,mime_type:string,response_payload:array<string,mixed>}  $labelData
     */
    private function storeBLPaczkaLabel(
        ExternalOrder $order,
        CourierAccount $account,
        array $labelData,
        bool $reused,
        bool $forceNew = false,
        ?ShippingLabel $attempt = null,
    ): ShippingLabel {
        $extension = str_contains(mb_strtolower($labelData['mime_type']), 'pdf') ? 'pdf' : 'bin';
        $filename = 'blpaczka-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($order->external_number ?: $order->external_id))
            .'-order-'.$order->id.'-'.now()->format('YmdHis').'-'.Str::lower((string) Str::ulid()).'.'.$extension;
        $path = 'shipping-labels/'.now()->format('Y/m').'/'.$filename;

        if (! Storage::disk('local')->put($path, $labelData['contents'])) {
            throw new RuntimeException('Nie udało się trwale zapisać pliku etykiety BLPaczka.');
        }

        $attemptPayload = $attempt instanceof ShippingLabel
            ? (array) ($attempt->fresh()?->response_payload ?? [])
            : [];
        $financialSnapshot = (array) data_get($attemptPayload, 'financial', []);

        if ($financialSnapshot === []) {
            $financialSnapshot = $this->financialSnapshot($order);
        }

        $responsePayload = [
            'courier_account' => $account->code,
            'reused_existing_shipment' => $reused,
            'blpaczka' => $labelData['response_payload'],
            'financial' => $financialSnapshot,
        ];

        if ($attempt instanceof ShippingLabel) {
            $responsePayload['generation'] = (array) data_get($attemptPayload, 'generation', []);
        }

        $attributes = [
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
            'purpose' => 'shipment',
            'idempotency_key' => $attempt?->idempotency_key ?? $this->shipmentIdempotencyKey($order, $forceNew),
            'status' => 'generated',
            'provider' => 'blpaczka',
            'label_number' => $labelData['shipment_id'],
            'tracking_number' => $labelData['tracking_number'],
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $labelData['mime_type'],
            'size' => strlen($labelData['contents']),
            'sha256' => hash('sha256', $labelData['contents']),
            'response_payload' => $responsePayload,
            'generated_at' => now(),
        ];

        $label = $attempt instanceof ShippingLabel
            ? $this->completeDirectGenerationAttempt($attempt, $attributes, $path)
            : $this->createShipmentLabel($attributes);

        $this->audit->record('shipping_label.generated', $label, null, [
            'order_number' => $order->external_number,
            'label_id' => $label->id,
            'provider' => 'blpaczka',
            'blpaczka_order_id' => $labelData['shipment_id'],
            'reused_existing_shipment' => $reused,
        ], [
            'sales_channel' => $order->salesChannel?->code,
        ]);

        return $label;
    }

    private function pendingDirectGenerationAttempt(ExternalOrder $order): ?ShippingLabel
    {
        $attempts = ShippingLabel::query()
            ->shipments()
            ->where('external_order_id', $order->id)
            ->where('status', 'generating')
            ->whereIn('provider', ['inpost', 'blpaczka'])
            ->orderBy('id')
            ->get();

        if ($attempts->count() > 1) {
            throw new RuntimeException(
                'Dla zamówienia istnieje więcej niż jedna niedokończona próba kurierska. Nie wolno automatycznie tworzyć kolejnej przesyłki; sprawdź je u przewoźnika i anuluj duplikaty ręcznie.',
            );
        }

        return $attempts->first();
    }

    private function startDirectGenerationAttempt(
        ExternalOrder $order,
        CourierAccount $account,
        string $provider,
        bool $remoteForceNew,
        bool $localForceNew,
        ?string $parcelTemplate = null,
    ): ShippingLabel {
        return ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
            'purpose' => 'shipment',
            'idempotency_key' => $this->shipmentIdempotencyKey($order, $localForceNew),
            'status' => 'generating',
            'provider' => $provider,
            'disk' => 'local',
            'path' => '',
            'response_payload' => [
                'courier_account' => $account->code,
                'financial' => $this->financialSnapshot($order),
                'generation' => [
                    'version' => 1,
                    'state' => 'prepared',
                    'attempt_token' => Str::lower((string) Str::ulid()),
                    'prepared_at' => now()->toIso8601String(),
                    'provider' => $provider,
                    'remote_force_new' => $remoteForceNew,
                    'local_force_new' => $localForceNew,
                    'parcel_template' => $parcelTemplate,
                ],
            ],
        ]);
    }

    private function resumeDirectGenerationAttempt(
        ExternalOrder $order,
        ShippingLabel $attempt,
    ): ShippingLabel {
        $attempt = ShippingLabel::query()->findOrFail($attempt->id);
        $account = CourierAccount::query()->find($attempt->courier_account_id);

        if (! $account instanceof CourierAccount) {
            throw new RuntimeException(
                'Nie można wznowić niedokończonej przesyłki, ponieważ zapisane konto kurierskie już nie istnieje. Sprawdź przesyłkę ręcznie u przewoźnika.',
            );
        }

        $generation = (array) data_get($attempt->response_payload, 'generation', []);
        $state = (string) ($generation['state'] ?? 'outcome_unknown');
        $shipmentId = trim((string) ($attempt->label_number ?: ($generation['remote_shipment_id'] ?? '')));

        if ($shipmentId === '') {
            if ($state === 'prepared') {
                return $attempt->provider === 'blpaczka'
                    ? $this->continueBLPaczkaGeneration($order, $account, $attempt)
                    : $this->continueInPostGeneration($order, $account, $attempt);
            }

            throw new RuntimeException($this->unknownRemoteCreationMessage($attempt));
        }

        try {
            if ($attempt->provider === 'blpaczka') {
                $labelData = $this->blpaczka->fetchLabelForShipment($shipmentId, $account);
                $remoteCheckpoint = (array) ($generation['remote_checkpoint'] ?? []);
                $labelData['response_payload'] = array_merge(
                    $labelData['response_payload'],
                    array_filter([
                        'courier_code' => $remoteCheckpoint['courier_code'] ?? null,
                        'courier_name' => $remoteCheckpoint['courier_name'] ?? null,
                        'price' => $remoteCheckpoint['price'] ?? null,
                    ], fn (mixed $value): bool => $value !== null && $value !== ''),
                );

                return $this->storeBLPaczkaLabel($order, $account, $labelData, reused: false, attempt: $attempt);
            }

            $labelData = $this->inpost->fetchExistingShipmentWithLabel(
                $shipmentId,
                $account,
                (bool) data_get($generation, 'remote_checkpoint.reused_existing_shipment', false),
            );

            return $this->storeInPostGenerationAttempt($order, $account, $attempt, $labelData);
        } catch (Throwable $exception) {
            $this->failDirectGeneration($order, $account, $attempt, $exception);
        }
    }

    /**
     * @return callable(string, array<string, mixed>): void
     */
    private function directGenerationCheckpoint(ShippingLabel $attempt): callable
    {
        return function (string $stage, array $checkpoint) use ($attempt): void {
            $current = ShippingLabel::query()->findOrFail($attempt->id);

            if ($current->status !== 'generating') {
                throw new RuntimeException('Nie można zapisać punktu kontrolnego zakończonej próby kurierskiej.');
            }

            $payload = (array) ($current->response_payload ?? []);
            $generation = (array) ($payload['generation'] ?? []);

            if ($stage === 'remote_creation_started') {
                $generation['state'] = 'remote_creation_started';
                $generation['remote_creation_started_at'] = (string) ($checkpoint['started_at'] ?? now()->toIso8601String());
                $generation['remote_creation_checkpoint'] = $checkpoint;
            } elseif ($stage === 'remote_creation_rejected') {
                $generation['state'] = 'prepared';
                $generation['remote_creation_rejected_at'] = (string) ($checkpoint['rejected_at'] ?? now()->toIso8601String());
                $generation['remote_creation_rejection'] = $checkpoint;
            } elseif ($stage === 'remote_shipment_resolved') {
                $shipmentId = trim((string) ($checkpoint['shipment_id'] ?? ''));

                if ($shipmentId === '') {
                    throw new RuntimeException('Przewoźnik nie zwrócił identyfikatora przesyłki do trwałego punktu kontrolnego.');
                }

                $generation['state'] = 'remote_shipment_resolved';
                $generation['remote_shipment_id'] = $shipmentId;
                $generation['remote_shipment_resolved_at'] = (string) ($checkpoint['resolved_at'] ?? now()->toIso8601String());
                $generation['remote_checkpoint'] = $checkpoint;
                $current->label_number = $shipmentId;
                $current->tracking_last_error = null;
            } else {
                throw new RuntimeException('Nieznany punkt kontrolny generowania przesyłki: '.$stage.'.');
            }

            $payload['generation'] = $generation;
            $current->response_payload = $payload;
            $current->saveOrFail();
            $attempt->refresh();
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function completeDirectGenerationAttempt(
        ShippingLabel $attempt,
        array $attributes,
        string $newPath,
    ): ShippingLabel {
        try {
            $current = ShippingLabel::query()->findOrFail($attempt->id);

            if ($current->status === 'generated') {
                if ($newPath !== '' && $newPath !== $current->path) {
                    Storage::disk('local')->delete($newPath);
                }

                return $current;
            }

            if ($current->status !== 'generating') {
                throw new RuntimeException('Niedokończona próba kurierska zmieniła status podczas zapisu etykiety.');
            }

            $persistedShipmentId = trim((string) $current->label_number);
            $completedShipmentId = trim((string) ($attributes['label_number'] ?? ''));

            if ($persistedShipmentId === '' || ! hash_equals($persistedShipmentId, $completedShipmentId)) {
                throw new RuntimeException('Identyfikator pobranej etykiety nie zgadza się z trwale zapisaną przesyłką przewoźnika.');
            }

            $responsePayload = (array) ($attributes['response_payload'] ?? []);
            $generation = (array) ($responsePayload['generation'] ?? []);
            $generation['state'] = 'completed';
            $generation['completed_at'] = now()->toIso8601String();
            $responsePayload['generation'] = $generation;
            $attributes['response_payload'] = $responsePayload;
            $attributes['tracking_last_error'] = null;
            $current->fill($attributes);
            $current->saveOrFail();
            $current->refresh();

            return $current;
        } catch (Throwable $exception) {
            if ($newPath !== '') {
                Storage::disk('local')->delete($newPath);
            }

            throw $exception;
        }
    }

    private function failDirectGeneration(
        ExternalOrder $order,
        CourierAccount $account,
        ShippingLabel $attempt,
        Throwable $exception,
    ): never {
        $current = ShippingLabel::query()->find($attempt->id);

        if ($current instanceof ShippingLabel && $current->status === 'generating') {
            $payload = (array) ($current->response_payload ?? []);
            $generation = (array) ($payload['generation'] ?? []);
            $shipmentId = trim((string) ($current->label_number ?: ($generation['remote_shipment_id'] ?? '')));

            if ($shipmentId !== '') {
                $generation['state'] = 'remote_shipment_resolved';
            } elseif (in_array((string) ($generation['state'] ?? ''), ['remote_creation_started', 'outcome_unknown'], true)) {
                $generation['state'] = 'outcome_unknown';
            } else {
                $generation['state'] = 'prepared';
            }

            $generation['last_error'] = $exception->getMessage();
            $generation['last_failed_at'] = now()->toIso8601String();
            $payload['generation'] = $generation;
            $current->response_payload = $payload;
            $current->tracking_last_error = mb_substr($exception->getMessage(), 0, 2000);
            $current->save();
            $attempt = $current;
        }

        $this->audit->record('shipping_label.failed', $order, null, null, [
            'sales_channel' => $order->salesChannel?->code,
            'provider' => $account->provider,
            'courier_account' => $account->code,
            'generation_attempt_id' => $attempt->id,
            'remote_shipment_id' => $attempt->label_number,
            'error' => $exception->getMessage(),
        ]);

        $generationState = (string) data_get($attempt->response_payload, 'generation.state', '');
        $message = match ($generationState) {
            'outcome_unknown' => $this->unknownRemoteCreationMessage($attempt),
            'remote_shipment_resolved' => $exception->getMessage().' Identyfikator przesyłki został bezpiecznie zapisany; ponowienie pobierze wyłącznie istniejącą etykietę.',
            default => $exception->getMessage(),
        };

        throw new RuntimeException($message, previous: $exception);
    }

    private function unknownRemoteCreationMessage(ShippingLabel $attempt): string
    {
        return 'Nie można bezpiecznie ponowić utworzenia przesyłki: po wysłaniu żądania do '
            .($attempt->provider === 'blpaczka' ? 'BLPaczka' : 'InPost')
            .' nie otrzymano identyfikatora, więc wynik jest nieznany. Sprawdź zamówienie w panelu przewoźnika i anuluj albo powiąż przesyłkę ręcznie; ERP nie wyśle automatycznie drugiego COD.';
    }

    /**
     * Jeśli przesyłka dla zamówienia została utworzona wtyczką BLPaczka
     * (meta BLPACZKA_blpaczka_order_id), pobiera jej etykietę z API BLPaczki.
     */
    private function fetchBLPaczkaLabelIfAvailable(ExternalOrder $order): ?ShippingLabel
    {
        $blpaczkaOrderId = $this->blpaczka->orderIdFromMeta($order);

        if ($blpaczkaOrderId === null) {
            return null;
        }

        $account = CourierAccount::defaultFor('blpaczka');

        if (! $account instanceof CourierAccount) {
            throw new RuntimeException(
                'Zamówienie ma przesyłkę BLPaczka (nr '.$blpaczkaOrderId.'), ale w ERP nie ma konta BLPaczka. Dodaj je w Ustawienia → Wysyłki (login + klucz API z panelu BLPaczki).',
            );
        }

        try {
            $labelData = $this->blpaczka->fetchLabelForShipment($blpaczkaOrderId, $account);

            return $this->storeBLPaczkaLabel($order, $account, $labelData, reused: true);
        } catch (Throwable $exception) {
            $this->audit->record('shipping_label.failed', $order, null, null, [
                'sales_channel' => $order->salesChannel?->code,
                'provider' => 'blpaczka',
                'blpaczka_order_id' => $blpaczkaOrderId,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Automatyczny fallback na konto InPost tylko dla zamówień, w których klient
     * wybrał wysyłkę InPost/Paczkomat — inne kuriery (np. z BLPaczki) nie mogą
     * dostać etykiety InPost.
     */
    private function looksLikeInPostShipping(ExternalOrder $order): bool
    {
        $methods = collect((array) data_get($order->raw_payload, 'shipping_lines', []))
            ->map(fn (array $line): string => mb_strtolower(trim((string) ($line['method_title'] ?? $line['method_id'] ?? ''))))
            ->filter();

        return $methods->contains(
            fn (string $method): bool => str_contains($method, 'inpost')
                || str_contains($method, 'paczkomat')
                || str_contains($method, 'easypack'),
        );
    }

    private function integrationWithLabelsForOrder(ExternalOrder $order): ?WordpressIntegration
    {
        return WordpressIntegration::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->get()
            ->first(fn (WordpressIntegration $candidate): bool => $candidate->shippingLabelsEnabled());
    }

    /**
     * @param  array<string, mixed>  $labelData
     */
    private function filename(ExternalOrder $order, array $labelData, string $mimeType): string
    {
        $raw = (string) ($labelData['filename'] ?? '');
        $extension = $this->extension($mimeType, $raw);
        $base = $raw !== ''
            ? pathinfo($raw, PATHINFO_FILENAME)
            : 'etykieta-'.($order->external_number ?: $order->external_id ?: $order->id);

        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'etykieta';

        $safeBase = mb_substr(trim($safeBase, '-_.'), 0, 140) ?: 'etykieta';

        return $safeBase.'-order-'.$order->id.'-'.now()->format('YmdHis').'-'.Str::lower((string) Str::ulid()).'.'.$extension;
    }

    private function extension(string $mimeType, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'zpl'], true)) {
            return $extension;
        }

        return match (strtolower($mimeType)) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'application/zpl', 'text/plain' => 'zpl',
            default => 'pdf',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function stringFromPayload(array $payload, array $keys): ?string
    {
        $response = (array) ($payload['response_payload'] ?? []);

        foreach ($keys as $key) {
            $value = $payload[$key] ?? data_get($response, $key);

            if (filled($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @return array{provider:?string,label_number:?string,tracking_number:?string}
     */
    private function shipmentDataFromOrder(ExternalOrder $order): array
    {
        $provider = null;
        $labelNumber = null;
        $trackingNumber = null;
        $shippingLines = (array) data_get($order->raw_payload, 'shipping_lines', []);

        foreach ($shippingLines as $shippingLine) {
            if (! is_array($shippingLine)) {
                continue;
            }

            $method = mb_strtolower(trim((string) ($shippingLine['method_title'] ?? $shippingLine['method_id'] ?? '')));

            foreach (['inpost', 'dpd', 'dhl', 'gls', 'ups', 'fedex', 'pocztex', 'orlen', 'blpaczka'] as $candidate) {
                if ($provider === null && str_contains($method, $candidate)) {
                    $provider = $candidate;
                }
            }

            if ($provider === null && (str_contains($method, 'paczkomat') || str_contains($method, 'easypack'))) {
                $provider = 'inpost';
            }
        }

        $metaSources = [(array) data_get($order->raw_payload, 'meta_data', [])];
        foreach ($shippingLines as $shippingLine) {
            if (is_array($shippingLine)) {
                $metaSources[] = (array) ($shippingLine['meta_data'] ?? []);
            }
        }

        foreach ($metaSources as $metaData) {
            foreach ($metaData as $meta) {
                if (! is_array($meta) || ! is_scalar($meta['value'] ?? null)) {
                    continue;
                }

                $key = mb_strtolower((string) ($meta['key'] ?? ''));
                $value = trim((string) $meta['value']);

                if ($value === '') {
                    continue;
                }

                if ($trackingNumber === null
                    && (str_contains($key, 'tracking') || str_contains($key, 'waybill') || str_contains($key, 'list_przewozowy'))
                    && preg_match('/^[A-Za-z0-9-]{6,40}$/', $value) === 1) {
                    $trackingNumber = $value;
                }

                if ($trackingNumber === null
                    && (str_contains($key, 'inpost') || str_contains($key, 'easypack') || str_contains($key, 'shipx'))
                    && preg_match('/^\d{20,26}$/', $value) === 1) {
                    $trackingNumber = $value;
                    $provider ??= 'inpost';
                }

                if ($labelNumber === null
                    && (str_contains($key, 'label') || str_contains($key, 'shipment_id'))
                    && preg_match('/^[A-Za-z0-9-]{1,80}$/', $value) === 1) {
                    $labelNumber = $value;
                }
            }
        }

        return [
            'provider' => $provider,
            'label_number' => $labelNumber,
            'tracking_number' => $trackingNumber,
        ];
    }

    private function trackingNumberFromFilename(string $filename): ?string
    {
        return preg_match('/(?<!\d)(\d{24})(?!\d)/', $filename, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createShipmentLabel(array $attributes): ShippingLabel
    {
        try {
            return ShippingLabel::query()->create($attributes);
        } catch (QueryException $exception) {
            $idempotencyKey = (string) ($attributes['idempotency_key'] ?? '');
            $existing = $idempotencyKey !== ''
                ? ShippingLabel::query()->where('idempotency_key', $idempotencyKey)->first()
                : null;

            if (! $existing instanceof ShippingLabel || $existing->status !== 'generated') {
                throw $exception;
            }

            $disk = (string) ($attributes['disk'] ?? 'local');
            $path = (string) ($attributes['path'] ?? '');
            if ($path !== '' && $path !== $existing->path) {
                Storage::disk($disk)->delete($path);
            }

            return $existing;
        }
    }

    private function shipmentIdempotencyKey(ExternalOrder $order, bool $forceNew): string
    {
        return $forceNew
            ? 'shipment:order:'.$order->id.':'.Str::lower((string) Str::ulid())
            : 'shipment:order:'.$order->id;
    }

    private function isActiveSplitOrder(ExternalOrder $order): bool
    {
        if ($order->split_parent_order_id !== null || $order->split_root_order_id !== null) {
            return true;
        }

        if ((array) data_get($order->raw_payload, 'sempre_erp_split_allocations', []) !== []) {
            return true;
        }

        return ExternalOrder::query()
            ->where('split_root_order_id', $order->id)
            ->exists();
    }

    private function ensureSplitReversalNotInProgress(ExternalOrder $order): void
    {
        if ($order->familyHasSplitReversalOperation()) {
            throw new RuntimeException(
                'Nie można wygenerować ani wznowić etykiety, dopóki nie zostanie dokończone cofnięcie podziału zamówienia.',
            );
        }
    }

    private function hasCompletedPickingReset(ExternalOrder $order): bool
    {
        return (string) data_get($order->raw_payload, 'sempre_erp_picking_reset.status') === 'completed';
    }

    private function preservedPickingResetLabel(ExternalOrder $order): ?ShippingLabel
    {
        $labelIds = collect((array) data_get(
            $order->raw_payload,
            'sempre_erp_picking_reset.preserved_label_ids',
            [],
        ))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($labelIds->count() !== 1) {
            return null;
        }

        return ShippingLabel::query()
            ->shipments()
            ->where('external_order_id', $order->id)
            ->whereKey($labelIds->first())
            ->where('status', 'generated')
            ->first();
    }

    private function assertPreservedPickingResetFinancialState(ExternalOrder $order): void
    {
        $snapshot = data_get($order->raw_payload, 'sempre_erp_picking_reset.financial_snapshot');

        if (! is_array($snapshot)
            || ! array_key_exists('cash_on_delivery', $snapshot)
            || ! is_numeric($snapshot['order_total'] ?? null)
            || trim((string) ($snapshot['currency'] ?? '')) === '') {
            throw new RuntimeException(
                'Brak pełnego zapisu finansowego z chwili zachowania etykiety. Przed ponownym użyciem wymagana jest ręczna weryfikacja.',
            );
        }

        $currentCashOnDelivery = $this->paymentMethods->isCashOnDelivery($order);
        $savedCashOnDelivery = (bool) $snapshot['cash_on_delivery'];
        $currentCurrency = strtoupper(trim((string) $order->currency));
        $savedCurrency = strtoupper(trim((string) $snapshot['currency']));

        if ($currentCashOnDelivery !== $savedCashOnDelivery
            || abs(round((float) $order->total_gross, 2) - round((float) $snapshot['order_total'], 2)) > 0.009
            || $currentCurrency !== $savedCurrency) {
            throw new RuntimeException(
                'Kwota, waluta albo sposób płatności zamówienia zmieniły się po zachowaniu etykiety. Nie można jej automatycznie użyć.',
            );
        }

        if ($savedCashOnDelivery
            && (! is_numeric($snapshot['cod_amount'] ?? null)
                || abs(round((float) $snapshot['cod_amount'], 2) - round((float) $order->total_gross, 2)) > 0.009
                || strtoupper(trim((string) ($snapshot['cod_currency'] ?? ''))) !== $currentCurrency)) {
            throw new RuntimeException(
                'Zapis COD zachowanej etykiety nie odpowiada bieżącej kwocie lub walucie zamówienia.',
            );
        }
    }

    /**
     * Snapshot used to prove which order amount was sent to a courier provider.
     *
     * @return array{order_id:int,order_total:float,currency:string,cash_on_delivery:bool,requested_cod_amount:?float,split_family_root_order_id:?int}
     */
    private function financialSnapshot(ExternalOrder $order): array
    {
        $cashOnDelivery = $this->paymentMethods->isCashOnDelivery($order);
        $isSplitOrder = $this->isActiveSplitOrder($order);
        $total = round((float) $order->total_gross, 2);

        return [
            'order_id' => (int) $order->id,
            'order_total' => $total,
            'currency' => strtoupper(trim((string) $order->currency)) ?: 'PLN',
            'cash_on_delivery' => $cashOnDelivery,
            'requested_cod_amount' => $cashOnDelivery ? $total : null,
            'split_family_root_order_id' => $isSplitOrder
                ? (int) ($order->split_root_order_id ?: $order->id)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $responsePayload
     */
    private function syncLog(
        WordpressIntegration $integration,
        ExternalOrder $order,
        string $status,
        mixed $startedAt,
        ?array $responsePayload = null,
        ?string $error = null,
    ): void {
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'generate_shipping_label',
            'status' => $status,
            'external_resource' => 'order',
            'external_id' => (string) $order->external_id,
            'request_payload' => [
                'order_id' => $order->external_id,
                'order_number' => $order->external_number,
            ],
            'response_payload' => $responsePayload,
            'error_message' => $error,
            'attempts' => 1,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
