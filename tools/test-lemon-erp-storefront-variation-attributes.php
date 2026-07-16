<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

$restRequest = in_array('--rest-request', $argv ?? [], true);

if ($restRequest) {
    define('REST_REQUEST', true);
}

/** @var array<string, array{callback:callable,priority:int,arguments:int}> */
$filters = [];
/** @var array<string, mixed> */
$cache = [];
/** @var array<int, array<string, mixed>> */
$postMeta = [];
/** @var array<int, array<string, list<WP_Term>>> */
$terms = [];
/** @var array<int, int|string> */
$termMeta = [];
/** @var array<string, int> */
$cachePrefixVersions = [];
/** @var list<array{type:string,ids:list<int>}> */
$metaCacheUpdates = [];
$isAdmin = false;

function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void
{
    $GLOBALS['filters'][$hook] = compact('callback', 'priority', 'arguments');
}

function is_admin(): bool
{
    return (bool) $GLOBALS['isAdmin'];
}

function wp_cache_get(string $key, string $group): mixed
{
    return $GLOBALS['cache'][$group.':'.$key] ?? false;
}

function wp_cache_set(string $key, mixed $value, string $group): bool
{
    $GLOBALS['cache'][$group.':'.$key] = $value;

    return true;
}

function wc_variation_attribute_name(string $name): string
{
    return 'attribute_'.strtolower($name);
}

function get_post_meta(int $postId, string $key, bool $single): mixed
{
    return $GLOBALS['postMeta'][$postId][$key] ?? '';
}

function metadata_exists(string $metaType, int $postId, string $key): bool
{
    if ($metaType !== 'post') {
        throw new RuntimeException('Unexpected metadata type.');
    }

    return isset($GLOBALS['postMeta'][$postId])
        && array_key_exists($key, $GLOBALS['postMeta'][$postId]);
}

/** @param list<int> $objectIds */
function update_meta_cache(string $metaType, array $objectIds): array
{
    $GLOBALS['metaCacheUpdates'][] = [
        'type' => $metaType,
        'ids' => array_values($objectIds),
    ];

    return [];
}

/** @return list<WP_Term> */
function wc_get_product_terms(int $productId, string $taxonomy, array $args): array
{
    if (($args['fields'] ?? null) !== 'all') {
        throw new RuntimeException('The canonical term query must request full term objects.');
    }

    if (($args['orderby'] ?? null) !== 'menu_order' || ($args['order'] ?? null) !== 'ASC') {
        throw new RuntimeException('The canonical term query must request ascending WooCommerce menu order.');
    }

    return $GLOBALS['terms'][$productId][$taxonomy] ?? [];
}

function get_term_meta(int $termId, string $key, bool $single): mixed
{
    if ($key !== 'order' || ! $single) {
        throw new RuntimeException('Unexpected term-meta read.');
    }

    return $GLOBALS['termMeta'][$termId] ?? '';
}

function sanitize_title(string $value): string
{
    return strtolower(str_replace(' ', '-', $value));
}

final class WC_Cache_Helper
{
    public static function get_cache_prefix(string $group): string
    {
        $version = $GLOBALS['cachePrefixVersions'][$group] ?? null;

        return 'prefix-'.$group.($version === null ? '' : '-v'.$version).'-';
    }
}

final class WP_Term
{
    public function __construct(
        public readonly int $term_id,
        public readonly string $slug,
        public readonly string $name,
    ) {}
}

final class Fake_Attribute
{
    /** @param list<string> $options */
    public function __construct(
        private readonly string $name,
        private readonly bool $taxonomy,
        private readonly bool $variation,
        private readonly array $options = [],
    ) {}

    public function get_name(): string
    {
        return $this->name;
    }

    public function is_taxonomy(): bool
    {
        return $this->taxonomy;
    }

    public function get_variation(): bool
    {
        return $this->variation;
    }

    /** @return list<string> */
    public function get_options(): array
    {
        return $this->options;
    }
}

final class WC_Product_Variable
{
    /** @var array<string, list<string>>|null */
    public ?array $variationAttributes = null;

    public int $getChildrenCalls = 0;

    public bool $didReenter = false;

    /**
     * @param  list<Fake_Attribute>  $attributes
     * @param  list<int>  $children
     */
    public function __construct(
        private readonly int $id,
        private readonly array $attributes,
        private readonly array $children = [],
        private readonly bool $reenterDuringAttributeRead = false,
    ) {}

    public function get_id(): int
    {
        return $this->id;
    }

    /** @return list<Fake_Attribute> */
    public function get_attributes(): array
    {
        if ($this->reenterDuringAttributeRead && ! $this->didReenter) {
            $this->didReenter = true;
            $this->get_variation_attributes();
        }

        return $this->attributes;
    }

    /** @param array<string, list<string>> $attributes */
    public function set_variation_attributes(array $attributes): void
    {
        $this->variationAttributes = $attributes;
    }

    /** @return list<int> */
    public function get_children(): array
    {
        $this->getChildrenCalls++;

        if ($this->getChildrenCalls > 1) {
            throw new RuntimeException('The normalizer recursed into get_children().');
        }

        $filter = $GLOBALS['filters']['woocommerce_get_children']['callback'];

        return $filter($this->children, $this, false);
    }

    /** @return array<string, list<string>> */
    public function get_variation_attributes(): array
    {
        if ($this->variationAttributes === null) {
            // Mirrors WC 10.9.1: get_children() is invoked before the exact
            // product_variation_attributes_* cache entry is read.
            $this->get_children();
            $key = 'products:prefix-product_'.$this->id.'-product_variation_attributes_'.$this->id;
            $cached = $GLOBALS['cache'][$key] ?? [];
            $this->variationAttributes = is_array($cached) ? $cached : [];
        }

        return $this->variationAttributes;
    }
}

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).', got '.var_export($actual, true).'.');
    }
}

require dirname(__DIR__).'/wordpress/lemon-erp-woocommerce/includes/class-storefront-variation-attributes.php';

$handler = new Lemon_Erp_Storefront_Variation_Attributes;
$handler->hooks();

expect(isset($filters['woocommerce_get_children']), 'Missing WooCommerce children filter.');
expectSame(PHP_INT_MAX, $filters['woocommerce_get_children']['priority'], 'Unexpected filter priority.');
expectSame(3, $filters['woocommerce_get_children']['arguments'], 'Unexpected filter argument count.');

if ($restRequest) {
    $restProduct = new WC_Product_Variable(12, [new Fake_Attribute('pa_rozmiar', true, true)]);
    $handler->normalizeBeforeVariationAttributeRead([91], $restProduct, false);
    expectSame(null, $restProduct->variationAttributes, 'A REST request modified product data or cache state.');
    expectSame([], $cache, 'A REST request wrote to the WooCommerce object cache.');
    echo "storefront variation attribute REST isolation tests passed\n";

    exit(0);
}

$attributes = [
    new Fake_Attribute('pa_rozmiar', true, true),
    new Fake_Attribute('pa_kolor', true, true),
    new Fake_Attribute('Materiał', false, true, ['Bawełna', 'Len', 'Jedwab']),
    new Fake_Attribute('pa_sklad', true, false),
];
$product = new WC_Product_Variable(44, $attributes);
$children = [101, 102, 103];
$postMeta = [
    44 => ['_product_version' => '10.9.1'],
    101 => [
        'attribute_pa_rozmiar' => 'm-l',
        'attribute_pa_kolor' => 'legacy-blue',
        'attribute_materiał' => 'Len',
    ],
    102 => [
        'attribute_pa_rozmiar' => 's-m',
        'attribute_pa_kolor' => 'black',
        'attribute_materiał' => 'Bawełna',
    ],
    103 => [
        'attribute_pa_rozmiar' => 'm-l',
        'attribute_pa_kolor' => 'legacy-blue',
        'attribute_materiał' => 'Len',
    ],
];
$terms = [
    44 => [
        // Deliberately stale query-cache order: current term meta below is the
        // source of truth and must produce S/M before M/L.
        'pa_rozmiar' => [
            new WP_Term(4402, 'm-l', 'M/L'),
            new WP_Term(4401, 's-m', 'S/M'),
            new WP_Term(4403, 'l-xl', 'L/XL'),
        ],
        'pa_kolor' => [
            new WP_Term(4412, 'cream', 'Cream'),
            new WP_Term(4411, 'black', 'Black'),
        ],
    ],
];
$termMeta = [
    4401 => 10,
    4402 => 20,
    4403 => 30,
    4411 => 10,
    4412 => 20,
];
$cacheKey = 'products:prefix-product_44-product_variation_attributes_44';
$cache[$cacheKey] = [
    'pa_rozmiar' => ['m-l', 's-m'],
    'pa_kolor' => ['legacy-blue', 'black'],
    // Deliberately unusual: the module must preserve an existing text value.
    'Materiał' => ['Len', 'Bawełna'],
];

$result = $handler->normalizeBeforeVariationAttributeRead($children, $product, false);

expectSame($children, $result, 'The filter must never alter child IDs.');
expectSame(0, $product->getChildrenCalls, 'The filter recursed into get_children().');
expectSame([
    'type' => 'post',
    'ids' => $children,
], $metaCacheUpdates[0] ?? null, 'Variation metadata was not primed in one bulk read.');
expectSame([
    'pa_rozmiar' => ['s-m', 'm-l'],
    'pa_kolor' => ['black', 'legacy-blue'],
    'Materiał' => ['Len', 'Bawełna'],
], $product->variationAttributes, 'The current product object received incorrect variation attributes.');
expectSame($product->variationAttributes, $cache[$cacheKey], 'The exact WooCommerce cache entry was not replaced.');

// A fresh WC_Product_Variable object for the same product must receive the
// canonical payload as well, even though this product was already normalized.
$freshProduct = new WC_Product_Variable(44, $attributes, $children);
expectSame($product->variationAttributes, $freshProduct->get_variation_attributes(), 'A fresh product object kept stale ordering.');
expectSame(1, $freshProduct->getChildrenCalls, 'The WooCommerce-compatible lazy path did not run exactly once.');

// No existing cache: preserve WooCommerce's configured text option order and
// include every taxonomy term for an "any value" (empty-meta) variation.
$uncachedProduct = new WC_Product_Variable(55, [
    new Fake_Attribute('pa_rozmiar', true, true),
    new Fake_Attribute('Materiał', false, true, ['Bawełna', 'Len', 'Jedwab']),
]);
$postMeta[55] = ['_product_version' => '10.9.1'];
$postMeta[201] = ['attribute_pa_rozmiar' => '', 'attribute_materiał' => 'Len'];
$postMeta[202] = ['attribute_pa_rozmiar' => 'm-l', 'attribute_materiał' => 'Bawełna'];
$terms[55] = ['pa_rozmiar' => [
    new WP_Term(5503, 'm-l', 'M/L'),
    new WP_Term(5501, 'xs', 'XS'),
    new WP_Term(5502, 's-m', 'S/M'),
]];
$termMeta += [5501 => 10, 5502 => 20, 5503 => 30];
$handler->normalizeBeforeVariationAttributeRead([201, 202], $uncachedProduct, false);
expectSame([
    'pa_rozmiar' => ['xs', 's-m', 'm-l'],
    'Materiał' => ['Bawełna', 'Len'],
], $uncachedProduct->variationAttributes, 'The uncached WooCommerce semantics were not preserved.');

// A child without the attribute meta row is absent from WooCommerce's SQL
// result. It must not be mistaken for an explicitly stored empty wildcard.
$missingMetaProduct = new WC_Product_Variable(56, [
    new Fake_Attribute('pa_rozmiar', true, true),
]);
$postMeta[211] = ['attribute_pa_rozmiar' => 'm-l'];
$postMeta[212] = ['attribute_inny' => 'ignored'];
$terms[56] = ['pa_rozmiar' => [
    new WP_Term(5603, 'm-l', 'M/L'),
    new WP_Term(5602, 's-m', 'S/M'),
    new WP_Term(5601, 'xs', 'XS'),
]];
$termMeta += [5601 => 10, 5602 => 20, 5603 => 30];
$handler->normalizeBeforeVariationAttributeRead([211, 212], $missingMetaProduct, false);
expectSame([
    'pa_rozmiar' => ['m-l'],
], $missingMetaProduct->variationAttributes, 'A missing child meta row was incorrectly treated as a wildcard.');

// A getter filter may re-enter get_variation_attributes(). The inner pass is
// allowed to read the old cache, but must not recurse indefinitely; the outer
// pass replaces both the current object and cache with canonical ordering.
$reentrantProduct = new WC_Product_Variable(57, [
    new Fake_Attribute('pa_rozmiar', true, true),
], [221, 222], true);
$postMeta[221] = ['attribute_pa_rozmiar' => 'm-l'];
$postMeta[222] = ['attribute_pa_rozmiar' => 's-m'];
$terms[57] = ['pa_rozmiar' => [
    new WP_Term(5702, 'm-l', 'M/L'),
    new WP_Term(5701, 's-m', 'S/M'),
]];
$termMeta += [5701 => 10, 5702 => 20];
$reentrantCacheKey = 'products:prefix-product_57-product_variation_attributes_57';
$cache[$reentrantCacheKey] = ['pa_rozmiar' => ['m-l', 's-m']];
$handler->normalizeBeforeVariationAttributeRead([221, 222], $reentrantProduct, false);
expect($reentrantProduct->didReenter, 'The reentrant harness path did not execute.');
expectSame(1, $reentrantProduct->getChildrenCalls, 'A reentrant attribute getter recursed into get_children().');
expectSame([
    'pa_rozmiar' => ['s-m', 'm-l'],
], $reentrantProduct->variationAttributes, 'The outer normalization did not replace a reentrant stale read.');
expectSame($reentrantProduct->variationAttributes, $cache[$reentrantCacheKey], 'Reentrant normalization left stale cache data.');

// Equal menu positions use Woo's name fallback and a stable ID tie-break,
// never the arbitrary cached query order.
$tieProduct = new WC_Product_Variable(58, [new Fake_Attribute('pa_rozmiar', true, true)]);
$postMeta[231] = ['attribute_pa_rozmiar' => 'beta'];
$postMeta[232] = ['attribute_pa_rozmiar' => 'alpha-b'];
$postMeta[233] = ['attribute_pa_rozmiar' => 'alpha-a'];
$terms[58] = ['pa_rozmiar' => [
    new WP_Term(5803, 'beta', 'Beta'),
    new WP_Term(5809, 'alpha-b', 'Alpha'),
    new WP_Term(5804, 'alpha-a', 'Alpha'),
]];
$termMeta += [5803 => 10, 5809 => 10, 5804 => 10];
$handler->normalizeBeforeVariationAttributeRead([231, 232, 233], $tieProduct, false);
expectSame([
    'pa_rozmiar' => ['alpha-a', 'alpha-b', 'beta'],
], $tieProduct->variationAttributes, 'Equal menu positions did not use deterministic name/ID ordering.');

// In-request memoization must follow Woo's live product cache prefix. Once the
// prefix changes, a fresh object must be normalized from current term meta,
// not receive the payload memoized under the previous generation.
$memoProduct = new WC_Product_Variable(59, [new Fake_Attribute('pa_rozmiar', true, true)]);
$postMeta[241] = ['attribute_pa_rozmiar' => 'small'];
$postMeta[242] = ['attribute_pa_rozmiar' => 'large'];
$terms[59] = ['pa_rozmiar' => [
    new WP_Term(5902, 'large', 'Large'),
    new WP_Term(5901, 'small', 'Small'),
]];
$termMeta += [5901 => 10, 5902 => 20];
$handler->normalizeBeforeVariationAttributeRead([241, 242], $memoProduct, false);
expectSame([
    'pa_rozmiar' => ['small', 'large'],
], $memoProduct->variationAttributes, 'Initial cache generation was not normalized.');

$termMeta[5901] = 20;
$termMeta[5902] = 10;
$cachePrefixVersions['product_59'] = 2;
$memoProductAfterInvalidation = new WC_Product_Variable(59, [new Fake_Attribute('pa_rozmiar', true, true)]);
$handler->normalizeBeforeVariationAttributeRead([241, 242], $memoProductAfterInvalidation, false);
expectSame([
    'pa_rozmiar' => ['large', 'small'],
], $memoProductAfterInvalidation->variationAttributes, 'A new cache prefix replayed stale in-request memoized values.');
expectSame(
    $memoProductAfterInvalidation->variationAttributes,
    $cache['products:prefix-product_59-v2-product_variation_attributes_59'] ?? null,
    'The new product cache generation did not receive the normalized payload.',
);

// Visible-only reads happen in a different WooCommerce code path and must not
// seed a cache from an incomplete child list.
$visibleProduct = new WC_Product_Variable(66, $attributes);
$handler->normalizeBeforeVariationAttributeRead([301], $visibleProduct, true);
expectSame(null, $visibleProduct->variationAttributes, 'Visible-only children unexpectedly changed the variation cache.');

// Admin requests are deliberately side-effect free.
$isAdmin = true;
$adminProduct = new WC_Product_Variable(77, $attributes);
$handler->normalizeBeforeVariationAttributeRead([401], $adminProduct, false);
expectSame(null, $adminProduct->variationAttributes, 'Admin product data was modified.');

echo "storefront variation attribute tests passed\n";
