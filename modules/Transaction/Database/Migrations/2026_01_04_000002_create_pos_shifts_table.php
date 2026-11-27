<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the pos_shifts table for cashier shift open/close cash reconciliation.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('core_branches')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 19, 4)->default('0.0000');
            $table->decimal('expected_cash', 19, 4)->default('0.0000');
            $table->decimal('actual_cash', 19, 4)->nullable();
            $table->string('status', 24)->default('open');
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'user_id', 'status'], 'pos_shifts_company_branch_user_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_shifts');
    }
};
