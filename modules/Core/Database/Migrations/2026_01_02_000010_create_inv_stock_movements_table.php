<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the append-only inv_stock_movements audit ledger table.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inv_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('core_branches')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('core_product_variants')->restrictOnDelete();
            $table->string('type', 24);
            $table->decimal('qty_change', 12, 2);
            $table->decimal('unit_cost', 19, 4)->default('0.0000');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'product_variant_id'], 'inv_stock_mov_company_branch_variant_idx');
            $table->index(['reference_type', 'reference_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_stock_movements');
    }
};
