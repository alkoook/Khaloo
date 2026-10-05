<?php

namespace App\Livewire\Partners;

use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Services\ExportFileService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\TemplateProcessor;

class Index extends Component
{
    use WithFileUploads, WithPagination;

    public string $search = '';

    public string $typeFilter = '';

    public bool $showStatementModal = false;

    public ?int $statementPartnerId = null;

    public string $statementFromDate = '';

    public string $statementToDate = '';

    public function mount(): void
    {
        $page = (int) request()->get('page', 1);
        if ($page > 1) {
            $this->setPage($page);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function openStatementModal(int $id): void
    {
        $this->statementPartnerId = $id;
        $this->statementFromDate = date('Y-m-01');
        $this->statementToDate = date('Y-m-t');
        $this->showStatementModal = true;
    }

    public function closeStatementModal(): void
    {
        $this->showStatementModal = false;
        $this->statementPartnerId = null;
    }

    public function generateStatement()
    {
        $partner = Partner::findOrFail($this->statementPartnerId);

        $templatePath = storage_path('app/public/templates/account_statement.docx');
        if (! file_exists($templatePath)) {
            session()->flash('error', 'قالب كشف الحساب غير موجود. يرجى إضافته في storage/app/public/templates/account_statement.docx');

            return;
        }

        $templateProcessor = new TemplateProcessor($templatePath);
        $templateProcessor->setValue('partner_name', $partner->name);
        $templateProcessor->setValue('from_date', $this->statementFromDate);
        $templateProcessor->setValue('to_date', $this->statementToDate);

        // Fetch Invoices
        $invoices = Invoice::with('lines')->active()->where('partner_id', $partner->id)
            ->whereBetween('created_at', [$this->statementFromDate.' 00:00:00', $this->statementToDate.' 23:59:59'])
            ->get();

        // Ledger Balance
        $invoiceType = $partner->isCustomer() ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE;
        $ledgerBalance = DB::table('invoices')
            ->where('partner_id', $partner->id)
            ->where('type', $invoiceType)
            ->where('status', Invoice::STATUS_ACTIVE)
            ->sum(DB::raw('total - COALESCE(amount_paid, 0)'));

        $templateProcessor->setValue('ledger_balance', number_format((float) $ledgerBalance, 2));

        // Stable Block Cloning for Invoices
        if (count($invoices) > 0) {
            // Standard block cloning without indexing to keep XML simple
            $templateProcessor->cloneBlock('invoice_block', count($invoices), true, false);

            foreach ($invoices as $invoice) {
                // We use limit=1 to replace only the first occurrence (which belongs to the current invoice block)
                $templateProcessor->setValue('invoice_id', $invoice->id, 1);
                $templateProcessor->setValue('invoice_date', $invoice->created_at->format('Y-m-d'), 1);
                $templateProcessor->setValue('invoice_total', number_format((float) $invoice->total, 2), 1);
                $templateProcessor->setValue('invoice_paid', number_format((float) $invoice->initial_paid, 2), 1);
                $templateProcessor->setValue('invoice_balance', number_format((float) ($invoice->total - $invoice->initial_paid), 2), 1);

                // Handle Multiple Rows per Invoice using Row Cloning
                // Matching user's template: ${l_products}, ${l_qtys}, ${l_prices}, ${l_totals}
                $invoiceLines = [];
                foreach ($invoice->lines as $line) {
                    $invoiceLines[] = [
                        'l_products' => $line->product_name ?? 'منتج',
                        'l_qtys' => (string) $line->quantity,
                        'l_prices' => number_format((float) $line->unit_price, 2),
                        'l_totals' => number_format((float) ($line->quantity * $line->unit_price), 2),
                    ];
                }

                if (count($invoiceLines) > 0) {
                    try {
                        $templateProcessor->cloneRowAndSetValues('l_products', $invoiceLines);
                    } catch (\Exception $e) {
                        // Fallback if row not found
                        $templateProcessor->setValue('l_products', 'Error: Placeholder not found in table row', 1);
                    }
                } else {
                    $templateProcessor->setValue('l_products', '-', 1);
                    $templateProcessor->setValue('l_qtys', '0', 1);
                    $templateProcessor->setValue('l_prices', '0', 1);
                    $templateProcessor->setValue('l_totals', '0', 1);
                }
            }
        } else {
            $templateProcessor->cloneBlock('invoice_block', 0);
        }

        // Fetch Payments
        $settlements = LedgerSettlement::where('partner_id', $partner->id)
            ->whereBetween('settlement_date', [$this->statementFromDate, $this->statementToDate])
            ->get();

        $paymentsRun = new TextRun;
        if ($settlements->count() > 0) {
            foreach ($settlements as $index => $payment) {
                if ($index > 0) {
                    $paymentsRun->addTextBreak();
                }

                $typeString = $payment->type === LedgerSettlement::TYPE_INCOMING ? 'وارد' : 'صادر';
                $formattedAmount = number_format((float) $payment->amount, 2);
                $formattedDate = $payment->settlement_date->format('Y-m-d');

                $paymentsRun->addText("{$formattedDate} | {$typeString} | القيمة: {$formattedAmount}");
            }
        } else {
            $paymentsRun->addText('لا يوجد دفعات');
        }

        // Final safe replacement for payments
        $templateProcessor->setComplexValue('payment_details', $paymentsRun);

        $fileName = 'كشف_حساب_'.str_replace(' ', '_', $partner->name).'_'.date('Ymd_His').'.docx';
        $outputPath = app(ExportFileService::class)->path($fileName);

        $templateProcessor->saveAs($outputPath);

        // Prepare Telegram Message
        $msg = "📄 كشف حساب\n";
        $msg .= 'الزبون/المورد: '.$partner->name."\n";
        $msg .= 'من تاريخ: '.$this->statementFromDate."\n";
        $msg .= 'إلى تاريخ: '.$this->statementToDate."\n";
        $msg .= "------------------\n";

        if ($invoices->count() > 0) {
            $msg .= "-- الفواتير --\n";
            foreach ($invoices as $inv) {
                $typeStr = $inv->isSale() ? 'بيع' : 'شراء';
                $invDate = $inv->created_at->format('Y-m-d');
                $msg .= ' فاتورة #'.$inv->id." ({$invDate}) | ".$typeStr.' | الإجمالي: '.number_format((float) $inv->total, 2)."\n";

                // Detailed items for Telegram
                foreach ($inv->lines as $line) {
                    $msg .= '     ▪️ '.($line->product_name ?? 'منتج').' | '.$line->quantity.' x '.number_format((float) $line->unit_price, 2)."\n";
                }

                $msg .= '     💵 المدفوع (وقت الفاتورة): '.number_format((float) $inv->initial_paid, 2)."\n";
                $msg .= '     💰 الباقي (وقت الفاتورة): '.number_format((float) ($inv->total - $inv->initial_paid), 2)."\n";
                $msg .= "------------------\n";
            }
        }

        if ($settlements->count() > 0) {
            $msg .= "-- الدفعات --\n";
            foreach ($settlements as $payment) {
                $typeStr = $payment->type === LedgerSettlement::TYPE_INCOMING ? 'وارد' : 'صادر';
                $msg .= 'دفعة: '.$payment->settlement_date->format('Y-m-d').' | '.$typeStr.' | القيمة: '.number_format((float) $payment->amount, 2)."\n";
            }
            $msg .= "------------------\n";
        }

        $msg .= 'إجمالي الرصيد النهائي بالدفتر: '.number_format((float) $ledgerBalance, 2);

        app(TelegramService::class)->sendMessage($msg);

        $this->closeStatementModal();

        return response()->download($outputPath)->deleteFileAfterSend(true);
    }

    public function delete(int $id): void
    {
        $partner = Partner::withCount(['invoices', 'settlements'])->findOrFail($id);
        if ($partner->invoices_count > 0 || $partner->settlements_count > 0) {
            $this->dispatch('flash', message: 'لا يمكن حذف الطرف لوجود فواتير أو دفعات مالية مرتبطة به.', type: 'error');

            return;
        }
        $name = $partner->name ?? (($partner->type === 'customer' ? 'زبون' : 'مورد').' #'.$id);
        if ($partner->image && str_starts_with($partner->image, 'partners/')) {
            Storage::disk('public')->delete($partner->image);
        }
        $partner->delete();
        app(TelegramService::class)->notifyDeleted('partner', $name);
        session()->flash('success', 'تم الحذف.');
    }

    public function render()
    {
        $partners = Partner::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->latest()
            ->paginate(10);

        return view('livewire.partners.index', ['partners' => $partners]);
    }
}
