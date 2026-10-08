<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * جدول المنتجات (products).
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->decimal('purchase_price', 14, 4)->nullable();
            $table->decimal('total_purchase_amount', 14, 2)->nullable();
            $table->decimal('paid_purchase_amount', 14, 2)->nullable();
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('alert_stock_at')->default(5);
            $table->string('country_origin')->nullable();
            $table->string('image')->nullable();
            $table->timestamps();
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
