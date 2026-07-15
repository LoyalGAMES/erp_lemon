<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Makes every WooCommerce global product attribute multilingual in Polylang.
 *
 * Polylang applies `pll_get_taxonomies` early and may cache its result, so the
 * filter itself must be registered while plugin files are loading, before the
 * `plugins_loaded` action. The settings branch hides these taxonomies from the
 * checkbox list so an administrator cannot disable the integration contract.
 */
final class Lemon_Erp_Global_Attribute_Taxonomies
{
    public const TERM_LANGUAGE_BOOTSTRAP_REVISION = 'global_attribute_term_languages_2026_07_15_000002';

    private const TERM_LANGUAGE_BOOTSTRAP_OPTION = 'lemon_erp_global_attribute_term_language_bootstrap';

    private const TERM_LANGUAGE_BOOTSTRAP_STATUS_OPTION = 'lemon_erp_global_attribute_term_language_bootstrap_status';

    private const TERM_LANGUAGE_SAFETY_HOLD_OPTION = 'lemon_erp_global_attribute_term_language_safety_hold';

    public static function register(): void
    {
        add_filter('pll_get_taxonomies', [self::class, 'translatedTaxonomies'], 10, 2);
        add_action('init', [self::class, 'bootstrapTermLanguages'], 100);
        add_action('created_term', [self::class, 'invalidateOnTermChange'], 10, 3);
        add_action('edited_term', [self::class, 'invalidateOnTermChange'], 10, 3);
    }

    /**
     * @param  array<string, string>  $taxonomies
     * @return array<string, string>
     */
    public static function translatedTaxonomies(array $taxonomies, bool $isSettings): array
    {
        if (! function_exists('wc_get_attribute_taxonomy_names')) {
            return $taxonomies;
        }

        $safetyHoldTaxonomies = $isSettings ? [] : self::safetyHoldTaxonomies();

        foreach ((array) wc_get_attribute_taxonomy_names() as $taxonomy) {
            if (! is_string($taxonomy)) {
                continue;
            }

            $taxonomy = trim($taxonomy);

            if (! str_starts_with($taxonomy, 'pa_') || strlen($taxonomy) <= 3) {
                continue;
            }

            if ($isSettings || in_array($taxonomy, $safetyHoldTaxonomies, true)) {
                unset($taxonomies[$taxonomy]);
            } else {
                $taxonomies[$taxonomy] = $taxonomy;
            }
        }

        return $taxonomies;
    }

    /**
     * Gives every existing global-attribute term a language before Polylang
     * starts filtering the catalog by language.
     *
     * This assigns only a verified language intent and never guesses or builds
     * translation families. Exact PL/EN families are linked later by the ERP
     * endpoint, which knows both term IDs. A completion marker is written only
     * after the entire current taxonomy set was scanned and every required
     * write was verified. Therefore an interrupted request can resume safely
     * on the next `init`.
     */
    public static function bootstrapTermLanguages(): void
    {
        $taxonomies = self::attributeTaxonomies();

        if (! self::bootstrapFunctionsAvailable()) {
            self::writeStatus('waiting_for_dependencies', $taxonomies);

            return;
        }

        // A previous pass may have found an unassigned term that Polylang
        // already references from a translation family. In that situation we
        // keep the affected taxonomy out of Polylang on subsequent requests,
        // protecting existing filters and variants until an operator edits
        // the term/family. The term hooks release the hold and trigger a fresh
        // complete preflight; inspecting the family while its taxonomy is not
        // translated would yield an unreliable false negative in Polylang.
        $safetyHoldTaxonomies = self::safetyHoldTaxonomies();
        $heldTaxonomies = array_values(array_intersect($safetyHoldTaxonomies, $taxonomies));

        if ($heldTaxonomies !== []) {
            self::writeStatus('blocked_by_existing_translation_family', $taxonomies, $heldTaxonomies);

            return;
        }

        if ($safetyHoldTaxonomies !== []) {
            self::clearSafetyHold();
        }

        $notTranslated = self::notTranslatedTaxonomies($taxonomies);

        if ($notTranslated !== []) {
            self::writeStatus('waiting_for_translated_taxonomies', $taxonomies, $notTranslated);

            return;
        }

        if (self::completionMarkerMatches($taxonomies)) {
            return;
        }

        try {
            $termsByTaxonomy = self::termsByTaxonomy($taxonomies);
            $familyConflicts = self::existingTranslationFamilyConflictTaxonomies($termsByTaxonomy);
        } catch (Throwable) {
            self::writeStatus('incomplete', $taxonomies);

            return;
        }

        // Do not assign a language to an otherwise unassigned term when it is
        // already referenced by a Polylang translation family. Guessing here
        // could silently move an existing EN term to PL and corrupt filters.
        // No term write has happened at this point.
        if ($familyConflicts !== []) {
            self::writeSafetyHold($familyConflicts);

            if (function_exists('delete_option')) {
                delete_option(self::TERM_LANGUAGE_BOOTSTRAP_OPTION);
            }

            self::writeStatus('blocked_by_existing_translation_family', $taxonomies, $familyConflicts);

            return;
        }

        $defaultLanguage = self::languageSlug(pll_default_language('slug'));
        $activeLanguages = array_values(array_unique(array_filter(array_map(
            static fn (mixed $language): ?string => self::languageSlug($language),
            (array) pll_languages_list(['fields' => 'slug']),
        ))));

        if ($defaultLanguage === null || ! in_array($defaultLanguage, $activeLanguages, true)) {
            self::writeStatus('invalid_default_language', $taxonomies);

            return;
        }

        $assigned = 0;

        try {
            foreach ($termsByTaxonomy as $taxonomy => $terms) {
                $taxonomySlugs = array_values(array_unique(array_map(
                    static fn (WP_Term $term): string => strtolower(trim((string) $term->slug)),
                    $terms,
                )));

                foreach ($terms as $term) {
                    $termId = (int) $term->term_id;

                    // Never rewrite a language selected by Polylang or an
                    // operator, even if the slug would suggest another one.
                    if (self::languageSlug(pll_get_term_language($termId, 'slug')) !== null) {
                        continue;
                    }

                    $slug = strtolower(trim((string) $term->slug));
                    $language = self::languageFromSlug(
                        $slug,
                        $activeLanguages,
                        $defaultLanguage,
                        $taxonomySlugs,
                    );

                    if (! in_array($language, $activeLanguages, true)) {
                        throw new RuntimeException(sprintf(
                            'Język %s wymagany przez wartość atrybutu %d nie jest aktywny.',
                            $language,
                            $termId,
                        ));
                    }

                    pll_set_term_language($termId, $language);

                    if (self::languageSlug(pll_get_term_language($termId, 'slug')) !== $language) {
                        throw new RuntimeException(sprintf(
                            'Polylang nie potwierdził języka wartości atrybutu %d.',
                            $termId,
                        ));
                    }

                    $assigned++;
                }
            }
        } catch (Throwable) {
            self::writeStatus('incomplete', $taxonomies, [], $assigned);

            return;
        }

        $marker = [
            'revision' => self::TERM_LANGUAGE_BOOTSTRAP_REVISION,
            'taxonomies' => $taxonomies,
        ];

        update_option(self::TERM_LANGUAGE_BOOTSTRAP_OPTION, $marker, false);

        // A failed database write must not be advertised as readiness. The
        // next request will retry the idempotent pass.
        if (! self::completionMarkerMatches($taxonomies)) {
            self::writeStatus('incomplete', $taxonomies, [], $assigned);

            return;
        }

        self::writeStatus('completed', $taxonomies, [], $assigned);
    }

    /**
     * @return array{
     *     revision:string,
     *     completed:bool,
     *     taxonomies:list<string>,
     *     not_ready_taxonomies:list<string>,
     *     not_ready_taxonomies_count:int,
     *     unassigned_terms_count:int,
     *     unassigned_term_taxonomies:list<string>,
     *     state:string
     * }
     */
    public static function readiness(): array
    {
        $taxonomies = self::attributeTaxonomies();
        $notReady = self::translationFunctionsAvailable()
            ? self::notTranslatedTaxonomies($taxonomies)
            : $taxonomies;
        $termState = self::termInspectionFunctionsAvailable()
            ? self::unassignedTermState($taxonomies)
            : [
                'complete' => false,
                'count' => 0,
                'taxonomies' => $taxonomies,
                'failed_taxonomies' => $taxonomies,
            ];
        $notReady = array_values(array_unique(array_merge(
            $notReady,
            $termState['failed_taxonomies'],
        )));
        sort($notReady, SORT_STRING);

        // Creation/edit hooks normally invalidate the marker immediately.
        // This additional check recovers safely from direct database writes or
        // term changes made while this plugin was inactive. It runs only when
        // the ERP asks for capabilities, never on normal storefront requests.
        if ($termState['count'] > 0
            && self::completionMarkerMatches($taxonomies)
            && function_exists('delete_option')
        ) {
            delete_option(self::TERM_LANGUAGE_BOOTSTRAP_OPTION);
        }

        $completed = $notReady === []
            && $termState['complete']
            && $termState['count'] === 0
            && self::completionMarkerMatches($taxonomies);
        $status = function_exists('get_option')
            ? get_option(self::TERM_LANGUAGE_BOOTSTRAP_STATUS_OPTION, [])
            : [];
        $state = is_array($status) && is_string($status['state'] ?? null)
            ? $status['state']
            : ($completed ? 'completed' : 'pending');

        if (! $completed && $state === 'completed') {
            $state = 'pending';
        }

        return [
            'revision' => self::TERM_LANGUAGE_BOOTSTRAP_REVISION,
            'completed' => $completed,
            'taxonomies' => $taxonomies,
            'not_ready_taxonomies' => $notReady,
            'not_ready_taxonomies_count' => count($notReady),
            'unassigned_terms_count' => $termState['count'],
            'unassigned_term_taxonomies' => $termState['taxonomies'],
            'state' => $state,
        ];
    }

    public static function invalidateOnTermChange(
        int $termId,
        int $termTaxonomyId,
        string $taxonomy,
    ): void {
        if (str_starts_with($taxonomy, 'pa_')
            && strlen($taxonomy) > 3
            && function_exists('delete_option')
        ) {
            delete_option(self::TERM_LANGUAGE_BOOTSTRAP_OPTION);
            delete_option(self::TERM_LANGUAGE_SAFETY_HOLD_OPTION);
        }
    }

    /** @return list<string> */
    private static function attributeTaxonomies(): array
    {
        if (! function_exists('wc_get_attribute_taxonomy_names')) {
            return [];
        }

        $taxonomies = [];

        foreach ((array) wc_get_attribute_taxonomy_names() as $taxonomy) {
            if (! is_string($taxonomy)) {
                continue;
            }

            $taxonomy = trim($taxonomy);

            if (str_starts_with($taxonomy, 'pa_') && strlen($taxonomy) > 3) {
                $taxonomies[] = $taxonomy;
            }
        }

        $taxonomies = array_values(array_unique($taxonomies));
        sort($taxonomies, SORT_STRING);

        return $taxonomies;
    }

    /** @param list<string> $taxonomies */
    private static function completionMarkerMatches(array $taxonomies): bool
    {
        if (! function_exists('get_option')) {
            return false;
        }

        $marker = get_option(self::TERM_LANGUAGE_BOOTSTRAP_OPTION, []);

        return is_array($marker)
            && ($marker['revision'] ?? null) === self::TERM_LANGUAGE_BOOTSTRAP_REVISION
            && ($marker['taxonomies'] ?? null) === $taxonomies;
    }

    /** @return list<string> */
    private static function safetyHoldTaxonomies(): array
    {
        if (! function_exists('get_option')) {
            return [];
        }

        $hold = get_option(self::TERM_LANGUAGE_SAFETY_HOLD_OPTION, []);

        if (! is_array($hold)
            || ($hold['revision'] ?? null) !== self::TERM_LANGUAGE_BOOTSTRAP_REVISION
            || ! is_array($hold['taxonomies'] ?? null)
        ) {
            return [];
        }

        $taxonomies = array_values(array_unique(array_filter(
            $hold['taxonomies'],
            static fn (mixed $taxonomy): bool => is_string($taxonomy)
                && str_starts_with($taxonomy, 'pa_')
                && strlen($taxonomy) > 3,
        )));
        sort($taxonomies, SORT_STRING);

        return $taxonomies;
    }

    /** @param list<string> $taxonomies */
    private static function writeSafetyHold(array $taxonomies): void
    {
        if (! function_exists('update_option')) {
            return;
        }

        $taxonomies = array_values(array_unique($taxonomies));
        sort($taxonomies, SORT_STRING);

        update_option(self::TERM_LANGUAGE_SAFETY_HOLD_OPTION, [
            'revision' => self::TERM_LANGUAGE_BOOTSTRAP_REVISION,
            'taxonomies' => $taxonomies,
        ], false);
    }

    private static function clearSafetyHold(): void
    {
        if (function_exists('delete_option')) {
            delete_option(self::TERM_LANGUAGE_SAFETY_HOLD_OPTION);
        }
    }

    /**
     * @param  list<string>  $taxonomies
     * @return array<string, list<WP_Term>>
     */
    private static function termsByTaxonomy(array $taxonomies): array
    {
        $termsByTaxonomy = [];

        foreach ($taxonomies as $taxonomy) {
            // `lang => ''` is Polylang's supported way to request terms from
            // every language, including terms that still have no language.
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'lang' => '',
            ]);

            if (is_wp_error($terms)) {
                throw new RuntimeException(sprintf(
                    'Nie udało się odczytać wartości globalnego atrybutu %s.',
                    $taxonomy,
                ));
            }

            $validatedTerms = [];

            foreach ((array) $terms as $term) {
                if (! $term instanceof WP_Term
                    || $term->taxonomy !== $taxonomy
                    || (int) $term->term_id <= 0
                ) {
                    throw new RuntimeException(sprintf(
                        'WooCommerce zwrócił niepoprawną wartość globalnego atrybutu %s.',
                        $taxonomy,
                    ));
                }

                $validatedTerms[] = $term;
            }

            $termsByTaxonomy[$taxonomy] = $validatedTerms;
        }

        return $termsByTaxonomy;
    }

    /**
     * @param  array<string, list<WP_Term>>  $termsByTaxonomy
     * @return list<string>
     */
    private static function existingTranslationFamilyConflictTaxonomies(array $termsByTaxonomy): array
    {
        $conflicts = [];

        foreach ($termsByTaxonomy as $taxonomy => $terms) {
            $unassignedTermIds = [];

            foreach ($terms as $term) {
                $termId = (int) $term->term_id;

                if (self::languageSlug(pll_get_term_language($termId, 'slug')) === null) {
                    $unassignedTermIds[$termId] = true;
                }
            }

            if ($unassignedTermIds === []) {
                continue;
            }

            foreach ($terms as $term) {
                $translations = pll_get_term_translations((int) $term->term_id);

                if (! is_array($translations)) {
                    throw new RuntimeException(sprintf(
                        'Polylang zwrócił niepoprawną rodzinę tłumaczeń atrybutu %s.',
                        $taxonomy,
                    ));
                }

                $familyIds = [];

                foreach ($translations as $translationId) {
                    if (! is_scalar($translationId)
                        || ! is_numeric((string) $translationId)
                        || (int) $translationId <= 0
                    ) {
                        throw new RuntimeException(sprintf(
                            'Polylang zwrócił niepoprawny identyfikator tłumaczenia atrybutu %s.',
                            $taxonomy,
                        ));
                    }

                    $familyIds[(int) $translationId] = true;
                }

                // A one-element map is Polylang's normal representation of an
                // assigned term without translations. It is not a family and
                // cannot make another, unassigned term ambiguous.
                if (count($familyIds) < 2) {
                    continue;
                }

                if (array_intersect_key($unassignedTermIds, $familyIds) !== []) {
                    $conflicts[] = $taxonomy;

                    break;
                }
            }
        }

        $conflicts = array_values(array_unique($conflicts));
        sort($conflicts, SORT_STRING);

        return $conflicts;
    }

    /** @param list<string> $taxonomies
     * @return list<string>
     */
    private static function notTranslatedTaxonomies(array $taxonomies): array
    {
        $notReady = [];

        foreach ($taxonomies as $taxonomy) {
            if (! taxonomy_exists($taxonomy) || ! pll_is_translated_taxonomy($taxonomy)) {
                $notReady[] = $taxonomy;
            }
        }

        return $notReady;
    }

    private static function bootstrapFunctionsAvailable(): bool
    {
        return self::translationFunctionsAvailable()
            && function_exists('pll_default_language')
            && function_exists('pll_languages_list')
            && function_exists('pll_get_term_translations')
            && function_exists('pll_set_term_language')
            && self::termInspectionFunctionsAvailable()
            && function_exists('get_option')
            && function_exists('update_option');
    }

    private static function translationFunctionsAvailable(): bool
    {
        return function_exists('wc_get_attribute_taxonomy_names')
            && function_exists('taxonomy_exists')
            && function_exists('pll_is_translated_taxonomy');
    }

    private static function termInspectionFunctionsAvailable(): bool
    {
        return function_exists('pll_get_term_language')
            && function_exists('get_terms')
            && function_exists('is_wp_error');
    }

    /**
     * @param  list<string>  $taxonomies
     * @return array{
     *     complete:bool,
     *     count:int,
     *     taxonomies:list<string>,
     *     failed_taxonomies:list<string>
     * }
     */
    private static function unassignedTermState(array $taxonomies): array
    {
        $count = 0;
        $withUnassignedTerms = [];
        $failedTaxonomies = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'lang' => '',
            ]);

            if (is_wp_error($terms)) {
                $failedTaxonomies[] = $taxonomy;

                continue;
            }

            foreach ((array) $terms as $term) {
                if (! $term instanceof WP_Term
                    || $term->taxonomy !== $taxonomy
                    || (int) $term->term_id <= 0
                ) {
                    $failedTaxonomies[] = $taxonomy;

                    break;
                }

                if (self::languageSlug(pll_get_term_language((int) $term->term_id, 'slug')) === null) {
                    $count++;
                    $withUnassignedTerms[] = $taxonomy;
                }
            }
        }

        $withUnassignedTerms = array_values(array_unique($withUnassignedTerms));
        $failedTaxonomies = array_values(array_unique($failedTaxonomies));
        sort($withUnassignedTerms, SORT_STRING);
        sort($failedTaxonomies, SORT_STRING);

        return [
            'complete' => $failedTaxonomies === [],
            'count' => $count,
            'taxonomies' => $withUnassignedTerms,
            'failed_taxonomies' => $failedTaxonomies,
        ];
    }

    /**
     * @param  list<string>  $activeLanguages
     * @param  list<string>  $taxonomySlugs
     */
    private static function languageFromSlug(
        string $slug,
        array $activeLanguages,
        string $defaultLanguage,
        array $taxonomySlugs,
    ): string {
        usort(
            $activeLanguages,
            static fn (string $first, string $second): int => strlen($second) <=> strlen($first),
        );

        foreach ($activeLanguages as $language) {
            $suffix = '-'.$language;

            if (! str_ends_with($slug, $suffix)) {
                continue;
            }

            $baseSlug = substr($slug, 0, -strlen($suffix));

            if ($baseSlug === '') {
                continue;
            }

            // ERP-created localized terms use a deterministic language suffix.
            // Treat the suffix as language metadata only when the same
            // taxonomy contains the unsuffixed base term or another localized
            // sibling. Without that evidence a natural slug such as
            // `model-en` remains in the default language instead of being
            // silently relabelled as English.
            if (in_array($baseSlug, $taxonomySlugs, true)) {
                return $language;
            }

            foreach ($activeLanguages as $siblingLanguage) {
                if ($siblingLanguage !== $language
                    && in_array($baseSlug.'-'.$siblingLanguage, $taxonomySlugs, true)
                ) {
                    return $language;
                }
            }
        }

        return $defaultLanguage;
    }

    private static function languageSlug(mixed $language): ?string
    {
        if (! function_exists('sanitize_key')) {
            return null;
        }

        $language = sanitize_key((string) $language);

        return $language !== '' ? $language : null;
    }

    /**
     * @param  list<string>  $taxonomies
     * @param  list<string>  $notReadyTaxonomies
     */
    private static function writeStatus(
        string $state,
        array $taxonomies,
        array $notReadyTaxonomies = [],
        int $assigned = 0,
    ): void {
        if (! function_exists('update_option')) {
            return;
        }

        update_option(self::TERM_LANGUAGE_BOOTSTRAP_STATUS_OPTION, [
            'revision' => self::TERM_LANGUAGE_BOOTSTRAP_REVISION,
            'state' => $state,
            'taxonomies' => $taxonomies,
            'not_ready_taxonomies' => array_values($notReadyTaxonomies),
            'assigned' => max(0, $assigned),
        ], false);
    }
}
