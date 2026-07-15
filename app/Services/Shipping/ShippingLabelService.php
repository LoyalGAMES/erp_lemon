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
    ) {}

    public function generateForOrder(
        ExternalOrder $order,
        ?CourierAccount $courierAccount = null,
        ?string $parcelTemplate = null,
    ): ShippingLabel {
        if ($parcelTemplate !== null && ! in_array($parcelTemplate, ['small', 'medium', 'large'], true)) {
            throw new RuntimeException('Nieprawidłowy gabaryt paczki. Wybierz A, B albo C.');
        }

        try {
            return Cache::lock('shipping-label-order-'.$order->id, self::GENERATION_LOCK_SECONDS)
                ->block(15, fn (): ShippingLabel => $this->generateForOrderWhileLocked($order, $courierAccount, $parcelTemplate));
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'Generowanie etykiety dla tego zamówienia już trwa. Spróbuj ponownie za chwilę.',
                previous: $exception,
            );
        }
    }

    public function registerManualInPost(ExternalOrder $order, string $trackingNumber): ShippingLabel
    {
        $trackingNumber = trim($trackingNumber);
        $duplicate = ShippingLabel::query()
            ->where(fn ($query) => $query->where('tracking_number', $trackingNumber)->orWhere('label_number', $trackingNumber))
            ->first();

        if ($duplicate instanceof ShippingLabel && (int) $duplicate->external_order_id !== (int) $order->id) {
            throw new RuntimeException('Ten numer przesyłki jest już przypisany do innego zamówienia.');
        }

        $label = ShippingLabel::query()->updateOrCreate(
            ['idempotency_key' => 'manual:inpost:order:'.$order->id],
            [
                'sales_channel_id' => $order->sales_channel_id,
                'external_order_id' => $order->id,
                'purpose' => 'shipment',
                'status' => 'generated',
                'provider' => 'inpost',
                'label_number' => $trackingNumber,
                'tracking_number' => $trackingNumber,
                'tracking_status' => null,
                'tracking_checked_at' => null,
                'next_tracking_check_at' => now(),
                'tracking_attempts' => 0,
                'tracking_last_error' => null,
                'disk' => 'local',
                'path' => '',
                'response_payload' => ['source' => 'manual_inpost_tracking_number'],
                'generated_at' => now(),
            ],
        );

        $this->audit->record('shipping_label.manual_inpost_added', $label, null, [
            'external_order_id' => $order->id,
            'tracking_number' => $trackingNumber,
        ]);

        return $label;
    }

    private function generateForOrderWhileLocked(
        ExternalOrder $order,
        ?CourierAccount $courierAccount = null,
        ?string $parcelTemplate = null,
    ): ShippingLabel {
        $order = ExternalOrder::query()->findOrFail($order->id);

        if ($order->hasCancellationOperation()
            || in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
            throw new RuntimeException('Nie można wygenerować etykiety dla anulowanego zamówienia ani podczas trwającej anulacji.');
        }

        $idempotencyKey = 'shipment:order:'.$order->id;
        $existing = ShippingLabel::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing instanceof ShippingLabel) {
            return $existing;
        }

        $existing = ShippingLabel::query()
            ->shipments()
            ->where('external_order_id', $order->id)
            ->where('status', 'generated')
            ->where(fn ($query) => $query->whereNull('idempotency_key')->orWhere('idempotency_key', 'not like', 'manual:%'))
            ->latest('generated_at')
            ->latest('id')
            ->first();

        if ($existing instanceof ShippingLabel) {
            return $existing;
        }

        if ($courierAccount instanceof CourierAccount) {
            return $courierAccount->provider === 'blpaczka'
                ? $this->generateViaBLPaczka($order, $courierAccount)
                : $this->generateViaInPost($order, $courierAccount, $parcelTemplate);
        }

        $order = ExternalOrder::query()
            ->with('salesChannel')
            ->findOrFail($order->id);

        $blpaczkaLabel = $this->fetchBLPaczkaLabelIfAvailable($order);

        if ($blpaczkaLabel instanceof ShippingLabel) {
            return $blpaczkaLabel;
        }

        $integration = $this->integrationWithLabelsForOrder($order);

        if (! $integration instanceof WordpressIntegration) {
            if ($this->looksLikeInPostShipping($order)) {
                $inpostAccount = CourierAccount::defaultFor('inpost');

                if ($inpostAccount instanceof CourierAccount) {
                    return $this->generateViaInPost($order, $inpostAccount, $parcelTemplate);
                }
            } else {
                $blpaczkaAccount = CourierAccount::defaultFor('blpaczka');

                if ($blpaczkaAccount instanceof CourierAccount) {
                    return $this->generateViaBLPaczka($order, $blpaczkaAccount);
                }
            }

            throw new RuntimeException(
                'Brak konfiguracji etykiet dla kanału tego zamówienia. Włącz etykiety kurierskie w Integracjach (endpoint wtyczki sklepu), dodaj konto InPost/BLPaczka w Ustawienia → Wysyłki albo wygeneruj etykietę ręcznie i wybierz konto przy zamówieniu.',
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

            Storage::disk('local')->put($path, $contents);

            $label = $this->createShipmentLabel([
                'sales_channel_id' => $order->sales_channel_id,
                'external_order_id' => $order->id,
                'wordpress_integration_id' => $integration->id,
                'purpose' => 'shipment',
                'idempotency_key' => 'shipment:order:'.$order->id,
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
    ): ShippingLabel {
        $order = ExternalOrder::query()
            ->with('salesChannel')
            ->findOrFail($order->id);

        try {
            $labelData = $this->inpost->createShipmentWithLabel($order, $account, $parcelTemplate);

            $contents = $labelData['contents'];
            $shipmentPayload = (array) $labelData['response_payload'];
            $reportedParcelTemplate = (string) (
                data_get($shipmentPayload, 'parcels.0.template')
                ?: data_get($shipmentPayload, 'parcel.template')
            );
            $reusedExistingShipment = (bool) data_get($shipmentPayload, 'reused_existing_shipment', false);
            $recordedParcelTemplate = in_array($reportedParcelTemplate, ['small', 'medium', 'large'], true)
                ? $reportedParcelTemplate
                : ($reusedExistingShipment ? null : ($parcelTemplate ?: $account->default_parcel_template ?: 'small'));
            $filename = 'inpost-'.($order->external_number ?: $order->external_id ?: $order->id);
            $extension = str_contains(mb_strtolower((string) $labelData['mime_type']), 'zpl') ? 'zpl' : 'pdf';
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename)
                .'-order-'.$order->id.'-'.now()->format('YmdHis').'-'.Str::lower((string) Str::ulid()).'.'.$extension;
            $path = 'shipping-labels/'.now()->format('Y/m').'/'.$filename;

            Storage::disk('local')->put($path, $contents);

            $label = $this->createShipmentLabel([
                'sales_channel_id' => $order->sales_channel_id,
                'external_order_id' => $order->id,
                'courier_account_id' => $account->id,
                'purpose' => 'shipment',
                'idempotency_key' => 'shipment:order:'.$order->id,
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
                ],
                'generated_at' => now(),
            ]);

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
        } catch (Throwable $exception) {
            $this->audit->record('shipping_label.failed', $order, null, null, [
                'sales_channel' => $order->salesChannel?->code,
                'provider' => 'inpost',
                'courier_account' => $account->code,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * Tworzy nową przesyłkę BLPaczka (wycena + automatyczny dobór kuriera)
     * i zapisuje jej etykietę.
     */
    private function generateViaBLPaczka(ExternalOrder $order, CourierAccount $account): ShippingLabel
    {
        $order = ExternalOrder::query()
            ->with('salesChannel')
            ->findOrFail($order->id);

        $existing = $this->fetchBLPaczkaLabelIfAvailable($order);

        if ($existing instanceof ShippingLabel) {
            return $existing;
        }

        try {
            $labelData = $this->blpaczka->createShipmentWithLabel($order, $account);

            return $this->storeBLPaczkaLabel($order, $account, $labelData, reused: false);
        } catch (Throwable $exception) {
            $this->audit->record('shipping_label.failed', $order, null, null, [
                'sales_channel' => $order->salesChannel?->code,
                'provider' => 'blpaczka',
                'courier_account' => $account->code,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException($exception->getMessage(), previous: $exception);
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
    ): ShippingLabel {
        $extension = str_contains(mb_strtolower($labelData['mime_type']), 'pdf') ? 'pdf' : 'bin';
        $filename = 'blpaczka-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($order->external_number ?: $order->external_id))
            .'-order-'.$order->id.'-'.now()->format('YmdHis').'-'.Str::lower((string) Str::ulid()).'.'.$extension;
        $path = 'shipping-labels/'.now()->format('Y/m').'/'.$filename;

        Storage::disk('local')->put($path, $labelData['contents']);

        $label = $this->createShipmentLabel([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
            'purpose' => 'shipment',
            'idempotency_key' => 'shipment:order:'.$order->id,
            'status' => 'generated',
            'provider' => 'blpaczka',
            'label_number' => $labelData['shipment_id'],
            'tracking_number' => $labelData['tracking_number'],
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $labelData['mime_type'],
            'size' => strlen($labelData['contents']),
            'sha256' => hash('sha256', $labelData['contents']),
            'response_payload' => [
                'courier_account' => $account->code,
                'reused_existing_shipment' => $reused,
                'blpaczka' => $labelData['response_payload'],
            ],
            'generated_at' => now(),
        ]);

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

            if (! $existing instanceof ShippingLabel) {
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
