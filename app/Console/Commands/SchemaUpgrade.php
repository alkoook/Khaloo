<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use App\Support\Decimal as D;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SchemaUpgrade extends Command
{
    protected $signature = 'khaloo:schema-upgrade';

    protected $description = 'ترقية مخطط قاعدة بيانات خلو القديمة بأمان مع نسخة احتياطية وفحص عدم فقدان السجلات';

    /** @var array<int, string> */
    private array $changes = [];

    /** @var array<int, string> */
    private array $criticalTables = [
        'products', 'partners', 'invoices', 'invoice_lines', 'invoice_additional_lines',
        'ledger_settlements', 'quotations', 'quotation_lines', 'expenses',
    ];

    public function handle(DatabaseBackupService $backups): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->error('هذا الأمر مخصص لترقية قاعدة MySQL الإنتاجية فقط.');

            return self::FAILURE;
        }

        $locked = (int) (DB::selectOne("SELECT GET_LOCK('khaloo_schema_upgrade', 10) AS acquired")->acquired ?? 0) === 1;
        if (! $locked) {
            $this->error('تعذر الحصول على قفل الترقية. توجد عملية ترقية أخرى قيد التنفيذ.');

            return self::FAILURE;
        }

        try {
            $backup = $this->createVerifiedBackup($backups);
            $before = $this->rowCounts();
            $this->info('تم التحقق من النسخة الاحتياطية: '.$backup['path']);
            $this->line('SHA-256: '.$backup['sha256']);

            $this->upgradeQuantities();
            $this->upgradeInvoices();
            $this->upgradeInvoiceLines();
            $this->ensureAdditionalLinesTable();
            $this->upgradeAdditionalLines();
            $this->upgradeSettlements();
            $this->ensureAuditLogsTable();
            $this->assertExpectedSchema();

            $after = $this->rowCounts();
            if ($before !== $after) {
                throw new RuntimeException('تغير عدد السجلات في جدول حرج أثناء ترقية المخطط. أوقف الاستخدام وراجع النسخة الاحتياطية فورًا.');
            }

            $reportPath = $this->writeReport($backup, $before, $after);
            if ($this->changes === []) {
                $this->info('المخطط متوافق مسبقًا؛ لم يلزم أي تعديل.');
            } else {
                $this->info('التعديلات المنفذة:');
                foreach ($this->changes as $change) {
                    $this->line(' - '.$change);
                }
            }
            $this->info('تمت مطابقة أعداد الصفوف قبل الترقية وبعدها دون فقدان بيانات.');
            $this->line('تقرير الترقية: '.$reportPath);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('فشلت ترقية المخطط: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            DB::selectOne("SELECT RELEASE_LOCK('khaloo_schema_upgrade') AS released");
        }
    }

    private function createVerifiedBackup(DatabaseBackupService $backups): array
    {
        $temporary = $backups->create();
        $directory = storage_path('app/private');
        $realDirectory = realpath($directory);
        $realTemporary = realpath($temporary);
        if (! $realDirectory || ! $realTemporary || ! str_starts_with(strtolower($realTemporary), strtolower($realDirectory.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('مسار النسخة الاحتياطية غير آمن. لم تبدأ الترقية.');
        }
        $header = file_get_contents($realTemporary, false, null, 0, 512);
        $validHeader = is_string($header) && (
            str_contains($header, 'MariaDB dump')
            || str_contains($header, 'MySQL dump')
            || str_contains($header, 'Khaloo PDO SQL backup')
        );
        if (filesize($realTemporary) <= 0 || ! $validHeader) {
            @unlink($realTemporary);
            throw new RuntimeException('تعذر التحقق من ملف النسخة الاحتياطية. لم تبدأ الترقية.');
        }

        $target = $directory.DIRECTORY_SEPARATOR.'khaloo-pre-schema-upgrade-'.now()->format('Ymd-His-u').'.sql';
        if (! rename($realTemporary, $target)) {
            @unlink($realTemporary);
            throw new RuntimeException('تعذر تثبيت ملف النسخة الاحتياطية. لم تبدأ الترقية.');
        }

        return ['path' => $target, 'bytes' => filesize($target), 'sha256' => hash_file('sha256', $target)];
    }

    private function rowCounts(): array
    {
        $counts = [];
        foreach ($this->criticalTables as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }

        return $counts;
    }

    private function upgradeQuantities(): void
    {
        foreach (['products', 'invoice_lines', 'invoice_additional_lines', 'quotation_lines'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'quantity')) {
                continue;
            }
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'quantity');
            if (in_array(strtolower((string) ($column['type'] ?? '')), ['decimal(14,3)', 'numeric(14,3)'], true)) {
                continue;
            }
            $before = DB::table($table)->orderBy('id')->pluck('quantity', 'id')->map(fn ($value) => D::quantity($value))->all();
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $quantity = $blueprint->decimal('quantity', 14, 3);
                if ($table === 'products') {
                    $quantity->default(0);
                }
                $quantity->change();
            });
            $after = DB::table($table)->orderBy('id')->pluck('quantity', 'id')->map(fn ($value) => D::quantity($value))->all();
            if ($before !== $after) {
                throw new RuntimeException("لم تنجح مطابقة قيم الكميات في {$table} بعد تحويل الدقة.");
            }
            $this->changes[] = "{$table}.quantity -> DECIMAL(14,3)";
        }
    }

    private function upgradeInvoices(): void
    {
        if (! Schema::hasTable('invoices')) {
            throw new RuntimeException('جدول invoices غير موجود؛ لا يمكن تنفيذ ترقية توافقية عليه.');
        }

        $this->addColumn('invoices', 'original_invoice_id', fn (Blueprint $table) => $table->unsignedBigInteger('original_invoice_id')->nullable()->after('partner_id'));
        $this->addColumn('invoices', 'status', fn (Blueprint $table) => $table->string('status')->default('active')->after('type'));
        $subtotalAdded = $this->addColumn('invoices', 'subtotal', fn (Blueprint $table) => $table->decimal('subtotal', 14, 2)->default(0)->after('status'));
        $this->addColumn('invoices', 'discount_type', fn (Blueprint $table) => $table->string('discount_type')->nullable()->after('subtotal'));
        $this->addColumn('invoices', 'discount_value', fn (Blueprint $table) => $table->decimal('discount_value', 14, 2)->default(0)->after('discount_type'));
        $this->addColumn('invoices', 'discount_amount', fn (Blueprint $table) => $table->decimal('discount_amount', 14, 2)->default(0)->after('discount_value'));
        $this->addColumn('invoices', 'delivery_amount', fn (Blueprint $table) => $table->decimal('delivery_amount', 14, 2)->default(0)->after('total'));
        $this->addColumn('invoices', 'additional_total', fn (Blueprint $table) => $table->decimal('additional_total', 14, 2)->default(0)->after('delivery_amount'));
        $payableAdded = $this->addColumn('invoices', 'payable_total', fn (Blueprint $table) => $table->decimal('payable_total', 14, 2)->nullable()->after('additional_total'));
        $initialPaidAdded = $this->addColumn('invoices', 'initial_paid', fn (Blueprint $table) => $table->decimal('initial_paid', 14, 2)->default(0)->after('payable_total'));
        $this->addColumn('invoices', 'return_request_key', fn (Blueprint $table) => $table->uuid('return_request_key')->nullable()->after('amount_paid'));
        $this->addColumn('invoices', 'return_preserves_original', fn (Blueprint $table) => $table->boolean('return_preserves_original')->default(false)->after('return_request_key'));
        $this->addColumn('invoices', 'cancelled_at', fn (Blueprint $table) => $table->timestamp('cancelled_at')->nullable()->after('return_preserves_original'));
        $this->addColumn('invoices', 'cancelled_by', fn (Blueprint $table) => $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at'));
        $this->addColumn('invoices', 'cancellation_reason', fn (Blueprint $table) => $table->text('cancellation_reason')->nullable()->after('cancelled_by'));

        if ($payableAdded) {
            DB::table('invoices')->where('type', 'sale')->whereNull('payable_total')->update(['payable_total' => DB::raw('total')]);
            $this->changes[] = 'تهيئة payable_total لفواتير البيع القديمة من total فقط';
        }
        if ($subtotalAdded) {
            DB::table('invoices')->update(['subtotal' => DB::raw('total')]);
            $this->changes[] = 'تهيئة subtotal التاريخي من total دون تغيير الإجمالي';
        }
        if ($initialPaidAdded && Schema::hasColumn('invoices', 'amount_paid')) {
            DB::table('invoices')->update(['initial_paid' => DB::raw('amount_paid')]);
            $this->changes[] = 'تهيئة initial_paid من amount_paid التاريخي';
        }

        $this->addUniqueIndex('invoices', ['return_request_key'], 'invoices_return_request_key_unique');
        $this->addIndex('invoices', ['return_preserves_original'], 'invoices_return_preserves_original_index');
        $this->addForeignKey('invoices', 'original_invoice_id', 'invoices', 'id', 'invoices_original_invoice_id_foreign');
        $this->addForeignKey('invoices', 'cancelled_by', 'users', 'id', 'invoices_cancelled_by_foreign');
    }

    private function upgradeInvoiceLines(): void
    {
        if (! Schema::hasTable('invoice_lines')) {
            throw new RuntimeException('جدول invoice_lines غير موجود؛ لا يمكن تنفيذ ترقية توافقية عليه.');
        }
        $this->addColumn('invoice_lines', 'purchase_price', fn (Blueprint $table) => $table->decimal('purchase_price', 14, 4)->nullable()->after('unit_price'));
        $this->addColumn('invoice_lines', 'sale_price', fn (Blueprint $table) => $table->decimal('sale_price', 14, 2)->nullable()->after('purchase_price'));
        $discountAdded = $this->addColumn('invoice_lines', 'discount_amount', fn (Blueprint $table) => $table->decimal('discount_amount', 14, 2)->default(0)->after('line_total'));
        $netAdded = $this->addColumn('invoice_lines', 'net_line_total', fn (Blueprint $table) => $table->decimal('net_line_total', 14, 2)->nullable()->after('discount_amount'));
        if ($netAdded) {
            DB::table('invoice_lines')->whereNull('net_line_total')->update([
                'net_line_total' => $discountAdded ? DB::raw('line_total') : DB::raw('line_total - COALESCE(discount_amount, 0)'),
            ]);
            $this->changes[] = 'تهيئة net_line_total حسابيًا من إجمالي السطر والحسم';
        }
    }

    private function ensureAdditionalLinesTable(): void
    {
        if (Schema::hasTable('invoice_additional_lines')) {
            return;
        }
        Schema::create('invoice_additional_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('row_total', 14, 2);
            $table->string('source_note')->nullable();
            $table->timestamps();
        });
        $this->changes[] = 'إنشاء invoice_additional_lines';
    }

    private function upgradeAdditionalLines(): void
    {
        $this->addColumn('invoice_additional_lines', 'description', fn (Blueprint $table) => $table->string('description')->default('')->after('invoice_id'));
        $this->addColumn('invoice_additional_lines', 'quantity', fn (Blueprint $table) => $table->decimal('quantity', 14, 3)->default(0)->after('description'));
        $this->addColumn('invoice_additional_lines', 'unit_amount', fn (Blueprint $table) => $table->decimal('unit_amount', 14, 2)->default(0)->after('quantity'));
        $rowTotalAdded = $this->addColumn('invoice_additional_lines', 'row_total', fn (Blueprint $table) => $table->decimal('row_total', 14, 2)->default(0)->after('unit_amount'));
        $this->addColumn('invoice_additional_lines', 'source_note', fn (Blueprint $table) => $table->string('source_note')->nullable()->after('row_total'));
        if ($rowTotalAdded) {
            DB::table('invoice_additional_lines')->update(['row_total' => DB::raw('quantity * unit_amount')]);
            $this->changes[] = 'تهيئة row_total للخانات الإضافية حسابيًا';
        }
    }

    private function upgradeSettlements(): void
    {
        if (! Schema::hasTable('ledger_settlements')) {
            throw new RuntimeException('جدول ledger_settlements غير موجود؛ لا يمكن تنفيذ ترقية توافقية عليه.');
        }
        $this->addColumn('ledger_settlements', 'invoice_id', fn (Blueprint $table) => $table->unsignedBigInteger('invoice_id')->nullable()->after('partner_id'));
        $this->addColumn('ledger_settlements', 'payment_method', fn (Blueprint $table) => $table->string('payment_method', 50)->nullable()->after('type'));
        $this->addColumn('ledger_settlements', 'cancelled_at', fn (Blueprint $table) => $table->timestamp('cancelled_at')->nullable()->after('notes'));
        $this->addColumn('ledger_settlements', 'cancelled_by', fn (Blueprint $table) => $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at'));
        $this->addColumn('ledger_settlements', 'cancellation_reason', fn (Blueprint $table) => $table->text('cancellation_reason')->nullable()->after('cancelled_by'));
        $this->addIndex('ledger_settlements', ['cancelled_at'], 'ledger_settlements_cancelled_at_index');
        $this->addIndex('ledger_settlements', ['partner_id', 'invoice_id'], 'ledger_settlements_partner_id_invoice_id_index');
        $this->addForeignKey('ledger_settlements', 'invoice_id', 'invoices', 'id', 'ledger_settlements_invoice_id_foreign');
        $this->addForeignKey('ledger_settlements', 'cancelled_by', 'users', 'id', 'ledger_settlements_cancelled_by_foreign');
        // Historical settlements remain NULL: dates/amounts do not prove a unique invoice relationship.
    }

    private function ensureAuditLogsTable(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('auditable');
            $table->string('event', 50)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });
        $this->changes[] = 'إنشاء audit_logs';
    }

    private function assertExpectedSchema(): void
    {
        $expected = [
            'products' => ['quantity'],
            'invoices' => ['original_invoice_id', 'status', 'subtotal', 'discount_type', 'discount_value', 'discount_amount', 'delivery_amount', 'additional_total', 'payable_total', 'initial_paid', 'amount_paid', 'return_request_key', 'return_preserves_original', 'cancelled_at', 'cancelled_by', 'cancellation_reason'],
            'invoice_lines' => ['quantity', 'purchase_price', 'sale_price', 'discount_amount', 'net_line_total'],
            'invoice_additional_lines' => ['invoice_id', 'description', 'quantity', 'unit_amount', 'row_total', 'source_note'],
            'ledger_settlements' => ['partner_id', 'invoice_id', 'amount', 'type', 'payment_method', 'settlement_date', 'notes', 'cancelled_at', 'cancelled_by', 'cancellation_reason'],
            'audit_logs' => ['auditable_type', 'auditable_id', 'event', 'user_id', 'old_values', 'new_values', 'reason'],
        ];
        foreach ($expected as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("فشل التحقق النهائي: الجدول {$table} غير موجود.");
            }
            $missing = array_values(array_filter($columns, fn (string $column) => ! Schema::hasColumn($table, $column)));
            if ($missing !== []) {
                throw new RuntimeException("فشل التحقق النهائي في {$table}: ".implode(', ', $missing));
            }
        }
    }

    private function addColumn(string $table, string $column, callable $definition): bool
    {
        if (Schema::hasColumn($table, $column)) {
            return false;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $definition($blueprint));
        $this->changes[] = "إضافة {$table}.{$column}";

        return true;
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        $exists = collect(Schema::getIndexes($table))->contains(fn (array $index) => $index['name'] === $name || $index['columns'] === $columns);
        if ($exists) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        $this->changes[] = "إضافة الفهرس {$name}";
    }

    private function addUniqueIndex(string $table, array $columns, string $name): void
    {
        $exists = collect(Schema::getIndexes($table))->contains(fn (array $index) => ($index['name'] === $name || $index['columns'] === $columns) && $index['unique']);
        if ($exists) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
        $this->changes[] = "إضافة الفهرس الفريد {$name}";
    }

    private function addForeignKey(string $table, string $column, string $on, string $references, string $name): void
    {
        $exists = collect(Schema::getForeignKeys($table))->contains(fn (array $key) => in_array($column, $key['columns'], true));
        if ($exists) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, $name)->references($references)->on($on)->nullOnDelete());
        $this->changes[] = "إضافة المفتاح الخارجي {$name}";
    }

    private function writeReport(array $backup, array $before, array $after): string
    {
        $path = 'backups/schema-upgrade-'.now()->format('Ymd-His-u').'.json';
        $payload = json_encode([
            'created_at' => now()->toIso8601String(),
            'database' => config('database.connections.mysql.database'),
            'backup' => $backup,
            'row_counts_before' => $before,
            'row_counts_after' => $after,
            'changes' => $this->changes,
            'historical_settlement_invoice_linkage' => 'left_null_unless_already_present',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (! Storage::disk('local')->put($path, $payload)) {
            throw new RuntimeException('اكتملت الترقية لكن تعذر حفظ تقرير التحقق.');
        }

        return Storage::disk('local')->path($path);
    }
}
