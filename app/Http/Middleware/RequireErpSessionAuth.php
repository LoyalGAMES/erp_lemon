<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Packing\PackingSettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireErpSessionAuth
{
    private const PACKING_STATION_SESSION_KEY = 'packing_station';

    private const PACKING_STATION_INITIALIZED_SESSION_KEY = 'packing_station_initialized';

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            if ($request->expectsJson()) {
                abort(401, 'Unauthenticated.');
            }

            return redirect()->guest(route('login'));
        }

        if (! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('error', 'Konto ERP jest nieaktywne.');
        }

        $this->initializePackingStation($request);
        $request->attributes->set('erp_user', $user);

        return $next($request);
    }

    private function initializePackingStation(Request $request): void
    {
        if ($request->session()->has(self::PACKING_STATION_INITIALIZED_SESSION_KEY)) {
            return;
        }

        if (! $request->session()->has(self::PACKING_STATION_SESSION_KEY)) {
            $request->session()->put(
                self::PACKING_STATION_SESSION_KEY,
                PackingSettingsService::DEFAULT_STATION_CODE,
            );
        }

        $request->session()->put(self::PACKING_STATION_INITIALIZED_SESSION_KEY, true);
    }
}
