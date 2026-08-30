<?php

namespace App\Livewire\Categories;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Livewire\Component;

class Edit extends Component
{
    public Category $category;

    public string $name = '';

    public function mount(Category $category): void
    {
        $this->category = $category;
        $this->name = $category->name;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function save(): RedirectResponse
    {
        $this->validate();
        $this->category->update(['name' => $this->name]);
        app(\App\Services\TelegramService::class)->notifyUpdated('category', $this->category->name);
        session()->flash('success', 'تم تحديث التصنيف.');

        return $this->redirect(route('categories.index'));
    }

    public function render()
    {
        return view('livewire.categories.edit');
    }
}
