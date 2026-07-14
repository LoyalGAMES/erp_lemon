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

final class WP_REST_Server
{
    public const READABLE = 'GET';

    public const CREATABLE = 'POST';
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

/** @var list<array{hook:string,callback:callable}> */
$testActions = [];
/** @var list<array{namespace:string,route:string,args:array<string,mixed>}> */
$testRoutes = [];
/** @var list<string> */
$testLanguages = ['pl', 'en'];
/** @var array<int, string> */
$testPostTypes = [
    101 => 'product',
    102 => 'product',
    103 => 'product_variation',
    104 => 'product',
];
/** @var array<int, string> */
$testPostStatuses = [
    101 => 'publish',
    102 => 'draft',
    103 => 'publish',
    104 => 'publish',
];
/** @var array<int, bool> */
$testEditablePosts = [101 => true, 102 => true, 103 => true, 104 => true];
/** @var array<int, string> */
$testPostLanguages = [];
/** @var array<int, array<string, int>> */
$testTranslationGroups = [];
/** @var list<array<mixed>> */
$testWrites = [];
$testManageWooCommerce = true;

function add_action(string $hook, callable $callback): void
{
    global $testActions;

    $testActions[] = compact('hook', 'callback');
}

/** @param array<string, mixed> $args */
function register_rest_route(string $namespace, string $route, array $args): void
{
    global $testRoutes;

    $testRoutes[] = compact('namespace', 'route', 'args');
}

function current_user_can(string $capability, mixed ...$args): bool
{
    global $testEditablePosts, $testManageWooCommerce;

    if ($capability === 'manage_woocommerce') {
        return $testManageWooCommerce;
    }

    if ($capability === 'edit_post') {
        return $testEditablePosts[(int) ($args[0] ?? 0)] ?? false;
    }

    return false;
}

function sanitize_key(string $key): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $key));
}

function __(string $message, string $domain): string
{
    return $message;
}

function get_post_type(int $postId): string|false
{
    global $testPostTypes;

    return $testPostTypes[$postId] ?? false;
}

function get_post_status(int $postId): string|false
{
    global $testPostStatuses;

    return $testPostStatuses[$postId] ?? false;
}

/** @return list<string> */
function pll_languages_list(array $args = []): array
{
    global $testLanguages;

    return $testLanguages;
}

function pll_get_post_language(int $postId, string $field = 'slug'): string|false
{
    global $testPostLanguages;

    return $testPostLanguages[$postId] ?? false;
}

/** @return array<string, int> */
function pll_get_post_translations(int $postId): array
{
    global $testTranslationGroups;

    return $testTranslationGroups[$postId] ?? [];
}

function pll_set_post_language(int $postId, string $language): void
{
    global $testPostLanguages, $testWrites;

    $testWrites[] = ['set_language', $postId, $language];
    $testPostLanguages[$postId] = $language;
}

/** @param array<string, int> $translations */
function pll_save_post_translations(array $translations): void
{
    global $testTranslationGroups, $testWrites;

    $testWrites[] = ['save_translations', $translations];

    foreach ($translations as $postId) {
        $testTranslationGroups[$postId] = $translations;
    }
}

function test_expect(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function test_expect_error(mixed $result, string $code, int $status): void
{
    test_expect($result instanceof WP_Error, "Expected WP_Error {$code}.");
    test_expect($result->get_error_code() === $code, "Expected error {$code}, received {$result->get_error_code()}.");
    test_expect(($result->get_error_data()['status'] ?? null) === $status, "Expected HTTP {$status} for {$code}.");
}

require_once dirname(__DIR__).'/wordpress/lemon-erp-woocommerce/includes/class-product-translation-linker.php';

$linker = new Lemon_Erp_Product_Translation_Linker;
$linker->hooks();

test_expect(count($testActions) === 1, 'The REST registration hook was not added exactly once.');
test_expect($testActions[0]['hook'] === 'rest_api_init', 'The endpoint must register during rest_api_init.');
($testActions[0]['callback'])();

test_expect(count($testRoutes) === 2, 'The product translation routes were not registered exactly once.');
test_expect($testRoutes[0]['namespace'] === 'wc-lemon-erp/v1', 'The capability endpoint must use the Woo-authenticated wc-lemon-erp/v1 namespace.');
test_expect($testRoutes[0]['route'] === '/catalog/products/translations/capabilities', 'Unexpected product translation capability endpoint path.');
test_expect($testRoutes[0]['args']['methods'] === WP_REST_Server::READABLE, 'The capability endpoint must accept GET requests.');
test_expect($testRoutes[1]['namespace'] === 'wc-lemon-erp/v1', 'The link endpoint must use the Woo-authenticated wc-lemon-erp/v1 namespace.');
test_expect($testRoutes[1]['route'] === '/catalog/products/translations', 'Unexpected product translation endpoint path.');
test_expect($testRoutes[1]['args']['methods'] === WP_REST_Server::CREATABLE, 'The link endpoint must accept POST requests.');
test_expect($linker->canLink(new WP_REST_Request), 'A WooCommerce manager should be allowed to link translations.');

$capabilities = $linker->capabilities(new WP_REST_Request);
test_expect($capabilities->status === 200, 'The capability endpoint must return HTTP 200.');
test_expect($capabilities->data['available'] === true, 'The capability endpoint did not confirm Polylang readiness.');
test_expect($capabilities->data['plugin_version'] === '0.5.0', 'The capability endpoint returned an unexpected plugin version.');
test_expect($capabilities->data['languages'] === ['pl', 'en'], 'The capability endpoint returned unexpected languages.');

$testManageWooCommerce = false;
test_expect(! $linker->canLink(new WP_REST_Request), 'A user without manage_woocommerce must be rejected.');
$testManageWooCommerce = true;

// A later invalid product must abort the entire request before the first write.
$invalidPost = $linker->link(new WP_REST_Request([
    'translations' => ['en' => 102, 'pl' => 103],
]));
test_expect_error($invalidPost, 'lemon_erp_product_translation_post_invalid', 422);
test_expect($testWrites === [], 'Validation failure mutated Polylang state.');

// Non-scalar IDs are rejected without notices, coercion or writes.
$invalidId = $linker->link(new WP_REST_Request([
    'translations' => ['en' => 102, 'pl' => true],
]));
test_expect_error($invalidId, 'lemon_erp_product_translation_id_invalid', 422);
test_expect($testWrites === [], 'Invalid ID validation mutated Polylang state.');

// Existing families cannot silently lose a third, unrelated translation.
$testTranslationGroups[101] = ['en' => 104, 'pl' => 101];
$testTranslationGroups[104] = ['en' => 104, 'pl' => 101];
$conflict = $linker->link(new WP_REST_Request([
    'translations' => ['pl' => 101, 'en' => 102],
]));
test_expect_error($conflict, 'lemon_erp_product_translation_conflict', 409);
test_expect($testWrites === [], 'Existing-family conflict mutated Polylang state.');

$testTranslationGroups = [];
$linked = $linker->link(new WP_REST_Request([
    'translations' => ['pl' => 101, 'en' => '102'],
]));

test_expect($linked instanceof WP_REST_Response, 'A valid request did not return a REST response.');
test_expect($linked->status === 200, 'A valid request did not return HTTP 200.');
test_expect($linked->data['linked'] === true, 'A valid request was not confirmed as linked.');
test_expect($linked->data['changed'] === true, 'The first valid request must report a change.');
test_expect($linked->data['translations'] === ['en' => 102, 'pl' => 101], 'The response did not contain the normalized translation map.');
test_expect($linked->data['translation_group'] === 'product:101|102', 'The response contains an unstable translation group.');
test_expect(count($testWrites) === 3, 'The first link must assign two languages and save one translation family.');

$writesAfterFirstLink = $testWrites;
$repeated = $linker->link(new WP_REST_Request([
    'translations' => ['en' => 102, 'pl' => 101],
]));

test_expect($repeated instanceof WP_REST_Response, 'The repeated request did not return a REST response.');
test_expect($repeated->data['changed'] === false, 'The repeated request must report no change.');
test_expect($testWrites === $writesAfterFirstLink, 'The repeated request performed redundant Polylang writes.');

fwrite(STDOUT, "product translation linker tests passed\n");
