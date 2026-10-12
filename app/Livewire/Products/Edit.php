<?php

namespace App\Livewire\Products;

use App\Models\Category;
use App\Models\Product;
use App\Services\AlertService;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class Edit extends Component
{
    use WithFileUploads;

    public Product $product;

    public ?int $category_id = null;

    public ?string $name = null;

    public ?string $description = null;

    public ?string $purchase_price = null;

    public ?string $sale_price = null;

    public string $quantity = '0.000';

    public int $alert_stock_at = 5;

    public ?string $country_origin = null;

    public $image = null;

    public function mount(Product $product): void
    {
        $this->product = $product;
        $this->category_id = $product->category_id;
        $this->name = $product->name;
        $this->description = $product->description;
        $this->purchase_price = $product->purchase_price !== null ? (string) $product->purchase_price : null;
        $this->sale_price = $product->sale_price !== null ? (string) $product->sale_price : null;
        $this->quantity = $product->quantity;
        $this->alert_stock_at = $product->alert_stock_at;
        $this->country_origin = $product->country_origin;
    }

    public function save(): mixed
    {
        $this->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'alert_stock_at' => ['required', 'integer', 'min:0', 'max:100000000'],
            'country_origin' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:10240'],
        ]);

        $data = [
            'category_id' => $this->category_id,
            'name' => $this->name ?: null,
            'description' => $this->description ?: null,
            'sale_price' => $this->sale_price !== null && $this->sale_price !== '' ? $this->sale_price : null,
            'alert_stock_at' => $this->alert_stock_at,
            'country_origin' => $this->country_origin ?: null,
        ];

        if ($this->image) {
            if ($this->product->image && str_starts_with($this->product->image, 'products/')) {
                Storage::disk('public')->delete($this->product->image);
            }
            $data['image'] = $this->image->store('products', 'public');
        }

        $this->product->update($data);
        app(AlertService::class)->evaluateProducts($this->product->id);
        app(TelegramService::class)->notifyUpdated('product', $this->product->name ?? 'منتج #'.$this->product->id);
        session()->flash('success', 'تم تحديث المنتج.');

        return $this->redirect(route('products.index'));
    }

    public function render()
    {
        return view('livewire.products.edit', [
            'categories' => Category::orderBy('name')->get(),
        ]);
    }
}
