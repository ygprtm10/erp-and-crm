<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the trx_order_vouchers table recording applied voucher snapshots per order.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trx_order_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('trx_orders')->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained('core_vouchers')->restrictOnDelete();
            $table->string('voucher_code_snapshot', 64);
            $table->decimal('discount_applied', 19, 4);
            $table->timestamps();

            $table->index(['company_id', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trx_order_vouchers');
    }
};
