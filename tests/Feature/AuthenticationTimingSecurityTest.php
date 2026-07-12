<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Timebox;
use Tests\TestCase;

final class AuthenticationTimingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticateByDefault = false;

    public function test_unknown_login_identifier_uses_the_failed_authentication_timebox(): void
    {
        $timebox = new RecordingFailedLoginTimebox;
        $this->app->instance(Timebox::class, $timebox);

        $this->post(route('login.attempt'), [
            'email' => 'unknown-user@example.test',
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');

        $this->assertGreaterThan(0, $timebox->sleptMicroseconds);
    }
}

final class RecordingFailedLoginTimebox extends Timebox
{
    public int $sleptMicroseconds = 0;

    protected function usleep(int $microseconds): void
    {
        $this->sleptMicroseconds = $microseconds;
    }
}
