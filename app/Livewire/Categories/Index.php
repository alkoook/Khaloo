<?php

namespace App\Livewire\Categories;

use App\Models\Category;
use App\Services\TelegramService;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $category = Category::withCount('products')->findOrFail($id);
        if ($category->products_count > 0) {
            $this->dispatch('flash', message: 'لا يمكن حذف التصنيف لأنه مرتبط بمنتجات. انقل المنتجات أولًا.', type: 'error');
            return;
        }
        $name = $category->name;
        $category->delete();
        app(TelegramService::class)->notifyDeleted('category', $name);
        session()->flash('success', 'تم حذف التصنيف.');
    }

    public function render()
    {
        $categories = Category::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->latest()
            ->paginate(10);

        return view('livewire.categories.index', ['categories' => $categories]);
    }
}
