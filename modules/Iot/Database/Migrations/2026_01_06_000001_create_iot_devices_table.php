<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the iot_devices table storing ESP32 hardware metadata and hashed bearer tokens.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('iot_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('core_branches')->nullOnDelete();
            $table->string('device_code', 64);
            $table->string('name', 120);
            $table->string('device_type', 32);
            $table->string('api_token_hash', 64)->unique();
            $table->string('token_prefix', 12);
            $table->string('firmware_version', 64)->nullable();
            $table->string('mac_address', 32)->nullable();
            $table->string('last_ip_address', 45)->nullable();
            $table->string('status', 24)->default('active');
            $table->unsignedSmallInteger('cooldown_seconds')->default(60);
            $table->dateTime('last_heartbeat_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'device_code'], 'iot_devices_company_code_unique');
            $table->index(['company_id', 'status', 'last_heartbeat_at'], 'iot_devices_company_status_heartbeat_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('iot_devices');
    }
};
