<?php

use App\Http\Controllers\Api\StoreReturnsController;
use App\Http\Middleware\VerifyStoreReturnsToken;
use Illuminate\Support\Facades\Route;

Route::middleware(VerifyStoreReturnsToken::class)
    ->prefix('store-returns')
    ->group(function (): void {
        Route::post('/lookup-order', [StoreReturnsController::class, 'lookupOrder'])->name('api.store-returns.lookup');
        Route::post('/', [StoreReturnsController::class, 'store'])->name('api.store-returns.store');
        Route::post('/status', [StoreReturnsController::class, 'status'])->name('api.store-returns.status');
    });
