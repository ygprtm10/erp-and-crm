<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the kpi_evaluation_items table storing indicator-level targets, actuals, and weighted scores.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kpi_evaluation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('kpi_evaluation_id')->constrained('kpi_evaluations')->cascadeOnDelete();
            $table->foreignId('kpi_indicator_id')->constrained('kpi_indicators')->restrictOnDelete();
            $table->string('indicator_name_snapshot', 150);
            $table->string('metric_type_snapshot', 40);
            $table->decimal('weight', 5, 2);
            $table->decimal('target_value', 19, 4);
            $table->decimal('actual_value', 19, 4)->default('0.0000');
            $table->decimal('achievement_percentage', 8, 2)->default('0.00');
            $table->decimal('weighted_score', 8, 2)->default('0.00');
            $table->boolean('is_auto_synced')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'kpi_evaluation_id', 'kpi_indicator_id'], 'kpi_eval_items_company_eval_ind_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_evaluation_items');
    }
};
