<?php

declare(strict_types=1);

define('ABSPATH', __DIR__.'/');

/** @var array<string, array{callback:callable,priority:int,arguments:int}> */
$filters = [];
/** @var list<string> */
$actions = [];
/** @var array<string, array{callback:callable,priority:int,arguments:int}> */
$actionCallbacks = [];
/** @var list<array{namespace:string,route:string,args:array<mixed>}> */
$routes = [];
/** @var array<int, WC_Order> */
$orders = [];

final class WP_REST_Server
{
    public const READABLE = 'GET';

    public const CREATABLE = 'POST';
}

final class WP_REST_Request implements ArrayAccess
{
    /** @param array<string, mixed> $params */
    public function __construct(private array $params = []) {}

    /** @return array<string, mixed> */
    public function get_json_params(): array
    {
        return $this->params;
    }

    /** @return array<string, mixed> */
    public function get_params(): array
    {
        return $this->params;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->params);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->params[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->params[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[(string) $offset]);
    }
}

final class WP_REST_Response
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly int $status = 200,
    ) {}
}

final class WP_Error
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly array $data = [],
    ) {}
}

class WC_Order
{
    /** @var array<string, mixed> */
    public array $meta = [];

    public function __construct(
        public readonly int $id,
        public string $status = 'processing',
    ) {}

    public function get_meta(string $key, bool $single = true): mixed
    {
        return $this->meta[$key] ?? '';
    }

    public function update_meta_data(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function save_meta_data(): void {}

    public function delete_meta_data(string $key): void
    {
        unset($this->meta[$key]);
    }

    public function has_status(string|array $status): bool
    {
        return is_array($status)
            ? in_array($this->status, $status, true)
            : $this->status === $status;
    }
}

function add_action(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void
{
    $GLOBALS['actions'][] = $hook;
    $GLOBALS['actionCallbacks'][$hook] = compact('callback', 'priority', 'arguments');
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $arguments = 1): void
{
    $GLOBALS['filters'][$hook] = compact('callback', 'priority', 'arguments');
}

function register_rest_route(string $namespace, string $route, array $args): void
{
    $GLOBALS['routes'][] = compact('namespace', 'route', 'args');
}

function current_user_can(string $capability, mixed ...$args): bool
{
    return in_array($capability, ['manage_woocommerce', 'edit_shop_orders', 'edit_shop_order'], true);
}

function wc_get_order(mixed $order): ?WC_Order
{
    if ($order instanceof WC_Order) {
        return $order;
    }

    return $GLOBALS['orders'][(int) $order] ?? null;
}

function rest_sanitize_boolean(mixed $value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function __(string $message, string $domain = ''): string
{
    return $message;
}

function expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

require dirname(__DIR__).'/wordpress/lemon-erp-woocommerce/includes/class-order-cancellation-stock.php';

$handler = new Lemon_Erp_Order_Cancellation_Stock;
$handler->hooks();
$handler->registerRestRoutes();

expect(isset($filters['woocommerce_can_restore_order_stock']), 'Missing WooCommerce stock-restoration filter.');
expect($filters['woocommerce_can_restore_order_stock']['arguments'] === 2, 'Stock-restoration filter must receive the order.');
expect($filters['woocommerce_can_restore_order_stock']['priority'] === PHP_INT_MAX, 'Stock-restoration blocker must run at the last possible priority.');
expect(in_array('rest_api_init', $actions, true), 'Missing REST route registration hook.');
expect(isset($actionCallbacks['woocommerce_order_status_changed']), 'Missing stale-decision cleanup hook.');
expect($actionCallbacks['woocommerce_order_status_changed']['arguments'] === 4, 'Status cleanup hook must receive the full transition.');
expect(count($routes) === 2, 'Expected capabilities and configuration routes.');
expect($routes[0]['namespace'] === 'wc-lemon-erp/v1', 'Routes must use the WooCommerce-authenticated namespace.');

$capabilities = $handler->capabilities(new WP_REST_Request);
expect($capabilities->data['available'] === true, 'No-restock capability must be available.');
expect($capabilities->data['plugin_version'] === '0.5.9', 'Unexpected plugin capability version.');
expect($capabilities->data['stock_disposition_contract'] === 1, 'Unexpected stock-disposition contract.');

$uuid = '01234567-89ab-4def-8123-456789abcdef';
$order = new WC_Order(701);
$orders[$order->id] = $order;
$confirmation = $handler->configure(new WP_REST_Request([
    'order_id' => 701,
    'restore_stock' => false,
    'cancellation_uuid' => $uuid,
]));

expect($confirmation instanceof WP_REST_Response, 'Valid no-restock decision must be confirmed.');
expect($confirmation->data['restore_stock'] === false, 'Confirmation changed the no-restock decision.');
expect($order->meta['_lemon_erp_cancellation_restore_stock'] === 'no', 'No-restock marker was not persisted.');
expect($order->meta['_lemon_erp_cancellation_uuid'] === $uuid, 'Cancellation UUID was not persisted.');
expect($order->meta['_lemon_erp_cancellation_stock_state'] === 'armed', 'No-restock decision was not armed.');
expect($handler->canRestoreOrderStock(true, $order) === true, 'An armed marker must not affect a non-cancelled order.');
$order->status = 'cancelled';
expect($handler->canRestoreOrderStock(true, $order) === false, 'WooCommerce stock restoration was not blocked.');
expect($handler->canRestoreOrderStock(1, $order) === false, 'Truthy upstream filter values must still be blocked.');
expect($handler->canRestoreOrderStock(false, $order) === false, 'A prior blocker must remain authoritative.');
expect($order->meta['_lemon_erp_cancellation_stock_state'] === 'applied', 'Used decision was not marked as applied.');

$retry = $handler->configure(new WP_REST_Request([
    'order_id' => 701,
    'restore_stock' => false,
    'cancellation_uuid' => $uuid,
]));
expect($retry instanceof WP_REST_Response, 'Identical retry must remain idempotent.');

$conflict = $handler->configure(new WP_REST_Request([
    'order_id' => 701,
    'restore_stock' => true,
    'cancellation_uuid' => $uuid,
]));
expect($conflict instanceof WP_Error, 'Changing the persisted decision must be rejected.');
expect($conflict->code === 'lemon_erp_cancellation_stock_disposition_conflict', 'Unexpected decision-conflict code.');

$order->status = 'processing';
$handler->orderStatusChanged(701, 'cancelled', 'processing', $order);
expect(! isset($order->meta['_lemon_erp_cancellation_restore_stock']), 'Reopening did not clear the old disposition.');
expect(! isset($order->meta['_lemon_erp_cancellation_uuid']), 'Reopening did not clear the old cancellation UUID.');
expect(! isset($order->meta['_lemon_erp_cancellation_stock_state']), 'Reopening did not clear the old decision state.');

$replacement = $handler->configure(new WP_REST_Request([
    'order_id' => 701,
    'restore_stock' => true,
    'cancellation_uuid' => '21234567-89ab-4def-8123-456789abcdef',
]));
expect($replacement instanceof WP_REST_Response, 'A reopened order must accept a new cancellation decision.');
expect($replacement->data['restore_stock'] === true, 'The replacement decision changed its disposition.');

$missedReopenOrder = new WC_Order(708);
$missedReopenOrder->meta['_lemon_erp_cancellation_restore_stock'] = 'no';
$missedReopenOrder->meta['_lemon_erp_cancellation_uuid'] = '61234567-89ab-4def-8123-456789abcdef';
$missedReopenOrder->meta['_lemon_erp_cancellation_stock_state'] = 'applied';
$orders[$missedReopenOrder->id] = $missedReopenOrder;
$explicitRestore = $handler->configure(new WP_REST_Request([
    'order_id' => 708,
    'restore_stock' => true,
    'cancellation_uuid' => '71234567-89ab-4def-8123-456789abcdef',
]));
expect($explicitRestore instanceof WP_REST_Response, 'Explicit restore must repair a stale marker after a missed reopen hook.');
expect($missedReopenOrder->meta['_lemon_erp_cancellation_restore_stock'] === 'yes', 'Explicit restore did not replace the stale no-restock marker.');
expect($missedReopenOrder->meta['_lemon_erp_cancellation_uuid'] === '71234567-89ab-4def-8123-456789abcdef', 'Explicit restore did not replace the stale UUID.');

$ordinaryOrder = new WC_Order(702);
$orders[$ordinaryOrder->id] = $ordinaryOrder;
expect($handler->canRestoreOrderStock(true, $ordinaryOrder) === true, 'Orders without the no-restock marker must keep WooCommerce defaults.');

$invalidMarkerOrder = new WC_Order(704, 'cancelled');
$invalidMarkerOrder->meta['_lemon_erp_cancellation_restore_stock'] = 'no';
$invalidMarkerOrder->meta['_lemon_erp_cancellation_uuid'] = 'not-a-uuid';
$invalidMarkerOrder->meta['_lemon_erp_cancellation_stock_state'] = 'armed';
$orders[$invalidMarkerOrder->id] = $invalidMarkerOrder;
expect($handler->canRestoreOrderStock(true, $invalidMarkerOrder) === true, 'An invalid or unrelated marker must not block restoration.');

$unconfirmedOrder = new WC_Order(705);
$orders[$unconfirmedOrder->id] = $unconfirmedOrder;
$unconfirmedUuid = '31234567-89ab-4def-8123-456789abcdef';
$armed = $handler->configure(new WP_REST_Request([
    'order_id' => 705,
    'restore_stock' => false,
    'cancellation_uuid' => $unconfirmedUuid,
]));
expect($armed instanceof WP_REST_Response, 'Preflight decision was not armed.');
$unconfirmedOrder->status = 'cancelled';
$unconfirmed = $handler->configure(new WP_REST_Request([
    'order_id' => 705,
    'restore_stock' => false,
    'cancellation_uuid' => $unconfirmedUuid,
]));
expect($unconfirmed instanceof WP_Error, 'An armed-only marker must not be confirmed after cancellation.');
expect($unconfirmed->code === 'lemon_erp_cancellation_stock_unconfirmed', 'Unexpected unconfirmed-marker error code.');
expect($unconfirmedOrder->meta['_lemon_erp_cancellation_stock_state'] === 'armed', 'Ambiguous retry must not manufacture an applied marker.');

$pendingOrder = new WC_Order(706);
$orders[$pendingOrder->id] = $pendingOrder;
$pendingUuid = '41234567-89ab-4def-8123-456789abcdef';
$pendingArmed = $handler->configure(new WP_REST_Request([
    'order_id' => 706,
    'restore_stock' => false,
    'cancellation_uuid' => $pendingUuid,
]));
expect($pendingArmed instanceof WP_REST_Response, 'Pending-transition decision was not armed.');
$pendingOrder->status = 'pending';
expect($handler->canRestoreOrderStock(true, $pendingOrder) === false, 'Pending status must not restore stock for an armed cancellation.');
$handler->orderStatusChanged(706, 'processing', 'pending', $pendingOrder);
$pendingRetry = $handler->configure(new WP_REST_Request([
    'order_id' => 706,
    'restore_stock' => false,
    'cancellation_uuid' => $pendingUuid,
]));
expect($pendingRetry instanceof WP_REST_Response, 'Applied pending transition must remain idempotent.');

$unmarkedPendingOrder = new WC_Order(707, 'pending');
$orders[$unmarkedPendingOrder->id] = $unmarkedPendingOrder;
$unmarkedPending = $handler->configure(new WP_REST_Request([
    'order_id' => 707,
    'restore_stock' => false,
    'cancellation_uuid' => '51234567-89ab-4def-8123-456789abcdef',
]));
expect($unmarkedPending instanceof WP_Error, 'An already-pending unmarked order must fail closed.');
expect($unmarkedPending->code === 'lemon_erp_cancellation_stock_too_late', 'Unexpected pending too-late error code.');

$tooLateOrder = new WC_Order(703, 'cancelled');
$orders[$tooLateOrder->id] = $tooLateOrder;
$tooLate = $handler->configure(new WP_REST_Request([
    'order_id' => 703,
    'restore_stock' => false,
    'cancellation_uuid' => '11234567-89ab-4def-8123-456789abcdef',
]));
expect($tooLate instanceof WP_Error, 'A marker added after cancellation must not be falsely confirmed.');
expect($tooLate->code === 'lemon_erp_cancellation_stock_too_late', 'Unexpected late-marker error code.');

echo "order cancellation stock tests passed\n";
