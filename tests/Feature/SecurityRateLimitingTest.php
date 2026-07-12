<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

final class SecurityRateLimitingTest extends TestCase
{
    protected bool $authenticateByDefault = false;

    public function test_invalid_print_bridge_tokens_are_throttled_before_authentication(): void
    {
        config(['erp.print_bridge_token' => 'configured-bridge-secret']);

        RateLimiter::for('print-bridge', static fn (Request $request): array => [
            Limit::perMinute(100)->by('test-token:'.hash('sha256', (string) $request->bearerToken())),
            Limit::perMinute(2)->by('test-ip:'.$request->ip()),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25']);

        $this->getJson('/api/print-bridge/jobs/next', [
            'Authorization' => 'Bearer invalid-token-one',
        ])->assertUnauthorized();

        $this->getJson('/api/print-bridge/jobs/next', [
            'Authorization' => 'Bearer invalid-token-two',
        ])->assertUnauthorized();

        $this->getJson('/api/print-bridge/jobs/next', [
            'Authorization' => 'Bearer invalid-token-three',
        ])->assertTooManyRequests();
    }

    public function test_rotating_login_identifiers_cannot_bypass_the_ip_bucket(): void
    {
        RateLimiter::for('erp-login', static fn (Request $request): array => [
            Limit::perMinute(100)->by('test-identifier:'.hash('sha256', (string) $request->input('email'))),
            Limit::perMinute(2)->by('test-ip:'.$request->ip()),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.26']);

        $this->post(route('login.attempt'), ['email' => 'first@example.test'])
            ->assertSessionHasErrors('password');
        $this->post(route('login.attempt'), ['email' => 'second@example.test'])
            ->assertSessionHasErrors('password');
        $this->post(route('login.attempt'), ['email' => 'third@example.test'])
            ->assertTooManyRequests();
    }

    public function test_production_api_limiters_have_independent_token_and_ip_buckets_without_raw_secrets(): void
    {
        $request = Request::create('/api/print-bridge/jobs/next', 'GET', server: [
            'REMOTE_ADDR' => '198.51.100.27',
            'HTTP_AUTHORIZATION' => 'Bearer raw-super-secret-token',
        ]);
        $resolver = RateLimiter::limiter('print-bridge');

        $this->assertNotNull($resolver);
        $limits = $resolver($request);

        $this->assertIsArray($limits);
        $this->assertCount(2, $limits);
        $this->assertSame([600, 300], array_map(static fn (Limit $limit): int => $limit->maxAttempts, $limits));
        $keys = implode('|', array_map(static fn (Limit $limit): string => (string) $limit->key, $limits));
        $this->assertStringContainsString('print-bridge:token:', $keys);
        $this->assertStringContainsString('print-bridge:ip:', $keys);
        $this->assertStringNotContainsString('raw-super-secret-token', $keys);
    }
}
