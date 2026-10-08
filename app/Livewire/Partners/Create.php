<?php

namespace App\Livewire\Partners;

use App\Models\Partner;
use Livewire\Component;
use Livewire\WithFileUploads;

class Create extends Component
{
    use WithFileUploads;

    public ?string $name = null;

    public ?string $father_name = null;

    public ?string $nickname = null;

    public ?string $title = null;

    public ?string $mother_name = null;

    public ?string $national_id = null;
    public ?string $phone = null;

    public string $type = 'customer';

    public $image = null;

    public function save(): void
    {
        $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'type' => ['required', 'in:customer,supplier'],
            'image' => ['nullable', 'image', 'max:10240'],
        ]);

        $data = [
            'name' => $this->name ?: null,
            'father_name' => $this->father_name ?: null,
            'nickname' => $this->nickname ?: null,
            'title' => $this->title ?: null,
            'mother_name' => $this->mother_name ?: null,
            'national_id' => $this->national_id ?: null,
            'phone' => $this->phone ?: null,
            'type' => $this->type,
        ];

        if ($this->image) {
            $data['image'] = $this->image->store('partners', 'public');
        }

        Partner::create($data);
        session()->flash('success', 'تم الإضافة.');

        $this->redirectRoute('partners.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.partners.create');
    }
}
