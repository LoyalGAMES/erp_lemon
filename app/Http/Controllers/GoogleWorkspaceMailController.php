<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Communication\GoogleWorkspaceOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

final class GoogleWorkspaceMailController extends Controller
{
    public function connect(
        Request $request,
        GoogleWorkspaceOAuthService $oauth,
    ): RedirectResponse {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $authorizationUrl = $oauth->beginAuthorization($request, $user->id);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('settings.mail')
                ->with('error', $exception->getMessage());
        }

        return redirect()->away($authorizationUrl);
    }

    public function callback(
        Request $request,
        GoogleWorkspaceOAuthService $oauth,
    ): RedirectResponse {
        $user = Auth::user();
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if (! $user instanceof User
            || ! $oauth->consumeAuthorizationState($request, $user->id, $state)) {
            return redirect()
                ->route('settings.mail')
                ->with('error', 'Sesja łączenia z Google wygasła lub jest nieprawidłowa. Rozpocznij ponownie.');
        }

        if ($request->filled('error')) {
            return redirect()
                ->route('settings.mail')
                ->with('error', $this->authorizationError((string) $request->query('error')));
        }

        if ($code === '') {
            return redirect()
                ->route('settings.mail')
                ->with('error', 'Google nie zwrócił kodu autoryzacji.');
        }

        try {
            $connection = $oauth->exchangeAuthorizationCode($code, $user->id);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('settings.mail')
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('settings.mail')
            ->with('status', 'Połączono Google Workspace: '.$connection->email.'.');
    }

    public function disconnect(GoogleWorkspaceOAuthService $oauth): RedirectResponse
    {
        $revoked = $oauth->disconnect();

        return redirect()
            ->route('settings.mail')
            ->with(
                $revoked ? 'status' : 'error',
                $revoked
                    ? 'Konto Google Workspace zostało odłączone.'
                    : 'Konto odłączono lokalnie, ale Google nie potwierdził cofnięcia tokenu.',
            );
    }

    private function authorizationError(string $error): string
    {
        return match ($error) {
            'access_denied' => 'Nie udzielono zgody na wysyłanie przez Google Workspace.',
            'admin_policy_enforced' => 'Administrator Google Workspace zablokował tę aplikację OAuth.',
            default => 'Google nie zakończył autoryzacji konta.',
        };
    }
}
