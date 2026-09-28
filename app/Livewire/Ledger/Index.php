<?php

namespace App\Livewire\Ledger;

use App\Models\LedgerSettlement;
use App\Models\Partner;
use App\Services\FinancialService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Pagination\LengthAwarePaginator;

#[Layout('layouts.app')]
#[Title('الدفتر')]
class Index extends Component
{
    use WithPagination;
    public string $tab = 'receivable';
    public string $search = '';
    public string $sortBy = 'balance';
    public string $dateFrom = '';
    public string $dateTo = '';
    public bool $showAddPaymentModal = false;
    public ?int $paymentPartnerId = null;
    public string $paymentAmount = '';
    public string $paymentDate = '';
    protected $queryString = ['search' => ['except' => ''], 'sortBy', 'dateFrom', 'dateTo', 'tab'];

    public function mount(): void
    {
        if (request()->boolean('open_modal')) $this->openAddPaymentModal();
    }

    public function openAddPaymentModal(): void
    {
        $this->resetValidation();
        $this->paymentPartnerId = null;
        $this->paymentAmount = '';
        $this->paymentDate = now()->toDateString();
        $this->showAddPaymentModal = true;
    }

    public function closeAddPaymentModal(): void { $this->showAddPaymentModal = false; }

    public function addPayment(FinancialService $financials): void
    {
        $this->validate([
            'paymentPartnerId' => ['required', 'exists:partners,id'],
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentDate' => ['required', 'date'],
        ]);
        $partner = $financials->partnerBalancesQuery()->findOrFail($this->paymentPartnerId);
        $available = $financials->balanceFor($partner, $this->tab);
        $amount = round((float) $this->paymentAmount, 2);
        if ($amount > $available) {
            $this->addError('paymentAmount', 'المبلغ أكبر من الرصيد المستحق الحالي.');
            return;
        }

        LedgerSettlement::create([
            'partner_id' => $partner->id,
            'amount' => $amount,
            'type' => $this->tab === 'receivable' ? LedgerSettlement::TYPE_INCOMING : LedgerSettlement::TYPE_OUTGOING,
            'settlement_date' => $this->paymentDate,
            'notes' => 'دفعة مسجلة من شاشة الدفتر',
        ]);
        app(\App\Services\AlertService::class)->evaluatePartners($partner->id);
        if (app(SettingsService::class)->get('telegram_payments', true)) {
            app(TelegramService::class)->sendMessage("💳 دفعة جديدة\nالطرف: {$partner->name}\nالمبلغ: ".number_format($amount, 2));
        }
        $this->closeAddPaymentModal();
        $this->dispatch('flash', message: 'تم تسجيل الدفعة بنجاح.', type: 'success');
    }

    public function setTabReceivable(): void { $this->tab = 'receivable'; }
    public function setTabPayable(): void { $this->tab = 'payable'; }
    public function updatedSearch(): void { $this->resetLedgerPages(); }
    public function updatedDateFrom(): void { $this->resetLedgerPages(); }
    public function updatedDateTo(): void { $this->resetLedgerPages(); }
    public function updatedSortBy(): void { $this->resetLedgerPages(); }
    private function resetLedgerPages(): void { $this->resetPage('receivablePage'); $this->resetPage('payablePage'); }

    private function balanceRows()
    {
        $financials = app(FinancialService::class);
        return $financials->partnerBalancesQuery($this->search, $this->dateFrom ?: null, $this->dateTo ?: null)->get()
            ->map(fn (Partner $partner) => [
                'partner' => $partner,
                'receivable' => $financials->receivable($partner),
                'payable' => $financials->payable($partner),
                'updated_at' => $partner->last_invoice_at,
            ]);
    }

    private function getBalances(string $side, $allRows = null)
    {
        $rows = $allRows ?? $this->balanceRows();
        $rows = $rows->map(fn (array $row) => $row + ['balance' => $row[$side]])->filter(fn (array $row) => $row['balance'] > 0);
        return ($this->sortBy === 'date' ? $rows->sortByDesc('updated_at') : $rows->sortByDesc('balance'))->values();
    }

    private function paginateBalances(string $side, $allRows = null): LengthAwarePaginator
    {
        $rows = $this->getBalances($side, $allRows); $pageName = $side.'Page'; $page = $this->paginators[$pageName] ?? 1; $perPage = 15;
        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path'=>request()->url(),'pageName'=>$pageName]);
    }
    public function getReceivableProperty() { return $this->paginateBalances('receivable'); }
    public function getPayableProperty() { return $this->paginateBalances('payable'); }

    public function settle(int $partnerId, string $type): void
    {
        $row = $this->getBalances($type)->first(fn (array $item) => $item['partner']->id === $partnerId);
        if (! $row || $row['balance'] <= 0) return;

        LedgerSettlement::create([
            'partner_id' => $partnerId,
            'amount' => $row['balance'],
            'type' => $type === 'receivable' ? LedgerSettlement::TYPE_INCOMING : LedgerSettlement::TYPE_OUTGOING,
            'settlement_date' => now()->toDateString(),
            'notes' => 'تسوية كاملة من شاشة الدفتر',
        ]);
        app(\App\Services\AlertService::class)->evaluatePartners($partnerId);
        if (app(SettingsService::class)->get('telegram_payments', true)) {
            app(TelegramService::class)->sendMessage("✅ تسوية كاملة\nالطرف: {$row['partner']->name}\nالمبلغ: ".number_format($row['balance'], 2));
        }
        $this->dispatch('flash', message: 'تمت تسوية الرصيد بنجاح.', type: 'success');
    }

    public function getPaymentPartnersProperty()
    {
        return $this->getBalances($this->tab)->pluck('partner');
    }

    public function render()
    {
        $allRows = $this->balanceRows();
        $receivable = $this->paginateBalances('receivable', $allRows);
        $payable = $this->paginateBalances('payable', $allRows);
        return view('livewire.ledger.index', [
            'receivable' => $receivable,
            'payable' => $payable,
            'paymentPartners' => $this->getBalances($this->tab, $allRows)->pluck('partner'),
        ]);
    }
}
