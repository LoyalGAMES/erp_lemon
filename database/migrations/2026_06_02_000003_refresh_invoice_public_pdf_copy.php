<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

return new class extends Migration {
    public function up(): void
    {
        $path = resource_path('views/invoices/print.blade.php');

        if (! File::exists($path)) {
            return;
        }

        DB::table('invoice_templates')
            ->where('code', 'default_vat')
            ->update([
                'name' => 'Sempre faktura VAT',
                'renderer' => 'blade_pdf',
                'template_body' => File::get($path),
                'settings' => json_encode([
                    'source' => 'resources/views/invoices/print.blade.php',
                    'source_version' => '2026-06-02-public-invoice-copy',
                ], JSON_THROW_ON_ERROR),
                'is_default' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The previous template body may have been edited by an operator, so this migration is not reversible safely.
    }
};
