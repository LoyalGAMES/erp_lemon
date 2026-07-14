<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ExternalOrder;
use App\Services\Orders\OrderCancellationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class ExternalOrderCancellationController extends Controller
{
    public function store(
        Request $request,
        ExternalOrder $order,
        OrderCancellationService $cancellations,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'confirm_cancellation' => ['accepted'],
        ]);

        try {
            $result = $cancellations->cancel(
                $order,
                (string) $validated['reason'],
                Auth::id(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', 'Nie udało się dokończyć anulowania: '.$exception->getMessage());
        }

        $cancellation = $result['cancellation'];
        $targetOrder = $cancellation->order()->first() ?? $order;

        if ($result['attention_required']) {
            $warnings = implode(' | ', $result['warnings']);

            return redirect()
                ->route('orders.show', $targetOrder)
                ->with(
                    'error',
                    'Zamówienie zostało zatrzymane lub anulowane, ale wymaga jeszcze interwencji.'
                        .($warnings !== '' ? ' '.$warnings : ''),
                );
        }

        return redirect()
            ->route('orders.show', $targetOrder)
            ->with(
                'status',
                $result['already_completed']
                    ? 'To zamówienie było już w pełni anulowane.'
                    : 'Zamówienie anulowano w ERP i WooCommerce. Dokumenty oraz wysyłkę cofnięto, a rozliczenie zapisano.',
            );
    }

    public function confirmManualShipping(
        Request $request,
        ExternalOrder $order,
        OrderCancellationService $cancellations,
    ): RedirectResponse {
        $validated = $request->validate([
            'confirm_manual_shipping_cancellation' => ['accepted'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $cancellations->confirmManualShippingCancellation(
                $order,
                Auth::id(),
                (string) ($validated['note'] ?? ''),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Nie udało się wznowić anulowania: '.$exception->getMessage());
        }

        $cancellation = $result['cancellation'];
        $targetOrder = $cancellation->order()->first() ?? $order;

        if ($result['attention_required']) {
            return redirect()
                ->route('orders.show', $targetOrder)
                ->with(
                    'error',
                    'Potwierdzenie wysyłki zapisano, ale anulowanie nadal wymaga interwencji. '
                        .implode(' | ', $result['warnings']),
                );
        }

        return redirect()
            ->route('orders.show', $targetOrder)
            ->with('status', 'Ręczne cofnięcie przesyłki potwierdzono. Dokończono zwrot, dokumenty i anulowanie w WooCommerce.');
    }

    public function reconcileUnknownRefund(
        Request $request,
        ExternalOrder $order,
        OrderCancellationService $cancellations,
    ): RedirectResponse {
        $request->validate([
            'confirm_reconciliation' => ['accepted'],
        ]);

        try {
            $result = $cancellations->reconcileUnknownRefund($order, Auth::id());
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'Nie udało się uzgodnić wyniku zwrotu: '.$exception->getMessage());
        }

        $cancellation = $result['cancellation'];
        $targetOrder = $cancellation->order()->first() ?? $order;

        if ($result['attention_required']) {
            return redirect()
                ->route('orders.show', $targetOrder)
                ->with(
                    'error',
                    'WooCommerce nadal nie potwierdza wyniku zwrotu. Nie wysłano kolejnego cashbacku. '
                        .implode(' | ', $result['warnings']),
                );
        }

        return redirect()
            ->route('orders.show', $targetOrder)
            ->with('status', 'Wynik zwrotu uzgodniono z WooCommerce bez ponownego wysyłania cashbacku.');
    }
}
