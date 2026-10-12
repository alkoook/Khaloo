<?php

namespace App\Livewire\Invoices;

use App\Models\Invoice;
use App\Models\Product;
use App\Services\InvoicePricingService;
use App\Services\InvoiceService;
use App\Support\Decimal as D;
use Livewire\Component;

class Edit extends Component
{
    public Invoice $invoice;

    public array $lines = [];

    public array $additionalLines = [];

    public bool $hasDelivery = false;

    public string $deliveryAmount = '';

    public string $amountPaid = '';

    public string $discountType = 'fixed';

    public string $discountValue = '';

    public array $products = [];

    public array $originalQuantities = [];

    public string $productSearch = '';

    protected $rules = [
        'amountPaid' => 'required|numeric|min:0',
        'discountType' => 'required|in:fixed,percentage',
        'discountValue' => 'nullable|numeric|min:0',
        'lines' => 'required|array|min:1',
        'lines.*.product_id' => 'required|exists:products,id',
        'lines.*.quantity' => 'required|numeric|decimal:0,3|gt:0',
        'lines.*.unit_price' => 'required|numeric|min:0',
        'hasDelivery' => 'boolean',
        'deliveryAmount' => 'nullable|numeric|min:0|required_if:hasDelivery,true',
        'additionalLines' => 'array',
        'additionalLines.*.description' => 'required|string|max:255',
        'additionalLines.*.quantity' => 'required|numeric|decimal:0,3|gt:0',
        'additionalLines.*.unit_amount' => 'required|numeric|min:0',
        'additionalLines.*.source_note' => 'nullable|string|max:255',
    ];

    public function mount(Invoice $invoice, InvoiceService $invoiceService)
    {
        abort_unless(auth()->user()?->can('update', $invoice), 403);
        abort_if($reason = $invoiceService->editBlockReason($invoice), 409, $reason);
        $this->invoice = $invoice;
        $this->invoice->load('lines.product', 'additionalLines');
        $this->amountPaid = D::scale($invoice->amount_paid);
        $this->discountType = $invoice->discount_type ?: 'fixed';
        $this->discountValue = D::cmp($invoice->discount_value, 0) > 0 ? (string) $invoice->discount_value : '';
        $this->hasDelivery = $invoice->isSale() && D::cmp($invoice->delivery_amount, 0) > 0;
        $this->deliveryAmount = $this->hasDelivery ? (string) $invoice->delivery_amount : '';
        $this->additionalLines = $invoice->isSale() ? $invoice->additionalLines->map(fn ($line) => [
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_amount' => $line->unit_amount,
            'source_note' => $line->source_note ?? '',
        ])->all() : [];

        foreach ($this->invoice->lines as $line) {
            $this->lines[] = [
                'product_id' => $line->product_id,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'sale_price' => $line->sale_price,
            ];
        }

        $this->originalQuantities = $this->invoice->lines
            ->groupBy('product_id')
            ->map(fn ($lines): string => D::sum($lines->pluck('quantity'), 3))
            ->all();
        $this->products = Product::query()->orderBy('name')->get()->map(fn (Product $product): array => [
            ...$product->toArray(),
            'available_for_invoice' => $this->availableForInvoice($product),
        ])->all();
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

    public function selectProduct(int $id): void
    {
        $product = Product::query()->findOrFail($id);
        $available = $this->availableForInvoice($product);

        foreach ($this->lines as $index => $line) {
            if ((int) ($line['product_id'] ?? 0) === $id) {
                if ($this->invoice->isSale() && D::cmp(D::add($line['quantity'] ?? 0, 1, 3), $available) > 0) {
                    $this->addError('lines', "لا يمكن إضافة كمية أخرى من «{$product->name}» لأن المتاح للتعديل {$available} فقط.");

                    return;
                }
                $this->lines[$index]['quantity'] = D::add($line['quantity'] ?? 0, 1, 3);
                $this->productSearch = '';
                $this->dispatch('focus-product-search');

                return;
            }
        }

        if ($this->invoice->isSale() && $available <= 0) {
            $this->addError('lines', "لا يمكن إضافة «{$product->name}» لأن مخزونه نافد.");

            return;
        }

        $this->lines[] = [
            'product_id' => $product->id,
            'quantity' => $this->invoice->isSale() ? D::min(1, $available) : '1.000',
            'unit_price' => $this->invoice->isSale() ? $product->sale_price : $product->purchase_price,
            'sale_price' => $product->sale_price,
        ];
        $this->productSearch = '';
        $this->dispatch('focus-product-search');
    }

    public function removeLine($index)
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function addAdditionalLine(): void
    {
        abort_unless($this->invoice->isSale(), 404);
        $this->additionalLines[] = ['description' => '', 'quantity' => 1, 'unit_amount' => '', 'source_note' => ''];
    }

    public function removeAdditionalLine(int $index): void
    {
        unset($this->additionalLines[$index]);
        $this->additionalLines = array_values($this->additionalLines);
    }

    public function updated($propertyName)
    {
        if ($propertyName === 'hasDelivery' && ! $this->hasDelivery) {
            $this->deliveryAmount = '';
        }
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
                D::scale($this->amountPaid),
                $this->discountType,
                $this->discountValue,
                $this->invoice->isSale() ? $this->additionalLines : [],
                $this->invoice->isSale() && $this->hasDelivery ? D::scale($this->deliveryAmount) : 0,
            );

            $route = $this->invoice->isSale() ? 'invoices.sales.show' : 'invoices.purchases.show';

            return redirect()->route($route, $this->invoice->id)->with('success', 'تم تعديل الفاتورة وتحديث المخزون بنجاح.');

        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function getPricingProperty(): array
    {
        try {
            return app(InvoicePricingService::class)->calculate($this->lines, $this->discountType, $this->discountValue);
        } catch (\Throwable) {
            $subtotal = D::sum(collect($this->lines)->map(fn ($line) => D::mul($line['quantity'] ?? 0, $line['unit_price'] ?? 0)));

            return ['subtotal' => $subtotal, 'discount_amount' => 0.0, 'total' => $subtotal];
        }
    }

    public function getAdditionalTotalProperty(): string
    {
        return D::sum(collect($this->additionalLines)->map(fn ($line) => D::mul($line['quantity'] ?? 0, $line['unit_amount'] ?? 0)));
    }

    public function getCustomerTotalProperty(): string
    {
        return D::sum([$this->pricing['total'], $this->hasDelivery ? $this->deliveryAmount : 0, $this->additionalTotal]);
    }

    public function render()
    {
        $term = trim($this->productSearch);

        return view('livewire.invoices.edit', [
            'productResults' => $term === '' ? collect() : Product::query()
                ->with('category:id,name')
                ->where(fn ($query) => $query->where('name', 'like', "%{$term}%")
                    ->when(is_numeric($term), fn ($query) => $query->orWhere($query->qualifyColumn('id'), (int) $term))
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$term}%")))
                ->orderBy('name')
                ->limit(15)
                ->get()
                ->each(fn (Product $product) => $product->setAttribute('available_for_invoice', $this->availableForInvoice($product))),
        ])
            ->layout('layouts.app')
            ->title('تعديل فاتورة');
    }

    private function availableForInvoice(Product $product): string
    {
        if (! isset($this->invoice) || ! $this->invoice->isSale()) {
            return $product->quantity;
        }

        return D::add($product->quantity, $this->originalQuantities[$product->id] ?? 0, 3);
    }
}
