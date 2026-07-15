<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Assigns languages to WooCommerce products and links their Polylang posts.
 *
 * Every requested post is validated before the first Polylang write. This is
 * intentionally exposed below a `wc-` REST namespace so WooCommerce ck_/cs_
 * credentials authenticate the request before the permission callback runs.
 */
final class Lemon_Erp_Product_Translation_Linker
{
    private const PLUGIN_VERSION = '0.5.2';

    private const CATALOG_CONTRACT = 1;

    public function hooks(): void
    {
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
    }

    public function registerRestRoutes(): void
    {
        register_rest_route('wc-lemon-erp/v1', '/catalog/products/translations/capabilities', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'capabilities'],
            'permission_callback' => [$this, 'canLink'],
        ]);

        register_rest_route('wc-lemon-erp/v1', '/catalog/products/translations', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'link'],
            'permission_callback' => [$this, 'canLink'],
            'args' => [
                'translations' => [
                    'required' => true,
                    'type' => 'object',
                ],
            ],
        ]);

        register_rest_route(
            'wc-lemon-erp/v1',
            '/catalog/products/attributes/(?P<attribute_id>\d+)/terms/translations',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'linkAttributeTerms'],
                'permission_callback' => [$this, 'canLinkAttributeTerms'],
                'args' => [
                    'attribute_id' => [
                        'required' => true,
                        'validate_callback' => fn ($value): bool => is_numeric($value) && (int) $value > 0,
                    ],
                    'translations' => [
                        'required' => true,
                        'type' => 'object',
                    ],
                ],
            ],
        );
    }

    public function canLink(WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public function canLinkAttributeTerms(WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce')
            && current_user_can('manage_product_terms');
    }

    public function capabilities(WP_REST_Request $request): WP_REST_Response
    {
        $polylangAvailable = $this->polylangAvailable();
        $attributeBootstrap = class_exists(Lemon_Erp_Global_Attribute_Taxonomies::class)
            ? Lemon_Erp_Global_Attribute_Taxonomies::readiness()
            : [
                'revision' => null,
                'completed' => false,
                'state' => 'unavailable',
                'not_ready_taxonomies' => [],
                'not_ready_taxonomies_count' => 0,
                'unassigned_terms_count' => 0,
                'unassigned_term_taxonomies' => [],
            ];
        $attributeTermLinkAvailable = $this->polylangTermsAvailable()
            && current_user_can('manage_product_terms')
            && $attributeBootstrap['completed'] === true
            && $attributeBootstrap['not_ready_taxonomies_count'] === 0;
        $languages = $polylangAvailable
            ? array_values(array_filter(array_map(
                fn (mixed $language): ?string => $this->languageSlug($language),
                (array) pll_languages_list(['fields' => 'slug']),
            )))
            : [];

        return new WP_REST_Response([
            'available' => $polylangAvailable,
            'resource' => 'product_translation_link',
            'languages' => array_values(array_unique($languages)),
            'attribute_term_translation_link_available' => $attributeTermLinkAvailable,
            'attribute_term_translation_link_endpoint' => '/wp-json/wc-lemon-erp/v1/catalog/products/attributes/{attribute_id}/terms/translations',
            'attribute_term_translation_bootstrap_revision' => $attributeBootstrap['revision'],
            'attribute_term_translation_bootstrap_completed' => $attributeBootstrap['completed'],
            'attribute_term_translation_bootstrap_state' => $attributeBootstrap['state'],
            'attribute_term_translation_taxonomies_not_ready' => $attributeBootstrap['not_ready_taxonomies'],
            'attribute_term_translation_taxonomies_not_ready_count' => $attributeBootstrap['not_ready_taxonomies_count'],
            'attribute_term_translation_unassigned_terms_count' => $attributeBootstrap['unassigned_terms_count'],
            'attribute_term_translation_unassigned_term_taxonomies' => $attributeBootstrap['unassigned_term_taxonomies'],
            'catalog_contract' => self::CATALOG_CONTRACT,
            'plugin_version' => self::PLUGIN_VERSION,
        ], 200);
    }

    public function link(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! $this->polylangAvailable()) {
            return new WP_Error(
                'lemon_erp_product_polylang_required',
                __('Powiązanie tłumaczeń produktów wymaga aktywnego Polylang.', 'lemon-erp-woocommerce'),
                ['status' => 409],
            );
        }

        $translations = $this->validatedTranslationMap($request->get_param('translations'));

        if ($translations instanceof WP_Error) {
            return $translations;
        }

        $activeLanguages = array_values(array_filter(array_map(
            fn (mixed $language): ?string => $this->languageSlug($language),
            (array) pll_languages_list(['fields' => 'slug']),
        )));

        foreach ($translations as $language => $postId) {
            if (! in_array($language, $activeLanguages, true)) {
                return new WP_Error(
                    'lemon_erp_product_language_invalid',
                    sprintf(__('Język %s nie jest aktywny w Polylang.', 'lemon-erp-woocommerce'), $language),
                    ['status' => 422],
                );
            }

            if (get_post_type($postId) !== 'product') {
                return new WP_Error(
                    'lemon_erp_product_translation_post_invalid',
                    sprintf(__('Post %d nie jest produktem WooCommerce.', 'lemon-erp-woocommerce'), $postId),
                    ['status' => 422],
                );
            }

            $status = get_post_status($postId);

            if (! is_string($status) || in_array($status, ['trash', 'auto-draft'], true)) {
                return new WP_Error(
                    'lemon_erp_product_translation_status_invalid',
                    sprintf(__('Produkt %d nie ma statusu pozwalającego na powiązanie tłumaczeń.', 'lemon-erp-woocommerce'), $postId),
                    ['status' => 422],
                );
            }

            if (! current_user_can('edit_post', $postId)) {
                return new WP_Error(
                    'lemon_erp_product_translation_forbidden',
                    sprintf(__('Brak uprawnienia do edycji produktu %d.', 'lemon-erp-woocommerce'), $postId),
                    ['status' => 403],
                );
            }
        }

        $requestedPostIds = array_values($translations);

        foreach ($requestedPostIds as $postId) {
            $existingPostIds = array_values($this->translationMap(
                (array) pll_get_post_translations($postId),
            ));
            $foreignPostIds = array_diff($existingPostIds, $requestedPostIds);

            if ($foreignPostIds !== []) {
                return new WP_Error(
                    'lemon_erp_product_translation_conflict',
                    sprintf(
                        __('Produkt %d należy już do innej rodziny tłumaczeń.', 'lemon-erp-woocommerce'),
                        $postId,
                    ),
                    ['status' => 409],
                );
            }
        }

        if ($this->alreadyLinked($translations)) {
            return $this->response($translations, false);
        }

        // All language, post, permission and existing-group checks above must
        // succeed before this first mutating Polylang call is made.
        foreach ($translations as $language => $postId) {
            pll_set_post_language($postId, $language);
        }

        pll_save_post_translations($translations);

        if (! $this->alreadyLinked($translations)) {
            return new WP_Error(
                'lemon_erp_product_translation_verification_failed',
                __('Polylang nie potwierdził zapisanego powiązania tłumaczeń produktów.', 'lemon-erp-woocommerce'),
                ['status' => 500],
            );
        }

        return $this->response($translations, true);
    }

    public function linkAttributeTerms(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (! $this->polylangTermsAvailable()) {
            return new WP_Error(
                'lemon_erp_attribute_term_polylang_required',
                __('Powiązanie tłumaczeń wartości atrybutów wymaga aktywnego Polylang.', 'lemon-erp-woocommerce'),
                ['status' => 409],
            );
        }

        $attributeBootstrap = class_exists(Lemon_Erp_Global_Attribute_Taxonomies::class)
            ? Lemon_Erp_Global_Attribute_Taxonomies::readiness()
            : ['completed' => false, 'not_ready_taxonomies' => []];

        if ($attributeBootstrap['completed'] !== true
            || $attributeBootstrap['not_ready_taxonomies'] !== []
        ) {
            return new WP_Error(
                'lemon_erp_attribute_term_bootstrap_incomplete',
                __('Języki istniejących wartości globalnych atrybutów WooCommerce nie zostały jeszcze bezpiecznie przygotowane.', 'lemon-erp-woocommerce'),
                [
                    'status' => 409,
                    'bootstrap_revision' => $attributeBootstrap['revision'] ?? null,
                    'not_ready_taxonomies' => $attributeBootstrap['not_ready_taxonomies'],
                ],
            );
        }

        $attributeId = (int) $request->get_param('attribute_id');
        $taxonomy = function_exists('wc_attribute_taxonomy_name_by_id')
            ? sanitize_key((string) wc_attribute_taxonomy_name_by_id($attributeId))
            : '';

        if ($attributeId <= 0
            || ! str_starts_with($taxonomy, 'pa_')
            || ! taxonomy_exists($taxonomy)
            || ! pll_is_translated_taxonomy($taxonomy)
        ) {
            return new WP_Error(
                'lemon_erp_attribute_taxonomy_invalid',
                __('ID nie wskazuje tłumaczonego globalnego atrybutu WooCommerce.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        $translations = $this->validatedTermTranslationMap($request->get_param('translations'));

        if ($translations instanceof WP_Error) {
            return $translations;
        }

        $activeLanguages = array_values(array_filter(array_map(
            fn (mixed $language): ?string => $this->languageSlug($language),
            (array) pll_languages_list(['fields' => 'slug']),
        )));
        $requestedTermIds = array_values($translations);
        $snapshot = [];

        foreach ($translations as $language => $termId) {
            if (! in_array($language, $activeLanguages, true)) {
                return new WP_Error(
                    'lemon_erp_attribute_term_language_invalid',
                    sprintf(__('Język %s nie jest aktywny w Polylang.', 'lemon-erp-woocommerce'), $language),
                    ['status' => 422],
                );
            }

            $term = get_term($termId, $taxonomy);

            if (! $term instanceof WP_Term || $term->taxonomy !== $taxonomy) {
                return new WP_Error(
                    'lemon_erp_attribute_term_invalid',
                    sprintf(__('Wartość atrybutu %d nie należy do taksonomii %s.', 'lemon-erp-woocommerce'), $termId, $taxonomy),
                    ['status' => 422],
                );
            }

            if (! current_user_can('edit_term', $termId)) {
                return new WP_Error(
                    'lemon_erp_attribute_term_forbidden',
                    sprintf(__('Brak uprawnienia do edycji wartości atrybutu %d.', 'lemon-erp-woocommerce'), $termId),
                    ['status' => 403],
                );
            }

            $currentLanguage = $this->languageSlug(pll_get_term_language($termId, 'slug'));

            // This endpoint links a known family; it is not a language
            // migration endpoint. Never let an ordinary ERP request move a
            // term which Polylang or an operator assigned to another language.
            if ($currentLanguage !== null && $currentLanguage !== $language) {
                return new WP_Error(
                    'lemon_erp_attribute_term_language_conflict',
                    sprintf(
                        __('Wartość atrybutu %1$d ma już język %2$s zamiast oczekiwanego %3$s.', 'lemon-erp-woocommerce'),
                        $termId,
                        $currentLanguage,
                        $language,
                    ),
                    ['status' => 409],
                );
            }

            $snapshot[$termId] = [
                'language' => $currentLanguage,
                'translations' => $this->termTranslationMap(
                    (array) pll_get_term_translations($termId),
                ),
            ];
        }

        foreach ($requestedTermIds as $termId) {
            $existingTermIds = array_values($this->termTranslationMap(
                (array) pll_get_term_translations($termId),
            ));
            $foreignTermIds = array_diff($existingTermIds, $requestedTermIds);

            if ($foreignTermIds !== []) {
                return new WP_Error(
                    'lemon_erp_attribute_term_translation_conflict',
                    sprintf(__('Wartość atrybutu %d należy już do innej rodziny tłumaczeń.', 'lemon-erp-woocommerce'), $termId),
                    ['status' => 409],
                );
            }
        }

        if ($this->attributeTermsAlreadyLinked($taxonomy, $translations)) {
            return $this->attributeTermResponse($attributeId, $taxonomy, $translations, false);
        }

        // Do not mutate any language before the complete taxonomy, language,
        // capability and existing-family validation above has succeeded. Each
        // language assignment is verified before the next term can be touched;
        // on any partial failure the exact pre-request family is restored.
        foreach ($translations as $language => $termId) {
            try {
                pll_set_term_language($termId, $language);
            } catch (Throwable) {
                return $this->attributeTermMutationFailure(
                    'lemon_erp_attribute_term_language_verification_failed',
                    __('Polylang nie zapisał języka wartości globalnego atrybutu.', 'lemon-erp-woocommerce'),
                    $taxonomy,
                    $snapshot,
                );
            }

            if ($this->languageSlug(pll_get_term_language($termId, 'slug')) !== $language) {
                return $this->attributeTermMutationFailure(
                    'lemon_erp_attribute_term_language_verification_failed',
                    sprintf(
                        __('Polylang nie potwierdził języka %1$s wartości atrybutu %2$d.', 'lemon-erp-woocommerce'),
                        $language,
                        $termId,
                    ),
                    $taxonomy,
                    $snapshot,
                );
            }
        }

        try {
            pll_save_term_translations($translations);
        } catch (Throwable) {
            return $this->attributeTermMutationFailure(
                'lemon_erp_attribute_term_translation_verification_failed',
                __('Polylang nie zapisał powiązania tłumaczeń wartości atrybutu.', 'lemon-erp-woocommerce'),
                $taxonomy,
                $snapshot,
            );
        }

        if (! $this->attributeTermsAlreadyLinked($taxonomy, $translations)) {
            return $this->attributeTermMutationFailure(
                'lemon_erp_attribute_term_translation_verification_failed',
                __('Polylang nie potwierdził dokładnego powiązania tłumaczeń wartości atrybutu.', 'lemon-erp-woocommerce'),
                $taxonomy,
                $snapshot,
            );
        }

        return $this->attributeTermResponse($attributeId, $taxonomy, $translations, true);
    }

    /**
     * @return array<string, int>|WP_Error
     */
    private function validatedTranslationMap(mixed $input): array|WP_Error
    {
        if (! is_array($input) && ! is_object($input)) {
            return new WP_Error(
                'lemon_erp_product_translations_invalid',
                __('Pole translations musi być mapą język => ID produktu.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        $translations = [];

        foreach ((array) $input as $language => $postId) {
            $rawLanguage = strtolower(trim((string) $language));
            $normalizedLanguage = $this->languageSlug($rawLanguage);

            if ($normalizedLanguage === null || $normalizedLanguage !== $rawLanguage) {
                return new WP_Error(
                    'lemon_erp_product_translation_language_invalid',
                    __('Kod języka tłumaczenia jest niepoprawny.', 'lemon-erp-woocommerce'),
                    ['status' => 422],
                );
            }

            if (array_key_exists($normalizedLanguage, $translations)) {
                return new WP_Error(
                    'lemon_erp_product_translation_language_duplicate',
                    sprintf(__('Język %s występuje więcej niż raz.', 'lemon-erp-woocommerce'), $normalizedLanguage),
                    ['status' => 422],
                );
            }

            if (! is_int($postId) && ! is_string($postId)) {
                return new WP_Error(
                    'lemon_erp_product_translation_id_invalid',
                    sprintf(__('ID produktu dla języka %s jest niepoprawne.', 'lemon-erp-woocommerce'), $normalizedLanguage),
                    ['status' => 422],
                );
            }

            $rawPostId = trim((string) $postId);

            if (preg_match('/^[1-9]\d*$/', $rawPostId) !== 1
                || (string) ((int) $rawPostId) !== $rawPostId
            ) {
                return new WP_Error(
                    'lemon_erp_product_translation_id_invalid',
                    sprintf(__('ID produktu dla języka %s jest niepoprawne.', 'lemon-erp-woocommerce'), $normalizedLanguage),
                    ['status' => 422],
                );
            }

            $translations[$normalizedLanguage] = (int) $rawPostId;
        }

        if (count($translations) < 2) {
            return new WP_Error(
                'lemon_erp_product_translations_incomplete',
                __('Do powiązania wymagane są co najmniej dwa języki produktu.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        if (count(array_unique(array_values($translations))) !== count($translations)) {
            return new WP_Error(
                'lemon_erp_product_translation_ids_duplicate',
                __('Każdy język musi wskazywać inny produkt WooCommerce.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        ksort($translations);

        return $translations;
    }

    /**
     * @return array<string, int>|WP_Error
     */
    private function validatedTermTranslationMap(mixed $input): array|WP_Error
    {
        if (! is_array($input) && ! is_object($input)) {
            return new WP_Error(
                'lemon_erp_attribute_term_translations_invalid',
                __('Pole translations musi być mapą język => ID wartości atrybutu.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        $translations = [];

        foreach ((array) $input as $language => $termId) {
            $rawLanguage = strtolower(trim((string) $language));
            $normalizedLanguage = $this->languageSlug($rawLanguage);

            if ($normalizedLanguage === null || $normalizedLanguage !== $rawLanguage) {
                return new WP_Error(
                    'lemon_erp_attribute_term_translation_language_invalid',
                    __('Kod języka tłumaczenia wartości atrybutu jest niepoprawny.', 'lemon-erp-woocommerce'),
                    ['status' => 422],
                );
            }

            if (array_key_exists($normalizedLanguage, $translations)) {
                return new WP_Error(
                    'lemon_erp_attribute_term_translation_language_duplicate',
                    sprintf(__('Język %s występuje więcej niż raz.', 'lemon-erp-woocommerce'), $normalizedLanguage),
                    ['status' => 422],
                );
            }

            if (! is_int($termId) && ! is_string($termId)) {
                return new WP_Error(
                    'lemon_erp_attribute_term_translation_id_invalid',
                    sprintf(__('ID wartości atrybutu dla języka %s jest niepoprawne.', 'lemon-erp-woocommerce'), $normalizedLanguage),
                    ['status' => 422],
                );
            }

            $rawTermId = trim((string) $termId);

            if (preg_match('/^[1-9]\d*$/', $rawTermId) !== 1
                || (string) ((int) $rawTermId) !== $rawTermId
            ) {
                return new WP_Error(
                    'lemon_erp_attribute_term_translation_id_invalid',
                    sprintf(__('ID wartości atrybutu dla języka %s jest niepoprawne.', 'lemon-erp-woocommerce'), $normalizedLanguage),
                    ['status' => 422],
                );
            }

            $translations[$normalizedLanguage] = (int) $rawTermId;
        }

        if (count($translations) < 2) {
            return new WP_Error(
                'lemon_erp_attribute_term_translations_incomplete',
                __('Do powiązania wymagane są co najmniej dwa języki wartości atrybutu.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        if (count(array_unique(array_values($translations))) !== count($translations)) {
            return new WP_Error(
                'lemon_erp_attribute_term_translation_ids_duplicate',
                __('Każdy język musi wskazywać inną wartość atrybutu.', 'lemon-erp-woocommerce'),
                ['status' => 422],
            );
        }

        ksort($translations);

        return $translations;
    }

    /**
     * @param  array<string, int>  $translations
     */
    private function alreadyLinked(array $translations): bool
    {
        foreach ($translations as $language => $postId) {
            if ($this->languageSlug(pll_get_post_language($postId, 'slug')) !== $language) {
                return false;
            }

            if ($this->translationMap((array) pll_get_post_translations($postId)) !== $translations) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, int> $translations */
    private function attributeTermsAlreadyLinked(string $taxonomy, array $translations): bool
    {
        foreach ($translations as $language => $termId) {
            $term = get_term($termId, $taxonomy);

            if (! $term instanceof WP_Term
                || $term->taxonomy !== $taxonomy
                || $this->languageSlug(pll_get_term_language($termId, 'slug')) !== $language
                || $this->termTranslationMap((array) pll_get_term_translations($termId)) !== $translations
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array{language:?string, translations:array<string, int>}>  $snapshot
     */
    private function attributeTermMutationFailure(
        string $code,
        string $message,
        string $taxonomy,
        array $snapshot,
    ): WP_Error {
        return new WP_Error($code, $message, [
            'status' => 500,
            'compensated' => $this->restoreAttributeTermSnapshot($taxonomy, $snapshot),
        ]);
    }

    /**
     * Best-effort compensation for a Polylang write interrupted halfway.
     *
     * Saving a one-term map is Polylang's public, stable way to detach that
     * term from its current translation family. Once every requested term is
     * isolated, the language assignments and the original multi-term families
     * can be rebuilt from the snapshot without guessing any relationship.
     *
     * @param  array<int, array{language:?string, translations:array<string, int>}>  $snapshot
     */
    private function restoreAttributeTermSnapshot(string $taxonomy, array $snapshot): bool
    {
        try {
            foreach ($snapshot as $termId => $state) {
                $currentLanguage = $this->languageSlug(pll_get_term_language($termId, 'slug'));

                if ($currentLanguage !== null) {
                    pll_save_term_translations([$currentLanguage => $termId]);
                }
            }

            foreach ($snapshot as $termId => $state) {
                $language = $state['language'];

                if ($language === null) {
                    if (! function_exists('wp_delete_object_term_relationships')) {
                        return false;
                    }

                    $result = wp_delete_object_term_relationships($termId, 'term_language');

                    if (is_wp_error($result)) {
                        return false;
                    }
                } elseif ($this->languageSlug(pll_get_term_language($termId, 'slug')) !== $language) {
                    pll_set_term_language($termId, $language);
                }

                if ($this->languageSlug(pll_get_term_language($termId, 'slug')) !== $language) {
                    return false;
                }
            }

            $restoredFamilies = [];

            foreach ($snapshot as $state) {
                $translations = $state['translations'];

                if (count($translations) < 2) {
                    continue;
                }

                $familyKey = implode('|', array_map(
                    static fn (string $language, int $termId): string => $language.':'.$termId,
                    array_keys($translations),
                    array_values($translations),
                ));

                if (isset($restoredFamilies[$familyKey])) {
                    continue;
                }

                pll_save_term_translations($translations);
                $restoredFamilies[$familyKey] = true;
            }
        } catch (Throwable) {
            return false;
        }

        foreach ($snapshot as $termId => $state) {
            if (! get_term($termId, $taxonomy) instanceof WP_Term
                || $this->languageSlug(pll_get_term_language($termId, 'slug')) !== $state['language']
                || $this->termTranslationMap((array) pll_get_term_translations($termId)) !== $state['translations']
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $translations
     * @return array<string, int>
     */
    private function translationMap(array $translations): array
    {
        $map = [];

        foreach ($translations as $language => $postId) {
            $language = $this->languageSlug($language);
            $postId = (int) $postId;

            if ($language !== null && $postId > 0) {
                $map[$language] = $postId;
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  array<mixed>  $translations
     * @return array<string, int>
     */
    private function termTranslationMap(array $translations): array
    {
        return $this->translationMap($translations);
    }

    /**
     * @param  array<string, int>  $translations
     */
    private function response(array $translations, bool $changed): WP_REST_Response
    {
        $ids = array_values($translations);
        sort($ids, SORT_NUMERIC);

        return new WP_REST_Response([
            'linked' => true,
            'changed' => $changed,
            'resource' => 'product',
            'translations' => $translations,
            'translation_group' => 'product:'.implode('|', $ids),
            'catalog_contract' => self::CATALOG_CONTRACT,
            'plugin_version' => self::PLUGIN_VERSION,
        ], 200);
    }

    /** @param array<string, int> $translations */
    private function attributeTermResponse(
        int $attributeId,
        string $taxonomy,
        array $translations,
        bool $changed,
    ): WP_REST_Response {
        $ids = array_values($translations);
        sort($ids, SORT_NUMERIC);

        return new WP_REST_Response([
            'linked' => true,
            'changed' => $changed,
            'resource' => 'product_attribute_term',
            'attribute_id' => $attributeId,
            'taxonomy' => $taxonomy,
            'translations' => $translations,
            'translation_group' => $taxonomy.':'.implode('|', $ids),
            'catalog_contract' => self::CATALOG_CONTRACT,
            'plugin_version' => self::PLUGIN_VERSION,
        ], 200);
    }

    private function languageSlug(mixed $language): ?string
    {
        $language = sanitize_key((string) $language);

        return $language !== '' ? $language : null;
    }

    private function polylangAvailable(): bool
    {
        return function_exists('pll_languages_list')
            && function_exists('pll_get_post_language')
            && function_exists('pll_get_post_translations')
            && function_exists('pll_set_post_language')
            && function_exists('pll_save_post_translations');
    }

    private function polylangTermsAvailable(): bool
    {
        return function_exists('pll_languages_list')
            && function_exists('pll_is_translated_taxonomy')
            && function_exists('pll_get_term_language')
            && function_exists('pll_get_term_translations')
            && function_exists('pll_set_term_language')
            && function_exists('pll_save_term_translations');
    }
}
