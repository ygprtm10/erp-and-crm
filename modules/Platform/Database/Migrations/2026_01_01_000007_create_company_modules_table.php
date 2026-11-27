<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the company_modules table tracking per-tenant module entitlements and expiration.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('company_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('saas_modules')->restrictOnDelete();
            $table->string('status', 24)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->json('limits')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'module_id']);
            $table->index(['company_id', 'status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_modules');
    }
};
