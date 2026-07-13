<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_mail_connections', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose')->unique();
            $table->text('client_id')->nullable();
            $table->text('client_secret_encrypted')->nullable();
            $table->string('google_subject')->nullable();
            $table->string('email')->nullable();
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('reauthorization_required_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $setting = DB::table('app_settings')
            ->where('key', 'mail_settings')
            ->first();

        if ($setting !== null) {
            $value = json_decode((string) $setting->value, true);

            if (is_array($value) && ($value['delivery_method'] ?? null) === 'google_workspace') {
                $value['enabled'] = false;
                $value['delivery_enabled'] = false;
                unset($value['delivery_method']);

                DB::table('app_settings')
                    ->where('key', 'mail_settings')
                    ->update([
                        'value' => json_encode($value, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            }
        }

        Schema::dropIfExists('google_mail_connections');
    }
};
