<?php

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Canonical option rows keyed by both their Polish and English identity.
     *
     * @var array<string, array{pl:string,en:string,order:int}|false>
     */
    private array $sizeOptions = [];

    /** @var array<string, array{pl:string,en:string,order:int}|false> */
    private array $sizeOptionsBySlug = [];

    public function up(): void
    {
        $this->canonicalizeSizeDefinition();

        if (! Schema::hasTable('products')
            || ! Schema::hasTable('product_channel_mappings')
            || ! Schema::hasTable('product_relations')
            || ! Schema::hasTable('sales_channels')
        ) {
            return;
        }

        $this->loadCanonicalSizeOptions();
        $this->normalizeUnmappedAndNonWooCatalog();

        $repair = app(WooOwnedVariantAxisRepairService::class);
        $visited = [];

        ProductChannelMapping::query()
            ->whereHas('salesChannel', fn ($query) => $query
                ->where('type', 'woocommerce')
                ->where('is_active', true))
            ->where(function ($query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->with([
                'product.parentRelations',
                'product.variantChildren.parentRelations',
            ])
            ->orderBy('id')
            ->chunkById(100, function ($mappings) use ($repair, &$visited): void {
                foreach ($mappings as $mapping) {
                    $product = $mapping->product;

                    if (! $product instanceof Product
                        || isset($visited[$product->id])
                    ) {
                        continue;
                    }

                    $visited[$product->id] = true;

                    if (! $repair->isSizeVariantRootCandidate($product)) {
                        continue;
                    }

                    $this->markWooRepairPending((int) $product->id);
                }
            });
    }

    public function down(): void
    {
        // Deliberate no-op. A queued remote-first repair may already have
        // completed and its verified local snapshot must never be reverted.
    }

    /**
     * Normalize only catalog state that is not owned by WooCommerce. Families
     * are always planned and persisted as a unit. A product participating in
     * any variant relation is never subsequently treated as a standalone row.
     */
    private function normalizeUnmappedAndNonWooCatalog(): void
    {
        $rootIds = DB::table('product_relations')
            ->where('relation_type', 'variant')
            ->select('parent_product_id')
            ->distinct()
            ->orderBy('parent_product_id')
            ->pluck('parent_product_id');

        foreach ($rootIds as $rootId) {
            $this->normalizeFamily((int) $rootId);
        }

        DB::table('products')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(200, function ($products): void {
                foreach ($products as $product) {
                    $productId = (int) $product->id;

                    if (DB::table('product_relations')
                        ->where('relation_type', 'variant')
                        ->where(function ($query) use ($productId): void {
                            $query
                                ->where('parent_product_id', $productId)
                                ->orWhere('child_product_id', $productId);
                        })
                        ->exists()
                    ) {
                        continue;
                    }

                    $this->normalizeStandaloneProduct($productId);
                }
            });
    }

    private function normalizeFamily(int $rootId): void
    {
        DB::transaction(function () use ($rootId): void {
            $relations = DB::table('product_relations')
                ->where('parent_product_id', $rootId)
                ->where('relation_type', 'variant')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($relations->isEmpty()
                || DB::table('product_relations')
                    ->where('child_product_id', $rootId)
                    ->where('relation_type', 'variant')
                    ->exists()
            ) {
                return;
            }

            $childIds = $relations
                ->pluck('child_product_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            if ($childIds->count() !== $relations->count()
                || DB::table('product_relations')
                    ->whereIn('child_product_id', $childIds)
                    ->where('relation_type', 'variant')
                    ->where('parent_product_id', '!=', $rootId)
                    ->exists()
                || DB::table('product_relations')
                    ->whereIn('parent_product_id', $childIds)
                    ->where('relation_type', 'variant')
                    ->exists()
            ) {
                // Nested or shared variant graphs cannot be normalized without
                // potentially changing the meaning of another family.
                return;
            }

            $productIds = collect([$rootId])->merge($childIds)->values();
            $products = DB::table('products')
                ->whereIn('id', $productIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (object $product): int => (int) $product->id);

            if ($products->count() !== $productIds->count()
                || $this->hasAnyWooMapping($productIds->all())
            ) {
                return;
            }

            $parent = $products->get($rootId);

            if (! is_object($parent)) {
                return;
            }

            $children = $relations->map(function (object $relation) use ($products): array {
                return [
                    'product' => $products->get((int) $relation->child_product_id),
                    'relation' => $relation,
                ];
            });

            if ($children->contains(fn (array $row): bool => ! is_object($row['product']))) {
                return;
            }

            $plan = $this->familyPlan($parent, $children->all());

            if ($plan === null) {
                return;
            }

            foreach ($plan['products'] as $productId => $attributes) {
                DB::table('products')
                    ->where('id', $productId)
                    ->update(['attributes' => $this->encodeJson($attributes)]);
            }

            foreach ($plan['relations'] as $relationId => $metadata) {
                DB::table('product_relations')
                    ->where('id', $relationId)
                    ->update(['metadata' => $this->encodeJson($metadata)]);
            }
        });
    }

    private function normalizeStandaloneProduct(int $productId): void
    {
        DB::transaction(function () use ($productId): void {
            $product = DB::table('products')
                ->where('id', $productId)
                ->lockForUpdate()
                ->first();

            if (! is_object($product)
                || DB::table('product_relations')
                    ->where('relation_type', 'variant')
                    ->where(function ($query) use ($productId): void {
                        $query
                            ->where('parent_product_id', $productId)
                            ->orWhere('child_product_id', $productId);
                    })
                    ->exists()
                || $this->hasAnyWooMapping([$productId])
            ) {
                return;
            }

            $attributes = $this->decodeJson($product->attributes ?? null);
            $master = data_get($attributes, 'master', []);

            if (! is_array($master)) {
                return;
            }

            $productType = mb_strtolower(trim((string) data_get(
                $master,
                'product_type',
                '',
            )));
            $axis = $this->axisPlan($master, $productType !== 'variation');

            if ($axis === null) {
                return;
            }

            $declaredKind = $this->axisKind((string) data_get(
                $master,
                'variant_attribute',
                '',
            ));
            $parameters = array_values((array) data_get($master, 'parameters', []));
            $hasVariationParameter = collect($axis['candidate_indices'])->contains(
                fn (int $index): bool => (bool) data_get(
                    $parameters[$index] ?? [],
                    'variation',
                    false,
                ),
            );

            if ($declaredKind === null
                && ! $hasVariationParameter
                && ! in_array($productType, ['variable', 'variation'], true)
            ) {
                // A simple standalone item may legitimately expose Size as an
                // informational parameter. It is not a variant axis.
                return;
            }

            $options = $axis['options'];

            if ($axis['generic'] && $options === []) {
                return;
            }

            $master = $this->canonicalMaster($master, $axis, $this->orderedOptions($options));
            data_set($attributes, 'master', $master);

            if ($attributes !== $this->decodeJson($product->attributes ?? null)) {
                DB::table('products')
                    ->where('id', $productId)
                    ->update(['attributes' => $this->encodeJson($attributes)]);
            }
        });
    }

    /**
     * @param  list<array{product:object,relation:object}>  $children
     * @return array{products:array<int,array<string,mixed>>,relations:array<int,array<string,mixed>>}|null
     */
    private function familyPlan(object $parent, array $children): ?array
    {
        $parentAttributes = $this->decodeJson($parent->attributes ?? null);
        $parentMaster = data_get($parentAttributes, 'master', []);

        if (! is_array($parentMaster)) {
            return null;
        }

        $parentAxis = $this->axisPlan($parentMaster, true);

        if ($parentAxis === null) {
            return null;
        }

        $childPlans = [];
        $childKeys = [];

        foreach ($children as $row) {
            $child = $row['product'];
            $relation = $row['relation'];
            $attributes = $this->decodeJson($child->attributes ?? null);
            $master = data_get($attributes, 'master', []);
            $metadata = $this->decodeJson($relation->metadata ?? null);

            if (! is_array($master)) {
                return null;
            }

            $axis = $this->axisPlan($master, false);
            $relationAttribute = trim((string) data_get($metadata, 'variant_attribute', ''));
            $relationKind = $this->axisKind($relationAttribute);
            $relationRawOption = trim((string) data_get($metadata, 'variant_option', ''));

            if ($relationAttribute !== '' && $relationKind === null) {
                return null;
            }

            if ($relationKind === 'generic'
                && ($relationRawOption === '' || ! $this->isProvenSizeOption($relationRawOption))
            ) {
                return null;
            }

            if ($axis === null) {
                return null;
            }

            $options = $axis['options'];

            if (count($options) > 1) {
                return null;
            }

            $option = $options[0] ?? null;
            $relationOption = $relationRawOption === ''
                ? null
                : $this->canonicalOption($relationRawOption);

            if ($option !== null
                && $relationOption !== null
                && $this->optionKey($option) !== $this->optionKey($relationOption)
            ) {
                return null;
            }

            $option ??= $relationOption;

            if ($option === null || trim($option) === '') {
                return null;
            }

            if (($axis['generic'] || $relationKind === 'generic')
                && ! $this->isProvenSizeOption($option)
            ) {
                return null;
            }

            $key = $this->optionKey($option);

            if ($key === '' || isset($childKeys[$key])) {
                // A one-axis family cannot safely contain two children with
                // the same option. That would conceal a second variation axis.
                return null;
            }

            $childKeys[$key] = true;
            $childPlans[] = [
                'product' => $child,
                'attributes' => $attributes,
                'master' => $master,
                'axis' => $axis,
                'relation' => $relation,
                'metadata' => $metadata,
                'option' => $option,
            ];
        }

        if ($childPlans === []) {
            return null;
        }

        $parentOptions = $parentAxis['options'];
        $parentKeys = collect($parentOptions)
            ->map(fn (string $option): string => $this->optionKey($option))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $expectedKeys = array_keys($childKeys);

        sort($parentKeys);
        sort($expectedKeys);

        if (($parentAxis['generic'] && $parentOptions === [])
            || ($parentOptions !== [] && $parentKeys !== $expectedKeys)
        ) {
            return null;
        }

        $optionsByKey = collect($parentOptions)
            ->mapWithKeys(fn (string $option): array => [$this->optionKey($option) => $option]);

        foreach ($childPlans as $childPlan) {
            $optionsByKey->put(
                $this->optionKey($childPlan['option']),
                $this->canonicalOption($childPlan['option']),
            );
        }

        $orderedOptions = $this->orderedOptions($optionsByKey->values()->all());
        $parentMaster = $this->canonicalMaster($parentMaster, $parentAxis, $orderedOptions);
        data_set($parentAttributes, 'master', $parentMaster);
        $productUpdates = [(int) $parent->id => $parentAttributes];
        $relationUpdates = [];

        foreach ($childPlans as $childPlan) {
            $option = $this->canonicalOption($childPlan['option']);
            $childMaster = $this->canonicalMaster(
                $childPlan['master'],
                $childPlan['axis'],
                [$option],
            );
            data_set($childPlan['attributes'], 'master', $childMaster);
            $productUpdates[(int) $childPlan['product']->id] = $childPlan['attributes'];
            $metadata = $childPlan['metadata'];
            data_set($metadata, 'variant_attribute', 'Rozmiar');
            data_set($metadata, 'variant_option', $option);
            $relationUpdates[(int) $childPlan['relation']->id] = $metadata;
        }

        return [
            'products' => $productUpdates,
            'relations' => $relationUpdates,
        ];
    }

    /**
     * @param  array<string, mixed>  $master
     * @return array{candidate_indices:list<int>,base_index:?int,options:list<string>,generic:bool}|null
     */
    private function axisPlan(array $master, bool $parent): ?array
    {
        $declared = trim((string) data_get($master, 'variant_attribute', ''));
        $declaredKind = $this->axisKind($declared);

        if ($declared !== '' && $declaredKind === null) {
            return null;
        }

        $parameters = array_values((array) data_get($master, 'parameters', []));
        $candidates = [];
        $optionSets = [];
        $generic = $declaredKind === 'generic';

        foreach ($parameters as $index => $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = trim((string) ($parameter['name'] ?? ''));
            $kind = $this->axisKind($name);
            $isVariation = (bool) ($parameter['variation'] ?? false);

            if ($kind === null) {
                if ($isVariation) {
                    return null;
                }

                continue;
            }

            $lists = $this->parameterOptionLists($parameter);
            $primary = $lists[0] ?? [];

            if ($kind === 'generic') {
                if ($lists === []
                    || collect($lists)->contains(fn (array $options): bool => $options === []
                        || ! collect($options)->every(
                            fn (string $option): bool => $this->isProvenSizeOption($option),
                        ))
                    || ! $this->optionListsAgree($lists)
                ) {
                    if ($isVariation || $this->sameAxisName($name, $declared)) {
                        return null;
                    }

                    // An informational generic colour parameter is unrelated
                    // to an explicitly declared direct size axis and is kept.
                    continue;
                }

                $generic = true;
            }

            $candidates[] = $index;

            if ($primary !== []) {
                $optionSets[] = collect($primary)
                    ->map(fn (string $option): string => $this->optionKey($option))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
            }
        }

        if ($candidates === []) {
            if ($declaredKind !== 'direct') {
                return null;
            }

            return [
                'candidate_indices' => [],
                'base_index' => null,
                'options' => [],
                'generic' => false,
            ];
        }

        if ($optionSets !== []) {
            $firstSet = $optionSets[0];

            foreach ($optionSets as $set) {
                if ($set !== $firstSet) {
                    return null;
                }
            }
        }

        $baseIndex = collect($candidates)->sortBy(function (int $index) use ($parameters): string {
            $parameter = $parameters[$index];
            $kind = $this->axisKind((string) ($parameter['name'] ?? ''));
            $slug = $this->axisSlug((string) ($parameter['name'] ?? ''));

            return sprintf(
                '%d-%d-%010d',
                $slug === 'rozmiar' ? 0 : ($kind === 'direct' ? 1 : 2),
                (bool) ($parameter['variation'] ?? false) ? 0 : 1,
                $index,
            );
        })->first();
        $baseLists = is_int($baseIndex)
            ? $this->parameterOptionLists($parameters[$baseIndex])
            : [];
        $options = $baseLists[0] ?? [];

        if (! $parent && count($options) > 1) {
            return null;
        }

        return [
            'candidate_indices' => array_values($candidates),
            'base_index' => is_int($baseIndex) ? $baseIndex : null,
            'options' => array_values($options),
            'generic' => $generic,
        ];
    }

    /**
     * @param  array<string, mixed>  $master
     * @param  array{candidate_indices:list<int>,base_index:?int,options:list<string>,generic:bool}  $axis
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function canonicalMaster(array $master, array $axis, array $options): array
    {
        $master['variant_attribute'] = 'Rozmiar';
        $parameters = array_values((array) data_get($master, 'parameters', []));

        if ($axis['candidate_indices'] === [] && $options === []) {
            return $master;
        }

        $base = [];

        foreach ($axis['candidate_indices'] as $candidateIndex) {
            if (is_array($parameters[$candidateIndex] ?? null)) {
                $base = array_replace_recursive($base, $parameters[$candidateIndex]);
            }
        }

        if ($axis['base_index'] !== null
            && is_array($parameters[$axis['base_index']] ?? null)
        ) {
            // The preferred exact `Rozmiar`/direct row wins conflicts, while
            // non-conflicting operator metadata from duplicate rows survives.
            $base = array_replace_recursive($base, $parameters[$axis['base_index']]);
        }

        $base = $this->canonicalParameter($base, $options);
        $candidateLookup = array_fill_keys($axis['candidate_indices'], true);
        $insertionIndex = $axis['candidate_indices'] === []
            ? count($parameters)
            : min($axis['candidate_indices']);
        $normalized = [];
        $inserted = false;

        foreach ($parameters as $index => $parameter) {
            if ($index === $insertionIndex) {
                $normalized[] = $base;
                $inserted = true;
            }

            if (isset($candidateLookup[$index])) {
                continue;
            }

            $normalized[] = $parameter;
        }

        if (! $inserted) {
            $normalized[] = $base;
        }

        $master['parameters'] = $normalized;

        return $master;
    }

    /**
     * @param  array<string, mixed>  $parameter
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function canonicalParameter(array $parameter, array $options): array
    {
        $polish = collect($options)
            ->map(fn (string $option): string => $this->canonicalOption($option))
            ->implode(' | ');
        $english = collect($options)
            ->map(fn (string $option): string => $this->localizedOption($option, 'en'))
            ->implode(' | ');

        $parameter['name'] = 'Rozmiar';
        $parameter['name_en'] = 'Size';
        $parameter['value'] = $polish;
        $parameter['variation'] = true;

        if (array_key_exists('slug', $parameter)) {
            $parameter['slug'] = 'rozmiar';
        }

        if (array_key_exists('value_pl', $parameter)) {
            $parameter['value_pl'] = $polish;
        }

        if (array_key_exists('value_en', $parameter)) {
            $parameter['value_en'] = $english;
        }

        foreach (['pl' => ['Rozmiar', $polish], 'en' => ['Size', $english]] as $language => $localized) {
            if (data_get($parameter, "translations.{$language}.name") !== null) {
                data_set($parameter, "translations.{$language}.name", $localized[0]);
            }

            if (data_get($parameter, "translations.{$language}.value") !== null) {
                data_set($parameter, "translations.{$language}.value", $localized[1]);
            }
        }

        return $parameter;
    }

    /**
     * Return each independently stored localized option list. Every populated
     * list must describe the same canonical options before a generic axis can
     * be treated as size.
     *
     * @param  array<string, mixed>  $parameter
     * @return list<list<string>>
     */
    private function parameterOptionLists(array $parameter): array
    {
        $rawLists = collect([
            $parameter['value'] ?? null,
            $parameter['value_pl'] ?? null,
            data_get($parameter, 'translations.pl.value'),
            $parameter['value_en'] ?? null,
            data_get($parameter, 'translations.en.value'),
        ]);
        $lists = [];

        foreach ($rawLists as $raw) {
            $options = $this->optionTokens($raw);

            if ($options === []) {
                continue;
            }

            $lists[] = collect($options)
                ->map(fn (string $option): string => $this->canonicalOption($option))
                ->values()
                ->all();
        }

        return $lists;
    }

    /** @param list<list<string>> $lists */
    private function optionListsAgree(array $lists): bool
    {
        $sets = collect($lists)->map(fn (array $options): array => collect($options)
            ->map(fn (string $option): string => $this->optionKey($option))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all());

        return $sets->every(fn (array $set): bool => $set === $sets->first());
    }

    /** @return list<string> */
    private function optionTokens(mixed $raw): array
    {
        if (is_array($raw)) {
            return collect($raw)
                ->flatMap(fn (mixed $value): array => $this->optionTokens($value))
                ->filter()
                ->unique(fn (string $value): string => $this->optionKey($value))
                ->values()
                ->all();
        }

        if (! is_scalar($raw) || trim((string) $raw) === '') {
            return [];
        }

        return collect(preg_split(
            '/(?<!\d),|,(?!\d)|[\r\n;|]+/u',
            trim((string) $raw),
        ) ?: [])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique(fn (string $value): string => $this->optionKey($value))
            ->values()
            ->all();
    }

    private function isProvenSizeOption(string $option): bool
    {
        return is_array($this->sizeOptions[$this->optionIdentity($option)] ?? null)
            || is_array($this->sizeOptionsBySlug[$this->optionSlug($option)] ?? null)
            || $this->looksLikeSize($option);
    }

    private function canonicalOption(string $option): string
    {
        $option = trim($option);
        $known = $this->knownSizeOption($option);

        if (is_array($known)) {
            return $known['pl'];
        }

        $compact = (string) preg_replace('/\s*([\/-])\s*/u', '$1', $option);

        if (preg_match(
            '/^(?:[2-9]xl|[2-6]xs|x{1,6}[sl]|[sml])(?:[\/-](?:[2-9]xl|[2-6]xs|x{1,6}[sl]|[sml]))*$/iu',
            $compact,
        ) === 1) {
            return mb_strtoupper($compact, 'UTF-8');
        }

        return $option;
    }

    private function localizedOption(string $option, string $language): string
    {
        $known = $this->knownSizeOption($option);

        return is_array($known) && $language === 'en'
            ? $known['en']
            : $this->canonicalOption($option);
    }

    /** @return array{pl:string,en:string,order:int}|null */
    private function knownSizeOption(string $option): ?array
    {
        $known = $this->sizeOptions[$this->optionIdentity($option)] ?? null;

        if (is_array($known)) {
            return $known;
        }

        $bySlug = $this->sizeOptionsBySlug[$this->optionSlug($option)] ?? null;

        return is_array($bySlug) ? $bySlug : null;
    }

    /** @param list<string> $options @return list<string> */
    private function orderedOptions(array $options): array
    {
        return collect($options)
            ->map(fn (string $option, int $index): array => [
                'option' => $this->canonicalOption($option),
                'index' => $index,
                'order' => $this->knownSizeOption($option)['order'] ?? null,
            ])
            ->unique(fn (array $row): string => $this->optionKey($row['option']))
            ->sort(function (array $left, array $right): int {
                if ($left['order'] === null && $right['order'] === null) {
                    return $left['index'] <=> $right['index'];
                }

                if ($left['order'] === null) {
                    return 1;
                }

                if ($right['order'] === null) {
                    return -1;
                }

                return $left['order'] <=> $right['order']
                    ?: $left['index'] <=> $right['index'];
            })
            ->pluck('option')
            ->values()
            ->all();
    }

    private function loadCanonicalSizeOptions(): void
    {
        if (! Schema::hasTable('product_parameter_definitions')) {
            return;
        }

        $definitions = DB::table('product_parameter_definitions')
            ->orderBy('id')
            ->get();
        $definition = $definitions
            ->filter(fn (object $candidate): bool => $this->isSizeDefinition($candidate))
            ->sortBy(fn (object $candidate): string => sprintf(
                '%02d-%010d',
                $this->sizeDefinitionPriority($candidate),
                (int) $candidate->id,
            ))
            ->first();

        if (! is_object($definition)) {
            return;
        }

        $polish = $this->decodeList($definition->values ?? null);
        $english = $this->decodeList($definition->values_en ?? null);

        foreach ($polish as $index => $value) {
            $pl = trim((string) $value);

            if ($pl === '') {
                continue;
            }

            $en = trim((string) ($english[$index] ?? $pl));
            $row = ['pl' => $pl, 'en' => $en !== '' ? $en : $pl, 'order' => $index];

            foreach ([$pl, $row['en']] as $localized) {
                $identity = $this->optionIdentity($localized);

                if ($identity !== '') {
                    if (! array_key_exists($identity, $this->sizeOptions)) {
                        $this->sizeOptions[$identity] = $row;
                    } elseif ($this->sizeOptions[$identity] !== $row) {
                        $this->sizeOptions[$identity] = false;
                    }
                }

                $slug = $this->optionSlug($localized);

                if ($slug === '') {
                    continue;
                }

                if (! array_key_exists($slug, $this->sizeOptionsBySlug)) {
                    $this->sizeOptionsBySlug[$slug] = $row;
                } elseif ($this->sizeOptionsBySlug[$slug] !== $row) {
                    // A slug collision is not sufficient evidence.
                    $this->sizeOptionsBySlug[$slug] = false;
                }
            }
        }
    }

    /** @return list<mixed> */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @param list<int> $productIds */
    private function hasAnyWooMapping(array $productIds): bool
    {
        if ($productIds === []) {
            return false;
        }

        if (DB::table('product_channel_mappings')
            ->join('sales_channels', 'sales_channels.id', '=', 'product_channel_mappings.sales_channel_id')
            ->whereIn('product_channel_mappings.product_id', $productIds)
            ->whereRaw('LOWER(TRIM(sales_channels.type)) = ?', ['woocommerce'])
            ->exists()
        ) {
            return true;
        }

        if (! Schema::hasTable('product_channel_aliases')) {
            return false;
        }

        return DB::table('product_channel_aliases')
            ->join('sales_channels', 'sales_channels.id', '=', 'product_channel_aliases.sales_channel_id')
            ->where(function ($query) use ($productIds): void {
                $query
                    ->whereIn('product_channel_aliases.product_id', $productIds)
                    ->orWhereIn('product_channel_aliases.source_product_id', $productIds);
            })
            ->whereRaw('LOWER(TRIM(sales_channels.type)) = ?', ['woocommerce'])
            ->exists();
    }

    /**
     * Queue active Woo mappings without Eloquent writes. This deliberately
     * changes only the maintenance envelope inside mapping metadata.
     */
    private function markWooRepairPending(int $productId): void
    {
        DB::transaction(function () use ($productId): void {
            $mappings = DB::table('product_channel_mappings')
                ->join('sales_channels', 'sales_channels.id', '=', 'product_channel_mappings.sales_channel_id')
                ->where('product_channel_mappings.product_id', $productId)
                ->whereRaw('LOWER(TRIM(sales_channels.type)) = ?', ['woocommerce'])
                ->where('sales_channels.is_active', true)
                ->where(function ($query): void {
                    $query
                        ->whereNull('product_channel_mappings.external_variation_id')
                        ->orWhereIn('product_channel_mappings.external_variation_id', ['', '0'])
                        ->orWhereRaw("TRIM(product_channel_mappings.external_variation_id) = ''");
                })
                ->select([
                    'product_channel_mappings.id',
                    'product_channel_mappings.metadata',
                ])
                ->orderBy('product_channel_mappings.id')
                ->lockForUpdate()
                ->get();

            foreach ($mappings as $mapping) {
                $metadata = $this->decodeJson($mapping->metadata ?? null);
                $state = (array) data_get(
                    $metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                    [],
                );

                if (($state['revision'] ?? null) === WooOwnedVariantAxisRepairService::REVISION
                    && in_array(
                        ($state['status'] ?? null),
                        ['pending', 'queued', 'completed', 'manual_review'],
                        true,
                    )
                ) {
                    continue;
                }

                data_set($metadata, WooOwnedVariantAxisRepairService::STATE_PATH, [
                    'revision' => WooOwnedVariantAxisRepairService::REVISION,
                    'status' => 'pending',
                    'requested_at' => now()->toISOString(),
                ]);

                DB::table('product_channel_mappings')
                    ->where('id', $mapping->id)
                    ->update(['metadata' => $this->encodeJson($metadata)]);
            }
        });
    }

    private function axisKind(string $attribute): ?string
    {
        $slug = $this->axisSlug($attribute);

        if (in_array($slug, ['rozmiar', 'rozmiary', 'size', 'sizes'], true)) {
            return 'direct';
        }

        if (in_array($slug, ['wariant', 'variant', 'blvariant', 'bl-variant', 'bl-wariant'], true)) {
            return 'generic';
        }

        return null;
    }

    private function axisSlug(string $attribute): string
    {
        $slug = Str::slug(trim($attribute));

        return str_starts_with($slug, 'pa-') ? substr($slug, 3) : $slug;
    }

    private function sameAxisName(string $left, string $right): bool
    {
        return $left !== '' && $right !== '' && $this->axisSlug($left) === $this->axisSlug($right);
    }

    private function optionIdentity(string $option): string
    {
        $option = (string) preg_replace('/\s*([\/-])\s*/u', '$1', trim($option));
        $option = (string) preg_replace('/\s+/u', ' ', $option);

        return mb_strtolower($option, 'UTF-8');
    }

    private function optionSlug(string $option): string
    {
        return Str::slug(str_replace('/', '-', trim($option)));
    }

    private function optionKey(string $option): string
    {
        $known = $this->knownSizeOption($option);

        return $known === null
            ? 'value:'.$this->optionIdentity($this->canonicalOption($option))
            : 'dictionary:'.$known['order'];
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return (array) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Promote one existing Size dictionary to the canonical Polish identity
     * without model events. Its value arrays and sort_order are deliberately
     * never rewritten: their stored sequence is the operator-owned order.
     */
    private function canonicalizeSizeDefinition(): void
    {
        if (! Schema::hasTable('product_parameter_definitions')) {
            return;
        }

        $hasNameEn = Schema::hasColumn('product_parameter_definitions', 'name_en');
        $hasValuesEn = Schema::hasColumn('product_parameter_definitions', 'values_en');
        $definitions = DB::table('product_parameter_definitions')
            ->orderBy('id')
            ->get();
        $source = $definitions
            ->filter(fn (object $definition): bool => $this->isSizeDefinition($definition))
            ->sortBy(fn (object $definition): string => sprintf(
                '%02d-%010d',
                $this->sizeDefinitionPriority($definition),
                (int) $definition->id,
            ))
            ->first();

        if (is_object($source)) {
            $updates = ['is_variant' => true];

            if ($this->canUseValue($definitions, 'name', 'Rozmiar', (int) $source->id)) {
                $updates['name'] = 'Rozmiar';
            }

            if ($this->canUseValue($definitions, 'slug', 'rozmiar', (int) $source->id)) {
                $updates['slug'] = 'rozmiar';
            }

            if ($hasNameEn
                && $this->canUseValue($definitions, 'name_en', 'Size', (int) $source->id)
            ) {
                $updates['name_en'] = 'Size';
            }

            $changed = collect($updates)->contains(
                fn (mixed $value, string $column): bool => $column === 'is_variant'
                    ? (bool) $source->{$column} !== (bool) $value
                    : trim((string) $source->{$column}) !== (string) $value,
            );

            if ($changed) {
                if (Schema::hasColumn('product_parameter_definitions', 'updated_at')) {
                    $updates['updated_at'] = now();
                }

                DB::table('product_parameter_definitions')
                    ->where('id', $source->id)
                    ->update($updates);
            }

            return;
        }

        // A pristine installation has no catalog to repair. Do not seed a
        // phantom dictionary there; create it only for existing size data.
        if (! $this->hasCatalogSizeAxisEvidence()) {
            return;
        }

        $now = now();
        $row = [
            'name' => 'Rozmiar',
            'slug' => 'rozmiar',
            'input_type' => 'select',
            'values' => null,
            'is_variant' => true,
            'is_required' => false,
            'sort_order' => 100,
            'metadata' => null,
        ];

        if ($hasNameEn) {
            $row['name_en'] = 'Size';
        }

        if ($hasValuesEn) {
            $row['values_en'] = null;
        }

        if (Schema::hasColumn('product_parameter_definitions', 'created_at')) {
            $row['created_at'] = $now;
        }

        if (Schema::hasColumn('product_parameter_definitions', 'updated_at')) {
            $row['updated_at'] = $now;
        }

        DB::table('product_parameter_definitions')->insert($row);
    }

    private function hasCatalogSizeAxisEvidence(): bool
    {
        if (! Schema::hasTable('products')) {
            return false;
        }

        foreach (DB::table('products')->select(['id', 'attributes'])->orderBy('id')->lazyById() as $row) {
            $attributes = is_string($row->attributes)
                ? json_decode($row->attributes, true)
                : $row->attributes;
            $master = data_get(is_array($attributes) ? $attributes : [], 'master', []);

            if (! is_array($master)) {
                continue;
            }

            $parameters = collect((array) data_get($master, 'parameters', []))
                ->filter(fn (mixed $parameter): bool => is_array($parameter));
            $names = collect([data_get($master, 'variant_attribute')])
                ->merge($parameters->pluck('name'))
                ->filter();

            if ($names->contains(fn (mixed $name): bool => $this->axisKind(
                (string) $name,
            ) === 'direct')) {
                return true;
            }

            $genericValues = $parameters
                ->filter(fn (array $parameter): bool => $this->axisKind(
                    (string) ($parameter['name'] ?? ''),
                ) === 'generic')
                ->flatMap(fn (array $parameter): array => $this->optionTokens(
                    $parameter['value'] ?? null,
                ))
                ->values();

            if ($genericValues->isNotEmpty()
                && $genericValues->every(fn (string $value): bool => $this->looksLikeSize($value))
            ) {
                return true;
            }
        }

        return false;
    }

    private function isSizeDefinition(object $definition): bool
    {
        $names = [
            $definition->name ?? null,
            $definition->name_en ?? null,
            $definition->slug ?? null,
        ];

        return collect($names)
            ->filter(fn (mixed $name): bool => is_scalar($name) && trim((string) $name) !== '')
            ->contains(fn (mixed $name): bool => in_array(
                Str::slug(trim((string) $name)),
                ['rozmiar', 'rozmiary', 'size', 'sizes'],
                true,
            ));
    }

    private function sizeDefinitionPriority(object $definition): int
    {
        $name = Str::slug(trim((string) ($definition->name ?? '')));
        $slug = Str::slug(trim((string) ($definition->slug ?? '')));

        return match (true) {
            $name === 'rozmiar' && $slug === 'rozmiar' => 0,
            $name === 'rozmiar' => 1,
            $slug === 'rozmiar' => 2,
            $name === 'size' && $slug === 'size' => 3,
            $name === 'size' || $slug === 'size' => 4,
            default => 5,
        };
    }

    private function canUseValue(
        iterable $definitions,
        string $column,
        string $value,
        int $exceptId,
    ): bool {
        return ! collect($definitions)->contains(function (object $definition) use (
            $column,
            $value,
            $exceptId,
        ): bool {
            return (int) $definition->id !== $exceptId
                && mb_strtolower(trim((string) ($definition->{$column} ?? '')))
                    === mb_strtolower($value);
        });
    }

    private function looksLikeSize(string $value): bool
    {
        $value = mb_strtolower(trim((string) preg_replace('/\s+/u', '', $value)));
        $value = str_replace(['–', '—'], '-', $value);

        if (in_array($value, [
            'onesize',
            'one-size',
            'uni',
            'uniwersalny',
            'uniwersalna',
        ], true)) {
            return true;
        }

        $letter = '(?:[2-9]xl|[2-6]xs|x{1,6}[sl]|[sml])';

        return preg_match('/^'.$letter.'(?:[\/-]'.$letter.')*$/iu', $value) === 1
            || preg_match('/^\d{1,3}(?:[.,]5)?(?:[\/-]\d{1,3}(?:[.,]5)?)*$/u', $value) === 1;
    }
};
