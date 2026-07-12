<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected bool $authenticateByDefault = false;

    public function test_first_admin_can_be_created_from_login_screen(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Utwórz administratora')
            ->assertSee('Pierwsze konto zostanie administratorem');

        $this->post(route('login.setup'), [
            'name' => 'Admin ERP',
            'email' => 'admin@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(User::ROLE_ADMINISTRATOR, $user->role);
        $this->assertTrue($user->is_active);
    }

    public function test_first_admin_can_be_created_with_plain_login_identifier(): void
    {
        $this->post(route('login.setup'), [
            'name' => 'Admin ERP',
            'email' => 'admin',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect(route('dashboard'));

        $user = User::query()->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('admin', $user->email);
    }

    public function test_active_user_can_login_and_logout(): void
    {
        $user = User::query()->create([
            'name' => 'Operator ERP',
            'email' => 'operator@example.test',
            'password' => 'secret-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Logowanie do ERP');

        $this->post(route('login.attempt'), [
            'email' => 'operator@example.test',
            'password' => 'secret-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);

        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::query()->create([
            'name' => 'Inactive ERP',
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => false,
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'inactive@example.test',
            'password' => 'secret-password',
        ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_active_user_can_login_with_plain_name_without_at_sign(): void
    {
        $user = User::query()->create([
            'name' => 'admin',
            'email' => 'operator@example.test',
            'password' => 'secret-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'admin',
            'password' => 'secret-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_is_redirected_from_a_protected_erp_route(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_role_middleware_rejects_an_authenticated_user_without_access(): void
    {
        $user = User::query()->create([
            'name' => 'Pakowanie',
            'email' => 'packer@example.test',
            'password' => 'secret-password',
            'role' => User::ROLE_PACKER,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertForbidden();
    }

    public function test_login_attempts_are_rate_limited_per_identifier_and_ip(): void
    {
        User::query()->create([
            'name' => 'Operator ERP',
            'email' => 'limited@example.test',
            'password' => 'correct-password',
            'role' => User::ROLE_OPERATOR,
            'is_active' => true,
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post(route('login.attempt'), [
                'email' => 'limited@example.test',
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $this->post(route('login.attempt'), [
            'email' => 'limited@example.test',
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_security_headers_are_added_to_web_responses(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Content-Security-Policy');
    }
}
