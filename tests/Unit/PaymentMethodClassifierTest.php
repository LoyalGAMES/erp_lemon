<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ExternalOrder;
use App\Services\Payments\PaymentMethodClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PaymentMethodClassifierTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('paymentMethods')]
    public function test_it_classifies_woocommerce_payment_methods(array $payload, string $expected): void
    {
        $order = new ExternalOrder(['raw_payload' => $payload]);

        $this->assertSame($expected, app(PaymentMethodClassifier::class)->category($order));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function paymentMethods(): iterable
    {
        yield 'WooCommerce COD' => [
            ['payment_method' => 'cod', 'payment_method_title' => 'Płatność za pobraniem'],
            PaymentMethodClassifier::CASH_ON_DELIVERY,
        ];
        yield 'Polish COD title takes priority over a stale PayU identifier' => [
            ['payment_method' => 'payu', 'payment_method_title' => 'Za pobraniem'],
            PaymentMethodClassifier::CASH_ON_DELIVERY,
        ];
        yield 'traditional bank transfer' => [
            ['payment_method' => 'bacs', 'payment_method_title' => 'Przelew tradycyjny'],
            PaymentMethodClassifier::BANK_TRANSFER,
        ];
        yield 'PayU' => [
            [
                'payment_method' => 'payu',
                'payment_method_title' => 'PayU',
                'meta_data' => [['key' => '_billing_postcode', 'value' => '60-001']],
            ],
            PaymentMethodClassifier::ONLINE,
        ];
        yield 'Przelewy24' => [
            ['payment_method' => 'przelewy24', 'payment_method_title' => 'Przelewy24 / BLIK'],
            PaymentMethodClassifier::ONLINE,
        ];
        yield 'unknown custom payment' => [
            ['payment_method' => 'custom', 'payment_method_title' => 'Bon sklepu'],
            PaymentMethodClassifier::OTHER,
        ];
        yield 'generic transaction id does not turn an unknown gateway into PayU' => [
            [
                'payment_method' => 'custom',
                'payment_method_title' => 'Bon sklepu',
                'transaction_id' => 'OTHER-GATEWAY-123',
            ],
            PaymentMethodClassifier::OTHER,
        ];
        yield 'unrelated metadata value does not turn an unknown gateway into PayU' => [
            [
                'payment_method' => 'custom',
                'payment_method_title' => 'Bon sklepu',
                'meta_data' => [['key' => '_billing_company', 'value' => 'Fundacja PayUżytkownik']],
            ],
            PaymentMethodClassifier::OTHER,
        ];
    }

    public function test_customer_instruction_matches_the_actual_order_stage(): void
    {
        $online = new ExternalOrder([
            'raw_payload' => ['payment_method' => 'payu', 'payment_method_title' => 'PayU'],
        ]);
        $bank = new ExternalOrder([
            'raw_payload' => ['payment_method' => 'bacs', 'payment_method_title' => 'Przelew tradycyjny'],
        ]);

        $classifier = app(PaymentMethodClassifier::class);

        $this->assertStringContainsString('możesz bezpiecznie ponowić', $classifier->customerInstruction($online, 'order_payment_failed'));
        $this->assertSame('Płatność online została potwierdzona.', $classifier->customerInstruction($online, 'order_received'));
        $this->assertStringContainsString('Przelew został zaksięgowany', $classifier->customerInstruction($bank, 'order_received'));
        $this->assertSame('', $classifier->customerInstruction($online, 'order_cancelled'));
    }

    public function test_generic_transaction_identifier_is_not_treated_as_a_payu_order(): void
    {
        $stripe = new ExternalOrder([
            'raw_payload' => [
                'payment_method' => 'stripe',
                'payment_method_title' => 'Karta Stripe',
                'transaction_id' => 'pi_123',
            ],
        ]);
        $payu = new ExternalOrder([
            'raw_payload' => [
                'payment_method' => 'payu',
                'payment_method_title' => 'PayU',
                'transaction_id' => 'PAYU-123',
            ],
        ]);

        $classifier = app(PaymentMethodClassifier::class);

        $this->assertFalse($classifier->isPayuPrepaid($stripe));
        $this->assertNull($classifier->payuOrderId($stripe));
        $this->assertTrue($classifier->isPayuPrepaid($payu));
        $this->assertSame('PAYU-123', $classifier->payuOrderId($payu));
    }

    public function test_bank_transfer_with_stale_payu_metadata_is_not_treated_as_payu_prepaid(): void
    {
        $order = new ExternalOrder([
            'raw_payload' => [
                'payment_method' => 'bacs',
                'payment_method_title' => 'Przelew tradycyjny',
                'payu_order_id' => 'STALE-PAYU-ID',
            ],
        ]);

        $classifier = app(PaymentMethodClassifier::class);

        $this->assertSame(PaymentMethodClassifier::BANK_TRANSFER, $classifier->category($order));
        $this->assertFalse($classifier->isPayuPrepaid($order));
        $this->assertNull($classifier->payuOrderId($order));
    }
}
