<?php

namespace App\Livewire\Quotations;

use App\Models\Partner;
use App\Models\Product;
use App\Models\Quotation;
use App\Services\QuotationService;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public ?int $partner_id = null;
    public string $customer_name = '';
    public string $customer_phone = '';
    public string $quotation_date = '';
    public string $expires_at = '';
    public string $status = 'draft';
    public string $discount = '0';
    public string $notes = '';
    public string $productSearch = '';
    public string $amountPaid = '0';
    public array $lines = [];

    public function mount(): void { $this->quotation_date = now()->toDateString(); }
    public function updatedSearch(): void { $this->resetPage(); }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function selectProduct(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        foreach ($this->lines as $index => $line) {
            if ((int) $line['product_id'] === $id) {
                $this->lines[$index]['quantity']++;
                $this->productSearch = '';
                return;
            }
        }
        $this->lines[] = ['product_id' => $id, 'product_name' => $product->name, 'quantity' => 1, 'unit_price' => (float) $product->sale_price];
        $this->productSearch = '';
        $this->dispatch('focus-quotation-quantity', index: count($this->lines) - 1);
    }

    public function removeLine(int $index): void { unset($this->lines[$index]); $this->lines = array_values($this->lines); }

    public function save(QuotationService $service): void
    {
        $validated = $this->validate([
            'partner_id' => ['nullable', Rule::exists('partners', 'id')->where('type', Partner::TYPE_CUSTOMER)],
            'customer_name' => ['nullable', 'string', 'max:255'], 'customer_phone' => ['nullable', 'string', 'max:40'],
            'quotation_date' => ['required', 'date'], 'expires_at' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'discount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:4000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'], 'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        $validated['expires_at'] = filled($validated['expires_at'] ?? null) ? $validated['expires_at'] : null;
        $quotation = $this->editingId ? Quotation::query()->findOrFail($this->editingId) : null;
        if ($quotation?->converted_invoice_id) { $this->addError('save', 'لا يمكن تعديل عرض تم تحويله.'); return; }
        $validated['status'] = $quotation?->status ?? 'draft';
        $service->save($validated, $validated['lines'], $quotation);
        session()->flash('success', $quotation ? 'تم تحديث عرض السعر.' : 'تم إنشاء عرض السعر دون تعديل المخزون.');
        $this->showForm = false;
        $this->resetForm();
    }

    public function edit(int $id): void
    {
        $quotation = Quotation::query()->with('lines')->findOrFail($id);
        if ($quotation->converted_invoice_id) return;
        $this->editingId = $quotation->id;
        $this->partner_id = $quotation->partner_id;
        $this->customer_name = (string) ($quotation->customer_name ?? '');
        $this->customer_phone = (string) ($quotation->customer_phone ?? '');
        $this->status = $quotation->status;
        $this->discount = (string) $quotation->discount;
        $this->notes = (string) ($quotation->notes ?? '');
        $this->quotation_date = $quotation->quotation_date->toDateString();
        $this->expires_at = $quotation->expires_at?->toDateString() ?? '';
        $this->lines = $quotation->lines->map(fn ($line) => $line->only('product_id','product_name','quantity','unit_price'))->all();
        $this->showForm = true;
    }

    public function delete(int $id): void
    {
        $quotation = Quotation::query()->findOrFail($id);
        if ($quotation->converted_invoice_id) { $this->addError('delete', 'لا يمكن حذف عرض مرتبط بفاتورة.'); return; }
        $quotation->delete();
        session()->flash('success', 'تم حذف العرض، ولم يتغير المخزون.');
    }

    public function convert(int $id, QuotationService $service): mixed
    {
        $invoice = $service->convertToSale(Quotation::query()->findOrFail($id), (float) $this->amountPaid);
        session()->flash('success', 'تم تحويل العرض إلى فاتورة مبيعات بعد التحقق من المخزون.');
        return $this->redirectRoute('invoices.sales.show', $invoice, navigate: true);
    }

    public function getTotalProperty(): float
    {
        return max(0, round(collect($this->lines)->sum(fn ($line) => (int) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0)) - (float) $this->discount, 2));
    }

    public function render()
    {
        $term = trim($this->productSearch);
        return view('livewire.quotations.index', [
            'quotations' => Quotation::query()->with('partner')->withCount('lines')
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('number', 'like', "%{$this->search}%")->orWhere('customer_name', 'like', "%{$this->search}%")->orWhereHas('partner', fn ($p) => $p->where('name', 'like', "%{$this->search}%"))))
                ->latest()->paginate(12),
            'customers' => Partner::query()->where('type', Partner::TYPE_CUSTOMER)->orderBy('name')->get(['id','name']),
            'productResults' => $term === '' ? collect() : Product::query()->with('category:id,name')->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->when(is_numeric($term), fn ($q) => $q->orWhere($q->qualifyColumn('id'), (int) $term))->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$term}%")))->limit(15)->get(),
        ])->layout('layouts.app', ['title' => 'عروض الأسعار']);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId','partner_id','customer_name','customer_phone','expires_at','discount','notes','productSearch','amountPaid','lines']);
        $this->quotation_date = now()->toDateString(); $this->status = 'draft'; $this->discount = '0'; $this->amountPaid = '0';
    }
}
