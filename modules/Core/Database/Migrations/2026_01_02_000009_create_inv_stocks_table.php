<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the inv_stocks table tracking branch-level variant quantities.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inv_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('core_branches')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('core_product_variants')->restrictOnDelete();
            $table->decimal('quantity', 12, 2)->default('0.00');
            $table->decimal('min_stock_alert', 12, 2)->default('0.00');
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'product_variant_id'], 'inv_stocks_company_branch_variant_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_stocks');
    }
};
