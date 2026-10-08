<?php

namespace App\Livewire\Expenses;

use App\Models\Expense;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('المصروفات')]
class Index extends Component
{
    use WithPagination;

    public string $amount = '';
    public string $reason = '';
    public string $date = '';

    public function mount(): void
    {
        $this->date = date('Y-m-d');
    }

    public function save(): void
    {
        $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
        ]);

        Expense::create([
            'amount' => $this->amount,
            'reason' => $this->reason,
            'date' => $this->date,
        ]);

        $this->reset(['amount', 'reason']);
        $this->date = date('Y-m-d');
        
        session()->flash('success', 'تم تسجيل المصروف بنجاح.');
    }

    public function delete(int $id): void
    {
        Expense::destroy($id);
        session()->flash('success', 'تم حذف المصروف.');
    }

    public function render()
    {
        return view('livewire.expenses.index', [
            'expenses' => Expense::orderBy('date', 'desc')->orderBy('created_at', 'desc')->paginate(20),
            'totalThisMonth' => Expense::whereMonth('date', date('m'))->whereYear('date', date('Y'))->sum('amount'),
        ]);
    }
}
