<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Enums;

enum WarehouseDocumentType: string
{
    case PZ = 'PZ';
    case WZ = 'WZ';
    case RW = 'RW';
    case RX = 'RX';
    case PW = 'PW';
    case MM = 'MM';
    case ZW = 'ZW';
    case KOR = 'KOR';

    public function label(): string
    {
        return match ($this) {
            self::PZ => 'Przyjęcie zewnętrzne',
            self::WZ => 'Wydanie zewnętrzne',
            self::RW => 'Rozchód wewnętrzny',
            self::RX => 'Przyjęcie zwrotu/reklamacji',
            self::PW => 'Przychód wewnętrzny',
            self::MM => 'Przesunięcie międzymagazynowe',
            self::ZW => 'Zwrot do magazynu',
            self::KOR => 'Korekta stanu',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    /**
     * @return list<string>
     */
    public static function sourceWarehouseValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->requiresSourceWarehouse()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function destinationWarehouseValues(): array
    {
        return array_values(array_map(
            fn (self $type): string => $type->value,
            array_filter(self::cases(), fn (self $type): bool => $type->requiresDestinationWarehouse()),
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return array_reduce(
            self::cases(),
            function (array $labels, self $type): array {
                $labels[$type->value] = $type->label();

                return $labels;
            },
            [],
        );
    }

    /**
     * @return array<string, string>
     */
    public static function helpTexts(): array
    {
        return array_reduce(
            self::cases(),
            function (array $texts, self $type): array {
                $texts[$type->value] = $type->helpText();

                return $texts;
            },
            [],
        );
    }

    public function helpText(): string
    {
        return match ($this) {
            self::PZ => 'PZ przyjmuje towar do magazynu. Wybierasz tylko magazyn docelowy.',
            self::PW => 'PW przyjmuje towar do magazynu. Wybierasz tylko magazyn docelowy.',
            self::RX => 'RX przyjmuje zwrot do magazynu. Wybierasz tylko magazyn docelowy.',
            self::ZW => 'ZW przyjmuje zwrot do magazynu. Wybierasz tylko magazyn docelowy.',
            self::KOR => 'Korekta przyjęcia zwiększa stan w magazynie docelowym.',
            self::WZ => 'WZ wydaje towar z magazynu. Wybierasz tylko magazyn źródłowy.',
            self::RW => 'RW rozchodzi towar z magazynu. Wybierasz tylko magazyn źródłowy.',
            self::MM => 'MM przesuwa towar między dwoma różnymi magazynami.',
        };
    }

    public function requiresSourceWarehouse(): bool
    {
        return match ($this) {
            self::WZ, self::RW, self::MM => true,
            self::PZ, self::RX, self::PW, self::ZW, self::KOR => false,
        };
    }

    public function requiresDestinationWarehouse(): bool
    {
        return match ($this) {
            self::PZ, self::RX, self::PW, self::MM, self::ZW, self::KOR => true,
            self::WZ, self::RW => false,
        };
    }

    public function decreasesSourceStock(): bool
    {
        return match ($this) {
            self::WZ, self::RW, self::MM => true,
            self::PZ, self::RX, self::PW, self::ZW, self::KOR => false,
        };
    }

    public function increasesDestinationStock(): bool
    {
        return match ($this) {
            self::PZ, self::RX, self::PW, self::MM, self::ZW, self::KOR => true,
            self::WZ, self::RW => false,
        };
    }

    public function isReturnReceipt(): bool
    {
        return in_array($this, [self::RX, self::ZW], true);
    }

    public function warehouseTopologyError(?int $sourceWarehouseId, ?int $destinationWarehouseId): ?string
    {
        if ($this === self::MM) {
            if (empty($sourceWarehouseId) || empty($destinationWarehouseId)) {
                return 'Dokument MM wymaga magazynu źródłowego i docelowego.';
            }

            if ($sourceWarehouseId === $destinationWarehouseId) {
                return 'MM wymaga różnych magazynów.';
            }

            return null;
        }

        if ($this->requiresSourceWarehouse() && empty($sourceWarehouseId)) {
            return "Dokument {$this->value} wymaga magazynu źródłowego.";
        }

        if ($this->requiresDestinationWarehouse() && empty($destinationWarehouseId)) {
            return "Dokument {$this->value} wymaga magazynu docelowego.";
        }

        return null;
    }

    /**
     * @return list<array{0:int,1:float}>
     */
    public function movementRows(int $sourceWarehouseId, int $destinationWarehouseId, float $quantity): array
    {
        $rows = [];

        if ($this->decreasesSourceStock()) {
            $rows[] = [$sourceWarehouseId, -$quantity];
        }

        if ($this->increasesDestinationStock()) {
            $rows[] = [$destinationWarehouseId, $quantity];
        }

        return $rows;
    }
}
