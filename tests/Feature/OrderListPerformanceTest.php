<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_module_loads_the_latest_page_without_rendering_every_order(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        foreach (range(1, 60) as $index) {
            ExternalOrder::query()->create([
                'sales_channel_id' => $channel->id,
                'external_id' => (string) (9000 + $index),
                'external_number' => (string) (9000 + $index),
                'status' => 'processing',
                'currency' => 'PLN',
                'total_gross' => $index,
                'external_created_at' => now()->subMinutes(60 - $index),
            ]);
        }

        $this->get(route('modules.show', 'orders'))
            ->assertOk()
            ->assertSee('9060')
            ->assertSee('9011')
            ->assertDontSee('9010')
            ->assertDontSee('9001');
    }
}
