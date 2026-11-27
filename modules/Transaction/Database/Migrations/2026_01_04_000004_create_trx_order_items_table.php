<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the trx_order_items table storing immutable pricing and cost snapshots per order line.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trx_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('trx_orders')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('core_product_variants')->restrictOnDelete();
            $table->string('item_name_snapshot');
            $table->decimal('qty', 10, 2);
            $table->string('unit_snapshot', 24);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('cost_price_snapshot', 19, 4)->default('0.0000');
            $table->decimal('discount_amount', 19, 4)->default('0.0000');
            $table->decimal('subtotal', 19, 4);
            $table->string('kitchen_status', 24)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trx_order_items');
    }
};
