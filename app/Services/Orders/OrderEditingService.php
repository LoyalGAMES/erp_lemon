<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\IntegrationSyncLog;
use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\WordpressIntegration;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\StockReservationService;
use App\Services\Packing\PackingTaskService;
use App\Services\WooCommerce\WooCommerceClient;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class OrderEditingService
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly OrderFulfillmentStatusService $fulfillmentStatus,
        private readonly StockReservationService $reservations,
        private readonly PackingTaskService $packingTasks,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{editable:bool,reason:?string}
     */
    public function availability(ExternalOrder $order): array
    {
        if (! $this->integration($order) instanceof WordpressIntegration) {
            return ['editable' => false, 'reason' => 'Brak aktywnej integracji WooCommerce dla kanału zamówienia.'];
        }

        if ($order->invoices()->exists()) {
            return ['editable' => false, 'reason' => 'Pozycje są zablokowane, ponieważ dla zamówienia istnieje już faktura lub proforma.'];
        }

        if ($this->fulfillmentStatus->wzDocumentsForOrder($order)->exists()) {
            return ['editable' => false, 'reason' => 'Pozycje są zablokowane, ponieważ dla zamówienia istnieje już dokument WZ.'];
        }

        if ($order->shipmentLabels()->whereIn('status', ['generated', 'picked_up'])->exists()) {
            return ['editable' => false, 'reason' => 'Pozycje są zablokowane po wygenerowaniu przesyłki.'];
        }

        if ($order->packingTasks()->whereIn('status', ['picked', 'packed', 'shipped'])->exists()) {
            return ['editable' => false, 'reason' => 'Pozycje są zablokowane, ponieważ rozpoczęto już kompletację lub wysyłkę.'];
        }

        if ($order->lines()->where(function ($query): void {
            $query->whereNull('external_line_id')->orWhere('external_line_id', '');
        })->exists()) {
            return ['editable' => false, 'reason' => 'To zamówienie zawiera lokalne pozycje bez identyfikatora WooCommerce.'];
        }

        return ['editable' => true, 'reason' => null];
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $newLine
     * @return array{updated:int,removed:int,added:int,warnings:list<string>}
     */
    public function updateLines(ExternalOrder $order, array $lines, array $newLine = []): array
    {
        $availability = $this->availability($order);

        if (! $availability['editable']) {
            throw new RuntimeException((string) $availability['reason']);
        }

        $integration = $this->integration($order);

        if (! $integration instanceof WordpressIntegration) {
            throw new RuntimeException('Brak aktywnej integracji WooCommerce dla kanału zamówienia.');
        }

        $order->load('lines');
        $requestedIds = collect(array_keys($lines))->map(fn (mixed $id): int => (int) $id)->sort()->values();
        $currentIds = $order->lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values();

        if ($requestedIds->all() !== $currentIds->all()) {
            throw new RuntimeException('Lista pozycji zmieniła się. Odśwież zamówienie i spróbuj ponownie.');
        }

        $payload = [];
        $updated = 0;
        $removed = 0;

        foreach ($order->lines as $line) {
            $requested = (array) ($lines[$line->id] ?? []);
            $externalLineId = trim((string) $line->external_line_id);

            if ($externalLineId === '' || ! ctype_digit($externalLineId)) {
                throw new RuntimeException("Pozycja {$line->name} nie ma poprawnego identyfikatora WooCommerce.");
            }

            if ((bool) ($requested['remove'] ?? false)) {
                $payload[] = ['id' => (int) $externalLineId, 'quantity' => 0];
                $removed++;

                continue;
            }

            $mapping = $this->mapping((int) ($requested['product_id'] ?? 0), (int) $order->sales_channel_id);
            $quantity = (float) ($requested['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw new RuntimeException("Ilość pozycji {$line->name} musi być większa od zera.");
            }

            $payload[] = array_filter([
                'id' => (int) $externalLineId,
                'product_id' => (int) $mapping->external_product_id,
                'variation_id' => filled($mapping->external_variation_id) ? (int) $mapping->external_variation_id : 0,
                'quantity' => $quantity,
            ], fn (mixed $value): bool => $value !== null);
            $updated++;
        }

        $added = 0;

        if (filled($newLine['product_id'] ?? null)) {
            $mapping = $this->mapping((int) $newLine['product_id'], (int) $order->sales_channel_id);
            $quantity = (float) ($newLine['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw new RuntimeException('Ilość nowej pozycji musi być większa od zera.');
            }

            $payload[] = [
                'product_id' => (int) $mapping->external_product_id,
                'variation_id' => filled($mapping->external_variation_id) ? (int) $mapping->external_variation_id : 0,
                'quantity' => $quantity,
            ];
            $added = 1;
        }

        if (($updated + $added) < 1) {
            throw new RuntimeException('Zamówienie musi zawierać co najmniej jedną pozycję.');
        }

        $startedAt = now();

        try {
            $response = $this->client->updateOrder($integration, (string) $order->external_id, [
                'line_items' => $payload,
            ]);
        } catch (Throwable $exception) {
            $this->syncLog($integration, $order, 'failed', $startedAt, $payload, error: $exception->getMessage());

            throw $exception;
        }

        $responseLines = $response['line_items'] ?? null;

        if (! is_array($responseLines)) {
            $this->syncLog($integration, $order, 'failed', $startedAt, $payload, $response, 'WooCommerce nie zwrócił aktualnej listy pozycji.');

            throw new RuntimeException('WooCommerce zapisał zmianę, ale nie zwrócił aktualnej listy pozycji. Odśwież import zamówień.');
        }

        $before = [
            'total_gross' => $order->total_gross,
            'lines' => $order->lines->map(fn (ExternalOrderLine $line): array => $line->only([
                'external_line_id', 'product_id', 'sku', 'name', 'quantity',
            ]))->values()->all(),
        ];

        DB::transaction(function () use ($order, $response, $responseLines): void {
            $lockedOrder = ExternalOrder::query()->lockForUpdate()->findOrFail($order->id);
            $lockedOrder->lines()->delete();

            foreach ($responseLines as $responseLine) {
                if (! is_array($responseLine) || (float) ($responseLine['quantity'] ?? 0) <= 0) {
                    continue;
                }

                $product = $this->productForWooLine($responseLine, (int) $lockedOrder->sales_channel_id);
                $quantity = (float) $responseLine['quantity'];

                $lockedOrder->lines()->create([
                    'product_id' => $product?->id,
                    'external_line_id' => isset($responseLine['id']) ? (string) $responseLine['id'] : null,
                    'sku' => filled($responseLine['sku'] ?? null) ? trim((string) $responseLine['sku']) : $product?->sku,
                    'name' => trim((string) ($responseLine['name'] ?? $product?->name ?? 'Pozycja zamówienia')),
                    'quantity' => $quantity,
                    'unit_net_price' => isset($responseLine['subtotal']) ? (float) $responseLine['subtotal'] / $quantity : null,
                    'unit_gross_price' => isset($responseLine['total']) ? (float) $responseLine['total'] / $quantity : null,
                    'vat_rate' => $product?->vat_rate,
                    'raw_payload' => $responseLine,
                ]);
            }

            if (! $lockedOrder->lines()->exists()) {
                throw new RuntimeException('WooCommerce zwrócił zamówienie bez aktywnych pozycji.');
            }

            $raw = array_merge((array) $lockedOrder->raw_payload, $response);
            $raw['sempre_erp_manual_line_edit'] = [
                'edited_at' => now()->toISOString(),
                'source' => 'order_view',
            ];

            $calculatedTotal = $lockedOrder->lines()->get()->sum(
                fn (ExternalOrderLine $line): float => (float) $line->quantity * (float) $line->unit_gross_price,
            );

            $lockedOrder->update([
                'status' => trim((string) ($response['status'] ?? $lockedOrder->status)),
                'total_gross' => isset($response['total']) ? (float) $response['total'] : $calculatedTotal,
                'raw_payload' => $raw,
                'external_updated_at' => now(),
            ]);
        }, 3);

        $warnings = [];
        $freshOrder = $order->fresh('lines');

        try {
            $this->reservations->syncForOrder($freshOrder);
        } catch (Throwable $exception) {
            $warnings[] = 'Nie udało się od razu przeliczyć rezerwacji: '.$exception->getMessage();
        }

        try {
            $this->packingTasks->syncForOrder($freshOrder);
        } catch (Throwable $exception) {
            $warnings[] = 'Nie udało się od razu odświeżyć pakowania: '.$exception->getMessage();
        }

        $after = [
            'total_gross' => $freshOrder->total_gross,
            'lines' => $freshOrder->lines->map(fn (ExternalOrderLine $line): array => $line->only([
                'external_line_id', 'product_id', 'sku', 'name', 'quantity',
            ]))->values()->all(),
        ];

        $this->audit->record('order.lines_updated', $freshOrder, $before, $after, [
            'source' => 'order_view',
            'warnings' => $warnings,
        ]);
        $this->syncLog($integration, $freshOrder, 'success', $startedAt, $payload, $response);

        return compact('updated', 'removed', 'added', 'warnings');
    }

    private function integration(ExternalOrder $order): ?WordpressIntegration
    {
        return WordpressIntegration::query()
            ->where('sales_channel_id', $order->sales_channel_id)
            ->first();
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

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @param  array<string, mixed>|null  $response
     */
    private function syncLog(
        WordpressIntegration $integration,
        ExternalOrder $order,
        string $status,
        mixed $startedAt,
        array $lineItems,
        ?array $response = null,
        ?string $error = null,
    ): void {
        IntegrationSyncLog::query()->create([
            'sales_channel_id' => $order->sales_channel_id,
            'wordpress_integration_id' => $integration->id,
            'direction' => 'out',
            'operation' => 'order_lines_manual_update',
            'status' => $status,
            'external_resource' => 'order',
            'external_id' => (string) $order->external_id,
            'request_payload' => ['line_items' => $lineItems],
            'response_payload' => $response,
            'error_message' => $error,
            'attempts' => 1,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }
}
