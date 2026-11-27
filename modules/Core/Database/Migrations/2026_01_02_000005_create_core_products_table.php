<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the core_products table with nullable Accounting account references for Stage 5 linking.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('core_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('core_categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('type', 24)->default('goods');
            $table->boolean('track_stock')->default(true);
            $table->boolean('show_on_web')->default(false);
            $table->string('image_path')->nullable();
            $table->unsignedBigInteger('income_account_id')->nullable();
            $table->unsignedBigInteger('cogs_account_id')->nullable();
            $table->unsignedBigInteger('inventory_account_id')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
            $table->index(['company_id', 'type', 'show_on_web']);
            $table->index(['company_id', 'income_account_id']);
            $table->index(['company_id', 'cogs_account_id']);
            $table->index(['company_id', 'inventory_account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('core_products');
    }
};
