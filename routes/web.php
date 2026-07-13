<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExternalOrderController;
use App\Http\Controllers\ExternalOrderFulfillmentController;
use App\Http\Controllers\ExternalOrderInvoiceController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\KsefController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\PackingController;
use App\Http\Controllers\ProductConfigurationController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageThumbnailController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockSyncController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use App\Http\Controllers\WarehouseDocumentController;
use App\Http\Controllers\WarehouseDocumentCreateController;
use App\Http\Middleware\EnsureErpRole;
use App\Http\Middleware\RequireErpSessionAuth;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:erp-login')->name('login.attempt');
Route::post('/login/setup', [AuthController::class, 'setupFirstAdmin'])->middleware('throttle:erp-first-admin')->name('login.setup');

Route::middleware(RequireErpSessionAuth::class)->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');
    Route::middleware(EnsureErpRole::class.':settings')->group(function (): void {
        Route::get('/settings', SettingsController::class)->name('settings.index');
        Route::get('/settings/documents', [SettingsController::class, 'documents'])->name('settings.documents');
        Route::get('/settings/returns', [SettingsController::class, 'returns'])->name('settings.returns');
        Route::put('/settings/documents', [SettingsController::class, 'updateDocuments'])->name('settings.documents.update');
        Route::put('/settings/locations', [SettingsController::class, 'updateLocations'])->name('settings.locations.update');
        Route::put('/settings/document-automation', [SettingsController::class, 'updateDocumentAutomation'])->name('settings.document_automation.update');
        Route::put('/settings/returns', [SettingsController::class, 'updateReturns'])->name('settings.returns.update');
        Route::get('/settings/mail', [SettingsController::class, 'mail'])->name('settings.mail');
        Route::put('/settings/mail', [SettingsController::class, 'updateMail'])->name('settings.mail.update');
        Route::put('/settings/mail/workflow', [SettingsController::class, 'updateMailWorkflow'])->name('settings.mail.workflow.update');
        Route::post('/settings/mail/preview', [SettingsController::class, 'previewMail'])->name('settings.mail.preview');
        Route::post('/settings/mail/retry-unsent', [SettingsController::class, 'retryUnsentMail'])->name('settings.mail.retry-unsent');
        Route::post('/settings/mail/test', [SettingsController::class, 'testMail'])->name('settings.mail.test');
        Route::post('/settings/mail/templates', [SettingsController::class, 'storeEmailTemplate'])->name('settings.mail.templates.store');
        Route::put('/settings/mail/templates/{template}', [SettingsController::class, 'updateEmailTemplate'])->name('settings.mail.templates.update');
        Route::delete('/settings/mail/templates/{template}', [SettingsController::class, 'destroyEmailTemplate'])->name('settings.mail.templates.destroy');
        Route::get('/settings/payments', [SettingsController::class, 'payments'])->name('settings.payments');
        Route::put('/settings/payments', [SettingsController::class, 'updatePayments'])->name('settings.payments.update');
        Route::get('/settings/shipping', [SettingsController::class, 'shipping'])->name('settings.shipping');
        Route::post('/settings/shipping/accounts', [SettingsController::class, 'storeCourierAccount'])->name('settings.shipping.accounts.store');
        Route::put('/settings/shipping/accounts/{account}', [SettingsController::class, 'updateCourierAccount'])->name('settings.shipping.accounts.update');
        Route::delete('/settings/shipping/accounts/{account}', [SettingsController::class, 'destroyCourierAccount'])->name('settings.shipping.accounts.destroy');
        Route::get('/settings/packing', [SettingsController::class, 'packing'])->name('settings.packing');
        Route::get('/settings/packing/print-bridge/status', [SettingsController::class, 'packingPrintBridgeStatus'])->name('settings.packing.print-bridge.status');
        Route::get('/settings/products', [SettingsController::class, 'products'])->name('settings.products');
        Route::get('/settings/packing/windows-listener/download', [SettingsController::class, 'downloadWindowsPrintListener'])->name('settings.packing.windows-listener.download');
        Route::get('/settings/packing/windows-listener/certificates/publisher', [SettingsController::class, 'downloadWindowsPrintListenerPublisherCertificate'])->name('settings.packing.windows-listener.certificate.publisher');
        Route::put('/settings/packing', [SettingsController::class, 'updatePacking'])->name('settings.packing.update');
        Route::put('/settings/products', [SettingsController::class, 'updateProducts'])->name('settings.products.update');
    });
    Route::middleware(EnsureErpRole::class.':users')->group(function (): void {
        Route::get('/settings/users', [UserController::class, 'index'])->name('settings.users');
        Route::post('/settings/users', [UserController::class, 'store'])->name('settings.users.store');
        Route::put('/settings/users/{user}', [UserController::class, 'update'])->name('settings.users.update');
    });

    Route::redirect('/modul/invoices', '/invoices');
    Route::redirect('/modul/ksef', '/ksef');
    Route::redirect('/modul/packing', '/packing');
    Route::redirect('/modul/audit', '/audit');
    Route::redirect('/modul/ledger', '/ledger');

    Route::get('/modul/{module}', ModuleController::class)
        ->middleware(EnsureErpRole::class.':module')
        ->whereIn('module', ['orders', 'returns', 'invoices', 'ksef', 'sync', 'ledger'])
        ->name('modules.show');

    Route::middleware(EnsureErpRole::class.':products')->group(function (): void {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/favorites', [ProductController::class, 'index'])->name('products.favorites');
        Route::get('/products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
        Route::get('/products/configuration/categories', [ProductConfigurationController::class, 'categories'])->name('products.categories.index');
        Route::post('/products/configuration/categories', [ProductConfigurationController::class, 'storeCategory'])->name('products.categories.store');
        Route::post('/products/configuration/categories/sort', [ProductConfigurationController::class, 'sortCategories'])->name('products.categories.sort');
        Route::put('/products/configuration/categories/{category}', [ProductConfigurationController::class, 'updateCategory'])->name('products.categories.update');
        Route::delete('/products/configuration/categories/{category}', [ProductConfigurationController::class, 'destroyCategory'])->name('products.categories.destroy');
        Route::get('/products/configuration/parameters', [ProductConfigurationController::class, 'parameters'])->name('products.parameters.index');
        Route::post('/products/configuration/parameters', [ProductConfigurationController::class, 'storeParameter'])->name('products.parameters.store');
        Route::put('/products/configuration/parameters/{parameter}', [ProductConfigurationController::class, 'updateParameter'])->name('products.parameters.update');
        Route::delete('/products/configuration/parameters/{parameter}', [ProductConfigurationController::class, 'destroyParameter'])->name('products.parameters.destroy');
        Route::get('/products/image-thumbnail', ProductImageThumbnailController::class)->name('products.image-thumbnail');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
        Route::post('/products/{product}/favorite', [ProductController::class, 'toggleFavorite'])->name('products.favorite.toggle');
        Route::post('/products/{product}/relations', [ProductController::class, 'storeRelation'])->name('products.relations.store');
        Route::delete('/products/{product}/relations/{relation}', [ProductController::class, 'destroyRelation'])->name('products.relations.destroy');
        Route::post('/products/{product}/stock-adjustments', [ProductController::class, 'adjustStock'])->name('products.stock.adjust');
        Route::post('/products/{product}/export-woocommerce', [ProductController::class, 'exportToWooCommerce'])->name('products.woocommerce.export');
        Route::post('/products/{product}/woocommerce/{integration}/create', [ProductController::class, 'createInWooCommerce'])->name('products.woocommerce.create');
        Route::post('/products/{product}/gs1/ean', [ProductController::class, 'generateGs1Ean'])->name('products.gs1.ean.generate');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    });

    Route::middleware(EnsureErpRole::class.':warehouses')->group(function (): void {
        Route::get('/warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::post('/warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::get('/warehouses/{warehouse}/edit', [WarehouseController::class, 'edit'])->name('warehouses.edit');
        Route::put('/warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::put('/warehouses/{warehouse}/routes', [WarehouseController::class, 'updateRoutes'])->name('warehouses.routes.update');
        Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');
    });

    Route::middleware(EnsureErpRole::class.':returns')->group(function (): void {
        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/orders/lookup', [ReturnController::class, 'lookupOrder'])->name('returns.orders.lookup');
        Route::get('/returns/payouts/mbank', [ReturnController::class, 'mbankPayouts'])->name('returns.payouts.mbank');
        Route::get('/returns/payouts/mbank/download', [ReturnController::class, 'downloadMbankPayouts'])->name('returns.payouts.mbank.download');
        Route::post('/returns', [ReturnController::class, 'store'])->name('returns.store');
        Route::get('/returns/{returnCase}', [ReturnController::class, 'show'])->name('returns.show');
        Route::get('/returns/{returnCase}/edit', [ReturnController::class, 'edit'])->name('returns.edit');
        Route::put('/returns/{returnCase}', [ReturnController::class, 'update'])->name('returns.update');
        Route::post('/returns/{returnCase}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('/returns/{returnCase}/reject', [ReturnController::class, 'reject'])->name('returns.reject');
        Route::post('/returns/{returnCase}/shipping-label', [ReturnController::class, 'createShippingLabel'])->name('returns.shipping-label.create');
        Route::post('/returns/{returnCase}/message', [ReturnController::class, 'sendMessage'])->name('returns.message.send');
        Route::post('/returns/{returnCase}/notes', [ReturnController::class, 'storeNote'])->name('returns.notes.store');
        Route::post('/returns/{returnCase}/payments', [ReturnController::class, 'storePayment'])->name('returns.payments.store');
        Route::post('/returns/{returnCase}/payu-refund', [ReturnController::class, 'refundWithPayu'])->name('returns.payu-refund');
        Route::get('/returns/labels/{label}/download', [ReturnController::class, 'downloadLabel'])->name('returns.labels.download');
        Route::post('/returns/{returnCase}/document', [ReturnController::class, 'createDocument'])->name('returns.document.create');
        Route::post('/returns/{returnCase}/correction', [ReturnController::class, 'createCorrection'])->name('returns.correction.create');
        Route::delete('/returns/{returnCase}', [ReturnController::class, 'destroy'])->name('returns.destroy');
    });

    Route::middleware(EnsureErpRole::class.':packing')->group(function (): void {
        Route::get('/packing', [PackingController::class, 'index'])->name('packing.index');
        Route::post('/packing/mode', [PackingController::class, 'mode'])->name('packing.mode');
        Route::post('/packing/station', [PackingController::class, 'station'])->name('packing.station');
        Route::post('/packing/scan', [PackingController::class, 'scan'])->name('packing.scan');
        Route::post('/packing/groups/pick', [PackingController::class, 'pick'])->name('packing.groups.pick');
        Route::post('/packing/groups/problem', [PackingController::class, 'problem'])->name('packing.groups.problem');
        Route::post('/packing/tasks/{task}/pack', [PackingController::class, 'pack'])->name('packing.tasks.pack');
        Route::post('/packing/tasks/{task}/reopen', [PackingController::class, 'reopen'])->name('packing.tasks.reopen');
        Route::post('/packing/orders/{order}/pack', [PackingController::class, 'packOrder'])->name('packing.orders.pack');
        Route::post('/packing/orders/{order}/unpack', [PackingController::class, 'unpackOrder'])->name('packing.orders.unpack');
        Route::post('/packing/orders/{order}/problem', [PackingController::class, 'problemOrder'])->name('packing.orders.problem');
        Route::post('/packing/orders/{order}/complete-with-label', [PackingController::class, 'completeWithLabel'])->name('packing.orders.complete-with-label');
        Route::post('/packing/orders/{order}/label', [PackingController::class, 'label'])->name('packing.orders.label');
        Route::post('/packing/couriers/check-pickups', [PackingController::class, 'checkCourierPickups'])->name('packing.couriers.check-pickups');
        Route::post('/packing/couriers/pickup', [PackingController::class, 'courierPickup'])->name('packing.couriers.pickup');
        Route::get('/packing/labels/{label}/download', [PackingController::class, 'downloadLabel'])->name('packing.labels.download');
    });

    Route::get('/audit', AuditLogController::class)
        ->middleware(EnsureErpRole::class.':audit')
        ->name('audit.index');

    Route::middleware(EnsureErpRole::class.':ledger')->group(function (): void {
        Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
        Route::get('/ledger/export', [LedgerController::class, 'export'])->name('ledger.export');
    });

    Route::middleware(EnsureErpRole::class.':invoices')->group(function (): void {
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/epp/export', [InvoiceController::class, 'exportEpp'])->name('invoices.epp.export');
        Route::put('/invoices/template', [InvoiceController::class, 'updateTemplate'])->name('invoices.template.update');
        Route::put('/invoices/seller', [InvoiceController::class, 'updateSeller'])->name('invoices.seller.update');
        Route::put('/invoices/settings', [InvoiceController::class, 'updateSettings'])->name('invoices.settings.update');
        Route::post('/invoices/apply-seller-settings', [InvoiceController::class, 'applySellerSettingsBatch'])->name('invoices.seller.apply-batch');
        Route::post('/invoices/upload-pending-woocommerce', [InvoiceController::class, 'uploadPendingToWooCommerce'])->name('invoices.woocommerce.upload-pending');
        Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('invoices.edit');
        Route::put('/invoices/{invoice}/data', [InvoiceController::class, 'updateData'])->name('invoices.data.update');
        Route::get('/invoices/{invoice}/preview', [InvoiceController::class, 'preview'])->name('invoices.preview');
        Route::get('/invoices/{invoice}/files/{file}/download', [InvoiceController::class, 'downloadFile'])->name('invoices.files.download');
        Route::post('/invoices/{invoice}/regenerate', [InvoiceController::class, 'regenerate'])->name('invoices.regenerate');
        Route::post('/invoices/{invoice}/apply-seller-settings', [InvoiceController::class, 'applySellerSettings'])->name('invoices.seller.apply');
        Route::post('/invoices/{invoice}/upload-woocommerce', [InvoiceController::class, 'uploadToWooCommerce'])->name('invoices.woocommerce.upload');
    });

    Route::middleware(EnsureErpRole::class.':documents')->group(function (): void {
        Route::get('/documents', [WarehouseDocumentCreateController::class, 'index'])->name('documents.index');
        Route::get('/documents/export', [WarehouseDocumentCreateController::class, 'export'])->name('documents.export');
        Route::get('/documents/create', [WarehouseDocumentCreateController::class, 'create'])->name('documents.create');
        Route::post('/documents', [WarehouseDocumentCreateController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}/edit', [WarehouseDocumentController::class, 'edit'])->name('documents.edit');
        Route::put('/documents/{document}', [WarehouseDocumentController::class, 'update'])->name('documents.update');
        Route::get('/documents/{document}', [WarehouseDocumentController::class, 'show'])->name('documents.show');
        Route::get('/documents/{document}/print', [WarehouseDocumentController::class, 'printView'])->name('documents.print');
    });

    Route::middleware(EnsureErpRole::class.':integrations')->group(function (): void {
        Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
        Route::get('/integrations/woocommerce-plugin/download', [IntegrationController::class, 'downloadWooCommercePlugin'])->name('integrations.woocommerce-plugin.download');
        Route::post('/integrations', [IntegrationController::class, 'store'])->name('integrations.store');
        Route::put('/integrations/{integration}', [IntegrationController::class, 'update'])->name('integrations.update');
        Route::post('/integrations/gs1/test', [IntegrationController::class, 'testGs1Connection'])->name('integrations.gs1.test');
        Route::post('/integrations/{integration}/test', [IntegrationController::class, 'test'])->name('integrations.test');
        Route::post('/integrations/{integration}/import-products', [IntegrationController::class, 'importProducts'])->name('integrations.import-products');
        Route::post('/integrations/{integration}/import-orders', [IntegrationController::class, 'importOrders'])->name('integrations.import-orders');
        Route::put('/integrations/{integration}/wordpress-credentials', [IntegrationController::class, 'updateWordpressCredentials'])->name('integrations.wordpress-credentials.update');
        Route::put('/integrations/{integration}/shipping-labels', [IntegrationController::class, 'updateShippingLabelSettings'])->name('integrations.shipping-labels.update');
        Route::put('/integrations/{integration}/order-statuses', [IntegrationController::class, 'updateOrderStatusSettings'])->name('integrations.order-statuses.update');
        Route::put('/integrations/ksef/configuration', [IntegrationController::class, 'updateKsefConfiguration'])->name('integrations.ksef.configuration.update');
        Route::put('/integrations/gs1/configuration', [IntegrationController::class, 'updateGs1Configuration'])->name('integrations.gs1.configuration.update');
        Route::post('/integrations/logs/{log}/retry', [IntegrationController::class, 'retryLog'])->name('integrations.logs.retry');
        Route::delete('/integrations/logs/failed', [IntegrationController::class, 'destroyFailedLogs'])->name('integrations.logs.failed.destroy');
        Route::delete('/integrations/{integration}', [IntegrationController::class, 'destroy'])->name('integrations.destroy');
    });

    Route::middleware(EnsureErpRole::class.':orders')->group(function (): void {
        Route::get('/orders/{order}', [ExternalOrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{order}/products/lookup', [ExternalOrderController::class, 'lookupProducts'])->name('orders.products.lookup');
        Route::put('/orders/{order}/lines', [ExternalOrderController::class, 'updateLines'])->name('orders.lines.update');
        Route::patch('/orders/{order}/status', [ExternalOrderController::class, 'updateStatus'])->name('orders.status.update');
        Route::post('/orders/{order}/payment-reminder', [ExternalOrderController::class, 'sendPaymentReminder'])->name('orders.payment-reminder.send');
        Route::post('/orders/{order}/split', [ExternalOrderController::class, 'split'])->name('orders.split');
        Route::post('/orders/{order}/shipping-decision', [ExternalOrderController::class, 'shippingDecision'])->name('orders.shipping-decision');
        Route::post('/orders/{order}/label', [ExternalOrderController::class, 'generateLabel'])->name('orders.label.generate');
        Route::post('/orders/{order}/message', [ExternalOrderController::class, 'sendMessage'])->name('orders.message.send');
        Route::post('/orders/{order}/notes', [ExternalOrderController::class, 'storeNote'])->name('orders.notes.store');
        Route::post('/orders/{order}/payments', [ExternalOrderController::class, 'storePayment'])->name('orders.payments.store');
        Route::post('/orders/{order}/wz', [ExternalOrderFulfillmentController::class, 'createWz'])
            ->name('orders.wz.create');

        Route::post('/orders/{order}/invoice', [ExternalOrderInvoiceController::class, 'create'])
            ->name('orders.invoice.create');
    });

    Route::middleware(EnsureErpRole::class.':ksef')->group(function (): void {
        Route::get('/ksef', [KsefController::class, 'index'])->name('ksef.index');
        Route::put('/ksef/invoices/{invoice}/policy', [KsefController::class, 'updatePolicy'])->name('ksef.invoices.policy.update');
        Route::post('/ksef/invoices/{invoice}/submit', [KsefController::class, 'submit'])->name('ksef.invoices.submit');
        Route::post('/ksef/submissions/{submission}/retry', [KsefController::class, 'retry'])->name('ksef.submissions.retry');
        Route::post('/ksef/submissions/{submission}/refresh', [KsefController::class, 'refresh'])->name('ksef.submissions.refresh');
        Route::get('/ksef/submissions/{submission}/xml', [KsefController::class, 'xml'])->name('ksef.submissions.xml');
    });
    Route::put('/ksef/configuration', [IntegrationController::class, 'updateKsefConfiguration'])
        ->middleware(EnsureErpRole::class.':integrations')
        ->name('ksef.configuration.update');

    Route::middleware(EnsureErpRole::class.':sync')->group(function (): void {
        Route::post('/sync/{item}/retry', [StockSyncController::class, 'retry'])
            ->name('sync.retry');
        Route::post('/sync/rebuild', [StockSyncController::class, 'rebuild'])
            ->name('sync.rebuild');
    });

    Route::middleware(EnsureErpRole::class.':documents')->group(function (): void {
        Route::post('/documents/{document}/post', [WarehouseDocumentController::class, 'post'])
            ->name('documents.post');
        Route::post('/documents/{document}/cancel', [WarehouseDocumentController::class, 'cancel'])
            ->name('documents.cancel');
    });
});
