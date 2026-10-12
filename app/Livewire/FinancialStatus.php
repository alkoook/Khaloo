<?php

namespace App\Livewire;

use App\Services\FinancialStatusService;
use Illuminate\Support\Facades\Validator;
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

    public function mount(): void
    {
        $this->applyPeriod();
    }

    public function updatedPeriod(): void
    {
        if ($this->period !== 'custom') {
            $this->applyPeriod();
        }
    }

    public function render(FinancialStatusService $financials)
    {
        $dates = Validator::make(['from' => $this->from, 'to' => $this->to], [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'required' => 'اختر تاريخ البداية والنهاية لعرض النشاط.',
            'date_format' => 'أدخل تاريخاً صحيحاً لعرض النشاط.',
            'after_or_equal' => 'تاريخ النهاية يجب ألا يسبق تاريخ البداية.',
        ]);
        $invalid = $dates->fails();

        return view('livewire.financial-status', [
            'summary' => $invalid ? null : $financials->summary($this->from, $this->to),
            'dateError' => $invalid ? $dates->errors()->first() : null,
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
