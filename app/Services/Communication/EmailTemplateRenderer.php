<?php

declare(strict_types=1);

namespace App\Services\Communication;

use Stringable;

final class EmailTemplateRenderer
{
    /**
     * @return array<string, string>
     */
    public function variables(): array
    {
        return [
            'order_number' => 'Numer zamówienia',
            'return_number' => 'Numer zwrotu',
            'customer_name' => 'Imię i nazwisko klienta',
            'customer_email' => 'E-mail klienta',
            'amount' => 'Kwota dopłaty lub zwrotu',
            'currency' => 'Waluta',
            'payment_url' => 'Link do płatności',
            'tracking_number' => 'Numer śledzenia',
            'tracking_url' => 'Link do śledzenia przesyłki',
            'shipping_label_notice' => 'Informacja o liście przewozowym',
            'courier_name' => 'Nazwa przewoźnika',
            'invoice_number' => 'Numer faktury lub korekty',
            'child_order_number' => 'Numer zamówienia częściowego',
            'order_date' => 'Data złożenia zamówienia',
            'order_status' => 'Status zamówienia',
            'shipping_method' => 'Sposób dostawy',
            'payment_method' => 'Sposób płatności',
            'payment_instruction' => 'Instrukcja właściwa dla płatności online, przelewu albo pobrania',
            'order_url' => 'Link do szczegółów zamówienia',
            'return_reason' => 'Powód zwrotu',
            'from_name' => 'Nazwa nadawcy',
            'brand_name' => 'Nazwa marki',
            'support_email' => 'E-mail kontaktowy',
            'support_phone' => 'Telefon kontaktowy',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function render(string $template, array $context): string
    {
        $normalized = $this->normalize($context);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            fn (array $matches): string => $normalized[$matches[1]] ?? '',
            $template,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string>
     */
    public function normalize(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalized[(string) $key] = $this->stringValue($value);
        }

        return $normalized;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'tak' : 'nie';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return '';
    }
}
