<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $migration = require __DIR__.'/2026_07_17_000034_reexport_woocommerce_custom_product_labels.php';
        $migration->up();
    }

    public function down(): void
    {
        // Deliberate no-op: a completed remote meta update is not reversible.
    }
};
