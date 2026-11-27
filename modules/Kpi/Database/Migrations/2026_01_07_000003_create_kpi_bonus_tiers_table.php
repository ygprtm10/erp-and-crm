<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the kpi_bonus_tiers table mapping final KPI score ranges to grades and bonus payouts.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kpi_bonus_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->decimal('min_score', 8, 2);
            $table->decimal('max_score', 8, 2);
            $table->string('grade', 5);
            $table->string('bonus_type', 40);
            $table->decimal('bonus_value', 19, 4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'min_score', 'max_score'], 'kpi_bonus_tiers_company_range_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_bonus_tiers');
    }
};
