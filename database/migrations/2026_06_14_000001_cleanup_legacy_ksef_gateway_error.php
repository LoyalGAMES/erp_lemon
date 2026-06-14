<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_ERROR = 'KSeF API 2.0 wymaga szyfrowanej sesji online i zaszyfrowanego XML. Skonfiguruj bramkę KSeF albo etap szyfrowania przed realną wysyłką.';

    private const CURRENT_ERROR = 'Poprzednia próba wysyłki pochodzi sprzed natywnej obsługi KSeF 2.0. Ponów zgłoszenie, aby ERP wysłał fakturę przez szyfrowaną sesję online.';

    public function up(): void
    {
        if (! Schema::hasTable('ksef_submissions')) {
            return;
        }

        DB::table('ksef_submissions')
            ->where('last_error', self::LEGACY_ERROR)
            ->update([
                'status' => 'failed',
                'last_error' => self::CURRENT_ERROR,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('ksef_submissions')) {
            return;
        }

        DB::table('ksef_submissions')
            ->where('last_error', self::CURRENT_ERROR)
            ->update([
                'status' => 'requires_configuration',
                'last_error' => self::LEGACY_ERROR,
                'updated_at' => now(),
            ]);
    }
};
