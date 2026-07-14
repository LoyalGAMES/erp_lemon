<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Jobs\SendReturnWaitingForPackageMailJob;
use App\Models\ExternalOrder;
use App\Models\ExternalOrderLine;
use App\Models\ReturnCase;
use App\Models\ReturnCaseLine;
use App\Services\Orders\OrderCancellationGuard;
use App\Services\Orders\OrderMutationLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
        private readonly OrderMutationLock $orderLock,
        private readonly OrderCancellationGuard $cancellationGuard,
    ) {}

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

        $matchedOrder = ExternalOrder::query()
            ->where('external_number', $reference)
            ->orWhere('external_id', $reference)
            ->first();

        if (! $matchedOrder instanceof ExternalOrder) {
            return null;
        }

        $rootOrder = $this->rootOrder($matchedOrder);

        if (! $this->contactMatchesOrder($rootOrder, $contact)
            && ! $this->contactMatchesOrder($matchedOrder, $contact)) {
            return null;
        }

        return $rootOrder->load('lines.product');
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
        $rootOrder = $this->rootOrder($order);
        $state = $this->familyAvailability($rootOrder);

        return [
            'source' => 'erp',
            'order_id' => (string) $rootOrder->external_id,
            'wc_order_id' => (string) $rootOrder->external_id,
            'return_order_key' => $this->returnOrderKey($rootOrder),
            'order_reference' => (string) ($rootOrder->external_number ?: $rootOrder->external_id),
            'order_number' => (string) ($rootOrder->external_number ?: $rootOrder->external_id),
            'currency' => (string) $rootOrder->currency,
            'customer_email' => (string) data_get($rootOrder->billing_data, 'email', ''),
            'customer_phone' => (string) (data_get($rootOrder->billing_data, 'phone') ?: data_get($rootOrder->shipping_data, 'phone', '')),
            'accounted_return_references' => $state['accounted_return_references'],
            'items' => collect($state['groups'])
                ->map(function (array $group): array {
                    /** @var ExternalOrderLine $line */
                    $line = $group['lines']->first();

                    return [
                        'id' => $group['canonical_id'],
                        'return_item_key' => $group['canonical_id'],
                        'wc_order_item_id' => $group['canonical_id'],
                        'name' => (string) $line->name,
                        'sku' => (string) ($line->sku ?? ''),
                        'quantity' => (int) floor($group['available_quantity']),
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
     * @param  array<string, mixed>  $payload
     */
    public function createFromStorePayload(array $payload): ReturnCase
    {
        $returnReference = trim((string) ($payload['return_reference'] ?? ''));

        if ($returnReference === '') {
            throw new RuntimeException('Brak identyfikatora zgłoszenia zwrotu ze sklepu.');
        }

        $existingReturn = ReturnCase::query()
            ->where('store_return_reference', $returnReference)
            ->first();

        if ($existingReturn instanceof ReturnCase) {
            return $existingReturn;
        }

        $order = $this->resolveOrderForPayload($payload);

        if (! $order instanceof ExternalOrder) {
            throw new RuntimeException('Nie znaleziono zamówienia wskazanego w zgłoszeniu zwrotu.');
        }

        $settings = $this->settings->data();
        $contact = trim((string) ($payload['customer_contact'] ?? ''));
        $email = trim((string) ($payload['customer_email'] ?? ''));

        if ($email === '' && filter_var($contact, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $contact;
        }

        $created = false;

        try {
            $returnCase = $this->orderLock->forOrderFamily(
                $order,
                function () use ($payload, $order, $settings, $contact, $email, $returnReference, &$created): ReturnCase {
                    return DB::transaction(function () use ($payload, $order, $settings, $contact, $email, $returnReference, &$created): ReturnCase {
                        $rootOrderId = (int) ($order->split_root_order_id ?: $order->id);
                        $order = ExternalOrder::query()
                            ->lockForUpdate()
                            ->findOrFail($rootOrderId);
                        $existing = ReturnCase::query()
                            ->where('store_return_reference', $returnReference)
                            ->lockForUpdate()
                            ->first();

                        if ($existing instanceof ReturnCase) {
                            return $existing;
                        }

                        $this->cancellationGuard->assertReturnAllowed($order);
                        $family = $this->familyOrders($order, true);
                        $state = $this->familyAvailability($order, $family);
                        $items = $this->matchItems($state, (array) ($payload['items'] ?? []));

                        if ($items === []) {
                            throw new RuntimeException('Zgłoszenie nie zawiera pozycji możliwych do zwrotu.');
                        }

                        $returnCase = ReturnCase::query()->create([
                            'number' => $this->numbers->next(),
                            'store_return_reference' => $returnReference,
                            'external_order_id' => $order->id,
                            'target_warehouse_id' => $settings['default_target_warehouse_id'],
                            'status' => self::STATUS_PENDING,
                            'reason' => $this->dominantReason($items),
                            'customer_email' => $email !== '' ? mb_substr($email, 0, 255) : null,
                            'notes' => filled($payload['customer_note'] ?? null)
                                ? mb_substr(trim((string) $payload['customer_note']), 0, 2000)
                                : null,
                            'metadata' => [
                                'source' => 'store_form',
                                'return_reference' => $returnReference,
                                'local_return_id' => $payload['local_return_id'] ?? null,
                                'site_url' => filled($payload['site_url'] ?? null) ? (string) $payload['site_url'] : null,
                                'return_method' => filled($payload['return_method'] ?? null) ? (string) $payload['return_method'] : null,
                                'customer_contact' => $contact !== '' ? $contact : null,
                                'customer_phone' => filled($payload['customer_phone'] ?? null) ? (string) $payload['customer_phone'] : null,
                                'external_order_number' => (string) ($payload['order_number'] ?? $payload['order_reference'] ?? ''),
                                'store_order_id' => filled($payload['order_id'] ?? null) ? (string) $payload['order_id'] : null,
                                'return_order_key' => $this->returnOrderKey($order),
                                'split_family_order_ids' => $family->pluck('id')->values()->all(),
                            ],
                        ]);

                        foreach ($items as $item) {
                            $returnCase->lines()->create([
                                'product_id' => $item['order_line']?->product_id,
                                'external_order_line_id' => $item['order_line']?->id,
                                'canonical_external_line_id' => $item['canonical_id'],
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
                                    'canonical_external_line_id' => $item['canonical_id'],
                                    'physical_external_order_id' => $item['order_line']?->external_order_id,
                                ],
                            ]);
                        }

                        $created = true;

                        return $returnCase;
                    });
                },
            );
        } catch (QueryException $exception) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            if (! in_array($sqlState, ['19', '23000', '23505'], true)) {
                throw $exception;
            }

            $returnCase = ReturnCase::query()->where('store_return_reference', $returnReference)->first();

            if (! $returnCase instanceof ReturnCase) {
                throw $exception;
            }
        }

        if ($created) {
            SendReturnWaitingForPackageMailJob::dispatch((int) $returnCase->id)->afterCommit();
        }

        return $returnCase;
    }

    public function findByReference(?string $returnReference, ?string $externalId): ?ReturnCase
    {
        $returnReference = trim((string) $returnReference);
        $externalId = trim((string) $externalId);

        if ($returnReference !== '') {
            $byReference = ReturnCase::query()
                ->where('store_return_reference', $returnReference)
                ->orWhere('metadata->return_reference', $returnReference)
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
     * @param  array<string, mixed>  $payload
     */
    private function resolveOrderForPayload(array $payload): ?ExternalOrder
    {
        foreach (['order_reference', 'order_number', 'order_id'] as $key) {
            $value = trim((string) ($payload[$key] ?? ''));

            if ($value === '') {
                continue;
            }

            $order = ExternalOrder::query()
                ->where('external_number', $value)
                ->orWhere('external_id', $value)
                ->first();

            if ($order instanceof ExternalOrder) {
                return $this->rootOrder($order);
            }
        }

        return null;
    }

    /**
     * Dopasowuje logiczne pozycje formularza do aktualnych fizycznych linii
     * zamówienia pierwotnego i wszystkich jego części wydzielonych.
     *
     * @param  array{groups:array<string, array<string, mixed>>}  $state
     * @param  array<int, mixed>  $items
     * @return list<array{id:string,canonical_id:string,name:string,sku:string,reason:string,quantity:float,order_line:ExternalOrderLine}>
     */
    private function matchItems(array $state, array $items): array
    {
        $matched = [];
        $seen = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = (float) ($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                continue;
            }

            $itemId = trim((string) ($item['return_item_key'] ?? $item['id'] ?? ''));
            $sku = trim((string) ($item['sku'] ?? ''));
            $canonicalId = isset($state['groups'][$itemId]) ? $itemId : '';

            if ($canonicalId === '' && $itemId === '' && $sku !== '') {
                $bySku = collect($state['groups'])
                    ->filter(function (array $group) use ($sku): bool {
                        $line = $group['lines']->first();

                        return $line instanceof ExternalOrderLine && (string) $line->sku === $sku;
                    });

                if ($bySku->count() === 1) {
                    $canonicalId = (string) $bySku->keys()->first();
                }
            }

            if ($canonicalId === '' || ! isset($state['groups'][$canonicalId])) {
                throw new RuntimeException('Wybrana pozycja nie należy do tego zamówienia. Odśwież formularz i spróbuj ponownie.');
            }

            if (isset($seen[$canonicalId])) {
                throw new RuntimeException('Ta sama pozycja została przesłana więcej niż raz. Odśwież formularz i spróbuj ponownie.');
            }

            $seen[$canonicalId] = true;
            $group = $state['groups'][$canonicalId];
            $available = (float) $group['available_quantity'];

            if ($quantity - $available > 0.00001) {
                throw new RuntimeException(sprintf(
                    'Dla pozycji „%s” dostępna liczba sztuk do zwrotu to %s. Odśwież formularz.',
                    (string) $group['lines']->first()?->name,
                    $this->formatQuantity($available),
                ));
            }

            $remaining = $quantity;
            $reason = mb_substr(trim((string) ($item['reason'] ?? '')), 0, 255);

            foreach ($group['lines'] as $orderLine) {
                $lineAvailable = (float) ($group['available_by_line'][$orderLine->id] ?? 0);
                $take = min($remaining, $lineAvailable);

                if ($take <= 0) {
                    continue;
                }

                $matched[] = [
                    'id' => $canonicalId,
                    'canonical_id' => $canonicalId,
                    'name' => mb_substr(trim((string) ($item['name'] ?? $orderLine->name)), 0, 255),
                    'sku' => mb_substr($sku !== '' ? $sku : (string) ($orderLine->sku ?? ''), 0, 120),
                    'reason' => $reason,
                    'quantity' => $take,
                    'order_line' => $orderLine,
                ];
                $remaining -= $take;

                if ($remaining <= 0.00001) {
                    break;
                }
            }

            if ($remaining > 0.00001) {
                throw new RuntimeException('Nie udało się przypisać całej ilości do pozycji zamówienia. Odśwież formularz.');
            }
        }

        return $matched;
    }

    /**
     * @param  list<array{reason:string}>  $items
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

    private function rootOrder(ExternalOrder $order): ExternalOrder
    {
        $rootId = (int) ($order->split_root_order_id ?: 0);

        if ($rootId > 0 && $rootId !== (int) $order->id) {
            $root = ExternalOrder::query()->find($rootId);

            if ($root instanceof ExternalOrder) {
                return $root;
            }
        }

        $current = $order;
        $visited = [];

        while (! isset($visited[$current->id])) {
            $visited[$current->id] = true;
            $parentId = (int) ($current->split_parent_order_id
                ?: data_get($current->raw_payload, 'sempre_erp_split.parent_order_id', 0));

            if ($parentId <= 0 || isset($visited[$parentId])) {
                break;
            }

            $parent = ExternalOrder::query()->find($parentId);

            if (! $parent instanceof ExternalOrder) {
                break;
            }

            $current = $parent;
        }

        return $current;
    }

    /**
     * @return Collection<int, ExternalOrder>
     */
    private function familyOrders(ExternalOrder $order, bool $lock = false): Collection
    {
        $root = $this->rootOrder($order);
        $query = ExternalOrder::query()
            ->with('lines.product')
            ->where(function ($query) use ($root): void {
                $query->whereKey($root->id)
                    ->orWhere('split_root_order_id', $root->id);
            })
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, ExternalOrder>|null  $family
     * @return array{groups:array<string, array<string, mixed>>,accounted_return_references:list<string>}
     */
    private function familyAvailability(ExternalOrder $order, ?Collection $family = null): array
    {
        $family ??= $this->familyOrders($order);
        $groups = [];
        $lineById = [];
        $canonicalByLineId = [];
        $availableByLine = [];

        foreach ($family as $familyOrder) {
            foreach ($familyOrder->lines->sortBy('id') as $line) {
                if ((float) $line->quantity <= 0) {
                    continue;
                }

                $canonicalId = $this->canonicalExternalLineId($line);
                $lineById[(int) $line->id] = $line;
                $canonicalByLineId[(int) $line->id] = $canonicalId;
                $availableByLine[(int) $line->id] = (float) $line->quantity;

                if (! isset($groups[$canonicalId])) {
                    $groups[$canonicalId] = [
                        'canonical_id' => $canonicalId,
                        'lines' => collect(),
                        'ordered_quantity' => 0.0,
                        'available_quantity' => 0.0,
                        'available_by_line' => [],
                    ];
                }

                $groups[$canonicalId]['lines']->push($line);
                $groups[$canonicalId]['ordered_quantity'] += (float) $line->quantity;
            }
        }

        $familyIds = $family->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $returnCases = ReturnCase::query()
            ->with('lines')
            ->whereIn('external_order_id', $familyIds)
            ->whereNotIn('status', [self::STATUS_REJECTED, 'cancelled'])
            ->get();
        $exactByLine = [];
        $unassignedByCanonical = [];
        $unassignedByProduct = [];

        foreach ($returnCases as $returnCase) {
            foreach ($returnCase->lines as $returnLine) {
                $quantity = $this->reservedQuantity($returnLine);

                if ($quantity <= 0) {
                    continue;
                }

                $lineId = (int) ($returnLine->external_order_line_id ?: 0);

                if ($lineId > 0 && isset($lineById[$lineId])) {
                    $exactByLine[$lineId] = ($exactByLine[$lineId] ?? 0) + $quantity;

                    continue;
                }

                $canonicalId = trim((string) (
                    $returnLine->canonical_external_line_id
                    ?: data_get($returnLine->metadata, 'canonical_external_line_id')
                    ?: data_get($returnLine->metadata, 'store_item_id')
                ));

                if ($canonicalId !== '') {
                    $canonicalId = $this->withoutSplitSuffix($canonicalId);

                    if (isset($groups[$canonicalId])) {
                        $unassignedByCanonical[$canonicalId] = ($unassignedByCanonical[$canonicalId] ?? 0) + $quantity;

                        continue;
                    }
                }

                if ($returnLine->product_id !== null) {
                    $productId = (int) $returnLine->product_id;
                    $unassignedByProduct[$productId] = ($unassignedByProduct[$productId] ?? 0) + $quantity;
                }
            }
        }

        foreach ($exactByLine as $lineId => $quantity) {
            $used = min($quantity, $availableByLine[$lineId] ?? 0);
            $availableByLine[$lineId] = max(0, ($availableByLine[$lineId] ?? 0) - $used);
            $excess = $quantity - $used;

            if ($excess > 0 && isset($canonicalByLineId[$lineId])) {
                $canonicalId = $canonicalByLineId[$lineId];
                $unassignedByCanonical[$canonicalId] = ($unassignedByCanonical[$canonicalId] ?? 0) + $excess;
            }
        }

        foreach ($unassignedByCanonical as $canonicalId => $quantity) {
            $this->consumeAvailability(
                $groups[$canonicalId]['lines']->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                $quantity,
                $availableByLine,
            );
        }

        foreach ($unassignedByProduct as $productId => $quantity) {
            $lineIds = collect($lineById)
                ->filter(fn (ExternalOrderLine $line): bool => (int) $line->product_id === $productId)
                ->keys()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
            $this->consumeAvailability($lineIds, $quantity, $availableByLine);
        }

        foreach ($groups as $canonicalId => &$group) {
            foreach ($group['lines'] as $line) {
                $group['available_by_line'][(int) $line->id] = (float) ($availableByLine[(int) $line->id] ?? 0);
            }

            $group['available_quantity'] = array_sum($group['available_by_line']);
        }
        unset($group);

        return [
            'groups' => $groups,
            'accounted_return_references' => $returnCases
                ->pluck('store_return_reference')
                ->filter(fn ($reference): bool => filled($reference))
                ->map(fn ($reference): string => (string) $reference)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<int>  $lineIds
     * @param  array<int, float>  $availableByLine
     */
    private function consumeAvailability(array $lineIds, float $quantity, array &$availableByLine): void
    {
        foreach ($lineIds as $lineId) {
            $available = (float) ($availableByLine[$lineId] ?? 0);
            $used = min($quantity, $available);
            $availableByLine[$lineId] = max(0, $available - $used);
            $quantity -= $used;

            if ($quantity <= 0.00001) {
                break;
            }
        }
    }

    private function canonicalExternalLineId(ExternalOrderLine $line): string
    {
        $canonical = trim((string) (
            $line->canonical_external_line_id
            ?: data_get($line->raw_payload, 'sempre_erp_split.root_external_line_id')
            ?: data_get($line->raw_payload, 'id')
            ?: data_get($line->raw_payload, 'sempre_erp_split.source_external_line_id')
            ?: $line->external_line_id
        ));

        if ($canonical === '') {
            return 'line-'.$line->id;
        }

        if (filled(data_get($line->raw_payload, 'sempre_erp_split.source_order_line_id'))) {
            $canonical = $this->withoutSplitSuffix($canonical);
        }

        return $canonical;
    }

    private function withoutSplitSuffix(string $value): string
    {
        do {
            $previous = $value;
            $value = (string) preg_replace('/-S\d+$/', '', $value);
        } while ($value !== $previous);

        return $value;
    }

    private function reservedQuantity(ReturnCaseLine $line): float
    {
        $accepted = (float) $line->quantity_accepted;

        return $accepted > 0 ? $accepted : max(0, (float) $line->quantity_expected);
    }

    private function returnOrderKey(ExternalOrder $order): string
    {
        return sprintf('erp:%d:%s', (int) $order->sales_channel_id, (string) $order->external_id);
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 4, '.', ''), '0'), '.');
    }
}
