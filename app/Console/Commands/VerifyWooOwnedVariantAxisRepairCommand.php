<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WooCommerce\WooOwnedVariantAxisDeploymentGate;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Console\Command;

final class VerifyWooOwnedVariantAxisRepairCommand extends Command
{
    protected $signature = 'erp:verify-woo-owned-variant-axis-repair';

    protected $description = 'Fail unless every mapping from the current Woo size-axis repair revision is cleanly completed.';

    public function handle(WooOwnedVariantAxisDeploymentGate $gate): int
    {
        $result = $gate->postcondition();
        $statuses = $result['statuses'] === []
            ? '-'
            : collect($result['statuses'])
                ->map(fn (int $count, string $status): string => "{$status}={$count}")
                ->implode(',');

        $this->info(sprintf(
            'Woo variant-axis postcondition: revision=%s, mappings=%d, families=%d, statuses=%s, unresolved=%d, unresolved_families=%s.',
            WooOwnedVariantAxisRepairService::REVISION,
            $result['mappings'],
            $result['families'],
            $statuses,
            $result['unresolved_mappings'],
            $result['unresolved_families'] === []
                ? '-'
                : implode(',', $result['unresolved_families']),
        ));

        return $result['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
