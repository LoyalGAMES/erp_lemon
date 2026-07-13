<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_bridge_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('station_code', 40);
            $table->string('worker_name', 120);
            $table->string('version', 40)->nullable();
            $table->json('printers');
            $table->text('printer_error')->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->unique(['station_code', 'worker_name']);
            $table->index(['station_code', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_bridge_clients');
    }
};
