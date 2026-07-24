<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Models\Invoice;
use App\Services\Audit\AuditLogService;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\Orders\OrderMutationLock;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExternalOrderInvoiceController extends Controller
{
    public function create(
        Request $request,
        ExternalOrder $order,
        OrderInvoiceService $invoices,
        InvoiceWooCommerceUploadService $uploader,
        OrderMutationLock $orderMutationLock,
    ): RedirectResponse {
        $validated = $request->validate([
            'document_type' => ['nullable', 'string', 'in:vat,proforma'],
        ]);
        $documentType = $validated['document_type'] ?? 'vat';

        try {
            return $orderMutationLock->forOrder(
                $order,
                function () use ($documentType, $invoices, $order, $uploader): RedirectResponse {
                    $invoice = $documentType === 'proforma'
                        ? $invoices->createProformaForOrder($order)
                        : $invoices->createForOrder($order);

                    if ($documentType === 'proforma') {
                        return back()->with('status', "Wystawiono proformę {$invoice->number}.");
                    }

                    try {
                        $uploader->upload($invoice);
                    } catch (RuntimeException $exception) {
                        return back()->with(
                            'error',
                            "Wystawiono fakturę {$invoice->number}, ale nie dodano jej do zamówienia WooCommerce: {$exception->getMessage()} Po poprawieniu integracji kliknij Wyślij do WooCommerce przy tej fakturze.",
                        );
                    }

                    return back()->with('status', "Wystawiono fakturę {$invoice->number} i dodano ją do zamówienia WooCommerce.");
                },
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }

    public function destroyProforma(
        ExternalOrder $order,
        Invoice $invoice,
        OrderMutationLock $orderMutationLock,
        AuditLogService $audit,
    ): RedirectResponse {
        try {
            $number = $orderMutationLock->forOrder(
                $order,
                fn (): string => DB::transaction(function () use ($order, $invoice, $audit): string {
                    $proforma = Invoice::query()
                        ->with(['files', 'ksefSubmissions'])
                        ->lockForUpdate()
                        ->findOrFail($invoice->id);

                    if ((int) $proforma->external_order_id !== (int) $order->id) {
                        throw new RuntimeException('Ta proforma nie należy do wskazanego zamówienia.');
                    }

                    if ($proforma->type !== 'proforma') {
                        throw new RuntimeException('Usunąć można wyłącznie proformę. Faktury wymagają korekty lub anulowania zgodnego z procesem księgowym.');
                    }

                    if (filled($proforma->ksef_number)
                        || $proforma->ksefSubmissions->contains(fn ($submission): bool => $submission->status === 'accepted')) {
                        throw new RuntimeException('Dokument przyjęty przez KSeF nie może zostać usunięty.');
                    }

                    $before = $proforma->only([
                        'id',
                        'number',
                        'type',
                        'status',
                        'external_order_id',
                        'issue_date',
                        'gross_total',
                        'currency',
                        'metadata',
                    ]);
                    $before['files'] = $proforma->files
                        ->map(fn ($file): array => $file->only(['id', 'type', 'disk', 'path', 'sha256']))
                        ->values()
                        ->all();

                    $audit->record(
                        'invoice.proforma_deleted',
                        $proforma,
                        $before,
                        null,
                        ['external_order_id' => $order->id],
                    );
                    $proforma->delete();

                    return $proforma->number;
                }),
            );
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('status', "Proforma {$number} została usunięta.");
    }
}
