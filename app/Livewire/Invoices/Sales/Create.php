<?php

namespace App\Livewire\Invoices\Sales;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Partner;
use App\Models\Product;
use App\Services\AlertService;
use App\Services\InvoicePricingService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public ?int $partner_id = null;

    public array $lines = [];

    public string $amount_paid = '';

    public string $discount_type = 'fixed';

    public string $discount_value = '';

    public string $productSearch = '';

    public bool $amountPaidTouched = false;

    public function selectProduct(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        if ($product->purchase_price === null) {
            $this->addError('lines', "لا يمكن بيع «{$product->name}» قبل تسجيل تكلفة شراء موثوقة له.");

            return;
        }
        foreach ($this->lines as $index => $line) {
            if ((int) $line['product_id'] === $id) {
                $this->lines[$index]['quantity']++;
                $this->afterLinesChanged();

                return;
            }
        }
        $this->lines[] = ['product_id' => $id, 'product_name' => $product->name ?: "منتج #{$id}", 'available' => $product->quantity, 'quantity' => 1, 'unit_price' => (float) $product->sale_price];
        $this->afterLinesChanged();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->syncPaidPrefill();
    }

    public function updatedAmountPaid(): void
    {
        $this->amountPaidTouched = true;
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'lines.') || in_array($property, ['discount_type', 'discount_value'], true)) {
            $this->syncPaidPrefill();
        }
    }

    public function getPricingProperty(): array
    {
        try {
            return app(InvoicePricingService::class)->calculate($this->lines, $this->discount_type, $this->discount_value);
        } catch (\Throwable) {
            return ['subtotal' => $this->subtotal, 'discount_amount' => 0.0, 'total' => $this->subtotal, 'lines' => []];
        }
    }

    public function getSubtotalProperty(): float
    {
        return round((float) collect($this->lines)->sum(fn ($line) => (int) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0)), 2);
    }

    public function getTotalProperty(): float
    {
        return (float) $this->pricing['total'];
    }

    public function save(): mixed
    {
        $this->validate([
            'partner_id' => ['nullable', Rule::exists('partners', 'id')->where('type', Partner::TYPE_CUSTOMER)],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],
            'discount_type' => ['required', Rule::in(['fixed', 'percentage'])],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);
        if (collect($this->lines)->pluck('product_id')->duplicates()->isNotEmpty()) {
            $this->addError('lines', 'لا يمكن تكرار المنتج في الفاتورة.');

            return null;
        }

        $pricing = app(InvoicePricingService::class)->calculate($this->lines, $this->discount_type, $this->discount_value);
        $paid = round((float) ($this->amount_paid === '' ? 0 : $this->amount_paid), 2);
        if ($pricing['total'] <= 0) {
            $this->addError('lines', 'يجب أن يكون الإجمالي بعد الحسم أكبر من صفر.');

            return null;
        }
        if ($paid > $pricing['total']) {
            $this->addError('amount_paid', 'المدفوع لا يمكن أن يتجاوز الإجمالي بعد الحسم.');

            return null;
        }
        if (! $this->partner_id && $paid < $pricing['total']) {
            $this->addError('partner_id', 'يجب اختيار عميل عند وجود مبلغ متبقٍ.');

            return null;
        }

        try {
            [$invoice, $messageLines] = DB::transaction(function () use ($pricing, $paid): array {
                $invoice = Invoice::query()->create([
                    'partner_id' => $this->partner_id, 'type' => Invoice::TYPE_SALE,
                    'subtotal' => $pricing['subtotal'], 'discount_type' => $pricing['discount_type'],
                    'discount_value' => $pricing['discount_value'], 'discount_amount' => $pricing['discount_amount'],
                    'total' => $pricing['total'], 'initial_paid' => $paid, 'amount_paid' => $paid,
                ]);
                $products = Product::query()->whereIn('id', collect($pricing['lines'])->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');
                $messageLines = [];
                foreach ($pricing['lines'] as $line) {
                    $product = $products->get((int) $line['product_id']);
                    $quantity = (int) $line['quantity'];
                    if (! $product || $product->quantity < $quantity) {
                        throw new \RuntimeException("المخزون غير كافٍ للمنتج {$line['product_name']}.");
                    }
                    if ($product->purchase_price === null) {
                        throw new \RuntimeException("لا يمكن بيع «{$product->name}» دون تكلفة شراء موثوقة.");
                    }
                    InvoiceLine::query()->create([
                        'invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name,
                        'quantity' => $quantity, 'unit_price' => $line['unit_price'], 'purchase_price' => $product->purchase_price,
                        'sale_price' => $line['unit_price'], 'line_total' => $line['line_total'],
                        'discount_amount' => $line['discount_amount'], 'net_line_total' => $line['net_line_total'],
                    ]);
                    $product->decrement('quantity', $quantity);
                    $messageLines[] = ['product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => $line['unit_price'], 'line_total' => $line['net_line_total']];
                }

                return [$invoice->load('partner'), $messageLines];
            }, 3);
        } catch (\Throwable $exception) {
            $this->dispatch('flash', message: $exception->getMessage(), type: 'error');

            return null;
        }

        app(AlertService::class)->evaluateProducts(collect($this->lines)->pluck('product_id'));
        if ($invoice->partner_id) {
            app(AlertService::class)->evaluatePartners($invoice->partner_id);
        }
        app(TelegramService::class)->sendInvoiceNotification('sale', $invoice->partner?->name ?? 'عميل نقدي', number_format($pricing['total'], 2), number_format($paid, 2), number_format($pricing['total'] - $paid, 2), (string) $invoice->id, $messageLines);
        session()->flash('success', 'تم إنشاء فاتورة البيع بنجاح.');

        return $this->redirectRoute('invoices.sales.show', $invoice, navigate: true);
    }

    private function afterLinesChanged(): void
    {
        $this->productSearch = '';
        $this->syncPaidPrefill();
        $this->dispatch('focus-product-search');
    }

    private function syncPaidPrefill(): void
    {
        if (! $this->amountPaidTouched) {
            $this->amount_paid = $this->total > 0 ? number_format($this->total, 2, '.', '') : '';
        }
    }

    public function render()
    {
        $term = trim($this->productSearch);

        return view('livewire.invoices.sales.create', [
            'customers' => Partner::query()->where('type', Partner::TYPE_CUSTOMER)->orderBy('name')->get(['id', 'name']),
            'productResults' => $term === '' ? collect() : Product::query()->with('category:id,name')->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->when(is_numeric($term), fn ($q) => $q->orWhere($q->qualifyColumn('id'), (int) $term))->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$term}%")))->orderByDesc('quantity')->limit(15)->get(),
        ]);
    }
}
