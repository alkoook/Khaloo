<?php

namespace App\Livewire\Invoices\Purchases;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Partner;
use App\Models\Product;
use App\Services\AlertService;
use App\Services\InvoicePricingService;
use App\Services\TelegramService;
use App\Support\Decimal as D;
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

    public bool $showProductModal = false;

    public string $newProductName = '';

    public ?int $newProductCategoryId = null;

    public function selectProduct(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        foreach ($this->lines as $index => $line) {
            if ((int) $line['product_id'] === $id) {
                $this->lines[$index]['quantity'] = D::add($line['quantity'], 1, 3);
                $this->afterLinesChanged();

                return;
            }
        }
        $this->lines[] = ['product_id' => $id, 'product_name' => $product->name ?: "منتج #{$id}", 'available' => $product->quantity, 'quantity' => 1, 'unit_price' => $product->purchase_price ?? '', 'sale_price' => $product->sale_price ?? ''];
        $this->afterLinesChanged();
    }

    public function openProductModal(): void
    {
        $this->resetValidation(['newProductName', 'newProductCategoryId']);
        $this->newProductName = trim($this->productSearch);
        $this->newProductCategoryId = null;
        $this->showProductModal = true;
        $this->dispatch('focus-new-product-name');
    }

    public function closeProductModal(): void
    {
        $this->showProductModal = false;
        $this->dispatch('focus-product-search');
    }

    public function createProduct(): void
    {
        $data = $this->validate([
            'newProductName' => ['required', 'string', 'max:255'],
            'newProductCategoryId' => ['required', 'exists:categories,id'],
            'partner_id' => ['nullable', Rule::exists('partners', 'id')->where('type', Partner::TYPE_SUPPLIER)],
        ], [
            'newProductName.required' => 'أدخل اسم المنتج.',
            'newProductCategoryId.required' => 'اختر التصنيف.',
        ]);
        $product = Product::query()->create([
            'name' => trim($data['newProductName']),
            'category_id' => $data['newProductCategoryId'],
            'partner_id' => $data['partner_id'],
            'quantity' => 0,
            'purchase_price' => null,
            'sale_price' => null,
        ]);
        $this->showProductModal = false;
        $this->newProductName = '';
        $this->newProductCategoryId = null;
        $this->selectProduct($product->id);
        $this->dispatch('flash', message: 'تمت إضافة المنتج واختياره في الفاتورة.', type: 'success');
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

    public function getSubtotalProperty(): string
    {
        return D::sum(collect($this->lines)->map(fn ($line) => D::mul($line['quantity'] ?? 0, $line['unit_price'] ?? 0)));
    }

    public function getPricingProperty(): array
    {
        try {
            return app(InvoicePricingService::class)->calculate($this->lines, $this->discount_type, $this->discount_value);
        } catch (\Throwable) {
            return ['subtotal' => $this->subtotal, 'discount_amount' => 0.0, 'total' => $this->subtotal, 'lines' => []];
        }
    }

    public function getTotalProperty(): string
    {
        return $this->pricing['total'];
    }

    public function save(): mixed
    {
        $this->validate([
            'partner_id' => ['nullable', Rule::exists('partners', 'id')->where('type', Partner::TYPE_SUPPLIER)],
            'amount_paid' => ['nullable', 'numeric', 'min:0'], 'discount_type' => ['required', Rule::in(['fixed', 'percentage'])], 'discount_value' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0.01'], 'lines.*.sale_price' => ['nullable', 'numeric', 'min:0'],
        ]);
        if (collect($this->lines)->pluck('product_id')->duplicates()->isNotEmpty()) {
            $this->addError('lines', 'لا يمكن تكرار المنتج في الفاتورة.');

            return null;
        }
        $pricing = app(InvoicePricingService::class)->calculate($this->lines, $this->discount_type, $this->discount_value);
        $paid = D::scale($this->amount_paid);
        if (D::cmp($pricing['total'], 0) <= 0) {
            $this->addError('lines', 'يجب أن يكون الإجمالي بعد الحسم أكبر من صفر.');

            return null;
        }
        if (D::cmp($paid, $pricing['total']) > 0) {
            $this->addError('amount_paid', 'المدفوع لا يمكن أن يتجاوز الإجمالي بعد الحسم.');

            return null;
        }
        if (! $this->partner_id && D::cmp($paid, $pricing['total']) < 0) {
            $this->addError('partner_id', 'يجب اختيار مورّد عند وجود مبلغ متبقٍ.');

            return null;
        }

        try {
            [$invoice, $messageLines] = DB::transaction(function () use ($pricing, $paid): array {
                $invoice = Invoice::query()->create([
                    'partner_id' => $this->partner_id, 'type' => Invoice::TYPE_PURCHASE,
                    'subtotal' => $pricing['subtotal'], 'discount_type' => $pricing['discount_type'],
                    'discount_value' => $pricing['discount_value'], 'discount_amount' => $pricing['discount_amount'],
                    'total' => $pricing['total'], 'initial_paid' => $paid, 'amount_paid' => $paid,
                ]);
                $products = Product::query()->whereIn('id', collect($pricing['lines'])->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');
                $messageLines = [];
                foreach ($pricing['lines'] as $line) {
                    $product = $products->get((int) $line['product_id']);
                    if (! $product) {
                        throw new \RuntimeException('أحد المنتجات لم يعد موجودًا.');
                    }
                    $quantity = D::quantity($line['quantity']);
                    $oldQuantity = $product->quantity;
                    if (D::cmp($oldQuantity, 0) > 0 && $product->purchase_price === null) {
                        throw new \RuntimeException("المخزون الحالي للمنتج «{$product->name}» بلا تكلفة موثوقة؛ صحح تكلفته قبل إضافة شراء جديد.");
                    }
                    $netUnitCost = D::div($line['net_line_total'], $quantity, 4);
                    $newAverage = D::cmp($oldQuantity, 0) > 0
                        ? D::div(D::of($oldQuantity)->multipliedBy($product->purchase_price)->plus($line['net_line_total']), D::add($oldQuantity, $quantity, 3), 4)
                        : $netUnitCost;
                    $salePrice = filled($line['sale_price'] ?? null) ? D::scale($line['sale_price']) : null;
                    InvoiceLine::query()->create([
                        'invoice_id' => $invoice->id, 'product_id' => $product->id, 'product_name' => $product->name,
                        'quantity' => $quantity, 'unit_price' => $line['unit_price'], 'purchase_price' => $netUnitCost,
                        'sale_price' => $salePrice, 'line_total' => $line['line_total'],
                        'discount_amount' => $line['discount_amount'], 'net_line_total' => $line['net_line_total'],
                    ]);
                    $product->update(array_filter(['quantity' => D::add($oldQuantity, $quantity, 3), 'purchase_price' => $newAverage, 'sale_price' => $salePrice], fn ($value) => $value !== null));
                    $messageLines[] = ['product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => $netUnitCost, 'line_total' => $line['net_line_total']];
                }

                return [$invoice->load('partner'), $messageLines];
            }, 3);
        } catch (\Throwable $exception) {
            $this->addError('amount_paid', $exception->getMessage());

            return null;
        }

        app(AlertService::class)->evaluateProducts(collect($this->lines)->pluck('product_id'));
        if ($invoice->partner_id) {
            app(AlertService::class)->evaluatePartners($invoice->partner_id);
        }
        app(TelegramService::class)->sendInvoiceNotification('purchase', $invoice->partner?->name ?? 'مورد نقدي', number_format($pricing['total'], 2), number_format($paid, 2), D::format(D::sub($pricing['total'], $paid)), (string) $invoice->id, $messageLines);
        session()->flash('success', 'تم إنشاء فاتورة الشراء بنجاح.');

        return $this->redirectRoute('invoices.purchases.show', $invoice, navigate: true);
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
            $this->amount_paid = $this->total > 0 ? D::scale($this->total) : '';
        }
    }

    public function render()
    {
        $term = trim($this->productSearch);

        return view('livewire.invoices.purchases.create', [
            'suppliers' => Partner::query()->where('type', Partner::TYPE_SUPPLIER)->orderBy('name')->get(['id', 'name']),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'productResults' => $term === '' ? collect() : Product::query()->with('category:id,name')->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->when(is_numeric($term), fn ($q) => $q->orWhere($q->qualifyColumn('id'), (int) $term))->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$term}%")))->limit(15)->get(),
        ]);
    }
}
