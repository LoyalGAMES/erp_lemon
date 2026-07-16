<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\ProductParameterDefinition;
use App\Models\WordpressIntegration;
use App\Services\Products\ProductVariantOptionNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

final class WooCommerceGlobalSizeOrderSyncService
{
    public function __construct(
        private readonly WooCommerceClient $client,
        private readonly ProductVariantOptionNormalizer $variantOptions,
    ) {}

    /**
     * Synchronize only an existing Size taxonomy and existing terms. The full
     * language/term plan is validated before the first PUT; nothing creates a
     * taxonomy, term, product, variation or Polylang relationship.
     *
     * @return array{status:string,attribute_id?:int,languages:int,matched_terms:int,updated_terms:int,renamed_terms:int}
     */
    public function sync(WordpressIntegration $integration): array
    {
        $definitions = $this->sizeDefinitions();

        if ($definitions->isEmpty()) {
            return [
                'status' => 'skipped_no_size_definition',
                'languages' => 0,
                'matched_terms' => 0,
                'updated_terms' => 0,
                'renamed_terms' => 0,
            ];
        }

        $attribute = $this->existingSizeAttribute($integration, $definitions);
        $definition = $this->sizeDefinitionForAttribute($definitions, $attribute);
        $attributeId = (int) $attribute['id'];
        $languages = collect($integration->productExportLanguages())
            ->map(fn (mixed $language): string => mb_strtolower(trim((string) $language)) ?: 'pl')
            // ProductParameterDefinition currently has dedicated localized
            // names and values only for Polish and English. Treating another
            // language as Polish would rename otherwise valid foreign terms.
            ->filter(fn (string $language): bool => in_array($language, ['pl', 'en'], true))
            ->prepend('pl')
            ->unique()
            ->values();
        $termBuckets = $languages->mapWithKeys(fn (string $language): array => [
            $language => collect($this->client->globalProductAttributeTermsById(
                $integration,
                $attributeId,
                $language,
            ))
                ->filter(fn (mixed $term): bool => is_array($term) && (int) ($term['id'] ?? 0) > 0)
                ->unique(fn (array $term): int => (int) $term['id'])
                ->values(),
        ]);
        $plan = $this->preflightPlan($definition, $languages, $termBuckets);
        $languagesWithoutMatches = $languages
            ->reject(fn (string $language): bool => $plan->contains(
                fn (array $entry): bool => $entry['language'] === $language,
            ))
            ->values();

        if ($languagesWithoutMatches->isNotEmpty()) {
            throw new RuntimeException(
                sprintf(
                    'Istniejący globalny atrybut #%d nie zawiera wartości jednoznacznie pasujących do słownika rozmiarów ERP dla języka: %s.',
                    $attributeId,
                    $languagesWithoutMatches->map(fn (string $language): string => mb_strtoupper($language))->implode(', '),
                ),
            );
        }

        // Mutate only after every language and duplicate candidate passed the
        // preflight above. This prevents a later ambiguity from leaving half
        // of the global taxonomy changed.
        $updated = 0;
        $renamed = 0;

        foreach ($plan as $entry) {
            $term = $entry['term'];
            $needsRename = trim((string) ($term['name'] ?? '')) !== $entry['name'];
            $needsOrder = ! array_key_exists('menu_order', $term)
                || (int) $term['menu_order'] !== $entry['menu_order'];

            if (! $needsRename && ! $needsOrder) {
                continue;
            }

            $this->client->updateExistingGlobalProductAttributeTerm(
                $integration,
                $attributeId,
                $term,
                $entry['name'],
                $entry['menu_order'],
            );
            $updated++;
            $renamed += (int) $needsRename;
        }

        // Make menu_order authoritative only after all term writes succeed.
        // If a remote term PUT fails, a taxonomy that still sorts by name is
        // not left visibly using a half-written order.
        $this->client->setExistingGlobalProductAttributeMenuOrder($integration, $attribute);

        return [
            'status' => 'synchronized',
            'attribute_id' => $attributeId,
            'languages' => $languages->count(),
            'matched_terms' => $plan->count(),
            'updated_terms' => $updated,
            'renamed_terms' => $renamed,
        ];
    }

    /** @return Collection<int, ProductParameterDefinition> */
    private function sizeDefinitions(): Collection
    {
        return ProductParameterDefinition::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductParameterDefinition $definition): bool => collect([
                $definition->name,
                $definition->name_en,
                $definition->slug,
            ])->contains(fn (mixed $name): bool => $this->variantOptions
                ->isSizeAttribute((string) $name)))
            ->filter(fn (ProductParameterDefinition $definition): bool => collect($definition->values)
                ->contains(fn (mixed $value): bool => trim((string) $value) !== ''))
            ->values();
    }

    /**
     * Historical Woo imports can leave a separate `Size` dictionary next to
     * the canonical Polish `Rozmiar` row. The Woo taxonomy is still singular,
     * so select the ERP row whose source name/slug identifies that concrete
     * taxonomy. A localized name is only a fallback; genuinely competing
     * direct matches remain a hard stop before any remote mutation.
     *
     * @param  Collection<int, ProductParameterDefinition>  $definitions
     * @param  array<string, mixed>  $attribute
     */
    private function sizeDefinitionForAttribute(
        Collection $definitions,
        array $attribute,
    ): ProductParameterDefinition {
        $attributeKeys = collect([
            $attribute['name'] ?? null,
            $attribute['slug'] ?? null,
        ])
            ->map(fn (mixed $value): string => $this->attributeKey((string) $value))
            ->filter()
            ->unique()
            ->values();
        $directMatches = $definitions
            ->filter(fn (ProductParameterDefinition $definition): bool => collect([
                $definition->name,
                $definition->slug,
            ])
                ->map(fn (mixed $value): string => $this->attributeKey((string) $value))
                ->filter()
                ->intersect($attributeKeys)
                ->isNotEmpty())
            ->values();

        if ($directMatches->count() === 1) {
            return $directMatches->first();
        }

        if ($directMatches->count() > 1) {
            throw new RuntimeException(sprintf(
                'ERP zawiera kilka słowników bezpośrednio pasujących do globalnego atrybutu Rozmiar/Size #%d.',
                (int) ($attribute['id'] ?? 0),
            ));
        }

        $localizedMatches = $definitions
            ->filter(fn (ProductParameterDefinition $definition): bool => $attributeKeys->contains(
                $this->attributeKey((string) $definition->name_en),
            ))
            ->values();

        if ($localizedMatches->count() === 1) {
            return $localizedMatches->first();
        }

        if ($definitions->count() === 1) {
            return $definitions->first();
        }

        throw new RuntimeException(sprintf(
            'ERP zawiera kilka słowników Rozmiar/Size, ale żaden nie odpowiada jednoznacznie globalnemu atrybutowi #%d.',
            (int) ($attribute['id'] ?? 0),
        ));
    }

    /**
     * @param  Collection<int, ProductParameterDefinition>  $definitions
     * @return array<string, mixed>
     */
    private function existingSizeAttribute(
        WordpressIntegration $integration,
        Collection $definitions,
    ): array {
        $names = $definitions
            ->flatMap(fn (ProductParameterDefinition $definition): array => [
                (string) $definition->name,
                (string) $definition->name_en,
                (string) $definition->slug,
            ])
            ->push('Rozmiar', 'Size');
        $attributes = $names
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->map(fn (string $name): ?array => $this->client
                ->globalProductAttributeByName($integration, $name))
            ->filter(fn (mixed $attribute): bool => is_array($attribute)
                && (int) ($attribute['id'] ?? 0) > 0)
            ->unique(fn (array $attribute): int => (int) $attribute['id'])
            ->values();

        if ($attributes->count() !== 1) {
            throw new RuntimeException($attributes->isEmpty()
                ? 'WooCommerce nie zawiera istniejącego globalnego atrybutu Rozmiar/Size.'
                : 'WooCommerce zawiera kilka globalnych atrybutów Rozmiar/Size.');
        }

        return $attributes->first();
    }

    private function attributeKey(string $value): string
    {
        $key = Str::slug(trim($value));

        return str_starts_with($key, 'pa-') ? substr($key, 3) : $key;
    }

    /**
     * @param  Collection<int,string>  $languages
     * @param  Collection<string,Collection<int,array<string,mixed>>>  $termBuckets
     * @return Collection<int,array{term:array<string,mixed>,name:string,menu_order:int,language:string,index:int}>
     */
    private function preflightPlan(
        ProductParameterDefinition $definition,
        Collection $languages,
        Collection $termBuckets,
    ): Collection {
        $plan = collect();
        $assignedTermIds = [];

        foreach ($languages as $language) {
            $entries = $this->dictionaryEntries($definition, $language);
            /** @var Collection<int,array<string,mixed>> $terms */
            $terms = $termBuckets->get($language, collect());

            foreach ($entries as $entry) {
                $matches = $terms
                    ->filter(fn (array $term): bool => $this->termMatchesEntry(
                        $term,
                        $entry,
                        $language,
                        $languages,
                    ))
                    ->values();

                if ($matches->count() > 1) {
                    // Interrupted historical exports may have left one unused
                    // duplicate next to the term actually assigned to the
                    // catalog. Woo exposes the usage count on this endpoint;
                    // a single used candidate is the only safe tie-breaker.
                    $usedMatches = $matches
                        ->filter(fn (array $term): bool => is_numeric($term['count'] ?? null)
                            && (int) $term['count'] > 0)
                        ->values();

                    if ($usedMatches->count() === 1) {
                        $matches = $usedMatches;
                    }
                }

                if ($matches->count() > 1) {
                    throw new RuntimeException(sprintf(
                        'WooCommerce zawiera kilka wartości %s języka %s globalnego atrybutu; kolejność nie zostanie zmieniona.',
                        $entry['name'],
                        mb_strtoupper($language),
                    ));
                }

                if ($matches->isEmpty()) {
                    continue;
                }

                $term = $matches->first();
                $termId = (int) $term['id'];

                if (isset($assignedTermIds[$termId])) {
                    throw new RuntimeException(
                        "Wartość #{$termId} globalnego atrybutu pasuje do kilku pozycji lub języków słownika ERP.",
                    );
                }

                $assignedTermIds[$termId] = true;
                $plan->push([
                    'term' => $term,
                    'name' => $entry['name'],
                    'menu_order' => $entry['menu_order'],
                    'language' => $language,
                    'index' => $entry['index'],
                ]);
            }
        }

        return $plan;
    }

    /**
     * @return Collection<int,array{index:int,name:string,menu_order:int,identities:list<string>,base_slugs:list<string>,localized_slugs:list<string>}>
     */
    private function dictionaryEntries(
        ProductParameterDefinition $definition,
        string $language,
    ): Collection {
        $sourceValues = collect((array) $definition->values)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->values();
        $localizedValues = collect($definition->valuesForLanguage($language));
        $attributeNames = collect([
            (string) $definition->name,
            $definition->nameForLanguage($language),
            'Rozmiar',
            'Size',
        ])->filter()->unique()->values();
        $entries = $sourceValues
            ->map(function (string $sourceValue, int $index) use (
                $localizedValues,
                $attributeNames,
                $language,
            ): ?array {
                if ($sourceValue === '') {
                    return null;
                }

                $localizedValue = trim((string) $localizedValues->get($index, '')) ?: $sourceValue;
                $values = collect([$sourceValue, $localizedValue])->unique()->values();
                $identities = $values
                    ->flatMap(fn (string $value): Collection => $attributeNames->map(
                        fn (string $attributeName): string => $this->variantOptions
                            ->identity($attributeName, $value),
                    ))
                    ->filter()
                    ->unique()
                    ->values();
                $baseSlugs = $values->flatMap(fn (string $value): array => [
                    // Woo has used both `sm` (plain WordPress slugification)
                    // and `s-m` (legacy size-axis recovery) for `S/M`.
                    // They identify the same dictionary option here.
                    Str::slug($value),
                    $this->sizeOptionKey($value),
                ])
                    ->filter()
                    ->unique()
                    ->values();
                $languageSlugs = $baseSlugs
                    ->map(fn (string $slug): string => $slug.'-'.Str::slug($language));
                $localizedSlugs = $language === 'pl'
                    // Historical source terms used an unsuffixed slug while
                    // newer exports deliberately use `-pl`. Both identities
                    // belong to Polish; foreign suffixes never do.
                    ? $baseSlugs->merge($languageSlugs)->unique()->values()
                    : $languageSlugs;

                return [
                    'index' => $index,
                    'name' => $localizedValue,
                    'menu_order' => ($index + 1) * 10,
                    'identities' => $identities->all(),
                    'base_slugs' => $baseSlugs->all(),
                    'localized_slugs' => $localizedSlugs->all(),
                ];
            })
            ->filter()
            ->values();

        $identityOwners = [];

        foreach ($entries as $entry) {
            foreach ([...$entry['identities'], ...$entry['base_slugs']] as $identity) {
                if (isset($identityOwners[$identity]) && $identityOwners[$identity] !== $entry['index']) {
                    throw new RuntimeException(
                        "Słownik rozmiarów ERP zawiera niejednoznaczną wartość {$entry['name']}.",
                    );
                }

                $identityOwners[$identity] = $entry['index'];
            }
        }

        return $entries;
    }

    /**
     * @param  array<string,mixed>  $term
     * @param  array{identities:list<string>,base_slugs:list<string>,localized_slugs:list<string>}  $entry
     * @param  Collection<int,string>  $configuredLanguages
     */
    private function termMatchesEntry(
        array $term,
        array $entry,
        string $language,
        Collection $configuredLanguages,
    ): bool {
        $termId = (int) ($term['id'] ?? 0);
        $name = trim((string) ($term['name'] ?? ''));
        $slug = Str::slug((string) ($term['slug'] ?? ''));
        $nameKey = $this->sizeOptionKey($name);
        $identities = collect(['Rozmiar', 'Size'])
            ->map(fn (string $attributeName): string => $this->variantOptions
                ->identity($attributeName, $name))
            ->filter();
        $semanticMatch = $identities->contains(fn (string $identity): bool => in_array(
            $identity,
            $entry['identities'],
            true,
        )) || in_array($nameKey, $entry['base_slugs'], true)
            || in_array($slug, [...$entry['base_slugs'], ...$entry['localized_slugs']], true);

        if (! $semanticMatch || $termId <= 0) {
            return false;
        }

        $explicitLanguage = $this->explicitTermLanguage($term);

        if ($explicitLanguage !== null) {
            return $explicitLanguage === $language;
        }

        if ($language === 'pl') {
            $foreignSuffixes = $configuredLanguages
                ->reject(fn (string $candidate): bool => $candidate === 'pl')
                ->map(fn (string $candidate): string => '-'.Str::slug($candidate));

            return in_array($slug, $entry['localized_slugs'], true)
                && ! $foreignSuffixes->contains(fn (string $suffix): bool => str_ends_with($slug, $suffix));
        }

        return in_array($slug, $entry['localized_slugs'], true);
    }

    /** @param array<string,mixed> $term */
    private function explicitTermLanguage(array $term): ?string
    {
        $language = mb_strtolower(trim((string) ($term['lang'] ?? $term['language'] ?? '')));
        $termId = (int) ($term['id'] ?? 0);
        $translationLanguages = collect((array) ($term['translations'] ?? []))
            ->filter(fn (mixed $id): bool => (int) $id === $termId)
            ->keys()
            ->map(fn (mixed $candidate): string => mb_strtolower(trim((string) $candidate)))
            ->filter()
            ->unique()
            ->values();

        if ($translationLanguages->count() > 1
            || ($language !== ''
                && $translationLanguages->isNotEmpty()
                && ! $translationLanguages->contains($language))
        ) {
            throw new RuntimeException(
                "Wartość #{$termId} globalnego atrybutu ma sprzeczną tożsamość językową Polylang.",
            );
        }

        return $language !== '' ? $language : $translationLanguages->first();
    }

    private function sizeOptionKey(string $value): string
    {
        // Preserve the semantic separator before slugification. Laravel turns
        // `S/M` into `sm`, while historical Woo terms use `s-m`.
        $value = (string) preg_replace('/\s*(?:\/|[-–—])\s*/u', '-', trim($value));

        return Str::slug($value);
    }
}
