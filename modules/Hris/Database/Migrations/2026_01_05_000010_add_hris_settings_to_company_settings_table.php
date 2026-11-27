<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge migration adding default HRIS tax and BPJS toggle columns to company_settings.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->boolean('enable_pph21')->default(false)->after('invoice_prefix');
            $table->boolean('enable_bpjs_kesehatan')->default(false)->after('enable_pph21');
            $table->boolean('enable_bpjs_ketenagakerjaan')->default(false)->after('enable_bpjs_kesehatan');
            $table->decimal('bpjs_jkk_rate', 6, 4)->default('0.0024')->after('enable_bpjs_ketenagakerjaan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'enable_pph21',
                'enable_bpjs_kesehatan',
                'enable_bpjs_ketenagakerjaan',
                'bpjs_jkk_rate',
            ]);
        });
    }
};
