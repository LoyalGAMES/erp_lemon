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
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ShippingLabelService
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly InPostShipmentService $inpost,
        private readonly BLPaczkaShipmentService $blpaczka,
        private readonly AuditLogService $audit,
    ) {
    }

    public function generateForOrder(ExternalOrder $order, ?CourierAccount $courierAccount = null): ShippingLabel
    {
        if ($courierAccount instanceof CourierAccount) {
            return $courierAccount->provider === 'blpaczka'
                ? $this->generateViaBLPaczka($order, $courierAccount)
                : $this->generateViaInPost($order, $courierAccount);
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
                    return $this->generateViaInPost($order, $inpostAccount);
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
            );

            $contents = (string) $labelData['contents'];
            $mimeType = (string) ($labelData['mime_type'] ?? 'application/pdf');
            $filename = $this->filename($order, $labelData, $mimeType);
            $path = 'shipping-labels/' . now()->format('Y/m') . '/' . $filename;

            Storage::disk('local')->put($path, $contents);

            $label = ShippingLabel::query()->create([
                'sales_channel_id' => $order->sales_channel_id,
                'external_order_id' => $order->id,
                'wordpress_integration_id' => $integration->id,
                'status' => 'generated',
                'provider' => $this->stringFromPayload($labelData, ['provider', 'carrier', 'shipping_provider']),
                'label_number' => $this->stringFromPayload($labelData, ['label_number', 'label_id', 'id']),
                'tracking_number' => $this->stringFromPayload($labelData, ['tracking_number', 'tracking', 'tracking_code']),
                'disk' => 'local',
                'path' => $path,
                'mime_type' => $mimeType,
                'size' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'source_url' => (string) ($labelData['source_url'] ?? ''),
                'response_payload' => $labelData['response_payload'] ?? null,
                'generated_at' => now(),
            ]);

            $this->syncLog($integration, $order, 'success', $startedAt, responsePayload: [
                'label_id' => $label->id,
                'path' => $label->path,
                'source_url' => $label->source_url,
                'tracking_number' => $label->tracking_number,
            ]);

            $this->audit->record('shipping_label.generated', $label, null, [
                'order_number' => $order->external_number,
                'label_id' => $label->id,
                'tracking_number' => $label->tracking_number,
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
            $filename = 'zwrot-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $returnCase->number) . '-' . now()->format('YmdHis') . '.pdf';
            $path = 'shipping-labels/returns/' . now()->format('Y/m') . '/' . $filename;

            Storage::disk('local')->put($path, $contents);

            $label = ShippingLabel::query()->create([
                'sales_channel_id' => $returnCase->externalOrder?->sales_channel_id,
                'external_order_id' => $returnCase->external_order_id,
                'return_case_id' => $returnCase->id,
                'courier_account_id' => $account->id,
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

    private function generateViaInPost(ExternalOrder $order, CourierAccount $account): ShippingLabel
    {
        $order = ExternalOrder::query()
            ->with('salesChannel')
            ->findOrFail($order->id);

        try {
            $labelData = $this->inpost->createShipmentWithLabel($order, $account);

            $contents = $labelData['contents'];
            $filename = 'inpost-' . ($order->external_number ?: $order->external_id ?: $order->id);
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) . '-' . now()->format('YmdHis') . '.pdf';
            $path = 'shipping-labels/' . now()->format('Y/m') . '/' . $filename;

            Storage::disk('local')->put($path, $contents);

            $label = ShippingLabel::query()->create([
                'sales_channel_id' => $order->sales_channel_id,
                'external_order_id' => $order->id,
                'courier_account_id' => $account->id,
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
                    'shipment' => $labelData['response_payload'],
                ],
                'generated_at' => now(),
            ]);

            $this->audit->record('shipping_label.generated', $label, null, [
                'order_number' => $order->external_number,
                'label_id' => $label->id,
                'tracking_number' => $label->tracking_number,
                'provider' => 'inpost',
                'courier_account' => $account->code,
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
     * @param array{shipment_id:string,tracking_number:?string,contents:string,mime_type:string,response_payload:array<string,mixed>} $labelData
     */
    private function storeBLPaczkaLabel(
        ExternalOrder $order,
        CourierAccount $account,
        array $labelData,
        bool $reused,
    ): ShippingLabel {
        $extension = str_contains(mb_strtolower($labelData['mime_type']), 'pdf') ? 'pdf' : 'bin';
        $filename = 'blpaczka-'.preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) ($order->external_number ?: $order->external_id))
            .'-'.now()->format('YmdHis').'.'.$extension;
        $path = 'shipping-labels/'.now()->format('Y/m').'/'.$filename;

        Storage::disk('local')->put($path, $labelData['contents']);

        $label = ShippingLabel::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'external_order_id' => $order->id,
            'courier_account_id' => $account->id,
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
     * @param array<string, mixed> $labelData
     */
    private function filename(ExternalOrder $order, array $labelData, string $mimeType): string
    {
        $raw = (string) ($labelData['filename'] ?? '');
        $extension = $this->extension($mimeType, $raw);
        $base = $raw !== ''
            ? pathinfo($raw, PATHINFO_FILENAME)
            : 'etykieta-' . ($order->external_number ?: $order->external_id ?: $order->id);

        $safeBase = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'etykieta';

        return trim($safeBase, '-_.') . '-' . now()->format('YmdHis') . '.' . $extension;
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
     * @param array<string, mixed> $payload
     * @param list<string> $keys
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
     * @param array<string, mixed>|null $responsePayload
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
