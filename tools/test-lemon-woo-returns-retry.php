<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);

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

/** @var array<int, array<string, mixed>> $meta */
$meta = [];

function get_post_meta(int $postId, string $key, bool $single = false): mixed
{
    return $GLOBALS['meta'][$postId][$key] ?? '';
}

function update_post_meta(int $postId, string $key, mixed $value): void
{
    $GLOBALS['meta'][$postId][$key] = $value;
}

function delete_post_meta(int $postId, string $key): void
{
    unset($GLOBALS['meta'][$postId][$key]);
}

function sanitize_key(mixed $value): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
}

function sanitize_text_field(mixed $value): string
{
    return trim((string) $value);
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function current_time(string $type): string
{
    return '2026-07-13 12:00:00';
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function __(string $value, string $domain = ''): string
{
    return $value;
}

function add_filter(...$args): void {}
function add_action(...$args): void {}
function wp_next_scheduled(...$args): bool { return false; }
function wp_schedule_event(...$args): void {}
function wp_clear_scheduled_hook(...$args): void {}

require __DIR__.'/../wordpress/lemon-woo-returns/includes/class-return-repository.php';

final class LL_Returns_Settings
{
    public function map_erp_status_to_internal_status(string $status): string
    {
        return $status === 'completed' ? 'completed' : 'pending_package';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

class LL_Returns_ERP_Client
{
    /** @var list<int> */
    public array $created = [];

    /** @var list<int> */
    public array $statusChecked = [];

    public mixed $createResponse = ['success' => true, 'external_id' => 'RET/1', 'status' => 'pending_package'];

    public function has_return_endpoint(): bool
    {
        return true;
    }

    public function has_status_endpoint(): bool
    {
        return true;
    }

    public function create_return(array $payload): mixed
    {
        $this->created[] = (int) $payload['id'];

        return $this->createResponse;
    }

    public function get_return_status(array $payload, array $context): array
    {
        $this->statusChecked[] = (int) $payload['id'];

        return ['status' => 'pending_package'];
    }
}

class LL_Returns_Refund_Service
{
    public function create_refund_for_request(int $requestId): true
    {
        return true;
    }
}

final class Test_Return_Repository extends LL_Returns_Return_Repository
{
    /** @var array<int, bool> */
    public array $needs = [1 => true, 2 => false];

    /** @var list<int> */
    public array $accepted = [];

    /** @var list<int> */
    public array $failed = [];

    public function get_syncable_request_ids($limit = 25)
    {
        return [1, 2];
    }

    public function get_payload($post_id)
    {
        return ['id' => (int) $post_id, 'return_reference' => 'LLR-'.$post_id];
    }

    public function needs_erp_submission($post_id)
    {
        return $this->needs[(int) $post_id];
    }

    public function mark_accepted($post_id, array $erp_response)
    {
        $this->accepted[] = (int) $post_id;
        $this->needs[(int) $post_id] = false;
    }

    public function mark_failed($post_id, WP_Error $error)
    {
        $this->failed[] = (int) $post_id;
    }

    public function get_erp_context($post_id)
    {
        return ['external_id' => 'RET/'.$post_id, 'response' => []];
    }

    public function record_status_sync_error($post_id, WP_Error $error): void {}

    public function update_status_from_erp($post_id, $status, $raw_status, array $erp_context = []): void {}

    public function record_refund_error($post_id, WP_Error $error): void {}
}

require __DIR__.'/../wordpress/lemon-woo-returns/includes/class-status-sync.php';

function assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$prefix = LL_Returns_Return_Repository::META_PREFIX;
$meta[10] = [
    $prefix.'status' => 'pending_package',
    $prefix.'erp_mode' => 'local',
    $prefix.'erp_external_id' => '',
    $prefix.'erp_response' => ['mode' => 'local'],
];
$meta[11] = [
    $prefix.'status' => 'erp_failed',
    $prefix.'erp_mode' => '',
    $prefix.'erp_external_id' => '',
];
$meta[12] = [
    $prefix.'status' => 'pending_package',
    $prefix.'erp_mode' => 'remote',
    $prefix.'erp_external_id' => 'RET/12',
];

$realRepository = new LL_Returns_Return_Repository();
assert_true($realRepository->needs_erp_submission(10), 'Local-mode request must be replayed to create endpoint.');
assert_true($realRepository->needs_erp_submission(11), 'Failed request must be replayed to create endpoint.');
assert_true(! $realRepository->needs_erp_submission(12), 'Delivered request must only use status synchronization.');

$repository = new Test_Return_Repository();
$client = new LL_Returns_ERP_Client();
$sync = new LL_Returns_Status_Sync(
    new LL_Returns_Settings(),
    $repository,
    $client,
    new LL_Returns_Refund_Service(),
);

assert_true($sync->sync_pending_requests(25) === 2, 'Both create delivery and status sync should be counted.');
assert_true($client->created === [1], 'Queued request must call create endpoint exactly once.');
assert_true($client->statusChecked === [2], 'Already delivered request must call status endpoint.');
assert_true($repository->accepted === [1], 'Successful create delivery must mark the request as accepted.');

$failedRepository = new Test_Return_Repository();
$failedRepository->needs = [1 => true, 2 => true];
$failedClient = new LL_Returns_ERP_Client();
$failedClient->createResponse = new WP_Error('http_403', 'Token missing');
$failedSync = new LL_Returns_Status_Sync(
    new LL_Returns_Settings(),
    $failedRepository,
    $failedClient,
    new LL_Returns_Refund_Service(),
);

assert_true($failedSync->sync_pending_requests(25) === 0, 'Failed creates must not be reported as synchronized.');
assert_true($failedRepository->failed === [1, 2], 'Every failed create must remain queued as ERP failed.');
assert_true($failedClient->statusChecked === [], 'Status endpoint must not be called before create succeeds.');

fwrite(STDOUT, "Lemon Woo Returns retry tests passed.\n");
