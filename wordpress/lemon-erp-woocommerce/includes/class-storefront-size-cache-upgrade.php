<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Purges historical size tiles and product data cached before version 0.5.6.
 *
 * Version 0.5.6 removes the storefront normalizer completely. Theme
 * transients and persistent WooCommerce product objects created by an older
 * version can otherwise keep its wrong availability/order after the code is
 * gone. This admin-only, revisioned cleanup deletes those historical values
 * once; it never creates a cache or changes product presentation at runtime.
 */
final class Lemon_Erp_Storefront_Size_Cache_Upgrade
{
    public const REVISION = 'lemon_sizes_2026_07_16_000002';

    private const REVISION_OPTION = 'lemon_erp_storefront_size_cache_revision';

    private const LOCK_OPTION = 'lemon_erp_storefront_size_cache_revision_lock';

    private const DATA_OPTION_PREFIX = '_transient_lemon_sizes_';

    private const TIMEOUT_OPTION_PREFIX = '_transient_timeout_lemon_sizes_';

    private const LOCK_TTL_SECONDS = 300;

    public function hooks(): void
    {
        add_action('admin_init', [self::class, 'maybeUpgrade'], 1);
    }

    /**
     * Performs the cleanup once per revision. An interrupted or failed pass
     * does not write the completion marker, so a later request retries it.
     */
    public static function maybeUpgrade(): void
    {
        if (! self::functionsAvailable() || self::completed()) {
            return;
        }

        if (! self::acquireLock()) {
            return;
        }

        try {
            // Another request can finish between the initial read and this
            // request acquiring a stale lock.
            if (self::completed() || ! self::deleteHistoricalTransients()) {
                return;
            }

            update_option(self::REVISION_OPTION, self::REVISION, false);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function completed(): bool
    {
        return get_option(self::REVISION_OPTION, '') === self::REVISION;
    }

    private static function acquireLock(): bool
    {
        $now = time();
        $lock = get_option(self::LOCK_OPTION, []);

        if (is_array($lock)
            && ($lock['revision'] ?? null) === self::REVISION
            && is_numeric($lock['started_at'] ?? null)
            && (int) $lock['started_at'] > $now - self::LOCK_TTL_SECONDS
        ) {
            return false;
        }

        if ($lock !== []) {
            delete_option(self::LOCK_OPTION);
        }

        return add_option(self::LOCK_OPTION, [
            'revision' => self::REVISION,
            'started_at' => $now,
        ], '', false);
    }

    /**
     * Deletes both database representations in one query, then invalidates the
     * exact wp_options cache keys discovered by the preceding read. This keeps
     * the one-time upgrade bounded even on a catalog with many products.
     */
    private static function deleteHistoricalTransients(): bool
    {
        global $wpdb;

        if (! is_object($wpdb)
            || ! isset($wpdb->options)
            || ! is_string($wpdb->options)
            || $wpdb->options === ''
            || ! method_exists($wpdb, 'esc_like')
            || ! method_exists($wpdb, 'prepare')
            || ! method_exists($wpdb, 'get_col')
            || ! method_exists($wpdb, 'query')
        ) {
            return false;
        }

        $dataLike = $wpdb->esc_like(self::DATA_OPTION_PREFIX).'%';
        $timeoutLike = $wpdb->esc_like(self::TIMEOUT_OPTION_PREFIX).'%';
        $optionNames = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options}\n"
            .'WHERE option_name LIKE %s OR option_name LIKE %s',
            $dataLike,
            $timeoutLike,
        ));

        if (! is_array($optionNames)
            || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')
        ) {
            return false;
        }

        $optionNames = array_values(array_unique(array_filter(
            $optionNames,
            static fn (mixed $optionName): bool => is_string($optionName)
                && (str_starts_with($optionName, self::DATA_OPTION_PREFIX)
                    || str_starts_with($optionName, self::TIMEOUT_OPTION_PREFIX)),
        )));

        if ($optionNames !== []) {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options}\n"
                .'WHERE option_name LIKE %s OR option_name LIKE %s',
                $dataLike,
                $timeoutLike,
            ));

            if ($deleted === false
                || (isset($wpdb->last_error) && trim((string) $wpdb->last_error) !== '')
            ) {
                return false;
            }

            if (function_exists('wp_cache_delete_multiple')) {
                wp_cache_delete_multiple($optionNames, 'options');
            } else {
                foreach ($optionNames as $optionName) {
                    wp_cache_delete($optionName, 'options');
                }
            }

            // A non-expiring transient can be autoloaded. Clearing these two
            // aggregate option-cache entries prevents a value removed by the
            // bulk SQL query from surviving in the current request.
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
        }

        return self::flushExternalObjectCache();
    }

    /**
     * With an external object cache WordPress can retain both theme transients
     * and the `product_variation_attributes_*` values written by the removed
     * normalizer. Prefer narrow group invalidation for `transient` and
     * `products`. Older backends expose no portable key enumeration or group
     * invalidation, so a one-time full flush is the only reliable fallback.
     */
    private static function flushExternalObjectCache(): bool
    {
        if (! wp_using_ext_object_cache()) {
            return true;
        }

        if (function_exists('wp_cache_supports')
            && function_exists('wp_cache_flush_group')
            && wp_cache_supports('flush_group')
        ) {
            $transientsFlushed = wp_cache_flush_group('transient');
            $productsFlushed = wp_cache_flush_group('products');

            if ($transientsFlushed && $productsFlushed) {
                return true;
            }
        }

        return wp_cache_flush();
    }

    private static function functionsAvailable(): bool
    {
        return function_exists('add_option')
            && function_exists('delete_option')
            && function_exists('get_option')
            && function_exists('update_option')
            && function_exists('wp_cache_delete')
            && function_exists('wp_cache_flush')
            && function_exists('wp_using_ext_object_cache');
    }
}
