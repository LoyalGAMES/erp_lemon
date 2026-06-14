<?php

declare(strict_types=1);

namespace App\Services\Ksef;

use App\Models\Invoice;
use App\Services\Invoices\InvoiceSettingsService;

final class KsefEligibilityService
{
    public const POLICY_AUTO = 'auto';

    public const POLICY_SEND = 'send';

    public const POLICY_SKIP = 'skip';

    public function __construct(private readonly InvoiceSettingsService $settings) {}

    /**
     * @return array{policy:string, should_send:bool, label:string, reason:string, tone:string}
     */
    public function state(Invoice $invoice): array
    {
        $policy = $this->policy($invoice);
        $hasBuyerTaxId = $this->hasBuyerTaxId($invoice);
        $hasInvoicePolicy = $this->hasInvoicePolicy($invoice);

        if ($policy === self::POLICY_SKIP) {
            return [
                'policy' => $policy,
                'should_send' => false,
                'label' => 'Nie wysyłać',
                'reason' => $hasInvoicePolicy
                    ? 'Ręcznie wyłączono wysyłkę tej faktury do KSeF.'
                    : 'Domyślna konfiguracja faktur wyłącza wysyłkę do KSeF.',
                'tone' => 'orange',
            ];
        }

        if ($policy === self::POLICY_SEND) {
            return [
                'policy' => $policy,
                'should_send' => true,
                'label' => 'Wysyłać',
                'reason' => $this->sendReason($hasBuyerTaxId, $hasInvoicePolicy),
                'tone' => '',
            ];
        }

        if (! $hasBuyerTaxId) {
            return [
                'policy' => $policy,
                'should_send' => false,
                'label' => 'B2C / pomiń',
                'reason' => 'Brak NIP nabywcy; faktura jest traktowana jako B2C i nie jest automatycznie wysyłana do KSeF.',
                'tone' => 'orange',
            ];
        }

        return [
            'policy' => $policy,
            'should_send' => true,
            'label' => 'B2B / wysyłaj',
            'reason' => 'Nabywca ma identyfikator podatkowy, więc faktura kwalifikuje się do wysyłki do KSeF.',
            'tone' => '',
        ];
    }

    public function shouldSend(Invoice $invoice): bool
    {
        return $this->state($invoice)['should_send'];
    }

    public function policy(Invoice $invoice): string
    {
        if ($this->hasInvoicePolicy($invoice)) {
            return $this->normalizePolicy(data_get($invoice->metadata, 'ksef.send_policy'));
        }

        return $this->normalizePolicy($this->settings->ksefData()['default_send_policy'] ?? self::POLICY_AUTO);
    }

    public function normalizePolicy(mixed $policy): string
    {
        $policy = is_string($policy) ? strtolower(trim($policy)) : '';

        return in_array($policy, [self::POLICY_AUTO, self::POLICY_SEND, self::POLICY_SKIP], true)
            ? $policy
            : self::POLICY_AUTO;
    }

    public function hasBuyerTaxId(Invoice $invoice): bool
    {
        return preg_replace('/\D+/', '', (string) data_get($invoice->buyer_data, 'tax_id', '')) !== '';
    }

    private function hasInvoicePolicy(Invoice $invoice): bool
    {
        $policy = data_get($invoice->metadata, 'ksef.send_policy');

        return is_string($policy) && trim($policy) !== '';
    }

    private function sendReason(bool $hasBuyerTaxId, bool $hasInvoicePolicy): string
    {
        if ($hasInvoicePolicy) {
            return $hasBuyerTaxId
                ? 'Ręcznie oznaczono fakturę do wysyłki; nabywca ma identyfikator podatkowy.'
                : 'Ręcznie oznaczono fakturę do dobrowolnej wysyłki mimo braku NIP nabywcy.';
        }

        return $hasBuyerTaxId
            ? 'Domyślna konfiguracja faktur wymusza wysyłkę do KSeF; nabywca ma identyfikator podatkowy.'
            : 'Domyślna konfiguracja faktur wymusza dobrowolną wysyłkę mimo braku NIP nabywcy.';
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataWithPolicy(array $metadata, string $policy): array
    {
        data_set($metadata, 'ksef.send_policy', $this->normalizePolicy($policy));
        data_set($metadata, 'ksef.policy_updated_at', now()->toISOString());

        return $metadata;
    }
}
