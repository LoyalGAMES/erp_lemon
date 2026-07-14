<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

$filters = [];

function __(string $message, string $domain): string
{
    return $message;
}

function add_filter(string $hook, callable $callback, int $priority, int $arguments): void
{
    global $filters;
    $filters[$hook] = compact('callback', 'priority', 'arguments');
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('Europe/Warsaw');
}

final class WP_Error
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $data = [],
    ) {}
}

final class WP_REST_Request
{
    public function __construct(private readonly array $parameters) {}

    public function get_param(string $key): mixed
    {
        return $this->parameters[$key] ?? null;
    }
}

final class Fake_WC_Product
{
    public ?int $dateCreated = null;

    public function set_date_created(int $timestamp): void
    {
        $this->dateCreated = $timestamp;
    }
}

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

require dirname(__DIR__).'/wordpress/lemon-erp-woocommerce/includes/class-product-publication-date.php';

$handler = new Lemon_Erp_Product_Publication_Date;
$handler->hooks();

expect(isset($filters['woocommerce_rest_pre_insert_product_object']), 'Missing product pre-insert hook.');
expect(isset($filters['woocommerce_rest_pre_insert_product_variation_object']), 'Missing variation pre-insert hook.');
expect($filters['woocommerce_rest_pre_insert_product_variation_object']['priority'] === 20, 'Unexpected hook priority.');
expect($filters['woocommerce_rest_pre_insert_product_variation_object']['arguments'] === 3, 'Unexpected hook argument count.');

$product = new Fake_WC_Product;
$result = $handler->apply($product, new WP_REST_Request([
    'meta_data' => [[
        'key' => '_sempre_erp_publication_date',
        'value' => '2026-07-14T16:35',
    ]],
]), true);
$expected = (new DateTimeImmutable('2026-07-14T16:35:00', new DateTimeZone('Europe/Warsaw')))->getTimestamp();
expect($result === $product, 'The product object should continue through the REST pipeline.');
expect($product->dateCreated === $expected, 'The local ERP publication date was not applied.');

$unchanged = new Fake_WC_Product;
$result = $handler->apply($unchanged, new WP_REST_Request(['meta_data' => []]), false);
expect($result === $unchanged, 'A request without the ERP date should remain unchanged.');
expect($unchanged->dateCreated === null, 'A missing ERP date must not clear the WooCommerce date.');

$invalid = new Fake_WC_Product;
$result = $handler->apply($invalid, new WP_REST_Request([
    'meta_data' => [[
        'key' => '_sempre_erp_publication_date',
        'value' => '2026-02-31T09:00:00',
    ]],
]), true);
expect($result instanceof WP_Error, 'An invalid publication date should reject the REST write.');
expect($result->code === 'lemon_erp_product_publication_date_invalid', 'Unexpected invalid-date error code.');
expect($invalid->dateCreated === null, 'An invalid date must not mutate the product.');

$conflict = $handler->apply(new Fake_WC_Product, new WP_REST_Request([
    'meta_data' => [
        ['key' => '_sempre_erp_publication_date', 'value' => '2026-07-14T10:00:00'],
        ['key' => '_sempre_erp_publication_date', 'value' => '2026-07-14T11:00:00'],
    ],
]), true);
expect($conflict instanceof WP_Error, 'Conflicting publication dates should reject the REST write.');
expect($conflict->code === 'lemon_erp_product_publication_date_conflict', 'Unexpected conflict error code.');

echo "product publication date tests passed\n";
