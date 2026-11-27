<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tenant-scoped hr_positions table with optional department link and salary bounds.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hr_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name', 120);
            $table->decimal('base_salary_min', 19, 4)->default('0.0000');
            $table->decimal('base_salary_max', 19, 4)->default('0.0000');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'department_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_positions');
    }
};
