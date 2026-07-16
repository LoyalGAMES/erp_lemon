<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

/** @var array<string, array{callback:callable,priority:int,arguments:int}> */
$actions = [];
/** @var array<string, mixed> */
$options = [];
/** @var array<string, mixed> */
$objectCache = [];
/** @var list<string> */
$objectCacheDeletes = [];
$usingExternalObjectCache = false;
$cacheSupportsGroupFlush = false;
$groupFlushSucceeds = true;
$globalFlushSucceeds = true;
$groupFlushes = [];
$globalFlushes = 0;

final class Test_Size_Cache_WPDB
{
    public string $options = 'wp_options';

    public string $last_error = '';

    public int $selectQueries = 0;

    public int $deleteQueries = 0;

    public bool $failNextSelect = false;

    public bool $failNextDelete = false;

    public function esc_like(string $value): string
    {
        return addcslashes($value, '_%\\');
    }

    /** @return array{query:string,args:list<mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        return compact('query', 'args');
    }

    /** @param array{query:string,args:list<mixed>} $prepared */
    public function get_col(array $prepared): array
    {
        $this->selectQueries++;

        if ($this->failNextSelect) {
            $this->failNextSelect = false;
            $this->last_error = 'simulated select error';

            return [];
        }

        $this->last_error = '';
        $names = array_values(array_filter(
            array_keys($GLOBALS['options']),
            static fn (string $optionName): bool => str_starts_with($optionName, '_transient_lemon_sizes_')
                || str_starts_with($optionName, '_transient_timeout_lemon_sizes_'),
        ));
        sort($names, SORT_STRING);

        return $names;
    }

    /** @param array{query:string,args:list<mixed>} $prepared */
    public function query(array $prepared): int|false
    {
        $this->deleteQueries++;

        if ($this->failNextDelete) {
            $this->failNextDelete = false;
            $this->last_error = 'simulated delete error';

            return false;
        }

        $this->last_error = '';
        $deleted = 0;

        foreach (array_keys($GLOBALS['options']) as $optionName) {
            if (str_starts_with($optionName, '_transient_lemon_sizes_')
                || str_starts_with($optionName, '_transient_timeout_lemon_sizes_')
            ) {
                unset($GLOBALS['options'][$optionName]);
                $deleted++;
            }
        }

        return $deleted;
    }
}

$wpdb = new Test_Size_Cache_WPDB;

function add_action(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void
{
    $GLOBALS['actions'][$hook] = compact('callback', 'priority', 'arguments');
}

function get_option(string $key, mixed $default = false): mixed
{
    return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default;
}

function add_option(string $key, mixed $value = '', string $deprecated = '', bool $autoload = true): bool
{
    if (array_key_exists($key, $GLOBALS['options'])) {
        return false;
    }

    $GLOBALS['options'][$key] = $value;

    return true;
}

function update_option(string $key, mixed $value, bool $autoload = true): bool
{
    $GLOBALS['options'][$key] = $value;

    return true;
}

function delete_option(string $key): bool
{
    $exists = array_key_exists($key, $GLOBALS['options']);
    unset($GLOBALS['options'][$key]);

    return $exists;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    $cacheKey = $group.':'.$key;
    $GLOBALS['objectCacheDeletes'][] = $cacheKey;
    $exists = array_key_exists($cacheKey, $GLOBALS['objectCache']);
    unset($GLOBALS['objectCache'][$cacheKey]);

    return $exists;
}

/** @param list<string> $keys */
function wp_cache_delete_multiple(array $keys, string $group = ''): array
{
    $results = [];

    foreach ($keys as $key) {
        $results[$key] = wp_cache_delete($key, $group);
    }

    return $results;
}

function wp_using_ext_object_cache(): bool
{
    return (bool) $GLOBALS['usingExternalObjectCache'];
}

function wp_cache_supports(string $feature): bool
{
    return $feature === 'flush_group' && (bool) $GLOBALS['cacheSupportsGroupFlush'];
}

function wp_cache_flush_group(string $group): bool
{
    $GLOBALS['groupFlushes'][] = $group;

    if (! $GLOBALS['groupFlushSucceeds']) {
        return false;
    }

    foreach (array_keys($GLOBALS['objectCache']) as $cacheKey) {
        if (str_starts_with($cacheKey, $group.':')) {
            unset($GLOBALS['objectCache'][$cacheKey]);
        }
    }

    return true;
}

function wp_cache_flush(): bool
{
    $GLOBALS['globalFlushes']++;

    if (! $GLOBALS['globalFlushSucceeds']) {
        return false;
    }

    $GLOBALS['objectCache'] = [];

    return true;
}

function test_expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function reset_revision(): void
{
    unset(
        $GLOBALS['options']['lemon_erp_storefront_size_cache_revision'],
        $GLOBALS['options']['lemon_erp_storefront_size_cache_revision_lock'],
    );
}

require_once __DIR__.'/../wordpress/lemon-erp-woocommerce/includes/class-storefront-size-cache-upgrade.php';

$upgrade = new Lemon_Erp_Storefront_Size_Cache_Upgrade;
$upgrade->hooks();
test_expect(
    isset($actions['admin_init']) && $actions['admin_init']['priority'] === 1,
    'The purge must run only on early admin init.',
);
test_expect(! isset($actions['init']), 'The purge must not register a storefront init hook.');

// Normal WordPress cache: remove only matching database rows and their exact
// option-cache entries. Unrelated options and runtime cache remain untouched.
$options['_transient_lemon_sizes_product_1_pl'] = ['M/L', 'S/M'];
$options['_transient_timeout_lemon_sizes_product_1_pl'] = time() + 3600;
$options['_transient_lemon_sizes_orphan_data'] = ['M/L', 'S/M'];
$options['_transient_timeout_lemon_sizes_orphan_timeout'] = time() + 3600;
$options['_transient_unrelated_widget'] = 'keep';
$options['_site_transient_lemon_sizes_network_value'] = 'keep';
$objectCache['options:_transient_lemon_sizes_product_1_pl'] = ['M/L', 'S/M'];
$objectCache['options:_transient_timeout_lemon_sizes_product_1_pl'] = time() + 3600;
$objectCache['options:_transient_unrelated_widget'] = 'keep';
$objectCache['transient:unrelated_widget'] = 'keep';

Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();

test_expect($wpdb->selectQueries === 1 && $wpdb->deleteQueries === 1, 'Database cleanup was not bounded to one read and one delete.');
test_expect(! isset($options['_transient_lemon_sizes_product_1_pl']), 'Transient data was not removed.');
test_expect(! isset($options['_transient_timeout_lemon_sizes_product_1_pl']), 'Transient timeout was not removed.');
test_expect(isset($options['_transient_unrelated_widget']), 'An unrelated transient was removed.');
test_expect(isset($options['_site_transient_lemon_sizes_network_value']), 'A site transient was removed.');
test_expect(! isset($objectCache['options:_transient_lemon_sizes_product_1_pl']), 'The exact option cache survived.');
test_expect(isset($objectCache['options:_transient_unrelated_widget']), 'An unrelated option cache was removed.');
test_expect(isset($objectCache['transient:unrelated_widget']), 'An unrelated transient cache was removed.');
test_expect($globalFlushes === 0 && $groupFlushes === [], 'A non-persistent cache was flushed broadly.');
test_expect(
    ($options['lemon_erp_storefront_size_cache_revision'] ?? null)
        === Lemon_Erp_Storefront_Size_Cache_Upgrade::REVISION,
    'Successful cleanup did not persist its revision.',
);

// The revision makes later requests a constant-time no-op.
$selectsAfterCompletion = $wpdb->selectQueries;
$options['_transient_lemon_sizes_after_revision'] = 'keep';
Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();
test_expect($wpdb->selectQueries === $selectsAfterCompletion, 'A completed revision scanned the database again.');
test_expect(isset($options['_transient_lemon_sizes_after_revision']), 'The completed revision ran twice.');
unset($options['_transient_lemon_sizes_after_revision']);

// SQL failure is retryable and never records a false completion.
reset_revision();
$options['_transient_lemon_sizes_retry_sql'] = 'old';
$wpdb->failNextDelete = true;
Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();
test_expect(! isset($options['lemon_erp_storefront_size_cache_revision']), 'Failed SQL was marked complete.');
test_expect(! isset($options['lemon_erp_storefront_size_cache_revision_lock']), 'Failed SQL retained its lock.');
test_expect(isset($options['_transient_lemon_sizes_retry_sql']), 'Failed SQL changed the transient.');
Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();
test_expect(! isset($options['_transient_lemon_sizes_retry_sql']), 'The SQL retry did not clean the transient.');

// External cache with group support can have no DB rows at all. The transient
// transient and product groups are flushed while every other persistent cache
// group remains intact.
reset_revision();
$usingExternalObjectCache = true;
$cacheSupportsGroupFlush = true;
$objectCache = [
    'transient:lemon_sizes_external_only' => ['M/L', 'S/M'],
    'transient:unrelated_transient' => 'also expires',
    'products:historical_variation_attributes' => ['m-l', 's-m'],
    'orders:keep' => 'keep',
];
Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();
test_expect($groupFlushes === ['transient', 'products'], 'External cache did not use narrow group invalidation.');
test_expect($globalFlushes === 0, 'Group-capable cache was flushed globally.');
test_expect(! isset($objectCache['transient:lemon_sizes_external_only']), 'External-only size cache survived.');
test_expect(! isset($objectCache['products:historical_variation_attributes']), 'Historical Woo product cache survived.');
test_expect(isset($objectCache['orders:keep']), 'Group flush removed another cache group.');
test_expect(isset($options['lemon_erp_storefront_size_cache_revision']), 'Group flush did not persist revision.');

// A backend can advertise group flushing and still fail an operation. Both
// narrow groups are attempted first, then the reliable one-time global
// fallback completes the purge instead of retrying forever.
reset_revision();
$groupFlushes = [];
$globalFlushes = 0;
$groupFlushSucceeds = false;
$objectCache = [
    'transient:lemon_sizes_failed_group' => ['M/L', 'S/M'],
    'products:historical_failed_group' => ['m-l', 's-m'],
];
Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();
test_expect($groupFlushes === ['transient', 'products'], 'A failed group flush skipped a cache group.');
test_expect($globalFlushes === 1, 'A failed group flush did not use the global fallback.');
test_expect($objectCache === [], 'Global fallback after group failure did not clear the cache.');
test_expect(isset($options['lemon_erp_storefront_size_cache_revision']), 'Group fallback did not persist revision.');

// Without a portable group operation the one-time global flush is the only
// reliable way to invalidate an unknown external lemon_sizes_* key.
reset_revision();
$groupFlushes = [];
$globalFlushes = 0;
$groupFlushSucceeds = true;
$cacheSupportsGroupFlush = false;
$objectCache = [
    'transient:lemon_sizes_external_fallback' => ['M/L', 'S/M'],
    'products:temporary' => 'old',
];
Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();
test_expect($globalFlushes === 1, 'Unsupported group invalidation did not use the fallback.');
test_expect($objectCache === [], 'Global fallback did not clear the external cache.');
test_expect(isset($options['lemon_erp_storefront_size_cache_revision']), 'Global fallback did not persist revision.');

// A failed external flush leaves both the cache and revision untouched. The
// next request can safely retry and finish the same revision.
reset_revision();
$globalFlushSucceeds = false;
$objectCache = ['transient:lemon_sizes_external_retry' => ['M/L', 'S/M']];
Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();
test_expect(! isset($options['lemon_erp_storefront_size_cache_revision']), 'Failed cache flush was marked complete.');
test_expect(! isset($options['lemon_erp_storefront_size_cache_revision_lock']), 'Failed cache flush retained its lock.');
test_expect(isset($objectCache['transient:lemon_sizes_external_retry']), 'Failed cache flush changed cached data.');
$globalFlushSucceeds = true;
Lemon_Erp_Storefront_Size_Cache_Upgrade::maybeUpgrade();
test_expect(! isset($objectCache['transient:lemon_sizes_external_retry']), 'Cache-flush retry did not remove the value.');
test_expect(isset($options['lemon_erp_storefront_size_cache_revision']), 'Cache-flush retry did not persist revision.');

echo "storefront size cache upgrade tests passed\n";
