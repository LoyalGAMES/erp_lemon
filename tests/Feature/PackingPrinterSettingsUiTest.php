<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Packing\PackingSettingsService;
use App\Services\Printing\PrintBridgeClientRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackingPrinterSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_printing_settings_are_discoverable_and_expose_connection_verification(): void
    {
        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Pakowanie i drukarki')
            ->assertSee('wybór drukarek z wykrytej listy')
            ->assertSee(route('settings.packing'), false);

        $this->get(route('settings.packing'))
            ->assertOk()
            ->assertSee('Wybierz drukarkę z Windows')
            ->assertSee('Sprawdź połączenie')
            ->assertSee('samo połączenie nie uruchamia automatycznego wydruku')
            ->assertSee(route('settings.packing.print-bridge.status'), false)
            ->assertSee('data-station-printer', false)
            ->assertDontSee('Wpisz ręcznie dokładną nazwę drukarki');

        $this->get(route('packing.index'))
            ->assertOk()
            ->assertSee('Drukarki i stanowiska')
            ->assertSee(route('settings.packing'), false);
    }

    public function test_online_station_is_ready_only_when_saved_printer_is_on_current_windows_list(): void
    {
        app(PackingSettingsService::class)->update([
            'stations' => [[
                'code' => 'station-1',
                'name' => 'Pakowanie główne',
                'printer_name' => 'zebra zd421',
                'segment' => 'all',
            ]],
        ]);
        app(PrintBridgeClientRegistry::class)->report(
            'station-1',
            'PACK-PC-1',
            '0.3.0',
            [[
                'name' => 'Zebra ZD421',
                'driver' => 'ZDesigner ZD421',
                'port' => 'USB001',
                'default' => true,
            ], [
                'name' => 'Microsoft Print to PDF',
                'driver' => 'Microsoft Print To PDF',
                'port' => 'PORTPROMPT:',
                'default' => false,
            ]],
        );

        $response = $this->get(route('settings.packing'));

        $response->assertOk()
            ->assertSee('PACK-PC-1')
            ->assertSee('v0.3.0')
            ->assertSee('Zebra ZD421')
            ->assertSee('domyślna · ZDesigner ZD421 · USB001')
            ->assertSee('Microsoft Print to PDF')
            ->assertSee('Gotowe do automatycznego wydruku na zebra zd421')
            ->assertSee('1 z 1 online · 1 gotowych do druku')
            ->assertSee('data-saved-printer="zebra zd421"', false);
        $this->assertMatchesRegularExpression(
            '/<option value="Zebra ZD421" data-reported-printer selected>/',
            $response->getContent(),
            'Nazwa zapisana inną wielkością liter powinna wybrać kanoniczną nazwę zgłoszoną przez Windows.',
        );
    }

    public function test_online_heartbeat_does_not_hide_missing_printer_mapping(): void
    {
        app(PackingSettingsService::class)->update([
            'stations' => [[
                'code' => 'station-1',
                'name' => 'Pakowanie główne',
                'printer_name' => 'Brother QL-700',
                'segment' => 'all',
            ]],
        ]);
        app(PrintBridgeClientRegistry::class)->report(
            'station-1',
            'PACK-PC-1',
            '0.3.0',
            [[
                'name' => 'Zebra ZD421',
                'driver' => 'ZDesigner ZD421',
                'port' => 'USB001',
                'default' => true,
            ]],
        );

        $this->get(route('settings.packing'))
            ->assertOk()
            ->assertSee('Połączono')
            ->assertSee('Brother QL-700 — zapisana, teraz niewidoczna')
            ->assertSee('Drukarka „Brother QL-700” nie jest dostępna na aktualnej liście Windows')
            ->assertSee('1 z 1 online · 0 gotowych do druku')
            ->assertSee('Wymaga konfiguracji')
            ->assertDontSee('Gotowe do automatycznego wydruku na Brother QL-700');
    }
}
