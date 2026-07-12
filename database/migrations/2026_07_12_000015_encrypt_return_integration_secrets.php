<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        $row = DB::table('app_settings')->where('key', 'return_settings')->first(['id', 'value']);

        if ($row === null) {
            return;
        }

        $settings = is_string($row->value)
            ? json_decode($row->value, true)
            : (array) $row->value;

        if (! is_array($settings)) {
            return;
        }

        foreach (['store_api_token', 'store_webhook_secret'] as $key) {
            $legacy = trim((string) ($settings[$key] ?? ''));
            $encrypted = $settings[$key.'_encrypted'] ?? null;
            $encryptedIsValid = false;

            if (is_string($encrypted) && trim($encrypted) !== '') {
                try {
                    Crypt::decryptString($encrypted);
                    $encryptedIsValid = true;
                } catch (Throwable) {
                    // A valid legacy value below can safely replace corrupt ciphertext.
                }
            }

            if (! $encryptedIsValid && $legacy !== '') {
                $settings[$key.'_encrypted'] = Crypt::encryptString($legacy);
            }

            unset($settings[$key]);
        }

        DB::table('app_settings')
            ->where('id', $row->id)
            ->update([
                'value' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Deliberately do not downgrade encrypted credentials to plaintext.
    }
};
