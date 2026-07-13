<?php

use App\Http\Controllers\Api\PrintBridgeController;
use App\Http\Controllers\Api\StoreReturnsController;
use App\Http\Controllers\Api\WooCommerceCustomerWebhookController;
use App\Http\Middleware\VerifyPrintBridgeToken;
use App\Http\Middleware\VerifyStoreReturnsToken;
use App\Http\Middleware\VerifyWooCommerceCustomerWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('/woocommerce/customer-webhooks/{integration}', WooCommerceCustomerWebhookController::class)
    ->middleware(['throttle:woocommerce-customer-webhooks', VerifyWooCommerceCustomerWebhookSignature::class])
    ->name('api.woocommerce.customer-webhooks.store');

Route::middleware(['throttle:print-bridge', VerifyPrintBridgeToken::class])
    ->prefix('print-bridge')
    ->name('api.print-bridge.')
    ->group(function (): void {
        Route::post('/status', [PrintBridgeController::class, 'status'])->name('status');
        Route::get('/jobs/next', [PrintBridgeController::class, 'next'])->name('jobs.next');
        Route::get('/jobs/{job}/file', [PrintBridgeController::class, 'file'])->name('jobs.file');
        Route::post('/jobs/{job}/printed', [PrintBridgeController::class, 'printed'])->name('jobs.printed');
        Route::post('/jobs/{job}/failed', [PrintBridgeController::class, 'failed'])->name('jobs.failed');
    });

Route::middleware(['throttle:store-returns', VerifyStoreReturnsToken::class])
    ->prefix('store-returns')
    ->group(function (): void {
        Route::post('/lookup-order', [StoreReturnsController::class, 'lookupOrder'])->name('api.store-returns.lookup');
        Route::post('/', [StoreReturnsController::class, 'store'])->name('api.store-returns.store');
        Route::post('/status', [StoreReturnsController::class, 'status'])->name('api.store-returns.status');
    });
