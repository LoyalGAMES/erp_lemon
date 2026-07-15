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
            .'Wymagana jest wtyczka Lemon ERP WooCommerce 0.5.3 lub nowsza oraz gotowy bootstrap tłumaczeń globalnych atrybutów i bezpiecznej unikalności GTIN.',
        );
    }

    public static function forRequiredVariantLanguages(): self
    {
        return new self(
            'WooCommerce nie jest gotowy do bezpiecznego utworzenia wersji językowych wariantów. '
            .'Wymagana jest wtyczka Lemon ERP WooCommerce 0.5.3 lub nowsza z obsługą powiązań tłumaczeń wariantów.',
        );
    }
}
