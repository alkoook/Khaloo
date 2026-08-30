<?php

namespace App\Livewire\Invoices;

use App\Models\Invoice;
use App\Models\Product;
use App\Services\InvoiceService;
use Livewire\Component;

class Edit extends Component
{
    public Invoice $invoice;
    public array $lines = [];
    public float $amountPaid = 0;
    public array $products = [];

    protected $rules = [
        'amountPaid' => 'required|numeric|min:0',
        'lines' => 'required|array|min:1',
        'lines.*.product_id' => 'required|exists:products,id',
        'lines.*.quantity' => 'required|integer|min:1',
        'lines.*.unit_price' => 'required|numeric|min:0',
    ];

    public function mount(Invoice $invoice)
    {
        abort_unless(auth()->user()?->can('update', $invoice), 403);
        $this->invoice = $invoice;
        $this->invoice->load('lines.product');
        $this->amountPaid = (float) $invoice->amount_paid;
        
        foreach ($this->invoice->lines as $line) {
            $this->lines[] = [
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'sale_price' => $line->sale_price,
            ];
        }
        
        $this->products = Product::orderBy('name')->get()->toArray();
    }

    public function addLine()
    {
        $this->lines[] = [
            'product_id' => '',
            'quantity' => 1,
            'unit_price' => 0,
            'sale_price' => '',
        ];
    }

    public function removeLine($index)
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function updated($propertyName)
    {
        if (preg_match('/lines\.(\d+)\.product_id/', $propertyName, $matches)) {
            $index = $matches[1];
            $productId = $this->lines[$index]['product_id'];
            if ($productId) {
                $product = Product::find($productId);
                if ($product) {
                    if ($this->invoice->isSale()) {
                        $this->lines[$index]['unit_price'] = $product->sale_price;
                    } else {
                        $this->lines[$index]['unit_price'] = $product->purchase_price;
                        $this->lines[$index]['sale_price'] = $product->sale_price;
                    }
                }
            }
        }
    }

    public function saveEdit(InvoiceService $invoiceService)
    {
        $this->validate();

        try {
            $invoiceService->updateInvoice(
                $this->invoice,
                $this->lines,
                (float) $this->amountPaid
            );

            $route = $this->invoice->isSale() ? 'invoices.sales.show' : 'invoices.purchases.show';
            return redirect()->route($route, $this->invoice->id)->with('success', 'تم تعديل الفاتورة وتحديث المخزون بنجاح.');
            
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.invoices.edit')
            ->layout('layouts.app')
            ->title('تعديل فاتورة');
    }
}
