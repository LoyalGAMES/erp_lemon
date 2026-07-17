<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Models\ProductParameterDefinition;
use App\Services\Products\ProductVariantAxisNameResolver;
use App\Services\Products\ProductVariantOptionNormalizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

final class WooCommerceSizeDictionaryOrder
{
    public function __construct(
        private readonly ProductVariantOptionNormalizer $variantOptions,
        private readonly ProductVariantAxisNameResolver $variantAxisNames,
    ) {}

    /** @return Collection<int, ProductParameterDefinition> */
    public function definitions(): Collection
    {
        $definitions = ProductParameterDefinition::query()
            ->orderBy('id')
            ->get();
        $direct = $definitions->filter(fn (ProductParameterDefinition $definition): bool => collect([
            $definition->name,
            $definition->name_en,
            $definition->slug,
        ])->filter()->contains(
            fn (mixed $name): bool => $this->variantOptions->isSizeAttribute((string) $name),
        ));
        $knownOptions = $direct
            ->flatMap(fn (ProductParameterDefinition $definition): array => [
                ...(array) $definition->values,
                ...(array) $definition->values_en,
            ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => $this->key($value))
            ->values();

        return $definitions
            ->filter(function (ProductParameterDefinition $definition) use (
                $direct,
                $knownOptions,
            ): bool {
                if ($direct->contains(fn (ProductParameterDefinition $candidate): bool => $candidate->is($definition))) {
                    return true;
                }

                return collect([
                    $definition->name,
                    $definition->name_en,
                    $definition->slug,
                ])
                    ->map(fn (mixed $name): string => trim((string) $name))
                    ->filter()
                    ->contains(fn (string $name): bool => $this->variantAxisNames->resolve(
                        $name,
                        (array) $definition->values,
                        $knownOptions,
                    ) === ProductVariantAxisNameResolver::SIZE);
            })
            ->sortBy(function (ProductParameterDefinition $definition): string {
                $name = $this->attributeKey((string) $definition->name);
                $slug = $this->attributeKey((string) $definition->slug);
                $isDirect = collect([
                    $definition->name,
                    $definition->name_en,
                    $definition->slug,
                ])->filter()->contains(
                    fn (mixed $candidate): bool => $this->variantAxisNames
                        ->isDirectSizeAlias((string) $candidate),
                );
                $priority = match (true) {
                    $name === 'rozmiar' && $slug === 'rozmiar' => 0,
                    $name === 'rozmiar' || $slug === 'rozmiar' => 1,
                    $isDirect => 2,
                    default => 3,
                };

                return sprintf('%02d-%010d', $priority, (int) $definition->id);
            })
            ->values();
    }

    /**
     * The first row in canonical priority supplies the spelling written by
     * ERP. Later legacy dictionaries remain read aliases, so an existing Woo
     * term can be found and renamed without letting that legacy spelling win.
     *
     * @return Collection<int,array{source:string,localized:string,source_aliases:list<string>,localized_aliases:list<string>,menu_order:int,index:int}>
     */
    public function entries(string $language = 'pl'): Collection
    {
        $language = mb_strtolower(trim($language)) ?: 'pl';
        $rows = $this->definitions()
            ->flatMap(function (ProductParameterDefinition $definition) use ($language): Collection {
                $localized = collect($definition->valuesForLanguage($language))->values();
                $polish = collect($definition->valuesForLanguage('pl'))->values();
                $english = collect($definition->valuesForLanguage('en'))->values();

                return collect((array) $definition->values)
                    ->values()
                    ->map(function (mixed $value, int $index) use (
                        $localized,
                        $polish,
                        $english,
                    ): array {
                        $source = trim((string) $value);

                        return [
                            'source' => $source,
                            'localized' => trim((string) $localized->get($index, '')) ?: $source,
                            'all_localized' => collect([
                                $polish->get($index),
                                $english->get($index),
                            ])
                                ->map(fn (mixed $candidate): string => trim((string) $candidate))
                                ->filter()
                                ->unique(fn (string $candidate): string => $this->aliasIdentity($candidate))
                                ->values()
                                ->all(),
                        ];
                    });
            })
            ->filter(fn (array $row): bool => $row['source'] !== '')
            ->values()
            ->map(fn (array $row, int $index): array => [
                ...$row,
                'identity' => $this->key($row['source']),
                'source_index' => $index,
            ])
            ->filter(fn (array $row): bool => $row['identity'] !== '');

        $rows = $this->coalesceEquivalentRows($rows);
        $this->assertUnambiguousRows($rows);

        $rows = $rows
            ->groupBy('identity')
            ->map(function (Collection $aliases): array {
                /** @var array{source:string,localized:string,identity:string,source_index:int} $canonical */
                $canonical = $aliases->first();

                return [
                    'source' => $canonical['source'],
                    'localized' => $canonical['localized'],
                    'source_aliases' => $aliases
                        ->pluck('source')
                        ->map(fn (mixed $value): string => trim((string) $value))
                        ->filter()
                        ->unique(fn (string $value): string => $this->aliasIdentity($value))
                        ->values()
                        ->all(),
                    'localized_aliases' => $aliases
                        ->flatMap(fn (array $row): array => [
                            $row['localized'],
                            ...(array) ($row['all_localized'] ?? []),
                        ])
                        ->map(fn (mixed $value): string => trim((string) $value))
                        ->filter()
                        ->unique(fn (string $value): string => $this->aliasIdentity($value))
                        ->values()
                        ->all(),
                    'source_index' => $canonical['source_index'],
                ];
            })
            // The order configured in ERP is authoritative. Do not silently
            // rearrange familiar labels (for example S/M before M/L): users
            // may deliberately configure a different commercial sequence,
            // and Woo terms, parent options and children must all mirror it.
            ->sortBy('source_index')
            ->values();

        return $rows->map(fn (array $row, int $index): array => [
            'source' => $row['source'],
            'localized' => $row['localized'],
            'source_aliases' => $row['source_aliases'],
            'localized_aliases' => $row['localized_aliases'],
            'menu_order' => ($index + 1) * 10,
            'index' => $index,
        ]);
    }

    public function localizedOption(string $option, string $language = 'pl'): ?string
    {
        $identity = $this->key($option);

        if ($identity === '') {
            return null;
        }

        $entry = $this->entries($language)->first(
            fn (array $candidate): bool => collect([
                ...$candidate['source_aliases'],
                ...$candidate['localized_aliases'],
            ])->contains(fn (string $alias): bool => $this->key($alias) === $identity),
        );

        return is_array($entry) ? $entry['localized'] : null;
    }

    public function isSizeAxis(
        string $attributeName,
        iterable $options = [],
        ?ProductParameterDefinition $definition = null,
    ): bool {
        $definitions = $this->definitions();

        if ($definition instanceof ProductParameterDefinition
            && $definitions->contains(
                fn (ProductParameterDefinition $candidate): bool => $candidate->is($definition),
            )
        ) {
            return true;
        }

        $knownOptions = $definitions
            ->flatMap(fn (ProductParameterDefinition $candidate): array => [
                ...(array) $candidate->values,
                ...(array) $candidate->values_en,
            ]);

        return $this->variantAxisNames->resolve(
            $attributeName,
            $options,
            $knownOptions,
        ) === ProductVariantAxisNameResolver::SIZE;
    }

    /**
     * @param  list<string>  $options
     * @return list<int>
     */
    public function menuOrders(array $options): array
    {
        $orders = [];

        foreach ($this->entries() as $entry) {
            foreach ([...$entry['source_aliases'], ...$entry['localized_aliases']] as $alias) {
                // PHP/Laravel reindexes numeric keys during flatMap/collapse.
                // Build the lookup explicitly so clothing sizes such as `36`
                // keep their dictionary identity and menu order.
                $orders[$this->key($alias)] = $entry['menu_order'];
            }
        }

        $missing = collect($options)
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter(fn (string $option): bool => $option !== '')
            ->first(fn (string $option): bool => ! array_key_exists(
                $this->key($option),
                $orders,
            ));

        if (is_string($missing)) {
            throw new RuntimeException(
                "Wartość rozmiaru `{$missing}` nie istnieje w żadnym słowniku rozmiarów ERP.",
            );
        }

        return collect($options)
            ->map(fn (mixed $option): int => $orders[$this->key((string) $option)])
            ->all();
    }

    public function key(string $value): string
    {
        $value = (string) preg_replace('/\s+/u', ' ', trim($value));
        $compact = mb_strtolower((string) preg_replace('/[\s\/\-–—]+/u', '', $value));

        if ($compact === 'onesize') {
            return 'one-size';
        }

        $value = preg_replace(
            '/\s*(?:\/|-|–|—)\s*/u',
            '/',
            $value,
        ) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }

    private function aliasIdentity(string $value): string
    {
        return mb_strtolower(
            (string) preg_replace('/\s+/u', ' ', trim($value)),
            'UTF-8',
        );
    }

    /**
     * Historical dictionaries can use a translated label as their source
     * value (for example `Medium/Large`) while another dictionary owns the
     * canonical source `M/L`. A shared semantic alias proves that these rows
     * describe one option; merge them into the first, canonical owner before
     * checking for genuine multi-owner conflicts and Woo slug collisions.
     *
     * @param  Collection<int,array{source:string,localized:string,all_localized:list<string>,identity:string,source_index:int}>  $rows
     * @return Collection<int,array{source:string,localized:string,all_localized:list<string>,identity:string,source_index:int}>
     */
    private function coalesceEquivalentRows(Collection $rows): Collection
    {
        $owners = [];

        return $rows->map(function (array $row) use (&$owners): array {
            $sourceKey = $this->key((string) $row['source']);
            $localizedAliases = collect([
                $row['localized'],
                ...$row['all_localized'],
            ])
                ->map(fn (mixed $alias): string => trim((string) $alias))
                ->filter()
                ->map(fn (string $alias): string => $this->key($alias))
                ->filter()
                ->unique()
                ->values();
            $matchedOwners = $localizedAliases
                ->map(fn (string $alias): ?string => $owners[$alias] ?? null)
                ->filter()
                ->unique()
                ->values();
            $sourceOwner = $owners[$sourceKey] ?? null;

            // An already known source value is authoritative. Historical
            // value_en arrays were sometimes reordered independently from
            // values, making e.g. source `S` point at localized `M/L`. Such a
            // translation must not move S into another canonical identity.
            $owner = (string) ($sourceOwner
                ?? ($matchedOwners->count() === 1
                    ? $matchedOwners->first()
                    : $row['identity']));
            $safeLocalizedAliases = $localizedAliases
                ->filter(function (string $alias) use ($owners, $owner): bool {
                    $aliasOwner = $owners[$alias] ?? null;

                    return $aliasOwner === null || $aliasOwner === $owner;
                })
                ->values();
            $localizedKey = $this->key((string) $row['localized']);

            if (! $safeLocalizedAliases->contains($localizedKey)) {
                $row['localized'] = $row['source'];
            }

            $row['all_localized'] = collect($row['all_localized'])
                ->filter(fn (mixed $alias): bool => $safeLocalizedAliases->contains(
                    $this->key((string) $alias),
                ))
                ->values()
                ->all();
            $row['identity'] = $owner;
            $owners[$sourceKey] = $owner;

            foreach ($safeLocalizedAliases as $alias) {
                $owners[$alias] = $owner;
            }

            return $row;
        });
    }

    /**
     * @param  Collection<int,array{source:string,localized:string,all_localized:list<string>,identity:string,source_index:int}>  $rows
     */
    private function assertUnambiguousRows(Collection $rows): void
    {
        $semanticOwners = [];
        $slugOwners = [];

        foreach ($rows as $row) {
            $owner = $row['identity'];
            $aliases = collect([
                $row['source'],
                $row['localized'],
                ...$row['all_localized'],
            ])
                ->map(fn (mixed $alias): string => trim((string) $alias))
                ->filter()
                ->unique()
                ->values();

            foreach ($aliases as $alias) {
                $semantic = $this->key($alias);

                if (isset($semanticOwners[$semantic]) && $semanticOwners[$semantic] !== $owner) {
                    throw new RuntimeException(
                        "Słownik rozmiarów ERP zawiera niejednoznaczną wartość `{$alias}`.",
                    );
                }

                $semanticOwners[$semantic] = $owner;

                foreach ($this->wooSlugs($alias) as $slug) {
                    if (isset($slugOwners[$slug]) && $slugOwners[$slug] !== $owner) {
                        throw new RuntimeException(
                            "Słownik rozmiarów ERP zawiera wartości o kolidującym slugu WooCommerce `{$slug}`.",
                        );
                    }

                    $slugOwners[$slug] = $owner;
                }
            }
        }
    }

    /** @return list<string> */
    private function wooSlugs(string $value): array
    {
        $separatorAware = (string) preg_replace(
            '/\s*(?:\/|-|–|—)\s*/u',
            '-',
            trim($value),
        );

        return collect([
            Str::slug($value),
            Str::slug($separatorAware),
        ])->filter()->unique()->values()->all();
    }

    private function attributeKey(string $value): string
    {
        $slug = Str::slug(trim($value));

        return str_starts_with($slug, 'pa-') ? substr($slug, 3) : $slug;
    }
}
