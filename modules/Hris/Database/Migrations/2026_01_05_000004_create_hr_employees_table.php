<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the hr_employees master table with PTKP status, RFID credential readiness, and tax/BPJS switches.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hr_employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('core_branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('work_shift_id')->nullable()->constrained('hr_work_shifts')->nullOnDelete();
            $table->string('employee_code', 32);
            $table->string('full_name', 150);
            $table->string('email', 150)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('rfid_uid', 64)->nullable();
            $table->string('ptkp_status', 8)->default('TK/0');
            $table->decimal('base_salary', 19, 4);
            $table->decimal('fixed_allowance', 19, 4)->default('0.0000');
            $table->boolean('enable_pph21')->default(false);
            $table->boolean('enable_bpjs_kesehatan')->default(false);
            $table->boolean('enable_bpjs_ketenagakerjaan')->default(false);
            $table->string('employment_status', 24)->default('active');
            $table->date('join_date');
            $table->string('bank_name', 64)->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->string('bank_account_holder', 150)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_code']);
            $table->unique(['company_id', 'rfid_uid']);
            $table->index(['company_id', 'employment_status']);
            $table->index(['company_id', 'branch_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_employees');
    }
};
