<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the per-column company_settings table including payment gateway configuration.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $table->string('currency', 10)->default('IDR');
            $table->decimal('tax_rate', 5, 2)->default('0.00');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->string('logo_path')->nullable();
            $table->string('payment_gateway_mode', 32)->default('platform_midtrans');
            $table->boolean('midtrans_is_production')->default(false);
            $table->string('midtrans_merchant_id')->nullable();
            $table->text('midtrans_server_key')->nullable();
            $table->string('midtrans_client_key')->nullable();
            $table->text('fonnte_token')->nullable();
            $table->string('invoice_prefix', 20)->default('INV-');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
