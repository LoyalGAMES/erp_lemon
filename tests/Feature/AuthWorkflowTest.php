<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthWorkflowTest extends TestCase
{
    use RefreshDatabase;

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
}
