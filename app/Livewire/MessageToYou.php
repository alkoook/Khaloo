<?php

namespace App\Livewire;

use Livewire\Component;

class MessageToYou extends Component
{
    public array $message = [];
    public int $sequence = 0;

    public function mount(): void { $this->another(); }

    public function another(): void
    {
        $items = collect(config('message_to_you.items', []))->values();
        if ($items->isEmpty()) { $this->message = ['type'=>'wisdom','text'=>'كل بداية صادقة تصنع أثرًا.']; return; }
        $last = session('message_to_you.last');
        $choices = $items->keys()->reject(fn ($key) => $items->count() > 1 && $key === $last)->values();
        $key = $choices->random();
        session(['message_to_you.last' => $key]);
        $this->message = $items[$key];
        $this->sequence++;
    }

    public function render()
    {
        return view('livewire.message-to-you')->layout('layouts.app', ['title' => 'رسالة إليك']);
    }
}
