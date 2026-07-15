<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use RuntimeException;

final class WooCommerceProductTranslationNotReadyException extends RuntimeException
{
    public static function forRequiredLanguages(): self
    {
        return new self(
            'WooCommerce nie jest gotowy do bezpiecznego utworzenia wersji językowych produktu. '
            .'Wymagana jest wtyczka Lemon ERP WooCommerce 0.5.2 lub nowsza oraz gotowy bootstrap tłumaczeń globalnych atrybutów.',
        );
    }
}
