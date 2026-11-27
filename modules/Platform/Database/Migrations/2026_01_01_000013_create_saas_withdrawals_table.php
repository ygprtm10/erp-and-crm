<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the saas_withdrawals table tracking tenant escrow payout requests and approvals.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saas_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained('saas_tenant_wallets')->restrictOnDelete();
            $table->foreignId('bank_account_id')->constrained('saas_tenant_bank_accounts')->restrictOnDelete();
            $table->string('withdrawal_number')->unique();
            $table->decimal('amount', 19, 4);
            $table->decimal('admin_fee', 19, 4)->default('0.0000');
            $table->decimal('net_transfer_amount', 19, 4);
            $table->json('bank_snapshot');
            $table->string('status', 24)->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->string('transfer_proof_path')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saas_withdrawals');
    }
};
