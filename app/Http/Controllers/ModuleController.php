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
use App\Services\Orders\OrderFulfillmentStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ModuleController extends Controller
{
    public function __invoke(Request $request, string $module)
    {
        $fulfillmentStatus = app(OrderFulfillmentStatusService::class);

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
            'orders' => [
                'title' => 'Zamówienia',
                'subtitle' => 'Zamówienia importowane z WooCommerce tworzą rezerwacje, dokumenty WZ i faktury.',
                'columns' => ['Kanał', 'Nr zewnętrzny', 'Status', 'Rezerwacje', 'Kwota brutto', 'Utworzone', 'Akcja'],
                'rows' => ExternalOrder::query()
                    ->with(['salesChannel', 'invoices'])
                    ->latest()
                    ->get()
                    ->map(function (ExternalOrder $order) use ($fulfillmentStatus): array {
                        $activeReservations = (float) StockReservation::query()
                            ->where('sales_channel_id', $order->sales_channel_id)
                            ->where('external_order_id', $order->external_id)
                            ->where('status', 'active')
                            ->sum('quantity');

                        return [
                            $order->salesChannel->code,
                            $order->external_number,
                            $order->status,
                            number_format($activeReservations, 0, ',', ' '),
                            number_format((float) $order->total_gross, 2, ',', ' ') . ' ' . $order->currency,
                            $order->external_created_at?->format('Y-m-d H:i') ?? '-',
                            view('partials.order-actions', [
                                'order' => $order,
                                'wzDocument' => $fulfillmentStatus->latestWz($order),
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
                    }),
                'html_last_column' => true,
            ],
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
