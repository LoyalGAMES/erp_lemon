<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shipping_label_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->string('source', 80)->nullable()->index();
            $table->string('station_code', 40)->nullable()->index();
            $table->string('printer_name', 120);
            $table->string('listener_url', 180)->nullable();
            $table->string('format', 16)->default('pdf');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->string('reserved_by', 120)->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('printed_at')->nullable()->index();
            $table->timestamp('failed_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['station_code', 'status', 'next_attempt_at']);
            $table->index(['status', 'reserved_at']);
            $table->index(['shipping_label_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
