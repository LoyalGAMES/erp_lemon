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
        public readonly string $slug = '',
    ) {}
}

final class TestWpdb
{
    public string $posts = 'wp_posts';

    public string $wc_product_meta_lookup = 'wp_wc_product_meta_lookup';

    /** @var array<int, mixed> */
    private array $preparedArguments = [];

    public function prepare(string $query, mixed ...$arguments): string
    {
        $this->preparedArguments = $arguments;

        return $query;
    }

    /** @return list<int> */
    public function get_col(string $query): array
    {
        global $testProductIdsByGlobalUniqueId, $testProductIdsBySku;

        $identity = (string) ($this->preparedArguments[0] ?? '');
        $excludedId = (int) ($this->preparedArguments[1] ?? 0);
        $ids = str_contains($query, 'lookup.global_unique_id')
            ? ($testProductIdsByGlobalUniqueId[$identity] ?? [])
            : ($testProductIdsBySku[$identity] ?? []);

        if (! str_contains($query, 'lookup.product_id <> %d')) {
            return $ids;
        }

        return array_values(array_filter(
            $ids,
            static fn (int $productId): bool => $productId !== $excludedId,
        ));
    }
}

/** @var list<array{hook:string,callback:callable,priority:int,accepted_args:int}> */
$testActions = [];
/** @var list<array{hook:string,callback:callable,priority:int,accepted_args:int}> */
$testFilters = [];
/** @var list<array{namespace:string,route:string,args:array<string,mixed>}> */
$testRoutes = [];
/** @var list<string> */
$testAttributeTaxonomies = ['pa_rozmiar', 'pa_kolor', 'pa_oficjalny-producent'];
/** @var list<string> */
$testTranslatedTaxonomies = ['pa_rozmiar', 'pa_kolor', 'pa_oficjalny-producent'];
/** @var list<string> */
$testLanguages = ['pl', 'en', 'de', 'pt', 'pt-br'];
$testDefaultLanguage = 'pl';
/** @var array<int, string> */
$testPostTypes = [
    101 => 'product',
    102 => 'product',
    103 => 'product_variation',
    104 => 'product',
    105 => 'product_variation',
    106 => 'product_variation',
    107 => 'product_variation',
    108 => 'product',
    109 => 'product_variation',
];
/** @var array<int, string> */
$testPostStatuses = [
    101 => 'publish',
    102 => 'draft',
    103 => 'publish',
    104 => 'publish',
    105 => 'publish',
    106 => 'publish',
    107 => 'publish',
    108 => 'publish',
    109 => 'publish',
];
/** @var array<int, bool> */
$testEditablePosts = [
    101 => true,
    102 => true,
    103 => true,
    104 => true,
    105 => true,
    106 => true,
    107 => true,
    108 => true,
    109 => true,
];
/** @var array<int, int> */
$testPostParents = [103 => 101, 105 => 101, 106 => 102, 107 => 104, 109 => 108];
/** @var array<int, string> */
$testPostLanguages = [];
/** @var array<int, array<string, int>> */
$testTranslationGroups = [];
$testPersistPostTranslations = true;
$testPartialPostTranslationSaveOnce = false;
$testThrowPostTranslationSaveOnce = false;
/** @var array<int, string> */
$testPostLanguageWriteOverridesOnce = [];
/** @var array<string, list<int>> */
$testProductIdsBySku = [];
/** @var array<string, list<int>> */
$testProductIdsByGlobalUniqueId = [];
/** @var list<array<mixed>> */
$testWrites = [];
$testManageWooCommerce = true;
$testManageProductTerms = true;
/** @var array<int, WP_Term> */
$testTerms = [
    201 => new WP_Term(201, 'pa_rozmiar', 's-pl'),
    202 => new WP_Term(202, 'pa_rozmiar', 's-en'),
    203 => new WP_Term(203, 'pa_rozmiar', 'm'),
    204 => new WP_Term(204, 'pa_rozmiar', 'm-en'),
    210 => new WP_Term(210, 'pa_oficjalny-producent', 'sempre'),
    211 => new WP_Term(211, 'pa_oficjalny-producent', 'sempre-en'),
    212 => new WP_Term(212, 'pa_oficjalny-producent', 'brand-pt-br'),
    213 => new WP_Term(213, 'pa_oficjalny-producent', 'brand-pt'),
    220 => new WP_Term(220, 'pa_kolor', 'camel'),
    221 => new WP_Term(221, 'pa_kolor', 'operator-choice-en'),
    299 => new WP_Term(299, 'product_cat'),
];
/** @var array<int, bool> */
$testEditableTerms = [
    201 => true,
    202 => true,
    203 => true,
    204 => true,
    210 => true,
    211 => true,
    212 => true,
    213 => true,
    220 => true,
    221 => true,
    299 => true,
];
/** @var array<int, string> */
$testTermLanguages = [221 => 'pl'];
/** @var array<int, array<string, int>> */
$testTermTranslationGroups = [];
$testPersistTermTranslations = true;
$testPartialTermTranslationSaveOnce = false;
/** @var array<int, string> */
$testTermLanguageWriteOverridesOnce = [];
$testGetTermsErrorTaxonomy = null;
/** @var array<string, mixed> */
$testOptions = [];
$wpdb = new TestWpdb;

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    global $testActions;

    $testActions[] = compact('hook', 'callback', 'priority', 'accepted_args');
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    global $testFilters;

    $testFilters[] = compact('hook', 'callback', 'priority', 'accepted_args');
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

function wp_get_post_parent_id(int $postId): int|false
{
    global $testPostParents;

    return $testPostParents[$postId] ?? false;
}

/** @return list<string> */
function pll_languages_list(array $args = []): array
{
    global $testLanguages;

    return $testLanguages;
}

function pll_default_language(string $field = 'slug'): string|false
{
    global $testDefaultLanguage;

    return $testDefaultLanguage;
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
    global $testPostLanguages, $testPostLanguageWriteOverridesOnce, $testWrites;

    $testWrites[] = ['set_language', $postId, $language];

    if (array_key_exists($postId, $testPostLanguageWriteOverridesOnce)) {
        $testPostLanguages[$postId] = $testPostLanguageWriteOverridesOnce[$postId];
        unset($testPostLanguageWriteOverridesOnce[$postId]);

        return;
    }

    $testPostLanguages[$postId] = $language;
}

/** @param array<string, int> $translations */
function pll_save_post_translations(array $translations): void
{
    global $testPartialPostTranslationSaveOnce, $testPersistPostTranslations;
    global $testThrowPostTranslationSaveOnce, $testTranslationGroups, $testWrites;

    $testWrites[] = ['save_translations', $translations];

    if ($testThrowPostTranslationSaveOnce) {
        $testThrowPostTranslationSaveOnce = false;

        throw new RuntimeException('Simulated post translation save failure.');
    }

    if (! $testPersistPostTranslations) {
        return;
    }

    $requestedPostIds = array_values($translations);
    $relatedPostIds = $requestedPostIds;

    foreach ($testTranslationGroups as $postId => $existingTranslations) {
        if (array_intersect($requestedPostIds, array_values($existingTranslations)) !== []) {
            $relatedPostIds[] = $postId;
            $relatedPostIds = array_merge($relatedPostIds, array_values($existingTranslations));
        }
    }

    foreach (array_unique($relatedPostIds) as $postId) {
        unset($testTranslationGroups[$postId]);
    }

    if ($testPartialPostTranslationSaveOnce) {
        $testPartialPostTranslationSaveOnce = false;
        $firstPostId = (int) reset($translations);

        if ($firstPostId > 0) {
            $testTranslationGroups[$firstPostId] = $translations;
        }

        return;
    }

    if (count($translations) < 2) {
        return;
    }

    foreach ($translations as $postId) {
        $testTranslationGroups[$postId] = $translations;
    }
}

function wc_attribute_taxonomy_name_by_id(int $attributeId): string
{
    return $attributeId === 9 ? 'pa_rozmiar' : '';
}

/** @return list<string> */
function wc_get_attribute_taxonomy_names(): array
{
    global $testAttributeTaxonomies;

    return $testAttributeTaxonomies;
}

function taxonomy_exists(string $taxonomy): bool
{
    global $testAttributeTaxonomies;

    return $taxonomy === 'product_cat' || in_array($taxonomy, $testAttributeTaxonomies, true);
}

function pll_is_translated_taxonomy(string $taxonomy): bool
{
    global $testTranslatedTaxonomies;

    return in_array($taxonomy, $testTranslatedTaxonomies, true);
}

/** @return list<WP_Term>|WP_Error */
function get_terms(array $args): array|WP_Error
{
    global $testGetTermsErrorTaxonomy, $testTerms;

    $taxonomy = (string) ($args['taxonomy'] ?? '');

    if ($testGetTermsErrorTaxonomy === $taxonomy) {
        return new WP_Error('test_get_terms_error', 'Simulated get_terms failure.');
    }

    return array_values(array_filter(
        $testTerms,
        static fn (WP_Term $term): bool => $term->taxonomy === $taxonomy,
    ));
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function get_option(string $name, mixed $default = false): mixed
{
    global $testOptions;

    return $testOptions[$name] ?? $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    global $testOptions;

    $changed = ! array_key_exists($name, $testOptions) || $testOptions[$name] !== $value;
    $testOptions[$name] = $value;

    return $changed;
}

function delete_option(string $name): bool
{
    global $testOptions;

    if (! array_key_exists($name, $testOptions)) {
        return false;
    }

    unset($testOptions[$name]);

    return true;
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
    global $testTermLanguages, $testTermLanguageWriteOverridesOnce, $testWrites;

    $testWrites[] = ['set_term_language', $termId, $language];

    if (array_key_exists($termId, $testTermLanguageWriteOverridesOnce)) {
        $testTermLanguages[$termId] = $testTermLanguageWriteOverridesOnce[$termId];
        unset($testTermLanguageWriteOverridesOnce[$termId]);

        return;
    }

    $testTermLanguages[$termId] = $language;
}

/** @param array<string, int> $translations */
function pll_save_term_translations(array $translations): void
{
    global $testPartialTermTranslationSaveOnce, $testPersistTermTranslations, $testTermTranslationGroups, $testWrites;

    $testWrites[] = ['save_term_translations', $translations];

    if (! $testPersistTermTranslations) {
        return;
    }

    $requestedTermIds = array_values($translations);
    $relatedTermIds = $requestedTermIds;

    foreach ($testTermTranslationGroups as $termId => $existingTranslations) {
        if (array_intersect($requestedTermIds, array_values($existingTranslations)) !== []) {
            $relatedTermIds[] = $termId;
            $relatedTermIds = array_merge($relatedTermIds, array_values($existingTranslations));
        }
    }

    foreach (array_unique($relatedTermIds) as $termId) {
        unset($testTermTranslationGroups[$termId]);
    }

    if ($testPartialTermTranslationSaveOnce) {
        $testPartialTermTranslationSaveOnce = false;
        $firstTermId = (int) reset($translations);

        if ($firstTermId > 0) {
            $testTermTranslationGroups[$firstTermId] = $translations;
        }

        return;
    }

    // Polylang uses a one-term map to detach an object and does not create a
    // translation group containing only that object.
    if (count($translations) < 2) {
        return;
    }

    foreach ($translations as $termId) {
        $testTermTranslationGroups[$termId] = $translations;
    }
}

function wp_delete_object_term_relationships(int $termId, string $taxonomy): bool
{
    global $testPostLanguages, $testTermLanguages, $testWrites;

    $testWrites[] = ['delete_object_term_relationships', $termId, $taxonomy];

    if ($taxonomy === 'term_language') {
        unset($testTermLanguages[$termId]);
    } elseif ($taxonomy === 'language') {
        unset($testPostLanguages[$termId]);
    }

    return true;
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

require_once dirname(__DIR__).'/wordpress/lemon-erp-woocommerce/includes/class-global-attribute-taxonomies.php';
require_once dirname(__DIR__).'/wordpress/lemon-erp-woocommerce/includes/class-product-translation-linker.php';

Lemon_Erp_Global_Attribute_Taxonomies::register();

test_expect(count($testFilters) === 1, 'The Polylang taxonomy filter was not added exactly once.');
test_expect($testFilters[0]['hook'] === 'pll_get_taxonomies', 'Global attributes must use the official Polylang taxonomy filter.');
test_expect($testFilters[0]['priority'] === 10, 'The Polylang taxonomy filter has an unexpected priority.');
test_expect($testFilters[0]['accepted_args'] === 2, 'The Polylang settings flag must be accepted by the taxonomy filter.');

$translatedTaxonomies = ($testFilters[0]['callback'])([
    'product_cat' => 'product_cat',
], false);
test_expect($translatedTaxonomies === [
    'product_cat' => 'product_cat',
    'pa_rozmiar' => 'pa_rozmiar',
    'pa_kolor' => 'pa_kolor',
    'pa_oficjalny-producent' => 'pa_oficjalny-producent',
], 'Runtime Polylang taxonomies do not contain every WooCommerce global attribute.');

$settingsTaxonomies = ($testFilters[0]['callback'])([
    'product_cat' => 'product_cat',
    'pa_rozmiar' => 'pa_rozmiar',
    'pa_kolor' => 'pa_kolor',
    'pa_oficjalny-producent' => 'pa_oficjalny-producent',
], true);
test_expect($settingsTaxonomies === [
    'product_cat' => 'product_cat',
], 'Forced WooCommerce attribute taxonomies remain editable in Polylang settings.');

$linker = new Lemon_Erp_Product_Translation_Linker;
$linker->hooks();

test_expect(count($testFilters) === 3, 'The taxonomy and WooCommerce identity filters were not registered exactly once.');
test_expect($testFilters[1]['hook'] === 'wc_product_has_unique_sku', 'The translated SKU exception uses an unexpected WooCommerce filter.');
test_expect($testFilters[1]['priority'] === 10 && $testFilters[1]['accepted_args'] === 3, 'The SKU duplicate filter must accept all three WooCommerce arguments.');
test_expect($testFilters[2]['hook'] === 'wc_product_has_global_unique_id', 'The translated GTIN/EAN exception uses an unexpected WooCommerce filter.');
test_expect($testFilters[2]['priority'] === 10 && $testFilters[2]['accepted_args'] === 3, 'The global unique ID duplicate filter must accept all three WooCommerce arguments.');
$skuDuplicateFilter = $testFilters[1]['callback'];
$globalUniqueIdDuplicateFilter = $testFilters[2]['callback'];

test_expect(count($testActions) === 4, 'The bootstrap, invalidation and REST hooks were not added exactly once.');
test_expect($testActions[0]['hook'] === 'init', 'Existing attribute terms must be bootstrapped after taxonomies initialize.');
test_expect($testActions[0]['priority'] === 100, 'The bootstrap must run after WooCommerce and Polylang initialize taxonomies.');
test_expect($testActions[1]['hook'] === 'created_term', 'New global attribute terms must invalidate the bootstrap marker.');
test_expect($testActions[2]['hook'] === 'edited_term', 'Edited global attribute terms must invalidate the bootstrap marker.');
test_expect($testActions[3]['hook'] === 'rest_api_init', 'The endpoint must register during rest_api_init.');

// Version 0.5.3 must not advertise term-link readiness before the bootstrap.
$capabilitiesBeforeBootstrap = $linker->capabilities(new WP_REST_Request);
test_expect(
    $capabilitiesBeforeBootstrap->data['attribute_term_translation_link_available'] === false,
    'Attribute-term readiness was advertised before the bootstrap marker existed.',
);
test_expect(
    $capabilitiesBeforeBootstrap->data['attribute_term_translation_bootstrap_completed'] === false,
    'The capability endpoint reported an uncompleted bootstrap as complete.',
);
test_expect(
    $capabilitiesBeforeBootstrap->data['attribute_term_translation_unassigned_terms_count'] === 9,
    'The capability endpoint did not report every unassigned legacy term.',
);

// A taxonomy that Polylang has not made translatable blocks the entire pass.
$testTranslatedTaxonomies = ['pa_rozmiar', 'pa_kolor'];
($testActions[0]['callback'])();
$oneTaxonomyNotReady = $linker->capabilities(new WP_REST_Request);
test_expect(
    $oneTaxonomyNotReady->data['attribute_term_translation_link_available'] === false,
    'Readiness ignored a global attribute taxonomy that is not translated.',
);
test_expect(
    $oneTaxonomyNotReady->data['attribute_term_translation_taxonomies_not_ready'] === ['pa_oficjalny-producent'],
    'The capability endpoint did not identify the untranslated global attribute taxonomy.',
);
test_expect($testWrites === [], 'The bootstrap mutated terms while a taxonomy was not ready.');

// An unassigned term that is already referenced by an existing Polylang
// translation family is ambiguous. The bootstrap must not mutate any term,
// must not write its completion marker, and must put that taxonomy on a
// storefront safety hold for subsequent requests.
$testTranslatedTaxonomies = $testAttributeTaxonomies;
$testTermTranslationGroups[221] = ['pl' => 221, 'en' => 220];
($testActions[0]['callback'])();
test_expect($testWrites === [], 'An existing translation-family conflict mutated terms.');
test_expect(
    ! array_key_exists(220, $testTermLanguages),
    'The bootstrap assigned a language to an unassigned term already referenced by a translation family.',
);
test_expect(
    ! array_key_exists('lemon_erp_global_attribute_term_language_bootstrap', $testOptions),
    'A translation-family conflict wrote the completion marker.',
);
test_expect(
    ($testOptions['lemon_erp_global_attribute_term_language_bootstrap_status']['state'] ?? null)
        === 'blocked_by_existing_translation_family',
    'The bootstrap did not report its translation-family safety stop.',
);
$heldRuntimeTaxonomies = ($testFilters[0]['callback'])([
    'product_cat' => 'product_cat',
    'pa_rozmiar' => 'pa_rozmiar',
    'pa_kolor' => 'pa_kolor',
    'pa_oficjalny-producent' => 'pa_oficjalny-producent',
], false);
test_expect(
    ! array_key_exists('pa_kolor', $heldRuntimeTaxonomies)
        && array_key_exists('pa_rozmiar', $heldRuntimeTaxonomies)
        && array_key_exists('pa_oficjalny-producent', $heldRuntimeTaxonomies),
    'The safety hold did not isolate exactly the ambiguous global attribute taxonomy.',
);
$testTermTranslationGroups = [];
($testActions[2]['callback'])(220, 220, 'pa_kolor');

// A get_terms failure must leave both the completion marker and all term
// languages untouched. The complete preflight happens before the first write.
$testGetTermsErrorTaxonomy = 'pa_rozmiar';
($testActions[0]['callback'])();
test_expect(
    ! array_key_exists('lemon_erp_global_attribute_term_language_bootstrap', $testOptions),
    'A partial bootstrap wrote its completion marker.',
);
test_expect(
    $testTermLanguages === [221 => 'pl'],
    'A failed complete preflight performed partial term-language writes.',
);
test_expect(
    count(array_filter($testWrites, static fn (array $write): bool => $write[0] === 'save_term_translations')) === 0,
    'The bootstrap guessed and linked translation families.',
);
$testGetTermsErrorTaxonomy = null;
($testActions[0]['callback'])();
$marker = $testOptions['lemon_erp_global_attribute_term_language_bootstrap'] ?? null;
test_expect(is_array($marker), 'The completed term bootstrap did not persist a marker.');
test_expect(
    ($marker['revision'] ?? null) === Lemon_Erp_Global_Attribute_Taxonomies::TERM_LANGUAGE_BOOTSTRAP_REVISION,
    'The term bootstrap persisted an unexpected revision.',
);
test_expect($testTermLanguages[210] === 'pl', 'The legacy SEMPRE base term did not inherit the default language.');
test_expect($testTermLanguages[211] === 'en', 'The deterministic SEMPRE -en term did not receive English.');
test_expect($testTermLanguages[212] === 'pt-br', 'The longest matching paired language suffix was not selected.');
test_expect($testTermLanguages[213] === 'pt', 'A paired non-PL/EN active language suffix was not selected.');
test_expect($testTermLanguages[220] === 'pl', 'An unsuffixed legacy term did not inherit the default language.');
test_expect(
    $testTermLanguages[221] === 'pl',
    'The bootstrap overwrote an existing operator-selected language based on its slug.',
);
test_expect($testTermLanguages[201] === 'pl', 'The deterministic -pl suffix was not respected.');
test_expect($testTermLanguages[202] === 'en', 'The deterministic -en suffix was not respected.');
test_expect($testTermLanguages[203] === 'pl', 'The default language was not applied to an unsuffixed term.');
test_expect($testTermLanguages[204] === 'en', 'The English suffix was not applied during resume.');

$capabilitiesAfterBootstrap = $linker->capabilities(new WP_REST_Request);
test_expect(
    $capabilitiesAfterBootstrap->data['attribute_term_translation_link_available'] === true,
    'The capability endpoint stayed blocked after a complete verified bootstrap.',
);
test_expect(
    $capabilitiesAfterBootstrap->data['attribute_term_translation_bootstrap_completed'] === true,
    'The capability endpoint did not expose the completed bootstrap.',
);
test_expect(
    $capabilitiesAfterBootstrap->data['attribute_term_translation_unassigned_terms_count'] === 0,
    'The completed bootstrap still reports unassigned terms.',
);

$writesAfterBootstrap = $testWrites;
($testActions[0]['callback'])();
test_expect($testWrites === $writesAfterBootstrap, 'A repeated bootstrap performed redundant term writes.');

// A later explicitly assigned term does not invalidate readiness. A later
// unassigned term does, and is picked up even though the marker/taxonomies did
// not change.
$testTerms[230] = new WP_Term(230, 'pa_kolor', 'assigned-en');
$testTermLanguages[230] = 'en';
($testActions[1]['callback'])(230, 230, 'pa_kolor');
($testActions[0]['callback'])();
test_expect(
    $linker->capabilities(new WP_REST_Request)->data['attribute_term_translation_link_available'] === true,
    'A newly created term with an explicit language unnecessarily blocked readiness.',
);
$testTerms[231] = new WP_Term(231, 'pa_kolor', 'new-en');
($testActions[1]['callback'])(231, 231, 'pa_kolor');
$newUnassignedTerm = $linker->capabilities(new WP_REST_Request);
test_expect(
    $newUnassignedTerm->data['attribute_term_translation_link_available'] === false,
    'A newly created unassigned term did not invalidate readiness.',
);
test_expect(
    $newUnassignedTerm->data['attribute_term_translation_unassigned_terms_count'] === 1,
    'The capability endpoint did not report the new unassigned term.',
);
($testActions[0]['callback'])();
test_expect(
    $testTermLanguages[231] === 'pl',
    'An unpaired natural slug ending in -en was incorrectly relabelled as English.',
);
test_expect(
    $linker->capabilities(new WP_REST_Request)->data['attribute_term_translation_link_available'] === true,
    'Readiness did not recover after assigning the new term.',
);

// Keep the REST contract tests isolated from bootstrap writes.
$testWrites = [];
($testActions[3]['callback'])();

test_expect(count($testRoutes) === 4, 'The product, variation and attribute-term translation routes were not registered exactly once.');
test_expect($testRoutes[0]['namespace'] === 'wc-lemon-erp/v1', 'The capability endpoint must use the Woo-authenticated wc-lemon-erp/v1 namespace.');
test_expect($testRoutes[0]['route'] === '/catalog/products/translations/capabilities', 'Unexpected product translation capability endpoint path.');
test_expect($testRoutes[0]['args']['methods'] === WP_REST_Server::READABLE, 'The capability endpoint must accept GET requests.');
test_expect($testRoutes[1]['namespace'] === 'wc-lemon-erp/v1', 'The link endpoint must use the Woo-authenticated wc-lemon-erp/v1 namespace.');
test_expect($testRoutes[1]['route'] === '/catalog/products/translations', 'Unexpected product translation endpoint path.');
test_expect($testRoutes[1]['args']['methods'] === WP_REST_Server::CREATABLE, 'The link endpoint must accept POST requests.');
test_expect($testRoutes[2]['namespace'] === 'wc-lemon-erp/v1', 'The variation endpoint must use Woo credentials.');
test_expect($testRoutes[2]['route'] === '/catalog/products/variations/translations', 'Unexpected variation translation endpoint path.');
test_expect($testRoutes[2]['args']['methods'] === WP_REST_Server::CREATABLE, 'The variation endpoint must accept POST requests.');
test_expect($testRoutes[3]['namespace'] === 'wc-lemon-erp/v1', 'The attribute-term endpoint must use Woo credentials.');
test_expect($testRoutes[3]['route'] === '/catalog/products/attributes/(?P<attribute_id>\d+)/terms/translations', 'Unexpected attribute-term translation endpoint path.');
test_expect($testRoutes[3]['args']['methods'] === WP_REST_Server::CREATABLE, 'The attribute-term endpoint must accept POST requests.');
test_expect($linker->canLink(new WP_REST_Request), 'A WooCommerce manager should be allowed to link translations.');
test_expect($linker->canLinkAttributeTerms(new WP_REST_Request), 'A WooCommerce term manager should be allowed to link attribute terms.');

$capabilities = $linker->capabilities(new WP_REST_Request);
test_expect($capabilities->status === 200, 'The capability endpoint must return HTTP 200.');
test_expect($capabilities->data['available'] === true, 'The capability endpoint did not confirm Polylang readiness.');
test_expect($capabilities->data['variation_translation_link_available'] === true, 'The capability endpoint did not confirm variation-link readiness.');
test_expect(
    $capabilities->data['variation_translation_link_endpoint'] === '/wp-json/wc-lemon-erp/v1/catalog/products/variations/translations',
    'The capability endpoint returned an unexpected variation-link endpoint.',
);
test_expect($capabilities->data['attribute_term_translation_link_available'] === true, 'The capability endpoint did not confirm attribute term readiness.');
test_expect($capabilities->data['plugin_version'] === '0.5.3', 'The capability endpoint returned an unexpected plugin version.');
test_expect($capabilities->data['languages'] === ['pl', 'en', 'de', 'pt', 'pt-br'], 'The capability endpoint returned unexpected languages.');

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

// WooCommerce reports true when it has found a duplicate SKU or global unique
// ID. Only the exact translated sibling may turn that conflict into false.
$testProductIdsBySku['translated-parent-sku'] = [101, 102];
$testProductIdsByGlobalUniqueId['5900000000102'] = [102];
test_expect($skuDuplicateFilter(true, 101, 'translated-parent-sku') === false, 'A translated product sibling did not share its SKU.');
test_expect($globalUniqueIdDuplicateFilter(true, 101, '5900000000102') === false, 'A translated product sibling did not share its global unique ID.');
test_expect($skuDuplicateFilter(false, 101, 'translated-parent-sku') === false, 'The SKU filter changed an already-unique WooCommerce result.');
$testProductIdsBySku["translated-parent's\\sku"] = [101, 102];
test_expect($skuDuplicateFilter(true, 101, "translated-parent's\\sku") === false, 'The SKU filter altered a raw identity before wpdb preparation.');

$testProductIdsBySku['unlinked-parent-sku'] = [104];
test_expect($skuDuplicateFilter(true, 101, 'unlinked-parent-sku') === true, 'An unlinked product duplicate was allowed.');

$testProductIdsBySku['translated-parent-with-foreign-sku'] = [101, 102, 104];
test_expect($skuDuplicateFilter(true, 101, 'translated-parent-with-foreign-sku') === true, 'A third foreign product duplicate was hidden by a valid sibling.');

$testPostLanguages[104] = 'en';
$testTranslationGroups[104] = ['en' => 104];
$testProductIdsByGlobalUniqueId['5900000000104'] = [104];
test_expect($globalUniqueIdDuplicateFilter(true, 101, '5900000000104') === true, 'A foreign product-family duplicate was allowed.');
unset($testPostLanguages[104], $testTranslationGroups[104]);

$testPostLanguages[103] = 'en';
$testTranslationGroups[101] = ['en' => 103, 'pl' => 101];
$testTranslationGroups[103] = ['en' => 103, 'pl' => 101];
$testProductIdsBySku['cross-type-sku'] = [103];
test_expect($skuDuplicateFilter(true, 101, 'cross-type-sku') === true, 'A product-to-variation duplicate was allowed.');
$testTranslationGroups[101] = ['en' => 102, 'pl' => 101];
$testTranslationGroups[102] = ['en' => 102, 'pl' => 101];
unset($testPostLanguages[103], $testTranslationGroups[103]);

// Variation linking is deliberately separate from product linking. Both maps
// must describe the same languages, and every validation failure must happen
// before the first Polylang write.
$testWrites = [];
$variationRequest = [
    'parents' => ['pl' => 101, 'en' => 102],
    'translations' => ['pl' => 105, 'en' => 106],
];

$mismatchedLanguages = $linker->linkVariations(new WP_REST_Request([
    'parents' => ['pl' => 101, 'en' => 102],
    'translations' => ['pl' => 105, 'pt' => 106],
]));
test_expect_error($mismatchedLanguages, 'lemon_erp_variation_translation_languages_mismatch', 422);
test_expect($testWrites === [], 'Mismatched variation languages mutated Polylang state.');

$inactiveLanguage = $linker->linkVariations(new WP_REST_Request([
    'parents' => ['fr' => 104, 'pl' => 101],
    'translations' => ['fr' => 107, 'pl' => 105],
]));
test_expect_error($inactiveLanguage, 'lemon_erp_variation_language_invalid', 422);
test_expect($testWrites === [], 'An inactive variation language mutated Polylang state.');

$wrongParent = $linker->linkVariations(new WP_REST_Request([
    'parents' => ['pl' => 101, 'en' => 102],
    'translations' => ['pl' => 106, 'en' => 105],
]));
test_expect_error($wrongParent, 'lemon_erp_variation_parent_mismatch', 422);
test_expect($testWrites === [], 'A wrong variation parent mutated Polylang state.');

$parentTranslationGroups = [
    101 => $testTranslationGroups[101],
    102 => $testTranslationGroups[102],
];
unset($testTranslationGroups[101], $testTranslationGroups[102]);
$unlinkedParents = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($unlinkedParents, 'lemon_erp_variation_parent_translations_mismatch', 409);
test_expect($testWrites === [], 'Unlinked parent products caused variation writes.');
$testTranslationGroups[101] = $parentTranslationGroups[101];
$testTranslationGroups[102] = $parentTranslationGroups[102];

$testTranslationGroups[105] = ['de' => 107, 'pl' => 105];
$testTranslationGroups[107] = ['de' => 107, 'pl' => 105];
$foreignVariationFamily = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($foreignVariationFamily, 'lemon_erp_variation_translation_conflict', 409);
test_expect($testWrites === [], 'A foreign variation family was overwritten.');
unset($testTranslationGroups[105], $testTranslationGroups[107]);

$testPostLanguages[105] = 'en';
$variationLanguageConflict = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($variationLanguageConflict, 'lemon_erp_variation_language_conflict', 409);
test_expect($testWrites === [], 'A conflicting variation language was overwritten.');
unset($testPostLanguages[105]);

$testEditablePosts[106] = false;
$forbiddenVariation = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($forbiddenVariation, 'lemon_erp_variation_translation_forbidden', 403);
test_expect($testWrites === [], 'A forbidden variation caused Polylang writes.');
$testEditablePosts[106] = true;

$testEditablePosts[102] = false;
$forbiddenParent = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($forbiddenParent, 'lemon_erp_variation_parent_forbidden', 403);
test_expect($testWrites === [], 'A forbidden parent product caused Polylang writes.');
$testEditablePosts[102] = true;

$linkedVariations = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect($linkedVariations instanceof WP_REST_Response, 'Valid variations did not return a REST response.');
test_expect($linkedVariations->status === 200, 'Valid variations did not return HTTP 200.');
test_expect($linkedVariations->data['linked'] === true, 'Valid variations were not confirmed as linked.');
test_expect($linkedVariations->data['changed'] === true, 'The first variation link must report a change.');
test_expect($linkedVariations->data['resource'] === 'product_variation', 'The variation response returned the wrong resource.');
test_expect($linkedVariations->data['parents'] === ['en' => 102, 'pl' => 101], 'The variation response lost the parent map.');
test_expect($linkedVariations->data['translations'] === ['en' => 106, 'pl' => 105], 'The variation response returned an incorrect translation map.');
test_expect($linkedVariations->data['translation_group'] === 'variation:105|106', 'The variation response contains an unstable translation group.');
test_expect($linkedVariations->data['plugin_version'] === '0.5.3', 'The variation response returned an unexpected plugin version.');
test_expect(count($testWrites) === 3, 'The first variation link must assign two languages and save one family.');
test_expect($testTranslationGroups[101] === ['en' => 102, 'pl' => 101] && $testTranslationGroups[102] === ['en' => 102, 'pl' => 101], 'A successful variation link modified the parent family.');

$writesAfterFirstVariationLink = $testWrites;
$repeatedVariations = $linker->linkVariations(new WP_REST_Request([
    'parents' => ['en' => 102, 'pl' => 101],
    'translations' => ['en' => 106, 'pl' => 105],
]));
test_expect($repeatedVariations instanceof WP_REST_Response, 'Repeated variation link did not return a response.');
test_expect($repeatedVariations->data['changed'] === false, 'Repeated variation link must be idempotent.');
test_expect($testWrites === $writesAfterFirstVariationLink, 'Repeated variation link performed redundant writes.');

$testProductIdsBySku['translated-variation-sku'] = [105, 106];
$testProductIdsByGlobalUniqueId['5900000000106'] = [106];
test_expect($skuDuplicateFilter(true, 105, 'translated-variation-sku') === false, 'Translated variation siblings did not share their SKU.');
test_expect($globalUniqueIdDuplicateFilter(true, 105, '5900000000106') === false, 'Translated variation siblings did not share their global unique ID.');

$testProductIdsBySku['unlinked-variation-sku'] = [103];
test_expect($skuDuplicateFilter(true, 105, 'unlinked-variation-sku') === true, 'An unlinked variation duplicate was allowed.');

$testProductIdsBySku['translated-variation-with-foreign-sku'] = [105, 106, 103];
test_expect($skuDuplicateFilter(true, 105, 'translated-variation-with-foreign-sku') === true, 'A third foreign variation duplicate was hidden by a valid sibling.');

// Even a directly linked variation pair is unsafe when its parent products are
// not translations of one another.
$validVariationFamily = ['en' => 106, 'pl' => 105];
$testPostLanguages[107] = 'en';
$testPostLanguages[104] = 'en';
$testTranslationGroups[105] = ['en' => 107, 'pl' => 105];
$testTranslationGroups[107] = ['en' => 107, 'pl' => 105];
$testProductIdsByGlobalUniqueId['5900000000107'] = [107];
test_expect($globalUniqueIdDuplicateFilter(true, 105, '5900000000107') === true, 'A variation duplicate under a foreign parent family was allowed.');
$testTranslationGroups[105] = $validVariationFamily;
$testTranslationGroups[106] = $validVariationFamily;
unset($testPostLanguages[107], $testPostLanguages[104], $testTranslationGroups[107]);

// If a later language assignment is not persisted, the earlier language write
// is compensated and the exact pre-request state is restored.
$testWrites = [];
unset($testTranslationGroups[105], $testTranslationGroups[106]);
$testPostLanguageWriteOverridesOnce[105] = 'en';
$variationLanguageVerificationFailure = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($variationLanguageVerificationFailure, 'lemon_erp_variation_language_verification_failed', 500);
test_expect(
    ($variationLanguageVerificationFailure->get_error_data()['compensated'] ?? false) === true,
    'A failed variation-language assignment was not compensated.',
);
test_expect($testPostLanguages[105] === 'pl' && $testPostLanguages[106] === 'en', 'Variation language compensation did not restore the snapshot.');
test_expect(($testTranslationGroups[105] ?? []) === [] && ($testTranslationGroups[106] ?? []) === [], 'Variation language compensation left a translation family.');
test_expect($testTranslationGroups[101] === ['en' => 102, 'pl' => 101] && $testTranslationGroups[102] === ['en' => 102, 'pl' => 101], 'Variation compensation modified the parent family.');

// A thrown family save is also compensated after both language assignments.
$testWrites = [];
$testThrowPostTranslationSaveOnce = true;
$variationSaveFailure = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($variationSaveFailure, 'lemon_erp_variation_translation_write_failed', 500);
test_expect(($variationSaveFailure->get_error_data()['compensated'] ?? false) === true, 'A thrown variation-family save was not compensated.');
test_expect($testPostLanguages[105] === 'pl' && $testPostLanguages[106] === 'en', 'Thrown-save compensation changed variation languages.');
test_expect(($testTranslationGroups[105] ?? []) === [] && ($testTranslationGroups[106] ?? []) === [], 'Thrown-save compensation left a translation family.');
test_expect($testTranslationGroups[101] === ['en' => 102, 'pl' => 101] && $testTranslationGroups[102] === ['en' => 102, 'pl' => 101], 'Thrown-save compensation modified the parent family.');

// A partial Polylang family write must never be acknowledged as success and is
// rolled back to the exact unlinked snapshot.
$testWrites = [];
$testPartialPostTranslationSaveOnce = true;
$variationVerificationFailure = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($variationVerificationFailure, 'lemon_erp_variation_translation_verification_failed', 500);
test_expect(($variationVerificationFailure->get_error_data()['compensated'] ?? false) === true, 'A partial variation-family write was not compensated.');
test_expect($testPostLanguages[105] === 'pl' && $testPostLanguages[106] === 'en', 'Partial-family compensation changed variation languages.');
test_expect(($testTranslationGroups[105] ?? []) === [] && ($testTranslationGroups[106] ?? []) === [], 'Partial-family compensation did not restore the unlinked snapshot.');
test_expect($testTranslationGroups[101] === ['en' => 102, 'pl' => 101] && $testTranslationGroups[102] === ['en' => 102, 'pl' => 101], 'Partial-family compensation modified the parent family.');

// Parent families may contain more languages than one incremental variation
// request. A PL/EN child pair must link against a PL/EN/DE parent family, and a
// later request may safely extend that exact child family with DE.
$fullParentFamily = ['de' => 108, 'en' => 102, 'pl' => 101];
$testPostLanguages[108] = 'de';
$testTranslationGroups[101] = $fullParentFamily;
$testTranslationGroups[102] = $fullParentFamily;
$testTranslationGroups[108] = $fullParentFamily;

$testWrites = [];
$linkedParentSubset = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect($linkedParentSubset instanceof WP_REST_Response, 'A PL/EN subset of a PL/EN/DE parent family did not link.');
test_expect($linkedParentSubset->data['changed'] === true, 'The incremental PL/EN variation family did not report a change.');
test_expect($linkedParentSubset->data['parents'] === ['en' => 102, 'pl' => 101], 'The subset response changed the requested parent map.');
test_expect($testTranslationGroups[105] === ['en' => 106, 'pl' => 105] && $testTranslationGroups[106] === ['en' => 106, 'pl' => 105], 'The incremental PL/EN family was not saved exactly.');
test_expect($testTranslationGroups[101] === $fullParentFamily && $testTranslationGroups[102] === $fullParentFamily && $testTranslationGroups[108] === $fullParentFamily, 'Subset linking modified the full parent family.');

$testWrites = [];
$fullVariationRequest = [
    'parents' => ['pl' => 101, 'en' => 102, 'de' => 108],
    'translations' => ['pl' => 105, 'en' => 106, 'de' => 109],
];
$linkedFullVariationFamily = $linker->linkVariations(new WP_REST_Request($fullVariationRequest));
test_expect($linkedFullVariationFamily instanceof WP_REST_Response, 'The later PL/EN/DE variation family did not link.');
test_expect($linkedFullVariationFamily->data['changed'] === true, 'Extending the variation family with DE did not report a change.');
test_expect($linkedFullVariationFamily->data['translations'] === ['de' => 109, 'en' => 106, 'pl' => 105], 'The full variation response returned an incorrect map.');
test_expect($linkedFullVariationFamily->data['translation_group'] === 'variation:105|106|109', 'The full variation family returned an unstable group.');
test_expect($testTranslationGroups[105] === ['de' => 109, 'en' => 106, 'pl' => 105] && $testTranslationGroups[106] === ['de' => 109, 'en' => 106, 'pl' => 105] && $testTranslationGroups[109] === ['de' => 109, 'en' => 106, 'pl' => 105], 'The PL/EN family was not extended with DE exactly.');
test_expect($testTranslationGroups[101] === $fullParentFamily && $testTranslationGroups[102] === $fullParentFamily && $testTranslationGroups[108] === $fullParentFamily, 'Full variation linking modified the parent family.');

$testPostLanguages[104] = 'en';
$testWrites = [];
$mismatchedParentSubset = $linker->linkVariations(new WP_REST_Request([
    'parents' => ['pl' => 101, 'en' => 104],
    'translations' => ['pl' => 105, 'en' => 107],
]));
test_expect_error($mismatchedParentSubset, 'lemon_erp_variation_parent_translations_mismatch', 409);
test_expect($testWrites === [], 'A mismatched parent subset mutated Polylang state.');

$sameFamilyVariationSubset = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect($sameFamilyVariationSubset instanceof WP_REST_Response, 'An exact child subset of one larger family did not return a response.');
test_expect($sameFamilyVariationSubset->data['changed'] === false, 'An exact child subset of one larger family was not treated as a no-op.');
test_expect($sameFamilyVariationSubset->data['parents'] === ['en' => 102, 'pl' => 101], 'The child subset no-op changed the requested parent map.');
test_expect($sameFamilyVariationSubset->data['translations'] === ['en' => 106, 'pl' => 105], 'The child subset no-op changed the requested translation map.');
test_expect($testWrites === [], 'The child subset no-op attempted to save a smaller family.');
test_expect($testTranslationGroups[105] === ['de' => 109, 'en' => 106, 'pl' => 105] && $testTranslationGroups[106] === ['de' => 109, 'en' => 106, 'pl' => 105] && $testTranslationGroups[109] === ['de' => 109, 'en' => 106, 'pl' => 105], 'The child subset no-op shrank the larger family.');

// A subset is safe only when every requested child reports the same complete
// family. Mixed or inconsistent existing families remain a conflict.
$testTranslationGroups[106] = ['en' => 106];
$testWrites = [];
$mixedVariationSubset = $linker->linkVariations(new WP_REST_Request($variationRequest));
test_expect_error($mixedVariationSubset, 'lemon_erp_variation_translation_conflict', 409);
test_expect($testWrites === [], 'A mixed child-family subset mutated Polylang state.');
$testTranslationGroups[106] = ['de' => 109, 'en' => 106, 'pl' => 105];

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

// A regular linking request must never relabel a term which already belongs to
// another language, even when the requested family itself would be valid.
$testWrites = [];
$testTermLanguages[201] = 'en';
$termLanguageConflict = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['pl' => 201, 'en' => 202],
]));
test_expect_error($termLanguageConflict, 'lemon_erp_attribute_term_language_conflict', 409);
test_expect($testWrites === [], 'A conflicting assigned term language was overwritten.');
$testTermLanguages[201] = 'pl';

// Every pll_set_term_language result is checked before the family write. If a
// later assignment is not persisted, earlier writes are compensated from the
// exact snapshot and no half-linked family survives.
$testTermLanguageWriteOverridesOnce[204] = 'pl';
$termLanguageVerificationFailure = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['pl' => 203, 'en' => 204],
]));
test_expect_error(
    $termLanguageVerificationFailure,
    'lemon_erp_attribute_term_language_verification_failed',
    500,
);
test_expect(
    ($termLanguageVerificationFailure->get_error_data()['compensated'] ?? false) === true,
    'A failed term-language assignment was not compensated.',
);
test_expect($testTermLanguages[203] === 'pl', 'Compensation changed the first term language.');
test_expect($testTermLanguages[204] === 'en', 'Compensation did not restore the second term language.');
test_expect(
    ($testTermTranslationGroups[203] ?? []) === [] && ($testTermTranslationGroups[204] ?? []) === [],
    'A failed term-language assignment left a partial translation family.',
);

// A save which mutates only one side must also be rolled back to the snapshot.
$testWrites = [];
$testPartialTermTranslationSaveOnce = true;
$partialFamilyFailure = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['pl' => 203, 'en' => 204],
]));
test_expect_error($partialFamilyFailure, 'lemon_erp_attribute_term_translation_verification_failed', 500);
test_expect(
    ($partialFamilyFailure->get_error_data()['compensated'] ?? false) === true,
    'A partially saved term family was not compensated.',
);
test_expect($testTermLanguages[203] === 'pl', 'Partial-save compensation changed the Polish term language.');
test_expect($testTermLanguages[204] === 'en', 'Partial-save compensation changed the English term language.');
test_expect(
    ($testTermTranslationGroups[203] ?? []) === [] && ($testTermTranslationGroups[204] ?? []) === [],
    'Partial-save compensation did not restore the unlinked term snapshot.',
);

// An incomplete Polylang save must be reported instead of acknowledged.
$testPersistTermTranslations = false;
$verificationFailure = $linker->linkAttributeTerms(new WP_REST_Request([
    'attribute_id' => 9,
    'translations' => ['pl' => 203, 'en' => 204],
]));
test_expect_error($verificationFailure, 'lemon_erp_attribute_term_translation_verification_failed', 500);

// The early hook must also be harmless on a site where Polylang is absent or
// has not initialized yet. Exercise that state in a clean PHP process because
// this harness defines the Polylang functions used by all other cases.
$bootstrapClassPath = dirname(__DIR__).'/wordpress/lemon-erp-woocommerce/includes/class-global-attribute-taxonomies.php';
$withoutPolylangCode = sprintf(<<<'PHP'
define('ABSPATH', __DIR__);
$actions = [];
$options = [];
function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {}
function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
    global $actions;
    $actions[$hook] = $callback;
}
function get_option(string $name, mixed $default = false): mixed {
    global $options;
    return $options[$name] ?? $default;
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool {
    global $options;
    $options[$name] = $value;
    return true;
}
require %s;
Lemon_Erp_Global_Attribute_Taxonomies::register();
($actions['init'])();
$readiness = Lemon_Erp_Global_Attribute_Taxonomies::readiness();
exit($readiness['completed'] === false && $readiness['state'] === 'waiting_for_dependencies' ? 0 : 1);
PHP, var_export($bootstrapClassPath, true));
$pipes = [];
$withoutPolylang = proc_open(
    [PHP_BINARY, '-r', $withoutPolylangCode],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
test_expect(is_resource($withoutPolylang), 'Could not start the no-Polylang bootstrap harness.');
$withoutPolylangOutput = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$withoutPolylangExit = proc_close($withoutPolylang);
test_expect(
    $withoutPolylangExit === 0,
    'The global-attribute bootstrap failed without Polylang: '.$withoutPolylangOutput,
);

fwrite(STDOUT, "product translation linker tests passed\n");
