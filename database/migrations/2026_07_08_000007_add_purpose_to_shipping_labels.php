<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shipping_labels', 'purpose')) {
            return;
        }

        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->string('purpose', 32)->default('shipment')->after('return_case_id')->index();
        });

        DB::table('shipping_labels')
            ->whereNotNull('return_case_id')
            ->update(['purpose' => 'return']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('shipping_labels', 'purpose')) {
            return;
        }

        if (Schema::hasIndex('shipping_labels', ['purpose'])) {
            Schema::table('shipping_labels', function (Blueprint $table): void {
                $table->dropIndex(['purpose']);
            });
        }

        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
