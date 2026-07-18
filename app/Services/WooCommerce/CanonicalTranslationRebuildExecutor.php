<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Jobs\ExportWooCommerceProductDataJob;
use RuntimeException;

class CanonicalTranslationRebuildExecutor
{
    public function __construct(
        private readonly ProductDataExportService $exporter,
        private readonly WooOwnedVariantAxisRepairService $repair,
    ) {}

    public function run(int $productId, string $syncToken): void
    {
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException(
                'A synchronous translated-variation rebuild requires maintenance mode.',
            );
        }

        // The deployment has restarted the queue and waited for every worker,
        // while maintenance blocks web catalog writers. Execute the existing
        // token rather than allocating a competing export. A queued copy with
        // the same token becomes a harmless no-op after this handler clears it.
        (new ExportWooCommerceProductDataJob($productId, $syncToken))->handle(
            $this->exporter,
            $this->repair,
        );
    }
}
