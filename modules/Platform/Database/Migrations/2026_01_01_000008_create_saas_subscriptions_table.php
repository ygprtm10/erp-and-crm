<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the saas_subscriptions table for Platform Midtrans bundle and add-on checkouts.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('saas_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained('saas_bundles')->nullOnDelete();
            $table->string('order_number')->unique();
            $table->json('module_ids')->nullable();
            $table->decimal('amount', 19, 4);
            $table->string('status', 24)->default('pending');
            $table->string('midtrans_snap_token')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saas_subscriptions');
    }
};
