<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the hr_attendances table tracking daily clock-in/out, lateness, overtime, and IoT source readiness.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hr_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('core_branches')->nullOnDelete();
            $table->foreignId('work_shift_id')->nullable()->constrained('hr_work_shifts')->nullOnDelete();
            $table->date('attendance_date');
            $table->dateTime('clock_in')->nullable();
            $table->dateTime('clock_out')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->string('status', 24);
            $table->string('source', 24)->default('manual');
            /** Deferred FK: iot_devices table will be introduced in Wave 2 Stage 8 (IoT module). */
            $table->unsignedBigInteger('iot_device_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_id', 'attendance_date'], 'hr_attendances_company_emp_date_unique');
            $table->index(['company_id', 'attendance_date', 'status'], 'hr_attendances_company_date_status_idx');
            $table->index(['company_id', 'iot_device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_attendances');
    }
};
