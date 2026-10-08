<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('type')->index();
            $table->string('status')->default('active')->index();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('delivery_amount', 14, 2)->default(0);
            $table->decimal('additional_total', 14, 2)->default(0);
            $table->decimal('payable_total', 14, 2)->nullable();
            $table->decimal('initial_paid', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->uuid('return_request_key')->nullable()->unique();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
