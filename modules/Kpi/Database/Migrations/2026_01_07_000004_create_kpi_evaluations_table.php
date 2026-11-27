<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the kpi_evaluations table storing per-employee KPI scores, grades, bonuses, and payroll linkage.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kpi_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('kpi_period_id')->constrained('kpi_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('evaluator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total_weight', 5, 2)->default('0.00');
            $table->decimal('final_score', 8, 2)->default('0.00');
            $table->string('grade', 5)->nullable();
            $table->decimal('bonus_amount', 19, 4)->default('0.0000');
            $table->foreignId('bonus_applied_to_payslip_id')->nullable()->constrained('hr_payslips')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'kpi_period_id', 'employee_id'], 'kpi_evaluations_company_period_emp_unique');
            $table->index(['company_id', 'status', 'bonus_applied_to_payslip_id'], 'kpi_evaluations_company_status_payslip_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_evaluations');
    }
};
