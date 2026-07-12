<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected bool $authenticateByDefault = true;

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->authenticateByDefault
            || ! in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            return;
        }

        $administrator = User::query()->create([
            'name' => 'Test Administrator',
            'email' => 'test-administrator@sempre.invalid',
            'password' => 'test-password-not-for-production',
            'role' => User::ROLE_ADMINISTRATOR,
            'is_active' => true,
        ]);

        $this->actingAs($administrator);
    }
}
