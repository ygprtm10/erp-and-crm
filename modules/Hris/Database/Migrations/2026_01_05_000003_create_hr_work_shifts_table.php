<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tenant-scoped hr_work_shifts table defining shift schedules and lateness tolerances.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hr_work_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 120);
            $table->time('clock_in_time');
            $table->time('clock_out_time');
            $table->unsignedSmallInteger('late_tolerance_minutes')->default(15);
            $table->boolean('is_overnight')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_work_shifts');
    }
};
