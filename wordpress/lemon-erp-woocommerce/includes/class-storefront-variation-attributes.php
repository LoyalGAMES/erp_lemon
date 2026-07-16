<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps taxonomy variation options in the configured WooCommerce term order.
 *
 * WC_Product_Variable_Data_Store_CPT::read_variation_attributes() collects
 * taxonomy values directly from postmeta without an ORDER BY clause. It does,
 * however, call WC_Product_Variable::get_children() immediately before reading
 * the product_variation_attributes_* cache. The children filter is therefore
 * the last public hook where the correctly ordered payload can be installed
 * without replacing a WooCommerce data store or touching persistent product
 * data.
 *
 * This module runs only on normal storefront requests. It never changes the
 * returned children and it preserves local/text variation attributes exactly
 * when WooCommerce has already cached them.
 */
final class Lemon_Erp_Storefront_Variation_Attributes
{
    /** @var array<string, array<string, list<string>>> */
    private array $normalizedByCacheKey = [];

    /** @var array<int, true> */
    private array $normalizing = [];

    public function hooks(): void
    {
        add_filter('woocommerce_get_children', [$this, 'normalizeBeforeVariationAttributeRead'], PHP_INT_MAX, 3);
    }

    public function normalizeBeforeVariationAttributeRead(mixed $children, mixed $product, mixed $visibleOnly): mixed
    {
        if (! $this->isStorefrontRequest()
            || $visibleOnly !== false
            || ! is_array($children)
            || ! is_a($product, 'WC_Product_Variable')
            || ! method_exists($product, 'get_id')
            || ! method_exists($product, 'set_variation_attributes')
        ) {
            return $children;
        }

        $productId = (int) $product->get_id();

        if ($productId <= 0) {
            return $children;
        }

        $cacheKey = $this->cacheKey($productId);

        // Product/term getter filters can execute custom widgets and re-enter
        // get_variation_attributes() for this same object. Let the inner Woo
        // call continue with its current cache; the outer pass will install
        // the canonical payload before it returns.
        if (isset($this->normalizing[$productId])) {
            return $children;
        }

        $this->normalizing[$productId] = true;

        try {
            if (isset($this->normalizedByCacheKey[$cacheKey])) {
                $normalized = $this->normalizedByCacheKey[$cacheKey];
                $this->store($cacheKey, $normalized);
                $product->set_variation_attributes($normalized);

                return $children;
            }

            $normalized = $this->normalizedAttributes($product, $productId, $children);

            if ($normalized === null) {
                return $children;
            }

            // A getter/filter above may have invalidated the product cache
            // prefix during normalization. Memoize and write the final key,
            // which is the same key Woo computes after this hook returns.
            $cacheKey = $this->cacheKey($productId);
            $this->normalizedByCacheKey[$cacheKey] = $normalized;
            $this->store($cacheKey, $normalized);
            $product->set_variation_attributes($normalized);

            return $children;
        } finally {
            unset($this->normalizing[$productId]);
        }
    }

    private function isStorefrontRequest(): bool
    {
        if ((defined('REST_REQUEST') && REST_REQUEST)
            || (defined('WP_CLI') && WP_CLI)
            || (defined('DOING_CRON') && DOING_CRON)
        ) {
            return false;
        }

        return ! function_exists('is_admin') || ! is_admin();
    }

    /**
     * @param  list<int|string>  $children
     * @return array<string, list<string>>|null
     */
    private function normalizedAttributes(mixed $product, int $productId, array $children): ?array
    {
        if (! method_exists($product, 'get_attributes')) {
            return null;
        }

        $attributes = $product->get_attributes();

        if (! is_array($attributes)) {
            return null;
        }

        $childIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $childId): int => (int) $childId, $children),
            static fn (int $childId): bool => $childId > 0,
        )));

        if ($childIds !== []) {
            // Both metadata_exists() and get_post_meta() use this cache. Prime
            // every variation in one query so archive pages do not add one
            // database round trip per child on a cold object cache.
            update_meta_cache('post', $childIds);
        }

        $cached = wp_cache_get($this->cacheKey($productId), 'products');
        $cached = is_array($cached) ? $cached : [];
        $normalized = [];

        foreach ($attributes as $attribute) {
            if (! is_object($attribute)
                || ! method_exists($attribute, 'get_variation')
                || ! $attribute->get_variation()
                || ! method_exists($attribute, 'get_name')
            ) {
                continue;
            }

            $name = trim((string) $attribute->get_name());

            if ($name === '') {
                continue;
            }

            $assigned = $this->assignedValues($children, $name);
            $isTaxonomy = method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy();

            if ($isTaxonomy) {
                $normalized[$name] = $this->orderedTaxonomyValues($productId, $name, $assigned);

                continue;
            }

            // The ordering bug exists only in the direct SQL branch used for
            // taxonomy attributes. Never rewrite a cached local/text value.
            if (array_key_exists($name, $cached) && is_array($cached[$name])) {
                $normalized[$name] = array_values($cached[$name]);

                continue;
            }

            $normalized[$name] = $this->textValues($attribute, $productId, $assigned);
        }

        return $normalized;
    }

    /**
     * @param  list<int|string>  $children
     * @return list<string>
     */
    private function assignedValues(array $children, string $attributeName): array
    {
        $metaKey = wc_variation_attribute_name($attributeName);
        $values = [];

        foreach ($children as $childId) {
            $childId = (int) $childId;

            if ($childId <= 0) {
                continue;
            }

            // Core's SQL query selects only existing meta rows. A missing row
            // must therefore be ignored; only an explicitly stored empty
            // value means "any option" for this attribute.
            if (! metadata_exists('post', $childId, $metaKey)) {
                continue;
            }

            $value = get_post_meta($childId, $metaKey, true);
            $value = is_scalar($value) || $value === null ? (string) $value : '';

            if (! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $assigned
     * @return list<string>
     */
    private function orderedTaxonomyValues(int $productId, string $taxonomy, array $assigned): array
    {
        $terms = wc_get_product_terms($productId, $taxonomy, [
            'fields' => 'all',
            'orderby' => 'menu_order',
            'order' => 'ASC',
        ]);
        $orderedTerms = [];

        if (is_array($terms)) {
            foreach ($terms as $term) {
                if (! is_object($term)
                    || ! isset($term->term_id, $term->slug)
                    || (int) $term->term_id <= 0
                    || trim((string) $term->slug) === ''
                ) {
                    continue;
                }

                $orderedTerms[] = [
                    'id' => (int) $term->term_id,
                    'slug' => (string) $term->slug,
                    'name' => isset($term->name) ? (string) $term->name : (string) $term->slug,
                    // update_term_meta() invalidates the term-meta cache even
                    // when Woo's product-terms cache prefix remains stale.
                    'order' => (int) get_term_meta((int) $term->term_id, 'order', true),
                ];
            }
        }

        usort($orderedTerms, static function (array $left, array $right): int {
            $comparison = $left['order'] <=> $right['order'];

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = strcasecmp($left['name'], $right['name']);

            if ($comparison !== 0) {
                return $comparison;
            }

            $comparison = strcmp($left['name'], $right['name']);

            return $comparison !== 0 ? $comparison : ($left['id'] <=> $right['id']);
        });

        $termSlugs = [];

        foreach ($orderedTerms as $term) {
            if (! in_array($term['slug'], $termSlugs, true)) {
                $termSlugs[] = $term['slug'];
            }
        }

        // WooCommerce treats an empty variation meta value as "any term".
        if ($assigned === [] || in_array('', $assigned, true)) {
            return $termSlugs;
        }

        $ordered = [];

        foreach ($termSlugs as $slug) {
            if (in_array($slug, $assigned, true)) {
                $ordered[] = $slug;
            }
        }

        // A legacy variation can reference a slug no longer attached to the
        // parent product. Keep it usable, but never let it disturb known term
        // ordering.
        foreach ($assigned as $slug) {
            if ($slug !== '' && ! in_array($slug, $ordered, true)) {
                $ordered[] = $slug;
            }
        }

        return $ordered;
    }

    /**
     * Mirrors WooCommerce's local/text attribute branch when there was no
     * existing cache entry to preserve.
     *
     * @param  list<string>  $assigned
     * @return list<string>
     */
    private function textValues(mixed $attribute, int $productId, array $assigned): array
    {
        $options = method_exists($attribute, 'get_options') ? $attribute->get_options() : [];
        $options = is_array($options)
            ? array_values(array_map(static fn (mixed $option): string => (string) $option, $options))
            : [];

        if ($assigned === [] || in_array('', $assigned, true)) {
            return array_values(array_unique($options));
        }

        $legacy = version_compare((string) get_post_meta($productId, '_product_version', true), '2.4.0', '<');
        $comparedAssigned = $legacy ? array_map('sanitize_title', $assigned) : $assigned;
        $values = [];

        foreach ($options as $option) {
            $candidate = $legacy ? sanitize_title($option) : $option;

            if (in_array($candidate, $comparedAssigned, true) && ! in_array($option, $values, true)) {
                $values[] = $option;
            }
        }

        return $values;
    }

    /** @param array<string, list<string>> $attributes */
    private function store(string $cacheKey, array $attributes): void
    {
        wp_cache_set($cacheKey, $attributes, 'products');
    }

    private function cacheKey(int $productId): string
    {
        return WC_Cache_Helper::get_cache_prefix('product_'.$productId)
            .'product_variation_attributes_'.$productId;
    }
}
