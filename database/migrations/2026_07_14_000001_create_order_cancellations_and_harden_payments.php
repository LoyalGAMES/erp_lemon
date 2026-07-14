<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_cancellations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('external_order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 32)->default('pending')->index();
            $table->text('reason');
            $table->string('refund_status', 32)->default('pending')->index();
            $table->decimal('refund_amount', 18, 2)->default(0);
            $table->string('currency', 3)->default('PLN');
            $table->string('payment_method')->nullable();
            $table->string('woo_refund_id')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('order_cancellation_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_cancellation_id')->constrained()->cascadeOnDelete();
            $table->string('step', 64);
            $table->string('status', 32)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('idempotency_key', 191)->nullable()->unique();
            $table->string('external_reference')->nullable()->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['order_cancellation_id', 'step']);
        });

        Schema::table('customer_payments', function (Blueprint $table): void {
            $table->string('idempotency_key', 191)->nullable()->after('return_case_id')->unique();
            $table->foreignId('order_cancellation_id')->nullable()->after('idempotency_key')
                ->constrained()->nullOnDelete();
            $table->string('source', 40)->default('manual')->after('direction')->index();
            $table->string('purpose', 40)->default('manual_adjustment')->after('source')->index();
            $table->string('external_transaction_id')->nullable()->after('reference')->index();
            $table->timestamp('requested_at')->nullable()->after('booked_at')->index();
            $table->timestamp('failed_at')->nullable()->after('paid_at')->index();
            $table->text('error_message')->nullable()->after('failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table): void {
            $table->dropForeign(['order_cancellation_id']);
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['source']);
            $table->dropIndex(['purpose']);
            $table->dropIndex(['external_transaction_id']);
            $table->dropIndex(['requested_at']);
            $table->dropIndex(['failed_at']);
        });

        Schema::table('customer_payments', function (Blueprint $table): void {
            $table->dropColumn([
                'idempotency_key',
                'order_cancellation_id',
                'source',
                'purpose',
                'external_transaction_id',
                'requested_at',
                'failed_at',
                'error_message',
            ]);
        });

        Schema::dropIfExists('order_cancellation_steps');
        Schema::dropIfExists('order_cancellations');
    }
};
