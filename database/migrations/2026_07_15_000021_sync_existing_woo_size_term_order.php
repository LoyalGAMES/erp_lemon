<?php

use App\Jobs\SyncWooCommerceGlobalSizeOrderJob;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wordpress_integrations')
            || ! Schema::hasTable('sales_channels')
            || ! Schema::hasTable('product_parameter_definitions')
            || ! Schema::hasTable('jobs')
        ) {
            return;
        }

        SyncWooCommerceGlobalSizeOrderJob::dispatchForActiveIntegrations(
            'historical_size_term_order_2026_07_15_000021',
        );
    }

    public function down(): void
    {
        // Deliberate no-op. Restoring alphabetical or lowercase global terms
        // would break every corrected storefront family at once.
    }
};
