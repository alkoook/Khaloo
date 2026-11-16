<?php

namespace App\Livewire\Invoices\Returns;

use App\Exceptions\BusinessRuleException;
use App\Models\Invoice;
use App\Services\ReturnService;
use App\Support\Decimal as D;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

class Create extends Component
{
    public ?Invoice $invoice = null;

    public ?Invoice $returnInvoice = null;

    public array $lines = [];

    public array $returnQuantities = [];

    public string $amountPaid = '0.00';

    public string $returnDate = '';

    public string $notes = '';

    public string $requestKey = '';

    public function mount(?Invoice $invoice = null, ?Invoice $returnInvoice = null): void
    {
        $this->requestKey = (string) Str::uuid();
        $this->returnDate = now()->toDateString();

        if ($returnInvoice?->exists) {
            abort_unless($returnInvoice->isReturn(), 404);
            abort_unless(auth()->user()?->can('update', $returnInvoice), 403);
            abort_if($returnInvoice->isCancelled(), 404);
            $returnInvoice->load('lines', 'originalInvoice.partner', 'originalInvoice.lines', 'originalInvoice.returns.lines');
            $this->returnInvoice = $returnInvoice;
            $this->invoice = $returnInvoice->originalInvoice;
            $this->amountPaid = D::scale($returnInvoice->amount_paid);
            $this->returnDate = $returnInvoice->accountingDate()->format('Y-m-d');
            $this->notes = (string) $returnInvoice->notes;
            $this->loadReturnableLines();

            return;
        }

        if ($invoice?->exists) {
            abort_unless($invoice->isSale(), 404);
            $this->invoice = $invoice;
            $this->invoice->load('partner', 'lines', 'returns.lines');
            $this->loadReturnableLines();
        }
    }

    public function findInvoice($searchId)
    {
        $inv = Invoice::query()->active()->find($searchId);
        if ($inv?->isSale()) {
            return redirect()->route('invoices.returns.create', ['invoice' => $inv->id]);
        }
        session()->flash('error', 'لم يتم العثور على فاتورة مبيعات بهذا الرقم.');
    }

    public function saveReturn(ReturnService $returnService)
    {
        if (! $this->invoice) {
            $this->addError('return', 'اختر فاتورة مبيعات قبل حفظ المرتجع.');

            return;
        }

        $this->validate([
            'amountPaid' => ['required', 'numeric', 'min:0'],
            'returnDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'returnQuantities' => ['required', 'array'],
            'returnQuantities.*' => ['numeric', 'decimal:0,3', 'min:0'],
        ], [
            'amountPaid.required' => 'أدخل المبلغ المردود نقدًا، أو صفرًا عند عدم وجود استرداد نقدي.',
            'amountPaid.numeric' => 'المبلغ المردود نقدًا يجب أن يكون رقمًا صالحًا.',
            'amountPaid.min' => 'المبلغ المردود نقدًا لا يمكن أن يكون سالبًا.',
            'returnDate.required' => 'أدخل تاريخ المرتجع.',
            'returnDate.date_format' => 'تاريخ المرتجع غير صالح.',
            'returnDate.before_or_equal' => 'تاريخ المرتجع لا يمكن أن يكون في المستقبل.',
            'notes.max' => 'ملاحظات المرتجع طويلة جدًا.',
            'returnQuantities.required' => 'حدد كمية واحدة على الأقل للإرجاع.',
            'returnQuantities.*.numeric' => 'كمية الإرجاع يجب أن تكون رقمًا صالحًا.',
            'returnQuantities.*.decimal' => 'كمية الإرجاع تقبل حتى ثلاث منازل عشرية.',
            'returnQuantities.*.min' => 'كمية الإرجاع لا يمكن أن تكون سالبة.',
        ]);

        if (D::cmp($this->amountPaid, $this->returnTotal) > 0) {
            $this->addError('amountPaid', 'المبلغ المردود نقدًا لا يمكن أن يتجاوز إجمالي المرتجع.');

            return;
        }

        try {
            if ($this->returnInvoice) {
                $saved = $returnService->updateSalesReturn(
                    $this->returnInvoice,
                    $this->amountPaid,
                    $this->returnQuantities,
                    $this->returnDate,
                    $this->notes,
                );
                $message = 'تم تعديل المرتجع بنجاح.';
            } else {
                $saved = $returnService->processSalesReturn(
                    $this->invoice,
                    $this->amountPaid,
                    $this->returnQuantities,
                    $this->requestKey,
                    $this->returnDate,
                    $this->notes,
                );
                $message = 'تم إنشاء المرتجع بنجاح.';
            }

            return redirect()->route('invoices.sales.show', $saved)->with('success', $message);
        } catch (BusinessRuleException $exception) {
            $this->addError('return', $exception->getMessage());
        } catch (Throwable $exception) {
            Log::error('فشل حفظ مرتجع المبيعات.', [
                'invoice_id' => $this->invoice->id,
                'return_id' => $this->returnInvoice?->id,
                'exception' => $exception,
            ]);
            $this->addError('return', 'تعذر حفظ المرتجع. لم يتم تغيير أي سجل، ويمكنك المحاولة مرة أخرى.');
        }
    }

    public function getReturnTotalProperty(): string
    {
        return D::sum(collect($this->lines)->map(function (array $line): string {
            $quantity = $this->returnQuantities[$line['id']] ?? 0;

            return D::mul($line['effective_unit_price'], $quantity ?: 0);
        }));
    }

    public function getAccountCreditProperty(): string
    {
        try {
            return D::max(0, D::sub($this->returnTotal, $this->amountPaid));
        } catch (Throwable) {
            return $this->returnTotal;
        }
    }

    public function getEditingProperty(): bool
    {
        return $this->returnInvoice !== null;
    }

    public function render()
    {
        return view('livewire.invoices.returns.create')
            ->layout('layouts.app')
            ->title($this->editing ? 'تعديل المرتجع' : 'إضافة مرتجع مبيعات');
    }

    private function loadReturnableLines(): void
    {
        $this->lines = app(ReturnService::class)->returnableLines($this->invoice, $this->returnInvoice);
        foreach ($this->lines as $line) {
            $this->returnQuantities[$line['id']] = $line['current_quantity'];
        }
    }
}
