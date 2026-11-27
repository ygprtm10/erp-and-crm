<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bridge migration linking Core product accounting columns to acc_accounts.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('core_products', function (Blueprint $table): void {
            $table->foreign('income_account_id')
                ->references('id')
                ->on('acc_accounts')
                ->nullOnDelete();

            $table->foreign('cogs_account_id')
                ->references('id')
                ->on('acc_accounts')
                ->nullOnDelete();

            $table->foreign('inventory_account_id')
                ->references('id')
                ->on('acc_accounts')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('core_products', function (Blueprint $table): void {
            $table->dropForeign(['income_account_id']);
            $table->dropForeign(['cogs_account_id']);
            $table->dropForeign(['inventory_account_id']);
        });
    }
};
