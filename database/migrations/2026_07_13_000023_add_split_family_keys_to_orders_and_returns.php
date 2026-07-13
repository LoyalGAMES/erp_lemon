<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_orders', function (Blueprint $table): void {
            $table->foreignId('split_parent_order_id')
                ->nullable()
                ->after('id')
                ->constrained('external_orders')
                ->nullOnDelete();
            $table->foreignId('split_root_order_id')
                ->nullable()
                ->after('split_parent_order_id')
                ->constrained('external_orders')
                ->nullOnDelete();
        });

        Schema::table('external_order_lines', function (Blueprint $table): void {
            $table->string('canonical_external_line_id')
                ->nullable()
                ->after('external_line_id')
                ->index();
        });

        Schema::table('return_case_lines', function (Blueprint $table): void {
            $table->string('canonical_external_line_id')
                ->nullable()
                ->after('external_order_line_id')
                ->index();
        });

        $orderIds = DB::table('external_orders')
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
        $parents = [];

        DB::table('external_orders')
            ->select(['id', 'raw_payload'])
            ->orderBy('id')
            ->chunkById(500, function ($orders) use (&$parents, $orderIds): void {
                foreach ($orders as $order) {
                    $payload = $this->decodeJson($order->raw_payload);
                    $parentId = (int) data_get($payload, 'sempre_erp_split.parent_order_id', 0);

                    if ($parentId > 0 && isset($orderIds[$parentId]) && $parentId !== (int) $order->id) {
                        $parents[(int) $order->id] = $parentId;
                    }
                }
            });

        foreach ($parents as $orderId => $parentId) {
            $rootId = $this->rootOrderId($orderId, $parents);

            DB::table('external_orders')
                ->where('id', $orderId)
                ->update([
                    'split_parent_order_id' => $parentId,
                    'split_root_order_id' => $rootId !== $orderId ? $rootId : null,
                ]);
        }

        $splitOrderIds = array_fill_keys(array_keys($parents), true);
        $returnedOrderLineIds = DB::table('return_case_lines')
            ->whereNotNull('external_order_line_id')
            ->pluck('external_order_line_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
        $canonicalByLineId = [];
        DB::table('external_order_lines')
            ->select(['id', 'external_order_id', 'external_line_id', 'raw_payload'])
            ->orderBy('id')
            ->chunkById(250, function ($lines) use (&$canonicalByLineId, $returnedOrderLineIds, $splitOrderIds): void {
                $updates = [];

                foreach ($lines as $line) {
                    $payload = $this->decodeJson($line->raw_payload);
                    $canonical = trim((string) (
                        data_get($payload, 'sempre_erp_split.root_external_line_id')
                        ?: data_get($payload, 'id')
                        ?: data_get($payload, 'sempre_erp_split.source_external_line_id')
                        ?: $line->external_line_id
                    ));

                    if (isset($splitOrderIds[(int) $line->external_order_id])) {
                        $canonical = $this->withoutSplitSuffix($canonical);
                    }

                    if ($canonical === '') {
                        continue;
                    }

                    if (isset($returnedOrderLineIds[(int) $line->id])) {
                        $canonicalByLineId[(int) $line->id] = $canonical;
                    }
                    $updates[(int) $line->id] = $canonical;
                }

                $this->updateCanonicalIds('external_order_lines', $updates);
            });

        DB::table('return_case_lines')
            ->select(['id', 'external_order_line_id', 'metadata'])
            ->orderBy('id')
            ->chunkById(250, function ($lines) use ($canonicalByLineId): void {
                $updates = [];

                foreach ($lines as $line) {
                    $metadata = $this->decodeJson($line->metadata);
                    $canonical = $line->external_order_line_id !== null
                        ? ($canonicalByLineId[(int) $line->external_order_line_id] ?? '')
                        : '';
                    $canonical = trim((string) ($canonical ?: data_get($metadata, 'store_item_id', '')));

                    if ($canonical === '') {
                        continue;
                    }

                    $updates[(int) $line->id] = $this->withoutSplitSuffix($canonical);
                }

                $this->updateCanonicalIds('return_case_lines', $updates);
            });
    }

    public function down(): void
    {
        Schema::table('return_case_lines', function (Blueprint $table): void {
            $table->dropIndex(['canonical_external_line_id']);
            $table->dropColumn('canonical_external_line_id');
        });

        Schema::table('external_order_lines', function (Blueprint $table): void {
            $table->dropIndex(['canonical_external_line_id']);
            $table->dropColumn('canonical_external_line_id');
        });

        Schema::table('external_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('split_root_order_id');
            $table->dropConstrainedForeignId('split_parent_order_id');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<int, int>  $parents
     */
    private function rootOrderId(int $orderId, array $parents): int
    {
        $current = $orderId;
        $visited = [];

        while (isset($parents[$current]) && ! isset($visited[$current])) {
            $visited[$current] = true;
            $current = $parents[$current];
        }

        return $current;
    }

    private function withoutSplitSuffix(string $value): string
    {
        do {
            $previous = $value;
            $value = (string) preg_replace('/-S\d+$/', '', $value);
        } while ($value !== $previous);

        return $value;
    }

    /**
     * @param  array<int, string>  $updates
     */
    private function updateCanonicalIds(string $table, array $updates): void
    {
        if ($updates === []) {
            return;
        }

        if (! in_array($table, ['external_order_lines', 'return_case_lines'], true)) {
            throw new InvalidArgumentException('Unsupported canonical-line backfill table.');
        }

        $caseSql = [];
        $bindings = [];

        foreach ($updates as $id => $canonical) {
            $caseSql[] = 'WHEN ? THEN ?';
            $bindings[] = $id;
            $bindings[] = $canonical;
        }

        $ids = array_keys($updates);
        $whereSql = implode(', ', array_fill(0, count($ids), '?'));
        $bindings = [...$bindings, ...$ids];

        DB::update(
            "UPDATE {$table} SET canonical_external_line_id = CASE id ".implode(' ', $caseSql)." ELSE canonical_external_line_id END WHERE id IN ({$whereSql})",
            $bindings,
        );
    }
};
