<?php

declare(strict_types=1);

namespace App\Services\Invoices;

use App\Models\Invoice;
use App\Models\InvoiceLine;

final class InvoiceValidationService
{
    /**
     * @return array{errors: list<string>, warnings: list<string>, is_blocking: bool}
     */
    public function validate(Invoice $invoice): array
    {
        $invoice->loadMissing('lines');

        $errors = [];
        $warnings = [];

        $this->validateHeader($invoice, $errors);
        $this->validateParties($invoice, $errors, $warnings);
        $this->validateLines($invoice, $errors);
        $this->validateTotals($invoice, $errors);
        $this->validateCorrection($invoice, $errors);

        return [
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'is_blocking' => $errors !== [],
        ];
    }

    public function assertValidForExternalSend(Invoice $invoice): void
    {
        $result = $this->validate($invoice);

        if ($result['is_blocking']) {
            throw new InvoiceValidationException(
                'Faktura wymaga poprawy przed wysyłką: ' . implode(' ', $result['errors']),
                $result['errors'],
            );
        }
    }

    /**
     * @param list<string> $errors
     */
    private function validateHeader(Invoice $invoice, array &$errors): void
    {
        if (! filled($invoice->number)) {
            $errors[] = 'Brakuje numeru faktury.';
        }

        if (! filled($invoice->currency) || strlen((string) $invoice->currency) !== 3) {
            $errors[] = 'Waluta faktury musi mieć trzyliterowy kod ISO.';
        }

        if ($invoice->issue_date === null) {
            $errors[] = 'Brakuje daty wystawienia.';
        }

        if ($invoice->sale_date === null) {
            $errors[] = 'Brakuje daty sprzedaży.';
        }

        if ($invoice->status !== 'issued') {
            $errors[] = 'Do wysyłki można użyć tylko faktury w statusie wystawiona.';
        }

        if (
            strtoupper((string) $invoice->currency) !== 'PLN'
            && abs((float) $invoice->vat_total) >= 0.005
            && ! is_numeric(data_get($invoice->metadata, 'currency_conversion.rate'))
        ) {
            $errors[] = 'Faktura walutowa z VAT wymaga kursu NBP do wykazania VAT w PLN.';
        }
    }

    /**
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    private function validateParties(Invoice $invoice, array &$errors, array &$warnings): void
    {
        $seller = $invoice->seller_data ?? [];
        $buyer = $invoice->buyer_data ?? [];

        foreach ([
            'name' => 'nazwy sprzedawcy',
            'tax_id' => 'NIP sprzedawcy',
            'address_1' => 'adresu sprzedawcy',
            'country' => 'kraju sprzedawcy',
        ] as $key => $label) {
            if (! filled($seller[$key] ?? null)) {
                $errors[] = 'Brakuje ' . $label . '.';
            }
        }

        if (filled($seller['tax_id'] ?? null) && ! $this->isValidTaxId((string) $seller['tax_id'], $seller['country'] ?? null)) {
            $errors[] = 'NIP sprzedawcy ma niepoprawny format.';
        }

        foreach ([
            'name' => 'nazwy nabywcy',
            'address_1' => 'adresu nabywcy',
            'country' => 'kraju nabywcy',
        ] as $key => $label) {
            if (! filled($buyer[$key] ?? null)) {
                $errors[] = 'Brakuje ' . $label . '.';
            }
        }

        if (filled($buyer['tax_id'] ?? null) && ! $this->isValidTaxId((string) $buyer['tax_id'], $buyer['country'] ?? null)) {
            $warnings[] = 'NIP nabywcy wygląda nietypowo. Sprawdź przed wysyłką do KSeF.';
        }
    }

    /**
     * @param list<string> $errors
     */
    private function validateLines(Invoice $invoice, array &$errors): void
    {
        if ($invoice->lines->isEmpty()) {
            $errors[] = 'Faktura nie ma pozycji.';

            return;
        }

        foreach ($invoice->lines as $index => $line) {
            $label = 'Pozycja ' . ($index + 1) . ': ';

            if (! filled($line->name)) {
                $errors[] = $label . 'brakuje nazwy towaru/usługi.';
            }

            if (! filled($line->unit)) {
                $errors[] = $label . 'brakuje jednostki miary.';
            }

            if (abs((float) $line->quantity) <= 0.000001) {
                $errors[] = $label . 'ilość nie może być zerowa.';
            }

            if ($line->vat_rate === null || (float) $line->vat_rate < 0 || (float) $line->vat_rate > 100) {
                $errors[] = $label . 'stawka VAT jest poza zakresem 0-100%.';
            }

            $expectedVat = round((float) $line->net_total * ((float) $line->vat_rate / 100), 2);
            if (! $this->sameMoney($expectedVat, (float) $line->vat_total)) {
                $errors[] = $label . 'kwota VAT nie zgadza się z netto i stawką VAT.';
            }

            $expectedGross = round((float) $line->net_total + (float) $line->vat_total, 2);
            if (! $this->sameMoney($expectedGross, (float) $line->gross_total)) {
                $errors[] = $label . 'kwota brutto nie zgadza się z netto i VAT.';
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private function validateTotals(Invoice $invoice, array &$errors): void
    {
        if ($invoice->lines->isEmpty()) {
            return;
        }

        $net = round($invoice->lines->sum(fn (InvoiceLine $line): float => (float) $line->net_total), 2);
        $vat = round($invoice->lines->sum(fn (InvoiceLine $line): float => (float) $line->vat_total), 2);
        $gross = round($invoice->lines->sum(fn (InvoiceLine $line): float => (float) $line->gross_total), 2);

        if (! $this->sameMoney($net, (float) $invoice->net_total)) {
            $errors[] = 'Suma netto faktury nie zgadza się z pozycjami.';
        }

        if (! $this->sameMoney($vat, (float) $invoice->vat_total)) {
            $errors[] = 'Suma VAT faktury nie zgadza się z pozycjami.';
        }

        if (! $this->sameMoney($gross, (float) $invoice->gross_total)) {
            $errors[] = 'Suma brutto faktury nie zgadza się z pozycjami.';
        }
    }

    /**
     * @param list<string> $errors
     */
    private function validateCorrection(Invoice $invoice, array &$errors): void
    {
        if ($invoice->type !== 'correction') {
            return;
        }

        if (! filled(data_get($invoice->metadata, 'corrected_invoice_number'))) {
            $errors[] = 'Korekta nie wskazuje numeru faktury pierwotnej.';
        }

        if (! filled(data_get($invoice->metadata, 'correction_reason'))) {
            $errors[] = 'Korekta nie ma powodu korekty.';
        }
    }

    private function isValidTaxId(string $value, mixed $country): bool
    {
        if ($this->isPolishCountry($country)) {
            return $this->isValidPolishNip($value);
        }

        return $this->looksLikeForeignTaxId($value);
    }

    private function isPolishCountry(mixed $country): bool
    {
        return strtoupper(trim((string) $country)) === 'PL';
    }

    private function isValidPolishNip(string $value): bool
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) !== 10) {
            return false;
        }

        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;

        foreach ($weights as $index => $weight) {
            $sum += ((int) $digits[$index]) * $weight;
        }

        $controlDigit = $sum % 11;

        return $controlDigit !== 10 && $controlDigit === (int) $digits[9];
    }

    private function looksLikeForeignTaxId(string $value): bool
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? '';
        $length = strlen($normalized);

        return $length >= 5 && $length <= 20;
    }

    private function sameMoney(float $expected, float $actual): bool
    {
        return abs($expected - $actual) <= 0.02;
    }
}
