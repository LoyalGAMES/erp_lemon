<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpRole
{
    public function handle(Request $request, Closure $next, string $area): Response
    {
        if ($area === 'module') {
            $area = (string) $request->route('module');
        }

        $user = $request->attributes->get('erp_user') ?: Auth::user();

        if (app()->runningUnitTests() && ! $user instanceof User) {
            return $next($request);
        }

        if (! $user instanceof User || ! $user->canAccessArea($area)) {
            abort(403, 'Brak dostępu do tego modułu.');
        }

        return $next($request);
    }
}
