<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the pos_tables table for branch dining/service tables and QR tokens.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_tables', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('core_branches')->cascadeOnDelete();
            $table->string('table_number', 32);
            $table->string('qr_token', 64)->unique();
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->string('status', 24)->default('available');
            $table->timestamps();

            $table->unique(['company_id', 'branch_id', 'table_number']);
            $table->index(['company_id', 'branch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_tables');
    }
};
