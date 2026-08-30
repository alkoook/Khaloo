<?php

namespace App\Livewire\Invoices\Returns;

use App\Models\Invoice;
use App\Services\ReturnService;
use Livewire\Component;

class Create extends Component
{
    public ?Invoice $invoice = null;
    public array $lines = [];
    public array $returnQuantities = [];
    public float $amountPaid = 0;

    public function mount(?Invoice $invoice = null)
    {
        if ($invoice && $invoice->exists) {
            abort_unless($invoice->isSale(), 404);
            $this->invoice = $invoice;
            $this->invoice->load('lines');
            $this->lines = $this->invoice->lines->toArray();
            
            foreach ($this->lines as $line) {
                $this->returnQuantities[$line['id']] = 0;
            }
        }
    }

    public function findInvoice($searchId)
    {
        $inv = Invoice::with('lines')->find($searchId);
        if ($inv && $inv->isSale()) {
            return redirect()->route('invoices.returns.create', ['invoice' => $inv->id]);
        }
        session()->flash('error', 'لم يتم العثور على فاتورة مبيعات بهذا الرقم.');
    }

    public function saveReturn(ReturnService $returnService)
    {
        if (!$this->invoice) return;

        $this->validate([
            'amountPaid' => ['required', 'numeric', 'min:0'],
            'returnQuantities' => ['required', 'array'],
            'returnQuantities.*' => ['integer', 'min:0'],
        ]);
        if ($this->amountPaid > $this->returnTotal) {
            $this->addError('amountPaid', 'الاسترداد النقدي لا يمكن أن يتجاوز إجمالي المرتجع.');
            return;
        }

        $hasReturn = false;
        foreach ($this->returnQuantities as $id => $qty) {
            if ($qty > 0) $hasReturn = true;
        }

        if (!$hasReturn) {
            session()->flash('error', 'يجب تحديد كمية لمنتج واحد على الأقل.');
            return;
        }

        try {
            $returnInvoice = $returnService->processSalesReturn(
                $this->invoice,
                (float) $this->amountPaid,
                $this->returnQuantities
            );

            return redirect()->route('dashboard')->with('success', 'تم تسجيل المرتجع بنجاح.');
            
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function getReturnTotalProperty()
    {
        $total = 0;
        foreach ($this->lines as $line) {
            $qty = (int) ($this->returnQuantities[$line['id']] ?? 0);
            $total += $qty * (float) $line['unit_price'];
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
