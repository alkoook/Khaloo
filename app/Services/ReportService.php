<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InAppNotification;
use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Quotation;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportService
{
    public const TYPES = [
        'sales' => 'المبيعات', 'purchases' => 'المشتريات', 'inventory' => 'المخزون',
        'partners' => 'العملاء والموردون', 'payments' => 'الدفعات', 'returns' => 'المرتجعات',
        'expenses' => 'المصاريف', 'profit' => 'الأرباح', 'quotations' => 'عروض الأسعار',
        'notifications' => 'التنبيهات', 'master' => 'التقرير الشامل',
    ];

    public function preview(string $type, array $filters): array
    {
        $filters = $this->appliedFilters($type, $filters);
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $partner = $filters['partner_id'] ?? null;
        $category = $filters['category_id'] ?? null;
        $count = null;
        $total = null;

        if (in_array($type, ['sales', 'purchases', 'returns'], true)) {
            $invoiceType = ['sales' => Invoice::TYPE_SALE, 'purchases' => Invoice::TYPE_PURCHASE, 'returns' => Invoice::TYPE_RETURN][$type];
            $query = Invoice::query()->active()->where('type', $invoiceType)
                ->when($partner, fn ($q) => $q->where('partner_id', $partner))
                ->when($category, fn ($q) => $q->whereHas('lines.product', fn ($product) => $product->where('category_id', $category)))
                ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
            $stats = $query->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(total), 0) as aggregate_total')->first();
            $count = (int) $stats->aggregate_count;
            $total = D::scale($stats->aggregate_total);
        } elseif ($type === 'inventory') {
            $count = Product::query()->when($category, fn ($q) => $q->where('category_id', $category))->count();
        } elseif ($type === 'partners') {
            $count = Partner::query()->when($partner, fn ($q) => $q->whereKey($partner))->count();
        } elseif ($type === 'payments') {
            $count = LedgerSettlement::query()->when($partner, fn ($q) => $q->where('partner_id', $partner))->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))->count();
        } elseif ($type === 'quotations') {
            $count = Quotation::query()->when($partner, fn ($q) => $q->where('partner_id', $partner))->when($category, fn ($q) => $q->whereHas('lines.product', fn ($product) => $product->where('category_id', $category)))->when($from, fn ($q) => $q->whereDate('quotation_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('quotation_date', '<=', $to))->count();
        } elseif ($type === 'expenses') {
            $count = DB::table('expenses')->when($from, fn ($q) => $q->whereDate('date', '>=', $from))->when($to, fn ($q) => $q->whereDate('date', '<=', $to))->count();
        } elseif ($type === 'notifications') {
            $count = InAppNotification::query()->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))->count();
        }

        if ($type === 'sales' || $type === 'profit') {
            [, $rows] = $this->rows($type, $filters);
            $count = count($rows);
            $total = $this->totalFor($type, $rows);
        }

        return ['count' => $count, 'total' => $total, 'filters' => $this->filterLabels($filters), 'title' => $this->reportTitle($type, $filters)];
    }

    public function appliedFilters(string $type, array $filters): array
    {
        $keys = match ($type) {
            'inventory' => ['category_id'],
            'partners' => ['partner_id'],
            'payments' => ['from', 'to', 'partner_id'],
            'expenses', 'notifications' => ['from', 'to'],
            default => ['from', 'to', 'partner_id', 'category_id'],
        };

        return array_intersect_key($filters, array_flip($keys));
    }

    public function filterLabels(array $filters): array
    {
        $labels = [];
        if (filled($filters['from'] ?? null)) {
            $labels['من تاريخ'] = $filters['from'];
        }
        if (filled($filters['to'] ?? null)) {
            $labels['إلى تاريخ'] = $filters['to'];
        }
        if ($id = ($filters['partner_id'] ?? null)) {
            $partner = Partner::query()->find($id);
            $labels[$partner?->isSupplier() ? 'المورد' : 'العميل'] = $partner?->name ?? 'غير محدد';
        }
        if ($id = ($filters['category_id'] ?? null)) {
            $labels['التصنيف'] = Category::query()->find($id)?->name ?? 'غير محدد';
        }

        return $labels;
    }

    public function reportTitle(string $type, array $filters): string
    {
        $filters = $this->appliedFilters($type, $filters);
        $title = 'تقرير '.(self::TYPES[$type] ?? 'البيانات');
        foreach ($this->filterLabels($filters) as $label => $value) {
            $title .= ' — '.$label.' '.$value;
        }

        return $title;
    }

    public function export(string $type, array $filters): string
    {
        $filters = $this->appliedFilters($type, $filters);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $types = $type === 'master' ? array_values(array_diff(array_keys(self::TYPES), ['master'])) : [$type];
        foreach ($types as $sheetType) {
            $sheetFilters = $this->appliedFilters($sheetType, $filters);
            [$headers, $rows] = $this->rows($sheetType, $sheetFilters);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle(mb_substr(self::TYPES[$sheetType], 0, 31));
            $sheet->setRightToLeft(true);
            $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
            $sheet->setCellValue('A1', $this->reportTitle($sheetType, $filters));
            $sheet->mergeCells("A1:{$lastColumn}1");
            $sheet->setCellValue('A2', 'المنشأة: '.app(SettingsService::class)->get('company_name', 'Khaloo'));
            $sheet->mergeCells("A2:{$lastColumn}2");
            $sheet->setCellValue('A3', $this->filterSummary($sheetFilters).' · تاريخ الإنشاء: '.now()->format('Y-m-d H:i'));
            $sheet->mergeCells("A3:{$lastColumn}3");
            $sheet->fromArray($headers, null, 'A5');
            if ($rows) {
                $sheet->fromArray($rows, null, 'A6');
            }
            $lastRow = count($rows) + 5;
            if (($total = $this->totalFor($sheetType, $rows)) !== null) {
                $sheet->setCellValue('A'.($lastRow + 1), 'الإجمالي');
                $sheet->setCellValue('B'.($lastRow + 1), $total);
                $lastRow++;
            }
            $this->style($sheet, count($headers), $lastRow, 5);
            if (in_array($sheetType, ['sales', 'purchases', 'returns'], true)) {
                $this->appendInvoiceLinesSheet($spreadsheet, $sheetType, $filters);
            }
        }
        $spreadsheet->setActiveSheetIndex(0);
        $path = $this->path($this->reportTitle($type, $filters).'-'.now()->format('Ymd-His-u').'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    public function statement(Partner $partner, ?string $from = null, ?string $to = null): string
    {
        $movements = app(FinancialService::class)->statementRows($partner, $from, $to);
        $openingPartner = $from ? app(FinancialService::class)->partnerBalancesQuery(null, null, now()->parse($from)->subDay()->toDateString())->find($partner->id) : null;
        $opening = $openingPartner ? app(FinancialService::class)->signedBalance($openingPartner) : '0.00';
        $rows = $movements->map(fn ($row) => [$row['date']->format('Y-m-d'), $row['reference'], $row['type'].' — '.$row['description'], $row['charge'], $row['payment'], $row['balance']])->all();
        if ($from) {
            array_unshift($rows, ['', '', 'الرصيد الافتتاحي', '', '', $opening]);
        }
        $closing = app(FinancialService::class)->partnerBalancesQuery(null, null, $to)->find($partner->id);
        $rows[] = ['', '', 'الرصيد الختامي المعتمد', '', '', $closing ? app(FinancialService::class)->signedBalance($closing) : 0.0];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('كشف الحساب');
        $sheet->setRightToLeft(true);
        $title = 'كشف حساب '.($partner->isCustomer() ? 'العميل ' : 'المورد ').$partner->name.' من '.($from ?: 'بداية السجل').' إلى '.($to ?: 'اليوم');
        $sheet->setCellValue('A1', $title)->mergeCells('A1:F1');
        $sheet->fromArray(['التاريخ', 'المرجع', 'البيان', 'قيمة الحركة', 'المدفوع/المستلم', 'الرصيد'], null, 'A3');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A4');
        }
        $this->style($sheet, 6, count($rows) + 3, 3);
        $path = $this->path($title.'-'.now()->format('Ymd-His-u').'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    public function statementMessage(Partner $partner, ?string $from = null, ?string $to = null): string
    {
        $rows = app(FinancialService::class)->statementRows($partner, $from, $to);
        $summary = app(FinancialService::class)->partnerSummary($partner);
        $text = "📑 كشف حساب\nالطرف: {$partner->name}\nالنوع: ".($partner->isCustomer() ? 'عميل' : 'مورد')
            ."\nالفترة: ".($from ?: 'بداية السجل').' إلى '.($to ?: 'اليوم')
            ."\nإجمالي الفواتير المتبقي: ".number_format($summary['invoiced'], 2)
            ."\nإجمالي الدفعات اللاحقة: ".number_format($summary['paid'], 2)
            ."\nالرصيد النهائي: ".number_format($summary['signed'], 2)."\n──────────\nآخر الحركات:";
        foreach ($rows->sortByDesc('date')->take(12) as $row) {
            $amount = D::cmp($row['charge'], 0) !== 0 ? $row['charge'] : $row['payment'];
            $text .= "\n• ".$row['date']->format('Y-m-d').' — '.$row['type'].' '.$row['reference'].' — '.number_format($amount, 2).' — الرصيد '.number_format($row['balance'], 2);
        }
        if ($rows->isEmpty()) {
            $text .= "\nلا توجد حركات ضمن هذه الفترة.";
        }

        return mb_substr($text, 0, 4000);
    }

    private function rows(string $type, array $filters): array
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;
        $partner = $filters['partner_id'] ?? null;
        $category = $filters['category_id'] ?? null;
        $date = fn ($q, $column = 'created_at') => $q->when($from, fn ($q) => $q->whereDate($column, '>=', $from))->when($to, fn ($q) => $q->whereDate($column, '<=', $to));

        return match ($type) {
            'sales' => (function () use ($from, $to, $partner, $category) {
                $events = app(SalesAccountingService::class)->activity($from, $to, $partner ? (int) $partner : null, $category ? (int) $category : null);

                return [['رقم الفاتورة', 'نوع الحركة', 'التاريخ', 'الوقت', 'العميل', 'الهاتف', 'عدد الأصناف', 'إجمالي الكميات', 'المجموع قبل الحسم', 'نوع الحسم', 'قيمة الحسم', 'صافي البضاعة', 'المدفوع عند الإنشاء', 'المدفوع المسجل', 'المتبقي الحالي', 'حالة الدفع الحالية', 'الفاتورة الأصلية', 'التوصيل', 'الخانات الإضافية'], $events->map(function ($event) {
                    $i = $event['invoice'];

                    return [$i->id, $i->isReturn() ? 'مرتجع' : 'بيع', $event['date']->format('Y-m-d'), $event['date']->format('H:i'),
                        $i->partner?->name ?: 'نقدي', $i->partner?->phone, $event['line_count'], $event['quantity'],
                        $event['subtotal'], $i->discount_type === 'percentage' ? 'نسبة مئوية' : (D::cmp($event['discount'], 0) !== 0 ? 'مبلغ ثابت' : 'بدون حسم'), $event['discount'],
                        $event['merchandise'], $i->initial_paid, $i->amount_paid, $i->remaining,
                        $i->remaining <= 0 ? 'مدفوعة بالكامل' : 'متبقي', $i->original_invoice_id, $event['delivery'], $i->isSale() ? $i->additional_total : '0.00'];
                })->all()];
            })(),
            'purchases', 'returns' => (function () use ($type, $partner, $category, $date) {
                $invoiceType = ['sales' => Invoice::TYPE_SALE, 'purchases' => Invoice::TYPE_PURCHASE, 'returns' => Invoice::TYPE_RETURN][$type];
                $q = Invoice::query()->active()->with('partner')->withCount('lines')->where('type', $invoiceType)->when($partner, fn ($q) => $q->where('partner_id', $partner))->when($category, fn ($q) => $q->whereHas('lines.product', fn ($product) => $product->where('category_id', $category)));
                $date($q);

                return [['رقم الفاتورة', 'نوع الفاتورة', 'التاريخ', 'الوقت', 'الطرف', 'الهاتف', 'عدد الأصناف', 'إجمالي الكميات', 'المجموع قبل الحسم', 'نوع الحسم', 'قيمة الحسم', 'الإجمالي بعد الحسم', 'المدفوع عند الإنشاء', 'إجمالي المدفوع', 'المتبقي', 'حالة الدفع', 'رقم الفاتورة الأصلية'], $q->withSum('lines', 'quantity')->get()->map(fn ($i) => [$i->id, $i->isSale() ? 'بيع' : ($i->isPurchase() ? 'شراء' : 'مرتجع'), $i->created_at->format('Y-m-d'), $i->created_at->format('H:i'), $i->partner?->name ?: 'نقدي', $i->partner?->phone, $i->lines_count, $i->lines_sum_quantity, $i->subtotal, $i->discount_type === 'percentage' ? 'نسبة مئوية' : ($i->discount_amount > 0 ? 'مبلغ ثابت' : 'بدون حسم'), $i->discount_amount, $i->total, $i->initial_paid, $i->amount_paid, $i->remaining, $i->remaining <= 0 ? 'مدفوعة بالكامل' : ($i->amount_paid > 0 ? 'مدفوعة جزئيًا' : 'غير مدفوعة'), $i->original_invoice_id])->all()];
            })(),
            'inventory' => (function () use ($category) {
                $q = Product::query()->with('category')->when($category, fn ($q) => $q->where('category_id', $category));

                return [['الرقم', 'المنتج', 'التصنيف', 'الكمية', 'حد التنبيه', 'تكلفة الشراء', 'سعر البيع', 'قيمة المخزون'], $q->get()->map(fn ($p) => [$p->id, $p->name, $p->category?->name, $p->quantity, $p->alert_stock_at, $p->purchase_price === null ? 'غير موثقة' : $p->purchase_price, $p->sale_price === null ? 'غير محدد' : $p->sale_price, $p->purchase_price === null && $p->quantity > 0 ? 'غير موثوقة' : D::mul($p->quantity, $p->purchase_price ?? 0)])->all()];
            })(),
            'partners' => (function () use ($partner) {
                $q = app(FinancialService::class)->partnerBalancesQuery()->when($partner, fn ($q) => $q->whereKey($partner));

                return [['الرقم', 'الاسم', 'النوع', 'الهاتف', 'الذمة المدينة', 'الذمة الدائنة'], $q->get()->map(fn ($p) => [$p->id, $p->name, $p->isCustomer() ? 'عميل' : 'مورد', $p->phone, app(FinancialService::class)->receivable($p), app(FinancialService::class)->payable($p)])->all()];
            })(),
            'payments' => (function () use ($partner, $from, $to) {
                $q = LedgerSettlement::query()->with('partner')->when($partner, fn ($q) => $q->where('partner_id', $partner))->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to));

                return [['الرقم', 'الطرف', 'نوع الطرف', 'التاريخ', 'نوع الحركة', 'المبلغ', 'ملاحظات'], $q->get()->map(fn ($p) => [$p->id, $p->partner?->name, $p->partner?->isCustomer() ? 'عميل' : 'مورد', $p->settlement_date->format('Y-m-d'), $p->type === LedgerSettlement::TYPE_INCOMING ? 'قبض' : 'دفع', $p->amount, $p->notes])->all()];
            })(),
            'expenses' => (function () use ($date) {
                $q = DB::table('expenses');
                $date($q, 'date');

                return [['الرقم', 'التاريخ', 'السبب', 'المبلغ'], $q->orderBy('date')->get()->map(fn ($e) => [$e->id, $e->date, $e->reason, $e->amount])->all()];
            })(),
            'profit' => (function () use ($from, $to, $partner, $category) {
                $events = app(SalesAccountingService::class)->activity($from, $to, $partner ? (int) $partner : null, $category ? (int) $category : null);

                return [['التاريخ', 'صافي مبيعات البضاعة', 'إيراد التوصيل', 'التكلفة التاريخية', 'مجمل الربح'], $events->groupBy(fn ($e) => $e['date']->toDateString())->map(function ($group, $day) {
                    $revenue = D::sum($group->pluck('merchandise'));
                    $delivery = D::sum($group->pluck('delivery'));
                    $cost = $group->sum('unknown') ? null : D::sum($group->pluck('cost'));

                    return [$day, $revenue, $delivery, $cost ?? 'غير موثوقة', $cost === null ? 'غير موثوق' : D::sub(D::add($revenue, $delivery), $cost)];
                })->values()->all()];
            })(),
            'quotations' => (function () use ($partner, $category, $date) {
                $q = Quotation::query()->with('partner')->when($partner, fn ($q) => $q->where('partner_id', $partner))->when($category, fn ($q) => $q->whereHas('lines.product', fn ($product) => $product->where('category_id', $category)));
                $date($q, 'quotation_date');

                return [['الرقم', 'العميل', 'التاريخ', 'المجموع', 'نوع الحسم', 'قيمة الحسم', 'مبلغ الحسم', 'الإجمالي'], $q->get()->map(fn ($r) => [$r->number, $r->display_customer, $r->quotation_date->format('Y-m-d'), $r->subtotal, $r->discount_type === 'percentage' ? 'نسبة مئوية' : ($r->discount_amount > 0 ? 'ثابت' : 'بدون حسم'), $r->discount_value, $r->discount_amount, $r->total])->all()];
            })(),
            'notifications' => (function () use ($date) {
                $q = InAppNotification::query();
                $date($q);
                $types = ['stock_zero' => 'نفاد مخزون', 'stock_low' => 'مخزون منخفض', 'receivable_high' => 'ذمة مدينة مرتفعة', 'payable_high' => 'ذمة دائنة مرتفعة'];
                $severity = ['danger' => 'حرج', 'warning' => 'مهم', 'info' => 'معلومة'];

                return [['الرقم', 'النوع', 'العنوان', 'الرسالة', 'الأهمية', 'مقروء', 'التاريخ'], $q->get()->map(fn ($n) => [$n->id, $types[$n->type] ?? 'تنبيه', $n->title, $n->message, $severity[$n->severity] ?? 'تنبيه', $n->read_at ? 'نعم' : 'لا', $n->created_at->format('Y-m-d H:i')])->all()];
            })(),
            default => [[], []],
        };
    }

    private function totalFor(string $type, array $rows): ?string
    {
        $column = match ($type) {
            'sales','purchases','returns' => 11, 'inventory' => 7, 'payments' => 5, 'expenses' => 3, 'profit' => 4, 'quotations' => 7, default => null
        };

        return $column === null ? null : (collect($rows)->contains(fn ($row) => ! is_numeric($row[$column] ?? 0)) ? null : D::sum(collect($rows)->map(fn ($row) => $row[$column] ?? 0)));
    }

    private function style($sheet, int $columns, int $lastRow, int $headerRow = 1): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}")->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1F2937']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]]);
        $sheet->getStyle("A{$headerRow}:{$last}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
        $sheet->getStyle("A1:{$last}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach (range(1, $columns) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
        $sheet->freezePane('A'.($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$last}{$lastRow}");
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('9F1239');
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getPageSetup()->setFitToWidth(1);
    }

    private function appendInvoiceLinesSheet(Spreadsheet $spreadsheet, string $type, array $filters): void
    {
        $invoiceType = ['sales' => Invoice::TYPE_SALE, 'purchases' => Invoice::TYPE_PURCHASE, 'returns' => Invoice::TYPE_RETURN][$type];
        $query = Invoice::query()->active()->with(['partner', 'lines.product.category'])->where('type', $invoiceType)
            ->when($filters['partner_id'] ?? null, fn ($q, $id) => $q->where('partner_id', $id))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereHas('lines.product', fn ($product) => $product->where('category_id', $id)))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
        $invoices = $query->get();
        $categoryId = $filters['category_id'] ?? null;
        $rows = $invoices->flatMap(function ($invoice) use ($categoryId) {
            $lines = $invoice->lines;
            if ($categoryId) {
                $lines = $lines->filter(fn ($line) => (int) $line->product?->category_id === (int) $categoryId);
            }

            return $lines->map(fn ($line) => [
                $invoice->id, $invoice->created_at->format('Y-m-d H:i'), $invoice->partner?->name ?: 'نقدي',
                $line->product_name, $line->product?->category?->name, $line->quantity,
                $line->unit_price, $line->purchase_price === null ? 'غير موثقة' : $line->purchase_price, $line->line_total,
                $line->discount_amount, ($line->net_line_total ?? $line->line_total),
            ]);
        })->all();
        if ($type === 'sales') {
            $events = app(SalesAccountingService::class)->activity($filters['from'] ?? null, $filters['to'] ?? null,
                isset($filters['partner_id']) ? (int) $filters['partner_id'] : null,
                isset($filters['category_id']) ? (int) $filters['category_id'] : null);
            $invoices = $events->pluck('invoice')->unique('id');
            $rows = $events->flatMap(function ($event) use ($categoryId) {
                $invoice = $event['invoice'];
                $lines = $invoice->isSale() ? $invoice->lines->concat($invoice->returns->flatMap->lines) : $invoice->lines;
                if ($categoryId) {
                    $lines = $lines->filter(fn ($line) => (int) $line->product?->category_id === (int) $categoryId);
                }
                $sign = $invoice->isReturn() ? '-1' : '1';

                return $lines->map(fn ($line) => [
                    $invoice->id, $event['date']->format('Y-m-d H:i'), $invoice->partner?->name ?: 'نقدي',
                    $line->product_name, $line->product?->category?->name, D::mul($line->quantity, $sign, 3),
                    $line->unit_price, $line->purchase_price ?? 'غير موثقة', D::mul($line->line_total, $sign),
                    D::mul($line->discount_amount, $sign), D::mul($line->net_line_total ?? $line->line_total, $sign),
                ]);
            })->all();
        }
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(mb_substr('تفاصيل '.self::TYPES[$type], 0, 31));
        $sheet->setRightToLeft(true);
        $sheet->fromArray(['رقم الفاتورة', 'التاريخ والوقت', 'الطرف', 'الصنف', 'التصنيف', 'الكمية', 'سعر الوحدة', 'التكلفة', 'إجمالي السطر', 'حسم السطر', 'صافي السطر'], null, 'A1');
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2');
        }
        $this->style($sheet, 11, count($rows) + 1);

        $partnerIds = $invoices->pluck('partner_id')->filter()->unique();
        $payments = LedgerSettlement::query()->with('partner')->whereIn('partner_id', $partnerIds)
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('settlement_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('settlement_date', '<=', $date))->get()
            ->map(fn ($payment) => [$payment->id, $payment->partner?->name, $payment->settlement_date->format('Y-m-d'), $payment->type === LedgerSettlement::TYPE_INCOMING ? 'قبض' : 'دفع', $payment->amount, $payment->notes])->all();
        $paymentSheet = $spreadsheet->createSheet();
        $paymentSheet->setRightToLeft(true);
        $paymentSheet->setTitle(mb_substr('حركات أطراف '.self::TYPES[$type], 0, 31));
        $paymentSheet->fromArray(['رقم الحركة', 'الطرف', 'التاريخ', 'نوع الحركة', 'المبلغ', 'ملاحظات'], null, 'A1');
        if ($payments) {
            $paymentSheet->fromArray($payments, null, 'A2');
        }
        $this->style($paymentSheet, 6, count($payments) + 1);

        if ($type === 'sales') {
            $returns = Invoice::query()->active()->with('partner')->where('type', Invoice::TYPE_RETURN)->whereHas('originalInvoice', fn ($q) => $q->active())
                ->when($filters['partner_id'] ?? null, fn ($q, $id) => $q->where('partner_id', $id))
                ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereHas('lines.product', fn ($p) => $p->where('category_id', $id)))
                ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))->get()
                ->map(fn ($return) => [$return->id, $return->original_invoice_id, $return->created_at->format('Y-m-d H:i'), $return->partner?->name ?: 'نقدي', $return->total, $return->amount_paid])->all();
            $returnSheet = $spreadsheet->createSheet();
            $returnSheet->setRightToLeft(true);
            $returnSheet->setTitle('مرتجعات المبيعات');
            $returnSheet->fromArray(['رقم المرتجع', 'رقم الفاتورة الأصلية', 'التاريخ والوقت', 'الطرف', 'قيمة المرتجع', 'الاسترداد النقدي'], null, 'A1');
            if ($returns) {
                $returnSheet->fromArray($returns, null, 'A2');
            }
            $this->style($returnSheet, 6, count($returns) + 1);
        }
    }

    private function filterSummary(array $filters): string
    {
        return collect($this->filterLabels($filters))->map(fn ($value, $label) => $label.': '.$value)->implode(' · ');
    }

    private function path(string $filename): string
    {
        $directory = storage_path('app/private/exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.DIRECTORY_SEPARATOR.preg_replace('/[<>:"\\\\\\/|?*\x00-\x1F]+/u', '-', $filename);
    }
}
