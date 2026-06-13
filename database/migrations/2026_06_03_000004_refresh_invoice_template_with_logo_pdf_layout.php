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

        $template = DB::table('invoice_templates')
            ->where('code', 'default_vat')
            ->first();

        if ($template === null) {
            DB::table('invoice_templates')->insert([
                'code' => 'default_vat',
                'name' => 'Sempre faktura VAT',
                'renderer' => 'blade_pdf',
                'template_body' => File::get($path),
                'settings' => json_encode($this->settings(), JSON_THROW_ON_ERROR),
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $settings = json_decode((string) $template->settings, true);

        if (($settings['source'] ?? null) === 'operator') {
            return;
        }

        DB::table('invoice_templates')
            ->where('id', $template->id)
            ->update([
                'name' => 'Sempre faktura VAT',
                'renderer' => 'blade_pdf',
                'template_body' => File::get($path),
                'settings' => json_encode($this->settings(), JSON_THROW_ON_ERROR),
                'is_default' => true,
                'is_active' => true,
                'updated_at' => now(),
            ]);

        DB::table('invoice_templates')
            ->where('id', '!=', $template->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }

    public function down(): void
    {
        // The previous template body may have been edited, so this migration is intentionally not reversible.
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'source' => 'resources/views/invoices/print.blade.php',
            'source_version' => '2026-06-03-managed-branded-invoice-v2',
            'legal_review_required' => true,
        ];
    }
};
