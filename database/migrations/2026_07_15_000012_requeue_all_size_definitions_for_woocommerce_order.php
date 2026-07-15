<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Production may already have applied 000011 before its historical
        // `is_variant` blind spot was discovered. Re-run the now idempotent
        // local repair under a new durable export revision. No HTTP occurs in
        // the migration; the bounded background queue performs the exports.
        (require database_path(
            'migrations/2026_07_15_000011_reexport_woocommerce_publication_dates_and_attribute_order.php',
        ))->up();
    }

    public function down(): void
    {
        // Deliberate no-op; corrected dictionary order and completed remote
        // exports must not be undone by application rollback.
    }
};
