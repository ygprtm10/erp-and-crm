<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the saas_tenant_wallets table storing escrow balances for platform_midtrans tenants.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saas_tenant_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();
            $table->decimal('available_balance', 19, 4)->default('0.0000');
            $table->decimal('pending_balance', 19, 4)->default('0.0000');
            $table->decimal('total_earned', 19, 4)->default('0.0000');
            $table->decimal('total_withdrawn', 19, 4)->default('0.0000');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saas_tenant_wallets');
    }
};
