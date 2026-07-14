<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_messages', function (Blueprint $table): void {
            $table->json('delivery_snapshot')->nullable()->after('metadata');
            $table->longText('rendered_html_snapshot')->nullable()->after('delivery_snapshot');
            $table->longText('rendered_text_snapshot')->nullable()->after('rendered_html_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('customer_messages', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_snapshot',
                'rendered_html_snapshot',
                'rendered_text_snapshot',
            ]);
        });
    }
};
