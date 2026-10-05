<?php

namespace App\Livewire\Invoices\Sales;

use App\Models\Invoice;
use App\Services\AlertService;
use App\Services\ExportFileService;
use App\Services\InvoiceCancellationService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;
use PhpOffice\PhpWord\TemplateProcessor;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $cancellingId = null;

    public string $cancellationReason = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openCancellation(int $id): void
    {
        $invoice = Invoice::query()->findOrFail($id);
        abort_unless(auth()->user()?->can('delete', $invoice), 403);
        abort_unless($invoice->type === Invoice::TYPE_SALE, 404);
        if ($invoice->isCancelled()) {
            return;
        }
        $this->cancellingId = $invoice->id;
        $this->cancellationReason = '';
        $this->resetValidation();
    }

    public function cancel(InvoiceCancellationService $cancellations): void
    {
        $invoice = Invoice::query()->findOrFail($this->cancellingId);
        abort_unless(auth()->user()?->can('delete', $invoice), 403);
        abort_unless($invoice->type === Invoice::TYPE_SALE, 404);
        try {
            $productIds = $cancellations->cancel($invoice, (int) auth()->id(), $this->cancellationReason);
        } catch (ValidationException $exception) {
            $this->addError('cancellationReason', collect($exception->errors())->flatten()->first());

            return;
        }
        app(AlertService::class)->evaluateProducts($productIds);
        if ($invoice->partner_id) {
            app(AlertService::class)->evaluatePartners($invoice->partner_id);
        }
        $this->cancellingId = null;
        $this->cancellationReason = '';
        $this->dispatch('flash', message: 'تم إلغاء فاتورة البيع وعكس أثر المخزون مرة واحدة مع الاحتفاظ بسجلها.', type: 'success');
    }

    public function printWord(int $id)
    {
        $invoice = Invoice::with('lines', 'partner')->findOrFail($id);

        $templatePath = storage_path('app/public/templates/invoice.docx');
        if (! file_exists($templatePath)) {
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
        $fileName = 'فاتورة_'.$typeLabel.'_'.$partnerName.'_'.$invoiceDate.'_'.$invoice->id.'.docx';
        $outputPath = app(ExportFileService::class)->path($fileName);

        $templateProcessor->saveAs($outputPath);

        return response()->download($outputPath)->deleteFileAfterSend(true);
    }

    public function render()
    {
        $invoices = Invoice::query()
            ->with('partner')
            ->where('type', Invoice::TYPE_SALE)
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

        return view('livewire.invoices.sales.index', ['invoices' => $invoices]);
    }
}
