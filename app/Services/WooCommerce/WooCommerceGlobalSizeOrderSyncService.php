<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\ProductChannelAlias;
use App\Models\ProductChannelMapping;
use App\Models\ProductParameterDefinition;
use App\Models\ProductRelation;
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
        private readonly WooCommerceSizeDictionaryOrder $sizeOrder,
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
        $plan = $this->preflightPlan($languages, $termBuckets);
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

        $this->assertNoUsedTermsOutsideDictionary($termBuckets, $plan, $attributeId);

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
        return $this->sizeOrder
            ->definitions()
            ->filter(fn (ProductParameterDefinition $definition): bool => collect($definition->values)
                ->contains(fn (mixed $value): bool => trim((string) $value) !== ''))
            ->values();
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
        $attributes = collect($this->client->globalProductAttributesByNames(
            $integration,
            $names
                ->map(fn (string $name): string => trim($name))
                ->filter()
                ->unique(fn (string $name): string => mb_strtolower($name))
                ->values()
                ->all(),
        ));

        if ($attributes->isEmpty()) {
            throw new RuntimeException(
                'WooCommerce nie zawiera istniejącego globalnego atrybutu Rozmiar/Size.',
            );
        }

        if ($attributes->count() === 1) {
            return $attributes->first();
        }

        return $this->sizeAttributeUsedByMappedVariantFamilies($integration, $attributes);
    }

    /**
     * A label, slug, term count or the lowest Woo ID cannot prove which of
     * several historical Size-like taxonomies is the live variation axis.
     * Use only ERP-mapped parent families and the remote `variation=true`
     * contract. A catalog split across candidate IDs remains a hard stop.
     *
     * @param  Collection<int, array<string, mixed>>  $attributes
     * @return array<string, mixed>
     */
    private function sizeAttributeUsedByMappedVariantFamilies(
        WordpressIntegration $integration,
        Collection $attributes,
    ): array {
        $parentProductIds = ProductRelation::query()
            ->where('relation_type', 'variant')
            ->distinct()
            ->pluck('parent_product_id');
        $externalProductIds = ProductChannelMapping::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->whereIn('product_id', $parentProductIds)
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->orderBy('id')
            ->pluck('external_product_id')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter(fn (string $id): bool => ctype_digit($id) && (int) $id > 0)
            ->values();
        $aliasExternalProductIds = ProductChannelAlias::query()
            ->where('sales_channel_id', $integration->sales_channel_id)
            ->where(function ($query) use ($parentProductIds): void {
                $query
                    ->whereIn('product_id', $parentProductIds)
                    ->orWhereIn('source_product_id', $parentProductIds);
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductChannelAlias $alias): bool => $alias->isOutboundSyncEnabled())
            ->pluck('external_product_id')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter(fn (string $id): bool => ctype_digit($id) && (int) $id > 0)
            ->values();
        $externalProductIds = $externalProductIds
            ->merge($aliasExternalProductIds)
            ->unique()
            ->values();

        if ($externalProductIds->isEmpty()) {
            throw new RuntimeException(
                'WooCommerce zawiera kilka globalnych atrybutów Rozmiar/Size, ale ERP nie ma zmapowanej rodziny wariantowej, która pozwala bezpiecznie wybrać oś.',
            );
        }

        $candidateIds = $attributes
            ->map(fn (array $attribute): int => (int) ($attribute['id'] ?? 0))
            ->filter()
            ->unique()
            ->values();
        $usedAxisIds = collect($this->client->productsByIds(
            $integration,
            $externalProductIds->all(),
        ))
            ->filter(fn (array $product): bool => mb_strtolower(
                trim((string) ($product['type'] ?? '')),
            ) === 'variable')
            ->flatMap(fn (array $product): Collection => collect((array) ($product['attributes'] ?? []))
                ->filter(fn (mixed $attribute): bool => is_array($attribute)
                    && $this->attributeDrivesVariations($attribute))
                ->map(fn (array $attribute): int => (int) ($attribute['id'] ?? 0))
                ->filter(fn (int $attributeId): bool => $candidateIds->contains($attributeId)))
            ->unique()
            ->values();

        if ($usedAxisIds->count() !== 1) {
            throw new RuntimeException($usedAxisIds->isEmpty()
                ? 'WooCommerce zawiera kilka globalnych atrybutów Rozmiar/Size, ale żaden nie jest jednoznacznie osią zmapowanych rodzin wariantowych ERP.'
                : 'Zmapowane rodziny wariantowe ERP używają kilku globalnych atrybutów Rozmiar/Size; automatyczna zmiana kolejności została przerwana.');
        }

        $selectedId = (int) $usedAxisIds->first();

        return $attributes->first(
            fn (array $attribute): bool => (int) ($attribute['id'] ?? 0) === $selectedId,
        );
    }

    /** @param array<string, mixed> $attribute */
    private function attributeDrivesVariations(array $attribute): bool
    {
        return $this->truthy($attribute['variation'] ?? false)
            || $this->truthy($attribute['has_variations'] ?? false);
    }

    private function truthy(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
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
        Collection $languages,
        Collection $termBuckets,
    ): Collection {
        $plan = collect();
        $assignedTermIds = [];

        foreach ($languages as $language) {
            $entries = $this->dictionaryEntries($language);
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
    private function dictionaryEntries(string $language): Collection
    {
        $attributeNames = $this->sizeOrder
            ->definitions()
            ->flatMap(fn (ProductParameterDefinition $definition): array => [
                (string) $definition->name,
                $definition->nameForLanguage($language),
                (string) $definition->slug,
            ])
            ->push('Rozmiar')
            ->push('Size')
            ->filter()
            ->unique()
            ->values();
        $entries = $this->sizeOrder
            ->entries($language)
            ->map(function (array $pair) use (
                $attributeNames,
                $language,
            ): array {
                $localizedValue = $pair['localized'];
                $values = collect([
                    ...$pair['source_aliases'],
                    ...$pair['localized_aliases'],
                ])->unique()->values();
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
                    'index' => $pair['index'],
                    'name' => $localizedValue,
                    'menu_order' => $pair['menu_order'],
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
     * Enabling menu_order while a used, unmatched term remains at zero would
     * move that size to the beginning of listings. Treat every such term as a
     * catalog-contract violation before the first remote mutation.
     *
     * @param  Collection<string,Collection<int,array<string,mixed>>>  $termBuckets
     * @param  Collection<int,array{term:array<string,mixed>,name:string,menu_order:int,language:string,index:int}>  $plan
     */
    private function assertNoUsedTermsOutsideDictionary(
        Collection $termBuckets,
        Collection $plan,
        int $attributeId,
    ): void {
        $assignedIds = $plan
            ->map(fn (array $entry): int => (int) ($entry['term']['id'] ?? 0))
            ->filter()
            ->flip();
        $outside = $termBuckets
            ->flatMap(fn (Collection $terms): Collection => $terms)
            ->filter(fn (array $term): bool => (int) ($term['id'] ?? 0) > 0
                && ! $assignedIds->has((int) $term['id'])
                && (! array_key_exists('count', $term) || (int) $term['count'] > 0))
            ->unique(fn (array $term): int => (int) $term['id'])
            ->values();

        if ($outside->isEmpty()) {
            return;
        }

        $terms = $outside
            ->map(fn (array $term): string => sprintf(
                '#%d `%s`',
                (int) $term['id'],
                trim((string) ($term['name'] ?? $term['slug'] ?? '')),
            ))
            ->implode(', ');

        throw new RuntimeException(
            "Globalny atrybut Rozmiar/Size #{$attributeId} zawiera używane wartości spoza słownika ERP: {$terms}.",
        );
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
