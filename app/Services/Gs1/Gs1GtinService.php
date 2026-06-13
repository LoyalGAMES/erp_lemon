<?php

declare(strict_types=1);

namespace App\Services\Gs1;

use App\Models\Product;
use RuntimeException;

final class Gs1GtinService
{
    public function __construct(
        private readonly Gs1SettingsService $settings,
        private readonly Gs1Client $client,
    ) {
    }

    /**
     * @return array{gtin:string,registered:bool,response:array<string,mixed>}
     */
    public function generateForProduct(Product $product, ?string $gpcCode = null, ?string $gpcLabel = null): array
    {
        if (filled($product->ean)) {
            throw new RuntimeException('Produkt ma już zapisany EAN. Usuń go ręcznie, jeśli ma zostać nadany nowy kod.');
        }

        $configuration = $this->settings->publicConfiguration();
        $prefix = $configuration['company_prefix'];
        $gpcCode = $this->cleanGpcCode($gpcCode)
            ?? $this->cleanGpcCode(data_get($product->masterData(), 'gs1.gpc_code'))
            ?? $this->cleanGpcCode($configuration['default_gpc_code'] ?? null);

        if ($prefix === '') {
            throw new RuntimeException('Brak prefiksu GS1 firmy. Uzupełnij konto GS1 w Integracjach.');
        }

        if (strlen($prefix) >= 12) {
            throw new RuntimeException('Prefiks GS1 jest za długi dla GTIN-13. Podaj prefiks bez cyfry kontrolnej, krótszy niż 12 cyfr.');
        }

        if ($configuration['register_products'] && $gpcCode === null) {
            throw new RuntimeException('Wybierz 8-cyfrowy kod GPC przed rejestracją produktu w MojeGS1.');
        }

        $gtin = $this->nextAvailableGtin($product, $prefix, (int) $configuration['next_item_reference']);
        $response = [];
        $registered = false;

        if ($configuration['register_products']) {
            $response = $this->client->upsertProduct($product, $gtin, $gpcCode);
            $registered = true;
        }

        $this->saveGtinAndGpc($product, $gtin, $gpcCode, $gpcLabel);
        $this->settings->incrementNextItemReference();

        return [
            'gtin' => $gtin,
            'registered' => $registered,
            'response' => $response,
        ];
    }

    private function nextAvailableGtin(Product $product, string $prefix, int $sequence): string
    {
        $referenceLength = 12 - strlen($prefix);
        $limit = 10 ** $referenceLength;
        $sequence = max(0, $sequence);

        while ($sequence < $limit) {
            $base = $prefix . str_pad((string) $sequence, $referenceLength, '0', STR_PAD_LEFT);
            $gtin = $base . $this->checkDigit($base);

            $exists = Product::query()
                ->where('ean', $gtin)
                ->whereKeyNot($product->id)
                ->exists();

            if (! $exists) {
                return $gtin;
            }

            $sequence++;
        }

        throw new RuntimeException('Wyczerpano pulę numerów GTIN dla podanego prefiksu GS1.');
    }

    private function checkDigit(string $base): int
    {
        $digits = array_map('intval', str_split($base));
        $sum = 0;
        $weight = 3;

        for ($index = count($digits) - 1; $index >= 0; $index--) {
            $sum += $digits[$index] * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (10 - ($sum % 10)) % 10;
    }

    private function saveGtinAndGpc(Product $product, string $gtin, ?string $gpcCode, ?string $gpcLabel): void
    {
        $attributes = (array) $product->attributes;
        $master = (array) data_get($attributes, 'master', []);

        if ($gpcCode !== null) {
            data_set($master, 'gs1.gpc_code', $gpcCode);
            data_set($master, 'gs1.gpc_label', $this->cleanGpcLabel($gpcLabel) ?? $this->settings->gpcLabelForCode($gpcCode));
        }

        data_set($attributes, 'master', $master);

        $product->forceFill([
            'ean' => $gtin,
            'attributes' => $attributes,
        ])->save();
    }

    private function cleanGpcCode(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';

        return preg_match('/^\d{8}$/', $digits) ? $digits : null;
    }

    private function cleanGpcLabel(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, 180) : null;
    }
}
