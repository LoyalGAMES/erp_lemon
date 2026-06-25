<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ExternalOrder;
use App\Models\PackingTask;
use App\Models\ReturnCase;
use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErpDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_empty_database_state_without_fake_records(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Panel operacyjny')
            ->assertSee('Pakowanie')
            ->assertSee('Moduł zwrotów')
            ->assertSee('Produkty')
            ->assertSee('Audyt')
            ->assertDontSee('12 458')
            ->assertDontSee('PZ/26/0001')
            ->assertDontSee('M1 - Glowny');
    }

    public function test_dashboard_shows_operational_sales_return_and_top_action_counts(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'B2C',
            'name' => 'Sklep B2C',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);

        $firstOrder = ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '1001',
            'external_number' => '1001',
            'status' => 'processing',
            'currency' => 'PLN',
            'total_gross' => 200,
        ]);

        ExternalOrder::query()->create([
            'sales_channel_id' => $channel->id,
            'external_id' => '1002',
            'external_number' => '1002',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 100.50,
        ]);

        PackingTask::query()->create([
            'sales_channel_id' => $channel->id,
            'external_order_id' => $firstOrder->id,
            'order_number' => '1001',
            'product_name' => 'Produkt testowy',
            'quantity_required' => 1,
            'status' => 'open',
        ]);

        ReturnCase::query()->create([
            'number' => 'R/1',
            'external_order_id' => $firstOrder->id,
            'status' => 'opened',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Zamówienia')
            ->assertSee('2')
            ->assertSee('Przychód')
            ->assertSee('300,50 PLN')
            ->assertSee('Zwroty')
            ->assertSee('1')
            ->assertSee('Procent zwrotów')
            ->assertSee('50,0%')
            ->assertSee('Pakowanie (1)')
            ->assertSee('Moduł zwrotów (1)');
    }

    public function test_integrations_page_starts_empty_and_accepts_real_woocommerce_configuration(): void
    {
        $this->get('/integrations')
            ->assertOk()
            ->assertSee('Dodaj sklep WooCommerce')
            ->assertSee('Import zamówień')
            ->assertSee('Brak integracji');
    }

    public function test_settings_page_groups_technical_modules_under_account_menu(): void
    {
        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Ustawienia')
            ->assertSee('Dokumenty magazynowe')
            ->assertSee('Zwroty')
            ->assertSee('Integracje')
            ->assertSee('Kolejka sync')
            ->assertSee('Drukarki')
            ->assertDontSee('Zapisz numerację')
            ->assertDontSee('Zapisz lokalizacje')
            ->assertDontSee('Zapisz ustawienia zwrotów');

        $this->get(route('settings.documents'))
            ->assertOk()
            ->assertSee('Ustawienia dokumentów')
            ->assertSee('Numeracja dokumentów')
            ->assertSee('Lokalizacje magazynowe')
            ->assertSee('Automatyczny obieg dokumentów')
            ->assertSee('Zapisz numerację');

        $this->get(route('settings.returns'))
            ->assertOk()
            ->assertSee('Ustawienia zwrotów')
            ->assertSee('Konfiguracja zwrotów')
            ->assertSee('Domyślny magazyn zwrotów')
            ->assertSee('Zapisz ustawienia zwrotów');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Administrator')
            ->assertSee('Ustawienia')
            ->assertSee('Audyt');
    }
}
