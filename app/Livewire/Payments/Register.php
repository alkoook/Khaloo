<?php

namespace App\Livewire\Payments;

use App\Models\Invoice;
use App\Models\LedgerSettlement;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use App\Services\FinancialService;
use App\Services\SettingsService;
use App\Services\TelegramService;

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

    protected $queryString = ['typeFilter' => ['except' => ''], 'dateFrom' => ['except' => ''], 'dateTo' => ['except' => '']];

    public function mount(): void
    {
        if (request()->boolean('open_modal')) {
            $this->showAddModal = true;
            $this->settlementDate = now()->format('Y-m-d');
        }
    }

    public function updatedSettlementType(): void
    {
        $this->partnerId = null;
    }

    public function updatedTypeFilter(): void { $this->resetPage(); }
    public function updatedDateFrom(): void { $this->resetPage(); }
    public function updatedDateTo(): void { $this->resetPage(); }

    public function openAddModal(): void
    {
        $this->resetValidation();
        $this->partnerId = null;
        $this->amount = '';
        $this->settlementDate = now()->format('Y-m-d');
        $this->settlementType = 'incoming';
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
    }

    public function addPayment(): void
    {
        $this->validate([
            'partnerId' => ['required', 'exists:partners,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'settlementDate' => ['required', 'date'],
            'settlementType' => ['required', 'in:incoming,outgoing'],
        ], [
            'partnerId.required' => 'اختر الزبون أو المورد.',
            'amount.required' => 'أدخل قيمة الدفعة.',
            'amount.min' => 'المبلغ يجب أن يكون أكبر من صفر.',
            'settlementDate.required' => 'أدخل تاريخ الدفعة.',
        ]);

        $amount = (float) $this->amount;
        $financials = app(FinancialService::class);
        $partner = $financials->partnerBalancesQuery()->findOrFail($this->partnerId);
        $side = $this->settlementType === LedgerSettlement::TYPE_INCOMING ? 'receivable' : 'payable';
        $available = $financials->balanceFor($partner, $side);

        if ($amount > $available) {
            $this->addError('amount', 'قيمة الدفعة أكبر من الرصيد المستحق لهذا الطرف.');

            return;
        }

        // The user requested that payments be kept separate and not modify existing invoices.
        // So we no longer iterate through invoices to update 'amount_paid'.
        // Everything is tracked via LedgerSettlement.
        
        LedgerSettlement::create([
            'partner_id' => $this->partnerId,
            'amount' => round($amount, 2),
            'type' => $this->settlementType === 'incoming' ? LedgerSettlement::TYPE_INCOMING : LedgerSettlement::TYPE_OUTGOING,
            'settlement_date' => $this->settlementDate,
        ]);
        app(\App\Services\AlertService::class)->evaluatePartners((int) $this->partnerId);
        if (app(SettingsService::class)->get('telegram_payments', true)) {
            app(TelegramService::class)->sendMessage("💳 دفعة جديدة\nالطرف: {$partner?->name}\nالمبلغ: ".number_format($amount, 2));
        }

        $this->closeAddModal();
        $this->dispatch('flash', message: 'تم تسجيل الدفعة بنجاح: '.number_format($amount, 2), type: 'success');
    }

    /**
     * شركاء لديهم رصيد (اللي النا أو اللي علينا) حسب نوع الدفعة المختار.
     *
     * @return \Illuminate\Support\Collection<int, Partner>
     */
    public function getPartnersWithBalanceProperty(): \Illuminate\Support\Collection
    {
        $financials = app(FinancialService::class);
        $side = $this->settlementType === 'incoming' ? 'receivable' : 'payable';
        return $financials->partnerBalancesQuery()->get()
            ->filter(fn (Partner $partner) => $financials->balanceFor($partner, $side) > 0)
            ->sortBy('name')->values();
    }

    public function render()
    {
        $query = LedgerSettlement::query()
            ->with('partner')
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
        ]);
    }
}
