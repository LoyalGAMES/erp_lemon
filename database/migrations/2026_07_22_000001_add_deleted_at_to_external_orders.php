<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_orders', function (Blueprint $table): void {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        if (DB::table('external_orders')->whereNotNull('deleted_at')->exists()) {
            throw new RuntimeException(
                'Nie można cofnąć migracji deleted_at: istnieją zarchiwizowane części zamówień. Ich przywrócenie utworzyłoby ponownie aktywne, cofnięte podziały.',
            );
        }

        Schema::table('external_orders', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
        });

        Schema::table('external_orders', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
