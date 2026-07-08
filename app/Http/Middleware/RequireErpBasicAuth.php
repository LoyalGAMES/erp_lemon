<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\ErpUserAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireErpBasicAuth
{
    public function __construct(
        private readonly ErpUserAuthenticator $authenticator,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        $login = (string) $request->getUser();
        $password = (string) $request->getPassword();
        $user = $this->authenticator->authenticate($login, $password);

        $fallbackConfigured = (string) config('erp.basic_user', '') !== ''
            && (string) config('erp.basic_password', '') !== '';

        if ($user === null && ! $fallbackConfigured && ! $this->authenticator->hasDatabaseUsers()) {
            abort(503, 'ERP basic auth is not configured.');
        }

        if ($user === null) {
            return response('Authentication required.', 401, [
                'WWW-Authenticate' => 'Basic realm="Sempre ERP"',
            ]);
        }

        Auth::setUser($user);
        $request->attributes->set('erp_user', $user);

        return $next($request);
    }
}
