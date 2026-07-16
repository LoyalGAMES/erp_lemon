<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use App\Models\ProductParameterDefinition;
use App\Services\Products\ProductVariantOptionNormalizer;
use App\Services\WooCommerce\WooCommerceSizeDictionaryOrder;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class ProductParameterDefinitionObserver implements ShouldHandleEventsAfterCommit
{
    private const ORDER_FIELDS = [
        'name',
        'name_en',
        'slug',
        'values',
        'values_en',
        'is_variant',
    ];

    public function __construct(
        private readonly ProductVariantOptionNormalizer $variantOptions,
        private readonly WooCommerceSizeDictionaryOrder $sizeOrder,
    ) {}

    public function saved(ProductParameterDefinition $definition): void
    {
        if (! $definition->wasChanged(self::ORDER_FIELDS)) {
            return;
        }

        $previous = array_replace(
            $definition->getAttributes(),
            $definition->getPrevious(),
        );

        if (! $this->affectsSizeUnion($definition->getAttributes(), $definition)
            && ! $this->affectsSizeUnion($previous)
        ) {
            return;
        }

        $this->dispatchSync();
    }

    public function deleted(ProductParameterDefinition $definition): void
    {
        $deleted = array_replace(
            $definition->getAttributes(),
            $definition->getOriginal(),
        );

        if (! $this->affectsSizeUnion($deleted)) {
            return;
        }

        $this->dispatchSync();
    }

    /** @param array<string,mixed> $attributes */
    private function affectsSizeUnion(
        array $attributes,
        ?ProductParameterDefinition $definition = null,
    ): bool {
        $names = collect([
            $attributes['name'] ?? null,
            $attributes['name_en'] ?? null,
            $attributes['slug'] ?? null,
        ])
            ->map(fn (mixed $name): string => trim((string) $name))
            ->filter();

        if ($names->contains(fn (string $name): bool => $this->variantOptions
            ->isSizeAttribute($name))) {
            return true;
        }

        $values = $attributes['values'] ?? [];

        if (is_string($values)) {
            $values = json_decode($values, true) ?: [];
        }

        return $names->contains(fn (string $name): bool => $this->sizeOrder->isSizeAxis(
            $name,
            is_array($values) ? $values : [],
            $definition,
        ));
    }

    private function dispatchSync(): void
    {
        SyncWooCommerceGlobalSizeOrderJob::dispatchForActiveIntegrations(
            'erp_size_dictionary_changed',
        );
    }
}
