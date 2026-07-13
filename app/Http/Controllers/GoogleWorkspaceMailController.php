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
    private const SESSION_STATE_KEY = 'google_workspace_mail_oauth_state';

    private const STATE_TTL_SECONDS = 600;

    public function connect(
        Request $request,
        GoogleWorkspaceOAuthService $oauth,
    ): RedirectResponse {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(401);
        }

        $state = bin2hex(random_bytes(32));
        $request->session()->put(self::SESSION_STATE_KEY, [
            'hash' => hash('sha256', $state),
            'user_id' => $user->id,
            'expires_at' => now()->addSeconds(self::STATE_TTL_SECONDS)->getTimestamp(),
        ]);

        try {
            $authorizationUrl = $oauth->authorizationUrl($state);
        } catch (RuntimeException $exception) {
            $request->session()->forget(self::SESSION_STATE_KEY);

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
        $pendingState = $request->session()->pull(self::SESSION_STATE_KEY);
        $user = Auth::user();
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if (! $user instanceof User
            || ! is_array($pendingState)
            || ! isset($pendingState['hash'], $pendingState['user_id'], $pendingState['expires_at'])
            || (int) $pendingState['user_id'] !== $user->id
            || (int) $pendingState['expires_at'] < now()->getTimestamp()
            || $state === ''
            || ! hash_equals((string) $pendingState['hash'], hash('sha256', $state))) {
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
