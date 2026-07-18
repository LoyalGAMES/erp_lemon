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
        {--mark-repair-candidates : Persist dictionary-backed remote evidence and queue safe roots}
        {--require-no-active-legacy : Fail when any live product still uses wariant/BLVariant as a variation axis}
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
            'Woo legacy-axis remote audit: integrations=%d, attributes=%d, remote_products=%d, unique_local_roots=%d, mapped=%d, unmapped=%d, ambiguous=%d, current_candidates=%d, missed=%d, exact_remote_products=%d, migration_safe_remote_products=%d, conflicting_remote_products=%d, exact_remote_roots=%d, migration_safe_remote_roots=%d.',
            $result['integrations'],
            $result['attributes'],
            $result['remote_products'],
            $result['unique_local_roots'],
            $result['mapped_products'],
            $result['unmapped_products'],
            $result['ambiguous_products'],
            $result['current_candidates'],
            $result['missed_products'],
            $result['exact_remote_products'],
            $result['migration_safe_remote_products'],
            $result['conflicting_remote_products'],
            $result['exact_remote_roots'],
            $result['migration_safe_remote_roots'],
        ));

        if ((bool) $this->option('mark-repair-candidates')) {
            $marked = $audit->markSafeRemoteRepairCandidates($result);
            $this->info(sprintf(
                'Woo legacy-axis remote candidates: eligible_roots=%d, marked_roots=%d, marked_mappings=%d, skipped_roots=%d.',
                $marked['eligible_roots'],
                $marked['marked_roots'],
                $marked['marked_mappings'],
                $marked['skipped_roots'],
            ));
        }

        foreach (collect($result['rows'])->take(max(1, (int) $this->option('limit'))) as $row) {
            $this->line(sprintf(
                'Woo legacy-axis product #%s channel=%s sku=%s lang=%s owners=%s candidates=%s state=%s remote_safe=%d remote_mode=%s remote_reason=%s size_axes=%s legacy_options=%s owner_detail=%s contract=%s.',
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
                (int) data_get($row, 'remote_evidence.verified', false),
                (string) (data_get($row, 'remote_evidence.mode') ?? '-'),
                (string) (data_get($row, 'remote_evidence.reason') ?? '-'),
                $row['size_axes'] !== []
                    ? collect($row['size_axes'])->map(
                        fn (array $axis): string => '#'.$axis['id'].'/variation='.(int) $axis['variation'].'/'.implode('|', $axis['options']),
                    )->implode(',')
                    : '-',
                implode('|', $row['legacy_options']),
                json_encode($row['owner_details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-',
                json_encode($row['remote_contract'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-',
            ));
        }

        if ((bool) $this->option('require-no-active-legacy')
            && $result['remote_products'] > 0
        ) {
            $this->error(sprintf(
                'Woo legacy-axis postcondition failed: %d live products still use a legacy variation axis.',
                $result['remote_products'],
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
