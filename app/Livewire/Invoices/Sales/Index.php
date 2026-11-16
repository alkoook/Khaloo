<?php

namespace App\Livewire\Invoices\Sales;

use App\Models\Invoice;
use App\Services\AlertService;
use App\Services\InvoiceCancellationService;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $cancellingId = null;

    public string $cancellationReason = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openCancellation(int $id): void
    {
        $invoice = Invoice::query()->findOrFail($id);
        abort_unless(auth()->user()?->can('delete', $invoice), 403);
        abort_unless($invoice->type === Invoice::TYPE_SALE, 404);
        if ($invoice->isCancelled()) {
            return;
        }
        $this->cancellingId = $invoice->id;
        $this->cancellationReason = '';
        $this->resetValidation();
    }

    public function cancel(InvoiceCancellationService $cancellations): void
    {
        $invoice = Invoice::query()->findOrFail($this->cancellingId);
        abort_unless(auth()->user()?->can('delete', $invoice), 403);
        abort_unless($invoice->type === Invoice::TYPE_SALE, 404);
        try {
            $productIds = $cancellations->cancel($invoice, (int) auth()->id(), $this->cancellationReason);
        } catch (ValidationException $exception) {
            $this->addError('cancellationReason', collect($exception->errors())->flatten()->first());

            return;
        }
        app(AlertService::class)->evaluateProducts($productIds);
        if ($invoice->partner_id) {
            app(AlertService::class)->evaluatePartners($invoice->partner_id);
        }
        $this->cancellingId = null;
        $this->cancellationReason = '';
        $this->dispatch('flash', message: 'تم إلغاء فاتورة البيع وعكس أثر المخزون مرة واحدة مع الاحتفاظ بسجلها.', type: 'success');
    }

    public function render()
    {
        $invoices = Invoice::query()
            ->with('partner', 'settlements')
            ->where('type', Invoice::TYPE_SALE)
            ->when($this->search, function ($q) {
                $s = trim($this->search);
                if (is_numeric($s)) {
                    $q->where('id', (int) $s);
                } else {
                    $q->whereHas('partner', fn ($q2) => $q2->where('name', 'like', '%'.$s.'%'));
                }
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('livewire.invoices.sales.index', ['invoices' => $invoices]);
    }
}
