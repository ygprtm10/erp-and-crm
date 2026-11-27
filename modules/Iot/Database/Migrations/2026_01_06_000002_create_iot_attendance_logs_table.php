<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the iot_attendance_logs table capturing every ESP32 tap attempt and ingestion outcome.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('iot_attendance_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('iot_device_id')->constrained('iot_devices')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('hr_attendances')->nullOnDelete();
            $table->string('scanned_uid', 100);
            $table->string('scan_type', 24);
            $table->string('status', 32);
            $table->dateTime('device_timestamp')->nullable();
            $table->dateTime('processed_at');
            $table->json('raw_payload')->nullable();
            $table->string('response_message', 255)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'iot_device_id', 'processed_at'], 'iot_logs_company_device_processed_idx');
            $table->index(['company_id', 'scanned_uid', 'processed_at'], 'iot_logs_company_uid_processed_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iot_attendance_logs');
    }
};
