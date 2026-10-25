<?php

namespace App\Livewire\Payments;

use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Services\AlertService;
use App\Services\FinancialService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Support\Decimal as D;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('سجل الدفعات')]
class Register extends Component
{
    use WithPagination;

    public string $typeFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public bool $showAddModal = false;

    public ?int $partnerId = null;

    public string $amount = '';

    public string $settlementDate = '';

    public string $settlementType = 'incoming';

    public string $notes = '';

    public string $paymentMethod = 'cash';

    public string $invoiceId = '';

    public bool $showEditModal = false;

    public ?int $editingSettlementId = null;

    public string $editAmount = '';

    public string $editSettlementDate = '';

    public string $editPaymentMethod = 'cash';

    public string $editNotes = '';

    public string $editInvoiceId = '';

    public bool $amountTouched = false;

    protected $queryString = ['typeFilter' => ['except' => ''], 'dateFrom' => ['except' => ''], 'dateTo' => ['except' => '']];

    public function mount(): void
    {
        if (request()->boolean('open_modal')) {
            $this->showAddModal = true;
            $this->settlementDate = now()->format('Y-m-d');
        }
        if ($settlementId = request()->integer('edit_settlement')) {
            $this->openEditModal($settlementId);
        }
    }

    public function updatedSettlementType(): void
    {
        $this->partnerId = null;
        $this->amount = '';
        $this->amountTouched = false;
        $this->invoiceId = '';
    }

    public function updatedPartnerId(): void
    {
        $this->invoiceId = '';
        if (! $this->partnerId || $this->amountTouched) {
            return;
        }
        $this->amount = $this->currentBalance > 0 ? number_format($this->currentBalance, 2, '.', '') : '';
    }

    public function updatedAmount(): void
    {
        $this->amountTouched = true;
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function openAddModal(): void
    {
        $this->resetValidation();
        $this->partnerId = null;
        $this->amount = '';
        $this->settlementDate = now()->format('Y-m-d');
        $this->settlementType = 'incoming';
        $this->notes = '';
        $this->paymentMethod = 'cash';
        $this->invoiceId = '';
        $this->amountTouched = false;
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    public function openEditModal(int $settlementId): void
    {
        $this->resetValidation();
        $settlement = LedgerSettlement::query()->with('partner', 'invoice')->findOrFail($settlementId);
        $this->editingSettlementId = $settlement->id;
        $this->editAmount = D::scale($settlement->amount);
        $this->editSettlementDate = $settlement->settlement_date->format('Y-m-d');
        $this->editPaymentMethod = $settlement->payment_method ?: 'cash';
        $this->editNotes = $settlement->notes ?: '';
        $this->editInvoiceId = $settlement->invoice_id ? (string) $settlement->invoice_id : '';
        $this->showEditModal = true;
    }

    public function closeEditModal(): void
    {
        $this->showEditModal = false;
        $this->editingSettlementId = null;
    }

    public function addPayment(): void
    {
        $this->validate([
            'partnerId' => ['required', 'exists:partners,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'settlementDate' => ['required', 'date'],
            'settlementType' => ['required', 'in:incoming,outgoing'],
            'paymentMethod' => ['required', 'in:cash,bank_transfer,card,other'],
            'invoiceId' => ['nullable', 'integer', 'exists:invoices,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'partnerId.required' => 'اختر السيد أو المورد.',
            'amount.required' => 'أدخل قيمة الدفعة.',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر.',
            'settlementDate.required' => 'أدخل تاريخ الدفعة.',
            'paymentMethod.in' => 'اختر طريقة دفع صالحة.',
            'invoiceId.exists' => 'مرجع الفاتورة المحدد غير موجود.',
        ]);

        $amount = D::scale($this->amount);
        $financials = app(FinancialService::class);
        $partner = $financials->partnerBalancesQuery()->findOrFail($this->partnerId);
        $side = $this->settlementType === LedgerSettlement::TYPE_INCOMING ? 'receivable' : 'payable';
        // Later payments remain their own ledger events. An optional invoice reference
        // allocates the payment for display without turning it into invoice revenue.

        try {
            $financials->recordSettlement((int) $this->partnerId, $side, $amount, $this->settlementDate, $this->notes, $this->paymentMethod, $this->invoiceId !== '' ? (int) $this->invoiceId : null);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        app(AlertService::class)->evaluatePartners((int) $this->partnerId);
        if (app(SettingsService::class)->get('telegram_payments', true)) {
            app(TelegramService::class)->sendMessage("💳 دفعة جديدة\nالطرف: {$partner?->name}\nالمبلغ: ".number_format($amount, 2));
        }

        $this->closeAddModal();
        $this->dispatch('flash', message: 'تم تسجيل الدفعة بنجاح: '.number_format($amount, 2), type: 'success');
    }

    public function savePaymentEdit(FinancialService $financials): void
    {
        $this->validate([
            'editingSettlementId' => ['required', 'integer'],
            'editAmount' => ['required', 'numeric', 'min:0.01'],
            'editSettlementDate' => ['required', 'date'],
            'editPaymentMethod' => ['required', 'in:cash,bank_transfer,card,other'],
            'editNotes' => ['nullable', 'string', 'max:1000'],
            'editInvoiceId' => ['nullable', 'integer', 'exists:invoices,id'],
        ], [
            'editAmount.required' => 'أدخل قيمة الدفعة.',
            'editAmount.min' => 'قيمة الدفعة يجب أن تكون أكبر من صفر.',
            'editSettlementDate.required' => 'أدخل تاريخ الدفعة.',
            'editPaymentMethod.in' => 'اختر طريقة دفع صالحة.',
            'editInvoiceId.exists' => 'مرجع الفاتورة المحدد غير موجود.',
        ]);
        $settlement = LedgerSettlement::query()->findOrFail($this->editingSettlementId);
        try {
            $updated = $financials->updateSettlement(
                $settlement,
                $this->editAmount,
                $this->editSettlementDate,
                $this->editPaymentMethod,
                $this->editNotes,
                $this->editInvoiceId !== '' ? (int) $this->editInvoiceId : null,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field === 'invoiceId' ? 'editInvoiceId' : $field, $messages[0]);
            }

            return;
        }
        app(AlertService::class)->evaluatePartners($updated->partner_id);
        $this->closeEditModal();
        $this->dispatch('flash', message: 'تم تعديل الدفعة بنجاح.', type: 'success');
    }

    public function cancelPayment(int $settlementId, FinancialService $financials): void
    {
        $settlement = LedgerSettlement::query()->findOrFail($settlementId);
        $partnerId = $settlement->partner_id;
        $financials->cancelSettlement($settlement, 'إلغاء دفعة مدخلة بالخطأ من سجل الدفعات.');
        app(AlertService::class)->evaluatePartners($partnerId);
        $this->dispatch('flash', message: 'تم حذف الدفعة وإعادة احتساب الرصيد.', type: 'success');
    }

    /**
     * شركاء لديهم رصيد (اللي النا أو اللي علينا) حسب نوع الدفعة المختار.
     *
     * @return Collection<int, Partner>
     */
    public function getPartnersWithBalanceProperty(): Collection
    {
        $financials = app(FinancialService::class);
        $side = $this->settlementType === 'incoming' ? 'receivable' : 'payable';

        return $financials->partnerBalancesQuery()->get()
            ->filter(fn (Partner $partner) => $this->settlementType === LedgerSettlement::TYPE_INCOMING
                ? $partner->isCustomer() || D::cmp($financials->balanceFor($partner, $side), 0) > 0
                : D::cmp($financials->balanceFor($partner, $side), 0) > 0)
            ->sortBy('name')->values();
    }

    public function getCurrentBalanceProperty(): string
    {
        if (! $this->partnerId) {
            return '0.00';
        }
        $financials = app(FinancialService::class);
        $partner = $financials->partnerBalancesQuery()->find($this->partnerId);
        if (! $partner) {
            return '0.00';
        }

        return $financials->balanceFor($partner, $this->settlementType === 'incoming' ? 'receivable' : 'payable');
    }

    public function getExpectedBalanceProperty(): string
    {
        return D::scale(D::sub($this->currentBalance, $this->amount));
    }

    public function getPaymentInvoicesProperty(): Collection
    {
        if (! $this->partnerId) {
            return collect();
        }
        $partner = Partner::query()->find($this->partnerId);
        if (! $partner) {
            return collect();
        }

        return Invoice::query()->active()->where('partner_id', $partner->id)
            ->where('type', $partner->isCustomer() ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE)
            ->latest()->get();
    }

    public function getEditInvoicesProperty(): Collection
    {
        if (! $this->editingSettlementId) {
            return collect();
        }
        $settlement = LedgerSettlement::query()->with('partner')->find($this->editingSettlementId);
        if (! $settlement?->partner) {
            return collect();
        }

        return Invoice::query()->active()->where('partner_id', $settlement->partner_id)
            ->where('type', $settlement->partner->isCustomer() ? Invoice::TYPE_SALE : Invoice::TYPE_PURCHASE)
            ->latest()->get();
    }

    public function render()
    {
        $query = LedgerSettlement::query()
            ->with('partner', 'invoice')
            ->orderByDesc('settlement_date')
            ->orderByDesc('created_at');

        if ($this->typeFilter === 'incoming') {
            $query->where('type', LedgerSettlement::TYPE_INCOMING);
        } elseif ($this->typeFilter === 'outgoing') {
            $query->where('type', LedgerSettlement::TYPE_OUTGOING);
        }

        if ($this->dateFrom !== '') {
            $query->whereDate('settlement_date', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== '') {
            $query->whereDate('settlement_date', '<=', $this->dateTo);
        }

        $settlements = $query->paginate(15);

        return view('livewire.payments.register', [
            'settlements' => $settlements,
            'partnersWithBalance' => $this->showAddModal ? $this->partnersWithBalance : collect(),
            'paymentInvoices' => $this->showAddModal ? $this->paymentInvoices : collect(),
            'editInvoices' => $this->showEditModal ? $this->editInvoices : collect(),
            'editingSettlement' => $this->showEditModal && $this->editingSettlementId
                ? LedgerSettlement::query()->with('partner', 'invoice')->find($this->editingSettlementId)
                : null,
        ]);
    }
}
