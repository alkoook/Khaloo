<?php

namespace App\Livewire\Invoices\Returns;

use App\Exceptions\BusinessRuleException;
use App\Models\Invoice;
use App\Services\ReturnService;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

class Create extends Component
{
    public ?Invoice $invoice = null;

    public array $lines = [];

    public array $returnQuantities = [];

    public string $amountPaid = '0.00';

    public string $requestKey = '';

    public function mount(?Invoice $invoice = null)
    {
        $this->requestKey = (string) Str::uuid();
        if ($invoice && $invoice->exists) {
            abort_unless($invoice->isSale(), 404);
            $this->invoice = $invoice;
            $this->invoice->load(['partner', 'lines', 'returns.lines']);
            $activeReturns = $this->invoice->returns->where('status', Invoice::STATUS_ACTIVE);
            $returnedLines = $activeReturns->flatMap->lines;
            $legacyReturnIds = $activeReturns->where('return_preserves_original', false)->pluck('id');
            $legacyReturnedLines = $returnedLines->whereIn('invoice_id', $legacyReturnIds);
            $this->lines = $this->invoice->lines->map(function ($line) use ($returnedLines, $legacyReturnedLines): array {
                $previous = $returnedLines->where('product_id', $line->product_id);
                $legacyPrevious = $legacyReturnedLines->where('product_id', $line->product_id);
                $returnedQuantity = D::sum($previous->pluck('quantity'), 3);
                $soldQuantity = D::add($line->quantity, D::sum($legacyPrevious->pluck('quantity'), 3), 3);
                $availableQuantity = D::sub($soldQuantity, $returnedQuantity, 3);
                $historicalNet = D::add($line->net_line_total ?? $line->line_total, D::sum($legacyPrevious->map(fn ($returnLine) => $returnLine->net_line_total ?? $returnLine->line_total)));
                $effectiveUnitPrice = D::cmp($soldQuantity, 0) > 0 ? D::div($historicalNet, $soldQuantity) : D::scale($line->unit_price);

                return [
                    'id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_name' => $line->product_name,
                    'effective_unit_price' => $effectiveUnitPrice,
                    'sold_quantity' => $soldQuantity,
                    'returned_quantity' => $returnedQuantity,
                    'available_quantity' => $availableQuantity,
                ];
            })->all();

            foreach ($this->lines as $line) {
                $this->returnQuantities[$line['id']] = '0.000';
            }
        }
    }

    public function findInvoice($searchId)
    {
        $inv = Invoice::with('lines')->active()->find($searchId);
        if ($inv && $inv->isSale()) {
            return redirect()->route('invoices.returns.create', ['invoice' => $inv->id]);
        }
        session()->flash('error', 'لم يتم العثور على فاتورة مبيعات بهذا الرقم.');
    }

    public function saveReturn(ReturnService $returnService)
    {
        if (! $this->invoice) {
            $this->addError('return', 'اختر فاتورة مبيعات قبل حفظ المرتجع.');

            return;
        }

        $this->validate([
            'amountPaid' => ['required', 'numeric', 'min:0'],
            'returnQuantities' => ['required', 'array'],
            'returnQuantities.*' => ['numeric', 'decimal:0,3', 'min:0'],
        ], [
            'amountPaid.required' => 'أدخل المبلغ المردود نقداً، أو صفرًا عند عدم وجود استرداد نقدي.',
            'amountPaid.numeric' => 'المبلغ المردود نقداً يجب أن يكون رقماً صالحاً.',
            'amountPaid.min' => 'المبلغ المردود نقداً لا يمكن أن يكون سالباً.',
            'returnQuantities.required' => 'حدد كمية واحدة على الأقل للإرجاع.',
            'returnQuantities.*.numeric' => 'كمية الإرجاع يجب أن تكون رقماً صالحاً.',
            'returnQuantities.*.decimal' => 'كمية الإرجاع تقبل حتى ثلاث منازل عشرية.',
            'returnQuantities.*.min' => 'كمية الإرجاع لا يمكن أن تكون سالبة.',
        ]);
        if (D::scale($this->amountPaid) > $this->returnTotal) {
            $this->addError('amountPaid', 'الاسترداد النقدي لا يمكن أن يتجاوز إجمالي المرتجع.');

            return;
        }

        $hasReturn = false;
        foreach ($this->returnQuantities as $id => $qty) {
            if ($qty > 0) {
                $hasReturn = true;
            }
        }

        if (! $hasReturn) {
            $this->addError('return', 'يجب تحديد كمية لمنتج واحد على الأقل.');

            return;
        }

        try {
            $returnInvoice = $returnService->processSalesReturn(
                $this->invoice,
                D::scale($this->amountPaid),
                $this->returnQuantities,
                $this->requestKey
            );

            return redirect()->route('invoices.sales.show', $returnInvoice)->with('success', 'تم إنشاء فاتورة المرتجع بنجاح.');

        } catch (BusinessRuleException $exception) {
            $this->addError('return', $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('فشل حفظ مرتجع المبيعات.', [
                'invoice_id' => $this->invoice->id,
                'exception' => $exception,
            ]);
            $this->addError('return', 'تعذر حفظ المرتجع. لم يتم إنشاء أي سجل، ويمكنك المحاولة مرة أخرى.');
        }
    }

    public function getReturnTotalProperty()
    {
        $total = '0.00';
        foreach ($this->lines as $line) {
            $qty = $this->returnQuantities[$line['id']] ?? 0;
            $total = D::add($total, D::mul($line['effective_unit_price'], $qty ?: 0));
        }

        return $total;
    }

    public function render()
    {
        return view('livewire.invoices.returns.create')
            ->layout('layouts.app')
            ->title('فاتورة مرتجع مبيعات');
    }
}
