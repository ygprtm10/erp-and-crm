<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the hr_payroll_runs table tracking monthly payroll batches and aggregate totals.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hr_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('core_branches')->nullOnDelete();
            $table->string('payroll_number', 64);
            $table->unsignedTinyInteger('period_month');
            $table->unsignedSmallInteger('period_year');
            $table->string('status', 24)->default('draft');
            $table->boolean('apply_pph21')->default(false);
            $table->boolean('apply_bpjs')->default(false);
            $table->decimal('total_gross', 19, 4)->default('0.0000');
            $table->decimal('total_other_deductions', 19, 4)->default('0.0000');
            $table->decimal('total_pph21', 19, 4)->default('0.0000');
            $table->decimal('total_bpjs_employee', 19, 4)->default('0.0000');
            $table->decimal('total_bpjs_company', 19, 4)->default('0.0000');
            $table->decimal('total_net', 19, 4)->default('0.0000');
            $table->foreignId('payment_account_id')->nullable()->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'payroll_number']);
            $table->index(['company_id', 'period_year', 'period_month', 'status'], 'hr_payroll_runs_company_period_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_payroll_runs');
    }
};
