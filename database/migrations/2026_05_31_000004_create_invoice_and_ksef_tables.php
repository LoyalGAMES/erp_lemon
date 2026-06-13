<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('invoice_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('renderer')->default('blade_pdf');
            $table->longText('template_body');
            $table->json('settings')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->string('type', 32)->default('vat')->index();
            $table->string('status', 24)->default('draft')->index();
            $table->foreignId('external_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_template_id')->nullable()->constrained()->nullOnDelete();
            $table->date('issue_date');
            $table->date('sale_date')->nullable();
            $table->date('payment_due_date')->nullable();
            $table->string('currency', 3)->default('PLN');
            $table->json('seller_data');
            $table->json('buyer_data');
            $table->decimal('net_total', 18, 2)->default(0);
            $table->decimal('vat_total', 18, 2)->default(0);
            $table->decimal('gross_total', 18, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->string('ksef_number')->nullable()->index();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('unit', 16)->default('szt');
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_net_price', 18, 4);
            $table->decimal('net_total', 18, 2);
            $table->decimal('vat_rate', 5, 2);
            $table->decimal('vat_total', 18, 2);
            $table->decimal('gross_total', 18, 2);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24)->index();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ksef_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('environment', 24)->default('demo')->index();
            $table->string('api_version', 32)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('reference_number')->nullable()->index();
            $table->string('ksef_number')->nullable()->index();
            $table->longText('xml_payload')->nullable();
            $table->json('request_metadata')->nullable();
            $table->json('response_metadata')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ksef_submissions');
        Schema::dropIfExists('invoice_files');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_templates');
    }
};

