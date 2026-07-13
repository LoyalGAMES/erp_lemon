<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SalesChannel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreReturnSplitBackfillMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migration_backfills_legacy_split_and_return_line_keys(): void
    {
        $migration = require database_path('migrations/2026_07_13_000023_add_split_family_keys_to_orders_and_returns.php');
        $migration->down();

        $channel = SalesChannel::query()->create([
            'name' => 'Legacy Woo',
            'code' => 'legacy-woo',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $rootId = DB::table('external_orders')->insertGetId([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001',
            'external_number' => '12345',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $childId = DB::table('external_orders')->insertGetId([
            'sales_channel_id' => $channel->id,
            'external_id' => '9001-SPLIT-1',
            'external_number' => '12345/S1',
            'status' => 'completed',
            'currency' => 'PLN',
            'total_gross' => 100,
            'raw_payload' => json_encode([
                'sempre_erp_split' => [
                    'parent_order_id' => $rootId,
                    'parent_external_id' => '9001',
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lineId = DB::table('external_order_lines')->insertGetId([
            'external_order_id' => $childId,
            'external_line_id' => '771-S1',
            'name' => 'Produkt legacy',
            'quantity' => 1,
            'raw_payload' => json_encode([
                'sempre_erp_split' => [
                    'source_external_line_id' => '771',
                ],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $returnCaseId = DB::table('return_cases')->insertGetId([
            'number' => 'RET/LEGACY/1',
            'external_order_id' => $rootId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $returnLineId = DB::table('return_case_lines')->insertGetId([
            'return_case_id' => $returnCaseId,
            'external_order_line_id' => $lineId,
            'quantity_expected' => 1,
            'quantity_accepted' => 1,
            'condition' => 'unchecked',
            'disposition' => 'restock',
            'metadata' => json_encode(['store_item_id' => '771'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::disableForeignKeyConstraints();
        $migration->up();
        Schema::enableForeignKeyConstraints();

        $this->assertDatabaseHas('external_orders', [
            'id' => $childId,
            'split_parent_order_id' => $rootId,
            'split_root_order_id' => $rootId,
        ]);
        $this->assertDatabaseHas('external_order_lines', [
            'id' => $lineId,
            'canonical_external_line_id' => '771',
        ]);
        $this->assertDatabaseHas('return_case_lines', [
            'id' => $returnLineId,
            'canonical_external_line_id' => '771',
        ]);
    }
}
