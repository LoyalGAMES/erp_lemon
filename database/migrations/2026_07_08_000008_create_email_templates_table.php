<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('context', 24)->default('both')->index();
            $table->string('subject');
            $table->text('body');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::table('email_templates')->insert([
            [
                'code' => 'missing_stock',
                'name' => 'Brak towaru',
                'context' => 'order',
                'subject' => 'Informacja o dostępności zamówienia {{order_number}}',
                'body' => "Dzień dobry,\n\ninformujemy, że w zamówieniu {{order_number}} wystąpił problem z dostępnością części towaru. Skontaktujemy się z propozycją rozwiązania albo zamiennikiem.",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'exchange_surcharge',
                'name' => 'Dopłata do wymiany',
                'context' => 'return',
                'subject' => 'Dopłata do wymiany {{return_number}}',
                'body' => "Dzień dobry,\n\nwymiana w zgłoszeniu {{return_number}} wymaga dopłaty w wysokości {{amount}} {{currency}}.\n\nLink do płatności: {{payment_url}}\n\nPo zaksięgowaniu płatności nadamy przesyłkę z produktem wymiennym.",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'general_return',
                'name' => 'Informacja o zwrocie',
                'context' => 'return',
                'subject' => 'Informacja o zwrocie {{return_number}}',
                'body' => "Dzień dobry,\n\nprzesyłamy informację dotyczącą zwrotu {{return_number}}.",
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
