<?php

namespace App\Livewire\Invoices\Purchases;

use App\Models\Invoice;
use App\Services\TelegramService;
use App\Services\AlertService;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use PhpOffice\PhpWord\TemplateProcessor;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $invoice = Invoice::with('lines.product', 'partner')->findOrFail($id);
        abort_unless(auth()->user()?->can('delete', $invoice), 403);
        if ($invoice->type !== Invoice::TYPE_PURCHASE) {
            return;
        }

        foreach ($invoice->lines as $line) {
            if ($line->product_id && (! $line->product || $line->product->quantity < $line->quantity)) {
                $this->dispatch('flash', message: 'لا يمكن حذف فاتورة الشراء لأن جزءًا من الكمية تم بيعه بالفعل.', type: 'error');
                return;
            }
        }

        $label = 'فاتورة #'.$id.' - '.($invoice->partner->name ?? '');

        $productIds = $invoice->lines->pluck('product_id')->filter();
        $partnerId = $invoice->partner_id;
        DB::transaction(function () use ($invoice): void {
            foreach ($invoice->lines as $line) {
                if ($line->product_id) {
                    $line->product->decrement('quantity', $line->quantity);
                }
            }
            $invoice->lines()->delete();
            $invoice->delete();
        });
        app(AlertService::class)->evaluateProducts($productIds);
        if ($partnerId) app(AlertService::class)->evaluatePartners($partnerId);

        app(TelegramService::class)->notifyDeleted('invoice_purchase', $label);
        $this->dispatch('flash', message: 'تم حذف فاتورة الشراء وتم خصم الكميات من المخزن.', type: 'success');
    }

    public function printWord(int $id)
    {
        $invoice = Invoice::with('lines', 'partner')->findOrFail($id);
        
        $templatePath = storage_path('app/public/templates/invoice.docx');
        if (!file_exists($templatePath)) {
            $this->dispatch('flash', message: 'قالب الفاتورة غير موجود. يرجى إضافته في storage/app/public/templates/invoice.docx', type: 'error');
            return;
        }

        $templateProcessor = new TemplateProcessor($templatePath);
        $templateProcessor->setValue('partner_name', $invoice->partner->name ?? '');
        $templateProcessor->setValue('invoice_date', $invoice->created_at->format('Y-m-d'));
        $templateProcessor->setValue('invoice_id', $invoice->id);
        $templateProcessor->setValue('invoice_total', number_format((float) $invoice->total, 2));
        $templateProcessor->setValue('amount_paid', number_format((float) $invoice->amount_paid, 2));
        $templateProcessor->setValue('balance', number_format((float) $invoice->balance, 2));

        $rows = [];
        if ($invoice->lines->count() > 0) {
            foreach ($invoice->lines as $line) {
                $rows[] = [
                    'product_name' => $line->product_name ?? 'منتج',
                    'qty' => $line->quantity,
                    'price' => number_format((float) $line->unit_price, 2),
                    'line_total' => number_format((float) ($line->quantity * $line->unit_price), 2),
                ];
            }
        }
        if (count($rows) > 0) {
            $templateProcessor->cloneRowAndSetValues('product_name', $rows);
        } else {
             $templateProcessor->cloneRow('product_name', 0);
        }

        $typeLabel = $invoice->isSale() ? 'بيع' : 'شراء';
        $partnerName = str_replace(' ', '_', $invoice->partner->name ?? 'مجهول');
        $invoiceDate = $invoice->created_at->format('Y-m-d');
        $fileName = 'فاتورة_'.$typeLabel.'_' . $partnerName . '_' . $invoiceDate . '_' . $invoice->id . '.docx';
        $outputPath = app(\App\Services\ExportFileService::class)->path($fileName);

        $templateProcessor->saveAs($outputPath);

        return response()->download($outputPath)->deleteFileAfterSend(true);
    }

    public function render()
    {
        $invoices = Invoice::query()
            ->with('partner')
            ->where('type', Invoice::TYPE_PURCHASE)
            ->when($this->search, function ($q) {
                $s = trim($this->search);
                if (is_numeric($s)) {
                    $q->where('id', (int) $s);
                } else {
                    $q->whereHas('partner', fn ($q2) => $q2->where('name', 'like', '%'.$s.'%'));
                }
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('livewire.invoices.purchases.index', ['invoices' => $invoices]);
    }
}
