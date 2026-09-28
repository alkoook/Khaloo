<?php

namespace App\Livewire\Products;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Models\Product;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Livewire\Component;
use Livewire\WithFileUploads;

class Create extends Component
{
    use WithFileUploads;

    public ?int $partner_id = null;

    public ?int $category_id = null;

    public ?string $name = null;

    public ?string $description = null;

    public ?string $purchase_price = null;

    public ?string $sale_price = null;

    public int $quantity = 0;
    public int $alert_stock_at = 5;

    public ?string $country_origin = null;

    public $image = null;
    
    public ?string $total_purchase_amount = '0';
    
    public ?string $paid_purchase_amount = '0';

    public function updatedPurchasePrice(): void
    {
        $this->recalculateTotal();
    }

    public function updatedQuantity(): void
    {
        $this->recalculateTotal();
    }

    protected function recalculateTotal(): void
    {
        $price = is_numeric($this->purchase_price) ? (float) $this->purchase_price : 0;
        $qty = is_numeric($this->quantity) ? (int) $this->quantity : 0;
        
        $total = $price * $qty;
        $this->total_purchase_amount = (string) round($total, 2);
    }

    public function save()
    {
        $this->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'partner_id' => ['required', 'exists:partners,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'alert_stock_at' => ['required', 'integer', 'min:0', 'max:100000000'],
            'country_origin' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:10240'],
            'total_purchase_amount' => ['nullable', 'numeric', 'min:0'],
            'paid_purchase_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ((float) $this->paid_purchase_amount > (float) $this->total_purchase_amount) {
            $this->addError('paid_purchase_amount', 'المدفوع لا يمكن أن يتجاوز إجمالي الشراء.');
            return null;
        }
        if (! Partner::findOrFail($this->partner_id)->isSupplier()) {
            $this->addError('partner_id', 'يجب اختيار مورد.');
            return null;
        }

        $product = DB::transaction(function () {
            $data = [
                'category_id' => $this->category_id,
                'partner_id' => $this->partner_id,
                'name' => $this->name ?: null,
                'description' => $this->description ?: null,
                'purchase_price' => $this->purchase_price !== null && $this->purchase_price !== '' ? $this->purchase_price : null,
                'sale_price' => $this->sale_price !== null && $this->sale_price !== '' ? $this->sale_price : null,
                'quantity' => (int) $this->quantity,
                'alert_stock_at' => $this->alert_stock_at,
                'country_origin' => $this->country_origin ?: null,
                'total_purchase_amount' => $this->total_purchase_amount ?: 0,
                'paid_purchase_amount' => $this->paid_purchase_amount ?: 0,
            ];

            if ($this->image) {
                $data['image'] = $this->image->store('products', 'public');
            }

            $product = Product::create($data);

            // Create Purchase Invoice
            $total = (float) ($this->total_purchase_amount ?: 0);
            $paid = (float) ($this->paid_purchase_amount ?: 0);
            
            $invoice = Invoice::create([
                'partner_id' => $this->partner_id,
                'type' => Invoice::TYPE_PURCHASE,
                'total' => $total,
                'initial_paid' => $paid,
                'amount_paid' => $paid,
            ]);

            InvoiceLine::create([
                'invoice_id' => $invoice->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'quantity' => (int) $this->quantity,
                'unit_price' => $this->purchase_price ?: 0,
                'sale_price' => $this->sale_price ?: 0,
                'line_total' => $total,
            ]);

            // We do NOT create a LedgerSettlement here because the user wants payments 
            // recorded only through the dedicated payment interface.
            // The remaining balance (debt) is automatically tracked via the invoice balance.

            return ['product' => $product, 'invoice' => $invoice];
        });

        $productObj = $product['product'];
        $invoiceObj = $product['invoice'];
        app(\App\Services\AlertService::class)->evaluateProducts($productObj->id);
        app(\App\Services\AlertService::class)->evaluatePartners((int) $this->partner_id);

        // Send Detailed Telegram Message
        $category = Category::find($this->category_id);
        $partner = Partner::find($this->partner_id);

        $text = "✅ تم إضافة منتج جديد\n";
        $text .= "📦 الاسم: ".($productObj->name ?? 'بدون اسم')."\n";
        $text .= "📁 التصنيف: ".($category->name ?? '-')."\n";
        $text .= "💰 سعر الشراء: ".number_format((float) $this->purchase_price, 2)."\n";
        $text .= "💵 سعر البيع: ".number_format((float) $this->sale_price, 2)."\n";
        $text .= "🔢 الكمية: ".$this->quantity."\n";
        $text .= "🤝 المورد: ".($partner->name ?? '-')."\n";
        $text .= "------------------\n";
        $text .= "🧾 الفاتورة: #".$invoiceObj->id."\n";
        $text .= "💰 الإجمالي: ".number_format((float) $this->total_purchase_amount, 2)."\n";
        $text .= "💵 المدفوع: ".number_format((float) $this->paid_purchase_amount, 2)."\n";
        $text .= "📉 الباقي: ".number_format((float) $this->total_purchase_amount - (float) $this->paid_purchase_amount, 2)."\n";
        $text .= "🌍 بلد المنشأ: ".($this->country_origin ?? '-')."\n";

        app(TelegramService::class)->sendMessage($text);

        session()->flash('success', 'تم إضافة المنتج وفاتورة الشراء وتسجيل الدفع.');

        return $this->redirect(route('products.create'));
    }

    public function render()
    {
        return view('livewire.products.create', [
            'categories' => Category::orderBy('name')->get(),
            'suppliers' => Partner::where('type', Partner::TYPE_SUPPLIER)->orderBy('name')->get(),
        ]);
    }
}
