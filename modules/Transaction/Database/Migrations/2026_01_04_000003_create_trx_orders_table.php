<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the unified trx_orders table for POS, QR Order, Invoice, Web, Membership, and Laundry orders.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trx_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('core_branches')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('crm_contacts')->nullOnDelete();
            $table->foreignId('pos_shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
            $table->foreignId('pos_table_id')->nullable()->constrained('pos_tables')->nullOnDelete();
            $table->unsignedBigInteger('salesperson_employee_id')->nullable();
            $table->string('order_number', 64);
            $table->string('channel', 24);
            $table->string('order_status', 24)->default('pending');
            $table->string('payment_status', 24)->default('unpaid');
            $table->decimal('subtotal', 19, 4)->default('0.0000');
            $table->decimal('discount_total', 19, 4)->default('0.0000');
            $table->decimal('tax_total', 19, 4)->default('0.0000');
            $table->decimal('grand_total', 19, 4)->default('0.0000');
            $table->date('due_date')->nullable();
            $table->timestamp('estimated_ready_at')->nullable();
            $table->string('rack_location', 64)->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'order_number']);
            $table->index(['company_id', 'channel', 'order_status', 'created_at'], 'trx_orders_company_channel_status_created_idx');
            $table->index(['company_id', 'payment_status']);
            $table->index(['company_id', 'salesperson_employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trx_orders');
    }
};
