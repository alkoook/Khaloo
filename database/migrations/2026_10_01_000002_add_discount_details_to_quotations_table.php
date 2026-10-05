<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quotations', 'discount_type')) {
            Schema::table('quotations', fn (Blueprint $table) => $table->string('discount_type')->nullable()->after('subtotal'));
        }
        if (! Schema::hasColumn('quotations', 'discount_value')) {
            Schema::table('quotations', fn (Blueprint $table) => $table->decimal('discount_value', 14, 2)->default(0)->after('discount_type'));
        }
        if (! Schema::hasColumn('quotations', 'discount_amount')) {
            Schema::table('quotations', fn (Blueprint $table) => $table->decimal('discount_amount', 14, 2)->default(0)->after('discount_value'));
        }
        DB::table('quotations')->where('discount', '>', 0)->update([
            'discount_type' => 'fixed',
            'discount_value' => DB::raw('discount'),
            'discount_amount' => DB::raw('discount'),
        ]);
    }

    public function down(): void
    {
        Schema::table('quotations', fn (Blueprint $table) => $table->dropColumn(['discount_type', 'discount_value', 'discount_amount']));
    }
};
