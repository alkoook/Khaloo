<?php

namespace App\Livewire\Partners;

use App\Models\Partner;
use Illuminate\Http\RedirectResponse;
use Livewire\Component;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;

class Edit extends Component
{
    use WithFileUploads;

    public Partner $partner;

    public ?string $name = null;

    public ?string $father_name = null;

    public ?string $nickname = null;

    public ?string $title = null;

    public ?string $mother_name = null;

    public ?string $national_id = null;
    public ?string $phone = null;

    public string $type = 'customer';

    public $image = null;

    public function mount(Partner $partner): void
    {
        $this->partner = $partner;
        $this->name = $partner->name;
        $this->father_name = $partner->father_name;
        $this->nickname = $partner->nickname;
        $this->title = $partner->title;
        $this->mother_name = $partner->mother_name;
        $this->national_id = $partner->national_id;
        $this->phone = $partner->phone;
        $this->type = $partner->type;
    }

    public function save(): RedirectResponse
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
            'image' => ['nullable', 'image', 'max:2048'],
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
            if ($this->partner->image && str_starts_with($this->partner->image, 'partners/')) Storage::disk('public')->delete($this->partner->image);
            $data['image'] = $this->image->store('partners', 'public');
        }

        $this->partner->update($data);
        app(\App\Services\TelegramService::class)->notifyUpdated('partner', $this->partner->name ?? ($this->partner->type === 'customer' ? 'زبون' : 'مورد').' #'.$this->partner->id);
        session()->flash('success', 'تم التحديث.');

        return $this->redirect(route('partners.index'));
    }

    public function render()
    {
        return view('livewire.partners.edit');
    }
}
