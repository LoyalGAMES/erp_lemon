<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Models\User;
use App\Services\Packing\PackedOrderPickingResetService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

final class PackedOrderPickingResetController extends Controller
{
    public function __invoke(
        Request $request,
        ExternalOrder $order,
        PackedOrderPickingResetService $reset,
    ): RedirectResponse {
        $administrator = Auth::user();

        if (! $administrator instanceof User || ! $administrator->isAdministrator()) {
            abort(403, 'Tylko administrator może cofnąć spakowane zamówienie do kompletacji.');
        }

        $validated = $request->validate([
            'expected_version' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
            'request_uuid' => ['required', 'uuid'],
            'typed_order_number' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:1000'],
            'confirm_goods_returned' => ['accepted'],
            'confirm_preserve_label' => ['accepted'],
            'confirm_cod_amount' => ['nullable', 'accepted'],
        ], [
            'expected_version.required' => 'Odśwież stronę i ponownie sprawdź plan korekty.',
            'confirm_goods_returned.accepted' => 'Potwierdź fizyczny zwrot wszystkich produktów na półkę.',
            'confirm_preserve_label.accepted' => 'Potwierdź zachowanie i zabezpieczenie istniejącej etykiety.',
            'confirm_cod_amount.accepted' => 'Potwierdź kwotę COD zachowywanej etykiety.',
        ]);

        try {
            $result = $reset->reset(
                $order,
                (string) $validated['expected_version'],
                (string) $validated['request_uuid'],
                (string) $validated['typed_order_number'],
                (string) $validated['reason'],
                (bool) $validated['confirm_goods_returned'],
                (bool) $validated['confirm_preserve_label'],
                (bool) ($validated['confirm_cod_amount'] ?? false),
                $administrator,
            );
        } catch (Throwable $exception) {
            if ($exception instanceof QueryException || ! $exception instanceof RuntimeException) {
                report($exception);
            }

            $message = $exception instanceof RuntimeException && ! $exception instanceof QueryException
                ? $exception->getMessage()
                : 'Wystąpił nieoczekiwany błąd. Żadna częściowa korekta nie została zatwierdzona.';

            return back()->withInput()->with('error', 'Nie cofnięto zamówienia do kompletacji. '.$message);
        }

        /** @var ExternalOrder $correctedOrder */
        $correctedOrder = $result['order'];

        return redirect()
            ->route('orders.show', $correctedOrder)
            ->with(
                'status',
                "Zamówienie {$correctedOrder->external_number} wróciło do kompletacji. "
                ."Wyzerowano {$result['tasks']} pozycji, odtworzono {$result['reservations']} rezerwacje i zachowano dotychczasową etykietę.",
            );
    }
}
