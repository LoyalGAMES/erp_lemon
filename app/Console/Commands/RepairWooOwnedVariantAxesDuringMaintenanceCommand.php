<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WooCommerce\WooOwnedVariantAxisDeploymentGate;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Illuminate\Console\Command;

final class RepairWooOwnedVariantAxesDuringMaintenanceCommand extends Command
{
    protected $signature = 'erp:repair-woo-owned-variant-axes-during-maintenance';

    protected $description = 'Synchronously finish current Woo size-axis repairs while deployment maintenance isolates the catalog.';

    public function handle(WooOwnedVariantAxisDeploymentGate $gate): int
    {
        if (! app()->isDownForMaintenance()) {
            $this->error(
                'Synchronous Woo variant-axis repair is allowed only while the application is in maintenance mode.',
            );

            return self::FAILURE;
        }

        $result = $gate->runSynchronously();

        foreach ($result['results'] as $family) {
            $line = sprintf(
                'Woo variant-axis family %d: %s.',
                $family['product_id'],
                $family['status'],
            );

            if (isset($family['error'])) {
                $this->error($line.' '.$family['error']);
            } else {
                $this->line($line);
            }
        }

        $postcondition = $result['postcondition'];
        $this->info(sprintf(
            'Woo variant-axis deployment repair: revision=%s, candidates=%d, processed=%d, skipped=%d, exceptions=%d, mappings=%d, families=%d, statuses=%s, unresolved=%d.',
            WooOwnedVariantAxisRepairService::REVISION,
            $result['candidates'],
            $result['processed'],
            $result['skipped'],
            $result['exceptions'],
            $postcondition['mappings'],
            $postcondition['families'],
            $this->statusSummary($postcondition['statuses']),
            $postcondition['unresolved_mappings'],
        ));

        if (! $postcondition['passed']) {
            $this->error(
                'Unresolved current-revision Woo variant-axis families: '
                .implode(',', $postcondition['unresolved_families']).'.',
            );
        }

        return $result['exceptions'] === 0 && $postcondition['passed']
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @param array<string, int> $statuses */
    private function statusSummary(array $statuses): string
    {
        if ($statuses === []) {
            return '-';
        }

        return collect($statuses)
            ->map(fn (int $count, string $status): string => "{$status}={$count}")
            ->implode(',');
    }
}
