<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\ReturnCase;
use App\Models\WordpressIntegration;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\StockReservationService;
use App\Services\Packing\PackingTaskService;
use App\Services\WooCommerce\WooCommerceClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class OrderEditingService
{
    /** @var list<string> */
    private const BILLING_FIELDS = [
        'first_name', 'last_name', 'company', 'address_1', 'address_2',
        'city', 'state', 'postcode', 'country', 'email', 'phone',
    ];

    /** @var list<string> */
    private const SHIPPING_FIELDS = [
        'first_name', 'last_name', 'company', 'address_1', 'address_2',
        'city', 'state', 'postcode', 'country', 'phone',
    ];

    /** @var list<string> */
    private const BILLING_TAX_META_KEYS = [
        '_lemon_erp_billing_nip', '_billing_nip', 'billing_nip',
        'nip', 'vat_number', 'billing_vat_number', '_billing_vat_number',
    ];

    private const SHIPPING_LOCK_SECONDS = 900;

    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly OrderMutationLock $orderLock,
        private readonly OrderWzDocumentService $wzDocuments,
        private readonly StockReservationService $reservations,
        private readonly PackingTaskService $packingTasks,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{editable:bool,reason:?string,resets_picking:bool}
     */
    public function availability(ExternalOrder $order): array
    {
        if ($order->hasCancellationOperation()
            || in_array($order->status, ['cancellation-pending', 'cancelled', 'refunded'], true)) {
            return $this->unavailable('Anulowanego zamówienia ani zamówienia w trakcie anulacji nie można już edytować.');
        }

        if (! $this->integration($order) instanceof WordpressIntegration) {
            return $this->unavailable('Brak jednoznacznej integracji WooCommerce dla tego zamówienia.');
        }

        if ($this->belongsToSplitFamily($order)) {
            return $this->unavailable('Edycja zamówienia podzielonego jest zablokowana, aby nie uszkodzić alokacji pozycji między częściami.');
        }

        if (ReturnCase::query()->where('external_order_id', $order->id)->exists()) {
            return $this->unavailable('Edycja jest zablokowana, ponieważ dla zamówienia rozpoczęto już obsługę zwrotu.');
        }

        if ($order->invoices()->exists()) {
            return $this->unavailable('Edycja jest zablokowana, ponieważ dla zamówienia istnieje już faktura lub proforma.');
        }

        if ($this->fulfillmentStatus->wzDocumentsForOrder($order)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->exists()) {
            return $this->unavailable('Edycja jest zablokowana, ponieważ dokument WZ został już zaksięgowany.');
        }

        if ($order->shipmentLabels()->whereIn('status', ['generated', 'picked_up'])->exists()) {
            return $this->unavailable('Edycja jest zablokowana po wygenerowaniu etykiety. Najpierw trzeba anulować przesyłkę, aby adres na etykiecie nie pozostał nieaktualny.');
        }

        if ($order->packingTasks()->whereIn('status', ['packed', 'shipped', 'problem'])->exists()) {
            return $this->unavailable('Edycja jest zablokowana, ponieważ zamówienie zostało spakowane, wysłane albo oznaczone jako problem.');
        }

        if ($order->lines()->where(function ($query): void {
            $query->whereNull('external_line_id')->orWhere('external_line_id', '');
        })->exists()) {
            return $this->unavailable('To zamówienie zawiera lokalne pozycje bez identyfikatora WooCommerce.');
        }

        return [
            'editable' => true,
            'reason' => null,
            'resets_picking' => $order->packingTasks()->where('status', 'picked')->exists(),
        ];
    }

    public function version(ExternalOrder $order): string
    {
        $order->loadMissing('lines');

        return hash('sha256', json_encode([
            'updated_at' => $order->updated_at?->format('Y-m-d H:i:s.u'),
            'external_updated_at' => $order->external_updated_at?->format('Y-m-d H:i:s.u'),
            'lines' => $order->lines
                ->sortBy('id')
                ->map(fn (ExternalOrderLine $line): array => [
                    'id' => $line->id,
                    'external_line_id' => $line->external_line_id,
                    'product_id' => $line->product_id,
                    'quantity' => (string) $line->quantity,
                    'updated_at' => $line->updated_at?->format('Y-m-d H:i:s.u'),
                ])
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function expectedRemoteModifiedAt(ExternalOrder $order): string
    {
        return trim((string) (
            data_get($order->raw_payload, 'date_modified_gmt')
            ?: data_get($order->raw_payload, 'date_modified')
            ?: ''
        ));
    }

    public function billingTaxId(ExternalOrder $order): string
    {
        foreach (self::BILLING_TAX_META_KEYS as $key) {
            $billingValue = data_get($order->billing_data, $key);

            if (filled($billingValue)) {
                return trim((string) $billingValue);
            }
        }

        foreach ((array) data_get($order->raw_payload, 'meta_data', []) as $meta) {
            if (is_array($meta) && in_array((string) ($meta['key'] ?? ''), self::BILLING_TAX_META_KEYS, true)) {
                return trim((string) ($meta['value'] ?? ''));
            }
        }

        return '';
    }

    public function targetPoint(ExternalOrder $order): string
    {
        foreach ([
            data_get($order->raw_payload, 'sempre_erp_target_point'),
            data_get($order->shipping_data, 'target_point'),
            data_get($order->shipping_data, 'paczkomat'),
        ] as $candidate) {
            if (filled($candidate)) {
                return strtoupper(trim((string) $candidate));
            }
        }

        foreach ((array) data_get($order->raw_payload, 'meta_data', []) as $meta) {
            if (! is_array($meta) || ! $this->isTargetPointMetaKey((string) ($meta['key'] ?? ''))) {
                continue;
            }

            if (filled($meta['value'] ?? null)) {
                return strtoupper(trim((string) $meta['value']));
            }
        }

        foreach ((array) data_get($order->raw_payload, 'shipping_lines', []) as $shippingLine) {
            foreach ((array) data_get($shippingLine, 'meta_data', []) as $meta) {
                if (is_array($meta)
                    && $this->isTargetPointMetaKey((string) ($meta['key'] ?? ''))
                    && filled($meta['value'] ?? null)) {
                    return strtoupper(trim((string) $meta['value']));
                }
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    public function shippingLine(ExternalOrder $order): ?array
    {
        $line = collect((array) data_get($order->raw_payload, 'shipping_lines', []))
            ->first(fn (mixed $candidate): bool => is_array($candidate) && filled($candidate['id'] ?? null));

        return is_array($line) ? $line : null;
    }

    public function paymentMethodLocked(ExternalOrder $order): bool
    {
        return filled(data_get($order->raw_payload, 'date_paid'))
            || filled(data_get($order->raw_payload, 'date_paid_gmt'))
            || filled(data_get($order->raw_payload, 'transaction_id'))
            || $order->customerPayments()
                ->where('direction', 'incoming')
                ->whereIn('status', ['booked', 'paid', 'settled'])
                ->exists();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $newLine
     * @return array{updated:int,removed:int,added:int,warnings:list<string>}
     */
    public function updateLines(ExternalOrder $order, array $lines, array $newLine = []): array
    {
        $result = $this->withOrderLock(
            $order,
            fn (): array => $this->updateWhileLocked(
                $order,
                [],
                $lines,
                $newLine,
                null,
                null,
                'order_lines_manual_update',
                'order.lines_updated',
            ),
        );

        return [
            'updated' => $result['updated'],
            'removed' => $result['removed'],
            'added' => $result['added'],
            'warnings' => $result['warnings'],
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int|string, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $newLine
     * @return array{updated:int,removed:int,added:int,lines_changed:bool,fulfillment_changed:bool,warnings:list<string>}
     */
    public function updateOrder(
        ExternalOrder $order,
        array $details,
        array $lines,
        array $newLine,
        ?string $expectedVersion,
        ?string $expectedRemoteModifiedAt,
    ): array {
        return $this->withOrderLock(
            $order,
            fn (): array => $this->updateWhileLocked(
                $order,
                $details,
                $lines,
                $newLine,
                $expectedVersion,
                $expectedRemoteModifiedAt,
                'order_manual_update',
                'order.updated',
            ),
        );
    }

    /**
     * @param  callable():array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withOrderLock(ExternalOrder $order, callable $callback): array
    {
        return $this->orderLock->forOrder($order, function () use ($order, $callback): array {
            try {
                return Cache::lock(
                    'shipping-label-order-'.$order->id,
                    self::SHIPPING_LOCK_SECONDS,
                )->block(15, $callback);
            } catch (LockTimeoutException $exception) {
                throw new RuntimeException(
                    'Dla tego zamówienia trwa generowanie etykiety. Spróbuj ponownie za chwilę.',
                    previous: $exception,
                );
            }
        });
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<int|string, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $newLine
     * @return array{updated:int,removed:int,added:int,lines_changed:bool,fulfillment_changed:bool,warnings:list<string>}
     */
    private function updateWhileLocked(
        ExternalOrder $order,
        array $details,
        array $lines,
        array $newLine,
        ?string $expectedVersion,
        ?string $expectedRemoteModifiedAt,
        string $operation,
        string $auditEvent,
    ): array {
        $order = ExternalOrder::query()->with('lines')->findOrFail($order->id);
        $availability = $this->availability($order);

        if (! $availability['editable']) {
            throw new RuntimeException((string) $availability['reason']);
        }

        if (filled($expectedVersion) && ! hash_equals((string) $expectedVersion, $this->version($order))) {
            throw new RuntimeException('Zamówienie zmieniło się w ERP od otwarcia formularza. Odśwież edytor i wprowadź zmianę ponownie.');
        }

        $integration = $this->integration($order);

        if (! $integration instanceof WordpressIntegration) {
            throw new RuntimeException('Brak jednoznacznej integracji WooCommerce dla tego zamówienia.');
        }

        $remoteOrder = $this->assertRemoteVersion($integration, $order, $expectedRemoteModifiedAt);
        $this->assertPaymentMethodChangeAllowed($order, $remoteOrder, $details);

        $lineUpdate = $this->linePayload(
            $order,
            $lines,
            $newLine,
            requireProductChangePrices: $details !== [],
        );
        $payload = $this->detailsPayload($order, $details);

        if ($lineUpdate['lines_changed']) {
            $payload['line_items'] = $lineUpdate['payload'];
        }

        if ($payload === []) {
            return [
                'updated' => 0,
                'removed' => 0,
                'added' => 0,
                'lines_changed' => false,
                'fulfillment_changed' => false,
                'warnings' => [],
            ];
        }

        $startedAt = now();

        try {
            // Nowa pozycja nie ma jeszcze ID. Ponowienie PUT po utracie odpowiedzi
            // mogłoby dodać ją drugi raz, dlatego ten zapis wykonujemy tylko raz.
            $response = $this->client->updateOrder(
                $integration,
                (string) $order->external_id,
                $payload,
                retry: false,
            );
        } catch (Throwable $exception) {
            $this->syncLog($integration, $order, $operation, 'failed', $startedAt, $payload, error: $exception->getMessage());

            throw $exception;
        }

        $responseLines = $response['line_items'] ?? null;

        if (! is_array($responseLines)) {
            $message = 'WooCommerce nie zwrócił aktualnej listy pozycji.';
            $this->syncLog($integration, $order, $operation, 'partial', $startedAt, $payload, $response, $message);

            throw new RuntimeException('WooCommerce zapisał zmianę, ale nie zwrócił pełnego zamówienia. Uruchom import zamówień przed kolejną edycją.');
        }

        $before = $this->auditSnapshot($order);

        try {
            $this->persistWooResponse($order, $response, $responseLines, $payload, $details);
        } catch (Throwable $exception) {
            $this->syncLog(
                $integration,
                $order,
                $operation,
                'partial',
                $startedAt,
                $payload,
                $response,
                'WooCommerce zapisane, synchronizacja ERP nieudana: '.$exception->getMessage(),
            );

            throw new RuntimeException(
                'WooCommerce zapisało zmianę, ale nie udało się odświeżyć danych w ERP. Uruchom import zamówień przed kolejną edycją.',
                previous: $exception,
            );
        }

        $warnings = [];
        $freshOrder = $order->fresh('lines');
        $reservationsSynchronized = false;

        try {
            retry(3, fn () => $this->reservations->syncForOrder($freshOrder), 100);
            $reservationsSynchronized = true;
        } catch (Throwable $exception) {
            $warnings[] = 'Nie udało się przeliczyć rezerwacji po trzech próbach: '.$exception->getMessage();
        }

        if ($reservationsSynchronized) {
            try {
                retry(3, fn () => $this->wzDocuments->ensureDrafts($freshOrder, 'order_edit'), 100);
            } catch (Throwable $exception) {
                $warnings[] = 'Nie udało się zaktualizować szkicu WZ po trzech próbach: '.$exception->getMessage();
            }
        } else {
            $warnings[] = 'Szkic WZ nie został zmieniony, ponieważ nie udało się najpierw przeliczyć rezerwacji.';
        }

        try {
            retry(3, function () use ($freshOrder, $lineUpdate): void {
                if ($lineUpdate['fulfillment_changed']) {
                    $this->packingTasks->resetForOrderEdit($freshOrder);
                }

                $this->packingTasks->syncForOrder($freshOrder);
            }, 100);
        } catch (Throwable $exception) {
            $warnings[] = 'Nie udało się odświeżyć pakowania po trzech próbach: '.$exception->getMessage();
        }

        $freshOrder = $freshOrder->fresh('lines');
        $this->audit->record($auditEvent, $freshOrder, $before, $this->auditSnapshot($freshOrder), [
            'source' => 'order_editor',
            'warnings' => $warnings,
            'picking_reset' => $lineUpdate['fulfillment_changed'] && $availability['resets_picking'],
        ]);
        $syncStatus = $warnings === [] ? 'success' : 'partial';
        $this->syncLog(
            $integration,
            $freshOrder,
            $operation,
            $syncStatus,
            $startedAt,
            $payload,
            $response,
            $warnings === [] ? null : implode(' | ', $warnings),
        );

        return [
            'updated' => $lineUpdate['updated'],
            'removed' => $lineUpdate['removed'],
            'added' => $lineUpdate['added'],
            'lines_changed' => $lineUpdate['lines_changed'],
            'fulfillment_changed' => $lineUpdate['fulfillment_changed'],
            'warnings' => $warnings,
        ];
    }

    /** @return array<string, mixed> */
    private function assertRemoteVersion(
        WordpressIntegration $integration,
        ExternalOrder $order,
        ?string $expectedRemoteModifiedAt,
    ): array {
        $expected = trim((string) $expectedRemoteModifiedAt);
        $remote = $this->client->order($integration, (string) $order->external_id);
        $actual = trim((string) ($remote['date_modified_gmt'] ?? $remote['date_modified'] ?? ''));

        if ($expected !== '' && $actual !== '' && ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Zamówienie zostało w międzyczasie zmienione w WooCommerce. Odśwież edytor, aby nie nadpisać nowszych danych.');
        }

        return $remote;
    }

    /** @param array<string, mixed> $remoteOrder @param array<string, mixed> $details */
    private function assertPaymentMethodChangeAllowed(
        ExternalOrder $order,
        array $remoteOrder,
        array $details,
    ): void {
        $isPaid = $this->paymentMethodLocked($order)
            || filled($remoteOrder['date_paid'] ?? null)
            || filled($remoteOrder['date_paid_gmt'] ?? null)
            || filled($remoteOrder['transaction_id'] ?? null);

        if (! $isPaid) {
            return;
        }

        foreach (['payment_method', 'payment_method_title'] as $field) {
            $submitted = trim((string) ($details[$field] ?? ''));
            $remote = trim((string) ($remoteOrder[$field] ?? ''));

            if ($submitted !== $remote) {
                throw new RuntimeException('Po opłaceniu zamówienia nie można zmienić metody płatności, ponieważ późniejszy zwrot musi trafić do pierwotnej bramki.');
            }
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $newLine
     * @return array{payload:list<array<string, mixed>>,updated:int,removed:int,added:int,lines_changed:bool,fulfillment_changed:bool}
     */
    private function linePayload(
        ExternalOrder $order,
        array $lines,
        array $newLine,
        bool $requireProductChangePrices,
    ): array {
        $requestedIds = collect(array_keys($lines))->map(fn (mixed $id): int => (int) $id)->sort()->values();
        $currentIds = $order->lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values();

        if ($requestedIds->all() !== $currentIds->all()) {
            throw new RuntimeException('Lista pozycji zmieniła się. Odśwież zamówienie i spróbuj ponownie.');
        }

        $payload = [];
        $updated = 0;
        $removed = 0;
        $active = 0;
        $linesChanged = false;
        $fulfillmentChanged = false;

        foreach ($order->lines as $line) {
            $requested = (array) ($lines[$line->id] ?? []);
            $externalLineId = trim((string) $line->external_line_id);

            if ($externalLineId === '' || ! ctype_digit($externalLineId)) {
                throw new RuntimeException("Pozycja {$line->name} nie ma poprawnego identyfikatora WooCommerce.");
            }

            if ((bool) ($requested['remove'] ?? false)) {
                $payload[] = ['id' => (int) $externalLineId, 'quantity' => 0];
                $removed++;
                $linesChanged = true;
                $fulfillmentChanged = true;

                continue;
            }

            $active++;
            $requestedProductId = (int) ($requested['product_id'] ?? 0);
            $quantity = (float) ($requested['quantity'] ?? 0);
            $productChanged = $requestedProductId > 0
                && (int) $line->product_id !== $requestedProductId;

            if ($quantity <= 0) {
                throw new RuntimeException("Ilość pozycji {$line->name} musi być większa od zera.");
            }

            if ($requireProductChangePrices
                && $productChanged
                && (($requested['subtotal'] ?? '') === '' || ($requested['total'] ?? '') === '')) {
                throw new RuntimeException(
                    "Po zmianie produktu w pozycji {$line->name} podaj sumę przed rabatem i po rabacie.",
                );
            }

            $item = [
                'id' => (int) $externalLineId,
                'quantity' => $quantity,
            ];

            if ($productChanged) {
                $mapping = $this->mapping($requestedProductId, (int) $order->sales_channel_id);
                $item['product_id'] = (int) $mapping->external_product_id;
                $item['variation_id'] = filled($mapping->external_variation_id)
                    ? (int) $mapping->external_variation_id
                    : 0;
            }

            $this->applyLineTotals($item, $requested, $line, $quantity, $productChanged);

            $quantityChanged = abs((float) $line->quantity - $quantity) > 0.0000001;
            $totalsChanged = $this->lineTotalsChanged($line, $item);
            $itemChanged = $productChanged || $quantityChanged || $totalsChanged;

            if ($itemChanged) {
                $payload[] = $item;
                $updated++;
            }

            $fulfillmentChanged = $fulfillmentChanged || $productChanged || $quantityChanged;
            $linesChanged = $linesChanged || $itemChanged;
        }

        $added = 0;

        if (filled($newLine['product_id'] ?? null)) {
            $mapping = $this->mapping((int) $newLine['product_id'], (int) $order->sales_channel_id);
            $quantity = (float) ($newLine['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw new RuntimeException('Ilość nowej pozycji musi być większa od zera.');
            }

            $item = [
                'product_id' => (int) $mapping->external_product_id,
                'variation_id' => filled($mapping->external_variation_id) ? (int) $mapping->external_variation_id : 0,
                'quantity' => $quantity,
            ];

            foreach (['subtotal', 'total'] as $field) {
                if (($newLine[$field] ?? '') !== '') {
                    $item[$field] = $this->moneyString((float) $newLine[$field]);
                }
            }

            $payload[] = $item;
            $added = 1;
            $linesChanged = true;
            $fulfillmentChanged = true;
        }

        if (($active + $added) < 1) {
            throw new RuntimeException('Zamówienie musi zawierać co najmniej jedną pozycję.');
        }

        return compact('payload', 'updated', 'removed', 'added') + [
            'lines_changed' => $linesChanged,
            'fulfillment_changed' => $fulfillmentChanged,
        ];
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $requested */
    private function applyLineTotals(
        array &$item,
        array $requested,
        ExternalOrderLine $line,
        float $newQuantity,
        bool $productChanged,
    ): void {
        foreach (['subtotal', 'total'] as $field) {
            if (($requested[$field] ?? '') !== '') {
                $item[$field] = $this->moneyString((float) $requested[$field]);

                continue;
            }

            // Stary, skrócony edytor pozycji nie pokazuje cen. Przy wymianie
            // produktu pozwalamy wtedy WooCommerce wyliczyć cenę nowego SKU.
            if ($productChanged) {
                continue;
            }

            $currentTotal = data_get($line->raw_payload, $field);
            $currentQuantity = (float) $line->quantity;

            if (is_numeric($currentTotal) && $currentQuantity > 0) {
                $item[$field] = $this->moneyString(((float) $currentTotal / $currentQuantity) * $newQuantity);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function lineTotalsChanged(ExternalOrderLine $line, array $payload): bool
    {
        foreach (['subtotal', 'total'] as $field) {
            if (array_key_exists($field, $payload)
                && abs((float) data_get($line->raw_payload, $field, 0) - (float) $payload[$field]) > 0.00001) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $details @return array<string, mixed> */
    private function detailsPayload(ExternalOrder $order, array $details): array
    {
        if ($details === []) {
            return [];
        }

        $payload = [
            'billing' => $this->addressPayload((array) ($details['billing'] ?? []), self::BILLING_FIELDS),
            'shipping' => $this->addressPayload((array) ($details['shipping'] ?? []), self::SHIPPING_FIELDS),
            'customer_note' => trim((string) ($details['customer_note'] ?? '')),
            'payment_method' => trim((string) ($details['payment_method'] ?? '')),
            'payment_method_title' => trim((string) ($details['payment_method_title'] ?? '')),
        ];
        $targetPoint = strtoupper(trim((string) ($details['target_point'] ?? '')));
        $metadata = $this->controlledMetadataPayload(
            $order,
            trim((string) ($details['billing_tax_id'] ?? '')),
            $targetPoint,
        );

        if ($metadata !== []) {
            $payload['meta_data'] = $metadata;
        }

        if (array_key_exists('shipping_line', $details)) {
            $shippingLine = $this->shippingLinePayload(
                $order,
                (array) $details['shipping_line'],
                $targetPoint,
            );

            if ($shippingLine !== null) {
                $payload['shipping_lines'] = [$shippingLine];
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $data @param list<string> $fields @return array<string, string> */
    private function addressPayload(array $data, array $fields): array
    {
        return collect($fields)
            ->mapWithKeys(function (string $field) use ($data): array {
                $value = trim((string) ($data[$field] ?? ''));

                return [$field => $field === 'country' ? strtoupper($value) : $value];
            })
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function controlledMetadataPayload(ExternalOrder $order, string $taxId, string $targetPoint): array
    {
        $current = collect((array) data_get($order->raw_payload, 'meta_data', []))
            ->filter(fn (mixed $meta): bool => is_array($meta))
            ->values();
        $payload = [];
        $taxEntries = $current->filter(fn (array $meta): bool => in_array((string) ($meta['key'] ?? ''), self::BILLING_TAX_META_KEYS, true));

        if ($taxEntries->isEmpty() && $taxId !== '') {
            $payload[] = ['key' => 'billing_nip', 'value' => $taxId];
        } else {
            foreach ($taxEntries as $meta) {
                $payload[] = array_filter([
                    'id' => isset($meta['id']) ? (int) $meta['id'] : null,
                    'key' => (string) $meta['key'],
                    'value' => $taxId,
                ], fn (mixed $value): bool => $value !== null);
            }
        }

        $targetEntries = $current->filter(fn (array $meta): bool => $this->isTargetPointMetaKey((string) ($meta['key'] ?? '')));

        if ($targetEntries->isEmpty() && $targetPoint !== '') {
            $payload[] = ['key' => 'sempre_erp_target_point', 'value' => $targetPoint];
        } else {
            foreach ($targetEntries as $meta) {
                $payload[] = array_filter([
                    'id' => isset($meta['id']) ? (int) $meta['id'] : null,
                    'key' => (string) $meta['key'],
                    'value' => $targetPoint,
                ], fn (mixed $value): bool => $value !== null);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $requested @return array<string, mixed>|null */
    private function shippingLinePayload(
        ExternalOrder $order,
        array $requested,
        string $targetPoint,
    ): ?array {
        $current = $this->shippingLine($order);

        if ($current === null) {
            return null;
        }

        $currentId = (int) ($current['id'] ?? 0);

        if ($currentId < 1 || (int) ($requested['id'] ?? 0) !== $currentId) {
            throw new RuntimeException('Metoda wysyłki zmieniła się. Odśwież edytor i spróbuj ponownie.');
        }

        $payload = [
            'id' => $currentId,
            'method_id' => trim((string) ($requested['method_id'] ?? $current['method_id'] ?? '')),
            'method_title' => trim((string) ($requested['method_title'] ?? $current['method_title'] ?? '')),
            'total' => $this->moneyString((float) ($requested['total'] ?? $current['total'] ?? 0)),
        ];
        $targetMetadata = collect((array) ($current['meta_data'] ?? []))
            ->filter(fn (mixed $meta): bool => is_array($meta)
                && $this->isTargetPointMetaKey((string) ($meta['key'] ?? '')))
            ->map(fn (array $meta): array => array_filter([
                'id' => isset($meta['id']) ? (int) $meta['id'] : null,
                'key' => (string) ($meta['key'] ?? ''),
                'value' => $targetPoint,
            ], fn (mixed $value): bool => $value !== null))
            ->values()
            ->all();

        if ($targetMetadata !== []) {
            $payload['meta_data'] = $targetMetadata;
        }

        return $payload;
    }

    /**
     * @param  list<array<string, mixed>>  $responseLines
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $details
     */
    private function persistWooResponse(
        ExternalOrder $order,
        array $response,
        array $responseLines,
        array $payload,
        array $details,
    ): void {
        DB::transaction(function () use ($order, $response, $responseLines, $payload, $details): void {
            $lockedOrder = ExternalOrder::query()->lockForUpdate()->findOrFail($order->id);
            $existingLines = $lockedOrder->lines()
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (ExternalOrderLine $line): string => (string) $line->external_line_id);
            $retainedLineIds = [];
            $seenExternalLineIds = [];

            foreach ($responseLines as $responseLine) {
                if (! is_array($responseLine) || (float) ($responseLine['quantity'] ?? 0) <= 0) {
                    continue;
                }

                $externalLineId = trim((string) ($responseLine['id'] ?? ''));

                if ($externalLineId === '' || ! ctype_digit($externalLineId)) {
                    throw new RuntimeException('WooCommerce zwróciło pozycję bez poprawnego identyfikatora.');
                }

                if (isset($seenExternalLineIds[$externalLineId])) {
                    throw new RuntimeException('WooCommerce zwróciło zduplikowany identyfikator pozycji.');
                }

                $seenExternalLineIds[$externalLineId] = true;

                $product = $this->productForWooLine($responseLine, (int) $lockedOrder->sales_channel_id);
                $quantity = (float) $responseLine['quantity'];
                $line = $existingLines->get($externalLineId) ?? new ExternalOrderLine([
                    'external_order_id' => $lockedOrder->id,
                ]);

                $line->fill([
                    'product_id' => $product?->id,
                    'external_line_id' => $externalLineId,
                    'canonical_external_line_id' => $externalLineId,
                    'sku' => filled($responseLine['sku'] ?? null) ? trim((string) $responseLine['sku']) : $product?->sku,
                    'name' => trim((string) ($responseLine['name'] ?? $product?->name ?? 'Pozycja zamówienia')),
                    'quantity' => $quantity,
                    'unit_net_price' => isset($responseLine['subtotal']) ? (float) $responseLine['subtotal'] / $quantity : null,
                    'unit_gross_price' => isset($responseLine['total']) ? (float) $responseLine['total'] / $quantity : null,
                    'vat_rate' => $product?->vat_rate,
                    'raw_payload' => $responseLine,
                ]);
                $line->external_order_id = $lockedOrder->id;
                $line->save();
                $retainedLineIds[] = $line->id;
            }

            if ($retainedLineIds === []) {
                throw new RuntimeException('WooCommerce zwrócił zamówienie bez aktywnych pozycji.');
            }

            $lockedOrder->lines()
                ->whereNotIn('id', $retainedLineIds)
                ->delete();

            $raw = array_merge((array) $lockedOrder->raw_payload, $response);

            foreach (['customer_note', 'payment_method', 'payment_method_title'] as $field) {
                if (! array_key_exists($field, $response) && array_key_exists($field, $payload)) {
                    $raw[$field] = $payload[$field];
                }
            }

            if (! array_key_exists('shipping_lines', $response) && isset($payload['shipping_lines'])) {
                $raw['shipping_lines'] = $this->mergeShippingLines(
                    (array) data_get($lockedOrder->raw_payload, 'shipping_lines', []),
                    (array) $payload['shipping_lines'],
                );
            }

            if (isset($payload['meta_data']) && ! isset($response['meta_data'])) {
                $raw['meta_data'] = $this->mergeMetadata(
                    (array) data_get($lockedOrder->raw_payload, 'meta_data', []),
                    (array) $payload['meta_data'],
                );
            }

            $raw['sempre_erp_target_point'] = strtoupper(trim((string) ($details['target_point'] ?? $this->targetPoint($lockedOrder))));
            $raw['sempre_erp_manual_order_edit'] = [
                'edited_at' => now()->toISOString(),
                'source' => 'order_editor',
            ];

            // WooCommerce extensions sometimes return only the address fields
            // they changed. Replacing the complete local address with that
            // partial response can silently drop phone/e-mail/postcode and
            // makes the next courier-label request invalid.
            $billing = array_merge(
                (array) $lockedOrder->billing_data,
                (array) ($payload['billing'] ?? []),
                is_array($response['billing'] ?? null) ? $response['billing'] : [],
            );
            $shipping = array_merge(
                (array) $lockedOrder->shipping_data,
                (array) ($payload['shipping'] ?? []),
                is_array($response['shipping'] ?? null) ? $response['shipping'] : [],
            );

            if (array_key_exists('billing_tax_id', $details)) {
                $billing['billing_nip'] = trim((string) $details['billing_tax_id']);
            }

            $calculatedTotal = $lockedOrder->lines()->get()->sum(
                fn (ExternalOrderLine $line): float => (float) $line->quantity * (float) $line->unit_gross_price,
            );

            $lockedOrder->update([
                'status' => trim((string) ($response['status'] ?? $lockedOrder->status)),
                'currency' => trim((string) ($response['currency'] ?? $lockedOrder->currency)),
                'total_gross' => isset($response['total']) ? (float) $response['total'] : $calculatedTotal,
                'billing_data' => $billing,
                'shipping_data' => $shipping,
                'raw_payload' => $raw,
                'external_updated_at' => $this->responseModifiedAt($response),
            ]);
        }, 3);
    }

    /** @param list<array<string, mixed>> $existing @param list<array<string, mixed>> $updates @return list<array<string, mixed>> */
    private function mergeMetadata(array $existing, array $updates): array
    {
        $merged = collect($existing)
            ->filter(fn (mixed $meta): bool => is_array($meta))
            ->values();

        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }

            $index = $merged->search(function (array $candidate) use ($update): bool {
                if (isset($update['id']) && isset($candidate['id'])) {
                    return (int) $candidate['id'] === (int) $update['id'];
                }

                return (string) ($candidate['key'] ?? '') === (string) ($update['key'] ?? '');
            });

            if ($index === false) {
                $merged->push($update);
            } else {
                $merged->put($index, array_merge((array) $merged->get($index), $update));
            }
        }

        return $merged->values()->all();
    }

    /** @param list<array<string, mixed>> $existing @param list<array<string, mixed>> $updates @return list<array<string, mixed>> */
    private function mergeShippingLines(array $existing, array $updates): array
    {
        $merged = collect($existing)
            ->filter(fn (mixed $line): bool => is_array($line))
            ->values();

        foreach ($updates as $update) {
            if (! is_array($update)) {
                continue;
            }

            $index = $merged->search(
                fn (array $candidate): bool => (int) ($candidate['id'] ?? 0) === (int) ($update['id'] ?? 0),
            );

            if ($index === false) {
                $merged->push($update);

                continue;
            }

            $current = (array) $merged->get($index);

            if (isset($update['meta_data'])) {
                $update['meta_data'] = $this->mergeMetadata(
                    (array) ($current['meta_data'] ?? []),
                    (array) $update['meta_data'],
                );
            }

            $merged->put($index, array_merge($current, $update));
        }

        return $merged->values()->all();
    }

    private function responseModifiedAt(array $response): CarbonImmutable
    {
        $isGmt = filled($response['date_modified_gmt'] ?? null);
        $value = trim((string) ($response['date_modified_gmt'] ?? $response['date_modified'] ?? ''));

        if ($value !== '') {
            try {
                return CarbonImmutable::parse(
                    $value,
                    $isGmt ? 'UTC' : (string) config('app.timezone', 'UTC'),
                );
            } catch (Throwable) {
                // WooCommerce can be extended with a non-standard date format.
            }
        }

        return CarbonImmutable::now();
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(ExternalOrder $order): array
    {
        $order->loadMissing('lines');

        return [
            'total_gross' => $order->total_gross,
            'billing' => $order->billing_data,
            'shipping' => $order->shipping_data,
            'customer_note' => data_get($order->raw_payload, 'customer_note'),
            'payment_method' => data_get($order->raw_payload, 'payment_method'),
            'payment_method_title' => data_get($order->raw_payload, 'payment_method_title'),
            'lines' => $order->lines->map(fn (ExternalOrderLine $line): array => $line->only([
                'external_line_id', 'product_id', 'sku', 'name', 'quantity', 'unit_net_price', 'unit_gross_price',
            ]))->values()->all(),
        ];
    }

    private function integration(ExternalOrder $order): ?WordpressIntegration
    {
        if ($order->wordpress_integration_id !== null) {
            return WordpressIntegration::query()
                ->whereKey($order->wordpress_integration_id)
                ->where('sales_channel_id', $order->sales_channel_id)
                ->first();
        }

        $integrations = WordpressIntegration::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->limit(2)
            ->get();

        return $integrations->count() === 1 ? $integrations->first() : null;
    }

    private function belongsToSplitFamily(ExternalOrder $order): bool
    {
        return $order->split_parent_order_id !== null
            || $order->split_root_order_id !== null
            || $order->splitChildren()->exists()
            || data_get($order->raw_payload, 'sempre_erp_split_allocations') !== null;
    }

    /** @return array{editable:false,reason:string,resets_picking:false} */
    private function unavailable(string $reason): array
    {
        return ['editable' => false, 'reason' => $reason, 'resets_picking' => false];
    }

    private function mapping(int $productId, int $salesChannelId): ProductChannelMapping
    {
        $mapping = ProductChannelMapping::query()
            ->where('product_id', $productId)
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_product_id', '!=', '')
            ->first();

        if (! $mapping instanceof ProductChannelMapping) {
            throw new RuntimeException('Wybrany produkt nie ma mapowania WooCommerce dla kanału tego zamówienia.');
        }

        return $mapping;
    }

    /** @param array<string, mixed> $line */
    private function productForWooLine(array $line, int $salesChannelId): ?Product
    {
        $productId = trim((string) ($line['product_id'] ?? ''));
        $variationId = trim((string) ($line['variation_id'] ?? ''));

        $mapping = ProductChannelMapping::query()
            ->where('sales_channel_id', $salesChannelId)
            ->where('external_product_id', $productId)
            ->when(
                $variationId !== '' && $variationId !== '0',
                fn ($query) => $query->where('external_variation_id', $variationId),
                fn ($query) => $query->whereNull('external_variation_id'),
            )
            ->first();

        if ($mapping instanceof ProductChannelMapping) {
            return Product::query()->find($mapping->product_id);
        }

        $sku = trim((string) ($line['sku'] ?? ''));

        return $sku !== '' ? Product::query()->where('sku', $sku)->first() : null;
    }

    private function isTargetPointMetaKey(string $key): bool
    {
        $key = mb_strtolower($key);

        if (str_contains($key, 'blpaczka') && str_contains($key, 'point')) {
            return true;
        }

        foreach (['paczkomat', 'target_point', 'parcel_machine', 'locker', 'easypack'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function moneyString(float $value): string
    {
        return number_format(max(0, $value), 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>|null  $response
     */
    private function syncLog(
        WordpressIntegration $integration,
        ExternalOrder $order,
        string $operation,
        string $status,
        mixed $startedAt,
        array $requestPayload,
        ?array $response = null,
        ?string $error = null,
    ): void {
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => $operation,
            'status' => $status,
            'external_resource' => 'order',
            'external_id' => (string) $order->external_id,
            'request_payload' => $requestPayload,
            'response_payload' => $response,
            'error_message' => $error,
            'attempts' => 1,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
