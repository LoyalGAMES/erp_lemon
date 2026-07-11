<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->string('gs1_gpc_code', 8)->nullable()->after('description')->index();
            $table->string('gs1_gpc_label')->nullable()->after('gs1_gpc_code');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropIndex(['gs1_gpc_code']);
            $table->dropColumn(['gs1_gpc_code', 'gs1_gpc_label']);
        });
    }
};
