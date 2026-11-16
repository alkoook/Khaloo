<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->date('return_date')->nullable()->after('return_request_key')->index();
            $table->text('notes')->nullable()->after('return_date');
        });

        Schema::table('ledger_settlements', function (Blueprint $table): void {
            $table->foreignId('return_invoice_id')->nullable()->after('invoice_id')
                ->constrained('invoices')->nullOnDelete()->index();
        });
    }

    public function down(): void
    {
        Schema::table('ledger_settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_invoice_id');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropIndex(['return_date']);
            $table->dropColumn(['return_date', 'notes']);
        });
    }
};
