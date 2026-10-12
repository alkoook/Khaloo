<?php

namespace App\Console\Commands;

use App\Support\Decimal as D;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Existing development databases need a non-destructive type upgrade, not migrate:fresh. */
class DecimalQuantities extends Command
{
    protected $signature = 'khaloo:decimal-quantities {--apply : حفظ نسخة الكميات ثم تحديث نوع الأعمدة دون حذف السجلات}';

    protected $description = 'فحص أو تهيئة كميات قاعدة خلو الحالية لدقة ثلاث منازل عشرية';

    public function handle(): int
    {
        $tables = ['products', 'invoice_lines', 'quotation_lines'];
        $pending = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'quantity');
            $type = strtolower($column['type']);
            $this->line($table.': '.$type);
            if (! in_array($type, ['decimal(14,3)', 'numeric(14,3)'], true)) {
                $pending[] = $table;
            }
        }
        if (! $this->option('apply')) {
            $this->info('فحص فقط: لا تغيير في البيانات. استخدم --apply لتحديث الأعمدة الموجودة مع حفظ نسخة من الكميات.');

            return self::SUCCESS;
        }
        if ($pending === []) {
            $this->info('دقة الكميات صحيحة مسبقاً؛ لا تعديل.');

            return self::SUCCESS;
        }
        $snapshots = [];
        foreach ($pending as $table) {
            $snapshots[$table] = DB::table($table)->orderBy('id')->pluck('quantity', 'id')->map(fn ($q) => D::quantity($q))->all();
        }
        $path = 'backups/decimal-quantities-'.now()->format('Ymd-His-u').'.json';
        if (! Storage::disk('local')->put($path, json_encode($snapshots, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT))) {
            $this->error('تعذر حفظ نسخة الكميات؛ لم يتم تعديل الأعمدة.');

            return self::FAILURE;
        }
        $this->warn('نسخة الكميات: '.Storage::disk('local')->path($path));
        foreach ($pending as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $column = $blueprint->decimal('quantity', 14, 3);
                if ($table === 'products') {
                    $column->default(0);
                }
                $column->change();
            });
            $after = DB::table($table)->orderBy('id')->pluck('quantity', 'id')->map(fn ($q) => D::quantity($q))->all();
            if ($after !== $snapshots[$table]) {
                $this->error('فشل تحقق الكميات في '.$table.'؛ توقف التحديث. راجع النسخة المحفوظة.');

                return self::FAILURE;
            }
        }
        $this->info('تم تحديث نوع الأعمدة والتحقق من بقاء جميع الكميات والسجلات كما هي.');

        return self::SUCCESS;
    }
}
