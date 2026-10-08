<?php

namespace App\Livewire\Notifications;

use App\Models\InAppNotification;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $filter = 'all';

    public function markRead(int $id): void { InAppNotification::query()->findOrFail($id)->markRead(); }
    public function markAllRead(): void { InAppNotification::query()->whereNull('read_at')->update(['read_at' => now()]); }
    public function updatedFilter(): void { $this->resetPage(); }

    public function render()
    {
        return view('livewire.notifications.index', [
            'notifications' => InAppNotification::query()
                ->when($this->filter === 'unread', fn ($q) => $q->whereNull('read_at'))
                ->when($this->filter === 'danger', fn ($q) => $q->where('severity', 'danger'))
                ->latest()->paginate(20),
            'unreadCount' => InAppNotification::query()->whereNull('read_at')->count(),
        ])->layout('layouts.app', ['title' => 'مركز التنبيهات']);
    }
}
