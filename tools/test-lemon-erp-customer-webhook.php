<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

final class WP_Error
{
    public function __construct(
        private readonly string $code,
        private readonly string $message,
        private readonly mixed $data = null,
    ) {}

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

final class WP_REST_Server
{
    public const READABLE = 'GET';

    public const CREATABLE = 'POST';

    public const DELETABLE = 'DELETE';
}

final class WP_REST_Request
{
    /** @param array<string, mixed> $params */
    public function __construct(private readonly array $params = []) {}

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
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

final class WP_User
{
    /** @param list<string> $roles */
    public function __construct(public readonly array $roles) {}
}

final class Test_WPDB
{
    public string $prefix = 'wp_';

    /** @var array<string, mixed> */
    public array $apiKey = [
        'key_id' => 73,
        'user_id' => 7,
        'permissions' => 'read_write',
        'consumer_key' => '',
        'consumer_secret' => 'cs_shared_secret_987654321',
    ];

    /** @return array{query:string,args:list<mixed>} */
    public function prepare(string $query, mixed ...$args): array
    {
        return ['query' => $query, 'args' => $args];
    }

    /** @param array{query:string,args:list<mixed>} $prepared */
    public function get_row(array $prepared, string $format): ?array
    {
        if (str_contains($prepared['query'], 'consumer_key =')) {
            return ($prepared['args'][0] ?? null) === $this->apiKey['consumer_key']
                ? $this->apiKey
                : null;
        }

        return (int) ($prepared['args'][0] ?? 0) === (int) $this->apiKey['key_id']
            ? $this->apiKey
            : null;
    }
}

final class Test_Logger
{
    /** @var list<array{level:string,message:string,context:array<string,mixed>}> */
    public array $entries = [];

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = compact('level', 'message', 'context');
    }
}

/** @var array<string, mixed> */
$options = [];
/** @var array<string, mixed> */
$transients = [];
/** @var array<int, WP_User> */
$users = [
    91 => new WP_User(['customer']),
    92 => new WP_User(['administrator']),
    93 => new WP_User(['subscriber']),
];
/** @var list<array{hook:string,args:array<mixed>,group:string,unique:bool}> */
$asyncActions = [];
/** @var list<array{timestamp:int,hook:string,args:array<mixed>,group:string,unique:bool}> */
$scheduledActions = [];
/** @var list<array{url:string,args:array<string,mixed>}> */
$httpRequests = [];
/** @var array<string, mixed>|WP_Error */
$httpResponse = ['response' => ['code' => 202], 'body' => ''];
/** @var list<array{namespace:string,route:string,args:array<mixed>}> */
$restRoutes = [];
/** @var list<string> */
$registeredActions = [];
$logger = new Test_Logger();
$wpdb = new Test_WPDB();

function add_action(string $hook, mixed $callback, int $priority = 10, int $acceptedArgs = 1): void
{
    $GLOBALS['registeredActions'][] = $hook;
}

function register_rest_route(string $namespace, string $route, array $args): void
{
    $GLOBALS['restRoutes'][] = compact('namespace', 'route', 'args');
}

function current_user_can(string $capability): bool
{
    return $capability === 'manage_woocommerce';
}

function get_current_user_id(): int
{
    return 7;
}

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['options'][$key] ?? $default;
}

function update_option(string $key, mixed $value, bool $autoload = true): bool
{
    $GLOBALS['options'][$key] = $value;

    return true;
}

function delete_option(string $key): bool
{
    unset($GLOBALS['options'][$key]);

    return true;
}

function get_transient(string $key): mixed
{
    return $GLOBALS['transients'][$key] ?? false;
}

function set_transient(string $key, mixed $value, int $expiration): bool
{
    $GLOBALS['transients'][$key] = $value;

    return true;
}

function get_userdata(int $userId): WP_User|false
{
    return $GLOBALS['users'][$userId] ?? false;
}

function sanitize_key(mixed $value): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    return $value;
}

function esc_url_raw(string $url, array $protocols = []): string
{
    return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : '';
}

function wp_parse_url(string $url): array|false
{
    return parse_url($url);
}

function wp_http_validate_url(string $url): string|false
{
    $parts = parse_url($url);

    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && ($parts['host'] ?? '') === 'erp.example.test'
            ? $url
            : false;
}

function wc_api_hash(string $consumerKey): string
{
    return hash_hmac('sha256', $consumerKey, 'wc-api');
}

function __(string $message, string $domain = ''): string
{
    return $message;
}

function wp_generate_uuid4(): string
{
    return '01234567-89ab-4def-8123-456789abcdef';
}

function wp_is_uuid(string $uuid, ?int $version = null): bool
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
}

function home_url(string $path = ''): string
{
    return 'https://shop.example.test'.($path === '/' ? '/' : $path);
}

function wp_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, $flags);
}

function as_enqueue_async_action(string $hook, array $args = [], string $group = '', bool $unique = false): int
{
    $GLOBALS['asyncActions'][] = compact('hook', 'args', 'group', 'unique');

    return count($GLOBALS['asyncActions']);
}

function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = '', bool $unique = false): int
{
    $GLOBALS['scheduledActions'][] = compact('timestamp', 'hook', 'args', 'group', 'unique');

    return count($GLOBALS['scheduledActions']);
}

function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool
{
    return true;
}

function wp_safe_remote_post(string $url, array $args): array|WP_Error
{
    $GLOBALS['httpRequests'][] = compact('url', 'args');

    return $GLOBALS['httpResponse'];
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code(array $response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wc_get_logger(): Test_Logger
{
    return $GLOBALS['logger'];
}

function assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

require __DIR__.'/../wordpress/lemon-erp-woocommerce/includes/class-customer-webhook.php';

$consumerKey = 'ck_1234567890123456789012345678901234567890';
$wpdb->apiKey['consumer_key'] = wc_api_hash($consumerKey);
$webhook = new Lemon_Erp_Customer_Webhook();
$webhook->hooks();

assert_true(in_array('woocommerce_created_customer', $registeredActions, true), 'Customer creation hook must be registered.');
assert_true(in_array('woocommerce_update_customer', $registeredActions, true), 'Customer update hook must be registered.');
assert_true(in_array('lemon_erp_deliver_customer_webhook', $registeredActions, true), 'Delivery callback must be registered.');

$webhook->registerRestRoutes();
assert_true(($restRoutes[0]['route'] ?? '') === '/customer-webhook/configure', 'Configuration REST route must be registered.');

$invalidConfiguration = $webhook->configure(new WP_REST_Request([
    'delivery_url' => 'http://erp.example.test/api/customer-webhook/1',
    'consumer_key' => $consumerKey,
]));
assert_true($invalidConfiguration instanceof WP_Error, 'Configuration must reject non-HTTPS delivery URLs.');

$wpdb->apiKey['permissions'] = 'read';
$readOnlyConfiguration = $webhook->configure(new WP_REST_Request([
    'delivery_url' => 'https://erp.example.test/api/woocommerce/customer-webhook/42',
    'consumer_key' => $consumerKey,
]));
assert_true($readOnlyConfiguration instanceof WP_Error, 'Configuration must reject a read-only WooCommerce API key.');
$wpdb->apiKey['permissions'] = 'read_write';

$configured = $webhook->configure(new WP_REST_Request([
    'delivery_url' => 'https://erp.example.test/api/woocommerce/customer-webhook/42',
    'consumer_key' => $consumerKey,
]));
assert_true($configured instanceof WP_REST_Response && $configured->status === 200, 'Valid webhook configuration must succeed.');
assert_true(($options['lemon_erp_customer_webhook']['key_id'] ?? null) === 73, 'Only the WooCommerce API key ID must be persisted.');
assert_true(! array_key_exists('consumer_key', $options['lemon_erp_customer_webhook']), 'Consumer Key must not be persisted in plugin settings.');
assert_true(! array_key_exists('consumer_secret', $options['lemon_erp_customer_webhook']), 'Consumer Secret must not be copied to plugin settings.');

$webhook->customerCreated(92);
$webhook->customerCreated(93);
assert_true($asyncActions === [], 'Administrator and subscriber accounts must not produce customer webhooks.');

$webhook->customerCreated(91);
assert_true(count($asyncActions) === 1, 'Customer registration must enqueue one asynchronous action.');
assert_true($httpRequests === [], 'Customer registration must not perform a blocking HTTP call.');

$queuedEvent = $asyncActions[0]['args'][0] ?? [];
assert_true(($queuedEvent['event'] ?? null) === 'customer.created', 'Queued event must describe account creation.');
assert_true(($queuedEvent['customer_id'] ?? null) === 91, 'Queued event must identify the WooCommerce customer.');

$webhook->userRegistered(91);
assert_true(count($asyncActions) === 1, 'Duplicate WordPress and WooCommerce create hooks must be debounced.');

$webhook->customerUpdated(91);
assert_true(count($asyncActions) === 2, 'Customer profile changes must enqueue an asynchronous update event.');
assert_true(($asyncActions[1]['args'][0]['event'] ?? null) === 'customer.updated', 'Update hook must use the customer.updated event name.');

$webhook->deliver($queuedEvent);
assert_true(count($httpRequests) === 1, 'Action Scheduler callback must deliver exactly one HTTP request.');
$request = $httpRequests[0];
$payload = json_decode((string) $request['args']['body'], true, flags: JSON_THROW_ON_ERROR);
assert_true(array_keys($payload) === ['event', 'event_id', 'occurred_at', 'store_url', 'customer_id'], 'Webhook payload must contain only the minimal synchronization contract.');
assert_true(! isset($payload['email'], $payload['customer']), 'Webhook payload must not expose customer PII.');
assert_true($request['args']['redirection'] === 0, 'Signed webhook must not follow redirects to another host.');
assert_true($request['args']['reject_unsafe_urls'] === true, 'Webhook delivery must reject unsafe destination URLs.');
assert_true($request['args']['headers']['X-Lemon-Webhook-Id'] === $payload['event_id'], 'Header and body event IDs must match.');
assert_true($request['args']['headers']['X-Lemon-Webhook-Event'] === $payload['event'], 'Header and body event names must match.');
$timestamp = $request['args']['headers']['X-Lemon-Webhook-Timestamp'];
$expectedSignature = base64_encode(hash_hmac('sha256', $timestamp.'.'.$request['args']['body'], $wpdb->apiKey['consumer_secret'], true));
assert_true(hash_equals($expectedSignature, $request['args']['headers']['X-Lemon-Webhook-Signature']), 'Webhook must be signed with the existing WooCommerce consumer secret.');

$httpResponse = ['response' => ['code' => 503], 'body' => ''];
$webhook->deliver($queuedEvent);
assert_true(count($scheduledActions) === 1, 'Failed delivery must schedule a retry.');
$retryEvent = $scheduledActions[0]['args'][0] ?? [];
assert_true(($retryEvent['attempt'] ?? null) === 2, 'First failure must schedule attempt two.');
assert_true(($retryEvent['event_id'] ?? null) === $queuedEvent['event_id'], 'Retry must preserve the idempotency event ID.');
assert_true(($retryEvent['occurred_at'] ?? null) === $queuedEvent['occurred_at'], 'Retry must preserve original occurrence time.');
$retryDelay = $scheduledActions[0]['timestamp'] - time();
assert_true($retryDelay >= 9 && $retryDelay <= 10, 'First retry must be scheduled after approximately ten seconds.');

fwrite(STDOUT, "Lemon ERP customer webhook tests passed.\n");
