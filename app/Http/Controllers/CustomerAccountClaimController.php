<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CompleteCustomerAccountClaimRequest;
use App\Models\CustomerAccountClaim;
use App\Models\ExternalOrder;
use App\Models\WordpressIntegration;
use App\Services\Communication\CustomerCommunicationService;
use App\Services\Customers\CustomerAccountClaimException;
use App\Services\Customers\CustomerAccountClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

final class CustomerAccountClaimController extends Controller
{
    public function show(
        CustomerAccountClaim $claim,
        CustomerAccountClaimService $claims,
    ): View|Response {
        $claim->loadMissing(['customer', 'externalOrder', 'integration']);
        $integration = $claim->integration;

        if (! $integration instanceof WordpressIntegration) {
            return $this->errorResponse(
                'Sklep powiązany z tym zaproszeniem nie jest już dostępny. Skontaktuj się z obsługą sklepu.',
                null,
                410,
            );
        }

        if ($claim->claimed_at !== null) {
            return view('customer-account-claims.success', [
                'createdAccount' => (bool) data_get($claim->metadata, 'created_account', false),
                'loginUrl' => $claims->loginUrl($integration),
            ]);
        }

        if ($claim->expires_at->isPast()) {
            return $this->errorResponse(
                CustomerAccountClaimException::expired()->publicMessage,
                $claims->storeUrl($integration),
                410,
            );
        }

        $order = $claim->externalOrder;

        if (! $order instanceof ExternalOrder) {
            return $this->errorResponse(
                'Zamówienie powiązane z tym zaproszeniem nie jest już dostępne.',
                $claims->storeUrl($integration),
                410,
            );
        }

        return view('customer-account-claims.form', [
            'orderNumber' => filled($order->external_number)
                ? (string) $order->external_number
                : '#'.(string) $order->external_id,
            'maskedEmail' => $this->maskedEmail((string) data_get($order->billing_data, 'email', '')),
        ]);
    }

    public function complete(
        CompleteCustomerAccountClaimRequest $request,
        CustomerAccountClaim $claim,
        CustomerAccountClaimService $claims,
        CustomerCommunicationService $communication,
    ): RedirectResponse|Response {
        $data = $request->validated();

        try {
            $result = $claims->complete(
                $claim,
                array_key_exists('password', $data) ? (string) $data['password'] : null,
            );
        } catch (CustomerAccountClaimException $exception) {
            if ($exception->field !== null) {
                return redirect()
                    ->to($request->fullUrl())
                    ->withErrors([$exception->field => $exception->publicMessage]);
            }

            $integration = $claim->integration;

            return $this->errorResponse(
                $exception->publicMessage,
                $integration instanceof WordpressIntegration ? $claims->storeUrl($integration) : null,
                $exception->httpStatus,
            );
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->to($request->fullUrl())
                ->with('claim_error', 'Nie udało się teraz połączyć ze sklepem. Konto ani zamówienie nie zostały oznaczone jako przypisane. Spróbuj ponownie za chwilę.');
        }

        if ($result->createdAccount) {
            try {
                $communication->sendCustomerAccountCreated(
                    $result->customer,
                    $result->order,
                    $result->loginUrl,
                );
            } catch (Throwable $exception) {
                // The WooCommerce account and order assignment are already
                // complete. A notification failure must never roll them back.
                report($exception);
            }
        }

        return redirect()->to($request->fullUrl());
    }

    private function errorResponse(string $message, ?string $storeUrl, int $status): Response
    {
        return response()->view('customer-account-claims.error', [
            'message' => $message,
            'storeUrl' => $storeUrl,
        ], $status);
    }

    private function maskedEmail(string $email): string
    {
        $normalized = mb_strtolower(trim($email));

        if (! str_contains($normalized, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $normalized, 2);

        return ($local !== '' ? mb_substr($local, 0, 1) : '*').'***@'.$domain;
    }
}
