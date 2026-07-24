<?php

declare(strict_types=1);

namespace App\Services\Returns;

use App\Models\CustomerPayment;
use App\Models\ExternalOrder;
use App\Models\ReturnCase;
use App\Services\Payments\OrderSettlementService;
use App\Services\Payments\PaymentMethodClassifier;
use Illuminate\Support\Collection;

final class ReturnProcessStatusService
{
    private const CONFIRMED_PAYMENT_STATUSES = ['booked', 'paid', 'settled'];

    private const PENDING_PAYMENT_STATUSES = ['pending', 'processing'];

    public function __construct(
        private readonly OrderSettlementService $orderSettlements,
        private readonly PaymentMethodClassifier $paymentMethods,
        private readonly ReturnInventoryReceiptService $inventoryReceipt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(ReturnCase $returnCase, bool $mbankEligible, float $mbankAmount): array
    {
        $returnCase->loadMissing([
            'lines.warehouseDocument',
            'warehouseDocument',
            'correctionInvoice',
            'customerPayments',
            'externalOrder',
        ]);

        $documents = $returnCase->lines
            ->map(fn ($line) => $line->warehouseDocument)
            ->filter()
            ->push($returnCase->warehouseDocument)
            ->filter()
            ->unique('id')
            ->values();
        $currency = mb_strtoupper((string) ($returnCase->correctionInvoice?->currency
            ?: $returnCase->externalOrder?->currency
            ?: 'PLN'));
        $expectedAmount = round(abs((float) ($returnCase->correctionInvoice?->gross_total ?? $mbankAmount)), 2);
        $payments = $returnCase->customerPayments
            ->filter(fn (CustomerPayment $payment): bool => mb_strtoupper((string) $payment->currency) === $currency)
            ->values();
        $outgoing = $payments
            ->where('direction', 'outgoing')
            ->values();
        $confirmed = $this->withStatuses($outgoing, self::CONFIRMED_PAYMENT_STATUSES);
        $pending = $this->withStatuses($outgoing, self::PENDING_PAYMENT_STATUSES);
        $uncertain = $this->withStatuses($outgoing, ['unknown']);
        $failed = $this->withStatuses($outgoing, ['failed', 'manual_required']);
        $confirmedAmount = $this->amount($confirmed);
        $pendingAmount = $this->amount($pending);
        $orderSettlement = $returnCase->externalOrder instanceof ExternalOrder
            ? $this->orderSettlements->summary($returnCase->externalOrder)
            : null;

        $warehouse = $this->warehouseStatus($returnCase, $documents);
        $correction = $this->correctionStatus($returnCase, $warehouse['state']);
        $payout = $this->payoutStatus(
            $returnCase,
            $expectedAmount,
            $confirmedAmount,
            $pendingAmount,
            $confirmed,
            $pending,
            $uncertain,
            $failed,
            $orderSettlement,
            $mbankEligible,
            $mbankAmount,
            $currency,
        );

        return [
            'currency' => $currency,
            'expected_amount' => $expectedAmount,
            'confirmed_amount' => $confirmedAmount,
            'pending_amount' => $pendingAmount,
            'warehouse' => $warehouse,
            'correction' => $correction,
            'payout' => $payout,
            'next_step' => $this->nextStep($warehouse['state'], $correction['state'], $payout),
            'order_settlement' => $orderSettlement,
            'is_payu' => $returnCase->externalOrder instanceof ExternalOrder
                && $this->paymentMethods->isPayuPrepaid($returnCase->externalOrder),
        ];
    }

    /** @param Collection<int, mixed> $documents */
    private function warehouseStatus(ReturnCase $returnCase, Collection $documents): array
    {
        if ($this->inventoryReceipt->isComplete($returnCase)) {
            $hasNoRestockLines = $this->inventoryReceipt->hasNoRestockLines($returnCase);

            if ($documents->isEmpty()) {
                return [
                    'state' => 'complete',
                    'label' => 'Przyjęty bez zmiany stanu',
                    'description' => 'Towar został fizycznie przyjęty, ale zgodnie z dyspozycją nie zwiększył stanu magazynowego. RX nie był wymagany.',
                ];
            }

            return [
                'state' => 'complete',
                'label' => $hasNoRestockLines ? 'Towar przyjęty częściowo na stan' : 'Towar przyjęty',
                'description' => 'RX zaksięgowany: '.$documents->pluck('number')->implode(', ').'. '
                    .($hasNoRestockLines
                        ? 'Tylko wskazane pozycje zwiększyły stan; pozostałe przyjęto bez zmiany stanu.'
                        : 'Stan magazynu został zwiększony.'),
            ];
        }

        if ($documents->isEmpty()) {
            $preparedWithoutStock = $returnCase->lines->contains(
                fn ($line): bool => $this->inventoryReceipt->isPreparedWithoutStock($line),
            );

            return [
                'state' => $preparedWithoutStock ? 'pending' : 'not_started',
                'label' => $preparedWithoutStock ? 'Przyjęcie przygotowane' : 'Towar nieprzyjęty',
                'description' => $preparedWithoutStock
                    ? 'Dyspozycja bez przywracania stanu została przygotowana, ale przyjęcie nie zostało jeszcze potwierdzone.'
                    : 'Nie potwierdzono jeszcze przyjęcia zwrotu.',
            ];
        }

        return [
            'state' => 'pending',
            'label' => 'RX niezaksięgowany',
            'description' => 'Dokument istnieje, ale towar nie został jeszcze przyjęty na stan.',
        ];
    }

    private function correctionStatus(ReturnCase $returnCase, string $warehouseState): array
    {
        if ($returnCase->correctionInvoice !== null) {
            return [
                'state' => 'complete',
                'label' => 'Korekta wystawiona',
                'description' => $returnCase->correctionInvoice->number.' · '.number_format(abs((float) $returnCase->correctionInvoice->gross_total), 2, ',', ' ').' '.$returnCase->correctionInvoice->currency,
            ];
        }

        if ($warehouseState === 'complete') {
            return [
                'state' => 'required',
                'label' => 'Korekta niewystawiona',
                'description' => 'Zwrot został przyjęty. Wystaw korektę, aby ustalić kwotę zwrotu.',
            ];
        }

        return [
            'state' => 'waiting',
            'label' => 'Korekta oczekuje',
            'description' => 'Korektę będzie można wystawić po potwierdzeniu przyjęcia zwrotu.',
        ];
    }

    /**
     * @param  Collection<int, CustomerPayment>  $confirmed
     * @param  Collection<int, CustomerPayment>  $pending
     * @param  Collection<int, CustomerPayment>  $uncertain
     * @param  Collection<int, CustomerPayment>  $failed
     * @param  array<string, mixed>|null  $orderSettlement
     */
    private function payoutStatus(
        ReturnCase $returnCase,
        float $expectedAmount,
        float $confirmedAmount,
        float $pendingAmount,
        Collection $confirmed,
        Collection $pending,
        Collection $uncertain,
        Collection $failed,
        ?array $orderSettlement,
        bool $mbankEligible,
        float $mbankAmount,
        string $currency,
    ): array {
        $latestConfirmed = $confirmed->sortByDesc(fn (CustomerPayment $payment): int => $this->paymentTimestamp($payment))->first();
        $latestPending = $pending->sortByDesc(fn (CustomerPayment $payment): int => $this->paymentTimestamp($payment))->first();
        $latestFailed = $failed->sortByDesc(fn (CustomerPayment $payment): int => $this->paymentTimestamp($payment))->first();

        if ($uncertain->isNotEmpty()) {
            return $this->paymentResult(
                'verify',
                'Wypłata do weryfikacji',
                'Wynik operacji jest nieznany. Nie wysyłaj drugiej wypłaty przed sprawdzeniem operatora płatności.',
                $this->amount($uncertain),
                $currency,
                $uncertain->first(),
            );
        }

        if ($confirmedAmount > 0 && $pendingAmount > 0) {
            return $this->paymentResult(
                'verify',
                'Wypłata do weryfikacji',
                'Potwierdzono '.number_format($confirmedAmount, 2, ',', ' ').' '.$currency
                    .', ale kolejna operacja na '.number_format($pendingAmount, 2, ',', ' ').' '.$currency
                    .' nadal oczekuje. Nie wysyłaj następnego refundu.',
                $confirmedAmount,
                $currency,
                $latestPending,
            );
        }

        if ($confirmedAmount > 0) {
            $complete = $expectedAmount <= 0 || $confirmedAmount + 0.005 >= $expectedAmount;

            return $this->paymentResult(
                $complete ? 'paid' : 'partially_paid',
                $complete ? 'Wypłacono' : 'Wypłacono częściowo',
                $complete
                    ? 'ERP ma potwierdzenie wypłaty przypisanej do tej karty zwrotu.'
                    : 'Potwierdzona wypłata jest niższa od kwoty korekty.',
                $confirmedAmount,
                $currency,
                $latestConfirmed,
            );
        }

        if ($pendingAmount > 0) {
            return $this->paymentResult(
                'pending',
                'Wypłata oczekuje',
                'Refund został wysłany, ale operator płatności nie potwierdził jeszcze wypłaty.',
                $pendingAmount,
                $currency,
                $latestPending,
            );
        }

        if ($latestFailed instanceof CustomerPayment) {
            $error = trim((string) ($latestFailed->error_message ?: data_get($latestFailed->metadata, 'payu.error', '')));

            return $this->paymentResult(
                'failed',
                'Wypłata nieudana',
                $error !== '' ? $error : 'Ostatnia próba wypłaty zakończyła się błędem.',
                abs((float) $latestFailed->amount),
                $currency,
                $latestFailed,
            );
        }

        $orderConfirmedRefund = round((float) ($orderSettlement['confirmed_refunded_amount'] ?? 0), 2);
        $unconfirmedWooRefund = round((float) ($orderSettlement['unconfirmed_woo_refund_amount'] ?? 0), 2);

        if ($orderConfirmedRefund > 0) {
            return [
                'state' => 'order_refund_unlinked',
                'label' => 'Refund widoczny tylko w zamówieniu',
                'description' => 'Zamówienie ma potwierdzony refund, ale nie jest on przypisany do tej karty zwrotu. Sprawdź referencję przed kolejną wypłatą.',
                'amount' => $orderConfirmedRefund,
                'currency' => $currency,
                'method' => null,
                'reference' => null,
                'date' => null,
            ];
        }

        if ($unconfirmedWooRefund > 0) {
            return [
                'state' => 'accounting_only',
                'label' => 'Tylko zapis w WooCommerce',
                'description' => 'WooCommerce ma zapis refundu, ale ERP nie ma potwierdzenia, że pieniądze rzeczywiście wyszły.',
                'amount' => $unconfirmedWooRefund,
                'currency' => $currency,
                'method' => null,
                'reference' => null,
                'date' => null,
            ];
        }

        if ($mbankEligible) {
            return [
                'state' => 'ready_bank',
                'label' => 'Do wypłaty przez mBank',
                'description' => 'Zwrot jest gotowy do dodania do koszyka przelewów. To jeszcze nie oznacza wykonanej wypłaty.',
                'amount' => round(abs($mbankAmount), 2),
                'currency' => $currency,
                'method' => 'Przelew mBank',
                'reference' => null,
                'date' => null,
            ];
        }

        if ($returnCase->correctionInvoice !== null) {
            return [
                'state' => 'not_paid',
                'label' => 'Nie wypłacono',
                'description' => 'Korekta jest wystawiona, ale brak potwierdzonej lub oczekującej wypłaty przypisanej do tego zwrotu.',
                'amount' => $expectedAmount,
                'currency' => $currency,
                'method' => null,
                'reference' => null,
                'date' => null,
            ];
        }

        return [
            'state' => 'waiting',
            'label' => 'Wypłata nieuruchomiona',
            'description' => 'Najpierw przyjmij towar i wystaw korektę.',
            'amount' => 0.0,
            'currency' => $currency,
            'method' => null,
            'reference' => null,
            'date' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function paymentResult(
        string $state,
        string $label,
        string $description,
        float $amount,
        string $currency,
        ?CustomerPayment $payment,
    ): array {
        return [
            'state' => $state,
            'label' => $label,
            'description' => $description,
            'amount' => round($amount, 2),
            'currency' => $currency,
            'method' => $payment instanceof CustomerPayment ? $this->methodLabel($payment->method) : null,
            'reference' => $payment?->reference ?: $payment?->external_transaction_id,
            'date' => $payment?->paid_at ?? $payment?->booked_at ?? $payment?->requested_at ?? $payment?->created_at,
        ];
    }

    /** @return array{label:string,description:string,tone:string} */
    private function nextStep(string $warehouseState, string $correctionState, array $payout): array
    {
        if ($warehouseState === 'not_started') {
            return ['label' => 'Przyjmij zwrot na stan', 'description' => 'Utwórz i zaksięguj RX. Towar od razu trafi na magazyn.', 'tone' => 'action'];
        }

        if ($warehouseState === 'pending') {
            return ['label' => 'Zaksięguj dokument RX', 'description' => 'Dopiero zaksięgowany RX potwierdza przyjęcie towaru.', 'tone' => 'warning'];
        }

        if ($correctionState !== 'complete') {
            return ['label' => 'Wystaw korektę', 'description' => 'Korekta ustali właściwą kwotę zwrotu dla klienta.', 'tone' => 'action'];
        }

        return match ($payout['state']) {
            'paid' => ['label' => 'Rozliczenie zakończone', 'description' => 'Towar przyjęty, korekta wystawiona i wypłata potwierdzona.', 'tone' => 'success'],
            'partially_paid' => ['label' => 'Dokończ wypłatę', 'description' => 'Wypłacono tylko część kwoty wynikającej z korekty.', 'tone' => 'warning'],
            'pending' => ['label' => 'Poczekaj na potwierdzenie', 'description' => 'Nie wysyłaj ponownie refundu, dopóki operator płatności nie zwróci wyniku.', 'tone' => 'info'],
            'verify', 'order_refund_unlinked' => ['label' => 'Sprawdź przed ponowną wypłatą', 'description' => 'Na zamówieniu istnieje operacja finansowa, której nie można bezpiecznie uznać za rozliczenie tej karty.', 'tone' => 'warning'],
            'accounting_only' => ['label' => 'Wykonaj faktyczną wypłatę', 'description' => 'Sam zapis refundu w WooCommerce nie potwierdza przepływu pieniędzy.', 'tone' => 'danger'],
            'ready_bank' => ['label' => 'Dodaj do koszyka mBank', 'description' => 'Po wykonaniu przelewu zarejestruj potwierdzoną wypłatę w ERP.', 'tone' => 'action'],
            'failed' => ['label' => 'Ponów albo wyjaśnij refund', 'description' => 'Sprawdź błąd operatora płatności przed kolejną próbą.', 'tone' => 'danger'],
            default => ['label' => 'Uruchom wypłatę', 'description' => 'Korekta jest gotowa, ale klient nie ma jeszcze potwierdzonego zwrotu pieniędzy.', 'tone' => 'danger'],
        };
    }

    /** @param Collection<int, CustomerPayment> $payments */
    private function withStatuses(Collection $payments, array $statuses): Collection
    {
        return $payments
            ->filter(fn (CustomerPayment $payment): bool => in_array(mb_strtolower((string) $payment->status), $statuses, true))
            ->values();
    }

    /** @param Collection<int, CustomerPayment> $payments */
    private function amount(Collection $payments): float
    {
        return round((float) $payments->sum(fn (CustomerPayment $payment): float => abs((float) $payment->amount)), 2);
    }

    private function paymentTimestamp(CustomerPayment $payment): int
    {
        return ($payment->paid_at ?? $payment->booked_at ?? $payment->requested_at ?? $payment->updated_at)?->timestamp ?? 0;
    }

    private function methodLabel(?string $method): string
    {
        return match (mb_strtolower((string) $method)) {
            'payu' => 'PayU',
            'mbank' => 'Przelew mBank',
            'bank_transfer' => 'Przelew bankowy',
            'cash' => 'Gotówka',
            'card' => 'Karta',
            'blik' => 'BLIK',
            default => filled($method) ? (string) $method : 'Inna metoda',
        };
    }
}
