<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Products with warehouse-document history can never be hard-deleted (the
// document and ledger FKs are restrictOnDelete by design). Archiving is the
// supported way to retire such a product: it leaves every document intact
// while removing the product from day-to-day views and from channel sync.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('storefront_restore_visibility');
            $table->index('archived_at', 'products_archived_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_archived_at_index');
            $table->dropColumn('archived_at');
        });
    }
};
