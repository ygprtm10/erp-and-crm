<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the trx_payments table recording manual and dual-mode Midtrans payment settlements.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trx_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('trx_orders')->restrictOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('acc_accounts')->restrictOnDelete();
            $table->string('payment_method', 24);
            $table->string('gateway_mode', 24);
            $table->decimal('amount', 19, 4);
            $table->decimal('gateway_fee_amount', 19, 4)->default('0.0000');
            $table->decimal('platform_fee_amount', 19, 4)->default('0.0000');
            $table->decimal('net_amount', 19, 4);
            $table->timestamp('paid_at')->nullable();
            $table->string('gateway_reference', 128)->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();

            $table->index(['company_id', 'order_id', 'status']);
            $table->index(['company_id', 'gateway_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trx_payments');
    }
};
