<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use App\Models\ProductParameterDefinition;
use App\Services\Products\ProductVariantOptionNormalizer;
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
    ) {}

    public function saved(ProductParameterDefinition $definition): void
    {
        if (! $definition->wasChanged(self::ORDER_FIELDS)) {
            return;
        }

        $previous = $definition->getPrevious();
        $names = collect([
            $definition->name,
            $definition->name_en,
            $definition->slug,
            $previous['name'] ?? null,
            $previous['name_en'] ?? null,
            $previous['slug'] ?? null,
        ]);

        if (! $names->contains(fn (mixed $name): bool => $this->variantOptions
            ->isSizeAttribute((string) $name))) {
            return;
        }

        SyncWooCommerceGlobalSizeOrderJob::dispatchForActiveIntegrations(
            'erp_size_dictionary_changed',
        );
    }
}
