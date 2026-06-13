<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('wordpress_integrations', function (Blueprint $table): void {
            if (! Schema::hasColumn('wordpress_integrations', 'wp_api_username')) {
                $table->string('wp_api_username')->nullable()->after('consumer_secret_encrypted');
            }

            if (! Schema::hasColumn('wordpress_integrations', 'wp_api_password_encrypted')) {
                $table->text('wp_api_password_encrypted')->nullable()->after('wp_api_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wordpress_integrations', function (Blueprint $table): void {
            if (Schema::hasColumn('wordpress_integrations', 'wp_api_password_encrypted')) {
                $table->dropColumn('wp_api_password_encrypted');
            }

            if (Schema::hasColumn('wordpress_integrations', 'wp_api_username')) {
                $table->dropColumn('wp_api_username');
            }
        });
    }
};
