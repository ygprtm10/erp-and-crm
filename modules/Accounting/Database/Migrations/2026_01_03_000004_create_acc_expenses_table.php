<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the acc_expenses operational expenditure table.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('acc_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('core_branches')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('expense_account_id')->constrained('acc_accounts')->restrictOnDelete();
            $table->foreignId('payment_account_id')->nullable()->constrained('acc_accounts')->restrictOnDelete();
            $table->string('expense_number', 64);
            $table->decimal('amount', 19, 4);
            $table->date('expense_date');
            $table->date('due_date')->nullable();
            $table->string('receipt_path')->nullable();
            $table->string('status', 24)->default('unpaid');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'expense_number']);
            $table->index(['company_id', 'expense_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acc_expenses');
    }
};
