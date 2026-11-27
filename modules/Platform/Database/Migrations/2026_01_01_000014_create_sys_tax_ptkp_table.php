<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the global sys_tax_ptkp master table for Indonesian PPh 21 PTKP statuses.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sys_tax_ptkp', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16);
            $table->string('ter_category', 4);
            $table->decimal('annual_amount', 19, 4);
            $table->unsignedSmallInteger('effective_year');
            $table->timestamps();

            $table->unique(['code', 'effective_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_tax_ptkp');
    }
};
