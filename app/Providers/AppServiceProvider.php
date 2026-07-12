<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('erp-login', function (Request $request): array {
            $identifier = mb_strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(5)->by('erp-login:identifier:'.hash('sha256', $identifier).'|'.$request->ip()),
                Limit::perMinute(30)->by('erp-login:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('erp-first-admin', fn (Request $request): Limit => Limit::perMinute(3)->by($request->ip()));

        RateLimiter::for('print-bridge', function (Request $request): array {
            $credential = trim((string) ($request->bearerToken() ?? $request->header('X-API-Key', '')));

            return [
                Limit::perMinute(600)->by('print-bridge:token:'.hash('sha256', $credential)),
                Limit::perMinute(300)->by('print-bridge:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('store-returns', function (Request $request): array {
            $credential = trim((string) ($request->bearerToken() ?? $request->header('X-API-Key', '')));

            return [
                Limit::perMinute(120)->by('store-returns:token:'.hash('sha256', $credential)),
                Limit::perMinute(240)->by('store-returns:ip:'.$request->ip()),
            ];
        });
    }
}
