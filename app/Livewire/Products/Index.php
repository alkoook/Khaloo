<?php

namespace App\Livewire\Products;

use App\Models\Category;
use App\Models\Product;
use App\Services\TelegramService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('المنتجات')]
class Index extends Component
{
    use WithPagination, WithFileUploads;

    public string $search = '';

    public string $categoryFilter = '';

    public function mount(): void
    {
        $page = (int) request()->get('page', 1);
        if ($page > 1) {
            $this->setPage($page);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $product = Product::withCount('invoiceLines')->findOrFail($id);
        abort_unless(auth()->user()?->can('delete', $product), 403);
        if ($product->invoice_lines_count > 0) {
            $this->dispatch('flash', message: 'لا يمكن حذف المنتج لأنه مستخدم في فواتير تاريخية.', type: 'error');
            return;
        }
        $name = $product->name ?? 'منتج #'.$id;
        if ($product->image && str_starts_with($product->image, 'products/')) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image);
        }
        $product->delete();
        app(TelegramService::class)->notifyDeleted('product', $name);
        session()->flash('success', 'تم حذف المنتج.');
    }

    public function render()
    {
        $products = Product::query()
            ->with('category')
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->categoryFilter, fn ($q) => $q->where('category_id', $this->categoryFilter))
            ->latest()
            ->paginate(10);

        $categories = Category::orderBy('name')->get();

        return view('livewire.products.index', [
            'products' => $products,
            'categories' => $categories,
        ]);
    }
}
