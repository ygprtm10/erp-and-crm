<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the hr_payslips table storing per-employee monthly salary, tax, and BPJS summaries.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->decimal('base_salary', 19, 4);
            $table->decimal('allowances_total', 19, 4)->default('0.0000');
            $table->decimal('overtime_pay', 19, 4)->default('0.0000');
            $table->decimal('bonus_total', 19, 4)->default('0.0000');
            $table->decimal('gross_salary', 19, 4);
            $table->decimal('pph21_amount', 19, 4)->default('0.0000');
            $table->decimal('bpjs_kesehatan_employee', 19, 4)->default('0.0000');
            $table->decimal('bpjs_kesehatan_company', 19, 4)->default('0.0000');
            $table->decimal('bpjs_tk_employee', 19, 4)->default('0.0000');
            $table->decimal('bpjs_tk_company', 19, 4)->default('0.0000');
            $table->decimal('other_deductions', 19, 4)->default('0.0000');
            $table->decimal('net_salary', 19, 4);
            $table->unsignedSmallInteger('attendance_days')->default(0);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'payroll_run_id', 'employee_id'], 'hr_payslips_company_run_emp_unique');
            $table->index(['company_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
    }
};
