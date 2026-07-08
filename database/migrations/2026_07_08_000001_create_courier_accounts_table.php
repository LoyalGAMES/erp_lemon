<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('inpost');
            $table->string('code', 40);
            $table->string('name');
            $table->text('api_token_encrypted');
            $table->string('organization_id');
            $table->string('sending_method')->default('dispatch_order');
            $table->string('default_parcel_template', 40)->default('small');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'code']);
        });

        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->foreignId('courier_account_id')
                ->nullable()
                ->constrained('courier_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipping_labels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('courier_account_id');
        });

        Schema::dropIfExists('courier_accounts');
    }
};
