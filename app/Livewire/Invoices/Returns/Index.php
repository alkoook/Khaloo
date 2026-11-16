<?php

namespace App\Livewire\Invoices\Returns;

use App\Models\Invoice;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public function render()
    {
        $returns = Invoice::query()
            ->with('partner')
            ->where('type', Invoice::TYPE_RETURN)
            ->orderByRaw('COALESCE(return_date, DATE(created_at)) DESC')
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.invoices.returns.index', [
            'returns' => $returns,
        ])->layout('layouts.app')->title('فواتير المرتجعات');
    }
}
