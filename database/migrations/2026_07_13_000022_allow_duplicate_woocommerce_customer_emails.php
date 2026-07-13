<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_external_accounts', function (Blueprint $table): void {
            $table->index(
                ['wordpress_integration_id', 'email_normalized'],
                'customer_external_integration_email_index',
            );
        });

        // MySQL may use the old unique index to support the integration foreign
        // key, so its non-unique replacement must exist before the drop.
        Schema::table('customer_external_accounts', function (Blueprint $table): void {
            $table->dropUnique('customer_external_integration_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customer_external_accounts', function (Blueprint $table): void {
            $table->unique(
                ['wordpress_integration_id', 'email_normalized'],
                'customer_external_integration_email_unique',
            );
        });

        Schema::table('customer_external_accounts', function (Blueprint $table): void {
            $table->dropIndex('customer_external_integration_email_index');
        });
    }
};
