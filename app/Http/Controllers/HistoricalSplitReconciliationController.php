<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Models\User;
use App\Services\Orders\HistoricalSplitReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

final class HistoricalSplitReconciliationController extends Controller
{
    public function __invoke(
        Request $request,
        ExternalOrder $order,
        HistoricalSplitReconciliationService $reconciliation,
    ): RedirectResponse {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isAdministrator()) {
            abort(403, 'Tylko administrator może uzgodnić historyczny stan podziału.');
        }

        $validated = $request->validate([
            'family_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'plan_digest' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'reconciliation_request_uuid' => ['required', 'uuid'],
            'typed_order_number' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirm_carrier_not_handed_over' => ['required', 'accepted'],
            'confirm_package_matches_preserved_wz' => ['required', 'accepted'],
            'confirm_duplicate_items_returned' => ['required', 'accepted'],
            'confirm_financial_total_verified' => ['required', 'accepted'],
        ], [
            'typed_order_number.required' => 'Wpisz numer zamówienia głównego.',
            'reason.required' => 'Podaj powód uzgodnienia historycznego podziału.',
            'reason.min' => 'Powód uzgodnienia musi mieć co najmniej 10 znaków.',
            'confirm_carrier_not_handed_over.accepted' => 'Potwierdź stan przesyłki u przewoźnika.',
            'confirm_package_matches_preserved_wz.accepted' => 'Potwierdź zawartość zachowywanej paczki.',
            'confirm_duplicate_items_returned.accepted' => 'Potwierdź fizyczny zwrot nadmiarowo wydanych sztuk.',
            'confirm_financial_total_verified.accepted' => 'Potwierdź kwotę źródłową zamówienia.',
        ]);

        try {
            $root = $reconciliation->adopt(
                $order,
                $user,
                $validated['family_version'],
                $validated['plan_digest'],
                $validated['reconciliation_request_uuid'],
                $validated['typed_order_number'],
                $validated['reason'],
                [
                    'carrier_not_handed_over' => true,
                    'package_matches_preserved_wz' => true,
                    'duplicate_items_returned' => true,
                    'financial_total_verified' => true,
                ],
            );
        } catch (Throwable $exception) {
            if (! $exception instanceof RuntimeException) {
                report($exception);
            }

            return back()
                ->withInput()
                ->with(
                    'error',
                    'Nie zapisano historycznego stanu początkowego. '.($exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : 'Wystąpił nieoczekiwany błąd. Odśwież zamówienie i spróbuj ponownie.'),
                );
        }

        return redirect()
            ->route('orders.show', $root)
            ->with('status', 'Zweryfikowany stan sprzed historycznego podziału został zapisany. Sprawdź końcowy plan i cofnij rozdzielenie.');
    }
}
