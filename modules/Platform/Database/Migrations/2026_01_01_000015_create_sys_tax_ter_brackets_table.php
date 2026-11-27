<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the global sys_tax_ter_brackets master table for monthly PPh 21 TER rates.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sys_tax_ter_brackets', function (Blueprint $table): void {
            $table->id();
            $table->string('ter_category', 4);
            $table->decimal('min_gross', 19, 4);
            $table->decimal('max_gross', 19, 4)->nullable();
            $table->decimal('rate_percent', 6, 4);
            $table->unsignedSmallInteger('effective_year');
            $table->timestamps();

            $table->index(['ter_category', 'effective_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_tax_ter_brackets');
    }
};
