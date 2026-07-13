<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

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

class LL_Returns_Settings {}

class LL_Returns_Order_Service {}

class LL_Returns_Return_Repository {}

class LL_Returns_ERP_Client {}

class LL_Returns_Status_Sync {}

/** @var array<string, mixed> $options */
$options = [];
$uuidCounter = 0;
$beforeConditionalDelete = null;

final class FakeWpdb
{
    public string $options = 'wp_options';

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where, array $whereFormat = []): int|false
    {
        $key = (string) ($where['option_name'] ?? '');

        if (is_callable($GLOBALS['beforeConditionalDelete'])) {
            $callback = $GLOBALS['beforeConditionalDelete'];
            $GLOBALS['beforeConditionalDelete'] = null;
            $callback($key);
        }

        if (
            $table !== $this->options
            || ! array_key_exists($key, $GLOBALS['options'])
            || maybe_serialize($GLOBALS['options'][$key]) !== ($where['option_value'] ?? null)
        ) {
            return 0;
        }

        unset($GLOBALS['options'][$key]);

        return 1;
    }
}

$wpdb = new FakeWpdb;

function absint(mixed $value): int
{
    return abs((int) $value);
}

function __(string $value, string $domain = ''): string
{
    return $value;
}

function wp_generate_uuid4(): string
{
    $GLOBALS['uuidCounter']++;

    return 'owner-'.$GLOBALS['uuidCounter'];
}

function add_option(string $key, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
{
    if (array_key_exists($key, $GLOBALS['options'])) {
        return false;
    }

    $GLOBALS['options'][$key] = $value;

    return true;
}

function get_option(string $key, mixed $default = false): mixed
{
    return $GLOBALS['options'][$key] ?? $default;
}

function delete_option(string $key): bool
{
    if (! array_key_exists($key, $GLOBALS['options'])) {
        return false;
    }

    unset($GLOBALS['options'][$key]);

    return true;
}

function maybe_serialize(mixed $value): mixed
{
    return is_array($value) || is_object($value) ? serialize($value) : $value;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    return true;
}

require __DIR__.'/../wordpress/lemon-woo-returns/includes/class-ajax.php';

function assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(LL_Returns_Ajax::class);
$ajax = $reflection->newInstanceWithoutConstructor();
$retryable = $reflection->getMethod('is_retryable_erp_error');
$retryable->setAccessible(true);

assert_true(! $retryable->invoke($ajax, new WP_Error('ll_returns_erp_http_error', 'Conflict', ['status' => 409])), 'HTTP 409 must be permanent.');
assert_true(! $retryable->invoke($ajax, new WP_Error('ll_returns_erp_http_error', 'Invalid', ['status' => 422])), 'HTTP 422 must be permanent.');
assert_true(! $retryable->invoke($ajax, new WP_Error('ll_returns_erp_rejected', 'Rejected')), 'Explicit ERP rejection must be permanent.');
assert_true($retryable->invoke($ajax, new WP_Error('ll_returns_erp_http_error', 'Server error', ['status' => 500])), 'HTTP 500 must remain retryable.');
assert_true($retryable->invoke($ajax, new WP_Error('http_request_failed', 'Timeout')), 'Transport failure must remain retryable.');
assert_true($retryable->invoke($ajax, new WP_Error('ll_returns_erp_http_error', 'Unauthorized', ['status' => 403])), 'Authentication failure must remain retryable after configuration is fixed.');

$acquire = $reflection->getMethod('acquire_order_lock');
$acquire->setAccessible(true);
$release = $reflection->getMethod('release_order_lock');
$release->setAccessible(true);
$firstLock = $acquire->invoke($ajax, 'erp:1:9001');
assert_true(is_array($firstLock), 'First canonical order lock must be acquired.');

$options[$firstLock['option']] = ['owner' => 'new-owner', 'expires' => time() + 45];
$release->invoke($ajax, $firstLock);
assert_true(isset($options[$firstLock['option']]), 'Expired lock owner must not delete a newer owner lock.');

$options[$firstLock['option']] = ['owner' => 'expired-owner', 'expires' => time() - 1];
$beforeConditionalDelete = static function (string $option): void {
    $GLOBALS['options'][$option] = ['owner' => 'racing-owner', 'expires' => time() + 45];
};
$racingLock = $acquire->invoke($ajax, 'erp:1:9001');
assert_true($racingLock instanceof WP_Error, 'Stale takeover must not delete a lock acquired by a concurrent request.');
assert_true($options[$firstLock['option']]['owner'] === 'racing-owner', 'Concurrent lock owner must remain intact.');

$options[$firstLock['option']] = ['owner' => 'expired-owner', 'expires' => time() - 1];
$replacementLock = $acquire->invoke($ajax, 'erp:1:9001');
assert_true(is_array($replacementLock) && $replacementLock['owner'] !== 'expired-owner', 'Expired lock must be replaced with a new owner token.');
$release->invoke($ajax, $replacementLock);
assert_true(! isset($options[$firstLock['option']]), 'Current lock owner must release its own lock.');

fwrite(STDOUT, "Lemon Woo Returns AJAX guard tests passed.\n");
