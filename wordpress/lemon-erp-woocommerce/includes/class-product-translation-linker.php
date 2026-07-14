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
    private const PLUGIN_VERSION = '0.5.0';

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
    }

    public function canLink(WP_REST_Request $request): bool
    {
        return current_user_can('manage_woocommerce');
    }

    public function capabilities(WP_REST_Request $request): WP_REST_Response
    {
        $polylangAvailable = $this->polylangAvailable();
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
}
