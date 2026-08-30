<?php

namespace App\Services;

use App\Models\InAppNotification;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Quotation;
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
        if ($type === 'master') return ['count' => collect(array_keys(self::TYPES))->reject(fn ($t) => $t === 'master')->sum(fn ($t) => count($this->rows($t, $filters)[1])), 'total' => null, 'filters'=>$this->filterLabels($filters), 'title'=>$this->reportTitle($type,$filters)];
        [$headers, $rows] = $this->rows($type, $filters);
        return ['count' => count($rows), 'total' => $this->totalFor($type, $rows), 'filters'=>$this->filterLabels($filters), 'title'=>$this->reportTitle($type,$filters)];
    }

    public function filterLabels(array $filters): array
    {
        $labels = ['الفترة'=>'من '.($filters['from'] ?? 'بداية السجل').' إلى '.($filters['to'] ?? 'اليوم')];
        if ($id = ($filters['partner_id'] ?? null)) $labels['الطرف'] = Partner::query()->find($id)?->name ?? 'غير محدد';
        if ($id = ($filters['category_id'] ?? null)) $labels['التصنيف'] = Category::query()->find($id)?->name ?? 'غير محدد';
        return $labels;
    }

    public function reportTitle(string $type, array $filters): string
    {
        $title = 'تقرير '.(self::TYPES[$type] ?? 'البيانات');
        if (($filters['from'] ?? null) || ($filters['to'] ?? null)) $title .= ' من '.($filters['from'] ?? 'بداية السجل').' إلى '.($filters['to'] ?? 'اليوم');
        $qualifiers = [];
        if ($id = ($filters['category_id'] ?? null)) {
            if ($category = Category::query()->find($id)) $qualifiers[] = 'التصنيف: '.$category->name;
        }
        if ($id = ($filters['partner_id'] ?? null)) {
            if ($partner = Partner::query()->find($id)) $qualifiers[] = 'الطرف: '.$partner->name;
        }
        if ($qualifiers) $title .= ' — '.implode(' — ', $qualifiers);
        return $title;
    }

    public function export(string $type, array $filters): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $types = $type === 'master' ? array_values(array_diff(array_keys(self::TYPES), ['master'])) : [$type];
        foreach ($types as $sheetType) {
            [$headers, $rows] = $this->rows($sheetType, $filters);
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle(mb_substr(self::TYPES[$sheetType], 0, 31));
            $sheet->setRightToLeft(true);
            $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
            $sheet->setCellValue('A1', $this->reportTitle($sheetType, $filters));
            $sheet->mergeCells("A1:{$lastColumn}1");
            $sheet->setCellValue('A2', 'المنشأة: '.app(SettingsService::class)->get('company_name', 'Khaloo'));
            $sheet->mergeCells("A2:{$lastColumn}2");
            $sheet->setCellValue('A3', $this->filterSummary($filters).' · تاريخ الإنشاء: '.now()->format('Y-m-d H:i'));
            $sheet->mergeCells("A3:{$lastColumn}3");
            $sheet->fromArray($headers, null, 'A5');
            if ($rows) $sheet->fromArray($rows, null, 'A6');
            $lastRow = count($rows) + 5;
            if (($total = $this->totalFor($sheetType, $rows)) !== null) {
                $sheet->setCellValue('A'.($lastRow + 1), 'الإجمالي');
                $sheet->setCellValue('B'.($lastRow + 1), $total);
                $lastRow++;
            }
            $this->style($sheet, count($headers), $lastRow, 5);
            if (in_array($sheetType, ['sales', 'purchases', 'returns'], true)) $this->appendInvoiceLinesSheet($spreadsheet, $sheetType, $filters);
        }
        $spreadsheet->setActiveSheetIndex(0);
        $path = $this->path($this->reportTitle($type,$filters).'-'.now()->format('Ymd-His').'.xlsx');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        return $path;
    }

    public function statement(Partner $partner, ?string $from = null, ?string $to = null): string
    {
        $entries = collect();
        Invoice::query()->where('partner_id', $partner->id)->whereIn('type', [Invoice::TYPE_SALE, Invoice::TYPE_PURCHASE])
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->get()->each(function ($invoice) use ($entries, $partner): void {
                $due = (float) $invoice->total - (float) $invoice->amount_paid;
                $relevant = ($partner->isCustomer() && $invoice->isSale()) || ($partner->isSupplier() && $invoice->isPurchase());
                if ($relevant) $entries->push(['date' => $invoice->created_at, 'ref' => '#'.$invoice->id, 'description' => $invoice->isSale() ? 'فاتورة مبيعات' : 'فاتورة مشتريات', 'debit' => $due, 'credit' => 0]);
            });
        LedgerSettlement::query()->where('partner_id', $partner->id)
            ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))
            ->get()->each(function ($payment) use ($entries, $partner): void {
                $reduces = ($partner->isCustomer() && $payment->type === LedgerSettlement::TYPE_INCOMING) || ($partner->isSupplier() && $payment->type === LedgerSettlement::TYPE_OUTGOING);
                $entries->push(['date' => $payment->settlement_date, 'ref' => 'P-'.$payment->id, 'description' => $payment->notes ?: 'دفعة', 'debit' => $reduces ? 0 : (float) $payment->amount, 'credit' => $reduces ? (float) $payment->amount : 0]);
            });
        $balance = 0.0;
        $rows = $entries->sortBy(fn ($row) => $row['date']->format('Y-m-d').'|'.$row['ref'])->values()->map(function ($row) use (&$balance): array {
            $balance += $row['debit'] - $row['credit'];
            return [$row['date']->format('Y-m-d'), $row['ref'], $row['description'], $row['debit'], $row['credit'], round($balance, 2)];
        })->all();
        $authoritativePartner = app(FinancialService::class)->partnerBalancesQuery(null, $from, $to)->find($partner->id);
        $authoritativeBalance = $authoritativePartner
            ? app(FinancialService::class)->balanceFor($authoritativePartner, $partner->isSupplier() ? 'payable' : 'receivable')
            : 0.0;
        $rows[] = ['', '', 'الرصيد الختامي المعتمد', '', '', $authoritativeBalance];
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet(); $sheet->setTitle('كشف الحساب'); $sheet->setRightToLeft(true);
        $statementTitle = 'كشف حساب '.($partner->isCustomer()?'العميل ':'المورد ').$partner->name.' من '.($from ?: 'بداية السجل').' إلى '.($to ?: 'اليوم');
        $sheet->setCellValue('A1', $statementTitle); $sheet->mergeCells('A1:F1');
        $sheet->fromArray(['التاريخ','المرجع','البيان','مدين','دائن','الرصيد'], null, 'A3');
        if ($rows) $sheet->fromArray($rows, null, 'A4');
        $this->style($sheet, 6, count($rows) + 3, 3);
        $path = $this->path($statementTitle.'-'.now()->format('Ymd-His').'.xlsx');
        (new Xlsx($spreadsheet))->save($path); $spreadsheet->disconnectWorksheets();
        return $path;
    }

    public function statementMessage(Partner $partner, ?string $from = null, ?string $to = null): string
    {
        $movements = collect();
        Invoice::query()->where('partner_id', $partner->id)->whereIn('type', [Invoice::TYPE_SALE, Invoice::TYPE_PURCHASE])
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->get()->each(function (Invoice $invoice) use ($movements, $partner): void {
                $relevant = ($partner->isCustomer() && $invoice->isSale()) || ($partner->isSupplier() && $invoice->isPurchase());
                if ($relevant) $movements->push(['date'=>$invoice->created_at, 'label'=>'فاتورة رقم '.$invoice->id, 'debit'=>(float)$invoice->remaining, 'credit'=>0.0]);
            });
        LedgerSettlement::query()->where('partner_id', $partner->id)
            ->when($from, fn ($q) => $q->whereDate('settlement_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('settlement_date', '<=', $to))
            ->get()->each(function (LedgerSettlement $payment) use ($movements, $partner): void {
                $reduces = ($partner->isCustomer() && $payment->type === LedgerSettlement::TYPE_INCOMING) || ($partner->isSupplier() && $payment->type === LedgerSettlement::TYPE_OUTGOING);
                $movements->push(['date'=>$payment->settlement_date, 'label'=>$payment->notes ?: ($payment->type === LedgerSettlement::TYPE_INCOMING ? 'دفعة مقبوضة' : 'دفعة مدفوعة'), 'debit'=>$reduces ? 0.0 : (float)$payment->amount, 'credit'=>$reduces ? (float)$payment->amount : 0.0]);
            });
        $opening = 0.0;
        if ($from) {
            $before = now()->parse($from)->subDay()->toDateString();
            $openingPartner = app(FinancialService::class)->partnerBalancesQuery(null, null, $before)->find($partner->id);
            if ($openingPartner) $opening = app(FinancialService::class)->balanceFor($openingPartner, $partner->isSupplier() ? 'payable' : 'receivable');
        }
        $authoritative = app(FinancialService::class)->partnerBalancesQuery(null, null, $to)->find($partner->id);
        $closing = $authoritative ? app(FinancialService::class)->balanceFor($authoritative, $partner->isSupplier() ? 'payable' : 'receivable') : 0.0;
        $debit = $movements->sum('debit'); $credit = $movements->sum('credit');
        $type = $partner->isCustomer() ? 'عميل' : 'مورد';
        $text = "📑 كشف حساب\nالطرف: {$partner->name}\nالنوع: {$type}\nالفترة: ".($from ?: 'بداية السجل').' إلى '.($to ?: 'اليوم')
            ."\nالرصيد الافتتاحي: ".number_format($opening, 2)."\nإجمالي المدين: ".number_format($debit, 2)
            ."\nإجمالي الدائن: ".number_format($credit, 2)."\nالرصيد النهائي: ".number_format($closing, 2)."\n──────────\nآخر الحركات:";
        foreach ($movements->sortByDesc('date')->take(12) as $movement) {
            $amount = $movement['debit'] ?: $movement['credit'];
            $direction = $movement['debit'] ? 'مدين' : 'دائن';
            $text .= "\n• ".$movement['date']->format('Y-m-d').' — '.$movement['label'].' — '.number_format($amount, 2)." ({$direction})";
        }
        if ($movements->isEmpty()) $text .= "\nلا توجد حركات ضمن هذه الفترة.";
        return mb_substr($text, 0, 4000);
    }

    private function rows(string $type, array $filters): array
    {
        $from = $filters['from'] ?? null; $to = $filters['to'] ?? null; $partner = $filters['partner_id'] ?? null; $category = $filters['category_id'] ?? null;
        $date = fn ($q, $column = 'created_at') => $q->when($from, fn ($q) => $q->whereDate($column, '>=', $from))->when($to, fn ($q) => $q->whereDate($column, '<=', $to));
        return match ($type) {
            'sales', 'purchases', 'returns' => (function () use ($type, $partner, $category, $date) {
                $invoiceType = ['sales'=>Invoice::TYPE_SALE,'purchases'=>Invoice::TYPE_PURCHASE,'returns'=>Invoice::TYPE_RETURN][$type];
                $q = Invoice::query()->with('partner')->withCount('lines')->where('type',$invoiceType)->when($partner,fn($q)=>$q->where('partner_id',$partner))->when($category,fn($q)=>$q->whereHas('lines.product',fn($product)=>$product->where('category_id',$category))); $date($q);
                return [['رقم الفاتورة','نوع الفاتورة','التاريخ','الوقت','الطرف','الهاتف','عدد الأصناف','إجمالي الكميات','الإجمالي','المدفوع عند الإنشاء','إجمالي المدفوع','المتبقي','حالة الدفع','رقم الفاتورة الأصلية'], $q->withSum('lines','quantity')->get()->map(fn($i)=>[$i->id,$i->isSale()?'بيع':($i->isPurchase()?'شراء':'مرتجع'),$i->created_at->format('Y-m-d'),$i->created_at->format('H:i'),$i->partner?->name ?: 'نقدي',$i->partner?->phone,$i->lines_count,(float)$i->lines_sum_quantity,(float)$i->total,(float)$i->initial_paid,(float)$i->amount_paid,(float)$i->remaining,$i->remaining<=0?'مدفوعة بالكامل':($i->amount_paid>0?'مدفوعة جزئيًا':'غير مدفوعة'),$i->original_invoice_id])->all()];
            })(),
            'inventory' => (function () use ($category) { $q=Product::query()->with('category')->when($category,fn($q)=>$q->where('category_id',$category)); return [['الرقم','المنتج','التصنيف','الكمية','حد التنبيه','سعر الشراء','سعر البيع','قيمة المخزون'],$q->get()->map(fn($p)=>[$p->id,$p->name,$p->category?->name,$p->quantity,$p->alert_stock_at,(float)$p->purchase_price,(float)$p->sale_price,round($p->quantity*(float)$p->purchase_price,2)])->all()]; })(),
            'partners' => (function () use ($partner) { $q=app(FinancialService::class)->partnerBalancesQuery()->when($partner,fn($q)=>$q->whereKey($partner)); return [['الرقم','الاسم','النوع','الهاتف','الذمة المدينة','الذمة الدائنة'],$q->get()->map(fn($p)=>[$p->id,$p->name,$p->isCustomer()?'عميل':'مورد',$p->phone,app(FinancialService::class)->receivable($p),app(FinancialService::class)->payable($p)])->all()]; })(),
            'payments' => (function () use ($partner,$from,$to) { $q=LedgerSettlement::query()->with('partner')->when($partner,fn($q)=>$q->where('partner_id',$partner))->when($from,fn($q)=>$q->whereDate('settlement_date','>=',$from))->when($to,fn($q)=>$q->whereDate('settlement_date','<=',$to)); return [['الرقم','الطرف','نوع الطرف','التاريخ','نوع الحركة','المبلغ','ملاحظات'],$q->get()->map(fn($p)=>[$p->id,$p->partner?->name,$p->partner?->isCustomer()?'عميل':'مورد',$p->settlement_date->format('Y-m-d'),$p->type===LedgerSettlement::TYPE_INCOMING?'قبض':'دفع',(float)$p->amount,$p->notes])->all()]; })(),
            'expenses' => (function () use ($date) { $q=DB::table('expenses'); $date($q,'date'); return [['الرقم','التاريخ','السبب','المبلغ'],$q->orderBy('date')->get()->map(fn($e)=>[$e->id,$e->date,$e->reason,(float)$e->amount])->all()]; })(),
            'profit' => (function () use ($from,$to,$partner,$category) { $q=DB::table('invoice_lines')->join('invoices','invoices.id','=','invoice_lines.invoice_id')->leftJoin('products','products.id','=','invoice_lines.product_id')->where('invoices.type',Invoice::TYPE_SALE)->when($partner,fn($q)=>$q->where('invoices.partner_id',$partner))->when($category,fn($q)=>$q->where('products.category_id',$category))->when($from,fn($q)=>$q->whereDate('invoices.created_at','>=',$from))->when($to,fn($q)=>$q->whereDate('invoices.created_at','<=',$to))->selectRaw('DATE(invoices.created_at) day, SUM(invoice_lines.line_total) revenue, SUM(invoice_lines.purchase_price * invoice_lines.quantity) cost')->groupBy('day')->orderBy('day'); return [['التاريخ','الإيراد','التكلفة','الربح'],$q->get()->map(fn($r)=>[$r->day,(float)$r->revenue,(float)$r->cost,round($r->revenue-$r->cost,2)])->all()]; })(),
            'quotations' => (function () use ($partner,$category,$date) { $q=Quotation::query()->with('partner')->when($partner,fn($q)=>$q->where('partner_id',$partner))->when($category,fn($q)=>$q->whereHas('lines.product',fn($product)=>$product->where('category_id',$category))); $date($q,'quotation_date'); return [['الرقم','العميل','التاريخ','المجموع','الحسم','الإجمالي'],$q->get()->map(fn($r)=>[$r->number,$r->display_customer,$r->quotation_date->format('Y-m-d'),(float)$r->subtotal,(float)$r->discount,(float)$r->total])->all()]; })(),
            'notifications' => (function () use ($date) { $q=InAppNotification::query(); $date($q); $types=['stock_zero'=>'نفاد مخزون','stock_low'=>'مخزون منخفض','receivable_high'=>'ذمة مدينة مرتفعة','payable_high'=>'ذمة دائنة مرتفعة']; $severity=['danger'=>'حرج','warning'=>'مهم','info'=>'معلومة']; return [['الرقم','النوع','العنوان','الرسالة','الأهمية','مقروء','التاريخ'],$q->get()->map(fn($n)=>[$n->id,$types[$n->type]??'تنبيه',$n->title,$n->message,$severity[$n->severity]??'تنبيه',$n->read_at?'نعم':'لا',$n->created_at->format('Y-m-d H:i')])->all()]; })(),
            default => [[], []],
        };
    }

    private function totalFor(string $type, array $rows): ?float
    {
        $column = match ($type) { 'sales','purchases','returns' => 8, 'inventory' => 7, 'payments' => 5, 'expenses' => 3, 'profit' => 3, 'quotations' => 5, default => null };
        return $column === null ? null : round(collect($rows)->sum(fn ($row) => (float) ($row[$column] ?? 0)), 2);
    }

    private function style($sheet, int $columns, int $lastRow, int $headerRow = 1): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);
        $sheet->getStyle("A{$headerRow}:{$last}{$headerRow}")->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']], 'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>'1F2937']], 'alignment'=>['horizontal'=>Alignment::HORIZONTAL_CENTER]]);
        $sheet->getStyle("A{$headerRow}:{$last}{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D1D5DB');
        $sheet->getStyle("A1:{$last}{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        foreach (range(1, $columns) as $column) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        $sheet->freezePane('A'.($headerRow + 1)); $sheet->setAutoFilter("A{$headerRow}:{$last}{$lastRow}");
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('9F1239');
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getPageSetup()->setFitToWidth(1);
    }

    private function appendInvoiceLinesSheet(Spreadsheet $spreadsheet, string $type, array $filters): void
    {
        $invoiceType = ['sales'=>Invoice::TYPE_SALE,'purchases'=>Invoice::TYPE_PURCHASE,'returns'=>Invoice::TYPE_RETURN][$type];
        $query = Invoice::query()->with(['partner','lines.product.category'])->where('type', $invoiceType)
            ->when($filters['partner_id'] ?? null, fn ($q, $id) => $q->where('partner_id', $id))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->whereHas('lines.product', fn ($product) => $product->where('category_id', $id)))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
        $invoices = $query->get();
        $categoryId = $filters['category_id'] ?? null;
        $rows = $invoices->flatMap(function ($invoice) use ($categoryId) {
            $lines = $invoice->lines;
            if ($categoryId) $lines = $lines->filter(fn ($line) => (int) $line->product?->category_id === (int) $categoryId);
            return $lines->map(fn ($line) => [
                $invoice->id, $invoice->created_at->format('Y-m-d H:i'), $invoice->partner?->name ?: 'نقدي',
                $line->product_name, $line->product?->category?->name, (float) $line->quantity,
                (float) $line->unit_price, (float) $line->purchase_price, (float) $line->line_total,
            ]);
        })->all();
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(mb_substr('تفاصيل '.self::TYPES[$type], 0, 31)); $sheet->setRightToLeft(true);
        $sheet->fromArray(['رقم الفاتورة','التاريخ والوقت','الطرف','الصنف','التصنيف','الكمية','سعر الوحدة','التكلفة','إجمالي السطر'], null, 'A1');
        if ($rows) $sheet->fromArray($rows, null, 'A2');
        $this->style($sheet, 9, count($rows) + 1);

        $partnerIds = $invoices->pluck('partner_id')->filter()->unique();
        $payments = LedgerSettlement::query()->with('partner')->whereIn('partner_id', $partnerIds)
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('settlement_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('settlement_date', '<=', $date))->get()
            ->map(fn ($payment) => [$payment->id,$payment->partner?->name,$payment->settlement_date->format('Y-m-d'),$payment->type===LedgerSettlement::TYPE_INCOMING?'قبض':'دفع',(float)$payment->amount,$payment->notes])->all();
        $paymentSheet = $spreadsheet->createSheet(); $paymentSheet->setRightToLeft(true);
        $paymentSheet->setTitle(mb_substr('حركات أطراف '.self::TYPES[$type], 0, 31));
        $paymentSheet->fromArray(['رقم الحركة','الطرف','التاريخ','نوع الحركة','المبلغ','ملاحظات'], null, 'A1');
        if ($payments) $paymentSheet->fromArray($payments, null, 'A2');
        $this->style($paymentSheet, 6, count($payments) + 1);

        if ($type === 'sales') {
            $returns = Invoice::query()->with('partner')->where('type', Invoice::TYPE_RETURN)->whereIn('original_invoice_id', $invoices->pluck('id'))->get()
                ->map(fn ($return) => [$return->id,$return->original_invoice_id,$return->created_at->format('Y-m-d H:i'),$return->partner?->name ?: 'نقدي',(float)$return->total,(float)$return->amount_paid])->all();
            $returnSheet = $spreadsheet->createSheet(); $returnSheet->setRightToLeft(true); $returnSheet->setTitle('مرتجعات المبيعات');
            $returnSheet->fromArray(['رقم المرتجع','رقم الفاتورة الأصلية','التاريخ والوقت','الطرف','قيمة المرتجع','الاسترداد النقدي'], null, 'A1');
            if ($returns) $returnSheet->fromArray($returns, null, 'A2');
            $this->style($returnSheet, 6, count($returns) + 1);
        }
    }

    private function filterSummary(array $filters): string
    {
        return collect($this->filterLabels($filters))->map(fn ($value,$label)=>$label.': '.$value)->implode(' · ');
    }

    private function path(string $filename): string
    {
        $directory = storage_path('app/private/exports');
        if (! is_dir($directory)) mkdir($directory, 0755, true);
        return $directory.DIRECTORY_SEPARATOR.preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $filename);
    }
}
