<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge migration linking transaction employee reference columns to hr_employees.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trx_orders', function (Blueprint $table): void {
            $table->foreign('salesperson_employee_id')
                ->references('id')
                ->on('hr_employees')
                ->nullOnDelete();
        });

        Schema::table('trx_order_stages', function (Blueprint $table): void {
            $table->foreign('handled_by_employee_id')
                ->references('id')
                ->on('hr_employees')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trx_orders', function (Blueprint $table): void {
            $table->dropForeign(['salesperson_employee_id']);
        });

        Schema::table('trx_order_stages', function (Blueprint $table): void {
            $table->dropForeign(['handled_by_employee_id']);
        });
    }
};
