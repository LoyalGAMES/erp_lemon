<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Shipping\ShippingAddressParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ShippingAddressParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string,?string,array{street:string,building_number:string,apartment_number:?string}}>
     */
    public static function addresses(): iterable
    {
        yield 'building in first line' => ['ul. Krzywa 2', null, [
            'street' => 'ul. Krzywa', 'building_number' => '2', 'apartment_number' => null,
        ]];
        yield 'apartment after slash' => ['Marszałkowska 10A/5', null, [
            'street' => 'Marszałkowska', 'building_number' => '10A', 'apartment_number' => '5',
        ]];
        yield 'apartment in second line' => ['Aleja 3 Maja 12', 'lok. 7', [
            'street' => 'Aleja 3 Maja', 'building_number' => '12', 'apartment_number' => '7',
        ]];
        yield 'building and apartment in second line' => ['Rynek Główny', '4/2', [
            'street' => 'Rynek Główny', 'building_number' => '4', 'apartment_number' => '2',
        ]];
        yield 'building range' => ['Długa 12-14', null, [
            'street' => 'Długa', 'building_number' => '12-14', 'apartment_number' => null,
        ]];
    }

    #[DataProvider('addresses')]
    public function test_it_parses_polish_shipping_addresses(string $line1, ?string $line2, array $expected): void
    {
        self::assertSame($expected, (new ShippingAddressParser)->parse($line1, $line2));
    }

    public function test_it_never_invents_a_building_number(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nie udało się odczytać numeru budynku');

        (new ShippingAddressParser)->parse('ul. Bez Numeru');
    }
}
