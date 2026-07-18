<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\WooCommerce\WooLegacyVariantAxisRemoteAuditService;
use Illuminate\Console\Command;
use Throwable;

final class AuditWooLegacyVariantAxesDuringMaintenanceCommand extends Command
{
    protected $signature = 'erp:audit-woo-legacy-variant-axes-during-maintenance
        {--external-id= : Include only one exact WooCommerce parent ID}
        {--limit=20 : Maximum number of detailed rows}';

    protected $description = 'Audit every live Woo wariant/BLVariant axis independently of local ERP candidate mappings.';

    public function handle(WooLegacyVariantAxisRemoteAuditService $audit): int
    {
        if (! app()->isDownForMaintenance()) {
            $this->error('Zdalny audyt historycznych osi wariantów wymaga trybu maintenance.');

            return self::FAILURE;
        }

        try {
            $result = $audit->audit((string) ($this->option('external-id') ?? ''));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Zdalny audyt historycznych osi wariantów nie powiódł się: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Woo legacy-axis remote audit: integrations=%d, attributes=%d, remote_products=%d, unique_local_roots=%d, mapped=%d, unmapped=%d, ambiguous=%d, current_candidates=%d, missed=%d.',
            $result['integrations'],
            $result['attributes'],
            $result['remote_products'],
            $result['unique_local_roots'],
            $result['mapped_products'],
            $result['unmapped_products'],
            $result['ambiguous_products'],
            $result['current_candidates'],
            $result['missed_products'],
        ));

        foreach (collect($result['rows'])->take(max(1, (int) $this->option('limit'))) as $row) {
            $this->line(sprintf(
                'Woo legacy-axis product #%s channel=%s sku=%s lang=%s owners=%s candidates=%s state=%s size_axes=%s legacy_options=%s.',
                $row['external_product_id'],
                $row['channel'],
                $row['sku'] !== '' ? $row['sku'] : '-',
                $row['language'] !== '' ? $row['language'] : 'unknown',
                $row['owner_root_ids'] !== [] ? implode(',', $row['owner_root_ids']) : '-',
                $row['candidate_root_ids'] !== [] ? implode(',', $row['candidate_root_ids']) : '-',
                $row['owner_statuses'] !== []
                    ? collect($row['owner_statuses'])->map(
                        fn (string $status, int|string $rootId): string => $rootId.'='.$status,
                    )->implode(',')
                    : '-',
                $row['size_axes'] !== []
                    ? collect($row['size_axes'])->map(
                        fn (array $axis): string => '#'.$axis['id'].'/variation='.(int) $axis['variation'].'/'.implode('|', $axis['options']),
                    )->implode(',')
                    : '-',
                implode('|', $row['legacy_options']),
            ));
        }

        return self::SUCCESS;
    }
}
