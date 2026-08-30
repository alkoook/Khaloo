<?php

namespace App\Livewire\Reports;

use App\Models\Category;
use App\Models\Partner;
use App\Services\ReportService;
use App\Services\TelegramService;
use Livewire\Component;

class Index extends Component
{
    public string $type = 'sales';
    public string $from = '';
    public string $to = '';
    public ?int $partner_id = null;
    public ?int $category_id = null;

    public function download(ReportService $reports)
    {
        $this->validateFilters();
        $path = $reports->export($this->type, $this->filters());
        return response()->download($path, basename($path))->deleteFileAfterSend(true);
    }

    public function downloadStatement(ReportService $reports)
    {
        $this->validate(['partner_id' => ['required', 'exists:partners,id'], 'from' => ['nullable','date'], 'to' => ['nullable','date','after_or_equal:from']]);
        $path = $reports->statement(Partner::query()->findOrFail($this->partner_id), $this->from ?: null, $this->to ?: null);
        return response()->download($path, basename($path))->deleteFileAfterSend(true);
    }

    public function sendStatement(ReportService $reports, TelegramService $telegram): void
    {
        $this->validate(['partner_id' => ['required', 'exists:partners,id']]);
        $partner = Partner::query()->findOrFail($this->partner_id);
        $sent = $telegram->sendMessage($reports->statementMessage($partner, $this->from ?: null, $this->to ?: null));
        $sent ? session()->flash('success', 'تم إرسال كشف الحساب إلى تيليغرام كرسالة نصية مرتبة.') : $this->addError('telegram', 'تعذر الإرسال. راجع تفعيل تيليغرام والبيانات.');
    }

    public function render(ReportService $reports)
    {
        $preview = $reports->preview($this->type, $this->filters());
        return view('livewire.reports.index', [
            'types' => ReportService::TYPES, 'preview' => $preview,
            'partners' => Partner::query()->orderBy('name')->get(['id','name','type']),
            'categories' => Category::query()->orderBy('name')->get(['id','name']),
        ])->layout('layouts.app', ['title' => 'منشئ التقارير']);
    }

    private function validateFilters(): void
    {
        $this->validate(['type' => ['required','in:'.implode(',',array_keys(ReportService::TYPES))], 'from'=>['nullable','date'], 'to'=>['nullable','date','after_or_equal:from'], 'partner_id'=>['nullable','exists:partners,id'], 'category_id'=>['nullable','exists:categories,id']]);
    }

    private function filters(): array { return ['from'=>$this->from ?: null,'to'=>$this->to ?: null,'partner_id'=>$this->partner_id,'category_id'=>$this->category_id]; }
}
