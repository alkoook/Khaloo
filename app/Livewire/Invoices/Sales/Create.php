<?php

namespace App\Livewire\Invoices\Sales;

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

    public array $additionalLines = [];

    public bool $hasDelivery = false;

    public string $deliveryAmount = '';

    public string $amount_paid = '';

    public string $discount_type = 'fixed';

    public string $discount_value = '';

    public string $productSearch = '';

    public bool $amountPaidTouched = false;

    public function selectProduct(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        if (D::cmp($product->quantity, 0) <= 0) {
            $this->addError('lines', "لا يمكن إضافة «{$product->name}» لأن مخزونه نافد.");

            return;
        }
        if ($product->purchase_price === null) {
            $this->addError('lines', "لا يمكن بيع «{$product->name}» قبل تسجيل تكلفة شراء موثوقة له.");

            return;
        }
        foreach ($this->lines as $index => $line) {
            if ((int) $line['product_id'] === $id) {
                if (D::cmp(D::add($line['quantity'], 1, 3), $product->quantity) > 0) {
                    $this->addError('lines', "لا يمكن إضافة كمية أخرى من «{$product->name}» لأن المتوفر {$product->quantity} فقط.");

                    return;
                }
                $this->lines[$index]['quantity'] = D::add($line['quantity'], 1, 3);
                $this->afterLinesChanged();

                return;
            }
        }
        $this->lines[] = ['product_id' => $id, 'product_name' => $product->name ?: "منتج #{$id}", 'available' => $product->quantity, 'quantity' => D::min(1, $product->quantity), 'unit_price' => $product->sale_price];
        $this->afterLinesChanged();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        $this->syncPaidPrefill();
    }

    public function addAdditionalLine(): void
    {
        $this->additionalLines[] = ['description' => '', 'quantity' => 1, 'unit_amount' => '', 'source_note' => ''];
    }

    public function removeAdditionalLine(int $index): void
    {
        unset($this->additionalLines[$index]);
        $this->additionalLines = array_values($this->additionalLines);
        $this->syncPaidPrefill();
    }

    public function updatedAmountPaid(): void
    {
        $this->amountPaidTouched = true;
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'lines.') || str_starts_with($property, 'additionalLines.') || in_array($property, ['discount_type', 'discount_value', 'hasDelivery', 'deliveryAmount'], true)) {
            if ($property === 'hasDelivery' && ! $this->hasDelivery) {
                $this->deliveryAmount = '';
            }
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

    public function getSubtotalProperty(): string
    {
        return D::sum(collect($this->lines)->map(fn ($line) => D::mul($line['quantity'] ?? 0, $line['unit_price'] ?? 0)));
    }

    public function getTotalProperty(): string
    {
        return $this->pricing['total'];
    }

    public function getAdditionalTotalProperty(): string
    {
        return D::sum(collect($this->additionalLines)->map(fn ($line) => D::mul($line['quantity'] ?? 0, $line['unit_amount'] ?? 0)));
    }

    public function getCustomerTotalProperty(): string
    {
        return D::sum([$this->total, $this->hasDelivery ? $this->deliveryAmount : 0, $this->additionalTotal]);
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
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'hasDelivery' => ['boolean'],
            'deliveryAmount' => ['nullable', 'numeric', 'min:0', 'required_if:hasDelivery,true'],
            'additionalLines' => ['array'],
            'additionalLines.*.description' => ['required', 'string', 'max:255'],
            'additionalLines.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0'],
            'additionalLines.*.unit_amount' => ['required', 'numeric', 'min:0'],
            'additionalLines.*.source_note' => ['nullable', 'string', 'max:255'],
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
        $deliveryAmount = $this->hasDelivery ? D::scale($this->deliveryAmount) : '0.00';
        $additionalTotal = $this->additionalTotal;
        $customerTotal = D::sum([$pricing['total'], $deliveryAmount, $additionalTotal]);
        if (! $this->partner_id && D::cmp($paid, $customerTotal) !== 0) {
            $this->addError('partner_id', 'يجب اختيار السيد عند وجود دين أو دفعة زائدة؛ الفاتورة النقدية بلا سيد يجب تسديدها بالكامل تمامًا.');

            return null;
        }

        try {
            [$invoice, $messageLines] = DB::transaction(function () use ($pricing, $paid, $deliveryAmount, $additionalTotal, $customerTotal): array {
                $invoice = Invoice::query()->create([
                    'partner_id' => $this->partner_id, 'type' => Invoice::TYPE_SALE,
                    'subtotal' => $pricing['subtotal'], 'discount_type' => $pricing['discount_type'],
                    'discount_value' => $pricing['discount_value'], 'discount_amount' => $pricing['discount_amount'],
                    'total' => $pricing['total'], 'delivery_amount' => $deliveryAmount,
                    'additional_total' => $additionalTotal, 'payable_total' => $customerTotal,
                    'initial_paid' => $paid, 'amount_paid' => $paid,
                ]);
                $products = Product::query()->whereIn('id', collect($pricing['lines'])->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');
                $messageLines = [];
                foreach ($pricing['lines'] as $line) {
                    $product = $products->get((int) $line['product_id']);
                    $quantity = D::quantity($line['quantity']);
                    if (! $product || D::cmp($product->quantity, $quantity) < 0) {
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
                    $product->update(['quantity' => D::sub($product->quantity, $quantity, 3)]);
                    $messageLines[] = ['product_name' => $product->name, 'quantity' => $quantity, 'unit_price' => $line['unit_price'], 'line_total' => $line['net_line_total']];
                }
                foreach ($this->additionalLines as $line) {
                    $quantity = D::quantity($line['quantity']);
                    $unitAmount = D::scale($line['unit_amount']);
                    $invoice->additionalLines()->create([
                        'description' => trim($line['description']),
                        'quantity' => $quantity,
                        'unit_amount' => $unitAmount,
                        'row_total' => D::mul($quantity, $unitAmount),
                        'source_note' => filled($line['source_note'] ?? null) ? trim($line['source_note']) : null,
                    ]);
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
        app(TelegramService::class)->sendInvoiceNotification('sale', $invoice->partner?->name ?? 'السيد النقدي', number_format($customerTotal, 2), number_format($paid, 2), D::format(D::sub($customerTotal, $paid)), (string) $invoice->id, $messageLines);
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
            $this->amount_paid = $this->customerTotal > 0 ? D::scale($this->customerTotal) : '';
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
