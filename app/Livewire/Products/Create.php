<?php

namespace App\Livewire\Products;

use App\Models\Category;
use App\Models\Partner;
use App\Models\Product;
use Livewire\Component;
use Livewire\WithFileUploads;

class Create extends Component
{
    use WithFileUploads;

    public ?int $partner_id = null;

    public ?int $category_id = null;

    public ?string $name = null;

    public ?string $description = null;

    public int $alert_stock_at = 5;

    public ?string $country_origin = null;

    public $image = null;

    public function save(): mixed
    {
        $data = $this->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'partner_id' => ['nullable', 'exists:partners,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'alert_stock_at' => ['required', 'integer', 'min:0', 'max:100000000'],
            'country_origin' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:10240'],
        ]);

        if ($this->partner_id && ! Partner::query()->findOrFail($this->partner_id)->isSupplier()) {
            $this->addError('partner_id', 'الجهة المختارة يجب أن تكون مورّدًا.');

            return null;
        }

        $data['quantity'] = 0;
        $data['purchase_price'] = null;
        $data['sale_price'] = null;
        if ($this->image) {
            $data['image'] = $this->image->store('products', 'public');
        }

        $product = Product::query()->create($data);
        session()->flash('success', 'تم إنشاء بطاقة المنتج دون إنشاء أي حركة شراء أو مخزون.');

        return $this->redirectRoute('products.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.products.create', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'suppliers' => Partner::query()->where('type', Partner::TYPE_SUPPLIER)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
