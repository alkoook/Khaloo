<?php

namespace App\Livewire\Categories;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Livewire\Component;

class Create extends Component
{
    public string $name = '';

    public function save(): RedirectResponse
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [
            'name.required' => 'الاسم مطلوب.',
        ]);

        Category::create(['name' => trim($this->name)]);
        session()->flash('success', 'تم إضافة التصنيف.');

        return $this->redirect(route('categories.index'));
    }

    public function render()
    {
        return view('livewire.categories.create');
    }
}
