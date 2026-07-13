<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\Returns\ReturnSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

final class ReturnSettingsSecretSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const API_TOKEN = 'returns-api-token-123456';

    private const WEBHOOK_SECRET = 'returns-webhook-secret-654321';

    public function test_return_integration_secrets_are_encrypted_at_rest_and_only_masked_in_the_ui(): void
    {
        $settings = app(ReturnSettingsService::class);
        $settings->update($this->payload([
            'store_api_token' => self::API_TOKEN,
            'store_webhook_secret' => self::WEBHOOK_SECRET,
        ]));

        $stored = (array) AppSetting::query()->where('key', 'return_settings')->value('value');

        $this->assertArrayNotHasKey('store_api_token', $stored);
        $this->assertArrayNotHasKey('store_webhook_secret', $stored);
        $this->assertSame(self::API_TOKEN, Crypt::decryptString((string) $stored['store_api_token_encrypted']));
        $this->assertSame(self::WEBHOOK_SECRET, Crypt::decryptString((string) $stored['store_webhook_secret_encrypted']));
        $this->assertStringNotContainsString(self::API_TOKEN, json_encode($stored, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, json_encode($stored, JSON_THROW_ON_ERROR));

        $this->assertSame(self::API_TOKEN, $settings->data()['store_api_token']);
        $this->assertSame(self::WEBHOOK_SECRET, $settings->data()['store_webhook_secret']);

        $public = $settings->publicData();
        $this->assertArrayNotHasKey('store_api_token', $public);
        $this->assertArrayNotHasKey('store_webhook_secret', $public);
        $this->assertSame('••••••••3456', $public['store_api_token_mask']);
        $this->assertSame('••••••••4321', $public['store_webhook_secret_mask']);

        $this->get(route('settings.returns'))
            ->assertOk()
            ->assertDontSee(self::API_TOKEN, false)
            ->assertDontSee(self::WEBHOOK_SECRET, false)
            ->assertSee('••••••••3456')
            ->assertSee('••••••••4321')
            ->assertSee('name="store_api_token" type="password" value=""', false)
            ->assertSee('name="store_webhook_secret" type="password" value=""', false)
            ->assertSee('data-token-copy="store_api_token" disabled', false)
            ->assertSee('data-token-copy="store_webhook_secret" disabled', false)
            ->assertSee('navigator.clipboard.writeText(value)', false)
            ->assertSee('Zapisanej, zamaskowanej wartości nie można skopiować z tego ekranu.')
            ->assertSee('window.crypto.getRandomValues(values)', false)
            ->assertDontSee('Math.random', false);
    }

    public function test_blank_or_masked_values_preserve_secrets_and_explicit_clear_removes_them(): void
    {
        $settings = app(ReturnSettingsService::class);
        $settings->update($this->payload([
            'store_api_token' => self::API_TOKEN,
            'store_webhook_secret' => self::WEBHOOK_SECRET,
        ]));

        $this->put(route('settings.returns.update'), $this->payload([
            'store_api_token' => '',
            'store_webhook_secret' => '',
        ]))->assertRedirect()->assertSessionHas('status');

        $this->assertSame(self::API_TOKEN, $settings->data()['store_api_token']);
        $this->assertSame(self::WEBHOOK_SECRET, $settings->data()['store_webhook_secret']);

        $public = $settings->publicData();
        $this->put(route('settings.returns.update'), $this->payload([
            'store_api_token' => $public['store_api_token_mask'],
            'store_webhook_secret' => $public['store_webhook_secret_mask'],
        ]))->assertRedirect()->assertSessionHas('status');

        $this->assertSame(self::API_TOKEN, $settings->data()['store_api_token']);
        $this->assertSame(self::WEBHOOK_SECRET, $settings->data()['store_webhook_secret']);

        $this->put(route('settings.returns.update'), $this->payload([
            'store_api_token' => 'replacement-api-token-abcdef',
            'store_webhook_secret' => '',
        ]))->assertRedirect()->assertSessionHas('status');

        $this->assertSame('replacement-api-token-abcdef', $settings->data()['store_api_token']);
        $this->assertSame(self::WEBHOOK_SECRET, $settings->data()['store_webhook_secret']);

        $this->put(route('settings.returns.update'), $this->payload([
            'clear_store_api_token' => '1',
            'clear_store_webhook_secret' => '1',
        ]))->assertRedirect()->assertSessionHas('status');

        $this->assertSame('', $settings->data()['store_api_token']);
        $this->assertSame('', $settings->data()['store_webhook_secret']);
    }

    public function test_legacy_plaintext_settings_are_read_compatibly_and_migrated_without_data_loss(): void
    {
        AppSetting::query()->create([
            'key' => 'return_settings',
            'value' => $this->payload([
                'store_api_token' => self::API_TOKEN,
                'store_webhook_secret' => self::WEBHOOK_SECRET,
            ]),
        ]);

        $settings = app(ReturnSettingsService::class);
        $this->assertSame(self::API_TOKEN, $settings->data()['store_api_token']);
        $this->assertSame(self::WEBHOOK_SECRET, $settings->data()['store_webhook_secret']);

        $migration = require database_path('migrations/2026_07_12_000015_encrypt_return_integration_secrets.php');
        $migration->up();

        $stored = (array) AppSetting::query()->where('key', 'return_settings')->value('value');
        $this->assertArrayNotHasKey('store_api_token', $stored);
        $this->assertArrayNotHasKey('store_webhook_secret', $stored);
        $this->assertSame(self::API_TOKEN, Crypt::decryptString((string) $stored['store_api_token_encrypted']));
        $this->assertSame(self::WEBHOOK_SECRET, Crypt::decryptString((string) $stored['store_webhook_secret_encrypted']));
        $this->assertSame(self::API_TOKEN, $settings->data()['store_api_token']);
        $this->assertSame(self::WEBHOOK_SECRET, $settings->data()['store_webhook_secret']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'numbering_pattern' => '{PREFIX}/{YYYY}/{SEQ}',
            'numbering_prefix' => 'RET',
            'numbering_padding' => 6,
            'default_target_warehouse_id' => null,
            'default_condition' => 'unchecked',
            'default_disposition' => 'restock',
            'return_reasons' => ['Odstąpienie od umowy'],
            'conditions' => [
                ['code' => 'unchecked', 'label' => 'Niezweryfikowany'],
            ],
            'dispositions' => [
                ['code' => 'restock', 'label' => 'Przywróć na stan', 'warehouse_id' => null],
            ],
        ], $overrides);
    }
}
