<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Models\IntegrationSyncLog;
use App\Models\Invoice;
use App\Models\KsefSubmission;
use App\Models\Product;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockReservation;
use App\Models\StockSyncQueueItem;
use App\Models\Warehouse;
use App\Models\WarehouseDocument;
use App\Models\WordpressIntegration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ModuleController extends Controller
{
    private const ORDER_LIST_PER_PAGE = 50;

    public function __invoke(Request $request, string $module)
    {
        $payload = match ($module) {
            'products' => [
                'title' => 'Produkty',
                'subtitle' => 'Katalog SKU niezależny od WooCommerce.',
                'columns' => ['SKU', 'Nazwa', 'VAT', 'Stan łączny', 'Status'],
                'rows' => Product::query()
                    ->with('stockBalances')
                    ->orderBy('sku')
                    ->get()
                    ->map(fn (Product $product) => [
                        $product->sku,
                        $product->name,
                        $product->vat_rate . '%',
                        number_format($product->stockBalances->sum(fn (StockBalance $balance) => (float) $balance->quantity_on_hand), 0, ',', ' '),
                        $product->is_active ? 'Aktywny' : 'Wylaczony',
                    ]),
            ],
            'warehouses' => [
                'title' => 'Magazyny',
                'subtitle' => 'Magazyny mogą synchronizować stany do wybranych sklepów lub działać wewnętrznie.',
                'columns' => ['Kod', 'Nazwa', 'Typ', 'Routing stanów', 'Stan łączny'],
                'rows' => Warehouse::query()
                    ->with(['routes.salesChannel', 'stockBalances'])
                    ->orderBy('code')
                    ->get()
                    ->map(fn (Warehouse $warehouse) => [
                        $warehouse->code,
                        $warehouse->name,
                        $warehouse->type,
                        $warehouse->routes->isEmpty()
                            ? 'Brak wysyłki stanów'
                            : $warehouse->routes->pluck('salesChannel.code')->implode(', '),
                        number_format($warehouse->stockBalances->sum(fn (StockBalance $balance) => (float) $balance->quantity_on_hand), 0, ',', ' '),
                    ]),
            ],
            'documents' => [
                'title' => 'Dokumenty magazynowe',
                'subtitle' => 'PZ, WZ, RW, RX, PW, MM, ZW i korekty. Tylko dokument zaksięgowany zmienia ledger.',
                'columns' => ['Numer', 'Typ', 'Status', 'Źródło', 'Cel', 'Pozycji', 'Akcja'],
                'rows' => WarehouseDocument::query()
                    ->with(['sourceWarehouse', 'destinationWarehouse', 'lines'])
                    ->latest('document_date')
                    ->get()
                    ->map(fn (WarehouseDocument $document) => [
                        $document->number,
                        $document->type,
                        $document->status,
                        $document->sourceWarehouse?->code ?? '-',
                        $document->destinationWarehouse?->code ?? '-',
                        (string) $document->lines->count(),
                        $document->status === 'draft'
                            ? view('partials.post-document-button', ['document' => $document])->render()
                            : 'Zaksięgowany',
                    ]),
                'html_last_column' => true,
            ],
            'integrations' => [
                'title' => 'Integracje WordPress/WooCommerce',
                'subtitle' => 'Każdy sklep jest osobnym kanałem sprzedaży z własnymi danymi API i routingiem stanów.',
                'columns' => ['Kanał', 'URL', 'Import zamówień', 'Eksport stanów', 'Upload faktur'],
                'rows' => WordpressIntegration::query()
                    ->with('salesChannel')
                    ->get()
                    ->map(fn (WordpressIntegration $integration) => [
                        $integration->salesChannel->name,
                        $integration->base_url,
                        $integration->order_import_enabled ? 'Tak' : 'Nie',
                        $integration->stock_export_enabled ? 'Tak' : 'Nie',
                        $integration->invoice_upload_enabled ? 'Tak' : 'Nie',
                    ]),
            ],
            'orders' => $this->ordersPayload($request),
            'returns' => [
                'title' => 'Zwroty',
                'subtitle' => 'Zwrot może przyjąć towar na dowolny magazyn przez dokument RX/ZW.',
                'columns' => ['Numer', 'Status', 'Magazyn docelowy', 'Powód', 'Klient'],
                'rows' => ReturnCase::query()
                    ->with('lines')
                    ->latest()
                    ->get()
                    ->map(fn (ReturnCase $returnCase) => [
                        $returnCase->number,
                        $returnCase->status,
                        Warehouse::query()->find($returnCase->target_warehouse_id)?->code ?? '-',
                        $returnCase->reason ?? '-',
                        $returnCase->customer_email ?? '-',
                    ]),
            ],
            'invoices' => [
                'title' => 'Faktury',
                'subtitle' => 'Dane faktury są strukturalne, a szablon wydruku będzie edytowalny niezależnie od danych prawnych.',
                'columns' => ['Numer', 'Typ', 'Status', 'Brutto', 'WooCommerce', 'KSeF', 'Data'],
                'rows' => Invoice::query()
                    ->with('externalOrder')
                    ->latest('issue_date')
                    ->get()
                    ->map(fn (Invoice $invoice) => [
                        $invoice->number,
                        $invoice->type,
                        $invoice->status,
                        number_format((float) $invoice->gross_total, 2, ',', ' ') . ' ' . $invoice->currency,
                        data_get($invoice->metadata, 'woocommerce_upload.status') === 'success' ? 'Wysłana' : '-',
                        $invoice->ksef_number ?? '-',
                        $invoice->issue_date?->format('Y-m-d') ?? '-',
                    ]),
            ],
            'ksef' => [
                'title' => 'KSeF',
                'subtitle' => 'Wysyłka faktur do KSeF będzie wykonywana asynchronicznie z historią statusów i odpowiedzi.',
                'columns' => ['Faktura ID', 'Środowisko', 'API', 'Status', 'Nr KSeF', 'Błąd'],
                'rows' => KsefSubmission::query()
                    ->latest()
                    ->get()
                    ->map(fn (KsefSubmission $submission) => [
                        (string) $submission->invoice_id,
                        $submission->environment,
                        $submission->api_version ?? '-',
                        $submission->status,
                        $submission->ksef_number ?? '-',
                        $submission->last_error ?? '-',
                    ]),
            ],
            'sync' => [
                'title' => 'Kolejka synchronizacji',
                'subtitle' => 'Zmiany stanów po zaksięgowaniu dokumentów tworzą zadania eksportu do WooCommerce. Nieudane eksporty można ponowić po poprawieniu konfiguracji lub mapowania produktu.',
                'summaryCards' => $this->syncSummaryCards(),
                'syncChannels' => SalesChannel::query()
                    ->whereHas('integrations', fn ($query) => $query->where('stock_export_enabled', true))
                    ->orderBy('code')
                    ->get(),
                'columns' => ['Magazyn', 'Produkt', 'Kanał', 'Status', 'Ilość do wysłania', 'Błąd', 'Akcja'],
                'rows' => StockSyncQueueItem::query()
                    ->with(['warehouse', 'product', 'salesChannel'])
                    ->latest()
                    ->get()
                    ->map(fn (StockSyncQueueItem $item) => [
                        $item->warehouse?->code ?? (string) $item->warehouse_id,
                        $item->product?->sku ?? (string) $item->product_id,
                        $item->salesChannel?->code ?? '-',
                        $item->status,
                        $item->quantity_to_push ?? '-',
                        $item->last_error !== null ? str($item->last_error)->limit(120)->toString() : '-',
                        view('partials.sync-actions', ['item' => $item])->render(),
                    ]),
                'html_last_column' => true,
            ],
            'ledger' => [
                'title' => 'Ledger stanów',
                'subtitle' => 'Historia zmian stanu powstaje tylko przez zaksięgowane dokumenty.',
                'columns' => ['Data', 'Magazyn', 'SKU', 'Zmiana', 'Kierunek'],
                'rows' => StockLedgerEntry::query()
                    ->with(['warehouse', 'product'])
                    ->latest('posted_at')
                    ->limit(100)
                    ->get()
                    ->map(fn (StockLedgerEntry $entry) => [
                        $entry->posted_at?->format('Y-m-d H:i') ?? '-',
                        $entry->warehouse?->code ?? '-',
                        $entry->product?->sku ?? '-',
                        $entry->quantity_change,
                        $entry->direction,
                    ]),
            ],
            default => throw new NotFoundHttpException(),
        };

        return view('module', $payload + ['module' => $module]);
    }

    /**
     * @return array{title:string,subtitle:string,columns:list<string>,rows:\Illuminate\Contracts\Pagination\Paginator,html_last_column:bool}
     */
    private function ordersPayload(Request $request): array
    {
        $orders = ExternalOrder::query()
            ->select([
                'id',
                'sales_channel_id',
                'external_id',
                'external_number',
                'status',
                'currency',
                'total_gross',
                'external_created_at',
                'created_at',
            ])
            ->with([
                'salesChannel:id,code,name',
                'invoices:id,external_order_id,number,type,status',
            ])
            ->orderByDesc('external_created_at')
            ->orderByDesc('id')
            ->simplePaginate($this->orderListPerPage($request))
            ->withQueryString();

        $pageOrders = $orders->getCollection();
        $activeReservationSums = $this->activeReservationSumsForOrders($pageOrders);
        $latestWzDocuments = $this->latestWzDocumentsForOrders($pageOrders);

        $orders->setCollection($pageOrders->map(function (ExternalOrder $order) use ($activeReservationSums, $latestWzDocuments): array {
            $activeReservations = (float) ($activeReservationSums[$this->reservationLookupKey($order->sales_channel_id, $order->external_id)] ?? 0);

            return [
                $order->salesChannel?->code ?? '-',
                $order->external_number,
                $order->status,
                number_format($activeReservations, 0, ',', ' '),
                number_format((float) $order->total_gross, 2, ',', ' ') . ' ' . $order->currency,
                $order->external_created_at?->format('Y-m-d H:i') ?? $order->created_at?->format('Y-m-d H:i') ?? '-',
                view('partials.order-actions', [
                    'order' => $order,
                    'wzDocument' => $latestWzDocuments[$order->id] ?? null,
                    'invoice' => $order->invoices
                        ->reject(fn ($invoice): bool => $invoice->type === 'proforma')
                        ->sortByDesc('id')
                        ->first(),
                    'proforma' => $order->invoices
                        ->where('type', 'proforma')
                        ->sortByDesc('id')
                        ->first(),
                    'activeReservations' => $activeReservations,
                ])->render(),
            ];
        }));

        return [
            'title' => 'Zamówienia',
            'subtitle' => 'Zamówienia importowane z WooCommerce tworzą rezerwacje, dokumenty WZ i faktury.',
            'columns' => ['Kanał', 'Nr zewnętrzny', 'Status', 'Rezerwacje', 'Kwota brutto', 'Utworzone', 'Akcja'],
            'rows' => $orders,
            'html_last_column' => true,
        ];
    }

    private function orderListPerPage(Request $request): int
    {
        return max(25, min(100, (int) $request->integer('per_page', self::ORDER_LIST_PER_PAGE)));
    }

    /**
     * @param Collection<int, ExternalOrder> $orders
     * @return array<string, float>
     */
    private function activeReservationSumsForOrders(Collection $orders): array
    {
        $externalIds = $orders
            ->pluck('external_id')
            ->filter()
            ->map(fn ($value): string => (string) $value)
            ->unique()
            ->values();
        $salesChannelIds = $orders
            ->pluck('sales_channel_id')
            ->filter()
            ->unique()
            ->values();

        if ($externalIds->isEmpty() || $salesChannelIds->isEmpty()) {
            return [];
        }

        return StockReservation::query()
            ->select([
                'sales_channel_id',
                'external_order_id',
                DB::raw('sum(quantity) as reserved_quantity'),
            ])
            ->where('status', 'active')
            ->whereIn('sales_channel_id', $salesChannelIds)
            ->whereIn('external_order_id', $externalIds)
            ->groupBy('sales_channel_id', 'external_order_id')
            ->get()
            ->mapWithKeys(fn (StockReservation $reservation): array => [
                $this->reservationLookupKey($reservation->sales_channel_id, $reservation->external_order_id) => (float) $reservation->reserved_quantity,
            ])
            ->all();
    }

    /**
     * @param Collection<int, ExternalOrder> $orders
     * @return array<int, WarehouseDocument>
     */
    private function latestWzDocumentsForOrders(Collection $orders): array
    {
        if ($orders->isEmpty()) {
            return [];
        }

        $documents = WarehouseDocument::query()
            ->select(['id', 'number', 'type', 'status', 'external_reference', 'metadata', 'created_at', 'updated_at'])
            ->where('type', 'WZ')
            ->where(function (Builder $query) use ($orders): void {
                foreach ($orders as $order) {
                    $query->orWhere(function (Builder $candidate) use ($order): void {
                        $candidate
                            ->where(function (Builder $identity) use ($order): void {
                                $identity->whereRaw('1 = 0');

                                if (filled($order->external_id)) {
                                    $identity->orWhere('metadata->external_order_id', (string) $order->external_id);
                                }

                                if (filled($order->external_number)) {
                                    $identity
                                        ->orWhere('metadata->external_order_number', (string) $order->external_number)
                                        ->orWhere('external_reference', (string) $order->external_number);
                                }
                            })
                            ->where(function (Builder $channel) use ($order): void {
                                $channel
                                    ->where('metadata->sales_channel_id', $order->sales_channel_id)
                                    ->orWhereNull('metadata->sales_channel_id');
                            });
                    });
                }
            })
            ->orderByRaw("case when status = 'posted' then 0 else 1 end")
            ->latest()
            ->get();

        return $orders
            ->mapWithKeys(fn (ExternalOrder $order): array => [
                $order->id => $documents->first(fn (WarehouseDocument $document): bool => $this->wzDocumentMatchesOrder($document, $order)),
            ])
            ->filter()
            ->all();
    }

    private function wzDocumentMatchesOrder(WarehouseDocument $document, ExternalOrder $order): bool
    {
        $metadata = (array) $document->metadata;
        $documentSalesChannelId = data_get($metadata, 'sales_channel_id');

        if ($documentSalesChannelId !== null && (int) $documentSalesChannelId !== (int) $order->sales_channel_id) {
            return false;
        }

        $externalId = (string) $order->external_id;
        $externalNumber = (string) $order->external_number;

        return (filled($externalId) && (string) data_get($metadata, 'external_order_id') === $externalId)
            || (filled($externalNumber) && (string) data_get($metadata, 'external_order_number') === $externalNumber)
            || (filled($externalNumber) && (string) $document->external_reference === $externalNumber);
    }

    private function reservationLookupKey(int|string|null $salesChannelId, string|int|null $externalOrderId): string
    {
        return ((string) $salesChannelId) . '|' . ((string) $externalOrderId);
    }

    /**
     * @return list<array{label:string,value:string,caption:string,tone:string}>
     */
    private function syncSummaryCards(): array
    {
        $jobs = $this->databaseQueueCounts();
        $imports = $this->latestImportStatusCounts();
        $stockExports = $this->statusCounts(StockSyncQueueItem::query());

        $activeImports = ($imports['queued'] ?? 0) + ($imports['running'] ?? 0);
        $activeStockExports = ($stockExports['pending'] ?? 0) + ($stockExports['running'] ?? 0);
        $failed = ($imports['failed'] ?? 0) + ($stockExports['failed'] ?? 0);

        return [
            [
                'label' => 'Zadania techniczne',
                'value' => (string) $jobs['total'],
                'caption' => $jobs['available']
                    ? "pending {$jobs['pending']} | delayed {$jobs['delayed']} | reserved {$jobs['reserved']}"
                    : 'Tabela jobs: brak w tym środowisku',
                'tone' => $jobs['total'] > 0 ? 'blue' : 'green',
            ],
            [
                'label' => 'Importy WooCommerce',
                'value' => (string) $activeImports,
                'caption' => "queued " . ($imports['queued'] ?? 0) . ' | running ' . ($imports['running'] ?? 0) . ' | failed ' . ($imports['failed'] ?? 0),
                'tone' => ($imports['failed'] ?? 0) > 0 ? 'red' : ($activeImports > 0 ? 'blue' : 'green'),
            ],
            [
                'label' => 'Eksport stanów',
                'value' => (string) $activeStockExports,
                'caption' => 'pending ' . ($stockExports['pending'] ?? 0) . ' | running ' . ($stockExports['running'] ?? 0) . ' | failed ' . ($stockExports['failed'] ?? 0),
                'tone' => ($stockExports['failed'] ?? 0) > 0 ? 'red' : ($activeStockExports > 0 ? 'blue' : 'green'),
            ],
            [
                'label' => 'Wymaga reakcji',
                'value' => (string) $failed,
                'caption' => 'Suma nieudanych importów i eksportów stanów',
                'tone' => $failed > 0 ? 'red' : 'green',
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function latestImportStatusCounts(): array
    {
        $latestImportIds = IntegrationSyncLog::query()
            ->whereIn('operation', ['import_products', 'import_orders'])
            ->whereNotNull('wordpress_integration_id')
            ->select(DB::raw('max(id) as id'))
            ->groupBy('wordpress_integration_id', 'operation')
            ->pluck('id')
            ->all();

        if ($latestImportIds === []) {
            return [];
        }

        return $this->statusCounts(IntegrationSyncLog::query()->whereIn('id', $latestImportIds));
    }

    /**
     * @return array{available:bool,total:int,pending:int,delayed:int,reserved:int}
     */
    private function databaseQueueCounts(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [
                'available' => false,
                'total' => 0,
                'pending' => 0,
                'delayed' => 0,
                'reserved' => 0,
            ];
        }

        $now = now()->getTimestamp();

        return [
            'available' => true,
            'total' => DB::table('jobs')->count(),
            'pending' => DB::table('jobs')->whereNull('reserved_at')->where('available_at', '<=', $now)->count(),
            'delayed' => DB::table('jobs')->whereNull('reserved_at')->where('available_at', '>', $now)->count(),
            'reserved' => DB::table('jobs')->whereNotNull('reserved_at')->count(),
        ];
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return array<string, int>
     */
    private function statusCounts($query): array
    {
        return $query
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }
}
