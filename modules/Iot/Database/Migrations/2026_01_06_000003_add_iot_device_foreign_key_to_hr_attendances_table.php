<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge migration linking hr_attendances.iot_device_id to iot_devices.id.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hr_attendances', function (Blueprint $table): void {
            $table->foreign('iot_device_id')
                ->references('id')
                ->on('iot_devices')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_attendances', function (Blueprint $table): void {
            $table->dropForeign(['iot_device_id']);
        });
    }
};
