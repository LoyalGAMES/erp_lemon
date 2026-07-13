<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('account_status', 24)->default('guest')->index();
            $table->json('billing_data')->nullable();
            $table->json('shipping_data')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_spent', 18, 2)->default(0);
            $table->decimal('loyalty_points_balance', 18, 2)->nullable();
            $table->string('loyalty_points_source')->nullable();
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamp('account_created_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_external_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wordpress_integration_id')->constrained()->cascadeOnDelete();
            $table->string('external_customer_id')->nullable();
            $table->string('email')->nullable();
            $table->string('email_normalized')->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_registered')->default(false)->index();
            $table->string('role', 80)->nullable();
            $table->json('billing_data')->nullable();
            $table->json('shipping_data')->nullable();
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('total_spent', 18, 2)->default(0);
            $table->timestamp('account_created_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(
                ['wordpress_integration_id', 'customer_id'],
                'customer_external_integration_customer_unique',
            );
            $table->unique(
                ['wordpress_integration_id', 'external_customer_id'],
                'customer_external_integration_external_unique',
            );
            $table->unique(
                ['wordpress_integration_id', 'email_normalized'],
                'customer_external_integration_email_unique',
            );
        });

        Schema::table('external_orders', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('sales_channel_id')->constrained()->nullOnDelete();
            $table->foreignId('customer_external_account_id')->nullable()->after('customer_id')
                ->constrained('customer_external_accounts')->nullOnDelete();
            $table->foreignId('wordpress_integration_id')->nullable()->after('customer_external_account_id')
                ->constrained()->nullOnDelete();
            $table->string('customer_match_method', 32)->nullable()->after('wordpress_integration_id')->index();
        });

        Schema::table('customer_messages', function (Blueprint $table): void {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::create('customer_account_claims', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_external_account_id')->nullable()
                ->constrained('customer_external_accounts')->nullOnDelete();
            $table->foreignId('external_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('wordpress_integration_id')->constrained()->cascadeOnDelete();
            $table->char('email_hash', 64);
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->string('external_customer_id')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_account_claims');

        Schema::table('customer_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('external_orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_match_method']);
            $table->dropConstrainedForeignId('wordpress_integration_id');
            $table->dropConstrainedForeignId('customer_external_account_id');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('customer_match_method');
        });

        Schema::dropIfExists('customer_external_accounts');
        Schema::dropIfExists('customers');
    }
};
