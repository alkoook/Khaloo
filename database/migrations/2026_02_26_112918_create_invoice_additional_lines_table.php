<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_additional_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('row_total', 14, 2);
            $table->string('source_note')->nullable();
            $table->timestamps();
            $table->index(['invoice_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_additional_lines');
    }
};
