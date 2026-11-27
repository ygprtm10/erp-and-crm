<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the global sys_tax_progressive_brackets table for annual PPh 21 Article 17 tiers.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sys_tax_progressive_brackets', function (Blueprint $table): void {
            $table->id();
            $table->decimal('min_pkp', 19, 4);
            $table->decimal('max_pkp', 19, 4)->nullable();
            $table->decimal('rate_percent', 6, 4);
            $table->unsignedSmallInteger('effective_year');
            $table->timestamps();

            $table->index(['effective_year', 'min_pkp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sys_tax_progressive_brackets');
    }
};
