<?php

declare(strict_types=1);

namespace App\Services\Gs1;

use App\Models\Product;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class Gs1Client
{
    public function __construct(
        private readonly Gs1SettingsService $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function upsertProduct(Product $product, string $gtin, ?string $gpcCode = null): array
    {
        $configuration = $this->settings->publicConfiguration();

        if ($this->settings->username() === '' || $this->settings->password() === '') {
            throw new RuntimeException('Brak loginu lub hasła API GS1. Uzupełnij konto GS1 w Integracjach.');
        }

        $response = $this->request()
            ->put($this->settings->baseUrl() . '/products/' . $gtin, $this->productPayload($product, $gtin, $configuration, $gpcCode));

        if (! $response->successful()) {
            $payload = $response->json();
            $this->throwApiException($response->status(), is_array($payload) ? $payload : []);
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        if ($this->settings->username() === '' || $this->settings->password() === '') {
            throw new RuntimeException('Brak loginu lub hasła API GS1. Uzupełnij konto GS1 w Integracjach.');
        }

        $response = $this->request()
            ->get($this->settings->baseUrl() . '/localizations', [
                'page[offset]' => '0',
                'page[limit]' => '1',
            ]);

        if (! $response->successful()) {
            $payload = $response->json();
            $this->throwApiException($response->status(), is_array($payload) ? $payload : []);
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function request(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(2, 300, null, false)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($this->settings->username(), $this->settings->password());
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function productPayload(Product $product, string $gtin, array $configuration, ?string $gpcCode = null): array
    {
        $master = $product->masterData();
        $description = strip_tags((string) data_get($master, 'content.pl.description', ''));
        $description = trim(preg_replace('/\s+/', ' ', $description) ?? '');
        $gpcCode = $this->cleanGpcCode($gpcCode)
            ?? $this->cleanGpcCode(data_get($master, 'gs1.gpc_code'))
            ?? $this->cleanGpcCode($configuration['default_gpc_code'] ?? null);

        $attributes = [
            'brandName' => data_get($master, 'producer') ?: 'SEMPRE',
            'commonName' => data_get($master, 'content.pl.name') ?: $product->name,
            'description' => $description !== '' ? $description : $product->name,
            'descriptionLanguage' => 'pl',
            'internalSymbol' => $product->sku,
            'name' => $product->name,
            'netContent' => 1,
            'netContentUnit' => 'szt',
            'status' => $product->is_active ? 'ACT' : 'HID',
            'targetMarket' => [$configuration['target_market'] ?: 'PL'],
            'variant' => data_get($master, 'variant_attribute') ?: null,
        ];

        if ($gpcCode !== null) {
            $attributes['gpcCode'] = (float) $gpcCode;
        }

        return [
            'data' => [
                'type' => 'products',
                'id' => $gtin,
                'attributes' => collect($attributes)
                    ->reject(fn ($value): bool => $value === null || $value === '')
                    ->all(),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function throwApiException(int $status, ?array $payload): never
    {
        $payload ??= [];
        $error = (array) data_get($payload, 'error', $payload);
        $title = (string) data_get($error, 'title', data_get($payload, 'title', ''));
        $message = (string) data_get($error, 'detail', data_get($payload, 'detail', ''));
        $errors = collect((array) data_get($error, 'errors', data_get($payload, 'errors', [])))
            ->map(fn ($error): string => trim((string) data_get($error, 'field') . ': ' . (string) data_get($error, 'message')))
            ->filter()
            ->implode('; ');

        if ($status === 401) {
            throw new RuntimeException(
                'MojeGS1 zwróciło 401: Błąd autoryzacji. W Integracjach wpisz login i hasło API z MojeGS1: Moje dane -> Profile użytkowników -> Menu -> Zmień dane api. To nie jest zwykłe hasło do panelu; po wygenerowaniu nowych danych stare tracą ważność.',
            );
        }

        throw new RuntimeException(
            trim($title . ' ' . $message . ' ' . $errors) ?: 'MojeGS1 odrzuciło zapis produktu.',
        );
    }

    private function cleanGpcCode(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? '')) ?? '';

        return preg_match('/^\d{8}$/', $digits) ? $digits : null;
    }
}
