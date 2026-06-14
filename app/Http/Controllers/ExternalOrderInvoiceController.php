<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Services\Invoices\OrderInvoiceService;
use App\Services\WooCommerce\InvoiceWooCommerceUploadService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class ExternalOrderInvoiceController extends Controller
{
    public function create(
        ExternalOrder $order,
        OrderInvoiceService $invoices,
        InvoiceWooCommerceUploadService $uploader,
    ): RedirectResponse {
        try {
            $invoice = $invoices->createForOrder($order);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
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
    }
}
