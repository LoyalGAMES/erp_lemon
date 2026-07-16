<?php

declare(strict_types=1);

namespace App\Services\WooCommerce;

use App\Jobs\RepairWooOwnedVariantAxisJob;
use App\Models\ProductChannelMapping;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final class WooOwnedVariantAxisDeploymentGate
{
    public function __construct(
        private readonly WooOwnedVariantAxisRepairService $repair,
        private readonly LegacyVariantFamilyBackfillService $backfill,
    ) {}

    /**
     * Run each family at most once. A deferred or unsafe result remains visible
     * to the fail-closed postcondition instead of being retried in a deploy
     * loop that could perform repeated remote reads or writes.
     *
     * @return array{
     *     candidates:int,
     *     processed:int,
     *     skipped:int,
     *     exceptions:int,
     *     results:list<array{product_id:int,status:string,error?:string}>,
     *     postcondition:array<string,mixed>
     * }
     */
    public function runSynchronously(): array
    {
        $productIds = $this->currentRevisionMappings()
            ->get(['product_id', 'metadata'])
            ->filter(fn (ProductChannelMapping $mapping): bool => in_array(
                data_get($mapping->metadata, WooOwnedVariantAxisRepairService::STATE_PATH.'.status'),
                ['pending', 'queued'],
                true,
            ))
            ->pluck('product_id')
            ->map(fn (mixed $productId): int => (int) $productId)
            ->unique()
            ->sort()
            ->values();
        $processed = 0;
        $skipped = 0;
        $exceptions = 0;
        $results = [];

        foreach ($productIds as $productId) {
            $reservation = $this->repair->reserveForIsolatedSynchronousRepair($productId);

            if (($reservation['status'] ?? null) !== 'reserved') {
                $skipped++;
                $results[] = [
                    'product_id' => $productId,
                    'status' => (string) ($reservation['status'] ?? 'missing'),
                ];

                continue;
            }

            $processed++;
            $job = new RepairWooOwnedVariantAxisJob(
                (int) $reservation['product_id'],
                (string) $reservation['token'],
            );

            try {
                // remote-release.sh has enabled maintenance, restarted the
                // queue and waited for every old worker. Calling the existing
                // handler directly preserves its axis-only repair, durable
                // follow-up export and audit semantics without waiting for the
                // once-per-minute scheduler or bypassing a live catalog writer.
                $job->handle($this->repair, $this->backfill);
                $results[] = [
                    'product_id' => $productId,
                    'status' => $this->familyStatus($productId),
                ];
            } catch (Throwable $exception) {
                $exceptions++;
                report($exception);

                try {
                    $job->failed($exception);
                } catch (Throwable $failureException) {
                    report($failureException);
                }

                $results[] = [
                    'product_id' => $productId,
                    'status' => $this->familyStatus($productId),
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'candidates' => $productIds->count(),
            'processed' => $processed,
            'skipped' => $skipped,
            'exceptions' => $exceptions,
            'results' => $results,
            'postcondition' => $this->postcondition(),
        ];
    }

    /**
     * @return array{
     *     passed:bool,
     *     mappings:int,
     *     families:int,
     *     statuses:array<string,int>,
     *     unresolved_mappings:int,
     *     unresolved_families:list<int>
     * }
     */
    public function postcondition(): array
    {
        $mappings = $this->currentRevisionMappings()
            ->get(['id', 'product_id', 'metadata']);
        $statuses = $mappings
            ->countBy(fn (ProductChannelMapping $mapping): string => (string) data_get(
                $mapping->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
                'missing',
            ))
            ->sortKeys()
            ->all();
        $unresolved = $mappings
            ->filter(fn (ProductChannelMapping $mapping): bool => ! $this->isCompletedState(
                (array) data_get(
                    $mapping->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                    [],
                ),
            ));

        return [
            'passed' => $unresolved->isEmpty(),
            'mappings' => $mappings->count(),
            'families' => $mappings->pluck('product_id')->unique()->count(),
            'statuses' => $statuses,
            'unresolved_mappings' => $unresolved->count(),
            'unresolved_families' => $unresolved
                ->pluck('product_id')
                ->map(fn (mixed $productId): int => (int) $productId)
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /** @return Builder<ProductChannelMapping> */
    private function currentRevisionMappings(): Builder
    {
        return ProductChannelMapping::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('external_variation_id')
                    ->orWhereIn('external_variation_id', ['', '0'])
                    ->orWhereRaw("TRIM(external_variation_id) = ''");
            })
            ->where(
                'metadata->'.str_replace(
                    '.',
                    '->',
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                ).'->revision',
                WooOwnedVariantAxisRepairService::REVISION,
            );
    }

    /** @param array<string, mixed> $state */
    private function isCompletedState(array $state): bool
    {
        return ($state['status'] ?? null) === 'completed'
            && in_array(data_get($state, 'result.status'), ['repaired', 'already_canonical'], true)
            && blank($state['pending_token'] ?? null)
            && blank($state['failed_at'] ?? null)
            && blank($state['error'] ?? null);
    }

    private function familyStatus(int $productId): string
    {
        /** @var Collection<int, string> $statuses */
        $statuses = $this->currentRevisionMappings()
            ->where('product_id', $productId)
            ->get(['metadata'])
            ->map(fn (ProductChannelMapping $mapping): string => (string) data_get(
                $mapping->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH.'.status',
                'missing',
            ))
            ->unique()
            ->sort()
            ->values();

        return $statuses->isEmpty() ? 'missing' : $statuses->implode(',');
    }
}
