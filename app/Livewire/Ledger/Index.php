<?php

namespace App\Livewire\Ledger;

use App\Models\Partner;
use App\Services\AlertService;
use App\Services\FinancialService;
use App\Services\SettingsService;
use App\Services\TelegramService;
use App\Support\Decimal as D;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

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

    public string $paymentNotes = '';

    public bool $paymentAmountTouched = false;

    public ?int $statementPartnerId = null;

    protected $queryString = ['search' => ['except' => ''], 'sortBy', 'dateFrom', 'dateTo', 'tab'];

    public function mount(): void
    {
        if (request()->boolean('open_modal')) {
            $this->openAddPaymentModal();
        }
    }

    public function openAddPaymentModal(): void
    {
        $this->resetValidation();
        $this->paymentPartnerId = null;
        $this->paymentAmount = '';
        $this->paymentDate = now()->toDateString();
        $this->paymentNotes = '';
        $this->paymentAmountTouched = false;
        $this->showAddPaymentModal = true;
    }

    public function updatedPaymentPartnerId(): void
    {
        if (! $this->paymentPartnerId || $this->paymentAmountTouched) {
            return;
        }
        $this->paymentAmount = $this->currentPaymentBalance > 0 ? number_format($this->currentPaymentBalance, 2, '.', '') : '';
    }

    public function updatedPaymentAmount(): void
    {
        $this->paymentAmountTouched = true;
    }

    public function closeAddPaymentModal(): void
    {
        $this->showAddPaymentModal = false;
    }

    public function showStatement(int $partnerId): void
    {
        $this->statementPartnerId = $partnerId;
    }

    public function closeStatement(): void
    {
        $this->statementPartnerId = null;
    }

    public function addPayment(FinancialService $financials): void
    {
        $this->validate([
            'paymentPartnerId' => ['required', 'exists:partners,id'],
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
            'paymentDate' => ['required', 'date'],
            'paymentNotes' => ['nullable', 'string', 'max:1000'],
        ]);
        $partner = $financials->partnerBalancesQuery()->findOrFail($this->paymentPartnerId);
        $available = $financials->balanceFor($partner, $this->tab);
        $amount = D::scale($this->paymentAmount);
        if (D::cmp($amount, $available) > 0) {
            $this->addError('paymentAmount', 'المبلغ أكبر من الرصيد المستحق الحالي.');

            return;
        }

        try {
            $financials->recordSettlement($partner->id, $this->tab, $amount, $this->paymentDate, $this->paymentNotes ?: 'دفعة مسجلة من شاشة الدفتر');
        } catch (ValidationException $exception) {
            $this->addError('paymentAmount', collect($exception->errors())->flatten()->first());

            return;
        }
        app(AlertService::class)->evaluatePartners($partner->id);
        if (app(SettingsService::class)->get('telegram_payments', true)) {
            app(TelegramService::class)->sendMessage("💳 دفعة جديدة\nالطرف: {$partner->name}\nالمبلغ: ".number_format($amount, 2));
        }
        $this->closeAddPaymentModal();
        $this->dispatch('flash', message: 'تم تسجيل الدفعة بنجاح.', type: 'success');
    }

    public function setTabReceivable(): void
    {
        $this->tab = 'receivable';
        $this->paymentPartnerId = null;
        $this->paymentAmount = '';
    }

    public function setTabPayable(): void
    {
        $this->tab = 'payable';
        $this->paymentPartnerId = null;
        $this->paymentAmount = '';
    }

    public function updatedSearch(): void
    {
        $this->resetLedgerPages();
    }

    public function updatedDateFrom(): void
    {
        $this->resetLedgerPages();
    }

    public function updatedDateTo(): void
    {
        $this->resetLedgerPages();
    }

    public function updatedSortBy(): void
    {
        $this->resetLedgerPages();
    }

    private function resetLedgerPages(): void
    {
        $this->resetPage('receivablePage');
        $this->resetPage('payablePage');
    }

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
        $rows = $this->getBalances($side, $allRows);
        $pageName = $side.'Page';
        $page = $this->paginators[$pageName] ?? 1;
        $perPage = 15;

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, ['path' => request()->url(), 'pageName' => $pageName]);
    }

    public function getReceivableProperty()
    {
        return $this->paginateBalances('receivable');
    }

    public function getPayableProperty()
    {
        return $this->paginateBalances('payable');
    }

    public function settle(int $partnerId, string $type): void
    {
        $row = $this->getBalances($type)->first(fn (array $item) => $item['partner']->id === $partnerId);
        if (! $row || $row['balance'] <= 0) {
            return;
        }

        $settledAmount = $row['balance'];
        try {
            app(FinancialService::class)->recordSettlement($partnerId, $type, $settledAmount, now()->toDateString(), 'تسوية كاملة من شاشة الدفتر');
        } catch (ValidationException) {
            return;
        }
        if ($settledAmount <= 0) {
            return;
        }
        app(AlertService::class)->evaluatePartners($partnerId);
        if (app(SettingsService::class)->get('telegram_payments', true)) {
            app(TelegramService::class)->sendMessage("✅ تسوية كاملة\nالطرف: {$row['partner']->name}\nالمبلغ: ".number_format($settledAmount, 2));
        }
        $this->dispatch('flash', message: 'تمت تسوية الرصيد بنجاح.', type: 'success');
    }

    public function getPaymentPartnersProperty()
    {
        return $this->getBalances($this->tab)->pluck('partner');
    }

    public function getCurrentPaymentBalanceProperty(): string
    {
        if (! $this->paymentPartnerId) {
            return '0.00';
        }
        $financials = app(FinancialService::class);
        $partner = $financials->partnerBalancesQuery()->find($this->paymentPartnerId);

        return $partner ? $financials->balanceFor($partner, $this->tab) : '0.00';
    }

    public function getExpectedPaymentBalanceProperty(): string
    {
        return D::scale(D::max(0, D::sub($this->currentPaymentBalance, $this->paymentAmount)));
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
            'statementPartner' => $this->statementPartnerId ? Partner::query()->find($this->statementPartnerId) : null,
            'statementRows' => $this->statementPartnerId ? app(FinancialService::class)->statementRows(Partner::query()->findOrFail($this->statementPartnerId), $this->dateFrom ?: null, $this->dateTo ?: null) : collect(),
        ]);
    }
}
