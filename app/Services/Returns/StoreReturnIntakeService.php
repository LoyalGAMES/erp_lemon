<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Services\Communication\CustomerCommunicationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StoreReturnIntakeService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        private readonly ReturnNumberService $numbers,
        private readonly ReturnSettingsService $settings,
        private readonly CustomerCommunicationService $communication,
    ) {
    }

    /**
     * Znajduje zamówienie po numerze oraz kontakcie klienta (e-mail lub telefon).
     * Kontakt musi się zgadzać, żeby formularz w sklepie nie ujawniał cudzych zamówień.
     */
    public function findOrder(string $reference, string $contact): ?ExternalOrder
    {
        $reference = trim($reference);
        $contact = trim($contact);

        if ($reference === '' || $contact === '') {
            return null;
        }

        $order = ExternalOrder::query()
            ->with('lines.product')
            ->where('external_number', $reference)
            ->orWhere('external_id', $reference)
            ->first();

        if (! $order instanceof ExternalOrder || ! $this->contactMatchesOrder($order, $contact)) {
            return null;
        }

        return $order;
    }

    /**
     * Serializuje zamówienie do formatu wtyczki lemon-woo-returns.
     * items[].id musi być identyfikatorem pozycji zamówienia WooCommerce
     * (external_line_id), bo wtyczka używa go do natywnego refundu.
     *
     * @return array<string, mixed>
     */
    public function serializeOrderForStore(ExternalOrder $order): array
    {
        $order->loadMissing('lines.product');
        $returned = $this->returnedQuantities($order);

        return [
            'source' => 'erp',
            'order_id' => (string) $order->external_id,
            'order_reference' => (string) ($order->external_number ?: $order->external_id),
            'order_number' => (string) ($order->external_number ?: $order->external_id),
            'currency' => (string) $order->currency,
            'customer_email' => (string) data_get($order->billing_data, 'email', ''),
            'customer_phone' => (string) (data_get($order->billing_data, 'phone') ?: data_get($order->shipping_data, 'phone', '')),
            'items' => $order->lines
                ->filter(fn (ExternalOrderLine $line): bool => (float) $line->quantity > 0)
                ->map(function (ExternalOrderLine $line) use ($returned): array {
                    $remaining = max(0, (float) $line->quantity - $this->returnedQuantityForLine($line, $returned));

                    return [
                        'id' => (string) ($line->external_line_id ?: 'line-'.$line->id),
                        'name' => (string) $line->name,
                        'sku' => (string) ($line->sku ?? ''),
                        'quantity' => (int) floor($remaining),
                        'image' => (string) data_get($line->raw_payload, 'image.src', ''),
                        'price' => (float) ($line->unit_gross_price ?? 0),
                    ];
                })
                ->filter(fn (array $item): bool => $item['quantity'] > 0)
                ->values()
                ->all(),
        ];
    }

    /**
     * Tworzy zgłoszenie zwrotu ze sklepu jako ReturnCase w statusie pending.
     * Idempotentne po return_reference — ponowiona próba zwraca istniejące zgłoszenie.
     *
     * @param array<string, mixed> $payload
     */
    public function createFromStorePayload(array $payload): ReturnCase
    {
        $returnReference = trim((string) ($payload['return_reference'] ?? ''));

        if ($returnReference !== '') {
            $existing = $this->findByReference($returnReference, null);

            if ($existing instanceof ReturnCase) {
                return $existing;
            }
        }

        $order = $this->resolveOrderForPayload($payload);
        $items = $this->matchItems($order, (array) ($payload['items'] ?? []));

        if ($items === []) {
            throw new RuntimeException('Zgłoszenie nie zawiera pozycji możliwych do zwrotu.');
        }

        $settings = $this->settings->data();
        $contact = trim((string) ($payload['customer_contact'] ?? ''));
        $email = trim((string) ($payload['customer_email'] ?? ''));

        if ($email === '' && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $contact;
        }

        $returnCase = DB::transaction(function () use ($payload, $order, $items, $settings, $contact, $email, $returnReference): ReturnCase {
            $returnCase = ReturnCase::query()->create([
                'number' => $this->numbers->next(),
                'external_order_id' => $order?->id,
                'target_warehouse_id' => $settings['default_target_warehouse_id'],
                'status' => self::STATUS_PENDING,
                'reason' => $this->dominantReason($items),
                'customer_email' => $email !== '' ? mb_substr($email, 0, 255) : null,
                'notes' => filled($payload['customer_note'] ?? null)
                    ? mb_substr(trim((string) $payload['customer_note']), 0, 2000)
                    : null,
                'metadata' => [
                    'source' => 'store_form',
                    'return_reference' => $returnReference !== '' ? $returnReference : null,
                    'local_return_id' => $payload['local_return_id'] ?? null,
                    'site_url' => filled($payload['site_url'] ?? null) ? (string) $payload['site_url'] : null,
                    'return_method' => filled($payload['return_method'] ?? null) ? (string) $payload['return_method'] : null,
                    'customer_contact' => $contact !== '' ? $contact : null,
                    'customer_phone' => filled($payload['customer_phone'] ?? null) ? (string) $payload['customer_phone'] : null,
                    'external_order_number' => (string) ($payload['order_number'] ?? $payload['order_reference'] ?? ''),
                    'store_order_id' => filled($payload['order_id'] ?? null) ? (string) $payload['order_id'] : null,
                ],
            ]);

            foreach ($items as $item) {
                $returnCase->lines()->create([
                    'product_id' => $item['order_line']?->product_id,
                    'external_order_line_id' => $item['order_line']?->id,
                    'quantity_expected' => $item['quantity'],
                    'quantity_accepted' => $item['quantity'],
                    'condition' => $settings['default_condition'],
                    'disposition' => $settings['default_disposition'],
                    'target_warehouse_id' => $settings['default_target_warehouse_id'],
                    'notes' => $item['reason'] !== '' ? mb_substr('Powód klienta: '.$item['reason'], 0, 2000) : null,
                    'metadata' => [
                        'created_from' => 'store_form',
                        'store_item_id' => $item['id'],
                        'store_item_name' => $item['name'],
                        'store_item_sku' => $item['sku'],
                        'store_item_reason' => $item['reason'],
                    ],
                ]);
            }

            return $returnCase;
        });

        $this->communication->sendReturnStatus($returnCase, 'return_waiting_for_package');

        return $returnCase;
    }

    public function findByReference(?string $returnReference, ?string $externalId): ?ReturnCase
    {
        $returnReference = trim((string) $returnReference);
        $externalId = trim((string) $externalId);

        if ($returnReference !== '') {
            $byReference = ReturnCase::query()
                ->where('metadata->return_reference', $returnReference)
                ->first();

            if ($byReference instanceof ReturnCase) {
                return $byReference;
            }
        }

        if ($externalId !== '') {
            return ReturnCase::query()->where('number', $externalId)->first();
        }

        return null;
    }

    /**
     * Mapuje status ERP na surowy status rozumiany przez wtyczkę.
     * "Zwrot zrealizowany" jest na domyślnej liście statusów finalnych wtyczki.
     */
    public function statusForStore(ReturnCase $returnCase): string
    {
        return match ($returnCase->status) {
            self::STATUS_PENDING => 'pending_package',
            self::STATUS_COMPLETED => 'Zwrot zrealizowany',
            self::STATUS_REJECTED => 'rejected',
            'cancelled' => 'cancelled',
            default => 'processing',
        };
    }

    private function contactMatchesOrder(ExternalOrder $order, string $contact): bool
    {
        $normalized = mb_strtolower(trim($contact));
        $email = mb_strtolower((string) data_get($order->billing_data, 'email', ''));

        if ($email !== '' && $email === $normalized) {
            return true;
        }

        $contactDigits = preg_replace('/\D+/', '', $contact) ?? '';

        if (mb_strlen($contactDigits) < 7) {
            return false;
        }

        foreach ([
            data_get($order->billing_data, 'phone'),
            data_get($order->shipping_data, 'phone'),
        ] as $phone) {
            $phoneDigits = preg_replace('/\D+/', '', (string) $phone) ?? '';

            if ($phoneDigits !== '' && (
                str_ends_with($phoneDigits, $contactDigits) || str_ends_with($contactDigits, $phoneDigits)
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveOrderForPayload(array $payload): ?ExternalOrder
    {
        foreach (['order_reference', 'order_number', 'order_id'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            $order = ExternalOrder::query()
                ->with('lines.product')
                ->where('external_number', $value)
                ->orWhere('external_id', $value)
                ->first();

            if ($order instanceof ExternalOrder) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Dopasowuje pozycje zgłoszenia do linii zamówienia i przycina ilości
     * do wartości pozostałych do zwrotu.
     *
     * @param array<int, mixed> $items
     * @return list<array{id:string,name:string,sku:string,reason:string,quantity:float,order_line:?ExternalOrderLine}>
     */
    private function matchItems(?ExternalOrder $order, array $items): array
    {
        $returned = $order instanceof ExternalOrder ? $this->returnedQuantities($order) : [];
        $matched = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $itemId = trim((string) ($item['id'] ?? ''));
            $sku = trim((string) ($item['sku'] ?? ''));
            $orderLine = $order instanceof ExternalOrder ? $this->matchOrderLine($order, $itemId, $sku) : null;

            if ($orderLine instanceof ExternalOrderLine) {
                $remaining = max(0, (float) $orderLine->quantity - $this->returnedQuantityForLine($orderLine, $returned));
                $quantity = min($quantity, $remaining);

                if ($quantity <= 0) {
                    continue;
                }
            }

            $matched[] = [
                'id' => $itemId,
                'name' => mb_substr(trim((string) ($item['name'] ?? '')), 0, 255),
                'sku' => mb_substr($sku, 0, 120),
                'reason' => mb_substr(trim((string) ($item['reason'] ?? '')), 0, 255),
                'quantity' => $quantity,
                'order_line' => $orderLine,
            ];
        }

        return $matched;
    }

    private function matchOrderLine(ExternalOrder $order, string $itemId, string $sku): ?ExternalOrderLine
    {
        $order->loadMissing('lines');

        if ($itemId !== '') {
            $line = $order->lines->first(
                fn (ExternalOrderLine $line): bool => (string) $line->external_line_id === $itemId
                    || 'line-'.$line->id === $itemId,
            );

            if ($line instanceof ExternalOrderLine) {
                return $line;
            }
        }

        if ($sku !== '') {
            return $order->lines->first(
                fn (ExternalOrderLine $line): bool => (string) $line->sku === $sku,
            );
        }

        return null;
    }

    /**
     * @param list<array{reason:string}> $items
     */
    private function dominantReason(array $items): ?string
    {
        foreach ($items as $item) {
            if ($item['reason'] !== '') {
                return $item['reason'];
            }
        }

        return null;
    }

    /**
     * @return array<string, float>
     */
    private function returnedQuantities(ExternalOrder $order): array
    {
        $rows = ReturnCaseLine::query()
            ->selectRaw('external_order_line_id, product_id, SUM(quantity_accepted) as quantity')
            ->whereHas('returnCase', function ($query) use ($order): void {
                $query->where('external_order_id', $order->id)
                    ->where('status', '!=', self::STATUS_REJECTED);
            })
            ->groupBy('external_order_line_id', 'product_id')
            ->get();

        $quantities = [];

        foreach ($rows as $row) {
            if ($row->external_order_line_id !== null) {
                $quantities['line:'.$row->external_order_line_id] = (float) $row->quantity;

                continue;
            }

            if ($row->product_id !== null) {
                $key = 'product:'.$row->product_id;
                $quantities[$key] = ($quantities[$key] ?? 0) + (float) $row->quantity;
            }
        }

        return $quantities;
    }

    /**
     * @param array<string, float> $returned
     */
    private function returnedQuantityForLine(ExternalOrderLine $line, array $returned): float
    {
        return (float) ($returned['line:'.$line->id]
            ?? ($line->product_id !== null ? ($returned['product:'.$line->product_id] ?? 0) : 0));
    }
}
