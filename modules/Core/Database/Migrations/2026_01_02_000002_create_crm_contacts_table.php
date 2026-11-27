<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the crm_contacts table for unified customers, vendors, and members.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('crm_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_customer')->default(true);
            $table->boolean('is_vendor')->default(false);
            $table->boolean('is_member')->default(false);
            $table->string('lead_stage', 32)->nullable();
            $table->decimal('points_balance', 12, 2)->default('0.00');
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_customer', 'is_vendor', 'is_member']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_contacts');
    }
};
