<?php

namespace App\Livewire;

use App\Models\MoneyParty;
use App\Models\MoneyPartyMovement;
use App\Services\FinancialStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('الوضع المالي')]
class FinancialStatus extends Component
{
    public string $period = 'month';
    public string $from = '';
    public string $to = '';
    public bool $showMovementForm = false;
    public ?int $money_party_id = null;
    public string $new_party_name = '';
    public string $direction = MoneyPartyMovement::DIRECTION_TO_PARTY;
    public string $category = MoneyPartyMovement::CATEGORY_PURCHASE_ADVANCE;
    public string $amount = '';
    public string $date = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->applyPeriod();
        $this->date = now()->toDateString();
    }

    public function updatedPeriod(): void
    {
        if ($this->period !== 'custom') {
            $this->applyPeriod();
        }
    }

    public function openMovementForm(): void
    {
        $this->resetValidation();
        $this->reset(['money_party_id', 'new_party_name', 'amount', 'notes']);
        $this->direction = MoneyPartyMovement::DIRECTION_TO_PARTY;
        $this->category = MoneyPartyMovement::CATEGORY_PURCHASE_ADVANCE;
        $this->date = now()->toDateString();
        $this->showMovementForm = true;
    }

    public function saveMovement(): void
    {
        $this->validate([
            'money_party_id' => ['nullable', 'exists:money_parties,id', 'required_without:new_party_name'],
            'new_party_name' => ['nullable', 'string', 'max:120', 'required_without:money_party_id'],
            'direction' => ['required', Rule::in([MoneyPartyMovement::DIRECTION_TO_PARTY, MoneyPartyMovement::DIRECTION_FROM_PARTY])],
            'category' => ['required', Rule::in([
                MoneyPartyMovement::CATEGORY_PURCHASE_ADVANCE,
                MoneyPartyMovement::CATEGORY_PURCHASE_COMPENSATION,
                MoneyPartyMovement::CATEGORY_OWNER_WITHDRAWAL,
                MoneyPartyMovement::CATEGORY_RETURNED_FROM_PARTY,
                MoneyPartyMovement::CATEGORY_OTHER,
                MoneyPartyMovement::CATEGORY_UNCLASSIFIED,
            ])],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function (): void {
            $partyId = $this->money_party_id;
            if (! $partyId) {
                $partyId = MoneyParty::query()->firstOrCreate(['name' => trim($this->new_party_name)])->id;
            }
            $amount = round((float) $this->amount, 2);
            MoneyPartyMovement::query()->create([
                'money_party_id' => $partyId,
                'direction' => $this->direction,
                'category' => $this->category,
                'amount' => $amount,
                'currency' => 'SYP',
                'exchange_rate_snapshot' => 1,
                'amount_base_currency' => $amount,
                'date' => $this->date,
                'notes' => blank($this->notes) ? null : trim($this->notes),
                'status' => MoneyPartyMovement::STATUS_ACTIVE,
            ]);
        });

        $this->showMovementForm = false;
        session()->flash('success', 'تم تسجيل حركة جهة المال.');
    }

    public function render(FinancialStatusService $financials)
    {
        $from = $this->from ?: null;
        $to = $this->to ?: null;

        $summary = $financials->summary($from, $to);
        return view('livewire.financial-status', [
            'summary' => $summary,
            'partyBalances' => $summary['money_party_rows'],
            'parties' => $this->showMovementForm ? MoneyParty::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'movements' => MoneyPartyMovement::query()->with('moneyParty:id,name')
                ->when($from, fn ($query) => $query->whereDate('date', '>=', $from))
                ->when($to, fn ($query) => $query->whereDate('date', '<=', $to))
                ->latest('date')->limit(12)->get(),
        ]);
    }

    private function applyPeriod(): void
    {
        [$from, $to] = match ($this->period) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'week' => [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()],
            'last_month' => [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'year' => [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()],
            default => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
        };
        $this->from = $from;
        $this->to = $to;
    }
}
