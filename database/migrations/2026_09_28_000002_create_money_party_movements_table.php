<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_party_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('money_party_id')->constrained()->restrictOnDelete();
            $table->string('direction')->index();
            $table->string('category')->index();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('SYP');
            $table->decimal('exchange_rate_snapshot', 18, 6)->default(1);
            $table->decimal('amount_base_currency', 14, 2);
            $table->date('date')->index();
            $table->text('notes')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
            $table->index(['money_party_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_party_movements');
    }
};
