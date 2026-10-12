<?php

namespace App\Livewire\Invoices\Returns;

use App\Models\Invoice;
use App\Services\ReturnService;
use App\Support\Decimal as D;
use Illuminate\Support\Str;
use Livewire\Component;

class Create extends Component
{
    public ?Invoice $invoice = null;

    public array $lines = [];

    public array $returnQuantities = [];

    public string $amountPaid = '';

    public string $requestKey = '';

    public function mount(?Invoice $invoice = null)
    {
        $this->requestKey = (string) Str::uuid();
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
        $inv = Invoice::with('lines')->active()->find($searchId);
        if ($inv && $inv->isSale()) {
            return redirect()->route('invoices.returns.create', ['invoice' => $inv->id]);
        }
        session()->flash('error', 'لم يتم العثور على فاتورة مبيعات بهذا الرقم.');
    }

    public function saveReturn(ReturnService $returnService)
    {
        if (! $this->invoice) {
            return;
        }

        $this->validate([
            'amountPaid' => ['required', 'numeric', 'min:0'],
            'returnQuantities' => ['required', 'array'],
            'returnQuantities.*' => ['numeric', 'decimal:0,3', 'min:0'],
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
            session()->flash('error', 'يجب تحديد كمية لمنتج واحد على الأقل.');

            return;
        }

        try {
            $returnInvoice = $returnService->processSalesReturn(
                $this->invoice,
                D::scale($this->amountPaid),
                $this->returnQuantities,
                $this->requestKey
            );

            return redirect()->route('dashboard')->with('success', 'تم تسجيل المرتجع بنجاح.');

        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function getReturnTotalProperty()
    {
        $total = '0.00';
        foreach ($this->lines as $line) {
            $qty = $this->returnQuantities[$line['id']] ?? 0;
            $currentQuantity = $line['quantity'];
            $currentNet = $line['net_line_total'] ?? $line['line_total'];
            $total = D::add($total, $currentQuantity > 0 ? D::div(D::of($currentNet)->multipliedBy($qty ?: 0), $currentQuantity) : 0);
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
