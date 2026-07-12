<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class SecuritySensitiveInputTest extends TestCase
{
    protected bool $authenticateByDefault = false;

    public function test_validation_errors_never_flash_credentials_or_tokens_to_the_session(): void
    {
        $sensitive = [
            'access_token' => 'ksef-secret',
            'api_token' => 'courier-secret',
            'client_secret' => 'oauth-secret',
            'consumer_key' => 'woocommerce-key',
            'consumer_secret' => 'woocommerce-secret',
            'lease_token' => str_repeat('a', 64),
            'payu_client_secret' => 'payu-secret',
            'pickup_token' => str_repeat('b', 64),
            'store_api_token' => 'returns-api-secret',
            'store_webhook_secret' => 'returns-webhook-secret',
            'wp_api_application_password' => 'wordpress-secret',
        ];

        $this->post(route('login.attempt'), array_merge([
            'email' => 'safe-identifier@example.test',
        ], $sensitive))->assertSessionHasErrors('password');

        $oldInput = (array) session()->get('_old_input', []);

        $this->assertSame('safe-identifier@example.test', $oldInput['email'] ?? null);
        foreach (array_keys($sensitive) as $field) {
            $this->assertArrayNotHasKey($field, $oldInput);
        }
    }
}
