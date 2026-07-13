<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

final class WP_Error
{
    public function __construct(
        private readonly string $code,
        private readonly string $message,
    ) {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

/** @var array<int, array<string, mixed>> $meta */
$meta = [];
/** @var list<int> $postIds */
$postIds = [];
/** @var array<string, mixed> $resolvedOrder */
$resolvedOrder = [];

function get_posts(array $args): array
{
    return $GLOBALS['postIds'];
}

function get_post_meta(int $postId, string $key, bool $single = false): mixed
{
    return $GLOBALS['meta'][$postId][$key] ?? '';
}

function sanitize_key(mixed $value): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
}

function sanitize_text_field(mixed $value): string
{
    return trim((string) $value);
}

function sanitize_email(mixed $value): string
{
    return trim((string) $value);
}

function esc_url_raw(mixed $value): string
{
    return trim((string) $value);
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function __(string $value, string $domain = ''): string
{
    return $value;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    if ($hook === 'll_returns_resolve_order') {
        return $GLOBALS['resolvedOrder'];
    }

    return $value;
}

final class LL_Returns_Settings {}

final class LL_Returns_ERP_Client {}

require __DIR__.'/../wordpress/lemon-woo-returns/includes/class-return-repository.php';
require __DIR__.'/../wordpress/lemon-woo-returns/includes/class-order-service.php';

function assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$prefix = LL_Returns_Return_Repository::META_PREFIX;
$payload = static fn (string $orderKey, int $quantity): array => [
    'return_order_key' => $orderKey,
    'order_id' => '9001',
    'order_reference' => '12345',
    'order_number' => '12345',
    'items' => [
        ['id' => '771', 'return_item_key' => '771', 'quantity' => $quantity],
    ],
];

$meta = [
    1 => [
        $prefix.'status' => 'submitting',
        $prefix.'return_number' => 'RET-1',
        $prefix.'payload' => $payload('erp:1:9001', 1),
    ],
    2 => [
        $prefix.'status' => 'erp_failed',
        $prefix.'return_number' => 'RET-2',
        $prefix.'payload' => $payload('erp:1:9001', 1),
    ],
    3 => [
        $prefix.'status' => 'pending_package',
        $prefix.'return_number' => 'RET-3',
        $prefix.'erp_mode' => 'remote',
        $prefix.'erp_external_id' => 'ERP/3',
        $prefix.'payload' => $payload('erp:1:9001', 1),
    ],
    4 => [
        $prefix.'status' => 'completed',
        $prefix.'return_number' => 'RET-4',
        $prefix.'wc_refund_id' => 44,
        $prefix.'payload' => $payload('erp:1:9001', 1),
    ],
    5 => [
        $prefix.'status' => 'submitting',
        $prefix.'return_number' => 'RET-5',
        $prefix.'payload' => $payload('erp:1:other', 1),
    ],
];
$postIds = [1, 2, 3, 4, 5];
$resolvedOrder = [
    'source' => 'erp',
    'order_id' => '9001',
    'wc_order_id' => '9001',
    'return_order_key' => 'erp:1:9001',
    'order_reference' => '12345',
    'order_number' => '12345',
    'currency' => 'PLN',
    'customer_email' => 'client@example.test',
    'customer_phone' => '123456789',
    'accounted_return_references' => [],
    'items' => [
        [
            'id' => '771',
            'return_item_key' => '771',
            'wc_order_item_id' => '771',
            'name' => 'Produkt',
            'sku' => 'SKU-1',
            'quantity' => 3,
            'image' => '',
            'price' => 10,
        ],
    ],
];

$repository = new LL_Returns_Return_Repository;
$service = new LL_Returns_Order_Service(new LL_Returns_Settings, new LL_Returns_ERP_Client, $repository);
$order = $service->resolve_order('12345', 'client@example.test');

assert_true(! is_wp_error($order), 'Order with one remaining unit must still be returnable.');
assert_true($order['items'][0]['quantity'] === 1, 'Submitting and ERP-failed local records must reserve two units.');

$resolvedOrder['accounted_return_references'] = ['RET-2'];
$order = $service->resolve_order('12345', 'client@example.test');
assert_true($order['items'][0]['quantity'] === 2, 'A return already counted by ERP must not be subtracted locally again.');

$duplicate = $service->validate_return_items($order, [
    ['id' => '771', 'quantity' => 1],
    ['id' => '771', 'quantity' => 1],
]);
assert_true(is_wp_error($duplicate), 'The same item ID submitted twice must be rejected.');
assert_true($duplicate->get_error_code() === 'll_returns_duplicate_item', 'Duplicate item error code must be stable.');

fwrite(STDOUT, "Lemon Woo Returns quantity tests passed.\n");
