<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

/** @var array<string, array{callback:callable,priority:int,arguments:int}> */
$filters = [];

function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void
{
    $GLOBALS['filters'][$hook] = compact('callback', 'priority', 'arguments');
}

function test_expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__.'/../wordpress/lemon-erp-woocommerce/includes/class-polylang-product-meta.php';

Lemon_Erp_Polylang_Product_Meta::register();

$registration = $filters['pll_copy_post_metas'] ?? null;
test_expect(is_array($registration), 'The Polylang metadata filter was not registered.');
test_expect($registration['priority'] === 10, 'The metadata filter has an unexpected priority.');
test_expect($registration['arguments'] === 5, 'The metadata filter must receive the full Polylang context.');

$source = [
    '_sku',
    'lemon_shipping_days',
    'lemon_shipping_text',
    'lemon_preorder',
    '_lemon_product_label_text',
    '_lemon_product_label_bg_color',
    '_lemon_product_label_text_color',
];
$expected = [
    '_sku',
    'lemon_shipping_days',
    'lemon_preorder',
    '_lemon_product_label_bg_color',
    '_lemon_product_label_text_color',
];

foreach ([false, true] as $synchronizing) {
    $result = ($registration['callback'])($source, $synchronizing, 101, 202, 'en');
    test_expect(
        $result === $expected,
        'Only the language-specific shipping and custom-label texts may be excluded.',
    );
}

fwrite(STDOUT, "Polylang product meta isolation tests passed\n");
