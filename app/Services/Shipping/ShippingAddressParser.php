<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use RuntimeException;

final class ShippingAddressParser
{
    /**
     * @return array{street:string,building_number:string,apartment_number:?string}
     */
    public function parse(string $addressLine1, ?string $addressLine2 = null): array
    {
        $line1 = $this->normalize($addressLine1);
        $line2 = $this->normalize((string) $addressLine2);

        if ($line1 === '') {
            throw new RuntimeException('Adres wysyłki nie zawiera ulicy i numeru budynku. Uzupełnij adres zamówienia przed wygenerowaniem etykiety.');
        }

        $parsed = $this->splitStreetAndNumber($line1);

        if ($parsed === null && $line2 !== '') {
            $number = $this->splitBuildingAndApartment($line2);

            if ($number !== null) {
                return [
                    'street' => $line1,
                    'building_number' => $number['building_number'],
                    'apartment_number' => $number['apartment_number'],
                ];
            }
        }

        if ($parsed === null) {
            throw new RuntimeException(
                "Nie udało się odczytać numeru budynku z adresu „{$line1}”. Uzupełnij numer w pierwszej albo drugiej linii adresu zamówienia.",
            );
        }

        $apartment = $parsed['apartment_number'];
        $line2Apartment = $this->apartmentNumber($line2);

        if ($apartment === null && $line2Apartment !== null) {
            $apartment = $line2Apartment;
        }

        return [
            'street' => $parsed['street'],
            'building_number' => $parsed['building_number'],
            'apartment_number' => $apartment,
        ];
    }

    public function combinedBuildingNumber(array $address): string
    {
        $building = (string) ($address['building_number'] ?? '');
        $apartment = trim((string) ($address['apartment_number'] ?? ''));

        return $apartment === '' ? $building : $building.'/'.$apartment;
    }

    /**
     * @return array{street:string,building_number:string,apartment_number:?string}|null
     */
    private function splitStreetAndNumber(string $address): ?array
    {
        if (preg_match(
            '/^(?<street>.+?)\s+(?<building>\d+[\p{L}]?(?:\s*[-–]\s*\d+[\p{L}]?)?)(?:\s*(?:\/|m\.?|lok\.?)\s*(?<apartment>[\p{L}\p{N}-]+))?$/iu',
            $address,
            $matches,
        ) !== 1) {
            return null;
        }

        $street = $this->normalize((string) $matches['street']);

        if ($street === '') {
            return null;
        }

        return [
            'street' => $street,
            'building_number' => preg_replace('/\s+/u', '', (string) $matches['building']) ?? (string) $matches['building'],
            'apartment_number' => isset($matches['apartment']) && trim((string) $matches['apartment']) !== ''
                ? (string) $matches['apartment']
                : null,
        ];
    }

    /**
     * @return array{building_number:string,apartment_number:?string}|null
     */
    private function splitBuildingAndApartment(string $value): ?array
    {
        if (preg_match(
            '/^(?<building>\d+[\p{L}]?(?:\s*[-–]\s*\d+[\p{L}]?)?)(?:\s*(?:\/|m\.?|lok\.?)\s*(?<apartment>[\p{L}\p{N}-]+))?$/iu',
            $value,
            $matches,
        ) !== 1) {
            return null;
        }

        return [
            'building_number' => preg_replace('/\s+/u', '', (string) $matches['building']) ?? (string) $matches['building'],
            'apartment_number' => isset($matches['apartment']) && trim((string) $matches['apartment']) !== ''
                ? (string) $matches['apartment']
                : null,
        ];
    }

    private function apartmentNumber(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(?:(?:m|lok)\.?\s*)?(?<apartment>[\p{L}\p{N}-]+)$/iu', $value, $matches) !== 1) {
            return null;
        }

        return (string) $matches['apartment'];
    }

    private function normalize(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
