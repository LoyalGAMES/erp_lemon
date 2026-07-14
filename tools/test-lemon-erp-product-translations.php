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

final class WP_Term
{
    public function __construct(
        public readonly int $term_id,
        public readonly string $taxonomy,
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
$testManageProductTerms = true;
/** @var array<int, WP_Term> */
$testTerms = [
    201 => new WP_Term(201, 'pa_rozmiar'),
    202 => new WP_Term(202, 'pa_rozmiar'),
    203 => new WP_Term(203, 'pa_rozmiar'),
    204 => new WP_Term(204, 'pa_rozmiar'),
    299 => new WP_Term(299, 'product_cat'),
];
/** @var array<int, bool> */
$testEditableTerms = [201 => true, 202 => true, 203 => true, 204 => true, 299 => true];
/** @var array<int, string> */
$testTermLanguages = [];
/** @var array<int, array<string, int>> */
$testTermTranslationGroups = [];
$testPersistTermTranslations = true;

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
    global $testEditablePosts, $testEditableTerms, $testManageProductTerms, $testManageWooCommerce;

    if ($capability === 'manage_woocommerce') {
        return $testManageWooCommerce;
    }

    if ($capability === 'edit_post') {
        return $testEditablePosts[(int) ($args[0] ?? 0)] ?? false;
    }

    if ($capability === 'manage_product_terms') {
        return $testManageProductTerms;
    }

    if ($capability === 'edit_term') {
        return $testEditableTerms[(int) ($args[0] ?? 0)] ?? false;
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

function wc_attribute_taxonomy_name_by_id(int $attributeId): string
{
    return $attributeId === 9 ? 'pa_rozmiar' : '';
}

function taxonomy_exists(string $taxonomy): bool
{
    return in_array($taxonomy, ['pa_rozmiar', 'product_cat'], true);
}

function pll_is_translated_taxonomy(string $taxonomy): bool
{
    return $taxonomy === 'pa_rozmiar';
}

function get_term(int $termId, string $taxonomy): WP_Term|false
{
    global $testTerms;

    $term = $testTerms[$termId] ?? null;

    return $term instanceof WP_Term && $term->taxonomy === $taxonomy ? $term : false;
}

function pll_get_term_language(int $termId, string $field = 'slug'): string|false
{
    global $testTermLanguages;

    return $testTermLanguages[$termId] ?? false;
}

/** @return array<string, int> */
function pll_get_term_translations(int $termId): array
{
    global $testTermTranslationGroups;

    return $testTermTranslationGroups[$termId] ?? [];
}

function pll_set_term_language(int $termId, string $language): void
{
    global $testTermLanguages, $testWrites;

    $testWrites[] = ['set_term_language', $termId, $language];
    $testTermLanguages[$termId] = $language;
}

/** @param array<string, int> $translations */
function pll_save_term_translations(array $translations): void
{
    global $testPersistTermTranslations, $testTermTranslationGroups, $testWrites;

    $testWrites[] = ['save_term_translations', $translations];

    if (! $testPersistTermTranslations) {
        return;
    }

    foreach ($translations as $termId) {
        $testTermTranslationGroups[$termId] = $translations;
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

test_expect(count($testRoutes) === 3, 'The product and attribute-term translation routes were not registered exactly once.');
test_expect($testRoutes[0]['namespace'] === 'wc-lemon-erp/v1', 'The capability endpoint must use the Woo-authenticated wc-lemon-erp/v1 namespace.');
test_expect($testRoutes[0]['route'] === '/catalog/products/translations/capabilities', 'Unexpected product translation capability endpoint path.');
test_expect($testRoutes[0]['args']['methods'] === WP_REST_Server::READABLE, 'The capability endpoint must accept GET requests.');
test_expect($testRoutes[1]['namespace'] === 'wc-lemon-erp/v1', 'The link endpoint must use the Woo-authenticated wc-lemon-erp/v1 namespace.');
test_expect($testRoutes[1]['route'] === '/catalog/products/translations', 'Unexpected product translation endpoint path.');
test_expect($testRoutes[1]['args']['methods'] === WP_REST_Server::CREATABLE, 'The link endpoint must accept POST requests.');
test_expect($testRoutes[2]['namespace'] === 'wc-lemon-erp/v1', 'The attribute-term endpoint must use Woo credentials.');
test_expect($testRoutes[2]['route'] === '/catalog/products/attributes/(?P<attribute_id>\d+)/terms/translations', 'Unexpected attribute-term translation endpoint path.');
test_expect($testRoutes[2]['args']['methods'] === WP_REST_Server::CREATABLE, 'The attribute-term endpoint must accept POST requests.');
test_expect($linker->canLink(new WP_REST_Request), 'A WooCommerce manager should be allowed to link translations.');
test_expect($linker->canLinkAttributeTerms(new WP_REST_Request), 'A WooCommerce term manager should be allowed to link attribute terms.');

$capabilities = $linker->capabilities(new WP_REST_Request);
test_expect($capabilities->status === 200, 'The capability endpoint must return HTTP 200.');
test_expect($capabilities->data['available'] === true, 'The capability endpoint did not confirm Polylang readiness.');
test_expect($capabilities->data['attribute_term_translation_link_available'] === true, 'The capability endpoint did not confirm attribute term readiness.');
test_expect($capabilities->data['plugin_version'] === '0.5.1', 'The capability endpoint returned an unexpected plugin version.');
test_expect($capabilities->data['languages'] === ['pl', 'en'], 'The capability endpoint returned unexpected languages.');

$testManageWooCommerce = false;
test_expect(! $linker->canLink(new WP_REST_Request), 'A user without manage_woocommerce must be rejected.');
$testManageWooCommerce = true;
$testManageProductTerms = false;
test_expect(! $linker->canLinkAttributeTerms(new WP_REST_Request), 'A user without manage_product_terms must be rejected.');
$termCapabilities = $linker->capabilities(new WP_REST_Request);
test_expect($termCapabilities->data['attribute_term_translation_link_available'] === false, 'Capabilities must not report attribute term readiness without manage_product_terms.');
$testManageProductTerms = true;

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

// Distinct raw keys that normalize to one language must never overwrite one another.
$duplicateLanguage = $linker->link(new WP_REST_Request([
    'translations' => ['PL' => 101, 'pl' => 102],
]));
test_expect_error($duplicateLanguage, 'lemon_erp_product_translation_language_duplicate', 422);
test_expect($testWrites === [], 'Duplicate normalized product languages mutated Polylang state.');

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

// Attribute term linking validates the complete existing family before writes.
$testWrites = [];
$duplicateTermLanguage = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['EN' => 201, 'en' => 202],
]));
test_expect_error($duplicateTermLanguage, 'lemon_erp_attribute_term_translation_language_duplicate', 422);
test_expect($testWrites === [], 'Duplicate normalized term languages mutated Polylang state.');

$foreignTaxonomy = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['pl' => 201, 'en' => 299],
]));
test_expect_error($foreignTaxonomy, 'lemon_erp_attribute_term_invalid', 422);
test_expect($testWrites === [], 'Foreign-taxonomy validation mutated Polylang state.');

$testTermTranslationGroups[201] = ['de' => 203, 'pl' => 201];
$testTermTranslationGroups[203] = ['de' => 203, 'pl' => 201];
$termConflict = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['pl' => 201, 'en' => 202],
]));
test_expect_error($termConflict, 'lemon_erp_attribute_term_translation_conflict', 409);
test_expect($testWrites === [], 'Attribute-term conflict mutated Polylang state.');

$testTermTranslationGroups = [];
$linkedTerms = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['pl' => 201, 'en' => '202'],
]));
test_expect($linkedTerms instanceof WP_REST_Response, 'Valid attribute terms did not return a REST response.');
test_expect($linkedTerms->status === 200, 'Valid attribute terms did not return HTTP 200.');
test_expect($linkedTerms->data['linked'] === true, 'Valid attribute terms were not confirmed as linked.');
test_expect($linkedTerms->data['changed'] === true, 'The first attribute-term link must report a change.');
test_expect($linkedTerms->data['attribute_id'] === 9, 'The attribute-term response lost the Woo attribute ID.');
test_expect($linkedTerms->data['taxonomy'] === 'pa_rozmiar', 'The endpoint resolved an incorrect Woo taxonomy.');
test_expect($linkedTerms->data['translations'] === ['en' => 202, 'pl' => 201], 'The endpoint returned an incorrect term map.');
test_expect(count($testWrites) === 3, 'The first term link must assign two languages and save one family.');

$writesAfterFirstTermLink = $testWrites;
$repeatedTerms = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['en' => 202, 'pl' => 201],
]));
test_expect($repeatedTerms instanceof WP_REST_Response, 'Repeated attribute-term link did not return a response.');
test_expect($repeatedTerms->data['changed'] === false, 'Repeated attribute-term link must be idempotent.');
test_expect($testWrites === $writesAfterFirstTermLink, 'Repeated attribute-term link performed redundant writes.');

// An incomplete Polylang save must be reported instead of acknowledged.
$testPersistTermTranslations = false;
$verificationFailure = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['pl' => 203, 'en' => 204],
]));
test_expect_error($verificationFailure, 'lemon_erp_attribute_term_translation_verification_failed', 500);

fwrite(STDOUT, "product translation linker tests passed\n");
