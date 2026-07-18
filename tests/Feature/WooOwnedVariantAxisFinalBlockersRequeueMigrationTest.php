<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductChannelMapping;
use App\Models\SalesChannel;
use App\Services\WooCommerce\WooOwnedVariantAxisDeploymentGate;
use App\Services\WooCommerce\WooOwnedVariantAxisRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WooOwnedVariantAxisFinalBlockersRequeueMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_requeues_only_the_two_recoverable_final_diagnostics(): void
    {
        CarbonImmutable::setTestNow('2026-07-18 15:10:00');

        try {
            $channel = SalesChannel::query()->create([
                'code' => 'FINAL-AXIS',
                'name' => 'Final axis',
                'type' => 'woocommerce',
                'is_active' => true,
            ]);
            $duplicate = $this->mapping(
                $channel,
                'DUPLICATE',
                'Polskie warianty nie odpowiadają dokładnie wariantom rodziny ERP.',
            );
            $sourceTerm = $this->mapping(
                $channel,
                'SOURCE-TERM',
                'WooCommerce EN #500316: WooCommerce nie zawiera źródłowej polskiej wartości XS globalnego atrybutu #1.',
            );
            $unrelated = $this->mapping(
                $channel,
                'UNRELATED',
                'Polska i angielska rodzina WooCommerce mają różne zbiory rozmiarów.',
            );
            $unrelatedBefore = $unrelated->metadata;

            $this->runMigration();

            foreach ([$duplicate, $sourceTerm] as $mapping) {
                $state = (array) data_get(
                    $mapping->refresh()->metadata,
                    WooOwnedVariantAxisRepairService::STATE_PATH,
                );

                $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
                $this->assertSame('pending', $state['status']);
                $this->assertSame(now()->toISOString(), $state['requested_at']);
                $this->assertSame(
                    WooOwnedVariantAxisRepairService::PREVIOUS_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
                    data_get($state, 'previous.revision'),
                );
                $this->assertSame('manual_review', data_get($state, 'previous.status'));
                $this->assertArrayNotHasKey('pending_token', $state);
            }

            $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
            $postcondition = app(WooOwnedVariantAxisDeploymentGate::class)->postcondition();
            $this->assertFalse($postcondition['passed']);
            $this->assertSame(['pending' => 2], $postcondition['statuses']);

            $afterFirstRun = ProductChannelMapping::query()
                ->orderBy('id')
                ->pluck('metadata', 'id')
                ->all();
            CarbonImmutable::setTestNow('2026-07-18 15:15:00');
            $this->runMigration();
            $this->assertSame(
                $afterFirstRun,
                ProductChannelMapping::query()->orderBy('id')->pluck('metadata', 'id')->all(),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_follow_up_migration_requeues_only_the_two_guards_proven_by_the_executed_retry(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'EXECUTED-FINAL-AXIS',
            'name' => 'Executed final axis',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $duplicate = $this->mapping(
            $channel,
            'EXECUTED-DUPLICATE',
            'Lokalne warianty zawierają niepuste lub obce wartości, których zdalna wersja językowa nie może nadpisać.',
            WooOwnedVariantAxisRepairService::PREVIOUS_EXECUTED_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
        );
        $sourceTerm = $this->mapping(
            $channel,
            'EXECUTED-SOURCE-TERM',
            'WooCommerce EN #500316: WooCommerce nie zawiera źródłowej polskiej wartości XS globalnego atrybutu #1.',
            WooOwnedVariantAxisRepairService::PREVIOUS_EXECUTED_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
        );
        $unrelated = $this->mapping(
            $channel,
            'EXECUTED-UNRELATED',
            'Zdalna rodzina nie ma kompletnego zbioru wariantów.',
            WooOwnedVariantAxisRepairService::PREVIOUS_EXECUTED_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
        );
        $unrelatedBefore = $unrelated->metadata;

        $this->runExecutedMigration();

        foreach ([$duplicate, $sourceTerm] as $mapping) {
            $state = (array) data_get(
                $mapping->refresh()->metadata,
                WooOwnedVariantAxisRepairService::STATE_PATH,
            );

            $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
            $this->assertSame('pending', $state['status']);
            $this->assertSame(
                WooOwnedVariantAxisRepairService::PREVIOUS_EXECUTED_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
                data_get($state, 'previous.revision'),
            );
        }

        $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
        $postcondition = app(WooOwnedVariantAxisDeploymentGate::class)->postcondition();
        $this->assertSame(['pending' => 2], $postcondition['statuses']);
    }

    public function test_existing_term_family_migration_requeues_only_the_exact_polylang_conflict(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'TERM-FAMILY-AXIS',
            'name' => 'Term family axis',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $conflict = $this->mapping(
            $channel,
            'TERM-FAMILY-CONFLICT',
            'WooCommerce EN #500316: WordPress nie powiązał tłumaczeń wartości globalnego atrybutu: Wartość atrybutu 110014 należy już do innej rodziny tłumaczeń.',
            WooOwnedVariantAxisRepairService::PREVIOUS_EXISTING_TERM_TRANSLATION_FAMILY_REVISION,
        );
        $unrelated = $this->mapping(
            $channel,
            'TERM-FAMILY-UNRELATED',
            'WordPress nie potwierdził kompletnej rodziny tłumaczeń.',
            WooOwnedVariantAxisRepairService::PREVIOUS_EXISTING_TERM_TRANSLATION_FAMILY_REVISION,
        );
        $unrelatedBefore = $unrelated->metadata;

        $this->runExistingTermFamilyMigration();

        $state = (array) data_get(
            $conflict->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        );
        $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
        $this->assertSame('pending', $state['status']);
        $this->assertSame(
            WooOwnedVariantAxisRepairService::PREVIOUS_EXISTING_TERM_TRANSLATION_FAMILY_REVISION,
            data_get($state, 'previous.revision'),
        );
        $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
        $this->assertSame(
            ['pending' => 1],
            app(WooOwnedVariantAxisDeploymentGate::class)->postcondition()['statuses'],
        );
    }

    public function test_term_family_probe_migration_requeues_only_the_persisting_exact_conflict(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'TERM-PROBE-AXIS',
            'name' => 'Term probe axis',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $conflict = $this->mapping(
            $channel,
            'TERM-PROBE-CONFLICT',
            'WooCommerce EN #500316: WordPress nie powiązał tłumaczeń wartości globalnego atrybutu: Wartość atrybutu 110014 należy już do innej rodziny tłumaczeń.',
            WooOwnedVariantAxisRepairService::PREVIOUS_TERM_TRANSLATION_CONFLICT_PROBE_REVISION,
        );
        $unrelated = $this->mapping(
            $channel,
            'TERM-PROBE-UNRELATED',
            'WordPress nie potwierdził kompletnej rodziny tłumaczeń.',
            WooOwnedVariantAxisRepairService::PREVIOUS_TERM_TRANSLATION_CONFLICT_PROBE_REVISION,
        );
        $unrelatedBefore = $unrelated->metadata;

        $this->runTermFamilyProbeMigration();

        $state = (array) data_get(
            $conflict->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        );
        $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
        $this->assertSame('pending', $state['status']);
        $this->assertSame(
            WooOwnedVariantAxisRepairService::PREVIOUS_TERM_TRANSLATION_CONFLICT_PROBE_REVISION,
            data_get($state, 'previous.revision'),
        );
        $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
        $this->assertSame(
            ['pending' => 1],
            app(WooOwnedVariantAxisDeploymentGate::class)->postcondition()['statuses'],
        );
    }

    public function test_numeric_source_term_migration_requeues_only_the_persisting_exact_conflict(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'NUMERIC-TERM-AXIS',
            'name' => 'Numeric term axis',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $conflict = $this->mapping(
            $channel,
            'NUMERIC-TERM-CONFLICT',
            'WooCommerce EN #500316: WordPress nie powiązał tłumaczeń wartości globalnego atrybutu: Wartość atrybutu 110014 należy już do innej rodziny tłumaczeń.',
            WooOwnedVariantAxisRepairService::PREVIOUS_NUMERIC_SOURCE_TERM_SLUG_REVISION,
        );
        $unrelated = $this->mapping(
            $channel,
            'NUMERIC-TERM-UNRELATED',
            'WordPress nie potwierdził kompletnej rodziny tłumaczeń.',
            WooOwnedVariantAxisRepairService::PREVIOUS_NUMERIC_SOURCE_TERM_SLUG_REVISION,
        );
        $unrelatedBefore = $unrelated->metadata;

        $this->runNumericSourceTermMigration();

        $state = (array) data_get(
            $conflict->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        );
        $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
        $this->assertSame('pending', $state['status']);
        $this->assertSame(
            WooOwnedVariantAxisRepairService::PREVIOUS_NUMERIC_SOURCE_TERM_SLUG_REVISION,
            data_get($state, 'previous.revision'),
        );
        $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
    }

    public function test_existing_sibling_probe_migration_requeues_only_the_persisting_exact_conflict(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'SIBLING-PROBE-AXIS',
            'name' => 'Sibling probe axis',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $conflict = $this->mapping(
            $channel,
            'SIBLING-PROBE-CONFLICT',
            'WooCommerce EN #500316: WordPress nie powiązał tłumaczeń wartości globalnego atrybutu: Wartość atrybutu 110014 należy już do innej rodziny tłumaczeń.',
            WooOwnedVariantAxisRepairService::PREVIOUS_EXISTING_TERM_SIBLING_PROBE_REVISION,
        );
        $unrelated = $this->mapping(
            $channel,
            'SIBLING-PROBE-UNRELATED',
            'WordPress nie potwierdził kompletnej rodziny tłumaczeń.',
            WooOwnedVariantAxisRepairService::PREVIOUS_EXISTING_TERM_SIBLING_PROBE_REVISION,
        );
        $unrelatedBefore = $unrelated->metadata;

        $this->runExistingSiblingProbeMigration();

        $state = (array) data_get(
            $conflict->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        );
        $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
        $this->assertSame('pending', $state['status']);
        $this->assertSame(
            WooOwnedVariantAxisRepairService::PREVIOUS_EXISTING_TERM_SIBLING_PROBE_REVISION,
            data_get($state, 'previous.revision'),
        );
        $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
    }

    public function test_misnamed_term_migration_requeues_only_the_proven_m_l_mismatch(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'MISNAMED-TERM-AXIS',
            'name' => 'Misnamed term axis',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $conflict = $this->mapping(
            $channel,
            'MISNAMED-TERM-CONFLICT',
            'WooCommerce EN #500316: Istniejące polskie tłumaczenie #57 (M/L, m-l) wartości #110014 nie odpowiada opcji XS.',
            WooOwnedVariantAxisRepairService::PREVIOUS_MISNAMED_TRANSLATION_TERM_REVISION,
        );
        $unrelated = $this->mapping(
            $channel,
            'MISNAMED-TERM-UNRELATED',
            'WooCommerce EN #500316: Istniejące polskie tłumaczenie nie jest jednoznaczne.',
            WooOwnedVariantAxisRepairService::PREVIOUS_MISNAMED_TRANSLATION_TERM_REVISION,
        );
        $unrelatedBefore = $unrelated->metadata;

        $this->runMisnamedTermMigration();

        $state = (array) data_get(
            $conflict->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        );
        $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
        $this->assertSame('pending', $state['status']);
        $this->assertSame(
            WooOwnedVariantAxisRepairService::PREVIOUS_MISNAMED_TRANSLATION_TERM_REVISION,
            data_get($state, 'previous.revision'),
        );
        $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
    }

    public function test_missing_target_term_name_migration_requeues_only_the_exact_guard(): void
    {
        $channel = SalesChannel::query()->create([
            'code' => 'TARGET-NAME-AXIS',
            'name' => 'Target name axis',
            'type' => 'woocommerce',
            'is_active' => true,
        ]);
        $conflict = $this->mapping(
            $channel,
            'TARGET-NAME-CONFLICT',
            'WooCommerce EN #500316: Naprawa błędnej rodziny tłumaczeń wartości atrybutu nie ma kompletnego kontraktu.',
            WooOwnedVariantAxisRepairService::PREVIOUS_MISSING_TARGET_TERM_NAME_REVISION,
        );
        $unrelated = $this->mapping(
            $channel,
            'TARGET-NAME-UNRELATED',
            'Naprawa innej rodziny nie ma kompletnego kontraktu.',
            WooOwnedVariantAxisRepairService::PREVIOUS_MISSING_TARGET_TERM_NAME_REVISION,
        );
        $unrelatedBefore = $unrelated->metadata;

        $this->runMissingTargetTermNameMigration();

        $state = (array) data_get(
            $conflict->refresh()->metadata,
            WooOwnedVariantAxisRepairService::STATE_PATH,
        );
        $this->assertSame(WooOwnedVariantAxisRepairService::REVISION, $state['revision']);
        $this->assertSame('pending', $state['status']);
        $this->assertSame(
            WooOwnedVariantAxisRepairService::PREVIOUS_MISSING_TARGET_TERM_NAME_REVISION,
            data_get($state, 'previous.revision'),
        );
        $this->assertSame($unrelatedBefore, $unrelated->refresh()->metadata);
    }

    private function mapping(
        SalesChannel $channel,
        string $suffix,
        string $reason,
        string $revision = WooOwnedVariantAxisRepairService::PREVIOUS_FINAL_VARIANT_REPAIR_BLOCKERS_REVISION,
    ): ProductChannelMapping {
        $product = Product::query()->create([
            'sku' => 'FINAL-AXIS-'.$suffix,
            'name' => 'Final axis '.$suffix,
            'unit' => 'szt',
            'vat_rate' => 23,
            'quantity_precision' => 0,
            'is_active' => true,
            'attributes' => ['master' => [
                'source' => 'woocommerce_import',
                'product_type' => 'variable',
                'variant_attribute' => 'Rozmiar',
            ]],
        ]);

        return ProductChannelMapping::query()->create([
            'product_id' => $product->id,
            'sales_channel_id' => $channel->id,
            'external_product_id' => (string) (900000 + $product->id),
            'stock_sync_enabled' => true,
            'metadata' => [
                'operator_note' => 'preserve',
                'maintenance' => ['woo_owned_variant_axis_repair' => [
                    'revision' => $revision,
                    'status' => 'manual_review',
                    'requested_at' => '2026-07-18T12:00:00+00:00',
                    'completed_at' => '2026-07-18T12:05:00+00:00',
                    'result' => [
                        'status' => 'manual_review',
                        'reason' => $reason,
                    ],
                ]],
            ],
        ]);
    }

    private function runMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000056_requeue_final_woo_variant_axis_blockers.php',
        ))->up();
    }

    private function runExecutedMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000057_requeue_executed_final_woo_variant_axis_blockers.php',
        ))->up();
    }

    private function runExistingTermFamilyMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000058_requeue_existing_woo_term_translation_family.php',
        ))->up();
    }

    private function runTermFamilyProbeMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000059_requeue_woo_term_translation_conflict_probe.php',
        ))->up();
    }

    private function runNumericSourceTermMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000060_requeue_numeric_woo_term_translation_sibling.php',
        ))->up();
    }

    private function runExistingSiblingProbeMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000061_requeue_existing_woo_term_sibling_probe.php',
        ))->up();
    }

    private function runMisnamedTermMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000062_requeue_misnamed_woo_translation_term.php',
        ))->up();
    }

    private function runMissingTargetTermNameMigration(): void
    {
        (require database_path(
            'migrations/2026_07_18_000063_requeue_missing_woo_target_term_name.php',
        ))->up();
    }
}
