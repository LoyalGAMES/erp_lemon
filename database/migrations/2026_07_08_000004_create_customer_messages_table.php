<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('external_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('return_case_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 24)->default('outgoing')->index();
            $table->string('type', 24)->index();
            $table->string('trigger', 80)->nullable()->index();
            $table->string('status', 24)->default('pending')->index();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['external_order_id', 'type', 'trigger', 'status']);
            $table->index(['return_case_id', 'type', 'trigger', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_messages');
    }
};
