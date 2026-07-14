<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PackingThumbnailAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_packer_reaches_thumbnail_controller_without_products_permission(): void
    {
        $packer = User::query()->create([
            'name' => 'Pakowanie zdjęć',
            'email' => 'packing-images@example.test',
            'password' => 'secret-password',
            'role' => User::ROLE_PACKER,
            'is_active' => true,
        ]);

        $this->actingAs($packer)
            ->get(route('products.image-thumbnail', [
                'src' => 'invalid-source',
                'w' => 52,
                'h' => 64,
                'sig' => 'invalid-signature',
            ]))
            ->assertNotFound();
    }
}
