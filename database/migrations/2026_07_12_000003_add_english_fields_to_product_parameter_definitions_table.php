<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_parameter_definitions', function (Blueprint $table): void {
            $table->string('name_en')->nullable()->after('name');
            $table->json('values_en')->nullable()->after('values');

            $table->unique('name_en', 'product_parameter_definitions_name_en_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_parameter_definitions', function (Blueprint $table): void {
            $table->dropUnique('product_parameter_definitions_name_en_unique');
            $table->dropColumn(['name_en', 'values_en']);
        });
    }
};
