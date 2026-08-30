<?php

namespace App\Livewire\Profile;

use Illuminate\Validation\Rule;
use Livewire\Component;

class Edit extends Component
{
    public string $email = '';
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void { $this->email = auth()->user()->email; }

    public function updateProfile(): void
    {
        $user = auth()->user();
        $this->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);
        $user->update(['email' => $this->email, 'password' => $this->password]);
        $this->reset(['current_password', 'password', 'password_confirmation']);
        session()->flash('status', 'تم تحديث الملف الشخصي بنجاح.');
    }

    public function render()
    {
        return view('livewire.profile.edit')->layout('layouts.app')->title('الملف الشخصي');
    }
}
