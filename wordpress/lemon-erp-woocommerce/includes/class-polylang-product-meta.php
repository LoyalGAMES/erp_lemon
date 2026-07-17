<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps language-specific storefront metadata independent in Polylang.
 *
 * ERP writes the same metadata key to separate PL and EN product posts. When
 * Polylang custom-field synchronization is enabled, its default behavior
 * copies the last saved value to every translation. Removing only the two
 * localized keys from that copy list preserves language-neutral settings such
 * as shipping days, preorder state and label colors.
 */
final class Lemon_Erp_Polylang_Product_Meta
{
    /** @var list<string> */
    private const LOCALIZED_META_KEYS = [
        'lemon_shipping_text',
        '_lemon_product_label_text',
    ];

    public static function register(): void
    {
        add_filter('pll_copy_post_metas', [self::class, 'excludeLocalizedMeta'], 10, 5);
    }

    /**
     * @param  list<string>  $metas
     * @return list<string>
     */
    public static function excludeLocalizedMeta(
        array $metas,
        bool $sync = false,
        int $from = 0,
        int $to = 0,
        string $language = '',
    ): array {
        unset($sync, $from, $to, $language);

        return array_values(array_diff($metas, self::LOCALIZED_META_KEYS));
    }
}
