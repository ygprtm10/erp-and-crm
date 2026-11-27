<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the core_product_variants table storing SKUs, units, and decimal pricing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('core_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('core_products')->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->string('unit', 24)->default('pcs');
            $table->decimal('cost_price', 19, 4)->default('0.0000');
            $table->decimal('selling_price', 19, 4)->default('0.0000');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'barcode']);
            $table->index(['company_id', 'product_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_product_variants');
    }
};
