<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        /** @var Migration $recovery */
        $recovery = require __DIR__.'/2026_07_15_000008_requeue_woocommerce_exports_blocked_by_global_attribute_terms.php';
        $recovery->up();
    }

    public function down(): void
    {
        // Deliberate no-op: completed remote exports are not reversible.
    }
};
